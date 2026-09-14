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

## 7.6 Pendiente — Etapa B: aprobación y entrega del Sitio

Definido en conversación (29-31 ago 2026), en paralelo al trabajo sobre
`legal/condizioni-generali-v2-monoprodotto.md` (art. 8.1 y 11.2 de ese
documento son la fuente de verdad legal de este flujo — mantenerlos
sincronizados si algo de esto cambia).

**Flujo de aprobación** (reusa el mecanismo de magic link de
`verification.php`, un `type` nuevo, no un sistema de login):

1. Mail "tu sitio está listo" (remitente personal, no no-reply) con un
   magic link único. El link en sí es la confirmación de identidad — no
   hay paso separado de "confirmá tu identidad" antes del draft, sería
   fricción de más.
2. Click → preview del draft. Dos botones: **Aprobar** / **Pedir un
   cambio** (campo de texto acotado, no chat abierto).
3. Si pide cambio: Rise Up ajusta y reenvía un único link nuevo. Una sola
   ronda de revisión — más cambios que eso quedan fuera del paquete
   estándar (art. 3.1 del contrato: personalizaciones extra requieren
   acuerdo y corrispettivo separado).
4. Si aprueba: se loggea timestamp + IP + token + versión/hash exacto
   del sitio mostrado (valor probatorio — ver art. 11.2 del contrato).
   Dispara mail de confirmación automático: *"confirmamos que aprobaste
   la versión X el [fecha/hora]; si no fue así, avisanos en 24hs."*
   Las 24hs son solo ventana de objeción legal, no bloquean nada — la
   publicación (o entrega, ver abajo) ya ocurrió en este mismo paso.

**Qué pasa según si hay abono activo** (art. 8.1/11.2 del contrato):

- **Con suscripción activa** (Manutenzione o Hiperlocal): el sitio se
  publica online en el momento de la aprobación. Es el camino esperado
  para casi todos los casos — Rise Up va a empujar comercialmente hacia
  acá.
- **Sin suscripción activa**: no hay hosting de parte de Rise Up (ni
  dominio, ni publicación, ni mantenimiento — así quedó en el art. 8.1).
  En vez de eso, la aprobación dispara la entrega de un paquete de
  migración estándar de WordPress: archivos (`wp-content/themes`,
  `wp-content/uploads`, plugins necesarios) + export SQL de la base de
  datos, empaquetados juntos con una herramienta tipo **Duplicator**
  (o All-in-One WP Migration free) — no un export estático en HTML, para
  que el cliente conserve un sitio WordPress/Elementor realmente
  editable, no una foto congelada. Se entrega igual que la aprobación:
  mail con un **link de descarga temporal** (no adjunto directo — evita
  límites de tamaño de email y permite loggear cuándo se descargó, mismo
  criterio probatorio que el resto del flujo), nunca el archivo pegado
  al mail.

**Dependencia técnica a resolver antes de que el export sin suscripción
funcione de verdad**: el formulario de contacto del sitio usa el
mecanismo de doble opt-in de `ru-plugin`, que vive en `riseup.marketing`
— si el cliente se lleva el sitio a otro hosting, el form se rompe sin
ese plugin. Decisión: en el momento del export para un cliente sin
abono, swapear el form por un plugin free liviano y reconocido — hoy la
opción de referencia es **Contact Form 7**, pero no es una elección
cerrada: si más adelante hace falta algo que CF7 no cubra bien (lógica
condicional, mejor UI de configuración), evaluar Fluent Forms u otra
alternativa sin atarse a esta desde ya. Los clientes con suscripción
activa siguen usando el form vía `ru-plugin` normalmente, no hace falta
swap para ellos.

**Pendiente de implementación**: el `type` nuevo de magic link para
aprobación de sitio (extensión de `verification.php`), el logueo de
IP/timestamp/versión, el trigger de export+swap de form para clientes
sin abono, y la integración con Duplicator/AIO WP Migration.

### 7.6.1 Decisiones de sesión (1 sep 2026) — Flow 2 (Approval) y afinado de Flow 3

