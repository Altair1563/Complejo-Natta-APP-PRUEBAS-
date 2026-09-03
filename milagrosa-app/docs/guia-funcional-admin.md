# Guía funcional para administración — NATTA App

## 1) Propósito

Describe qué puede hacer una familia en la app, qué solicitudes genera y qué debe revisar administración/secretaría. No requiere conocimientos de programación.

**Documentos relacionados**

- `docs/guia-tecnica-devs.md` — detalle técnico para soporte/desarrollo
- `docs/frontend-backend-mapa.md` — mapa de pantallas y endpoints
- `docs/contrato-sha256-verificacion.md` — verificación de integridad del PDF firmado

---

## 2) Qué ofrece la app a las familias

1. Consultar estado de cuenta por alumno.
2. Ver historial de pagos.
3. Leer comunicados y notificaciones.
4. Solicitar talón de pago.
5. Solicitar cambio o alta de emails del grupo familiar.
6. **Firmar digitalmente el contrato de servicios educativos** (por alumno).
7. Enviar sugerencias e informar errores en los datos.
8. Recuperar o crear contraseña de acceso.

---

## 3) Pantallas principales

### `home.php` — Estado de cuenta

- Datos de alumnos del grupo familiar.
- Historial de pagos por alumno.
- Modal de pagos.
- Enlaces/indicadores de **estado del contrato 2027** (pendiente, firmado, aprobado).

### `contratos.php` — Contratos

- Pantalla dedicada para ver estado y **firmar** el contrato por alumno.
- El responsable debe leer el contrato HTML y el reglamento antes de marcar la casilla.
- Al firmar: confirma identidad con contraseña, nombre, DNI y domicilio.
- Recibe email con comprobante y enlace al PDF firmado.

### Otras pantallas

| Pantalla | Función |
|----------|---------|
| `info-importante.php` | Comunicados |
| `instituciones.php` | Contactos institucionales |
| `talondepago.php` | Solicitud/cancelación de talones |
| `agregaremail.php` | Gestión de emails familiares |
| `librodesugerencias.php` | Sugerencias |
| `informarerror.php` | Reporte de errores en datos |

---

## 4) Contratos digitales — flujo para familias

### Estados visibles

| Estado | Significado |
|--------|-------------|
| **Pendiente de firmar** | Aún no aceptó el contrato vigente para ese alumno |
| **Firmado — pendiente de aprobación** | Firma registrada; secretaría debe revisar documentación |
| **Aprobado** | Administración confirmó (`admin_aprobado = 1`) |
| **No aplica (inactivo)** | Alumno inactivo sin firma previa |

### Qué hace la familia al firmar

1. Entra a **Contratos** (o desde el home).
2. Abre el **contrato con sus datos** (HTML) y el **reglamento** (PDF).
3. Completa datos del firmante y marca la declaración de lectura.
4. Ingresa su **contraseña** de la app y confirma.
5. El sistema genera un **PDF íntegro**, lo guarda y envía **email de constancia** con huella SHA-256.

### Qué recibe la familia

- Email con fecha, IP, versión del documento y **código SHA-256** del PDF.
- Enlace para **descargar el PDF firmado** (requiere sesión en la app).

---

## 5) Contratos — gestión administrativa

### Configuración por colegio (base de datos)

Todo se administra en la tabla **`contratos_instituciones`** (ya no se usa la tabla `contracts` ni PDFs de plantilla en la DB):

| Campo | Qué controla |
|-------|--------------|
| Datos del directivo / institución / sede | Texto del preámbulo del contrato |
| `contract_anio` / `contract_revision` | Ciclo vigente (ej. 2027 / v1) |
| `contrato_activo` | Si ese colegio puede firmar (`1` = sí) |
| `accepted_text` | Texto legal de la casilla (igual para todos los colegios) |

El **texto del contrato** (cláusulas) se edita en archivos HTML en el servidor (`docs/contratos/`), no en la base de datos.

### Cambiar de ciclo (ej. 2027 → 2028)

1. Subir nuevas plantillas HTML (`Contrato_*_2028_v1.html`).
2. Actualizar en base de datos: `contract_anio = '2028'` (y revisión si corresponde).
3. Comunicar a familias que deben firmar la nueva versión.

### Secretaría / documentación

- Pantalla **`estados_de_cuenta/secretaria_documentacion.php`**: seguimiento de firmas y aprobación.
- Pantalla **`estados_de_cuenta/informacion_general.php`**: resumen de instituciones con contrato activo y cantidad de firmados/pendientes.

### Aprobación administrativa

Tras la firma digital, el registro queda con `admin_aprobado = 0` hasta que secretaría/administración lo confirme. Solo entonces el estado familiar muestra **Aprobado**.

### Verificación del PDF (integridad)

- El SHA-256 identifica el archivo exacto depositado al firmar.
- Para verificar: calcular SHA-256 del PDF descargado y comparar con el código del email o de la base (`contract_hash` / `signed_document_sha256`).
- Detalle técnico: `docs/contrato-sha256-verificacion.md`.

---

## 6) Notificaciones

- Icono de campana en el dashboard.
- Se marcan como leídas al abrir el panel.
- Uso: avisos institucionales y novedades.

---

## 7) Modal de pagos

Opciones para la familia:

1. Pagar total familiar.
2. Pagar total de un alumno.
3. Pagar una cuota específica.

Muestra concepto, monto, referencia y datos bancarios (CBU, CUIT, titular). En pago por cuota puede incluir saldos anteriores.

---

## 8) Solicitudes que impactan administración

| Solicitud | Comportamiento |
|-----------|----------------|
| **Talón de pago** | Una solicitud activa bloque duplicados; la familia puede cancelar |
| **Cambio de email** | Una solicitud pendiente por posición de email |
| **Sugerencias / errores** | Formularios en base; revisar periódicamente |
| **Firma de contrato** | Registro inmutable en `contratos_aceptados` + PDF en storage |

---

## 9) Buenas prácticas operativas

- Revisar diariamente: talones, emails, informes de error y **contratos pendientes de aprobación**.
- Mantener comunicados actualizados.
- Al inicio de ciclo: verificar `contrato_activo` y plantillas HTML por colegio.
- Validar pagos con comprobante, referencia (legajo/DNI), fecha y monto.

---

## 10) Flujo recomendado de atención

1. Identificar familia (`nro_familia` / alumno).
2. Revisar comunicados vigentes.
3. **Contratos:** verificar si firmó, si falta aprobación, si el PDF está disponible.
4. **Pagos:** talón, referencia, acreditación (hasta 72 h hábiles).
5. **Datos:** informe de error → corrección en sistema fuente.
6. **Acceso:** primer ingreso u olvidé contraseña.

---

## 11) Incidencias frecuentes

| Consulta | Acción |
|----------|--------|
| No veo pago acreditado | Ventana 72 h; pedir comprobante |
| No puedo pedir talón | Solicitud activa pendiente |
| No puedo cambiar email | Solicitud pendiente en esa posición |
| No aparece botón firmar contrato | Alumno inactivo, o colegio sin `contrato_activo = 1` |
| Error al firmar contrato | Escalar a soporte técnico (PDF / servidor) |
| SHA del PDF no coincide | Ver guía SHA; puede ser PDF regenerado o código impreso vs archivo final |
| Modal de pagos no abre | Escalar a soporte técnico |

---

## 12) Alcance

Esta guía cubre operación funcional. Para código, endpoints y estructura de archivos:

- `docs/guia-tecnica-devs.md`
- `docs/frontend-backend-mapa.md`

---

*Última actualización: junio 2026.*
