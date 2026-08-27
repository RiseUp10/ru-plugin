<?php

//Aísla utilidades, no toca la lógica.

if (!defined('ABSPATH')) exit;

if (!defined('RUM_AUDIT_PATH')) define('RUM_AUDIT_PATH', plugin_dir_path(__FILE__) . '../');
if (!defined('RUM_AUDIT_URL'))  define('RUM_AUDIT_URL',  plugin_dir_url(__FILE__)  . '../');

function rum_safe_require($rel) {
    $path = RUM_AUDIT_PATH . ltrim($rel, '/');
    if (file_exists($path)) require_once $path;
    else error_log('[RUM] Missing include: ' . $rel);
}

function rum_render_template($rel, array $vars = []) {
    $path = RUM_AUDIT_PATH . ltrim($rel, '/');
    if (!file_exists($path)) {
        error_log('[RUM] Missing template: ' . $rel);
        return '';
    }
    extract($vars, EXTR_SKIP);
    ob_start();
    include $path;
    return ob_get_clean();
}

function rum_headers($type = 'plain', array $extra = []) {
    $headers = [];
    $headers[] = $type === 'html'
        ? 'Content-Type: text/html; charset=UTF-8'
        : 'Content-Type: text/plain; charset=UTF-8';

    // From / Reply-To filtrables — remitente con nombre propio, no "no-reply"
    // (principio del workspace: mínimo contacto pero sin sonar frío/corporativo).
    // Reply-To apunta al mismo mail, no a un contatto@ genérico — quien
    // responda el mail le llega directo a Roberto, no a una casilla anónima.
    $from_name  = apply_filters('rum_email_from_name',  'Roberto da RiseUp');
    $from_email = apply_filters('rum_email_from_email', 'roberto@riseup.marketing');
    $reply_to   = apply_filters('rum_email_reply_to',   'roberto@riseup.marketing');

    $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
    if ($reply_to) $headers[] = 'Reply-To: ' . $reply_to;

    // Unsubscribe / List-Id opcionales (evitamos 'Precedence: bulk' para transaccionales)
    if (!empty($extra['list_unsub'])) $headers[] = 'List-Unsubscribe: <' . $extra['list_unsub'] . '>';
    if (!empty($extra['list_id']))    $headers[] = 'List-ID: ' . $extra['list_id'];

    // Merge headers extra
    foreach ($extra as $h) if (is_string($h) && str_contains($h, ':')) $headers[] = $h;
    return $headers;
}
