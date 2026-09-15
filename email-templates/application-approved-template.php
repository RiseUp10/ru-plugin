<html>
<body style="font-family: Arial, sans-serif; line-height:1.5; background:#150505; color:#EEEBEB;">
    <h2>La tua candidatura è stata approvata! 🎉</h2>

    <p>Ottima notizia, ci piacerebbe lavorare con te.</p>

    <p>Il prossimo passo è raccontarci come vuoi il tuo sito: stile,
    logo, colori, e le pagine che ti servono. Ci mette pochi minuti:</p>

    <?php if (!empty($onboarding_form_url)): ?>
        <p>
            <a href="<?= esc_url($onboarding_form_url) ?>" style="display:inline-block; padding:12px 22px; border:1px solid #EEEBEB; background:#150505; color:#EEEBEB; text-decoration:none; border-radius:20px;">
                Compila il modulo
            </a>
        </p>
    <?php else: ?>
        <p><em>[link al modulo da completare]</em></p>
    <?php endif; ?>

    <p>Dopo che lo compili, ci sentiamo per definire i dettagli e ti
    mandiamo il link per procedere.</p>

    <p>Hai un dubbio nel frattempo? Rispondi pure a questa email, ti
    risponde una persona vera, non un sistema automatico.</p>

    <p style="font-size:12px; color:#999;">RiseUp Marketing, riseup.marketing</p>
</body>
</html>
