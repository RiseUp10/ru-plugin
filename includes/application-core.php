<?php
// Aplicación al monoprodotto (sitio 1€) — Etapa A: screening con
// verificación de identidad (email + SMS) antes de las preguntas de
// negocio, guardado progresivo por respuesta. Ver
// ru-plugin/RU-SUBSCRIPTION-SYSTEM-PLAN.md, sección 7.5.
//
// Requiere: cpt-register.php (CPT ru_application), verification.php
// (doble opt-in de email, reusado con type='application'), email-manager.php.
//
// Orden del flujo: email (doble opt-in existente) → celular (código SMS
// vía Brevo) → nombre del negocio, relato, objetivo, sitio/social actual,
// ubicación (orientativa, no bloqueante).
//
// Campos permitidos en el guardado genérico (ru_application_save_field) —
// whitelist explícita, no se acepta cualquier meta key desde el cliente.
define('RU_APPLICATION_FIELDS', [
    'business_name',
    'story',
    'goal',
    'website',
    'instagram',
    'tiktok',
    'facebook',
    'location',
]);

if (!defined('ABSPATH')) exit;

// ---------------------------------------------------------------------
// Front-end: shortcode + JS
// ---------------------------------------------------------------------

// [ru_application] — insertar en la página /applica/. El JS arma toda la
// UI de una-pregunta-a-la-vez adentro de este contenedor.
add_shortcode('ru_application', function () {
    return '<div id="ru-application-root"></div>';
});

add_action('wp_enqueue_scripts', function () {
    $script_path = plugin_dir_path(__FILE__) . '../js/application.js';
    $script_url  = plugin_dir_url(__FILE__) . '../js/application.js';

    wp_enqueue_script(
        'ru-application',
        $script_url,
        [],
        file_exists($script_path) ? filemtime($script_path) : null,
        true
    );

    wp_localize_script('ru-application', 'ruApplicationData', [
        'ajaxurl' => admin_url('admin-ajax.php'),
    ]);
});

// ---------------------------------------------------------------------
// Paso 1 — Email (reusa el doble opt-in de verification.php)
// ---------------------------------------------------------------------

add_action('wp_ajax_ru_application_start', 'ru_application_start');
add_action('wp_ajax_nopriv_ru_application_start', 'ru_application_start');

function ru_application_start() {
    $email = sanitize_email($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        wp_send_json(['success' => false, 'message' => 'Email non valida.']);
    }

    // Honeypot + rate limit — mismo patrón que seo_audit_run().
    if (!empty($_POST['hp_field'])) {
        wp_send_json(['success' => true, 'post_id' => 0, 'message' => 'Controlla la tua email.']);
    }

    // En local (misma IP de casa en cada test) el rate limit se pisa enseguida
    // y traba las pruebas — se exceptúa acá, producción lo sigue aplicando.
    $ip = (wp_get_environment_type() === 'local') ? '' : sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip) {
        $rl_key = 'ru_application_rl_' . md5($ip);
        $count  = (int) get_transient($rl_key);
        if ($count >= 3) {
            wp_send_json(['success' => false, 'message' => 'Hai già iniziato una candidatura di recente. Controlla la tua email.']);
        }
        set_transient($rl_key, $count + 1, DAY_IN_SECONDS);
    }

    $post_id = wp_insert_post([
        'post_type'   => 'ru_application',
        'post_title'  => 'Candidatura - ' . $email . ' - ' . current_time('mysql'),
        'post_status' => 'publish',
    ]);
    if (is_wp_error($post_id) || !$post_id) {
        wp_send_json(['success' => false, 'message' => 'Errore durante il salvataggio.']);
    }

    update_post_meta($post_id, 'email', $email);
    update_post_meta($post_id, 'application_status', 'pending_email');

    register_shutdown_function(function () use ($post_id, $email) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ru_send_verification_email($post_id, $email, 'application');
    });

    wp_send_json([
        'success' => true,
        'post_id' => $post_id,
        'message' => 'Controlla la tua email e conferma l\'indirizzo per continuare.',
    ]);
}

// Reenvía el mismo mail de confirmación (mismo token, no crea nada nuevo) —
// para cuando el usuario no lo recibió y quiere seguir con el mismo email
// en vez de tener que arrancar de cero con otro.
add_action('wp_ajax_ru_application_resend_email', 'ru_application_resend_email');
add_action('wp_ajax_nopriv_ru_application_resend_email', 'ru_application_resend_email');

function ru_application_resend_email() {
    $post_id = absint($_POST['post_id'] ?? 0);
    if (!$post_id || get_post_type($post_id) !== 'ru_application') {
        wp_send_json(['success' => false, 'message' => 'Candidatura non trovata.']);
    }
    if (get_post_meta($post_id, 'verify_status', true) === 'confirmed') {
        wp_send_json(['success' => false, 'message' => 'Email già confermata.']);
    }

    $rl_key = 'ru_app_resend_' . $post_id;
    if (get_transient($rl_key)) {
        wp_send_json(['success' => false, 'message' => 'Aspetta un momento prima di richiedere un altro invio.']);
    }
    set_transient($rl_key, 1, 60);

    $sent = ru_dispatch_verification_email($post_id);
    wp_send_json([
        'success' => (bool) $sent,
        'message' => $sent ? 'Email reinviata — controlla la tua casella.' : 'Errore nell\'invio. Riprova tra poco.',
    ]);
}

