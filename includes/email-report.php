<?php
/*function send_seo_audit_email($post_id, $email) {
    $site_url   = get_post_meta($post_id, 'site_url', true);
    $site_title = get_post_meta($post_id, 'titolo', true);
    $psi_error  = get_post_meta($post_id, 'psi-psi-error', true);

    $subject = 'SEO Audit Report – ' . $site_url;

    ob_start();
    ?>
    <html>
    <body style="font-family: Arial, sans-serif; line-height: 1.5; background:#150505; color:#EEEBEB">
        <h2>Rapporto di audit SEO per <?php echo esc_html($site_url); ?></h2>

        <h3>📌 Informazione Website</h3>
        <ul>
            <li><strong>Titolo:</strong> <?php echo esc_html($site_title); ?></li>
            <li><strong>Meta Descrizione:</strong> <?php echo esc_html(get_post_meta($post_id, 'meta-description', true)); ?></li>
            <li><strong>H1:</strong> <?php echo esc_html(get_post_meta($post_id, 'h1', true)); ?></li>
            <li><strong>Meta Robots:</strong> <?php echo esc_html(get_post_meta($post_id, 'meta-robots', true)); ?></li>
            <li><strong>Canonical:</strong> <?php echo esc_html(get_post_meta($post_id, 'canonical', true)); ?></li>
        </ul>

        <h3>⚙️ Informazioni Tecniche</h3>
        <ul>
            <li><strong>HTTPS:</strong> <?php echo esc_html(get_post_meta($post_id, 'https', true)); ?></li>
            <li><strong>Viewport:</strong> <?php echo esc_html(get_post_meta($post_id, 'viewport', true)); ?></li>
            <li><strong>Linguaggio:</strong> <?php echo esc_html(get_post_meta($post_id, 'lingua', true)); ?></li>
            <li><strong>Dimensione HTML:</strong> <?php echo esc_html(get_post_meta($post_id, 'dimensione-html', true)); ?></li>
            <li><strong>Schema Markup:</strong> <?php echo esc_html(get_post_meta($post_id, 'schema-markup', true)); ?></li>
        </ul>

        <h3>📊 PageSpeed Metrics</h3>
        <ul>
            <li><strong>LCP:</strong> <?php echo esc_html(get_post_meta($post_id, 'psi-lcp', true)); ?></li>
            <li><strong>CLS:</strong> <?php echo esc_html(get_post_meta($post_id, 'psi-cls', true)); ?></li>
            <li><strong>INP:</strong> <?php echo esc_html(get_post_meta($post_id, 'psi-inp', true)); ?></li>
            <li><strong>FCP:</strong> <?php echo esc_html(get_post_meta($post_id, 'psi-fcp', true)); ?></li>
            <li><strong>Indice di velocità:</strong> <?php echo esc_html(get_post_meta($post_id, 'psi-speed-index', true)); ?></li>
            <li><strong>TBT:</strong> <?php echo esc_html(get_post_meta($post_id, 'psi-tbt', true)); ?></li>
            <li><strong>Punteggio delle prestazioni:</strong> <?php echo esc_html(get_post_meta($post_id, 'psi-performance-score', true)); ?></li>
            <li><strong>Responsive:</strong> <?php echo esc_html(get_post_meta($post_id, 'psi-responsive', true)); ?></li>
        </ul>

        <?php if ($psi_error): ?>
            <p style="color: red;"><strong>⚠️ Non è stato possibile caricare alcuni dati sulle prestazioni. Riceverai presto una versione aggiornata del report.</strong></p>
        <?php endif; ?>

        <p><br>
            👉 <a href="https://riseup.marketing/contatto" style="padding: 10px 15px; border: 1px solid #EEEBEB; background: #150505; color: #EEEBEB; text-decoration: none; border-radius: 20px;">Vuoi risolvere qualche problema?</a>
        </p>

        <p>Grazie per utilizzare il nostro SEO Audit Tool!</p>
    </body>
    </html>
    <?php
    $body = ob_get_clean();

        
        $sent = wp_mail($email, $subject, $body, [
        'Content-Type: text/html; charset=UTF-8',
        'From: Rise Up Audit <noreply@riseup.marketing>' // ← this helps mail delivery
            ]);
            
            if (!$sent) {
                error_log("❌ wp_mail failed for: $email");
            } else {
                error_log("✅ wp_mail sent to: $email");
            }

}*/

function send_seo_audit_email($post_id, $email) {
    $site_url = get_post_meta($post_id, 'site_url', true);
    $val = fn($k) => get_post_meta($post_id, $k, true) ?: '-';

    $data = [
        'site_url' => $site_url,
        'onpage' => [
            'Titolo'           => $val('titolo'),
            'Meta Description' => $val('meta-description'),
            'H1'               => $val('h1'),
            'Meta Robots'      => $val('meta-robots'),
            'Canonical'        => $val('canonical'),
        ],
        'technical' => [
            'HTTPS'           => $val('https'),
            'Viewport'        => $val('viewport'),
            'Lingua'          => $val('lingua'),
            'Dimensione HTML' => $val('dimensione-html'),
            'Schema Markup'   => $val('schema-markup'),
        ],
        'performance' => [
            'LCP'               => $val('psi-lcp'),
            'CLS'               => $val('psi-cls'),
            'INP'               => $val('psi-inp'),
            'FCP'               => $val('psi-fcp'),
            'Speed Index'       => $val('psi-speed-index'),
            'TBT'               => $val('psi-tbt'),
            'Performance Score' => $val('psi-performance-score'),
            'Responsive'        => $val('psi-responsive'),
        ],
        'ai_recommendations' => $val('ai-recommendations'),
        'psi_error' => $val('psi-psi-error'),
        'cta_url'  => 'https://riseup.marketing/contatto',
    ];

    return riseup_send_email([
        'to'        => $email,
        'subject'   => 'SEO Audit Report – ' . $site_url,
        'template'  => 'seo-audit',   // debe matchear el match() de riseup_send_email() en email-manager.php, que internamente lo resuelve a email-templates/seo-audit-template.php
        'data'      => $data,
        'format'    => 'html',
    ]);
}
