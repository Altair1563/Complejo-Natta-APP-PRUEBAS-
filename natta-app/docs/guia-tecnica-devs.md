# Guía técnica para devs — NATTA App

## 1) Objetivo

Documento de onboarding técnico: estructura del proyecto, flujos frontend/backend, módulo de contratos digitales y reglas para extender la app sin romper lo existente.

**Documentos relacionados**

| Archivo | Contenido |
|---------|-----------|
| `docs/guia-funcional-admin.md` | Operación para administración / secretaría |
| `docs/frontend-backend-mapa.md` | Mapa rápido vista → JS → AJAX |
| `docs/contrato-sha256-verificacion.md` | Huella SHA-256 del PDF firmado |
| `docs/sql/u207063327_contactos_db.sql` | Esquema canónico de la base |
| `docs/sql/contratos_unificar_instituciones.sql` | Migración one-shot desde tabla `contracts` (ya aplicada en prod) |

---

## 2) Stack

| Capa | Tecnología |
|------|------------|
| Backend | PHP 7.2+ (vistas + `backend/ajax/`) |
| Frontend dashboard | ES Modules en `frontend/js/` |
| Base de datos | MySQL / MariaDB (mysqli) |
| PDF contratos | Dompdf (`composer install` en raíz → `vendor/`) |
| UI legacy | jQuery, Bootstrap, Material, SweetAlert2 |

---

## 3) Estructura mínima

```text
docs/
  contratos/              # Plantillas HTML por institución (Contrato_JN_2027_v1.html, …)
  sql/                    # Dumps y migraciones
frontend/js/
  index.js                # Router por data-page
  config/apiEndpoints.js  # URLs AJAX centralizadas
  core/                   # Layout, notificaciones, scroll
  ui/                     # Modal pagos, diálogos
  pages/                  # Un módulo por pantalla
backend/
  bootstrap.php
  ajax/                   # Endpoints JSON
  lib/                    # Lógica de contratos, PDF, render, ingresantes
storage/
  contratos_firmados/     # PDF firmados (no público directo)
config/
contrato_documento.php    # Vista HTML del contrato con datos del firmante
contrato_documento_firmado.php  # Descarga PDF firmado (sesión)
contratos.php             # Pantalla familiar: firmar / ver estado
```

---

## 4) Flujo de carga de una página dashboard

1. La vista PHP renderiza HTML.
2. El `<body>` define `data-page` (ej. `home`, `contratos`).
3. Opcional: `<script id="page-data" type="application/json">…</script>`.
4. `frontend/js/index.js` importa `pages/*Page.js` y ejecuta `init*Page(pageData)`.
5. El módulo inicializa layout, notificaciones y lógica propia.

---

## 5) Frontend modular

### Entrada

- **`index.js`**: router por `data-page`.
- **`config/apiEndpoints.js`**: todas las rutas AJAX; no hardcodear URLs en páginas.

### Core / UI

- `core/dashboardLayout.js` — sidebar, ayuda, logout.
- `core/notificationsPanel.js` — campana y lectura.
- `ui/paymentModal.js` — modal de pagos (familiar / alumno / cuota).
- `pages/homeContracts.js` — lógica compartida de contratos (estado, modal firma); usada desde `homePage.js` y `contratosPage.js`.

### Páginas con contratos

| Vista | `data-page` | Módulo |
|-------|-------------|--------|
| `contratos.php` | `contratos` | `contratosPage.js` |
| `home.php` | `home` | `homePage.js` (+ enlaces estado contrato vía `homeContracts.js`) |

---

## 6) Backend por capas

| Carpeta / archivo | Rol |
|-------------------|-----|
| Vistas PHP (raíz) | HTML + `page-data` |
| `backend/ajax/` | Endpoints JSON (`fetch`) |
| `backend/lib/` | Librerías reutilizables |
| `php/` | Auth, primer ingreso, recupero clave |
| `config/` | DB, SMTP, app URL |

### Librerías de contratos y cuotas (`backend/lib/`)

| Archivo | Responsabilidad |
|---------|-----------------|
| `ingresantes_externos_2027.php` | Cursos EX*, cuota 10 liquidada, nombres y “cuota futura” por curso |
| `contract_institution.php` | Código institución, firma habilitada, requisitos 2027, emails |
| `contract_render.php` | Render HTML desde plantilla + placeholders, vista documento |
| `contract_pdf.php` | HTML → PDF (Dompdf), storage, SHA-256, regeneración |
| `familia_context.php` / `talon_context.php` | Contexto financiero / talones (usan la misma regla de cuota futura) |

---

## 7) Ingresantes externos 2027

