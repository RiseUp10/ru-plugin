<html>
<body style="font-family: Arial, sans-serif; line-height:1.5; background:#150505; color:#EEEBEB;">
    <h2>Ci siamo quasi</h2>

    <p>Ecco i link per procedere:</p>

    <?php if (!empty($site_url)): ?>
        <p><strong>Il tuo sito (una tantum):</strong><br>
        <a href="<?= esc_url($site_url) ?>" style="color:#EEEBEB;"><?= esc_html($site_url) ?></a></p>
    <?php endif; ?>

    <?php if (!empty($plan_url)): ?>
        <p><strong>Il tuo abbonamento:</strong><br>
        <a href="<?= esc_url($plan_url) ?>" style="color:#EEEBEB;"><?= esc_html($plan_url) ?></a></p>
    <?php else: ?>
        <p>Hai scelto di procedere senza abbonamento — nessun link
        ricorrente da completare.</p>
    <?php endif; ?>

    <?php if (!empty($contract_url)): ?>
        <p>Prima di procedere, puoi leggere il contratto qui:
        <a href="<?= esc_url($contract_url) ?>" style="color:#EEEBEB;">contratto</a>.</p>
    <?php endif; ?>

    <p>Hai un dubbio nel frattempo? Rispondi pure a questa email — ti
    risponde una persona vera, non un sistema automatico.</p>

    <p style="font-size:12px; color:#999;">RiseUp Consulting — riseup.marketing</p>
</body>
</html>
