<?php
// Hub de RU: dónde vive el estado real de la suscripción de cada cliente
// (qué plan tiene, activo o no), el gate que lo lee, y el webhook de
// Stripe que dispara los cambios. Ver RU-SUBSCRIPTION-SYSTEM-PLAN.md
// secciones 0, 3D, 7.7 y 7.9.
//
// Decisión (14 sep 2026): no se copia la estructura de SC 1:1. SC necesita
// a Make como puente porque Main y Shop son dos instalaciones de WP
// separadas. En RU, WP Simple Pay y el hub viven en el mismo WordPress —
// no hay dos sistemas que puentear. Stripe es el responsable directo del
// cobro y notifica accá vía su propio webhook firmado (no un secret
// inventado por nosotros, no un archivo "make.php" en el camino crítico).
// Make, si se usa, queda para control/auditoría aparte (Sheets, Slack),
// nunca con lógica de negocio.
//
// Identidad: RU no tiene login de cliente (mismo criterio de mínimo
// contacto que el resto del plugin — magic links, no cuentas). El plan
// igual se guarda como user meta de un WP user "silencioso": se busca por
// email y, si no existe, se crea sin flujo de login expuesto — es solo un
// lugar donde colgar meta, no una cuenta que el cliente use para entrar.

if (!defined('ABSPATH')) exit;

// --- Identidad: WP user silencioso por email -----------------------------
function ru_client_user_id(string $email, bool $create_if_missing = false): int {
    $email = sanitize_email($email);
    if (!$email) return 0;

    $user = get_user_by('email', $email);
    if ($user) return (int) $user->ID;
    if (!$create_if_missing) return 0;

    $user_id = wp_insert_user([
        'user_login' => $email,
        'user_email' => $email,
        'user_pass'  => wp_generate_password(32), // nunca se usa para login
        'role'       => 'subscriber',
    ]);

    return is_wp_error($user_id) ? 0 : (int) $user_id;
}

// --- Plan del cliente (única fuente de verdad) ---------------------------
function ru_client_plan(int $user_id): ?string {
    $plan = (string) get_user_meta($user_id, 'ru_subscription_plan', true);
    return $plan ?: null; // sin abono = null, RU no tiene tier gratis
}

function ru_client_has_active_plan(int $user_id): bool {
    return get_user_meta($user_id, 'ru_subscription_status', true) === 'active';
}

// --- Mutación de estado (única entrada permitida) -------------------------
function ru_billing_handle_subscription_activate(array $data): void {
    $email    = sanitize_email($data['email'] ?? '');
    $plan     = sanitize_key($data['plan'] ?? '');
    $interval = sanitize_key($data['interval'] ?? ''); // 'monthly' | 'yearly'

    // Falla seguro: sin plan reconocible no se activa nada al voleo. El
    // plan viaja en la metadata del Price de Stripe (ru_plan/ru_interval,
    // ver checklist de forms) — si falta, revisar la config del Price
    // antes de sospechar del código.
    if (!$email || !$plan) return;

    // Segunda capa de dedup, específica para el caso de dos TIPOS de
    // evento sobre el mismo invoice (invoice.payment_succeeded +
    // invoice_payment.paid) — el dedup por event_id del endpoint no lo
    // cubre porque son event_id distintos para el mismo hecho.
    $invoice_id = $data['invoice_id'] ?? '';
    if ($invoice_id) {
        $dedup_key = 'ru_stripe_invoice_' . md5($invoice_id);
        if (get_transient($dedup_key)) return;
        set_transient($dedup_key, 1, DAY_IN_SECONDS);
    }

    $user_id = ru_client_user_id($email, true);
    if (!$user_id) return;

    update_user_meta($user_id, 'ru_subscription_plan', $plan);
    update_user_meta($user_id, 'ru_subscription_interval', $interval);
    update_user_meta($user_id, 'ru_subscription_status', 'active');
    update_user_meta($user_id, 'ru_subscription_activated_at', current_time('mysql'));

    // Mail de confirmación directo, no vía Make — la deuda de SC (sección 1
    // del plan: "se agregó recién en agosto") se evita acá desde el día 1.
    riseup_send_email([
        'to'       => $email,
        'subject'  => 'Il tuo abbonamento RiseUp è attivo',
        'template' => 'subscription-activated',
        'data'     => ['plan' => $plan, 'interval' => $interval],
    ]);
}

