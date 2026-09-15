<html>
<body style="font-family: Arial, sans-serif; line-height:1.5; background:#150505; color:#EEEBEB;">
    <?php if (!empty($voluntary)): ?>
        <h2>Il tuo abbonamento è stato annullato</h2>
        <p>Come richiesto, non ti verranno più addebitati costi.</p>
    <?php else: ?>
        <h2>Non siamo riusciti a rinnovare il tuo abbonamento</h2>
        <p>Il pagamento non è andato a buon fine e il tuo abbonamento è
        stato sospeso. Se vuoi continuare, basta aggiornare il metodo di
        pagamento.</p>
    <?php endif; ?>

    <p>Il sito non sarà più online da parte nostra.</p>

    <?php if (!empty($download_link)): ?>
        <p>Ecco il link per scaricare una copia completa e modificabile del
        tuo sito (valido 7 giorni):</p>
        <p>
            <a href="<?= esc_url($download_link) ?>" style="display:inline-block; padding:12px 22px; border:1px solid #EEEBEB; background:#150505; color:#EEEBEB; text-decoration:none; border-radius:20px;">
                Scarica il tuo sito
            </a>
        </p>
    <?php else: ?>
        <p>Stiamo preparando il pacchetto per scaricare una copia completa
        del tuo sito, se non lo ricevi entro qualche giorno scrivici a
        <a href="mailto:servizioclienti@riseup.marketing" style="color:#EEEBEB;">servizioclienti@riseup.marketing</a>
        e te lo mandiamo subito.</p>
    <?php endif; ?>

    <?php if (!empty($resubscribe_url)): ?>
        <p>Se cambi idea, puoi riattivare l'abbonamento in qualsiasi momento:</p>
        <p>
            <a href="<?= esc_url($resubscribe_url) ?>" style="color:#EEEBEB;">Riattiva l'abbonamento</a>
        </p>
    <?php endif; ?>

    <p>Per qualsiasi dubbio, rispondi pure a questa email, ti risponde una
    persona vera, non un sistema automatico.</p>

    <p style="font-size:12px; color:#999;">RiseUp Marketing, riseup.marketing</p>
</body>
</html>