Confirmado en conversación al analizar los 3 flujos base del monoprodotto
(Applying / Approval / Acceptance):

- **Mail de aprobación de la Etapa A (Flow 2) — automático**: al marcar la
  aplicación como aprobada en wp-admin (cambio de status del CPT
  `ru_application`), se dispara solo el mail con el Google Form de
  onboarding + link de pago — no lo escribe el founder a mano cada vez.
  Contraste con el rechazo, que sigue siendo manual/fuera del sistema (ya
  decidido en §7.5).
- **Herramienta de onboarding de la Etapa B — Google Form, no Google
  Doc** (corrige la mención a "Google Doc" que queda en
  `ESTRATEGIA-MONOPRODUCTO.md`, desactualizada): un Doc de texto libre
  termina en respuestas inconsistentes/incompletas por cliente. Un Form
  da preguntas con tipo definido (texto corto, opción múltiple, carga de
  archivo para logo/fotos — soportado nativo) sin desarrollo nuevo. Se
  evaluó ir directo a un widget nativo en el sitio (mismo patrón que la
  Etapa A: CPT propio, guardado progresivo por AJAX) y se descartó **por
  ahora**: es esfuerzo comparable a lo que ya llevó construir la Etapa A,
  para una etapa de mucho menor volumen (solo aplicaciones ya aprobadas)
  — mismo criterio de no automatizar de más antes de validar el funnel
  completo con datos reales, usado también para no automatizar la
  aprobación en v1 (§7.5). Reevaluar el widget nativo como mejora de v2
  si el volumen lo justifica. Única fricción del Form: requiere cuenta
  Google del cliente para subir archivos — aceptable acá porque ya pagó
  1€ y verificó email+celular en la Etapa A, más compromiso que un lead
  frío de tope de embudo.

  **Preguntas del Form (borrador, 5 sep 2026)**:
  1. Partita IVA o Codice Fiscale (o, alternativa: confirmar que es admin
     del Google Business Profile del negocio)
  2. ¿Tiene dominio propio? ¿Cuál? (requerido antes de publicar, art. 4.8
     del contrato)
  3. Elegir estilo mirando el showcase (`esempi.riseup.marketing`, ver
     abajo) — con opción de mezclar elementos de looks distintos
  4. Logo (carga de archivo) o, si no tiene, colores preferidos
  5. **Menú fijo cerrado (5 sep 2026)**, reemplaza el "etc." abierto de
     `ESTRATEGIA-MONOPRODUCTO.md`: **Contatto / Servizi / Chi Siamo /
     Blog / Galleria d'immagini (fino a 12 immagini) / FAQ / Prodotti
     (fino a 6, con immagine, nome e prezzo opzionale)**. El cliente
     elige hasta 3 de estos.
     - `Prodotti` y `Galleria` llevan tope fijo deliberado: permite un
       solo template de grid (6 y 12 respectivamente, se ocultan las
       tarjetas no usadas) sin lógica dinámica/paginación. Si un cliente
       necesita más, empuja hacia los add-ons ya tarifados en
       `ESTRATEGIA-MONOPRODUCTO.md` (`Catalogo de productos`, `Carga de
       productos/servicios`) — el tope es justamente lo que marca ese
       corte.
     - `Prodotti` es una versión liviana de presentación (sin
       estructura/filtros) — **distinta** del add-on pago "Catalogo de
       productos", que sí implica setup estructurado. Aclarar la
       diferencia en el propio Form para que no se confundan.
     - Precio en `Prodotti` es opcional (no todos los rubros — ej.
       consultoría profesional — quieren precio público).
     - Wording del Form evita la jerga técnica "Home": en vez de "Oltre
       alla Home...", usar algo como *"Oltre alla presentazione della
       tua attività (il cuore del tuo sito), scegli fino a 3 argomenti
       in più tra: [menú fijo de arriba]..."* — describe el contenido,
       no la etiqueta técnica de página, coherente con que además puede
       no ser ni siquiera una página separada (ver punto siguiente).
     - **Aclarar también en el Form que el mismo contenido se puede
       entregar como páginas separadas con menú, o como un único sitio
       "a scorrimento" (one page, todas las secciones en una sola
       página con links de ancla)**. Mismo alcance/precio en ambos
       casos — es una decisión de formato, no de scope (la pregunta es
       "cuántos temas/secciones", "página separada vs. one-page" es
       aparte). Efecto colateral técnico favorable: un cliente que
       elige one-page no necesita el widget Nav Menu con dropdown entre
       páginas (pendiente de confirmar si es free o Pro en la
       instalación actual, ver `CLAUDE.md`) — alcanza con anchors
       nativos dentro de la misma página, evitando ese punto pendiente
       por completo.
  6. Carga de imágenes/fotos (archivo)
  7. Textos por página (o relato libre para que RiseUp los redacte —
     nota: copy profesional es add-on aparte)
  8. Add-ons opcionales (idioma extra, página extra, catálogo, ecommerce,
     copywriting, branding)
  9. Suscripción opcional (Manutenzione / Hiperlocal)

  No se repiten acá las preguntas de identidad del negocio (nombre,
  relato, objetivo, sitio/social actual, ubicación) — ya recolectadas en
  la Etapa A. Al completar el Form se manda el link de pago final (sitio
  + extras elegidos + primer mes de abono si corresponde).

  **Operativa de los archivos subidos (logo, fotos, preguntas 4 y 6)**:
  los archivos caen en una carpeta de Drive asociada a la cuenta dueña
  del Form — **confirmado: cuenta de Gmail propia de Rise Up, no
  personal** (no es un pendiente, ya está resuelto). Para cruzar
  archivo↔cliente sin ambigüedad (nombres de archivo genéricos se
  repiten entre clientes, ej. "logo.png"), vincular el Form a una
  Google Sheet de respuestas (cada fila trae el link directo al archivo
  de esa respuesta) — no navegar la carpeta de Drive a ojo. **Pendiente
  de implementación**: el mail automático de aprobación (§ arriba) debe
  llevar un link **prellenado** al Form (con email/ID de la aplicación
  ya cargado) para que cada respuesta llegue identificable contra el
  `ru_application` correspondiente sin cruce manual.