Regla centralizada en `backend/lib/ingresantes_externos_2027.php`.

### Cursos

`EXCJ`, `EXHV`, `EXJA`, `EXJN`, `EXSC`, `EXMB`, `EXET`  
(código institución = últimas 2 letras → plantilla `CJ`, `HV`, …, `ET`→`IDET`, `MB`→`IMB`).

### Cuotas

| Alumno | Qué se liquida / muestra |
|--------|--------------------------|
| **Ingresante externo** | Solo **cuota 10** — *Adelanto de Reserva de vacante (2027)*. El resto se trata como futura (no entra en saldo, historial, talón ni modal de pagos). |
| **Regular** | Regla habitual: cuotas 1–9 según `cuota_vigente`; 10–12 futuras solo antes de marzo. |

Función clave: `cuota_es_futura_para_curso($num, $cuotaVigente, $mesActual, $curso)`.  
Consumida desde vistas (`home.php`, `contratos.php`, `talondepago.php`, …), AJAX (`ajax_cuotas.php`, `ajax_historial.php`), admin (`cuotas_admin_lib.php`) y contextos de familia/talón.

### Firma de contrato

| Tipo | Habilitación (`contrato_alumno_puede_firmar`) |
|------|-----------------------------------------------|
| Regular | Cuota de **noviembre** abonada (9; o 10 en cursos SU) |
| Ingresante externo | **Cuota 10** (Adelanto RV 2027) con diferencia saldada |

Estados de flujo adicionales:

- `firma_bloqueada_noviembre`
- `firma_bloqueada_adelanto_rv`

`ajax_contract_status.php` expone por alumno: `es_ingresante_externo`, `firma_habilitada`, `requisitos`, `estado_flujo`.

### Requisitos post-firma (UI en `homeContracts.js`)

**Ingresantes** (orden):

1. Pago del ADELANTO RV 2027 (cuota 10)  
2. Firma y aceptación  
3. Documentación institucional  
4. Sin deudas ciclo 2026 (se evalúa tras cuota 11 Resto RV; los ingresantes no aportan deuda 1–9)

**Regulares** (orden):

1. Sin deudas ciclo 2026  
2. Firma y aceptación  
3. Documentación  
4. Reserva de Vacante 2027 (adelanto + resto; resto no aplica en ingresantes)

En ingresantes, `contrato_resto_rv_estado` marca `aplica: false` (solo cuenta el adelanto en el bloque RV de requisitos).

---

## 8) Módulo de contratos digitales

### Modelo de datos (post-migración)

**Ya no existe la tabla `contracts`.** Todo se configura así:

#### `contratos_instituciones` (una fila por colegio)

| Columna | Uso |
|---------|-----|
| `codigo` | Últimas 2 letras del curso (`JN`, `CJ`, `ET`→plantilla `IDET`, `MB`→`IMB`) |
| `responsable_institucion`, `institucion`, `domicilio_institucion` | Preámbulo del contrato |
| `contract_anio` | Ciclo lectivo (`2027`, `2028`, …) |
| `contract_revision` | Revisión (`v1`, `v2`, …) |
| `contrato_activo` | `1` = familia puede firmar |
| `accepted_text` | Texto legal del checkbox (mismo en todas las filas) |

Versión calculada en PHP: `Contrato_{slug}_{anio}_{revision}` → archivo `docs/contratos/Contrato_JN_2027_v1.html`.

#### `contratos_aceptados` (una firma activa por alumno + versión)

Registra la aceptación: datos del firmante, IP, user-agent, `contract_hash` / `signed_document_sha256` (SHA-256 del PDF), `accepted_pdf_path` (ruta en storage).

#### Plantillas y archivos

- **Plantilla:** `docs/contratos/*.html` (editables; no van en la DB).
- **PDF firmado:** `storage/contratos_firmados/{año}/Contrato_*_{dni}_{timestamp}.pdf` (protegido con `.htaccess`).

### Endpoints AJAX de contratos

| Endpoint | Función |
|----------|---------|
| `ajax_contract_status.php` | Estado por alumno (pendiente / firmado / pendiente aprobación / aprobado) |
| `ajax_contract_get.php` | Datos para modal de firma (institución, versión, reglamento) |
| `ajax_contract_accept.php` | Firma: valida clave, genera PDF, guarda registro, envía email |

Rutas en `frontend/js/config/apiEndpoints.js`.

### Vistas públicas (con sesión)

| Archivo | Qué entrega |
|---------|-------------|
| `contrato_documento.php` | HTML personalizado para leer antes de firmar |
| `contrato_documento_firmado.php` | Descarga del PDF depositado |

