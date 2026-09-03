# NATTA App — Mapa frontend / backend

Referencia rápida: qué archivo PHP carga qué JS, qué AJAX consume y cómo encaja el módulo de contratos.

**Guías ampliadas:** `guia-tecnica-devs.md` · `guia-funcional-admin.md`

---

## 1) Visión general

```text
Vista PHP (HTML + page-data)
        ↓
frontend/js/index.js  (data-page → pages/*Page.js)
        ↓
fetch → backend/ajax/*.php  (+ php/* para auth)
        ↓
MySQL + storage/contratos_firmados/
```

---

## 2) Estructura de carpetas (resumida)

```text
natta-app/
├─ index.php, home.php, contratos.php, …
├─ contrato_documento.php
├─ contrato_documento_firmado.php
├─ frontend/js/
│  ├─ index.js
│  ├─ config/apiEndpoints.js
│  ├─ core/
│  ├─ ui/
│  └─ pages/
│     ├─ homePage.js
│     ├─ contratosPage.js
│     └─ homeContracts.js      ← lógica compartida contratos
├─ backend/
│  ├─ bootstrap.php
│  ├─ ajax/
│  └─ lib/
│     ├─ ingresantes_externos_2027.php  ← cursos EX*, cuota 10
│     ├─ contract_institution.php
│     ├─ contract_render.php
│     ├─ contract_pdf.php
│     ├─ familia_context.php
│     └─ talon_context.php
├─ docs/contratos/             ← plantillas HTML (no DB)
├─ storage/contratos_firmados/ ← PDF firmados
├─ estados_de_cuenta/          ← secretaría / informes admin
├─ php/                        ← login, passwords
└─ config/
```

---

## 3) Páginas dashboard → módulos JS

| Vista PHP | `data-page` | Módulo JS | Notas |
|-----------|-------------|-----------|-------|
| `home.php` | `home` | `homePage.js` | Estado cuenta + contratos en home |
| `contratos.php` | `contratos` | `contratosPage.js` | Firma y estado contrato |
| `info-importante.php` | `info-importante` | `infoImportantePage.js` | Comunicados |
| `instituciones.php` | `info-importante` | `infoImportantePage.js` | Contactos |
| `agregaremail.php` | `agregar-email` | `agregarEmailPage.js` | Emails |
| `talondepago.php` | `talon-de-pago` | `talonDePagoPage.js` | Talones |
| `librodesugerencias.php` | `libro-sugerencias` | `libroSugerenciasPage.js` | Sugerencias |
| `informarerror.php` | `informar-error` | `informarErrorPage.js` | Errores |

Todas usan `notificationsPanel.js`. La mayoría inicializa `paymentModal.js`.

---

## 4) Endpoints AJAX

Definidos en `frontend/js/config/apiEndpoints.js`.

### Cuenta y pagos

| Endpoint | Método | Uso |
|----------|--------|-----|
| `ajax_cuotas.php` | POST | Cuotas para modal de pagos |
| `ajax_historial.php` | POST | Historial HTML por legajo |
| `ajax_solicitar_talon.php` | POST | Solicitar talones |
| `ajax_cancelar_solicitudes.php` | POST | Cancelar talones |
| `ajax_agregar_email.php` | POST | Solicitar email |
| `ajax_cancelar_solicitud_email.php` | POST | Cancelar solicitud email |

### Notificaciones

| Endpoint | Uso |
|----------|-----|
| `ajax_notificaciones.php` | Listado |
| `ajax_notificaciones_leer.php` | Marcar leídas |

### Contratos

| Endpoint | Uso | Respuesta clave |
|----------|-----|-----------------|
| `ajax_contract_status.php` | Estado por alumno del grupo | `status[]`: `signed`, `admin_aprobado`, `estado_flujo`, `es_ingresante_externo`, `firma_habilitada`, `requisitos` |
| `ajax_contract_get.php` | Datos modal firma | `contract.contract_version`, `institucion_contrato` |
| `ajax_contract_accept.php` | Registrar firma | Genera PDF + inserta `contratos_aceptados` |

`estado_flujo` puede ser: `pendiente_firma`, `firma_bloqueada_noviembre`, `firma_bloqueada_adelanto_rv`, `pendiente_aprobacion`, `aprobado`, `inactivo_sin_firma`.

### Vistas contrato (no AJAX)

| URL | Función |
|-----|---------|
| `contrato_documento.php` | HTML personalizado (lectura) |
| `contrato_documento_firmado.php` | Descarga PDF firmado |

Constantes JS: `CONTRATO_DOCUMENTO_URL`, `CONTRATO_DOCUMENTO_FIRMADO_URL`.

---

## 5) Módulo contratos — mapa de archivos

```text
Familia (contratos.php / home)
    │
    ├─► homeContracts.js
    │       ├─► ajax_contract_status.php
    │       ├─► ajax_contract_get.php
    │       └─► ajax_contract_accept.php
    │
    ├─► contrato_documento.php
    │       └─► contract_render.php + docs/contratos/*.html
    │
    └─► contrato_documento_firmado.php
            └─► storage/contratos_firmados/

ajax_contract_accept.php
    └─► contract_pdf.php → Dompdf → storage + SHA-256
    └─► contract_institution.php → contratos_instituciones
```