- **Selección de "template" en la Etapa B (Flow 2) — sitio de muestra, no
  RiseUp eligiendo unilateralmente**: el cliente elige mirando un sitio/
  set de páginas de muestra con las variantes visuales disponibles, no
  solo describiendo estilo en un formulario a ciegas. Fusiona con el
  catálogo de secciones/variantes del motor de ensamblado (ver
  `CLAUDE.md` del workspace, "Próximos Pasos") — construir esos templates
  de Elementor como páginas reales de muestra sirve doble propósito:
  catálogo de trabajo interno + vidriera que el cliente recorre.
  **Resuelto: subdominio aparte** (propuesto `esempi.riseup.marketing`,
  a confirmar nombre), instalación WP separada (no multisite, no
  Theme Builder/widgets Pro — mismo criterio anti-lock-in que los sitios
  de cliente), con una única paleta neutra site-wide (Global Colors es
  ajuste site-wide, no por página — el showcase compara estructura/
  layout por slot, no color; color/tipografía se resuelve por cliente
  vía Google Form + logo, no necesita showcase). MVP: 2-3 "looks"
  completos de Home únicamente (no las 4 páginas todavía), cada uno
  guardado también como Elementor Templates por slot para poder mezclar
  en producción si el cliente lo pide. `noindex` en las páginas del
  showcase. A futuro, esta instalación es la base para exportar los
  `.json` del catálogo versionado en git (motor de ensamblado, ver
  CLAUDE.md).