// Cuando confirma el link de email (verification.php redirige de vuelta a
// /applica/ con post_id + status=ok en vez de a la landing genérica —
// ver el ajuste en ru_verify_email()). No hace falta lógica pesada acá,
// el estado real ya lo dejó marcado ru_verify_email(); esto es solo el
// punto de extensión si más adelante hace falta algo al confirmar email.
add_action('ru_verified_application', function ($post_id) {
    update_post_meta($post_id, 'application_status', 'pending_phone');
});

// Consulta de estado — el JS la usa al cargar /applica/?post_id=X&status=ok
// para confirmar server-side que el email está realmente verificado, en
// vez de confiar ciegamente en el query string.
add_action('wp_ajax_ru_application_status', 'ru_application_status');
add_action('wp_ajax_nopriv_ru_application_status', 'ru_application_status');

function ru_application_status() {
    $post_id = absint($_POST['post_id'] ?? 0);
    if (!$post_id || get_post_type($post_id) !== 'ru_application') {
        wp_send_json(['success' => false, 'message' => 'Candidatura non trovata.']);
    }

    wp_send_json([
        'success'          => true,
        'email_verified'   => get_post_meta($post_id, 'verify_status', true) === 'confirmed',
        'phone_verified'   => (bool) get_post_meta($post_id, 'phone_verified', true),
        'application_status' => get_post_meta($post_id, 'application_status', true) ?: 'pending_email',
    ]);
}

// ---------------------------------------------------------------------
// Paso 2 — Celular (código de 6 dígitos por SMS vía Brevo)
// ---------------------------------------------------------------------

add_action('wp_ajax_ru_application_send_sms_code', 'ru_application_send_sms_code');
add_action('wp_ajax_nopriv_ru_application_send_sms_code', 'ru_application_send_sms_code');

