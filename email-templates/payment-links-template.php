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

    <?php if (!empty($contract_summary)): ?>
        <p><strong>Riepilogo di cosa hai scelto:</strong></p>
        <p style="white-space:pre-line; border-left:2px solid #EEEBEB; padding-left:12px;"><?= nl2br(esc_html($contract_summary)) ?></p>
    <?php endif; ?>

    <?php if (!empty($terms_url)): ?>
        <p style="font-size:12px; color:#bbb;">Questo riepilogo si aggiunge alle
        <a href="<?= esc_url($terms_url) ?>" style="color:#bbb;">Condizioni Generali</a> complete, che restano valide per tutto il resto.</p>
    <?php endif; ?>

    <p>Hai un dubbio nel frattempo? Rispondi pure a questa email — ti
    risponde una persona vera, non un sistema automatico.</p>

    <p style="font-size:12px; color:#999;">RiseUp Consulting — riseup.marketing</p>
</body>
</html>
