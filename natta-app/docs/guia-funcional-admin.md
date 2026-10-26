# Guía funcional para administración — NATTA App

## 1) Propósito

Describe qué puede hacer una familia en la app, qué solicitudes genera y qué debe revisar administración, secretaría y dirección. No requiere conocimientos de programación.

**Documentos relacionados**

- `docs/guia-tecnica-devs.md` — detalle técnico para soporte/desarrollo
- `docs/frontend-backend-mapa.md` — mapa de pantallas y endpoints

---

## 2) Dos entradas al sistema

| Quién | Dónde entra | Para qué |
|-------|-------------|----------|
| **Familia** | `index.php` (email + DNI + contraseña) | Estado de cuenta, pagos, contratos, solicitudes |
| **Superadmin / admin** | `admin/admin_dashboard.php` | Configuración, solicitudes, comunicados, informes, cartas |
| **Secretaría / directivo** | `admin/estado_alumno.php` | Documentación, cuentas y contratos de **su escuela** |

Tras el login familiar, si hay una política de privacidad vigente sin aceptar, aparece una pantalla intermedia (`privacy_gate`) antes del home.

---

## 3) Roles del personal

| Rol | Qué ve |
|-----|--------|
| **Superadmin** | Todo el dashboard: actualizaciones, usuarios, cartas, KPIs, auditoría |
| **Admin** | Comunicados, QR de la app, solicitudes (talones, emails, informes, sugerencias) y auditorías |
| **Secretaría** | Panel Estado Alumnos de la escuela asignada |
| **Directivo** | Igual que secretaría, más **Control de usuarios** de esa escuela |

Las secretarías no operan el dashboard general. Si un usuario de escuela intenta entrar al dashboard, el sistema lo lleva a Estado Alumnos.

---

## 4) Qué ofrece la app a las familias

1. Consultar estado de cuenta por alumno.
2. Ver historial de pagos.
3. Leer comunicados y notificaciones.
4. Solicitar talón de pago.
5. Solicitar cambio o alta de emails del grupo familiar.
6. **Firmar digitalmente el contrato de servicios educativos** (por alumno).
7. Enviar sugerencias e informar errores en los datos.
8. Recuperar o crear contraseña de acceso.
9. Aceptar la política de privacidad vigente.
10. Compartir el acceso a la app con un código QR (desde el dashboard familiar).

---

## 5) Pantallas principales (familias)

### `home.php` — Estado de cuenta

- Datos de alumnos del grupo familiar.
- Historial de pagos por alumno (ingresantes EX*: solo cuota 10 visible).
- Modal de pagos.
- Enlaces/indicadores de **estado del contrato 2027** (bloqueado por pago, reubicación, pendiente, firmado, requisitos, aprobado).

### `contratos.php` — Contratos

- Pantalla dedicada para ver estado y **firmar** el contrato por alumno.
- Aviso de habilitación: noviembre (regulares), Adelanto RV 2027 (ingresantes) o febrero (cursos en reubicación).
- El responsable debe leer el contrato HTML y el reglamento antes de marcar la casilla.
- Al firmar: confirma identidad con contraseña, nombre, DNI y domicilio.
- Recibe email con comprobante y enlace al PDF firmado.

### Otras pantallas

| Pantalla | Función |
|----------|---------|
| `info-importante.php` | Comunicados |
| `instituciones.php` | Contactos institucionales (8 escuelas) |
| `talondepago.php` | Solicitud/cancelación de talones |
| `agregaremail.php` | Gestión de emails familiares |
| `librodesugerencias.php` | Sugerencias |
| `informarerror.php` | Reporte de errores en datos |
| `politica-privacidad.php` | Texto público de la política |

---

## 6) Contratos digitales — flujo para familias

### Estados visibles

| Estado | Significado |
|--------|-------------|
| **Pendiente de firmar** | Puede firmar; aún no aceptó el contrato vigente |
| **Bloqueado — noviembre** | Alumno regular: falta abonar la cuota de noviembre |
| **Bloqueado — Adelanto RV** | Ingresante externo 2027: falta abonar la cuota 10 (Adelanto RV 2027) |
| **Bloqueado — reubicación** | Curso de egreso / pasaje (jardín→primaria, 6.º→secundaria, etc.): firma en febrero, cuando el alumno tenga el curso correcto |
| **Firmado — requisitos pendientes** | Firma registrada; faltan documentación, deudas y/o reserva |
| **Aprobado / validez completa** | Firma + todos los requisitos cumplidos |
| **No aplica (inactivo)** | Alumno inactivo sin firma previa |

