# RU Subscription System — Build Pack

> Referencia para construir en RiseUp (`riseup.marketing`) un sistema de
> suscripciones equivalente al que ya funciona en producción en SalvaCash.
> No es una propuesta de split (ver `ARCHITECTURE-RU-SC-SPLIT.md`, descartado) —
> es copiar un mecanismo probado a un segundo producto standalone, por
> CLAUDE.md sección 7: "RU se construye standalone... sin sync."
>
> **Decisión (2026-08-18): `ru-shop` queda fuera de esta arquitectura.**
> El cobro no pasa por un Shop separado — pasa directo por `riseup.marketing`,
> que ya tiene WP Simple Pay instalado. No se vuelve a nombrar `ru-shop` en
> este documento.

## 0. Regla madre equivalente para RU

En SC es: *Main decide, Wallet guarda, Shop cobra, Make orquesta*.
Para RU, con las piezas que ya existen:

> **Hub (ru-plugin) decide, RU Shop cobra, Make orquesta.**

"Hub" = donde vive el estado real de la suscripción (qué plan tiene cada
cliente) y la lógica de acceso. No existe todavía — es el hueco principal
de este pack (ver sección 3D).

## 1. Cómo funciona el sistema de SC hoy (lo que estás por copiar)

Ya está en producción y probado. Mapa completo:

| Capa | Archivo/componente en SC | Qué hace |
|---|---|---|
| Cobro | `shop/` (`sc-shop`) — páginas `checkout-pro-subscription`, `checkout-business-subscription` | Formularios WP Simple Pay/Stripe, uno por plan. JS (`shop_sc-frontend.js`) captura promo code del form al submit. |
| Orquestación | Make.com, escenario **Payments** | Webhook nativo de WP Simple Pay (no Stripe directo). Filtra por `simpay_form_id` (uno por plan). Loguea a Google Sheets. Llama 2x a Main. |
| Constantes | `main-site/core_functionalities.php` | `SC_CHECKOUT_PRO_URL`, `SC_CHECKOUT_BUSINESS_URL`, `SC_CHECKOUT_START_URL`, `SC_CHECKOUT_SETUP_URL`, `SC_CHECKOUT_BOOST_URL`, `SALVACASH_MAKE_SECRET` (en wp-config, no en repo). |
| Endpoint entrante | `main-site/includes/make.php` → `sc_make_callback_endpoint()` | `POST /wp-json/salvacash/v1/callback`. Valida header `x-salvacash-secret` contra `SALVACASH_MAKE_SECRET`. Body trae `type` + `data`. Despacha `do_action('sc_make_callback_' . $type, $data)` — 403 si el secret no matchea, 400 si no hay handler para ese `type`. |
| Decisión/acceso | `main-site/includes/billing.php` (55 líneas, todo el "billing" de SC vive acá) | `sc_brand_plan($brand_id)` lee meta `subscription_plan` (default `starter`). `sc_brand_can_view_stats()` — ejemplo de gate. Handler `sc_make_callback_subscription_activate` — escribe el plan, manda email de confirmación **directo** (no vía Make). Handler `sc_make_callback_subscription_downgrade` (en `make.php`) — fuerza plan a `starter`, registra razón/fuente/fecha. |
| CTA de venta | `main-site/includes/interface.php` — shortcode `[sc_pricing_buy]` | Gate de login → gate de "tenés una marca" → compara rank del plan actual vs el que se está por comprar (no re-vender un plan igual o menor) → arma el botón con `data-checkout` = URL de Shop. |
| Dashboard | `main-site/includes/dashboards.php` | Reusa las mismas constantes `SC_CHECKOUT_*` para los CTA de upgrade dentro del dashboard. |
| Auditoría | Make.com, escenario **Subscriptions Audit** | Corre aparte (no dispara desde Main). Lee Sheets, manda emails vía Brevo directo (`sendinblue:SendEmail`, no pasa por el endpoint de Main). Red de seguridad por si un callback se pierde. Nota: ni siquiera en SC está bien auditado el detalle (ver `make-scenarios/CLAUDE.md` deuda técnica). |

**Deuda conocida incluso en la versión de SC** (para no repetirla en RU si podés evitarla desde el día 1): el email de confirmación de upgrade se agregó recién en agosto, después de meses en producción. En RU, meterlo desde el principio.

