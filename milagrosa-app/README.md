# milagrosa-app

Copia de `natta-app` adaptada para **Instituto Jardin de Infantes La Milagrosa**, con multi-tenant por base de datos (una carpeta de código / una MySQL por institución).

## Tenant

Toda la config específica vive en `config/tenant.php`:

| Clave | Valor |
|---|---|
| DB | `u207063327_LaMilagrosa` / user `u207063327_Elias3` |
| Cursos | `SCI`, `SRI`, `SVI`, `NUI` |
| Salas (últimas 2 letras) | `CI`, `RI`, `VI`, `UI` |
| Alta alumnos nuevos | curso `NUI` |
| URL | `/milagrosa-app/` |

Convención (igual que Natta): la sala se infiere con las **últimas 2 letras** del curso (`SCI` → `CI`, etc.).

## Acceso

- Familias: `https://complejonatta.com/milagrosa-app/index.php`
- Admin: `https://complejonatta.com/milagrosa-app/admin/admin_dashboard.php`

## Notas

- Los datos de Natta y Milagrosa no se mezclan: cada app apunta a su MySQL.
- Logos de salas: opcionalmente colocar `assets/img/CI.jpg`, `RI.jpg`, `VI.jpg`, `UI.jpg`.
- Textos legales de contratos / domicilio / logo institucional se pueden completar después en `tenant.php` y plantillas.