### Requisito para habilitar la firma

| Tipo de alumno | Cuándo se habilita el botón Firmar |
|----------------|-------------------------------------|
| **Regular** | Cuando la cuota de **noviembre** figura pagada (en Superior / SU es la cuota 10) |
| **Ingresante externo 2027** (cursos `EXCJ`, `EXHV`, `EXJA`, `EXJN`, `EXSC`, `EXMB`, `EXET`) | Cuando figura pagada la **CUOTA-10 Adelanto de Reserva de vacante (2027)** |
| **Pendiente de reubicación** | No firma hasta que el curso deje de ser uno de los de egreso/pasaje |

Cursos en reubicación (firma cerrada hasta reubicar): 3.º de jardines (CJ, HV, JA), 6.º de primarias (JN, SC), últimos años de MB/ET y 4.º de Superior.

### Qué hace la familia al firmar

1. Entra a **Contratos** (o desde el home, si la firma ya está habilitada).
2. Abre el **contrato con sus datos** (HTML) y el **reglamento** (PDF).
3. Completa datos del firmante y marca la declaración de lectura.
4. Ingresa su **contraseña** de la app y confirma.
5. El sistema genera un **PDF íntegro**, lo guarda y envía **email de constancia** con huella SHA-256.

### Qué recibe la familia

- Email con fecha, IP, versión del documento y **código SHA-256** del PDF.
- Enlace para **descargar el PDF firmado** (requiere sesión en la app).

### Requisitos para que el contrato entre en vigencia

Tras firmar, la familia ve una lista de requisitos. El orden y los textos cambian según el tipo de alumno.

**Ingresantes externos 2027**

1. Pago del ADELANTO RV 2027 (cuota 10)
2. Firma y aceptación del contrato / reglamento
3. Presentación de documentación institucional
4. Sin deudas del ciclo 2026 (se habilita tras el Resto de RV / cuota 11 y sin deuda del grupo)

**Alumnos regulares**

1. Sin deudas del ciclo 2026
2. Firma y aceptación
3. Documentación
4. Pago de la Reserva de Vacante 2027 (adelanto + resto; aranceles en diciembre). En Superior (SU) la reserva de vacante no aplica.

---

## 7) Ingresantes externos 2027 — operación

### Quiénes son

Alumnos en cursos **EX*** (alta de ingresantes nuevos). Solo tienen liquidada / visible la **cuota 10** (Adelanto RV 2027). No deben verse ni pedirse cuotas mensuales 1–9 ni el resto de reserva en estado de cuenta, talón o modal de pagos.

### Qué debe hacer administración

1. Dar de alta el alumno en **Alta de Alumnos** (el curso queda en `EX*` según escuela) o importar el legajo.
2. Al cargar cuotas: importar **únicamente la cuota 10** para esos legajos.
3. Cuando esa cuota figure **pagada**, la familia puede firmar el contrato del colegio correspondiente (CJ, HV, JA, JN, SC, MB o ET).
4. Seguir el flujo habitual de aprobación documental en secretaría.
5. El **Resto de RV (cuota 11)** no se muestra aún como requisito de reserva en ingresantes; sí condiciona más adelante la evaluación de “sin deudas 2026”.
6. Los ingresantes **no entran** al circuito de cartas documento / notificaciones fehacientes.

---

## 8) Contratos — gestión administrativa

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

Pantalla **`admin/estado_alumno.php`**:

- **Estados de cuenta** y **Listado por familias** (se puede ver todo el complejo).
- **Revisión de contratos**: seguimiento de firmas, documentación recibida y aprobación.
- **Emails** de contacto.
- **Bolsa de trabajo**: currículums.
- **Control de usuarios** (dirección / admin): supervisión de secretarías.

En el dashboard (superadmin): pestaña **Revisión Contratos** y KPIs en **Información General**.

### Aprobación administrativa

Tras la firma digital, el registro queda con `admin_aprobado = 0` hasta que secretaría/administración lo confirme. Solo entonces el estado familiar muestra **Aprobado**. Si hay datos erróneos en la firma, el registro puede marcarse con información errónea (`info_erronea`) y aparece como pendiente en el badge de Revisión Contratos.

### Verificación del PDF (integridad)

- El SHA-256 identifica el archivo exacto depositado al firmar.
- Para verificar: calcular SHA-256 del PDF descargado y comparar con el código del email o de la base (`contract_hash` / `signed_document_sha256`).
- Si se regenera el PDF (cambio de plantilla o márgenes), la huella cambia.

---

## 9) Panel Estado Alumnos (secretaría / dirección)

