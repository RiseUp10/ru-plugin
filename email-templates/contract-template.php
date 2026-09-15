<html>
<body style="font-family: Arial, sans-serif; line-height:1.5; background:#150505; color:#EEEBEB;">
    <h2>Il tuo contratto</h2>

    <p>Ecco il riepilogo definitivo di cosa hai scelto:</p>

    <table style="width:100%; max-width:500px; border-collapse:collapse;">
        <tr><td style="padding:2px 0;">Sito base</td><td style="text-align:right;">1€</td></tr>
        <?php foreach ($addon_lines as $line): ?>
            <tr><td style="padding:2px 0;"><?= esc_html($line['label']) ?></td><td style="text-align:right;"><?= esc_html(number_format($line['price'], 2)) ?>€</td></tr>
        <?php endforeach; ?>
        <tr><td style="padding:6px 0; border-top:1px solid #EEEBEB;"><strong>Totale</strong></td><td style="text-align:right; padding:6px 0; border-top:1px solid #EEEBEB;"><strong><?= esc_html(number_format(1 + $extra_cost, 2)) ?>€</strong></td></tr>
    </table>

    <?php if (!empty($plan_label)): ?>
        <p style="margin-top:10px;"><strong>Abbonamento:</strong> <?= esc_html($plan_label) ?></p>
    <?php endif; ?>

    <p style="font-size:13px; color:#bbb; margin-top:15px;">L'importo indicato è definitivo. Cambia solo se: richiedi
    funzionalità aggiuntive, superi le revisioni incluse, o chiedi un cambio di stile/design diverso
    da quello scelto, in questi casi ti presentiamo un preventivo chiuso prima di iniziare.</p>

    <?php if (!empty($terms_url)): ?>
        <p style="font-size:12px; color:#bbb;">Questo riepilogo si aggiunge alle
        <a href="<?= esc_url($terms_url) ?>" style="color:#bbb;">Condizioni Generali</a> complete, che restano valide per tutto il resto.</p>
    <?php endif; ?>

    <p>Hai un dubbio? Rispondi pure a questa email, ti risponde una
    persona vera, non un sistema automatico.</p>

    <p style="font-size:12px; color:#999;">RiseUp Marketing, riseup.marketing</p>
</body>
</html>
