<html>
<body style="font-family: Arial, sans-serif; line-height:1.5; background:#150505; color:#EEEBEB;">
    <h2>Il tuo sito è pronto per una prima occhiata</h2>

    <p>Dacci un'occhiata:</p>

    <p>
        <a href="<?= esc_url($preview_url) ?>" style="display:inline-block; padding:12px 22px; border:1px solid #EEEBEB; background:#150505; color:#EEEBEB; text-decoration:none; border-radius:20px;">
            Vedi l'anteprima
        </a>
    </p>

    <p>Ti va bene così? Clicca qui per confermare:</p>

    <p>
        <a href="<?= esc_url($confirm_url) ?>" style="color:#EEEBEB;">Conferma il sito</a>
    </p>

    <p>Se invece vorresti cambiare qualcosa, rispondi pure a questa
    email raccontandoci cosa, niente moduli da compilare, ci scriviamo
    direttamente.</p>

    <p style="font-size:13px; color:#bbb;">Il tuo pacchetto include <?= (int) ($revision_limit ?? 1) ?> round di revisione
    gratuit<?= (int) ($revision_limit ?? 1) === 1 ? 'o' : 'i' ?>. Oltre questo, eventuali modifiche aggiuntive hanno un
    costo a parte, te ne parliamo caso per caso.</p>

    <p style="font-size:12px; color:#999;">RiseUp Marketing, riseup.marketing</p>
</body>
</html>