## 2. Lo que ya existe del lado de RU (no reconstruir)

- **WP Simple Pay (Stripe)** — ya está instalado directo en el sitio
  principal de `riseup.marketing`. Es la instalación que cobra — no hay
  Shop separado en esta arquitectura (decisión de la nota al inicio del
  documento). Falta cargar los formularios reales, uno por plan (hoy no
  hay ningún producto/plan cargado).
- **`ru-plugin`** — vive en `riseup.marketing`, es el candidato natural a
  ser "hub". Hoy solo tiene herramientas SEO/leads (`seo_report`,
  `lead_optins`, `accademia`) — nada de CPT de cliente, nada de billing,
  nada de endpoint REST para Make. Sí tiene ya un despachador de mails
  reusable (`email-manager.php` → `riseup_send_email()`), que sirve de
  base para el email de confirmación.

## 3. Lo que falta construir (gap list, mapeado 1:1 contra SC)

### A. Modelo de datos
- Definir dónde vive el plan del cliente: ¿CPT propio (`ru_client`) o meta
  en el usuario de WP? SC usa post meta en el CPT `brand` porque un usuario
  puede tener varias marcas — RU hoy no tiene esa complejidad (confirmado
  en `CLAUDE.md` sección 7, punto 6: "no hay relación que migrar"), así que
  probablemente **user meta alcanza** y evita un CPT de más.
- Definir los tiers de RU (nombres, cuántos — CLAUDE.md sección 7 punto 1
  menciona "sus propios 2 abonos", sin nombrarlos todavía).

### B. Cobro (`riseup.marketing`, vía WP Simple Pay directo)
- Cargar los formularios WP Simple Pay reales, uno por plan, en el sitio
  principal.
- Definir constantes `RU_CHECKOUT_*_URL` (equivalente a `SC_CHECKOUT_*_URL`),
  mantenidas por `ru-plugin` (hub), apuntando a las páginas de checkout
  del propio `riseup.marketing`.

### C. Orquestación (Make)
- Clonar el escenario **Payments** → repuntar a un webhook y `simpay_form_id`
  propios de RU. **Nunca reusar** la API key ni el webhook de SC.
- Nuevo secreto compartido (ej. `RU_MAKE_SECRET`), independiente de
  `SALVACASH_MAKE_SECRET`, sincronizado entre el wp-config de RU y los
  headers del escenario en Make.
- Opcional, más adelante: clonar **Subscriptions Audit** como red de
  seguridad.

### D. Decisión/acceso (nuevo — `ru-plugin/includes/billing.php`)
- Ruta REST `/ru/v1/callback` (mismo patrón que `sc_make_callback_endpoint`):
  valida secret por header, despacha `do_action('ru_make_callback_' . $type, $data)`.
- `ru_client_plan($user_id)` + helpers de gate (`ru_client_can_X()`).
- Handlers `ru_make_callback_subscription_activate` /
  `..._downgrade`, mismo patrón que SC.
- Email de confirmación disparado directo desde el handler de activate
  (no vía Make) — reusar `riseup_send_email()` que ya existe.

### E. CTA de venta
- Shortcode `[ru_pricing_buy]` para la página de precios de
  `riseup.marketing` — mismo patrón de gates que `[sc_pricing_buy]` (login
  → elegibilidad → no re-vender el mismo plan o uno menor).

### F. Documentación
- Actualizar `ru-shop/CLAUDE.md` y crear/actualizar `ru-plugin/CLAUDE.md`
  una vez construido, en el mismo commit (convención del workspace).

## 4. Decisiones bloqueantes — TODAS RESUELTAS (18 ago 2026)

1. ~~¿Dónde vive "hub"?~~ — **Resuelto:** se extiende `ru-plugin`. Confirmado
   además que coincide con el comportamiento real de SC hoy (no solo el
   ideal documentado): Make mueve datos y dispara avisos — incluido el
   chequeo de umbral de días vencidos — pero la única mutación de estado
   (activar/downgradear el plan) la hace siempre Main. Ver flujo detallado
   en la sección 7 (nueva, abajo).
2. ~~¿Qué instalación de WP Simple Pay cobra de verdad?~~ — **Resuelto:**
   `riseup.marketing` directo, no un Shop separado (ver nota al inicio del
   documento).
