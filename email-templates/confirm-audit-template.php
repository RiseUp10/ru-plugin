<html>
<body style="font-family: Arial, sans-serif; line-height:1.5; background:#150505; color:#EEEBEB;">
    <h2>Conferma il tuo indirizzo email</h2>

    <?php if (!empty($body_text)): ?>
        <p><?= esc_html($body_text) ?></p>
    <?php else: ?>
        <p>Hai richiesto: <strong><?= esc_html($context_label ?? 'un contenuto') ?></strong>.</p>
        <p>Clicca qui sotto per confermare il tuo indirizzo e riceverlo:</p>
    <?php endif; ?>

    <p>
        <a href="<?= esc_url($confirm_url) ?>" style="display:inline-block; padding:12px 22px; border:1px solid #EEEBEB; background:#150505; color:#EEEBEB; text-decoration:none; border-radius:20px;">
            <?= esc_html($button_text ?? 'Conferma e ricevi') ?>
        </a>
    </p>

    <p style="font-size:12px; color:#999;">Se non hai fatto tu questa richiesta, ignora questa email.</p>
</body>
</html>