### Base de datos (contratos)

| Tabla | Rol |
|-------|-----|
| **`contratos_instituciones`** | Config por colegio: directivo, ciclo (`contract_anio`, `contract_revision`), `contrato_activo`, `accepted_text` |
| **`contratos_aceptados`** | Firma por alumno + versión; SHA; ruta PDF; `admin_aprobado` |
| ~~`contracts`~~ | **Eliminada** (jun 2026) |

Plantillas: `docs/contratos/Contrato_{INST}_{AÑO}_{REV}.html`  
Código institución = últimas 2 letras del curso (`ET` → archivo `IDET`).  
Ingresantes: cursos `EXCJ`…`EXET` → misma institución; ver `ingresantes_externos_2027.php`.

---

## 6) Ingresantes externos 2027 (mapa rápido)

```text
Curso EX*
    │
    ├─► ingresantes_externos_2027.php
    │       └─► solo cuota 10 liquidada (Adelanto RV 2027)
    │
    ├─► home / historial / talón / ajax_cuotas
    │       └─► cuota_es_futura_para_curso(..., $curso)
    │
    └─► contract_institution.php
            ├─► firma si cuota 10 pagada
            └─► requisitos: adelanto aplica; resto_rv no aplica
                    └─► homeContracts.js (orden y textos distintos a regulares)
```

| Pieza | Rol |
|-------|-----|
| `ingresantes_externos_2027.php` | Lista de cursos, nombre cuota 10, `cuota_es_futura_para_curso` |
| `contract_institution.php` | `contrato_alumno_puede_firmar`, mensajes de bloqueo, requisitos |
| `homeContracts.js` | UI estados + checklist post-firma por tipo de alumno |
| `admin/includes/cuotas_admin_lib.php` | Ventana de cuotas en listados admin (solo cuota 10 en EX*) |

---

## 7) Flujo: firma de contrato

```mermaid
sequenceDiagram
    participant F as Familia (contratos.php)
    participant JS as homeContracts.js
    participant ST as ajax_contract_status
    participant GT as ajax_contract_get
    participant AC as ajax_contract_accept
    participant PDF as contract_pdf.php
    participant DB as MySQL

    F->>JS: Abre pantalla
    JS->>ST: POST csrf + familia
    ST->>DB: legajos + contratos_instituciones + contratos_aceptados
    ST-->>JS: estado por alumno
    F->>JS: Clic Firmar
    JS->>GT: POST student_dni
    GT-->>JS: versión, institución, reglamento
    F->>JS: Confirma modal + password
    JS->>AC: POST datos firmante
    AC->>PDF: Generar PDF + SHA-256
    PDF->>DB: INSERT contratos_aceptados
    AC-->>JS: ok + signed_pdf_url
```

---

## 8) Flujos clásicos (no contratos)

### Estado de cuenta

`home.php` → `ajax_historial.php` + `ajax_cuotas.php` (modal pagos).  
Ambos respetan `cuota_es_futura_para_curso` (ingresantes: solo cuota 10).

### Talones

`talondepago.php` → `ajax_solicitar_talon.php` / `ajax_cancelar_solicitudes.php`  
(+ filtro de cuotas liquidables por curso en `talon_context.php`).

### Auth

| UI | Proceso |
|----|---------|
| `php/login_padre.php` | Login |
| `php/primer_ingreso.php` … | Alta clave |
| `php/olvide_password.php` … | Reset email |

---

## 9) Backend admin / secretaría

| Archivo | Uso |
|---------|-----|
| `estados_de_cuenta/secretaria_documentacion.php` | Documentación y contratos por alumno |
| `estados_de_cuenta/informacion_general.php` | KPIs; contratos activos por `contratos_instituciones` |
| `admin/includes/cuotas_admin_lib.php` | Ventana de cuotas (EX* → solo cuota 10) |

---

## 10) Configuración

| Archivo | Contenido |
|---------|-----------|
| `config/db.php` | Conexión MySQL |
| `config/app.php` | `APP_PUBLIC_URL` (enlaces email/PDF) |
| `config/smtp.php` | Mail confirmación contrato |
| `backend/bootstrap.php` | Sesión, autoload, helpers |

---

## 11) Checklist: nueva página dashboard

1. Vista PHP + `data-page`.
2. `pages/nuevaPage.js` → `initNuevaPage`.
3. Registro en `index.js`.
4. `page-data` si aplica.
5. Endpoint + `apiEndpoints.js`.
6. Probar notificaciones y consola.

---

## 12) SQL de referencia

| Archivo | Cuándo usar |
|---------|-------------|
| `docs/sql/u207063327_contactos_db.sql` | Esquema completo actual (sin `contracts`) |
| `docs/sql/contratos_unificar_instituciones.sql` | Migración desde DB antigua (one-shot) |

---

*Última actualización: septiembre 2026 — ingresantes externos 2027.*
