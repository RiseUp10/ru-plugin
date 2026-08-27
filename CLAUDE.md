# RU Plugin — contexto

Plugin custom de RiseUp Consulting para `riseup.marketing`. Consolida lo que
antes eran 3 plugins separados (`riseup-seo-tools`, `seo-audit-tool`,
`reviews` — este último murió, se rehace a mano en Elementor).

## Qué hace

- **Audit SEO público** — form en el sitio → scrapea la página + PageSpeed
  Insights → guarda como CPT `seo_report` → manda reporte por email.
- **Audit Schema Markup** — variante que solo chequea JSON-LD/microdata.
- **Lead capture de guías** — formulario Elementor Pro ("risorse") → CPT
  `lead_optins` → manda el recurso por email.
- **Aplicación al monoprodotto (Etapa A)** — `/applica/`, shortcode
  `[ru_application]`. UX de una pregunta a la vez, verifica email (doble
  opt-in) + celular (SMS) antes de las preguntas de negocio, guardado
  progresivo por respuesta en el CPT `ru_application`. Ver detalle abajo y
  `RU-SUBSCRIPTION-SYSTEM-PLAN.md` (sección 7.5) para el diseño completo.
- **Recomendaciones por IA** — en vez de las "opportunities" genéricas que
  devuelve PageSpeed, se le pasan los datos del audit a Claude Haiku 4.5 y
  devuelve 3-4 recomendaciones concretas en italiano.
- **Export a PDF** — de cualquier `seo_report`, vía dompdf.
- **Accademia** — CPT `accademia` (blog/recursos educativos, público, slug
  `/accademia/`) + taxonomía jerárquica `academy_pillar` (slug `/pilastro/`)
  para organizar los artículos por pilar temático.

## Estructura

```
ru-plugin.php          bootstrap, requiere todo lo de includes/
includes/
  ai-helpers.php        ru_ai_complete() — genérico, no específico de ningún audit
  cpt-register.php      CPTs: seo_report, lead_optins, ru_application
  verification.php       doble opt-in compartido (ver abajo)
  application-core.php   aplicación al monoprodotto (Etapa A, ver abajo)
  email-helpers.php      utilidades de templating de mail (rum_*)
  email-manager.php      riseup_send_email() — despachador por template
  email-report.php       email del audit SEO
  schema-email-report.php email del audit Schema
  seo-audit-core.php     AJAX handlers, scraping, PSI, cron de reintentos
  elementor-integration.php  hook del form "risorse"
  pdf-report.php          export a PDF (dompdf)
email-templates/         plantillas HTML de los mails
templates/                otras plantillas (guía en PDF, sin usar aún)
js/, assets/              front-end del audit + de la aplicación
vendor/dompdf/             vendored a mano, no vía composer
```

## Doble opt-in (antispam)

Los 3 flujos de lead-gen (audit SEO, audit Schema, guías) comparten el mismo
mecanismo en `includes/verification.php`: no se manda nada real hasta que el
dueño del email confirma por link. `ru_send_verification_email($post_id,
$email, $type)` dispara la confirmación; el trabajo real cuelga de
`do_action('ru_verified_' . $type, $post_id)` — cada flujo engancha su
propio hook (`ru_verified_seo_audit`, `ru_verified_schema_audit`,
`ru_verified_guide`).

**Depende de una página en Elementor**: `/email-confirmed/`, con el
shortcode `[ru_audit_status]` insertado en el contenido — muestra el mensaje
correcto según `?status=ok|used|expired|invalid`. Si esa página no existe,
el redirect después de confirmar da 404.

Antes del opt-in hay honeypot (campo oculto, inyectado por JS) + rate limit
(3 audits/día por IP). Esto no evita que alguien meta el email de otra
persona — no está pensado para eso, corta bots/abuso por volumen.

## Aplicación al monoprodotto (Etapa A)

