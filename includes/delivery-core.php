<?php
// Flow 3 (Acceptance) — preview del sitio vía magic link, sin login:
// el cliente confirma o pide una modificación. Ver
// RU-SUBSCRIPTION-SYSTEM-PLAN.md sección 7.6 y 7.11.
//
// Requiere: application-core.php (CPT ru_application, campo 'email'),
// billing.php (ru_client_user_id/ru_client_has_active_plan, para saber
// si corresponde ofrecer la descarga), email-manager.php.
//
// Depende de una página en Elementor con el shortcode [ru_site_preview]
// (ej. /anteprima/) — el link del mail apunta ahí con ?post_id=X&token=Y.

if (!defined('ABSPATH')) exit;

// ---------------------------------------------------------------------
// Admin: bloque de delivery en el editor de ru_application — solo tiene
// sentido después de aprobar la candidatura (mismo gate que el bloque de
// Flow 2 en application-core.php).
// ---------------------------------------------------------------------

add_action('edit_form_after_title', function ($post) {
    if ($post->post_type !== 'ru_application') return;
    if (get_post_meta($post->ID, 'ru_application_decision', true) !== 'approved') return;

    $post_id = $post->ID;
    $get = fn($k) => get_post_meta($post_id, $k, true);

    $status   = $get('ru_delivery_status') ?: 'not_sent';
    $limit    = $get('ru_delivery_revision_limit');
    $limit    = ($limit === '' || $limit === false) ? 1 : (int) $limit; // default 1 ronda
    $count    = (int) $get('ru_delivery_revision_count');
    $feedback = $get('ru_delivery_last_feedback');

    wp_nonce_field('ru_delivery_preview', 'ru_delivery_preview_nonce');
    echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:15px 20px; margin:20px 0;">';
    echo '<h2 style="margin-top:0;">Consegna del sito (Flow 3)</h2>';

    echo '<p><strong>Stato:</strong> ' . esc_html($status) . ' — Round richiesti: ' . (int) $count . ' / ' . (int) $limit . ' inclusi</p>';
    if ($feedback) {
        echo '<p><strong>Ultimo feedback del cliente:</strong><br>' . nl2br(esc_html($feedback)) . '</p>';
    }
    if ($status === 'approved') {
        echo '<p>✅ Approvato il ' . esc_html($get('ru_delivery_approved_at')) . ' — IP: ' . esc_html($get('ru_delivery_approved_ip')) . '</p>';
    }

    echo '<p><label>URL anteprima<br><input type="text" name="ru_delivery_preview_url" value="' . esc_attr($get('ru_delivery_preview_url')) . '" style="width:100%; max-width:500px;"></label></p>';
    echo '<p><label>Round di revisione inclusi<br><input type="number" name="ru_delivery_revision_limit" value="' . esc_attr($limit) . '" min="0" style="width:80px;"></label></p>';
    echo '<p><label><input type="checkbox" name="ru_delivery_send_preview" value="1"> Invia (o re-invia) l\'email con l\'anteprima</label></p>';

    // Descarga: solo tiene sentido ofrecerla si el cliente no tiene un
    // abono activo (con abono, hosteás vos — ver plan, sección 7.6).
    $email = $get('email');
    $user  = $email ? get_user_by('email', $email) : false;
    $has_subscription = $user && function_exists('ru_client_has_active_plan') && ru_client_has_active_plan($user->ID);

    if ($status === 'approved' && !$has_subscription) {
        echo '<hr style="margin:15px 0;">';
        echo '<p>Il cliente non ha un abbonamento attivo — a te preparare il pacchetto di export e caricarlo, poi mandare il link qui sotto.</p>';
        echo '<p><label>URL download<br><input type="text" name="ru_delivery_download_url" value="' . esc_attr($get('ru_delivery_download_url')) . '" style="width:100%; max-width:500px;"></label></p>';
        echo '<p><label><input type="checkbox" name="ru_delivery_send_download" value="1"> Invia email con il link per scaricare il sito</label></p>';
        $download_sent = $get('ru_delivery_download_sent_at');
        if ($download_sent) {
            echo '<p style="color:#666; font-size:12px;">Ultimo invio: ' . esc_html($download_sent) . '</p>';
        }
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
}, 30); // después de los hooks de Flow 1/2 (10 y 20)

function ru_delivery_send_preview_email($post_id) {
    $email = get_post_meta($post_id, 'email', true);
    $preview_url = get_post_meta($post_id, 'ru_delivery_preview_url', true);
    if (!$email || !$preview_url) return;

    // Token persistente — no se regenera en cada reenvío, así un mail
    // viejo sigue sirviendo si el cliente lo busca de nuevo.
    $token = get_post_meta($post_id, 'ru_delivery_token', true);
    if (!$token) {
        $token = wp_generate_password(32, false);
        update_post_meta($post_id, 'ru_delivery_token', $token);
    }

    update_post_meta($post_id, 'ru_delivery_status', 'sent');

    $limit = (int) get_post_meta($post_id, 'ru_delivery_revision_limit', true);
    $limit = $limit ?: 1;

    $preview_page_url = add_query_arg([
        'post_id' => $post_id,
        'token'   => $token,
    ], home_url('/anteprima/'));

    riseup_send_email([
        'to'       => $email,
        'subject'  => 'Il tuo sito è pronto per una prima occhiata',
        'template' => 'site-preview',
        'data'     => [
            'preview_page_url' => $preview_page_url,
            'revision_limit'   => $limit,
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

    update_post_meta($post_id, 'ru_delivery_download_sent_at', current_time('mysql'));
}

// ---------------------------------------------------------------------
// Página pública: [ru_site_preview] — /anteprima/?post_id=X&token=Y
// ---------------------------------------------------------------------

add_shortcode('ru_site_preview', function () {
    $post_id = absint($_GET['post_id'] ?? 0);
    $token   = sanitize_text_field($_GET['token'] ?? '');

    if (!$post_id || get_post_type($post_id) !== 'ru_application') {
        return '<p>Link non valido.</p>';
    }

    $stored_token = get_post_meta($post_id, 'ru_delivery_token', true);
    if (!$stored_token || !$token || !hash_equals($stored_token, $token)) {
        return '<p>Link non valido.</p>';
    }

    $status = get_post_meta($post_id, 'ru_delivery_status', true);
    if ($status === 'approved') {
        return '<p>Hai già confermato questo sito — grazie! Se hai bisogno di qualcosa, scrivici pure.</p>';
    }

    $preview_url = get_post_meta($post_id, 'ru_delivery_preview_url', true);
    $limit = (int) get_post_meta($post_id, 'ru_delivery_revision_limit', true);
    $limit = $limit ?: 1;

    ob_start();
    ?>
    <div id="ru-site-preview" data-post-id="<?= esc_attr($post_id) ?>" data-token="<?= esc_attr($token) ?>" data-ajaxurl="<?= esc_url(admin_url('admin-ajax.php')) ?>">
        <?php if ($preview_url): ?>
            <p><a href="<?= esc_url($preview_url) ?>" target="_blank" rel="noopener" style="display:inline-block; padding:12px 22px; border:1px solid currentColor; text-decoration:none; border-radius:20px;">Vedi l'anteprima del sito</a></p>
        <?php endif; ?>

        <p style="font-size:13px; opacity:0.8;">Il tuo pacchetto include <?= (int) $limit ?> round di revisione gratuit<?= $limit === 1 ? 'o' : 'i' ?>. Oltre questo, eventuali modifiche aggiuntive hanno un costo a parte — te ne parliamo caso per caso.</p>

        <p><button type="button" id="ru-preview-confirm">Conferma</button></p>

        <p>
            <textarea id="ru-preview-feedback" rows="4" style="width:100%; max-width:500px;" placeholder="Cosa vorresti cambiare?"></textarea><br>
            <button type="button" id="ru-preview-request-change">Richiedi una modifica</button>
        </p>

        <p id="ru-preview-message"></p>
    </div>
    <script>
    (function () {
        var root = document.getElementById('ru-site-preview');
        if (!root) return;
        var postId = root.dataset.postId, token = root.dataset.token, ajaxurl = root.dataset.ajaxurl;
        var msg = document.getElementById('ru-preview-message');

        function post(action, extra) {
            var body = new URLSearchParams(Object.assign({ action: action, post_id: postId, token: token }, extra || {}));
            return fetch(ajaxurl, { method: 'POST', body: body }).then(function (r) { return r.json(); });
        }

        document.getElementById('ru-preview-confirm').addEventListener('click', function () {
            post('ru_delivery_confirm').then(function (res) {
                msg.textContent = res.data && res.data.message ? res.data.message : '';
                if (res.success) root.querySelectorAll('button, textarea').forEach(function (el) { el.disabled = true; });
            });
        });

        document.getElementById('ru-preview-request-change').addEventListener('click', function () {
            var feedback = document.getElementById('ru-preview-feedback').value.trim();
            if (!feedback) { msg.textContent = 'Scrivi cosa vorresti cambiare prima di inviare.'; return; }
            post('ru_delivery_request_change', { feedback: feedback }).then(function (res) {
                msg.textContent = res.data && res.data.message ? res.data.message : '';
                if (res.success) root.querySelectorAll('button, textarea').forEach(function (el) { el.disabled = true; });
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});

function ru_delivery_validate_token($post_id, $token) {
    if (!$post_id || get_post_type($post_id) !== 'ru_application') return false;
    $stored = get_post_meta($post_id, 'ru_delivery_token', true);
    return $stored && $token && hash_equals($stored, $token);
}

add_action('wp_ajax_ru_delivery_confirm', 'ru_delivery_confirm');
add_action('wp_ajax_nopriv_ru_delivery_confirm', 'ru_delivery_confirm');

function ru_delivery_confirm() {
    $post_id = absint($_POST['post_id'] ?? 0);
    $token   = sanitize_text_field($_POST['token'] ?? '');

    if (!ru_delivery_validate_token($post_id, $token)) {
        wp_send_json(['success' => false, 'message' => 'Link non valido.']);
    }
    if (get_post_meta($post_id, 'ru_delivery_status', true) === 'approved') {
        wp_send_json(['success' => true, 'message' => 'Già confermato in precedenza.']);
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

    wp_send_json(['success' => true, 'message' => 'Grazie! Confermato con successo.']);
}

add_action('wp_ajax_ru_delivery_request_change', 'ru_delivery_request_change');
add_action('wp_ajax_nopriv_ru_delivery_request_change', 'ru_delivery_request_change');

function ru_delivery_request_change() {
    $post_id  = absint($_POST['post_id'] ?? 0);
    $token    = sanitize_text_field($_POST['token'] ?? '');
    $feedback = sanitize_textarea_field($_POST['feedback'] ?? '');

    if (!ru_delivery_validate_token($post_id, $token)) {
        wp_send_json(['success' => false, 'message' => 'Link non valido.']);
    }
    if (get_post_meta($post_id, 'ru_delivery_status', true) === 'approved') {
        wp_send_json(['success' => false, 'message' => 'Il sito è già stato confermato.']);
    }
    if (!$feedback) {
        wp_send_json(['success' => false, 'message' => 'Scrivi cosa vorresti cambiare.']);
    }

    $count = (int) get_post_meta($post_id, 'ru_delivery_revision_count', true);
    $count++;
    $limit = (int) get_post_meta($post_id, 'ru_delivery_revision_limit', true) ?: 1;

    update_post_meta($post_id, 'ru_delivery_status', 'change_requested');
    update_post_meta($post_id, 'ru_delivery_last_feedback', $feedback);
    update_post_meta($post_id, 'ru_delivery_revision_count', $count);

    // Aviso interno — no es un mail de marca para el cliente, es solo
    // para que el founder se entere y vea si ya se pasó de las rondas
    // incluidas (el sistema no lo bloquea, solo informa — decisión 14 sep 2026).
    $over_limit = $count > $limit ? " ⚠️ Ronda {$count}/{$limit} — ya superó las incluidas." : " (ronda {$count}/{$limit})";
    wp_mail(
        'riseup.businessmaker@gmail.com',
        'Pedido de cambio — candidatura #' . $post_id,
        "Feedback:\n{$feedback}\n" . $over_limit . "\n\n" . admin_url('post.php?post=' . $post_id . '&action=edit')
    );

    wp_send_json(['success' => true, 'message' => 'Grazie! Ricevuto — ti aggiorniamo appena pronto.']);
}