3. ~~Tiers y precios de RU~~ — **Resuelto:** dos planes, definidos en
   `ESTRATEGIA-MONOPRODUCTO.md` — **Mantenimiento** (100-120€/mes: hosting +
   monitoreo + reporte mensual + SEO audit→ajustes) e **Hiperlocal**
   (170-200€/mes: Mantenimiento + gestión de Google Business Profile).
4. ~~Cuenta de Stripe~~ — **Resuelto:** misma cuenta que SC (el usuario es
   autónomo, una sola identidad fiscal — separar cuentas no separa fondos
   de todos modos). Se separan reportes por Producto/Price, uno por plan de
   RU, distinto de los productos de SC dentro de la misma cuenta.
5. ~~¿CPT de cliente o user meta?~~ — **Resuelto:** user meta. RU no tiene
   la complejidad de "varias marcas por usuario" que sí tiene SC.

## 7.5 Pendiente — Etapa A de la aplicación (`ru_application`)

- UX de una pregunta a la vez, guardado progresivo por respuesta (AJAX) —
  permite capturar leads parciales que abandonan a mitad de la aplicación.
- CPT nuevo `ru_application`, post meta nativo (sin ACF — mismo patrón que
  `seo_report`/`lead_optins`, confirmado que no se usa ACF en ningún lado
  del plugin).
- **Orden confirmado (18 ago 2026)** — verificación de identidad primero,
  el resto de las preguntas después (mejora retargeting: contacto
  verificado desde el arranque, no al final):
  1. Email → doble opt-in existente (`verification.php`, link por mail)
  2. Celular → código de 6 dígitos por **SMS vía Brevo** (ya hay
     `BREVO_API_KEY`, tienen API de SMS transaccional — se evalúo WhatsApp
     Business API y se descartó por ahora: requiere verificación de
     negocio en Meta, número remitente propio, y aprobación previa de
     plantilla del mensaje — mucho más setup que Brevo SMS, que ya está
     listo para usar)
  3. Nombre del negocio, relato, objetivo, sitio/social actual, +
     **ubicación** (nueva, orientativa/no bloqueante — el modelo ya no está
     limitado a Lombardia, se agrega solo a título informativo)
- **Nuevo mecanismo, no reusa `verification.php` tal cual**: el de email es
  un link (click), el de celular es un código que el usuario tipea de
  vuelta — necesita su propio endpoint AJAX de validación.
- **Detalle de UX a resolver en la implementación**: el link de
  confirmación de email debe devolver al usuario al mismo flujo (ID de la
  aplicación en la URL) para continuar automáticamente en el paso del
  celular — no mandarlo a una página de "gracias" suelta como
  `/email-confirmed/` (que sirve para los otros flujos, no para este).
- Pendiente: cómo usar la meta data de aplicaciones parciales/abandonadas
  para **retargeting** — pixel/audiencia de Meta o Google Ads, o flujo de
  recuperación por email vía Brevo.
- **Aprobación — NO automática en v1** (decidido 18 ago 2026): la revisión
  del contenido, la exclusión de rubros y la decisión aprobar/rechazar las
  hace el usuario a mano, caso por caso — no hay motor de reglas que
  construir por ahora. Simplifica el alcance inicial:
  - Solo hace falta una forma de ver las aplicaciones enviadas en
    wp-admin (alcanza con la lista nativa del CPT, no requiere UI custom
    para v1).
  - Rechazo: mail personal escrito a mano por el usuario, **fuera del
    sistema** (no hay que automatizar un template de rechazo todavía).
  - Aprobación: si dispara automáticamente el siguiente paso (mail con
    Google Doc + link de pago) o si también es manual, queda para cuando
    se implemente — no bloquea el desarrollo de la Etapa A en sí.
  - Dedup de aplicaciones repetidas: no es una preocupación por ahora.

**Implementado (18 ago 2026)**: CPT `ru_application` + `includes/application-core.php`
+ `js/application.js` + ajuste en `verification.php` (landing por tipo).
Flujo: email (doble opt-in existente) → celular (código SMS vía Brevo,
requiere agregar `BREVO_API_KEY` a wp-config.php, no existe todavía —
solo están `ANTHROPIC_API_KEY`/`GOOGLE_PSI_API_KEY`) → 5 preguntas
(nombre, relato, objetivo, presencia actual, ubicación orientativa) →
submit. Guardado progresivo por AJAX en cada paso.