`includes/application-core.php` + `js/application.js`. Flujo completo en
`RU-SUBSCRIPTION-SYSTEM-PLAN.md` sección 7.5 — acá solo lo operativo:

- **Identidad antes que preguntas**: email (reusa `verification.php`,
  `type='application'`) → celular (código de 6 dígitos por SMS, API
  transaccional de Brevo). Ninguna pregunta de negocio se guarda
  (`ru_application_save_field`) hasta que ambos están verificados — lo
  chequea el propio backend, no solo el JS.
- **`verification.php` tiene una rama especial para `application`**: en vez
  de redirigir a `/email-confirmed/` (landing genérica de los otros 3
  flujos), redirige a `/applica/?post_id=X` para retomar el flujo en el
  paso del celular. El JS nunca confía ciegamente en ese query string —
  siempre re-chequea contra `ru_application_status` antes de avanzar.
- **Reenvío sin reiniciar**: `ru_application_resend_email` reusa el mismo
  token (no crea una aplicación nueva) — rate limit de 60s entre reenvíos.
- **Rate limit de arranque (3/día/IP) exceptuado en local**
  (`wp_get_environment_type() === 'local'`, ya seteado en el wp-config de
  Local) — si no, se pisa enseguida probando desde la misma IP de casa.
- **Aprobación manual, deliberado (v1)**: no hay motor de reglas. La
  revisión es a mano, vía la lista nativa del CPT `ru_application` en
  wp-admin — no se automatizó a propósito, ver build pack.
- **`RU_APPLICATION_FIELDS`** (en `application-core.php`) es la whitelist
  de campos que acepta el guardado genérico — agregar ahí antes de sumar
  una pregunta nueva al array `QUESTIONS` del JS, si no el backend la
  rechaza.

**Depende de una página en Elementor**: `/applica/`, con el shortcode
`[ru_application]` insertado en el contenido — el JS arma toda la UI ahí
adentro, no hace falta nada más en la página.

## Secretos

- `ANTHROPIC_API_KEY` en `wp-config.php` (fuera del repo). Sin ella,
  `ru_ai_complete()` loguea el error en `debug.log` y devuelve `null` — el
  audit sigue funcionando, solo sin la sección de recomendaciones.
- `BREVO_API_KEY` en `wp-config.php` — requerida por `application-core.php`
  para mandar el SMS de verificación (API transaccional de Brevo, no el
  SMTP que usa WP Mail SMTP para el resto de los mails). Sin ella, el paso
  del celular loguea el error y no manda nada. La cuenta de Brevo necesita
  el add-on de SMS activado (créditos comprados) y un sender aprobado —
  si no, Brevo devuelve 400 aunque la key esté bien.
- **IPs nuevas bloqueadas por Brevo**: tanto el envío de mail (si WP Mail
  SMTP usa la API de Brevo en vez de SMTP puro) como el de SMS fallan
  silenciosamente (o con 401/error genérico) si la IP del servidor no está
  en la whitelist de la cuenta — `https://app.brevo.com/security/authorised_ips`.
  Le pasó en dev con la IP de casa; en prod ya está anotado en Deuda
  Técnica del CLAUDE.md del workspace (IP de Hostinger).

## Deuda / pendiente

- El generador de PDF (`pdf-report.php`) y el email template
  (`seo-audit-template.php`) ya no muestran las "opportunities" crudas de
  PSI — están comentadas, no borradas, por si hace falta volver atrás.
- Idea en el radar, sin empezar: un audit general **interno** (no público)
  para prospección — el equipo corre el audit contra el dominio de un
  prospecto y manda un teaser (20% de los hallazgos) como outreach. No
  necesita el doble opt-in de los flujos públicos.
- Aplicación (Etapa A): falta CSS real (hoy es un primer paso mínimo en
  `assets/css/style.css`, a retocar con el diseño final), y la
  normalización de teléfono (`ru_normalize_phone()`) es básica — asume
  Italia (+39) si no viene con "+", no valida formato real.
