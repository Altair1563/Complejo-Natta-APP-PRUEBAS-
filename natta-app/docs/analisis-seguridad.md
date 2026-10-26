# Análisis de seguridad — NATTA App

**Fecha:** 26 de octubre de 2026  
**Alcance:** `natta-app/` (app de familias + panel admin/secretaría). Se excluyeron `vendor/` e `importador-excel/vendor/`.  
**Método:** revisión estática de código (auth, sesiones, CSRF, SQL, XSS, uploads, secretos, exposición HTTP, IDOR). No es un pentest en vivo ni un escaneo de infraestructura.

Este documento **no incluye contraseñas, hashes ni datos personales**. Donde el código los tiene, se indica el archivo y se pide rotación.

**Documentos relacionados:** `guia-tecnica-devs.md` · `guia-funcional-admin.md` · `frontend-backend-mapa.md`

---

## 1) Resumen ejecutivo

La app tiene bases sólidas en el flujo familiar cotidiano: cookies endurecidas, CSRF en POST, sentencias preparadas, escape XSS en las vistas revisadas y **sin IDOR entre familias** en los 14 endpoints AJAX.

El riesgo real hoy no es un SQL injection clásico. Está en **secretos y PII dentro del árbol web/git** y **huecos de autorización** (sesión admin/familia mezclada, endpoint legado de comunicados, primer ingreso sin prueba de email).

`backend/tools/` quedó **cerrado a HTTP** (octubre 2026): `.htaccess` Deny + guardia `PHP_SAPI` en cada script. El hallazgo C3 está mitigado.

| Severidad | Cantidad | Acción |
|-----------|----------|--------|
| Crítico | 2 abiertos (+ 1 mitigado: C3) | Rotar secretos y sacar PII del vhost **hoy** |
| Alto | 8 abiertos (+ 1 mitigado en HTTP: A4) | Corregir en el próximo ciclo (authz, reset, primer ingreso, lockout) |
| Medio | 11 | Planificar |
| Bajo / Info | 8 | Deuda técnica / confirmar regla de negocio |

---

## 2) Qué hay que hacer ya (prioridad)

1. **Rotar** contraseña de MySQL, SMTP y hash/clave admin legado. Tratarlas como filtradas: están en archivos del repo (`config/db.php`, `config/smtp.php`, `config/admin.php`).
2. **Sacar de git y del document root** los CSV/XLS de `config/imports/` y los Excel de `docs/cartas-documento/` (nombres, DNI, emails, saldos de familias).
3. ~~**Bloquear HTTP** a `backend/tools/`~~ **Hecho** (octubre 2026): `.htaccess` + `_cli_only.php`; se quitó el login web de `contrato_pdf_diagnostico.php`.
4. Unificar `.htaccess` de `config/` a `Require all denied` (el actual mezcla `Deny from all` de Apache 2.2 con Basic Auth y un `.htpasswd` commiteado).
5. Agregar `.gitignore` de secretos e imports. Aislar sesión admin vs familia.

---

## 3) Superficie de ataque

| Superficie | Entrada | Autenticación actual |
|------------|---------|----------------------|
| App familias | `index.php`, `home.php`, `backend/ajax/*` | Sesión `dni_alumno` + `nro_familia` + CSRF |
| Auth familias | login, primer ingreso, olvide clave, privacy gate | Mixto (ver hallazgos) |
| Dashboard admin | `admin/admin_dashboard.php`, `admin_ajax.php` | `admin_users` + capabilities |
| Secretaría | `admin/estado_alumno.php` | Mismos usuarios, rol escuela |
| Importaciones | `php/importar_*.php`, `importador-excel/import_facturado-ingresado.php` | Superadmin + CSRF (bien) |
| Storage | `storage/contratos_firmados/`, `comunicados_pdfs/` | Deny all + descarga autenticada |
| Config / imports | `config/*.php`, `config/imports/*` | `.htaccess` frágil |
| Docs operativos | `docs/cartas-documento/*.xlsx`, `docs/sql/*.sql` | **Sin protección HTTP** |
| Tools | `backend/tools/*.php` | HTTP denegado (`.htaccess` + `_cli_only.php`); solo CLI |
| Legado | `php/publicar_comunicado.php` | Solo `admin_logged_in` |

---

## 4) Hallazgos críticos

### C1. Credenciales de producción en el código

**Archivos:** `config/db.php`, `config/smtp.php`, `config/admin.php`

Usuario de MySQL, contraseña, nombre de base Hostinger, SMTP de Hostinger y hash de admin legado están en texto (o hash) dentro del árbol. `_ACCESS` y `.htaccess` no protegen el historial de git ni una copia del proyecto (esta carpeta se llama PRUEBAS y apunta a cuentas de `complejonatta.com`).

