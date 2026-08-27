<?php
// wp-content/plugins/seo-audit-tool/includes/elementor-integration.php

/*require_once __DIR__ . '/email-manager.php';
// 1) Router mínimo para Elementor AJAX
add_action('wp_ajax_nopriv_elementor_pro_forms_send_form', function() {
    // Devolvemos éxito para que Elementor limpie el form sin 500
    wp_send_json_success();
});
add_action('wp_ajax_elementor_pro_forms_send_form', function() {
    wp_send_json_success();
});*/

use ElementorPro\Modules\Forms\Classes\Form_Record;


// 1) Hook que atrapa todos los envíos de formularios de Elementor Pro
add_action('elementor_pro/forms/new_record', function(Form_Record $record) {
    // 2) Diagnóstico inicial: nombre del formulario
    $form_name = $record->get_form_settings('form_name');
    error_log("🔥 [RiseUp] ➤ Hook FIRE, form_name='{$form_name}'");

    // 3) Solo procesar si es el formulario "risorse" (sin importar mayúsculas)
    if (strcasecmp($form_name, 'risorse') !== 0) {
        error_log("🔥 [RiseUp] ➤ Abort: form_name != risorse");
        return;
    }

    // 4) Leer campos crudos y loguear sus IDs
    $raw = $record->get('fields');
    error_log("🔥 [RiseUp] ➤ RAW keys: " . implode(', ', array_keys($raw)));

    // 5) Normalizar cualquier prefijo que Elementor ponga en los IDs
    $fields = [];
    foreach ($raw as $id => $d) {
        // Captura solo name/email/sector/resource tras prefijos como form-field-, form_field_, form-widget-submission-
        if (preg_match('/(?:^form[-_]?field[-_]?|^form[-_]?widget[-_]?submission[-_]?)(name|email|sector|resource)$/i', $id, $m)) {
            $clean = strtolower($m[1]);
        } else {
            // Si no encaja, usa el ID en minúsculas
            $clean = strtolower($id);
        }
        $fields[$clean] = $d['value'] ?? '';
    }
    error_log("🔥 [RiseUp] ➤ CLEAN keys: " . implode(', ', array_keys($fields)));

    // 6) Sanitizar y validar valores
    $name     = sanitize_text_field( $fields['name']     ?? '' );
    $email    = sanitize_email(    $fields['email']    ?? '' );
    $sector   = sanitize_text_field( $fields['sector']   ?? '' );
    $resource = sanitize_text_field( $fields['resource'] ?? '' );
    error_log("🔥 [RiseUp] ➤ VALUES: name='{$name}', email='{$email}', sector='{$sector}', resource='{$resource}'");
    if (! $name || ! $email || ! $sector || ! $resource) {
        error_log("🔥 [RiseUp] ➤ Abort: missing data");
        return;
    }

    // 7) Insertar CPT "lead_optins"
    $post_id = wp_insert_post([
        'post_type'   => 'lead_optins',
        'post_status' => 'private',
        'post_title'  => $email,
    ]);
    if (is_wp_error($post_id)) {
        error_log("🔥 [RiseUp] ➤ Error al insertar post: " . $post_id->get_error_message());
        return;
    }
    error_log("🔥 [RiseUp] ➤ Lead guardado, post_id={$post_id}");

    // 8) Guardar meta datos
    update_post_meta($post_id, 'lead_name',     $name);
    update_post_meta($post_id, 'lead_email',    $email);
    update_post_meta($post_id, 'lead_sector',   $sector);
    update_post_meta($post_id, 'lead_resource', $resource);
    update_post_meta($post_id, 'lead_source',   $resource);

    // 9) Doble opt-in: no se manda la guía todavía. ru_verified_guide
    // (en includes/verification.php + este archivo) la manda recién al confirmar.
    ru_send_verification_email($post_id, $email, 'guide');
    error_log("🔥 [RiseUp] ➤ Verification email queued for post_id={$post_id}");

});

add_action('ru_verified_guide', function ($post_id) {
    $name     = get_post_meta($post_id, 'lead_name', true);
    $email    = get_post_meta($post_id, 'lead_email', true);
    $sector   = get_post_meta($post_id, 'lead_sector', true);
    $resource = get_post_meta($post_id, 'lead_resource', true);
    if (!$email) return;

    // Cada guía vive como página armada a mano en Elementor bajo /risorse/{slug}/
    $resource_url = home_url('/risorse/' . sanitize_title($resource) . '/');

    $sent = riseup_send_email([
        'to'         => $email,
        'subject'    => 'Ecco la tua risorsa gratuita',
        'template'   => 'guides',
        'data'       => compact('name', 'sector', 'resource', 'resource_url'),
        'format'     => 'html',
        'attachments'=> [],
    ]);
    error_log("🔥 [RiseUp] ➤ Guide email send result=" . ($sent ? 'OK' : 'FAIL'));
});

 ?>