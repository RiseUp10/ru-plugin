<html>
<body style="font-family: Arial, sans-serif; line-height:1.5; background:#150505; color:#EEEBEB;">
    <h2>Il tuo sito è pronto per una prima occhiata</h2>

    <p>Dacci un'occhiata e facci sapere cosa ne pensi:</p>

    <p>
        <a href="<?= esc_url($preview_page_url) ?>" style="display:inline-block; padding:12px 22px; border:1px solid #EEEBEB; background:#150505; color:#EEEBEB; text-decoration:none; border-radius:20px;">
            Vedi l'anteprima
        </a>
    </p>

    <p style="font-size:13px; color:#bbb;">Il tuo pacchetto include <?= (int) ($revision_limit ?? 1) ?> round di revisione
    gratuit<?= (int) ($revision_limit ?? 1) === 1 ? 'o' : 'i' ?>. Oltre questo, eventuali modifiche aggiuntive hanno un
    costo a parte — te ne parliamo caso per caso.</p>

    <p>Hai un dubbio? Rispondi pure a questa email — ti risponde una
    persona vera, non un sistema automatico.</p>

    <p style="font-size:12px; color:#999;">RiseUp Consulting — riseup.marketing</p>
</body>
</html>