**Impacto:** acceso a la base, envío de mail institucional y ataque offline al hash admin.

**Remediación:** rotar ya. Pasar secretos a variables de entorno o a un archivo **fuera** del document root, nunca versionado. En git dejar solo `*.example`.

---

### C2. PII de familias en `config/imports/`

**Archivos:** `config/imports/email-padres.csv`, `legajos.csv`, `legajos-inactivos.csv`, `rptfacing*.xls`

Planillas reales: emails, nombres, DNI, cursos, números de familia, saldos. No hay `.gitignore` de la app. Si `config/.htaccess` falla (ver A4), esos archivos se descargan por URL.

**Impacto:** filtración masiva de datos de menores y responsables (Ley 25.326).

**Remediación:** borrar del repo y del vhost; guardar imports fuera de `public_html`; `.gitignore` de `config/imports/`. Tratar esta copia como incidente de datos si el repo se compartió.

---

### C3. Regeneración de PDFs firmados sin autenticación — **mitigado** (octubre 2026)

**Archivo original:** `backend/tools/regenerar_pdfs_firmados.php`

Antes no había chequeo de CLI ni sesión: un GET HTTP conectaba a la DB y llamaba `contrato_regenerate_signed_pdfs_from_db()` (año por defecto `2026`), pudiendo reescribir contratos firmados y cambiar las huellas SHA-256.

**Mitigación aplicada**

- `backend/tools/.htaccess`: `Require all denied` (Apache 2.4) / `Deny from all` (2.2).
- `_cli_only.php` incluido al inicio de **todos** los scripts de la carpeta: si `PHP_SAPI` no es `cli` ni `phpdbg`, responde 404 y sale (cubre nginx u `.htaccess` ignorado).
- `contrato_pdf_diagnostico.php` ya no tiene formulario de contraseña ni setea `admin_logged_in`.

Uso restante (SSH): `php backend/tools/regenerar_pdfs_firmados.php [año]`.

---

## 5) Hallazgos altos

### A1. `docs/` es público: Excel de cartas documento y SQL

No hay `.htaccess` en `docs/`. Rutas usadas por el módulo CD:

- `docs/cartas-documento/legajo + resp/Legajos limpio prueba.xlsx`
- `docs/cartas-documento/legajo + resp/legajos + resp.xlsx`
- `docs/cartas-documento/modelo-CD/MODELO CARTA DOC.docx`
- `docs/sql/u207063327_contactos_db.sql` (esquema; el nombre revela la cuenta Hostinger)

**Impacto:** nombres, responsables y deuda sin login. El dump no trae `INSERT` de filas, pero sí el mapa completo de tablas.

**Remediación:** sacar spreadsheets operativos del document root; servir solo por exporters autenticados. Denegar HTTP a `docs/sql/` y `docs/cartas-documento/`.

---

### A2. `.htpasswd` en git y `.htaccess` de `config/` mezclado

**Archivos:** `config/.htpasswd`, `config/.htaccess`

`Deny from all` (Apache 2.2) + Basic Auth con `AuthUserFile` a la ruta de producción (`/home/u207063327/domains/complejonatta.com/...`). `storage/` usa bien `Require all denied`. Si `mod_access_compat` está apagado, `Deny from all` no aplica.

**Remediación:** un solo `Require all denied`. Borrar `.htpasswd` del git y rotar esa clave.

---

### A3. Sin `.gitignore` de la app

No hay `.gitignore` en la raíz del repo ni en `natta-app/` (salvo el de un paquete vendor). El próximo commit puede volver a subir secretos y PII.

**Remediación:** ignorar `config/db.php`, `smtp.php`, `admin.php`, `.htpasswd`, `config/imports/`, `.env`, contenido de `storage/` y uploads.

---

### A4. Tools HTTP: login legado + `passthru` + DNI en rutas — **HTTP mitigado** (octubre 2026)

El acceso por navegador y el formulario de `contrato_pdf_diagnostico.php` (que setea `admin_logged_in` sin `admin_user_id`) están cerrados: misma mitigación que C3.

**Queda abierto (solo CLI / código):**

| Script | Problema restante |
|--------|-------------------|
| `regenerar_pdf_local.php` / `inspect_pdf_margins.php` | Ruta por defecto con DNI de un alumno; `passthru` en CLI |
| `test_contrato_*.php` | Operativos; ya no ejecutables por URL |

**Remediación restante:** no hardcodear nombres de PDF con DNI; exigir `$argv[1]`.

