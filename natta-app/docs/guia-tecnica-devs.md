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
  lib/                    # Lógica de contratos, PDF, render
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

### Librerías de contratos (`backend/lib/`)

| Archivo | Responsabilidad |
|---------|-----------------|
| `contract_institution.php` | Código institución desde curso, datos de `contratos_instituciones`, contrato vigente, emails |
| `contract_render.php` | Render HTML desde plantilla + placeholders, vista documento |
| `contract_pdf.php` | HTML → PDF (Dompdf), storage, SHA-256, regeneración |

---

## 7) Módulo de contratos digitales

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

## 8) Autenticación (resumen)

| Flujo | Archivos |
|-------|----------|
| Login | `php/login_padre.php` → sesión → `home.php` |
| Primer ingreso | `primer_ingreso.php` → `validar_primer_ingreso.php` → `establecer_password.php` |
| Olvidé clave | `olvide_password.php` → token email → `restablecer_password.php` |

Sesión familiar: `$_SESSION['dni_alumno']`, `nro_familia`, `csrf_token`.

---

## 9) Modal de pagos

Requiere en `page-data`: `alumnos`, `saldoTotalFamiliar`, `nroFamilia`.

HTML: `#overlay`, `#modalPago`. Lógica: `ui/paymentModal.js`.

---

## 10) Endpoints AJAX (lista completa)

**Pagos / cuenta**

- `ajax_cuotas.php`, `ajax_historial.php`
- `ajax_solicitar_talon.php`, `ajax_cancelar_solicitudes.php`

**Familia / comunicación**

- `ajax_notificaciones.php`, `ajax_notificaciones_leer.php`
- `ajax_agregar_email.php`, `ajax_cancelar_solicitud_email.php`

**Contratos**

- `ajax_contract_status.php`, `ajax_contract_get.php`, `ajax_contract_accept.php`

---

## 11) Checklist: nueva página dashboard

1. Vista PHP + `data-page`.
2. `frontend/js/pages/nuevaPage.js` con `initNuevaPage(data)`.
3. Registrar en `frontend/js/index.js`.
4. `page-data` JSON si hace falta.
5. Endpoint + entrada en `apiEndpoints.js`.
6. Probar layout, notificaciones, consola sin errores.

---

## 12) Reglas para no romper producción

- Rutas AJAX solo vía `apiEndpoints.js`.
- No duplicar lógica del modal de pagos.
- Contratos: no reintroducir tabla `contracts`; usar `contratos_instituciones`.
- Tras cambiar plantilla HTML o márgenes PDF, los SHA de PDFs viejos cambian si se regeneran.
- `backend/lib/contract_institution.php` debe cargarse solo o antes que `contract_render.php` (institution no depende de render).

---

## 13) Debug rápido

| Síntoma | Revisar |
|---------|---------|
| Página en blanco / sin init | `data-page`, `index.js`, consola JS |
| 403 AJAX | Sesión / CSRF |
| Contrato “no configurado” | `contratos_instituciones.contrato_activo = 1`, fila del `codigo` |
| Error estado contrato (500) | Log PHP; funciones en `contract_institution.php` |
| PDF no genera | `contrato_pdf_diagnostico.php`, `vendor/`, permisos `storage/` |
| SHA no coincide | Ver `docs/contrato-sha256-verificacion.md` |

---

*Última actualización: junio 2026 — esquema contratos unificado en `contratos_instituciones`.*