function ru_application_send_sms_code() {
    $post_id = absint($_POST['post_id'] ?? 0);
    $phone   = sanitize_text_field($_POST['phone'] ?? '');

    if (!$post_id || get_post_type($post_id) !== 'ru_application') {
        wp_send_json(['success' => false, 'message' => 'Candidatura non trovata.']);
    }
    if (get_post_meta($post_id, 'verify_status', true) !== 'confirmed') {
        wp_send_json(['success' => false, 'message' => 'Devi prima confermare la tua email.']);
    }
    if (!$phone) {
        wp_send_json(['success' => false, 'message' => 'Numero di telefono non valido.']);
    }

    // Rate limit propio del SMS: cada envío tiene costo real, más agresivo
    // que el de arranque (máx 5 envíos/código por candidatura).
    $attempts = (int) get_post_meta($post_id, 'sms_send_attempts', true);
    if ($attempts >= 5) {
        wp_send_json(['success' => false, 'message' => 'Troppi tentativi. Contattaci direttamente.']);
    }

    $phone_normalized = ru_normalize_phone($phone);

    $code = str_pad((string) wp_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    update_post_meta($post_id, 'phone', $phone_normalized);
    update_post_meta($post_id, 'sms_code', $code);
    update_post_meta($post_id, 'sms_code_created', time());
    update_post_meta($post_id, 'sms_send_attempts', $attempts + 1);
    update_post_meta($post_id, 'sms_verify_attempts', 0); // reset intentos de validación por cada código nuevo

    $sent = ru_send_sms_via_brevo($phone_normalized, "Il tuo codice RiseUp: {$code}");

    if (!$sent) {
        wp_send_json(['success' => false, 'message' => 'Errore nell\'invio dell\'SMS. Riprova.']);
    }

    wp_send_json(['success' => true, 'message' => 'Ti abbiamo inviato un codice via SMS.']);
}

add_action('wp_ajax_ru_application_verify_sms_code', 'ru_application_verify_sms_code');
add_action('wp_ajax_nopriv_ru_application_verify_sms_code', 'ru_application_verify_sms_code');

function ru_application_verify_sms_code() {
    $post_id = absint($_POST['post_id'] ?? 0);
    $code    = sanitize_text_field($_POST['code'] ?? '');

    if (!$post_id || get_post_type($post_id) !== 'ru_application') {
        wp_send_json(['success' => false, 'message' => 'Candidatura non trovata.']);
    }

    $verify_attempts = (int) get_post_meta($post_id, 'sms_verify_attempts', true);
    if ($verify_attempts >= 5) {
        wp_send_json(['success' => false, 'message' => 'Troppi tentativi. Richiedi un nuovo codice.']);
    }
    update_post_meta($post_id, 'sms_verify_attempts', $verify_attempts + 1);

    $stored_code = get_post_meta($post_id, 'sms_code', true);
    $created     = (int) get_post_meta($post_id, 'sms_code_created', true);

    if ($created && (time() - $created) > 10 * MINUTE_IN_SECONDS) {
        wp_send_json(['success' => false, 'message' => 'Codice scaduto. Richiedine uno nuovo.']);
    }
    if (!$stored_code || !hash_equals($stored_code, $code)) {
        wp_send_json(['success' => false, 'message' => 'Codice non valido.']);
    }

    update_post_meta($post_id, 'phone_verified', true);
    update_post_meta($post_id, 'application_status', 'pending_answers');
    delete_post_meta($post_id, 'sms_code');

    wp_send_json(['success' => true, 'message' => 'Numero verificato.']);
}

// ---------------------------------------------------------------------
// Paso 3 — Preguntas de negocio (guardado genérico por campo)
// ---------------------------------------------------------------------

add_action('wp_ajax_ru_application_save_field', 'ru_application_save_field');
add_action('wp_ajax_nopriv_ru_application_save_field', 'ru_application_save_field');

function ru_application_save_field() {
    $post_id = absint($_POST['post_id'] ?? 0);
    $field   = sanitize_key($_POST['field'] ?? '');
    $value   = sanitize_textarea_field($_POST['value'] ?? '');

    if (!$post_id || get_post_type($post_id) !== 'ru_application') {
        wp_send_json(['success' => false, 'message' => 'Candidatura non trovata.']);
    }
    if (get_post_meta($post_id, 'verify_status', true) !== 'confirmed' || !get_post_meta($post_id, 'phone_verified', true)) {
        wp_send_json(['success' => false, 'message' => 'Devi prima verificare email e telefono.']);
    }
    if (!in_array($field, RU_APPLICATION_FIELDS, true)) {
        wp_send_json(['success' => false, 'message' => 'Campo non valido.']);
    }

    update_post_meta($post_id, $field, $value);

    wp_send_json(['success' => true]);
}

// Disparado explícitamente por el JS al completar la última pregunta —
// separado de save_field para no adivinar cuál es "la última" del lado
// del servidor.
add_action('wp_ajax_ru_application_submit', 'ru_application_submit');
add_action('wp_ajax_nopriv_ru_application_submit', 'ru_application_submit');

function ru_application_submit() {
    $post_id = absint($_POST['post_id'] ?? 0);

    if (!$post_id || get_post_type($post_id) !== 'ru_application') {
        wp_send_json(['success' => false, 'message' => 'Candidatura non trovata.']);
    }
    if (get_post_meta($post_id, 'verify_status', true) !== 'confirmed' || !get_post_meta($post_id, 'phone_verified', true)) {
        wp_send_json(['success' => false, 'message' => 'Devi prima verificare email e telefono.']);
    }

    update_post_meta($post_id, 'application_status', 'submitted');

    // Revisión manual por ahora (decidido 18 ago 2026, ver build pack) —
    // no hay motor de reglas. Alcanza con que la candidatura sea visible
    // en la lista nativa del CPT en wp-admin.

    wp_send_json(['success' => true, 'message' => 'Grazie mille! Abbiamo ricevuto la tua candidatura. La leggiamo con calma e ti contattiamo via email o WhatsApp per i prossimi passi.']);
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

// Normalización mínima a E.164 — asume Italia (+39) si no viene con "+".
// TODO: validar formato real antes de mandar a Brevo (hoy solo limpia
// caracteres, no confirma que sea un número válido).
function ru_normalize_phone($phone) {
    $digits = preg_replace('/[^\d+]/', '', $phone);
    if (strpos($digits, '+') !== 0) {
        $digits = '+39' . ltrim($digits, '0');
    }
    return $digits;
}

// Envía un SMS transaccional vía Brevo. Requiere BREVO_API_KEY en
// wp-config.php (no existe todavía — hoy el email sale por WP Mail SMTP,
// no por esta API). También requiere un sender registrado/aprobado en
// Brevo para SMS a números italianos.
function ru_send_sms_via_brevo($phone_e164, $message) {
    if (!defined('BREVO_API_KEY') || !BREVO_API_KEY) {
        error_log('[RU Application] BREVO_API_KEY no definida en wp-config.php — SMS no enviado.');
        return false;
    }

    $response = wp_remote_post('https://api.brevo.com/v3/transactionalSMS/sms', [
        'timeout' => 10,
        'headers' => [
            'api-key'      => BREVO_API_KEY,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body' => wp_json_encode([
            'sender'    => 'RiseUp',
            'recipient' => $phone_e164,
            'content'   => $message,
            'type'      => 'transactional',
        ]),
    ]);

    if (is_wp_error($response)) {
        error_log('[RU Application] SMS HTTP error: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        error_log('[RU Application] SMS API error (' . $code . '): ' . wp_remote_retrieve_body($response));
        return false;
    }

    return true;
}