---

### A5. Sesión única: admin y familia se mezclan

Cookie: `NATTAAPPSESSID` / path `/natta-app/`.

- `php/login_padre.php` no llama `admin_clear_session_user()`.
- `admin_set_session_user()` no borra `dni_alumno` / `nro_familia`.
- `session_regenerate_id(true)` conserva el payload.
- `session_is_authenticated()` es verdadero si hay **admin o** familia.

**Impacto:** en una PC compartida (secretaría), quien entra después hereda el otro rol. Un padre puede quedar con `admin_*`; un admin puede operar AJAX de esa familia.

**Remediación:** al loguear un lado, destruir el otro (o dos nombres de sesión). Un solo “contexto de auth” que se reemplaza, no se fusiona.

---

### A6. Endpoint legado publica comunicados a todo el complejo

**Archivo:** `php/publicar_comunicado.php`

El dashboard exige `admin_can('comunicados')`. Este PHP solo mira `$_SESSION['admin_logged_in']`. Secretaría y directivo **sí** setean ese flag, pero **no** tienen capability `comunicados`. Inserta notificaciones a **todas** las familias (`SELECT DISTINCT nro_familia FROM legajos`).

El atajo vía el formulario de `contrato_pdf_diagnostico.php` ya no existe (A4 mitigado). El riesgo restante es una sesión de secretaría/directivo.

**Remediación:** `admin_can('comunicados')` + `admin_user_id`. Deprecar el archivo si el alta real es `post_handlers.php`.

---

### A7. Token de reset atado al DNI, no al email

**Archivos:** `php/procesar_olvide.php`, `php/restablecer_password.php`, `php/guardar_nueva_password.php`

El insert es `(dni_alumno, token, …)`. El email viaja en `$_GET['email']` y se copia a sesión. El `UPDATE` usa ese email. El producto admite varios responsables del mismo alumno (comentario en `procesar_olvide.php`).

**Impacto:** quien tiene un enlace válido (o pide reset) puede cambiar `email=` a otra cuenta del mismo DNI y fijarle la contraseña.

**Remediación:** guardar el email (o su hash) con el token. Ignorar el email de la query salvo para mostrar. Invalidar tokens previos de ese par.

---

### A8. Primer ingreso: alcanza con email + DNI

**Archivos:** `php/validar_primer_ingreso.php`, `php/guardar_password.php`

Tras CSRF, comprueba que el mail esté en `email_familia` y el DNI en `legajos` de esa familia. No hay mail de prueba, ni segundo factor, ni rate limit como el login.

**Impacto:** en un colegio, email de un padre y DNI del alumno suelen ser conocibles. Si esa cuenta aún no tiene clave, un tercero la crea y se queda con el acceso.

**Remediación:** enlace de un solo uso al email (igual que el reset). Rate limit en DB. Mensajes genéricos.

---

### A9. Lockout admin solo en `$_SESSION`

**Archivos:** `admin/includes/auth.php`, `admin/estado_alumno.php`, `admin/control_usuarios.php`

5 fallos → 15 minutos, pero en la cookie de sesión. Borrar la cookie reinicia el contador. El login familiar **sí** usa tabla `login_attempts` (email + IP).

**Remediación:** mismos intentos en DB por usuario e IP.

---

## 6) Hallazgos medios

### M1. Tokens de reset en texto plano y varios vigentes

`bin2hex(random_bytes(32))` se guarda tal cual. No se borran los anteriores del mismo DNI. Una filtración de `password_resets` da enlaces vivos (1 hora).

Guardar `hash('sha256', $token)` y expirar/borrar los no usados del mismo email+DNI.

### M2. Enumeración de usuarios (reset y primer ingreso)

Mensajes distintos: email no registrado / DNI no coincide / no tiene contraseña (usar “Primera vez”). Permite armar el padrón email↔DNI.

Misma respuesta genérica siempre, después de CSRF y formato.

### M3. Privacy gate sin timeout de inactividad

Tras password correcta se guarda `$_SESSION['privacy_gate']` **sin** `dni_alumno`, así que `session_is_authenticated()` es falso y se salta el idle de 30 min. `gate_started_at` se escribe y no se lee. Cookie `lifetime 0` = hasta cerrar el navegador.

Quien encuentra esa PC puede aceptar la política y entrar a `home.php`.

Aplicar idle al gate (p. ej. 10 min) y destruir al vencer.

### M4. Privacy gate falla abierto

Si `privacy_policy_user_has_accepted()` lanza excepción, el `catch` solo loguea y `$needs_privacy_gate` queda `false`: se crea sesión familiar igual.