### Flujo técnico de firma

1. Frontend consulta `ajax_contract_status.php`.
2. Usuario abre contrato HTML y reglamento PDF; marca checkbox.
3. `ajax_contract_accept.php` valida CSRF, contraseña, duplicados.
4. `contrato_archive_signed_document()` renderiza plantilla → PDF (Dompdf) → `hash_file('sha256')` → guarda en storage y DB.
5. Email de confirmación con enlace al PDF firmado.

### Cambiar ciclo lectivo (dev / DBA)

```sql
UPDATE contratos_instituciones
SET contract_anio = '2028', contract_revision = 'v1';
```

Subir nuevas plantillas `docs/contratos/Contrato_*_2028_v1.html` antes de activar.

### Herramientas CLI (`backend/tools/`)

| Script | Uso |
|--------|-----|
| `contrato_pdf_diagnostico.php` | Dompdf, extensiones, storage |
| `test_contrato_archive_cli.php` | Prueba generación PDF |
| `regenerar_pdfs_firmados.php` | Regenera PDFs desde DB |

### Requisitos PDF en servidor

- `composer install` (Dompdf en `vendor/`)
- Extensiones PHP: `dom`, `mbstring`
- `storage/contratos_firmados/` escribible

---

## 9) Autenticación (resumen)

| Flujo | Archivos |
|-------|----------|
| Login | `php/login_padre.php` → sesión → `home.php` |
| Primer ingreso | `primer_ingreso.php` → `validar_primer_ingreso.php` → `establecer_password.php` |
| Olvidé clave | `olvide_password.php` → token email → `restablecer_password.php` |

Sesión familiar: `$_SESSION['dni_alumno']`, `nro_familia`, `csrf_token`.

---

## 10) Modal de pagos

Requiere en `page-data`: `alumnos`, `saldoTotalFamiliar`, `nroFamilia`.

HTML: `#overlay`, `#modalPago`. Lógica: `ui/paymentModal.js`.

---

## 11) Endpoints AJAX (lista completa)

**Pagos / cuenta**

- `ajax_cuotas.php`, `ajax_historial.php`
- `ajax_solicitar_talon.php`, `ajax_cancelar_solicitudes.php`

**Familia / comunicación**

- `ajax_notificaciones.php`, `ajax_notificaciones_leer.php`
- `ajax_agregar_email.php`, `ajax_cancelar_solicitud_email.php`

**Contratos**

- `ajax_contract_status.php`, `ajax_contract_get.php`, `ajax_contract_accept.php`

---

## 12) Checklist: nueva página dashboard

1. Vista PHP + `data-page`.
2. `frontend/js/pages/nuevaPage.js` con `initNuevaPage(data)`.
3. Registrar en `frontend/js/index.js`.
4. `page-data` JSON si hace falta.
5. Endpoint + entrada en `apiEndpoints.js`.
6. Probar layout, notificaciones, consola sin errores.

---

## 13) Reglas para no romper producción

- Rutas AJAX solo vía `apiEndpoints.js`.
- No duplicar lógica del modal de pagos.
- Contratos: no reintroducir tabla `contracts`; usar `contratos_instituciones`.
- Reglas de ingresantes EX*: solo en `ingresantes_externos_2027.php` (no hardcodear cursos EX* en vistas).
- “Cuota futura” / liquidación: siempre pasar `$curso` a `cuota_es_futura_para_curso` (o wrappers).
- Tras cambiar plantilla HTML o márgenes PDF, los SHA de PDFs viejos cambian si se regeneran.
- `backend/lib/contract_institution.php` debe cargarse solo o antes que `contract_render.php` (institution no depende de render).

---

## 14) Debug rápido

| Síntoma | Revisar |
|---------|---------|
| Página en blanco / sin init | `data-page`, `index.js`, consola JS |
| 403 AJAX | Sesión / CSRF |
| Contrato “no configurado” | `contratos_instituciones.contrato_activo = 1`, fila del `codigo` |
| Error estado contrato (500) | Log PHP; funciones en `contract_institution.php` |
| Firma bloqueada (ingresante) | Cuota 10 pagada; curso en lista EX*; `firma_habilitada` en status |
| Ingresante ve cuotas 1–9 / 11–12 | Import/liquidación: solo cuota 10; `cuota_es_futura_para_curso` |
| PDF no genera | `contrato_pdf_diagnostico.php`, `vendor/`, permisos `storage/` |
| SHA no coincide | Ver `docs/contrato-sha256-verificacion.md` |

---

*Última actualización: septiembre 2026 — ingresantes externos 2027 (cursos EX*, cuota 10 / firma).*