// Disparado por customer.subscription.deleted (Stripe ya agotó los Smart
// Retries y canceló la suscripción — ver plan, sección 7.7). Nunca confía
// en un plan/estado que venga del payload, siempre fuerza a inactive.
function ru_billing_handle_subscription_downgrade(array $data): void {
    $email = sanitize_email($data['email'] ?? '');
    if (!$email) return;

    $user_id = ru_client_user_id($email, false);
    if (!$user_id) return;

    $reason = sanitize_key($data['reason'] ?? 'unknown');

    update_user_meta($user_id, 'ru_subscription_status', 'inactive');
    update_user_meta($user_id, 'ru_subscription_downgraded_at', current_time('mysql'));
    update_user_meta($user_id, 'ru_subscription_downgrade_reason', $reason);
    update_user_meta($user_id, 'ru_subscription_downgrade_source', sanitize_key($data['source'] ?? 'stripe'));

    // Voluntaria ('cancellation_requested') vs. todo lo demás (pago fallido/
    // disputado/no identificado) — cambia el tono del mail, no la mutación
    // de estado (que es la misma en ambos casos: desactivar).
    $voluntary = ($reason === 'cancellation_requested');

    riseup_send_email([
        'to'       => $email,
        'subject'  => $voluntary
            ? 'Il tuo abbonamento RiseUp è stato annullato'
            : 'Non siamo riusciti a rinnovare il tuo abbonamento RiseUp',
        'template' => 'subscription-cancelled',
        'data'     => [
            'voluntary'       => $voluntary,
            // TODO: se completa cuando exista el trigger de export
            // automático (ver RU-SUBSCRIPTION-SYSTEM-PLAN.md sección 7.6,
            // "Dependencia técnica..." — todavía no implementado). Hasta
            // entonces el template usa el fallback de escribir a soporte.
            'download_link'   => '',
            'resubscribe_url' => RU_CHECKOUT_BASE_MONTHLY_URL, // TODO: URL real del plan que tenía
        ],
    ]);

    // Punto de extensión para lo que todavía no existe (export automático
    // del sitio) — mismo patrón que ru_verified_* en verification.php.
    do_action('ru_client_subscription_downgraded', $user_id, $data);
}

// --- Webhook de Stripe: POST /wp-json/ru/v1/stripe-webhook ---------------
add_action('rest_api_init', 'ru_stripe_register_rest_routes');
function ru_stripe_register_rest_routes(): void {
    register_rest_route('ru/v1', '/stripe-webhook', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true', // la firma de Stripe es el gate real
        'callback'            => 'ru_stripe_webhook_endpoint',
    ]);
}