Fallar cerrado: no setear `dni_alumno` / `nro_familia` si la consulta de política falla.

### M5. Rol admin no se relee de la DB

`admin_can()` y `admin_is_logged_in()` leen solo sesión. `activo = 1` se mira al login. Un usuario dado de baja o degradado sigue hasta idle (30 min) o logout.

En cada request admin recargar `rol`, `escuela_codigo`, `activo`.

### M6. Cambiar la clave no invalida otras sesiones familiares

`guardar_nueva_password.php` actualiza el hash y limpia keys de reset. Otras cookies con `dni_alumno` siguen vivas.

Versión de sesión o `password_changed_at` chequeada en `requireAuth` / `secure_session_start`.

### M7. Secretaría puede leer cuentas de todo el complejo

En `estado_alumno.php`, vistas `cuentas` y `listado_familias` permiten `escuela=ALL` también para rol escuela (comentario de producto). Escrituras de contratos **sí** se fuerzan a `admin_escuela_codigo()`. `exportar_listado_familias.php` puede exportar todas las familias.

Si el negocio pide aislamiento por colegio, es IDOR de lectura (deudas, grupos, alumnos). Si “ver el complejo” es a propósito, documentarlo y no tratarlo como bug.

### M8. Exports GET sin CSRF

`exportar_csv.php`, `exportar_listado_familias.php`, `exportar_estado_alumno_emails.php`, descargas de contrato/comunicado. Con `SameSite=Lax`, un click top-level envía la cookie y baja el CSV. No es CSRF de cambio de estado.

POST + CSRF, o token de un solo uso en la query.

### M9. `X-Forwarded-Proto` se confía para cookie Secure y HSTS

`config/session.php`. Si PHP se alcanza sin un proxy que limpie el header, un cliente puede fingir HTTPS.

Confiar el header solo detrás del proxy conocido, o marcar HTTPS en config del server.

### M10. CSP con `'unsafe-inline'`

No bloquea XSS inline. Hoy el escape es bueno; es defensa en profundidad rota.

Nonces o hashes; sacar `'unsafe-inline'`.

### M11. Sin `.htaccess` en la raíz de la app

Hay deny en `config/`, `storage/`, `backend/tools/` e `importador-excel/` (este último solo `csv|xls|xlsx`, **no** PHP). Siguen sin deny: `docs/`, `vendor/` (p. ej. `vendor/phpmailer/phpmailer/get_oauth_token.php`).

`Options -Indexes` en la raíz; denegar `/docs`, `/vendor`, `/importador-excel` salvo el front controller autenticado.

`display_errors` está comentado en `php/importar_legajos.php` y `php/email_familia.php`: si el php.ini lo tiene On, un fallo de import filtra SQL/rutas al browser (igual están gated a superadmin).

---

## 7) Hallazgos bajos e informativos

| ID | Sev. | Tema | Notas |
|----|------|------|-------|
| B1 | Bajo | Clave familiar mín. 6 caracteres | Admin pide 8. Alinear. |
| B2 | Bajo | Algunos AJAX solo piden `nro_familia` | `ajax_agregar_email`, notificaciones, cancelar email. Hoy `nro_familia` nace junto con `dni_alumno`. Unificar `requireAuth()`. |
| B3 | Bajo | `innerHTML` en flash de estado alumno | `estado_alumno.php`. Hoy el `msg` es string fijo; no interpolar texto de usuario. |
| B4 | Bajo | MIME de import incluye `octet-stream` / `zip` | Destino lo nombra el server (`rptfacingN`). Ajustar allowlist. |
| B5 | Bajo | Path de PDF firmado con `strpos` | `contract_pdf.php`. Preferir `str_starts_with($real, $base . DIRECTORY_SEPARATOR)`. |
| I1 | Info | Bolsa CV sin escuela | `descargar_curriculum_cv.php` por `id`. La tabla no tiene colegio; parece bolsa compartida. |
| I2 | Info | `requireAuth()` no revalida la DB | Solo keys de sesión. Usado por vistas de contrato. |
| I3 | Info | `casita-jesus.html` (raíz del repo, fuera de `natta-app`) | Landing pública. Teléfonos/email institucionales y GA. Sin secretos de la app. No servirla en el vhost de `natta-app`. |

---

## 8) Lo que se revisó y **no** es un hallazgo

