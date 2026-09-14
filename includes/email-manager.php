<?php

/*if ( ! function_exists( 'riseup_send_email' ) ) {

    // Loguear fallos de wp_mail
    add_action( 'wp_mail_failed', function( $wp_error ) {
        error_log( "🔥 [RiseUp] ➤ WP Mail failed: " . print_r( $wp_error, true ) );
    } );*/

    /**
     * Envía un email plain-text usando plantillas en email-templates/{template}/plain.php
     * Añade cabeceras para mejorar entregabilidad.
     *
     * @param array $args {
     *   @type string $to
     *   @type string $subject
     *   @type string $template    Nombre de carpeta en email-templates (e.g. 'guides', 'seo-audit', 'schema-audit')
     *   @type array  $data        Variables para la plantilla
     *   @type array  $attachments Adjuntos opcionales
     * }
     * @return bool
     */
/*    function riseup_send_email( $args = [] ) {
        $to          = $args['to']         ?? '';
        $subject     = $args['subject']    ?? '';
        $template    = $args['template']   ?? '';
        $data        = $args['data']       ?? [];
        $attachments = $args['attachments'] ?? [];

        if ( ! $to || ! $subject || ! $template ) {
            return false;
        }

        // Construye resource URL si aplica
        if ( ! empty( $data['resource'] ) ) {
            $slug                 = sanitize_title( $data['resource'] );
            $data['resource_url'] = home_url( '/risorse/' . $slug );
        }

        // Base path al plugin root
        $base = dirname( plugin_dir_path( __FILE__ ) );

        // Carga plantilla plain-text
        $tpl = $base . "/email-templates/{$template}/plain.php";
        if ( file_exists( $tpl ) ) {
            ob_start();
            include $tpl;
            $body = ob_get_clean();
        } else {
            // Fallback genérico
            $body = sprintf(
                "Ciao %s,\n\n" .
                "Ecco quello che abbiamo promesso \n\n" .
                "Risorsa: %s\n%s\n\n" .
                "Grazie,\nIl team di Rise Up",
                stripslashes( $data['name']    ?? '' ),
                stripslashes( $data['resource'] ?? $subject ),
                stripslashes( $data['resource_url'] ?? '' )
            );
        }
        
        // Asunto dinámico y personalizado
        $subject = sprintf(
            "%s, la tua guida per il settore %s è qui",
            $data['name']    ?? '',
            $data['sector']  ?? ''
        );

        // Cabeceras plain-text y entregabilidad
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: Rise Up <nonrispondere@riseup.marketing>',
            'Reply-To: contatto@riseup.marketing',
            'List-Unsubscribe: <mailto:unsubscribe@riseup.marketing>',
            'Precedence: bulk',
            'List-ID: guides.riseup.marketing'
        ];

        // Envía el correo
        return wp_mail( $to, $subject, $body, $headers, $attachments );
    };
}*/


if (!defined('ABSPATH')) exit;

// Log de fallos global
add_action('wp_mail_failed', function($wp_error){
    error_log("[RiseUp] wp_mail_failed: " . print_r($wp_error, true));
});

/**
 * riseup_send_email()
 * Args:
 *  - to (string, req)
 *  - subject (string, req)
 *  - template (string, req) => 'seo' | 'schema' | 'guides' (acepto alias)
 *  - data (array)
 *  - attachments (array)
 *  - format ('plain'|'html') default 'html'
 *  - bcc_admin (bool) default false
 */
if (!function_exists('riseup_send_email')) {
function riseup_send_email($args = []) {
    $to          = $args['to']          ?? '';
    $subject     = $args['subject']     ?? '';
    $template    = $args['template']    ?? '';
    $data        = $args['data']        ?? [];
    $attachments = $args['attachments'] ?? [];
    $format      = $args['format']      ?? 'html';
    $bcc_admin   = !empty($args['bcc_admin']);

    if (!$to || !$subject || !$template) return false;

    // Map a tus archivos reales
    $tpl_rel = match (strtolower($template)) {
        'seo', 'seo-audit', 'seo_audit'       => 'email-templates/seo-audit-template.php',
        'schema', 'schema-audit', 'schema_audit' => 'email-templates/schema-audit-template.php',
        'guides', 'guide', 'resource'         => 'email-templates/guides-template.php',
        'confirm-audit', 'confirm_audit'      => 'email-templates/confirm-audit-template.php',
        'subscription-activated', 'subscription_activated' => 'email-templates/subscription-activated-template.php',
        'subscription-cancelled', 'subscription_cancelled' => 'email-templates/subscription-cancelled-template.php',
        default                               => ''
    };

    // Render (fallback si falta)
    $body = $tpl_rel ? rum_render_template($tpl_rel, $data) : '';
    if ($body === '') {
        $body = ($format === 'html') ? '<pre>' . esc_html(print_r($data, true)) . '</pre>' : print_r($data, true);
    }

    // Headers
    $headers = rum_headers($format, [
        'list_unsub' => 'mailto:unsubscribe@riseup.marketing',
        // 'list_id' => ($template === 'guides' ? 'guides.riseup.marketing' : null),
        'extra'      => [],
    ]);

    if ($bcc_admin) $headers[] = 'Bcc: riseup.businessmaker@gmail.com';

    return wp_mail($to, $subject, $body, $headers, $attachments);
}}
