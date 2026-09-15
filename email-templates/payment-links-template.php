<html>
<body style="font-family: Arial, sans-serif; line-height:1.5; background:#150505; color:#EEEBEB;">
    <h2>Ci siamo quasi</h2>

    <p>Ecco i link per procedere:</p>

    <?php if (!empty($site_url)): ?>
        <p><strong>Il tuo sito<?= !empty($extra_cost) ? ' + extra' : ' (una tantum)' ?>:</strong><br>
        <a href="<?= esc_url($site_url) ?>" style="color:#EEEBEB;"><?= esc_html($site_url) ?></a></p>
    <?php endif; ?>

    <?php if (!empty($plan_url)): ?>
        <p><strong>Il tuo abbonamento:</strong><br>
        <a href="<?= esc_url($plan_url) ?>" style="color:#EEEBEB;"><?= esc_html($plan_url) ?></a></p>
    <?php else: ?>
        <p>Hai scelto di procedere senza abbonamento, nessun link
        ricorrente da completare.</p>
    <?php endif; ?>

    <?php if (!empty($addon_lines)): ?>
        <p><strong>Cosa include il totale di oggi:</strong></p>
        <table style="width:100%; max-width:500px; border-collapse:collapse;">
            <tr><td style="padding:2px 0;">Sito base</td><td style="text-align:right;">1€</td></tr>
            <?php foreach ($addon_lines as $line): ?>
                <tr><td style="padding:2px 0;"><?= esc_html($line['label']) ?></td><td style="text-align:right;"><?= esc_html(number_format($line['price'], 2)) ?>€</td></tr>
            <?php endforeach; ?>
            <tr><td style="padding:6px 0; border-top:1px solid #EEEBEB;"><strong>Totale</strong></td><td style="text-align:right; padding:6px 0; border-top:1px solid #EEEBEB;"><strong><?= esc_html(number_format(1 + $extra_cost, 2)) ?>€</strong></td></tr>
        </table>
    <?php endif; ?>

    <?php if (!empty($terms_url)): ?>
        <p style="font-size:12px; color:#bbb;">Questo riepilogo si aggiunge alle
        <a href="<?= esc_url($terms_url) ?>" style="color:#bbb;">Condizioni Generali</a> complete, che restano valide per tutto il resto.</p>
    <?php endif; ?>

    <?php if (!empty($clause_approve_url)): ?>
        <div style="margin:24px 0; padding:16px; border:1px solid #EEEBEB; border-radius:8px;">
            <p style="margin-top:0;"><strong>Un'ultima cosa prima di pagare.</strong></p>
            <p>Ai sensi degli artt. 1341 e 1342 del codice civile, ti chiediamo
            di approvare specificamente questo passaggio delle Condizioni
            Generali (art. 14):</p>
            <p style="font-style:italic; color:#ddd;">&ldquo;Ai sensi e per gli
            effetti degli artt. 1341 e 1342 c.c., dichiaro di approvare
            specificamente le seguenti clausole: 4.6 (rimozione contenuti), 9
            (limitazione di responsabilità), 10 (risoluzione e penali), 12
            (decadenza), 13 (foro competente).&rdquo;</p>
            <?php if (!empty($clause_approved)): ?>
                <p style="color:#9ddc9d;">Hai già approvato queste clausole, grazie.</p>
            <?php else: ?>
                <p>
                    <a href="<?= esc_url($clause_approve_url) ?>" style="display:inline-block; padding:10px 20px; border:1px solid #EEEBEB; background:#150505; color:#EEEBEB; text-decoration:none; border-radius:20px;">
                        Approvo specificamente queste clausole
                    </a>
                </p>
                <p style="font-size:12px; color:#bbb;">Ti chiediamo di farlo
                prima di completare il pagamento.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p>Hai un dubbio nel frattempo? Rispondi pure a questa email, ti
    risponde una persona vera, non un sistema automatico.</p>

    <p style="font-size:12px; color:#999;">RiseUp Marketing, riseup.marketing</p>
</body>
</html>
