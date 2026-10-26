# Guía técnica para devs — NATTA App

## 1) Objetivo

Documento de onboarding técnico: estructura del proyecto, flujos frontend/backend, módulo de contratos digitales, panel admin y reglas para extender la app sin romper lo existente.

**Documentos relacionados**

| Archivo | Contenido |
|---------|-----------|
| `docs/guia-funcional-admin.md` | Operación para administración / secretaría |
| `docs/frontend-backend-mapa.md` | Mapa rápido vista → JS → AJAX |
| `docs/sql/u207063327_contactos_db.sql` | Esquema de contactos |
| `docs/sql/app_audit_log.sql` | Log de la app pública |
| `docs/sql/curriculums_area.sql` / `curriculums_duplencias.sql` | Bolsa de trabajo |

---

## 2) Stack

| Capa | Tecnología |
|------|------------|
| Backend familias | PHP 7.2+ (vistas + `backend/ajax/`) |
| Backend admin | PHP + PDO (`admin/includes/`) |
| Frontend | ES Modules en `frontend/js/` |
| Base de datos | MySQL / MariaDB (mysqli familias, PDO admin) |
| PDF contratos / comunicados | Dompdf (`composer install` en raíz → `vendor/`) |
| Excel (cuotas y cartas) | PhpSpreadsheet en `importador-excel/` |
| Mail | PHPMailer |
| UI legacy | jQuery, Bootstrap, Material, SweetAlert2 |
| Sesión | Cookie `NATTAAPPSESSID`, path `/natta-app/` |

Timezone: `America/Argentina/Buenos_Aires` y `SET time_zone = '-03:00'` en mysqli.

---

## 3) Estructura mínima

```text
docs/
  contratos/                 # Plantillas HTML por institución
  cartas-documento/          # Excel responsables + modelo DOCX
  reglamento-institucional/
  sql/
frontend/js/
  index.js                   # Router por data-page
  config/apiEndpoints.js     # URLs AJAX centralizadas
  config/csrf.js
  core/                      # Layout, notificaciones, familiaContext, http
  ui/                        # Modal pagos, QR, diálogos
  pages/                     # Un módulo por pantalla (+ admin)
backend/
  bootstrap.php
  ajax/                      # 14 endpoints JSON familias
  lib/                       # Contratos, PDF, ingresantes, familia/talón
  tools/                     # CLI diagnóstico PDF
admin/
  admin_dashboard.php        # Pestañas superadmin/admin
  estado_alumno.php          # Panel secretaría/directivo
  admin_ajax.php
  includes/                  # Auth, nav, cartas, cuotas, KPIs, POST
  views/tabs/
php/                         # Auth, privacy gate, imports CSV
includes/                    # CV, PDF comunicados, QR modal
storage/
  contratos_firmados/        # PDF firmados (Deny all)
  comunicados_pdfs/
config/                      # DB, sesión, SMTP, privacy, imports/
importador-excel/            # Import rptfacing (PhpSpreadsheet)
contrato_documento.php
contrato_documento_firmado.php
contratos.php
```

`estados_de_cuenta/` fue absorbido por `admin/estado_alumno.php`. No reintroducir esa carpeta.

`importador-excel/frontend/js/` es una **copia desactualizada** del JS de familias. El frontend canónico es `natta-app/frontend/js/`.

---

## 4) Flujo de carga de una página dashboard (familias)

1. La vista PHP renderiza HTML.
2. El `<body>` define `data-page` (ej. `home`, `contratos`).
3. CSRF (y poco más) en `<script id="page-data" type="application/json">`.
4. `frontend/js/index.js` importa `pages/*Page.js`.
5. `familiaContext.js` hace POST a `ajax_familia_context.php` y mergea saldos/alumnos.
6. Ejecuta `init*Page(mergedData)`.
7. El módulo inicializa layout, notificaciones y lógica propia.

**No embeber PII financiera en `#page-data`.** Va por AJAX.

Páginas con contexto familiar: `home`, `info-importante`, `agregar-email`, `talon-de-pago`, `contratos`, `libro-sugerencias`, `informar-error`. En `talon-de-pago` además se llama `ajax_talon_cuotas.php`.

---

## 5) Frontend modular

### Entrada

- **`index.js`**: router por `data-page`.
- **`config/apiEndpoints.js`**: todas las rutas AJAX de familias; no hardcodear URLs en páginas.

### Core / UI