function ru_stripe_webhook_endpoint(WP_REST_Request $r) {
    $payload    = $r->get_body();
    $sig_header = $r->get_header('stripe-signature');

    if (!defined('STRIPE_WEBHOOK_SECRET') || !STRIPE_WEBHOOK_SECRET) {
        return new WP_REST_Response(['ok' => false, 'error' => 'not_configured'], 500);
    }

    if (!ru_stripe_verify_signature($payload, $sig_header, STRIPE_WEBHOOK_SECRET)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'invalid_signature'], 403);
    }

    $event = json_decode($payload, true);
    $type  = $event['type'] ?? '';

    // Traba contra reenvíos de Stripe (redelivery legítimo del mismo
    // evento) — no resuelve el caso de dos TIPOS de evento distintos para
    // el mismo hecho (invoice.payment_succeeded + invoice_payment.paid),
    // eso se evita eligiendo un solo evento al crear el destination.
    $event_id = $event['id'] ?? '';
    if ($event_id) {
        $dedup_key = 'ru_stripe_evt_' . md5($event_id);
        if (get_transient($dedup_key)) {
            return new WP_REST_Response(['ok' => true, 'dedup' => true], 200);
        }
        set_transient($dedup_key, 1, DAY_IN_SECONDS);
    }

    switch ($type) {
        case 'invoice.payment_succeeded':
        case 'invoice_payment.paid': // API version nueva — mismo hecho, objeto distinto
            ru_billing_handle_subscription_activate(ru_stripe_extract_billing_data($event));
            break;
        case 'customer.subscription.deleted':
            ru_billing_handle_subscription_downgrade(ru_stripe_extract_billing_data($event));
            break;
        default:
            // Tipo de evento que no nos interesa — Stripe manda muchos.
            // 200 igual, si no reintenta pensando que falló la entrega.
            break;
    }

    return new WP_REST_Response(['ok' => true], 200);
}

// Verificación manual de la firma (mismo algoritmo que el SDK oficial de
// Stripe) — no depende de que la librería de Stripe esté cargada por WP
// Simple Pay u otro plugin.
function ru_stripe_verify_signature(string $payload, ?string $sig_header, string $secret, int $tolerance = 300): bool {
    if (!$sig_header) return false;

    $parts = [];
    foreach (explode(',', $sig_header) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, null);
        $parts[$k][] = $v;
    }

    $timestamp  = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
    $signatures = $parts['v1'] ?? [];

    if (!$timestamp || empty($signatures)) return false;
    if (abs(time() - $timestamp) > $tolerance) return false; // evita replay

    $signed_payload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed_payload, $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($expected, (string) $sig)) return true;
    }
    return false;
}

// Saca email + plan/interval del evento de Stripe. El objeto que viaja en
// el evento varía según el tipo:
// - invoice.payment_succeeded: el objeto ES el invoice completo (email y
//   metadata directo).
// - invoice_payment.paid (API version nueva): el objeto es un
//   "invoice_payment", NO trae ni email ni metadata — solo una referencia
//   ('invoice' => id). Hace falta pedirle el invoice completo a la API.
// - customer.subscription.deleted: el objeto es la subscription (sin
//   email directo tampoco, pero sí trae 'customer' para resolverlo).
function ru_stripe_extract_billing_data(array $event): array {
    $type = $event['type'] ?? '';
    $obj  = $event['data']['object'] ?? [];

    if ($type === 'invoice_payment.paid') {
        $invoice_id = is_string($obj['invoice'] ?? null) ? $obj['invoice'] : '';
        $invoice    = $invoice_id ? ru_stripe_fetch_invoice($invoice_id) : null;
        $obj        = $invoice ?? []; // a partir de acá, mismo shape que invoice.payment_succeeded
    }

    $email       = $obj['customer_email'] ?? $obj['customer_details']['email'] ?? '';
    $customer_id = is_string($obj['customer'] ?? null) ? $obj['customer'] : '';

    if (!$email && $customer_id) {
        $email = ru_stripe_fetch_customer_email($customer_id);
    }

    // El plan/interval vienen de la metadata del Price en Stripe (ver
    // checklist de los 6 forms: ru_plan / ru_interval). La API actual de
    // Stripe NO expande el price adentro de lines.data[] — solo trae su
    // ID en lines.data[].pricing.price_details.price (confirmado con un
    // evento real: "pricing":{"price_details":{"price":"price_..."}}).
    // Hay que pedir el Price aparte para leer su metadata.
    $metadata = $obj['metadata'] ?? [];
    if (empty($metadata)) {
        $price_id = $obj['lines']['data'][0]['pricing']['price_details']['price']
            ?? $obj['lines']['data'][0]['price']['id']
            ?? null;
        if (is_string($price_id)) {
            $price = ru_stripe_fetch_price($price_id);
            $metadata = $price['metadata'] ?? [];

            // Fallback: si la metadata se cargó en el Product en vez del
            // Price (son objetos separados en Stripe, cada uno con la
            // suya) — el Price trae el product ID en $price['product'].
            if (empty($metadata) && is_string($price['product'] ?? null)) {
                $product = ru_stripe_fetch_product($price['product']);
                $metadata = $product['metadata'] ?? [];
            }
        }
    }

    // customer.subscription.deleted trae el motivo real en
    // cancellation_details.reason ('cancellation_requested' = el cliente
    // canceló a propósito; 'payment_failed'/'payment_disputed'/null = todo
    // lo demás). Para otros tipos de evento no aplica, se cae al fallback.
    $cancellation_reason = $obj['cancellation_details']['reason'] ?? null;

    return [
        'email'      => $email,
        'plan'       => $metadata['ru_plan'] ?? '',
        'interval'   => $metadata['ru_interval'] ?? '',
        'reason'     => $cancellation_reason ?? ($type ?: 'unknown'),
        'source'     => 'stripe',
        // Solo tiene sentido para invoice.payment_succeeded/invoice_payment.paid
        // (ahí $obj es o pasa a ser el invoice) — se usa para el dedup en
        // ru_billing_handle_subscription_activate().
        'invoice_id' => (string) ($obj['id'] ?? ''),
    ];
}