Límite conocido, no bloqueante para v1: si el usuario refresca la página
durante las preguntas de negocio (después de verificar celular pero antes
de terminar), el flujo reinicia desde la primera pregunta en vez de
retomar la que quedó — no se pierde el lead (ya está guardado), pero
repite preguntas ya contestadas. Mejora futura: que `ru_application_status`
devuelva también los campos ya guardados para que el JS salte los que ya
tienen valor.

Pendiente de verificar antes de producción: registro/aprobación de sender
SMS en Brevo para números italianos (`ru_send_sms_via_brevo()` asume que
ya existe), y validación real de formato de teléfono en
`ru_normalize_phone()` (hoy solo limpia caracteres, no confirma que sea
un número válido).

## 7. Flujo real de SC, confirmado en detalle (referencia para construir el de RU)

**Parte 1 — De la compra a la activación**

1. El usuario elige un plan en la página de precios. El link de pago lleva
   pegado un identificador de a quién pertenece la compra (a qué marca/cliente).
2. Paga en el checkout externo. Ese identificador queda como metadata del
   pago en Stripe (no es parte del monto ni de la tarjeta).
3. Al confirmarse el pago, un webhook avisa a Make.
4. Make saca el identificador y el plan comprado, deja registro en una
   planilla (auditoría/backup legible por humanos), y avisa al sitio
   principal (identificador + plan).
5. El sitio principal valida el aviso (secreto compartido en el header) y
   recién ahí actualiza el plan del usuario/marca.
6. El sitio principal manda el mail de confirmación directo, sin pasar por Make.

**Parte 2 — Cómo se sostiene la suscripción después**

- El plan activo se guarda como dato del usuario/marca en el sitio
  principal — única fuente de verdad (no Stripe, no Make).
- Ciclo de pago: 30 días. Día 28 (2 días antes del vencimiento): mail
  automático de aviso de próximo cobro. Día 35 (7 días después del aviso =
  5 días después del vencimiento): chequeo — si no se pagó, downgrade por
  el mismo mecanismo (mismo endpoint, mismo secreto, cambia el tipo de
  evento), Main guarda motivo/fecha para auditar después. **RU replica
  exactamente este mecanismo** — el "5 días" ya definido para RU en
  `ESTRATEGIA-MONOPRODUCTO.md` es este mismo margen post-vencimiento, no un
  número distinto.
- Aparte, corre una revisión periódica independiente (no reacciona a un
  pago puntual): lee la planilla, detecta pagos vencidos/fallidos, dispara
  el downgrade por el mismo mecanismo, y manda un mail de aviso — pero ese
  mail sale directo desde la herramienta de email marketing, sin pasar por
  el sitio principal.
- Ni Make ni la planilla deciden nada de negocio por su cuenta — solo
  mueven datos y disparan avisos. La única que decide si el plan cambia es
  el sitio principal.

## 5. Orden sugerido de construcción

1. Resolver las decisiones de la sección 4.
2. Cargar los formularios reales en `riseup.marketing` + definir
   slugs/constantes de checkout.
3. Construir `ru-plugin/includes/billing.php` (endpoint REST + helpers de
   gate) — se puede armar y probar con payloads falsos antes de que Make
   exista del todo.
4. Clonar el escenario Payments en Make, repuntado a RU.
5. Shortcode de pricing en `riseup.marketing`.
6. Template de email de confirmación (aprendiendo de la deuda de SC:
   meterlo desde el día 1, no después).
7. Más adelante: clon de Subscriptions Audit para auditoría periódica.

## 6. Convenciones a respetar (heredadas del workspace)

- No guardar secretos en el repo ni en este documento — `RU_MAKE_SECRET`
  y las claves de Stripe van a wp-config, fuera de git.
- `riseup.marketing` cobra (vía WP Simple Pay), no decide permisos de
  negocio — eso vive en hub (`ru-plugin`).
- No duplicar lógica de negocio en Make — un módulo de Make arma payload y
  llama al endpoint, la regla vive en `ru-plugin`.
- Cada sesión termina con commit + actualización de CLAUDE.md/CHANGELOG
  del repo que se tocó.