| Archivo | Rol |
|---------|-----|
| `core/dashboardLayout.js` | Sidebar, ayuda, logout |
| `core/sidebar.js` | Toggle sidebar |
| `core/notificationsPanel.js` | Campana y lectura |
| `core/familiaContext.js` | Contexto financiero POST |
| `core/http.js` | fetch + JSON |
| `ui/paymentModal.js` | Modal de pagos |
| `ui/qrShareModal.js` | QR de la app |
| `ui/dialogs.js` | SweetAlert2 |
| `pages/homeContracts.js` | Contratos (home + contratos) |
| `pages/adminDashboardPage.js` | Toasts, heartbeat, batch actions, QR admin |
| `pages/estadoAlumnoPage.js` | Tablas ordenables; import directo desde `estado_alumno.php` |

### Mapping `data-page`

| Vista | `data-page` | Init |
|-------|-------------|------|
| `home.php` | `home` | `initHomePage` |
| `contratos.php` | `contratos` | `initContratosPage` |
| `info-importante.php` / `instituciones.php` | `info-importante` | `initInfoImportantePage` |
| `agregaremail.php` | `agregar-email` | `initAgregarEmailPage` |
| `talondepago.php` | `talon-de-pago` | `initTalonDePagoPage` |
| `librodesugerencias.php` | `libro-sugerencias` | `initLibroSugerenciasPage` |
| `informarerror.php` | `informar-error` | `initInformarErrorPage` |
| `admin/views/layout_start.php` | `admin-dashboard` | `initAdminDashboardPage` |
| `admin/estado_alumno.php` | `estado-alumno` | `initEstadoAlumnoPage` (fuera de `PAGE_MODULES`) |

`js/main.js` es un shim deprecado que solo inyecta `index.js`.

---

## 6) Backend por capas

| Carpeta / archivo | Rol |
|-------------------|-----|
| Vistas PHP (raíz) | HTML + `page-data` mínimo |
| `backend/ajax/` | Endpoints JSON (`fetch`) de familias |
| `backend/lib/` | Librerías reutilizables |
| `php/` | Auth, privacy, recupero clave, imports CSV |
| `admin/` | Dashboard, estado alumno, exports |
| `includes/` | CV, comunicados PDF, QR |
| `config/` | DB, SMTP, sesión, app URL, privacy |

### Librerías (`backend/lib/`)

| Archivo | Responsabilidad |
|---------|-----------------|
| `ingresantes_externos_2027.php` | Cursos EX*, cuota 10 liquidada, `cuota_es_futura_para_curso` |
| `contract_institution.php` | Código institución, firma habilitada, reubicación, requisitos, emails |
| `contract_render.php` | HTML desde plantilla + placeholders |
| `contract_pdf.php` | HTML → PDF (Dompdf), storage, SHA-256 |
| `contract_preview_session.php` | Preview sin PII en query string |
| `familia_context.php` | JSON financiero del grupo |
| `talon_context.php` | Cuotas y solicitudes de talón |

### Librerías admin (`admin/includes/`)

| Archivo | Responsabilidad |
|---------|-----------------|
| `admin_user.php` | Roles `superadmin`, `admin`, `secretaria`, `directivo` + `admin_can()` |
| `nav.php` | Grupos, pestañas, aliases, badges de pendientes |
| `post_handlers.php` | POST del dashboard |
| `cartas_doc_lib.php` | Clasificación CD/NF, Excel, generación DOCX/ZIP |
| `cuotas_admin_lib.php` | Mes lógico, ventana de deuda, SU vs resto |
| `estado_alumno_lib.php` | Vistas secretaría, contratos, bolsa |
| `informacion_general_data.php` | KPIs |
| `alta_alumnos_nuevos_lib.php` | Altas EX* |
| `escuelas_catalog.php` | 8 escuelas (CJ…SU) |

`admin/includes/cuotas_admin_lib.php` mapea `numero_cuota` → mes lógico. En **SU** las cuotas 2–10 son marzo–noviembre (sin RV). En el resto, 1–9 mensuales y 10–12 RV.

---

## 7) Ingresantes externos 2027

Regla centralizada en `backend/lib/ingresantes_externos_2027.php`.

### Cursos

`EXCJ`, `EXHV`, `EXJA`, `EXJN`, `EXSC`, `EXMB`, `EXET`  
(código institución = últimas 2 letras → plantilla `CJ`, `HV`, …, `ET`→`IDET`, `MB`→`IMB`).

### Cuotas

