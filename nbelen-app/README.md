# nbelen-app

Copia de `natta-app` / `milagrosa-app` adaptada para **Instituto Nuestra Señora de Itati**, con multi-tenant por base de datos (una carpeta de código / una MySQL por institución).

## Tenant

Toda la config específica vive en `config/tenant.php`:

| Clave | Valor |
|---|---|
| DB | `u207063327_NBelen` / user `u207063327_Elias4` |
| Niveles | `JB` Jardín, `PB` Primaria, `SB` Secundaria |
| Cursos Jardín | `1AJ`, `1BJ`, `2AJ`, `2BJ`, `3AJ`, `3BJ` (+ `NAN` ingresos nuevos) |
| Cursos Primaria | `1AP`…`6BP` |
| Cursos Secundaria | `1AS`, `2AS`, `2BS`, `3AS`, `3BS`, `4AS`, `5AS`, `6AS` |
| Alta alumnos nuevos | curso `NAN` |
| URL | `/nbelen-app/` |

Convención: la escuela se resuelve por el **catálogo de cursos** (y letra final `J`/`P`/`S`), no solo con las últimas 2 letras del código (`1AJ` → `AJ` rompería el agrupamiento).

## Acceso

- Familias: `https://complejonatta.com/nbelen-app/index.php`
- Admin: `https://complejonatta.com/nbelen-app/admin/admin_dashboard.php`

## Pendiente para poner en marcha

1. En la DB: cargar `contratos_instituciones` con códigos `JB`, `PB` y `SB`.
2. Opcional: logos de nivel `JB.jpg`, `PB.jpg`, `SB.jpg` en `assets/img/`.
3. Completar teléfonos/emails/dirección en `instituciones.php`.
4. Plantillas de contrato HTML/PDF en `docs/contratos/` (`Contrato_JB_2027_v1`, etc.).

## Notas

- Los datos de Natta, Milagrosa y Nuestra Señora de Itati no se mezclan: cada app apunta a su MySQL.
- `NAN` es el curso interno para el formulario de alta de alumnos nuevos (equivalente a `NUI` en Milagrosa).