- **Rondas de revisión en Acceptance (afina §7.6 punto 3, no lo
  contradice)**: se confirma 1 ronda gratis tal como ya estaba escrito.
  A partir de la 2da ronda, **recomendado (a confirmar): 40€ flat por
  ronda** (no facturación por hora real) — parte de la base ~35€/h ya
  usada para add-ons en `ESTRATEGIA-MONOPRODUCTO.md`, redondeado a bloque
  fijo para no tener que trackear/justificar tiempo frente al cliente
  (mismo criterio de mínimo contacto/una sola decisión que el resto del
  pricing). Pedidos grandes (páginas nuevas, reescritura de copy) no
  entran acá — caen en los add-ons ya tarifados (`Página extra`,
  `Copywriting`).
  **Mecanismo operativo, resuelto (5 sep 2026)**: no requiere nada nuevo
  en `ru-plugin`/Make — no gatilla ningún cambio de estado persistente
  (no activa/desactiva plan, no publica nada por sí solo), así que se
  cobra 100% fuera del sistema técnico. Preparar de antemano **Stripe
  Payment Links reutilizables** (vía WP Simple Pay) por cada precio fijo
  ya definido (40€ ronda extra, y uno por cada add-on de precio único) —
  se pega el link que corresponda en la respuesta personal por mail. No
  se arranca el trabajo hasta ver el pago (mismo criterio que el resto
  del funnel: se cobra antes de producir). Para add-ons de **rango**
  (ej. "Página extra 50-70€"), usar **Stripe Invoicing** ad-hoc con el
  monto negociado en vez de un link fijo. **Qué pasa si el cliente pide
  una 2da ronda y no la paga**: no se manda un nuevo link de preview, el
  ciclo queda pausado en la última versión ya mostrada — si el cliente
  igual la aprueba, sigue el flujo normal de publicación/entrega.
- **Personalización de diseño fuera del catálogo (nuevo, 5 sep 2026) —
  distinto de la ronda de revisión de arriba**: cuando el pedido no es
  un ajuste dentro de la variante de estilo ya elegida sino una sección/
  layout que ninguna variante del catálogo cubre ("que quede más
  bonito", diseño custom) — no tiene precio flat posible de antemano.
  Base legal: art. 3.1 bis (nuevo) de
  `legal/condizioni-generali-v2-monoprodotto.md`, que además fija la
  frontera formal entre esto y la ronda de revisión del art. 11.2, para
  que una ronda de revisión no se use para colar un rediseño gratis.
  **Decidido**: tarifa **35€/h** (misma base que los add-ons, no la
  premium de 50€/h que se había sugerido — a este ritmo por ahora),
  **sin mínimo de horas** (se obvia por el momento, reevaluar si genera
  fricción de negociación en pedidos muy chicos), siempre con
  **presupuesto cerrado** (horas estimadas × tarifa, monto total) antes
  de empezar — nunca hora suelta abierta. Se cobra igual que el add-on
  de rango de arriba: **Stripe Invoicing** con el monto ya cotizado.

**Sigue abierto, no resuelto hoy**: qué pasa si el cliente aprobado no
completa el Google Doc o no paga después de la aprobación — no hay
recordatorio/seguimiento definido para este punto del funnel (sí existe
para abandonos de la Etapa A, ver §7.5).

## 7.7 Decisión (14 sep 2026) — Webhooks nativos de Stripe en vez de día 28/35 + planilla

**Cambia** el mecanismo de renovación/gracia/downgrade descrito en la Parte 2
de la sección 7 (abajo). **No cambia** la regla madre (sección 0): Hub decide,
Make orquesta, ninguna decisión de negocio vive en Make.

- **Antes (patrón SC, con deuda conocida)**: Make escucha el webhook nativo
  de WP Simple Pay solo en el checkout inicial; el sostenimiento de la
  suscripción (aviso día 28, downgrade día 35) lo hace una revisión
  periódica aparte que lee la planilla de Google Sheets y detecta vencidos
  a mano. El propio doc admite (sección 1) que ni en SC está bien auditado
  el detalle de este mecanismo — dos caminos de eventos que pueden
  desincronizarse.
- **Ahora, para RU**: se usan los webhooks nativos del ciclo de vida de
  Stripe (`invoice.payment_failed`, `invoice.payment_succeeded`,
  `customer.subscription.updated`, `customer.subscription.deleted`) como
  disparador real, en vez de un timer casero. Motivo: WP Simple Pay ya crea
  suscripciones reales de Stripe por debajo (esto no es nuevo, ya corre
  así) — el único cambio es a qué eventos reacciona Make/hub para decidir
  downgrade. Stripe Smart Retries reemplaza el reintento fijo de "7 días
  después del aviso"; el comportamiento al agotar reintentos (cancelar
  suscripción) se configura en Stripe, no se recalcula a mano.