| Alumno | Qué se liquida / muestra |
|--------|--------------------------|
| **Ingresante externo** | Solo **cuota 10** — *Adelanto de Reserva de vacante (2027)*. El resto se trata como futura. |
| **Regular** | Cuotas 1–9 según `cuota_vigente`; 10–12 futuras solo antes de marzo. |

Función clave: `cuota_es_futura_para_curso($num, $cuotaVigente, $mesActual, $curso)`.  
Consumida desde vistas, AJAX (`ajax_cuotas`, `ajax_historial`, `ajax_familia_context`), admin (`cuotas_admin_lib.php`) y contextos de familia/talón.

No hardcodear cursos `EX*` en vistas: usar `curso_es_ingresante_externo_2027()`.

### Firma de contrato

| Tipo | Habilitación (`contrato_alumno_puede_firmar`) |
|------|-----------------------------------------------|
| Regular | Cuota de **noviembre** abonada (9; o 10 en cursos SU) |
| Ingresante externo | **Cuota 10** (Adelanto RV 2027) con diferencia saldada |
| Reubicación | Siempre `false` mientras el curso esté en `contrato_cursos_pendientes_reubicacion()` |

### Requisitos post-firma (UI en `homeContracts.js`)

**Ingresantes** (orden): adelanto RV → firma → documentación → sin deudas 2026 (tras cuota 11).  
**Regulares:** sin deudas 2026 → firma → documentación → RV 2027 (adelanto + resto). En SU, RV no aplica.

`ajax_contract_status.php` expone: `es_ingresante_externo`, `firma_habilitada`, `pendiente_reubicacion`, `requisitos`, `estado_flujo`.

---

## 8) Reubicación de cursos (bloqueo de firma)

Lista en `contrato_cursos_pendientes_reubicacion()`:

- Jardín → primaria: `3ACJ`, `3BCJ`, `3AHV`…`3DHV`, `3AJA`, `3BJA`
- Primaria → secundaria: `6AJN`, `6CJN`, `6DJN`, `6EJN`, `6ASC`, `6BSC`
- Egreso: `61MB`, `62MB`, `63MB`, `7AET`, `4ISU`, `4PSU`

`estado_flujo = firma_bloqueada_reubicacion`. El bloqueo cae solo al cambiar el código de curso en legajos. No agregar cursos a esa lista desde las vistas.

---

## 9) Módulo de contratos digitales

### Modelo de datos

**Ya no existe la tabla `contracts`.**

#### `contratos_instituciones` (una fila por colegio)

| Columna | Uso |
|---------|-----|
| `codigo` | Últimas 2 letras del curso (`JN`, `CJ`, `ET`→plantilla `IDET`, `MB`→`IMB`) |
| `responsable_institucion`, `institucion`, `domicilio_institucion` | Preámbulo |
| `contract_anio` / `contract_revision` | Ciclo (`2027` / `v1`) |
| `contrato_activo` | `1` = familia puede firmar |
| `accepted_text` | Texto del checkbox |

Versión: `Contrato_{slug}_{anio}_{revision}` → `docs/contratos/Contrato_JN_2027_v1.html`.

#### `contratos_aceptados`

Firma: datos del firmante, IP, UA, `contract_hash` / `signed_document_sha256`, `accepted_pdf_path`, `admin_aprobado`, `info_erronea`.

#### Archivos

- Plantilla: `docs/contratos/*.html`
- PDF: `storage/contratos_firmados/{año}/Contrato_*_{dni}_{timestamp}.pdf` (`.htaccess` Deny)
- Reglamento: constante JS `REGLEMENTO_INSTITUCIONAL_2027_PDF_URL`

### Preview sin PII en URL

1. JS POST a `ajax_contract_preview.php` con `student_dni` + CSRF.
2. El DNI queda en sesión (`contract_preview_session.php`).
3. `contrato_documento.php` lee la sesión (no query string).

### Flujo técnico de firma

1. Frontend consulta `ajax_contract_status.php`.
2. Usuario abre HTML (preview) y reglamento; marca checkbox.
3. `ajax_contract_accept.php` valida CSRF, pertenencia al grupo, contraseña, bloqueos, duplicados.
4. `contrato_archive_signed_document()` → Dompdf → `hash_file('sha256')` → storage + DB.
5. Email de confirmación con SHA-256 y enlace al PDF.

### Verificar SHA-256

```text
hash_file('sha256', $pdfAbsoluto)  ===  contract_hash / signed_document_sha256
```

Si se regenera el PDF (plantilla, márgenes, Dompdf), la huella de archivos viejos **cambia**. No regenerar en producción salvo incidente; usar `backend/tools/regenerar_pdfs_firmados.php` con criterio.