Acceso con usuario de escuela. Cada usuario ve **su** colegio; superadmin/admin pueden cambiar de escuela (pestañas CJ, HV, JA, JN, SC, MB, ET, SU).

| Vista | Uso diario |
|-------|------------|
| Estados de cuenta | Deuda al día, filtros, resumen al día / con deuda / becados |
| Listado por familias | Misma información agrupada por `nro_familia` |
| Revisión contratos | Quién firmó, PDF, documentación recibida, aprobación |
| Emails | Contactos del grupo; exportar Excel |
| Bolsa | Currículums por área; descargar CV |
| Control de usuarios | Solo dirección: actividad de secretarías de esa escuela |

Las modificaciones de contratos (aprobación / documentación) de un rol de escuela **siempre** se aplican a su escuela asignada, aunque esté mirando el complejo.

---

## 10) Dashboard admin — pestañas

### Sistema (superadmin)

| Pestaña | Función |
|---------|---------|
| **Actualizaciones** | Subir CSV/Excel a `config/imports/` y disparar importadores (cuotas `rptfacing`, emails, legajos, inactivos) |
| **Configuración** | Cuota vigente (mes de corte) y modo mantenimiento de la app familiar |
| **Agregar Usuario** | Alta/edición de usuarios admin, secretaría y directivo (con escuela) |
| **Alta de Alumnos** | Carga de ingresantes nuevos (curso `EX*`); lista oculta de recientes |
| **Cartas Doc.** | CD y notificaciones fehacientes (ver §11) |
| **QR App familias** | URL pública y QR para compartir el login |
| **Auditoría Admin.** | Acciones del personal |
| **Auditoría App** | Eventos de la app familiar (login, privacy, etc.) |

### Solicitudes

Revisar a diario. Hay badges de pendientes en el menú.

| Pestaña | Origen familiar |
|---------|-----------------|
| Solicitudes de talón | `talondepago.php` |
| Informes de error | `informarerror.php` |
| Cambios de email | `agregaremail.php` |
| Sugerencias | `librodesugerencias.php` |

Se pueden cambiar estados, eliminar, marcar leídas y exportar CSV. Una solicitud de talón o de email **bloquea duplicados** mientras esté pendiente; la familia puede cancelar.

### Comunicados App

- **Generales:** llegan a todas las familias (opcional PDF).
- **Individuales:** notificación a un grupo/alumno.

### Información General

- **Matrículas:** cantidades por escuela/curso.
- **Ingresado-Facturado:** montos.
- **Información APP:** uso de la app, aceptaciones de privacy.
- **Listado por Familias:** export CSV.
- **Estado Alumnos:** atajo al panel de secretaría.

---

## 11) Cartas documento y notificaciones fehacientes

Pestaña **Cartas Doc.** (solo superadmin).

### Origen de datos

- Listado de responsables: Excel `Legajos limpio prueba.xlsx`.
- Deuda: **legajos activos** (no se usan inactivos).
- Si se filtra por escuela, entra **la familia completa**. Hermanos que no están en el Excel se completan desde la base por número de familia.

### Criterio

| Documento | Quién lo lleva |
|-----------|----------------|
| **Carta documento (CD)** | Adeuda **marzo, abril o mayo** |
| **Notificación fehaciente (NF)** | No adeuda esos meses, y sí **junio o julio** |
| Ninguno | Al día en ese tramo, o ingresante EX* |

Un mismo alumno no lleva CD y NF a la vez: si hay deuda mar–may, es CD.  
La **deuda total del grupo** que se informa es la suma impaga **hasta septiembre**.

### Qué puede hacer administración

1. Filtrar por tipo (CD / NF / ambas), escuela y búsqueda.
2. Exportar CSV o Excel de cotejo.
3. Generar el **ZIP de cartas** (DOCX) desde el modelo `MODELO CARTA DOC.docx`.

---

## 12) Notificaciones (familias)

- Icono de campana en el dashboard familiar.
- Se marcan como leídas al abrir el panel.
- Uso: avisos institucionales, comunicados individuales y novedades.

---

## 13) Modal de pagos

Opciones para la familia:

1. Pagar total familiar.
2. Pagar total de un alumno.
3. Pagar una cuota específica.

Muestra concepto, monto, referencia y datos bancarios (CBU, CUIT, titular). En pago por cuota puede incluir saldos anteriores. En ingresantes EX* solo aparece la cuota 10.

---

## 14) Solicitudes que impactan administración

