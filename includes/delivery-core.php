<?php
// Flow 3 (Acceptance) — preview del sitio por mail. "Conferma" es un
// link simple (mismo mecanismo que el doble opt-in de verification.php,
// sin página ni AJAX propios); pedir un cambio es directamente responder
// el mail, lo lee el founder en su inbox. Ver
// RU-SUBSCRIPTION-SYSTEM-PLAN.md sección 7.11.
//
// Requiere: application-core.php (CPT ru_application, campo 'email'),
// billing.php (ru_client_has_active_plan, para saber si corresponde
// ofrecer la descarga), email-manager.php.
//
// Dónde vive el draft que se linkea (subdominio, staging, etc.) queda
// afuera de esto — el campo de URL es texto libre.

if (!defined('ABSPATH')) exit;

add_action('edit_form_after_title', function ($post) {
    if ($post->post_type !== 'ru_application') return;
    if (get_post_meta($post->ID, 'ru_application_decision', true) !== 'approved') return;

    $post_id = $post->ID;
    $get = fn($k) => get_post_meta($post_id, $k, true);

    $status = $get('ru_delivery_status');
    $limit  = $get('ru_delivery_revision_limit');
    $limit  = ($limit === '' || $limit === false) ? 1 : (int) $limit;

    wp_nonce_field('ru_delivery_preview', 'ru_delivery_preview_nonce');
    echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:15px 20px; margin:20px 0;">';
    echo '<h2 style="margin-top:0;">Consegna del sito (Flow 3)</h2>';

    if ($status === 'approved') {
        echo '<p>✅ Approvato il ' . esc_html($get('ru_delivery_approved_at')) . ' (IP: ' . esc_html($get('ru_delivery_approved_ip')) . ')</p>';
    }

    echo '<p><label>URL anteprima (dominio/staging temporaneo che usi tu)<br><input type="text" name="ru_delivery_preview_url" value="' . esc_attr($get('ru_delivery_preview_url')) . '" style="width:100%; max-width:500px;"></label></p>';
    echo '<p><label>Round di revisione inclusi <input type="number" name="ru_delivery_revision_limit" value="' . esc_attr($limit) . '" min="0" style="width:60px;"></label></p>';
    echo '<p><label><input type="checkbox" name="ru_delivery_send_preview" value="1"> Invia (o re-invia) l\'email con l\'anteprima</label></p>';

    // Descarga: solo si no tiene abono activo (con abono, hosteás vos).
    $email = $get('email');
    $user  = $email ? get_user_by('email', $email) : false;
    $has_subscription = $user && function_exists('ru_client_has_active_plan') && ru_client_has_active_plan($user->ID);

    if ($status === 'approved' && !$has_subscription) {
        echo '<hr style="margin:15px 0;">';
        echo '<p><label>URL download (a te prepararlo)<br><input type="text" name="ru_delivery_download_url" value="' . esc_attr($get('ru_delivery_download_url')) . '" style="width:100%; max-width:500px;"></label></p>';
        echo '<p><label><input type="checkbox" name="ru_delivery_send_download" value="1"> Invia email con il link per scaricare il sito</label></p>';
    }

    echo '</div>';
});

add_action('save_post_ru_application', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!isset($_POST['ru_delivery_preview_nonce']) || !wp_verify_nonce($_POST['ru_delivery_preview_nonce'], 'ru_delivery_preview')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['ru_delivery_preview_url'])) {
        update_post_meta($post_id, 'ru_delivery_preview_url', esc_url_raw($_POST['ru_delivery_preview_url']));
    }
    if (isset($_POST['ru_delivery_revision_limit'])) {
        update_post_meta($post_id, 'ru_delivery_revision_limit', max(0, (int) $_POST['ru_delivery_revision_limit']));
    }
    if (isset($_POST['ru_delivery_download_url'])) {
        update_post_meta($post_id, 'ru_delivery_download_url', esc_url_raw($_POST['ru_delivery_download_url']));
    }

    if (!empty($_POST['ru_delivery_send_preview'])) {
        ru_delivery_send_preview_email($post_id);
    }
    if (!empty($_POST['ru_delivery_send_download'])) {
        ru_delivery_send_download_email($post_id);
    }
}, 30);

function ru_delivery_send_preview_email($post_id) {
    $email = get_post_meta($post_id, 'email', true);
    $preview_url = get_post_meta($post_id, 'ru_delivery_preview_url', true);
    if (!$email || !$preview_url) return;

    $token = get_post_meta($post_id, 'ru_delivery_token', true);
    if (!$token) {
        $token = wp_generate_password(32, false);
        update_post_meta($post_id, 'ru_delivery_token', $token);
    }
    update_post_meta($post_id, 'ru_delivery_status', 'sent');

    $limit = (int) get_post_meta($post_id, 'ru_delivery_revision_limit', true) ?: 1;

    $confirm_url = add_query_arg([
        'action'  => 'ru_delivery_confirm',
        'post_id' => $post_id,
        'token'   => $token,
    ], admin_url('admin-ajax.php'));

    riseup_send_email([
        'to'       => $email,
        'subject'  => 'Il tuo sito è pronto per una prima occhiata',
        'template' => 'site-preview',
        'data'     => [
            'preview_url'    => $preview_url,
            'confirm_url'    => $confirm_url,
            'revision_limit' => $limit,
        ],
    ]);
}

function ru_delivery_send_download_email($post_id) {
    $email = get_post_meta($post_id, 'email', true);
    $download_url = get_post_meta($post_id, 'ru_delivery_download_url', true);
    if (!$email || !$download_url) return;

    riseup_send_email([
        'to'       => $email,
        'subject'  => 'Il file del tuo sito',
        'template' => 'site-download',
        'data'     => ['download_url' => $download_url],
    ]);
}

// "Conferma" — GET simple, sin página ni AJAX del lado del cliente.
add_action('wp_ajax_ru_delivery_confirm', 'ru_delivery_confirm');
add_action('wp_ajax_nopriv_ru_delivery_confirm', 'ru_delivery_confirm');

function ru_delivery_confirm() {
    $post_id = absint($_GET['post_id'] ?? 0);
    $token   = sanitize_text_field($_GET['token'] ?? '');

    $message = function ($text) {
        wp_die(esc_html($text), '', ['response' => 200]);
    };

    if (!$post_id || get_post_type($post_id) !== 'ru_application') {
        $message('Link non valido.');
    }

    $stored_token = get_post_meta($post_id, 'ru_delivery_token', true);
    if (!$stored_token || !$token || !hash_equals($stored_token, $token)) {
        $message('Link non valido.');
    }

    if (get_post_meta($post_id, 'ru_delivery_status', true) === 'approved') {
        $message('Avevi già confermato, grazie di nuovo!');
    }

    update_post_meta($post_id, 'ru_delivery_status', 'approved');
    update_post_meta($post_id, 'ru_delivery_approved_at', current_time('mysql'));
    update_post_meta($post_id, 'ru_delivery_approved_ip', sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''));

    $email = get_post_meta($post_id, 'email', true);
    if ($email) {
        riseup_send_email([
            'to'       => $email,
            'subject'  => 'Il tuo sito è confermato! 🎉',
            'template' => 'site-approved',
        ]);
    }

    $message('Grazie! Il tuo sito è confermato. Ti abbiamo mandato una email di conferma.');
}