### Cambiar ciclo lectivo

```sql
UPDATE contratos_instituciones
SET contract_anio = '2028', contract_revision = 'v1';
```

Subir `docs/contratos/Contrato_*_2028_v1.html` **antes** de activar.

### Herramientas CLI (`backend/tools/`)

| Script | Uso |
|--------|-----|
| `contrato_pdf_diagnostico.php` | Dompdf, extensiones, storage |
| `test_contrato_archive_cli.php` / `test_contrato_pdf_cli.php` | Prueba generación |
| `regenerar_pdfs_firmados.php` / `regenerar_pdf_local.php` | Regenerar PDFs |
| `inspect_pdf_margins.php` | Márgenes |

Requisitos servidor: `composer install`, extensiones `dom` + `mbstring`, `storage/contratos_firmados/` escribible.

---

## 10) Autenticación

### Familias

| Flujo | Archivos |
|-------|----------|
| Login | `index.php` → `php/login_padre.php` |
| Privacy | Si hay política vigente sin aceptar → `php/privacy_gate.php` → `privacy_gate_submit.php` / `_cancel.php` |
| Primer ingreso | `primer_ingreso.php` → `validar_primer_ingreso.php` → `establecer_password.php` → `guardar_password.php` |
| Olvidé clave | `olvide_password.php` → `procesar_olvide.php` → `restablecer_password.php` → `guardar_nueva_password.php` |
| Logout | `php/logout.php` |

Sesión familiar: `$_SESSION['dni_alumno']`, `nro_familia`, `csrf_token`.  
Privacy: `config/privacy_policy.php`, tablas `privacy_policies` / `privacy_policy_acceptances`. Auditoría en `app_audit_log`.

Modo mantenimiento (`configuracion.modo_mantenimiento = 1`) redirige a `mantenimiento.php`.

### Admin

| Flujo | Archivos |
|-------|----------|
| Dashboard | `admin/admin_dashboard.php` + `views/login.php` |
| Secretaría | `admin/estado_alumno.php` + `views/secretaria_login.php` |
| Directivo | mismo panel; `control_usuarios` extra |
| Logout | `admin/admin_logout.php` |

Usuarios en `admin_users`. Capabilities en `ADMIN_CAPABILITIES` (`admin_user.php`). Superadmin = `*`. Secretaría/directivo no entran al dashboard (`admin_panel_home()` → `estado_alumno.php`). Login lock: 5 fallos → 15 min.

CSRF: `$_SESSION['csrf_token']` en ambos mundos.

---

## 11) Panel admin (detalle)

### Navegación

`admin_nav_resolve($_GET)` lee `grupo` + `tab`. Vistas en `$tabViews` de `admin_dashboard.php`.  
POST: `post_handlers.php` (comunicados, uploads, cuota vigente, mantenimiento, altas).  
AJAX: `admin_ajax.php` (heartbeat 15 s en pestañas de solicitudes, cambio de estado, eliminar, modales).

### Estado alumno (`?vista=` + `?escuela=`)

Vistas: `cuentas`, `listado_familias`, `contratos` / `revision_contratos`, `emails`, `bolsa`, `control_usuarios`.  
`ALL` solo en cuentas/listado (y control, si no es directivo). Escrituras de contratos de rol escuela: siempre `admin_escuela_codigo()`.

### Cartas documento

`cartas_doc_lib.php`:

- Fuente: `docs/cartas-documento/legajo + resp/Legajos limpio prueba.xlsx`
- Deuda: legajos **activos**, mes lógico 1–`mesCorte` (default 7 = julio para CD/NF; deuda total hasta septiembre)
- CD: meses 1–3 impagos (mar–may). NF: 4–5 (jun–jul) y no CD.
- EX* → no clasifica.
- Generación: `exportar_cartas_doc_crear.php` + modelo `MODELO CARTA DOC.docx`

### Importaciones

Archivos fijos en `config/imports/`. Superadmin sube y dispara:

- `importador-excel/import_facturado-ingresado.php` — cuotas `rptfacing*`
- `php/email_familia.php` — emails
- `php/importar_legajos.php` — legajos + inactivos

### Alta alumnos nuevos

Curso del form: `EXCJ` (u otro EX* según escuela). Tabla `alta_alumnos_nuevos`. Lista oculta `lista-alumnos-nuevos`.

---

## 12) Modal de pagos

Requiere en el contexto mergeado (AJAX, no page-data): `alumnos`, `saldoTotalFamiliar`, `nroFamilia`.

