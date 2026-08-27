<?php
function send_schema_audit_email($post_id, $email) {
    $site_url   = get_post_meta($post_id, 'site_url', true);
    $status     = get_post_meta($post_id, 'schema_status', true);
    $raw        = get_post_meta($post_id, 'schema_raw', true);
    $valid_json = get_post_meta($post_id, 'schema_valid', true); // ya es array (ver schema_audit_full_job)

    // Formato compatible con template schema-audit-template.php
    $snippets = $raw ? explode("\n\n", trim($raw)) : [];
    $valid    = !empty($valid_json) ? print_r($valid_json, true) : '';

    $data = [
        'site_url' => $site_url,
        'status'   => $status,
        'snippets' => $snippets,
        'valid'    => $valid,
        'cta_url'  => 'https://riseup.marketing/contatto',
    ];

    return riseup_send_email([
        'to'       => $email,
        'subject'  => 'Schema Audit – ' . $site_url,
        'template' => 'schema-audit',
        'data'     => $data,
        'format'   => 'html',
    ]);
}