function ru_stripe_fetch_invoice(string $invoice_id): ?array {
    $secret_key = ru_stripe_secret_key();
    if (!$secret_key) return null;

    // Sin expand: la API actual no expande el price adentro de lines.data[]
    // de ningún modo útil (confirmado con un evento real) — el metadata se
    // pide aparte con ru_stripe_fetch_price().
    $res = wp_remote_get("https://api.stripe.com/v1/invoices/{$invoice_id}", [
        'headers' => ['Authorization' => 'Basic ' . base64_encode($secret_key . ':')],
        'timeout' => 10,
    ]);

    if (is_wp_error($res)) return null;

    $body = json_decode(wp_remote_retrieve_body($res), true);
    return is_array($body) ? $body : null;
}

function ru_stripe_fetch_price(string $price_id): ?array {
    $secret_key = ru_stripe_secret_key();
    if (!$secret_key) return null;

    $res = wp_remote_get("https://api.stripe.com/v1/prices/{$price_id}", [
        'headers' => ['Authorization' => 'Basic ' . base64_encode($secret_key . ':')],
        'timeout' => 10,
    ]);

    if (is_wp_error($res)) return null;

    $body = json_decode(wp_remote_retrieve_body($res), true);
    return is_array($body) ? $body : null;
}

function ru_stripe_fetch_product(string $product_id): ?array {
    $secret_key = ru_stripe_secret_key();
    if (!$secret_key) return null;

    $res = wp_remote_get("https://api.stripe.com/v1/products/{$product_id}", [
        'headers' => ['Authorization' => 'Basic ' . base64_encode($secret_key . ':')],
        'timeout' => 10,
    ]);

    if (is_wp_error($res)) return null;

    $body = json_decode(wp_remote_retrieve_body($res), true);
    return is_array($body) ? $body : null;
}

function ru_stripe_secret_key(): string {
    return function_exists('simpay_get_secret_key')
        ? simpay_get_secret_key() // reusa la key ya cargada por WP Simple Pay, si está
        : (defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '');
}

function ru_stripe_fetch_customer_email(string $customer_id): string {
    $secret_key = ru_stripe_secret_key();
    if (!$secret_key) return '';

    $res = wp_remote_get("https://api.stripe.com/v1/customers/{$customer_id}", [
        'headers' => ['Authorization' => 'Basic ' . base64_encode($secret_key . ':')],
        'timeout' => 10,
    ]);

    if (is_wp_error($res)) return '';

    $body = json_decode(wp_remote_retrieve_body($res), true);
    return $body['email'] ?? '';
}