HTML: `#overlay`, `#modalPago`. Lógica: `ui/paymentModal.js`.

---

## 13) Endpoints AJAX familias (lista completa)

**Contexto / cuenta**

- `ajax_familia_context.php`, `ajax_cuotas.php`, `ajax_historial.php`
- `ajax_talon_cuotas.php`, `ajax_solicitar_talon.php`, `ajax_cancelar_solicitudes.php`

**Familia / comunicación**

- `ajax_notificaciones.php`, `ajax_notificaciones_leer.php`
- `ajax_agregar_email.php`, `ajax_cancelar_solicitud_email.php`

**Contratos**

- `ajax_contract_status.php`, `ajax_contract_get.php`, `ajax_contract_preview.php`, `ajax_contract_accept.php`

Admin no usa este catálogo: usa `admin_ajax.php` + POST del dashboard.

Hay leftovers en `php/get_notificaciones.php` y `php/publicar_comunicado.php`; el camino vigente de notificaciones familiares es `backend/ajax/`.

---

## 14) Checklist: nueva página dashboard familias

1. Vista PHP + `data-page`.
2. `frontend/js/pages/nuevaPage.js` con `initNuevaPage(data)`.
3. Registrar en `index.js` (`PAGE_MODULES` + `PAGE_INIT_FNS`).
4. Si necesita saldo: agregar a `PAGES_WITH_FAMILIA_CONTEXT`.
5. Endpoint + entrada en `apiEndpoints.js`.
6. Probar layout, notificaciones, consola.

### Nueva pestaña admin

1. Tab + grupo en `admin/includes/nav.php`.
2. Capability (o alias) en `admin_nav_capability` / `ADMIN_CAPABILITIES`.
3. Archivo en `admin/views/tabs/` y entrada en `$tabViews`.
4. POST en `post_handlers.php` si aplica; heartbeat en `admin_ajax.php` si hay listado vivo.

---

## 15) Reglas para no romper producción

- Rutas AJAX de familias solo vía `apiEndpoints.js`.
- No duplicar lógica del modal de pagos ni del contexto familiar.
- Contratos: no reintroducir tabla `contracts`; usar `contratos_instituciones`.
- Preview de contrato: sesión, nunca DNI en query string.
- Ingresantes EX*: solo en `ingresantes_externos_2027.php`.
- Reubicación: solo en `contrato_cursos_pendientes_reubicacion()`.
- “Cuota futura” / liquidación: siempre pasar `$curso` a `cuota_es_futura_para_curso`.
- Mes lógico admin: `cuotaANumeroMesLogico` (SU es distinto).
- Tras cambiar plantilla HTML o márgenes PDF, los SHA de PDFs viejos cambian si se regeneran.
- `contract_institution.php` debe cargarse solo o antes que `contract_render.php`.
- No servir `storage/` ni `config/` por HTTP (`.htaccess`).
- No editar `importador-excel/frontend/js/`; el canónico es `natta-app/frontend/js/`.

---

## 16) Debug rápido

| Síntoma | Revisar |
|---------|---------|
| Página en blanco / sin init | `data-page`, `index.js`, consola JS |
| Home sin saldos | `ajax_familia_context.php`, CSRF, sesión |
| 403 AJAX | Sesión / CSRF |
| Contrato “no configurado” | `contratos_instituciones.contrato_activo = 1`, fila del `codigo` |
| Error estado contrato (500) | Log PHP; `contract_institution.php` |
| Firma bloqueada (ingresante) | Cuota 10 pagada; curso EX*; `firma_habilitada` |
| Firma bloqueada (febrero) | Curso en lista de reubicación |
| Ingresante ve cuotas 1–9 / 11–12 | Import: solo cuota 10; `cuota_es_futura_para_curso` |
| PDF no genera | `contrato_pdf_diagnostico.php`, `vendor/`, permisos `storage/` |
| SHA no coincide | PDF regenerado vs huella original |
| Admin 403 | `admin_can($tab)`, rol, escuela asignada |
| Secretaría ve dashboard | Debe ir a `estado_alumno.php` (`admin_panel_home`) |
| App en blanco para familias | `modo_mantenimiento` |
| Cartas vacías / mal clasificadas | Excel de responsables + última importación rptfacing; EX* no entran |
| Import no corre | Nombre exacto del archivo en `config/imports/`; solo superadmin |

---

*Última actualización: octubre 2026 — admin unificado, privacy gate, cartas documento, reubicación, preview de contrato en sesión, contexto familiar por AJAX.*
