# Changelog — RU Plugin

## 2026-08-27

**Aplicación al monoprodotto (Etapa A) + remitente de mail + fixes varios**

- **Nuevo: `ru_application`** — CPT + flujo de aplicación al sitio 1€ (Etapa A del pivot a monoproducto, ver `RU-SUBSCRIPTION-SYSTEM-PLAN.md`). UX de una pregunta a la vez (`js/application.js`, shortcode `[ru_application]`), guardado progresivo por respuesta vía AJAX (permite retargeting de leads que abandonan a mitad de camino). Sin ACF — post meta nativo, mismo patrón que `seo_report`/`lead_optins`.
- **Verificación de identidad en dos pasos**: email (reusa el doble opt-in de `verification.php`, `type='application'`) + celular (código de 6 dígitos por SMS vía API transaccional de Brevo, requiere `BREVO_API_KEY` en wp-config.php). `verification.php` ahora redirige el tipo `application` de vuelta a `/applica/` (con `post_id`) en vez de a la landing genérica `/email-confirmed/`, para retomar el flujo en el paso del celular.
- **Reenvío de confirmación**: si el mail no llega, se puede reenviar el mismo (mismo token, no crea una aplicación nueva) — antes solo se podía reiniciar con otro email.
- **Aprobación manual (v1, deliberado)**: no hay motor de reglas — la revisión de cada aplicación es manual, vía la lista nativa del CPT en wp-admin.
- **Remitente de todos los mails del plugin corregido**: era `noreply@riseup.marketing`, contradecía el principio del workspace de no sonar frío/corporativo. Ahora `Roberto da RiseUp <roberto@riseup.marketing>`, con Reply-To al mismo mail (no a un `contatto@` genérico) — afecta a los 4 flujos (SEO, Schema, guías, candidatura).
- **Fixes de paso** (parte de la misma sesión): `guides-template.php` reescrito a HTML real (antes mandaba texto plano armado a mano, con referencia a una ruta vieja pre-consolidación); `email-report.php` con el nombre de template corregido para matchear `email-manager.php`; `schema-email-report.php` ya no hace doble-decode de `schema_valid` (se guarda como array nativo desde `schema_audit_full_job`, no como JSON string); `seo-audit-core.php` con el `schema_audit_run()` viejo comentado (reemplazado hace rato por `init_schema_audit()`, quedaba código muerto activo).

## 2026-08-06

**Email delivery fixes + API upgrade + Schema audit redesign**

- **SEO Audit**: Switched from unreliable third-party Render proxy to official Google PageSpeed Insights API (requires `GOOGLE_PSI_API_KEY` in wp-config.php). Added 3 synchronous retries with backoff to handle transient failures.
- **SEO Audit**: Fixed email template rendering (was showing raw Array dump). Rewrote template with proper variable checks, CSS styling, and prominent PSI failure warnings with retry instructions.
- **SEO Audit**: Email now only goes to requestor (removed BCC to admin).
- **Schema Audit**: Completely redesigned to match SEO flow — URL + Email mandatory upfront, email confirmation required before analysis runs. Analysis now executes in background after verification, not immediately.
- **Schema Audit**: Fixed email validation (was only checking if empty, not validating format).
- **Schema Audit**: Email now uses `riseup_send_email()` wrapper instead of raw `wp_mail()` (ensures SMTP routing).
- **Schema Audit**: If schema NOT found, no email sent (reduces noise; only sends when schema exists).

## 2026-07-29

Agregado el CPT `accademia` (blog/recursos, público) + taxonomía jerárquica
`academy_pillar` para organizarlo por pilar temático. Era código pegado
suelto (función sin el hook `init`, taxonomía registrada fuera de cualquier
hook — no llegaba a correr tal como estaba) — se corrigió al integrarlo:
se le sacó el prefijo `salvacash_` del nombre de función (se porta a RU, no
se queda con el nombre de origen) y se enganchó todo correctamente a `init`.

## 2026-07-28

Primera versión del plugin consolidado. Antes eran 3 plugins separados
(`riseup-seo-tools`, `seo-audit-tool`, `reviews`) instalados sueltos en
producción; se migró el sitio completo a Local y se fusionaron acá.

Qué cambió respecto a lo que había en producción:

- **Estructura aplanada**: nada de carpetas tipo sub-plugin — un solo
  `includes/` para toda la lógica, `email-templates/`, `js/`, `assets/`,
  `vendor/` a nivel raíz.
- **`reviews` se dio de baja** — estaba sobre-armado para simular reviews
  falsas; se va a rehacer a mano en Elementor. No se migró.
- **Audit SEO pasó a ser asíncrono**: antes el visitante esperaba hasta 3
  minutos (scrape + PageSpeed + email, todo antes de responder). Ahora
  responde al toque y el trabajo pesado corre después de cerrar la
  conexión.
- **Se sacó el guard de dominio del email** (el que exigía que el email
  "correspondiera" al sitio auditado) — era trivialmente bypasseable
  (matching por substring) y solo generaba fricción a gente real.
- **Antispam nuevo**: honeypot + rate limit (3/día por IP), en vez del
  guard de dominio.
- **Doble opt-in**: ningún flujo de lead-gen (audit SEO, audit Schema,
  guías) manda nada real hasta que el dueño del email confirma por link.
  Antes se mandaba directo.
- **Recomendaciones por IA**: se reemplazaron las "opportunities" genéricas
  de PageSpeed (texto de Google, no análisis real) por 3-4 recomendaciones
  concretas generadas con Claude Haiku 4.5, a partir de los datos reales
  del audit.
- **Timeout del proxy de PageSpeed** subido de 10s a 30s — como ahora el
  audit corre en background, puede esperar más sin costarle nada al
  visitante, y cubre mejor el cold-start del proxy en Render.