| Solicitud | Comportamiento |
|-----------|----------------|
| **Talón de pago** | Una solicitud activa bloquea duplicados; la familia puede cancelar |
| **Cambio de email** | Una solicitud pendiente por posición de email |
| **Sugerencias / errores** | Formularios en base; revisar periódicamente |
| **Firma de contrato** | Registro inmutable en `contratos_aceptados` + PDF en storage |
| **Alta de alumno nuevo** | Queda en lista de altas; el curso es EX* hasta la reubicación |

---

## 15) Actualización de datos (importación)

Solo **superadmin**, pestaña Actualizaciones.

1. Subir archivos con **nombre fijo**:
   - Excel de cuotas: `rptfacing.xls` … `rptfacing5.xls` (o `.xlsx`)
   - `email-padres.csv`
   - `legajos.csv`
   - `legajos-inactivos.csv`
2. Ejecutar el importador correspondiente.
3. Verificar la **última actualización** en Estado Alumnos / Información General.

Sin una importación reciente, deudas y estados de cuenta quedan desactualizados (impacto en contratos, talones y cartas documento).

---

## 16) Modo mantenimiento

En **Configuración**, el superadmin puede activar el modo mantenimiento. Las familias ven `mantenimiento.php` en lugar del dashboard. Útil durante importaciones masivas.

---

## 17) Política de privacidad

- Texto público en `politica-privacidad.php`.
- Tras un login correcto, si el DNI+email no aceptó la versión vigente, debe aceptar para entrar.
- Si rechaza, no entra y queda auditado.
- Las aceptaciones se ven en Información APP.

---

## 18) Bolsa de trabajo (currículums)

Vista **Bolsa** del panel Estado Alumnos: listado de CV por área, con descarga. No forma parte del dashboard familiar.

---

## 19) Buenas prácticas operativas

- Revisar diariamente: talones, emails, informes de error y **contratos pendientes de aprobación** (badges del menú).
- Mantener comunicados actualizados.
- Al inicio de ciclo: verificar `contrato_activo` y plantillas HTML por colegio.
- Ingresantes EX*: importar solo cuota 10; no liquidar mensuales hasta que corresponda.
- Cursos de egreso/pasaje: no pedir firma hasta reubicar el curso (febrero).
- Validar pagos con comprobante, referencia (legajo/DNI), fecha y monto.
- Antes de generar cartas documento: confirmar que el Excel de responsables y la última importación de cuotas estén al día.

---

## 20) Flujo recomendado de atención

1. Identificar familia (`nro_familia` / alumno) y si el curso es **EX*** (ingresante), **regular** o **reubicación**.
2. Revisar comunicados vigentes.
3. **Contratos:** verificar si puede firmar (noviembre vs Adelanto RV vs reubicación), si firmó, requisitos y PDF.
4. **Pagos:** talón, referencia, acreditación (hasta 72 h hábiles).
5. **Datos:** informe de error → corrección en sistema fuente e importación.
6. **Acceso:** primer ingreso, olvidé contraseña o privacy gate.

---

## 21) Incidencias frecuentes

| Consulta | Acción |
|----------|--------|
| No veo pago acreditado | Ventana 72 h; pedir comprobante; chequear última importación de cuotas |
| No puedo pedir talón | Solicitud activa pendiente |
| No puedo cambiar email | Solicitud pendiente en esa posición |
| No aparece botón firmar (regular) | Falta cuota de noviembre pagada, alumno inactivo, colegio sin `contrato_activo`, o curso en reubicación |
| No aparece botón firmar (ingresante) | Falta CUOTA-10 Adelanto RV 2027 pagada; verificar curso EX* |
| Dice que firma en febrero | Curso de egreso/pasaje; reubicar el código de curso |
| Ingresante ve muchas cuotas | Revisar importación: solo debe liquidarse la cuota 10 |
| App en mantenimiento | Superadmin: desactivar modo mantenimiento en Configuración |
| Pide aceptar política otra vez | Cambió la versión vigente; debe volver a aceptar |
| Error al firmar contrato | Escalar a soporte técnico (PDF / servidor) |
| SHA del PDF no coincide | Puede ser PDF regenerado o código impreso vs archivo final |
| Modal de pagos no abre | Escalar a soporte técnico |
| Secretaría no ve otra escuela | Es el diseño: cada usuario tiene una escuela asignada |

---

## 22) Alcance

Esta guía cubre operación funcional. Para código, endpoints y estructura de archivos:

- `docs/guia-tecnica-devs.md`
- `docs/frontend-backend-mapa.md`

---

*Última actualización: octubre 2026 — panel admin unificado, cartas documento, privacy gate, reubicación y alta de ingresantes.*
