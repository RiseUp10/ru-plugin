<html>
<body style="font-family: Arial, sans-serif; line-height:1.5; background:#150505; color:#EEEBEB;">
    <h2>Il tuo abbonamento è attivo 🎉</h2>

    <p>Grazie! Da questo momento il tuo piano <strong><?= esc_html($plan ?? '') ?></strong>
    (<?= esc_html($interval === 'yearly' ? 'annuale' : 'mensile') ?>) è attivo.</p>

    <p>Se qualcosa non torna o hai bisogno di aiuto, rispondi pure a
    questa email, ti risponde una persona vera, non un sistema
    automatico.</p>

    <p style="font-size:12px; color:#999;">RiseUp Marketing, riseup.marketing</p>
</body>
</html>