| Área | Resultado |
|------|-----------|
| **SQL injection** | AJAX, admin, imports y heartbeat usan `prepare`/`bind`. Tablas del importer las elige el server, no el request. |
| **IDOR entre familias** | Los 14 `backend/ajax/*.php` atan `legajo` / `student_dni` a `nro_familia` de sesión (o cargan solo esa familia). Preview de contrato va por sesión, no por query. PDF firmado igual. |
| **XSS almacenado** | Comunicados: `strip_tags` al guardar, `htmlspecialchars` al mostrar. SweetAlert usa `text:`. Contratos JS: `escapeHtml()`. Modales admin: `textContent`. |
| **Open redirect** | `Location` fijos o `admin_page_url()` / `admin_panel_home()`. |
| **RCE en código de app** | No hay `eval` / `exec` / `system` / `shell_exec` / `unserialize` en código propio. El único `passthru` está en tools CLI (ya no HTTP). |
| **CORS** | No hay `Access-Control-Allow-Origin`. |
| **Upload de comunicados** | Extensión `.pdf`, `finfo`, tamaño 15 MB, `is_uploaded_file`, destino `comunicado_{id}.pdf`, storage deny, descarga con sesión. |
| **Upload Actualizaciones** | CSRF, superadmin, nombres fijos `rptfacing[1-5]`, nunca el filename del cliente. |
| **Importer Excel** | Superadmin + POST + CSRF. |
| **Contrato: firma** | CSRF, pertenencia al grupo, re-pide password, bloqueos de curso/cuota. |

### Controles que ya están bien

- Cookie `HttpOnly`, `SameSite=Lax`, `use_strict_mode`, `use_only_cookies`, path `/natta-app/`, `Secure` en HTTPS.
- Regeneración de ID al login (familia, privacy accept, admin); rotación cada 15 min; idle 30 min en sesiones autenticadas.
- Headers: `X-Frame-Options`, `nosniff`, Referrer-Policy, Permissions-Policy, CSP, HSTS si HTTPS.
- CSRF `hash_equals` en login familia, primer ingreso, reset, privacy, 14 AJAX, login admin, POST dashboard.
- Login familia: email y DNI de la misma familia; `password_verify`; lockout en DB por email e IP; auditoría.
- Passwords con `PASSWORD_DEFAULT`. Firma de contrato vuelve a pedir la clave.
- RBAC admin: secretaría/directivo no entran al dashboard; no pueden desactivarse a sí mismos ni quitarse superadmin.
- Escrituras de contratos de rol escuela acotadas a su colegio.
- `storage/` y `backend/tools/` con deny HTTP. PHP de `config/` muere sin `_ACCESS`. Tools: `_cli_only.php`.

---

## 9) Plan de remediación sugerido

### Ya (horas)

1. Rotar MySQL, SMTP, admin legado y Basic Auth de `config/.htpasswd`.
2. Deny HTTP a `docs/cartas-documento/` + `docs/sql/`. (`backend/tools/` ya está cerrado.)
3. ~~Guardia CLI en tools / form de diagnóstico~~ **Hecho.**
4. Dejar `config/.htaccess` en solo `Require all denied`.

### Esta semana

5. `.gitignore` + templates `config/*.example`. Sacar imports y Excel de CD del git (y del historial si el remoto es compartido).
6. Separar sesión admin / familia.
7. `admin_can('comunicados')` en `publicar_comunicado.php` o borrar el archivo.
8. Token de reset ligado al email; hash del token; un solo token vivo.
9. Primer ingreso con enlace al mail + rate limit.
10. Lockout admin en DB.

### Siguiente iteración

11. Timeout del privacy gate; fail-closed si falla la consulta de política.
12. Releer `admin_users` en cada request; invalidar sesión al resetear clave.
13. CSRF/POST en exports; `Options -Indexes`; deny `vendor/` e `importador-excel`.
14. CSP sin `'unsafe-inline'`; clave familiar ≥ 8; `requireAuth()` unificado en AJAX.

Confirmar con dirección si secretaría **debe** ver cuentas de todo el complejo (M7). Si no, forzar `admin_escuela_codigo()` también en lecturas y CSV.

---

## 10) Limitaciones

- Revisión de código, no explotación en el servidor de Hostinger.
- No se probaron WAF, TLS, backups ni permisos de MariaDB en el hosting.
- No se abrieron los Excel de cartas (se asume PII por el uso del módulo).
- `casita-jesus.html` está fuera de `natta-app` y no se auditó como app PHP.
- Este informe **no** debe copiar secretos. Los valores vivos siguen en `config/db.php` y `config/smtp.php` hasta que se roten y se saquen del árbol.

---

*Informe generado por revisión de código — octubre 2026. Actualizado el mismo mes: C3/A4 HTTP en `backend/tools/` mitigados. No sustituye un pentest ni la rotación de credenciales.*
