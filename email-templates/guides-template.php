<?php
// Email template per l'invio della guida (lead magnet).
// Variabili disponibili (via extract() in rum_render_template()):
// $name, $sector, $resource, $resource_url

$name         = isset($name) ? $name : '';
$sector       = isset($sector) ? $sector : '';
$resource_url = isset($resource_url) ? $resource_url : 'https://riseup.marketing/risorse/';
?>
<html>
<head>
<meta charset="UTF-8">
<style>
body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
.cta-button { display: inline-block; padding: 12px 24px; background: #150505; color: white; text-decoration: none; border-radius: 6px; margin-top: 20px; }
</style>
</head>
<body>

<p>Ciao <?php echo esc_html($name); ?>,</p>

<p>Come promesso, abbiamo preparato una risorsa per attività come la tua<?php echo $sector ? ' nel settore ' . esc_html($sector) : ''; ?>.</p>

<p>Non ti cambierà la vita, ma ti aiuterà ad avviare il processo di cambiamento necessario per la tua attività. È composta da 10 punti, ognuno dei quali contiene passaggi concreti da implementare a partire da domani.</p>

<p>Buona fortuna, e contattaci per qualsiasi cosa tu abbia bisogno.</p>

<div style="text-align: center;">
  <a href="<?php echo esc_url($resource_url); ?>" class="cta-button">Apri la guida</a>
</div>

<p>Buona lettura!<br>
— Il team di Rise Up</p>

</body>
</html>