- **No cambia el costo**: el fee de Stripe Billing sobre pagos recurrentes
  (si aplica según volumen) ya se paga por tener suscripciones vía WP
  Simple Pay, independientemente de este mecanismo — no es un costo nuevo
  de esta decisión. Verificar el número vigente en la página de precios de
  Stripe antes de presupuestar.
- **Resuelto (14 sep 2026): el webhook llega directo, sin pasar por Make**.
  No se copia la estructura de archivos de SC (Main+Shop separados, Make
  como puente obligado) porque en RU no aplica — WP Simple Pay y el hub
  viven en el mismo WordPress. El webhook de Stripe (firma verificada con
  `STRIPE_WEBHOOK_SECRET`, no un secret inventado) llega directo a
  `includes/billing.php` (`POST /ru/v1/stripe-webhook`), que llama directo
  a los handlers de activate/downgrade — sin `do_action`/Make en el medio.
  No existe `includes/make.php`. Eventos usados: `invoice.payment_succeeded`
  (activate) y `customer.subscription.deleted` (downgrade, ya agotados los
  Smart Retries de Stripe). El plan/interval viaja en la metadata del Price
  de Stripe (`ru_plan`, `ru_interval`) — se setea al crear cada uno de los
  6 Prices.
- **Calendario de reintentos/gracia**: configurar en Stripe (Billing →
  Subscriptions settings) para que coincida con el margen ya definido en
  `ESTRATEGIA-MONOPRODUCTO.md`. Pendiente de hacer, no de diseñar.
- **Make queda afuera del camino crítico**: si se usa, es para
  control/auditoría (Sheets, Slack, fatturazione vía A-Cube/FatturaExpress
  — ver sección 7.8) sin lógica de negocio, nunca como gate de una
  decisión de activar/desactivar. `Subscriptions Audit` (sección 3.C,
  "opcional más adelante"), si se clona, es puramente informativo.

## 7.8 Nota (14 sep 2026) — Expansión a España y Argentina, research preliminar

No bloquea nada de lo que se está construyendo hoy — queda anotado por si
se retoma. Contexto: el foco pasó de "marketing local Lombardia" a un
sistema de creación de sitios masivo, vendible a cualquier país (ver
memoria `riseup_mass_site_business_model`); España y Argentina son las
primeras oportunidades concretas.

- **España (fácil, ya cubierto por el stack elegido)**: B2B (caso más
  probable dado el tipo de cliente) → reverse charge, sin IVA en la
  factura, requiere alta en el registro de operadores intracomunitarios
  (equivalente italiano al ROI) — a confirmar con el commercialista. B2C →
  régimen **OSS**, se cobra IVA español (21%) pero se declara todo desde
  Italia, sin registro aparte en España. A-Cube/FatturaExpress ya declaran
  soporte para esto (sección 7.7 y research de facturación).
- **Argentina (compliance no recae en RiseUp, pero afecta precio/comunicación)**:
  IVA del 21% sobre servicios digitales del exterior lo retiene el
  banco/tarjeta del cliente argentino al pagar, no RiseUp. Se suma una
  percepción ~30% a cuenta de Ganancias/Bienes Personales sobre la
  conversión a pesos (+ percepciones provinciales en algunos casos desde
  2025) — el cliente termina pagando bastante más que el precio nominal.
  **Implicancia de producto, no técnica**: avisar este extra cost de
  entrada en el checkout/pricing para clientes argentinos, coherente con
  el principio de "sin sorpresas, explicar qué pasa". Verificar estado del
  cepo cambiario para pagos con tarjeta en moneda extranjera cerca de la
  fecha de lanzamiento a ese mercado — cambia rápido.
- **Pendiente real si se retoma**: confirmar con el commercialista el alta
  intracomunitaria para España, y decidir cómo/cuándo comunicar el extra
  cost argentino en el copy del checkout.

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
