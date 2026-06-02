# Bridge — GLPI 11 Plugin

Plugin para **GLPI 11** que permite explorar y migrar datos ITSM desde plataformas externas hacia GLPI. Actualmente soporta **SolarWinds Service Desk (Samanage)**.

---

## Estado — v0.6.0

| Paso | Estado |
|------|--------|
| Plugin base (setup, hook, PSR-4) | ✅ |
| Pestaña de configuración en GLPI | ✅ |
| Gestión de conexiones (CRUD + cifrado GLPIKey) | ✅ |
| Botones Editar/Borrar en lista de conexiones | ✅ |
| Test de conexión inline | ✅ |
| Scan de incidentes (descubrimiento, solo lectura) | ✅ |
| Resolver de entidades / categorías / grupos / usuarios por nombre | ✅ |
| Resolver: sufijo parentético, `C. A.`, sin puntos, comillas | ✅ |
| Dry-run con selector de tipo de recurso | ✅ |
| Motor de migración — Incidents → Ticket (followups + solución + adjuntos) | ✅ |
| Motor de migración — Problems → ITILProblem (cause + symptoms + workaround) | ✅ |
| Migración por número de ticket (#194943) o ID interno | ✅ |
| Prefijo `[ SD #num ]` en el título | ✅ |
| Trazabilidad de comentarios públicos/privados (`is_private`) | ✅ |
| Solicitantes externos como `alternative_email` | ✅ |
| Imágenes inline reemplazadas por URLs de GLPI | ✅ |
| Tipo de ticket: Incident vs Service Request | ✅ |
| Sincronización de usuarios desde SolarWinds | ✅ |
| Historial: checkboxes, purge selected, status parcial, búsqueda + paginación | ✅ |
| Migración de Changes | ⏳ |

---

## Requisitos

| | |
|---|---|
| GLPI | 11.0.0 – 11.0.99 |
| PHP | ≥ 8.1 |
| Extensión PHP | `curl` (fallback a streams si no está disponible) |

---

## Instalación

```bash
cd /var/lib/glpi/plugins
git clone git@github.com:giovanny07/bridge.git bridge
cd bridge
composer install --no-dev
```

En GLPI: **Configuración → Plugins → Bridge → Instalar → Activar**.

> La instalación crea dos tablas:
> - `glpi_plugin_bridge_connections` — conexiones a sistemas externos
> - `glpi_plugin_bridge_migrations` — log de auditoría de cada migración

---

## Estructura del código

```
bridge/
├── setup.php                        # Declaración del plugin, hooks GLPI
├── hook.php                         # install/uninstall de tablas
├── ajax/
│   └── test_connection.php          # AJAX: verifica conectividad
├── front/
│   ├── config.php                   # Redirect al tab Bridge
│   ├── config.form.php              # POST handler conexiones (add/update/purge)
│   ├── scan.php                     # Scan de descubrimiento (solo lectura)
│   ├── dryrun.php                   # Simulación con selector de recurso
│   ├── migrate.php                  # Motor de migración
│   └── migration_history.php        # Historial + purge + retry
├── src/
│   ├── Config.php                   # Tab Bridge en Configuración General
│   ├── Connection.php               # Modelo CommonDBTM de conexiones
│   ├── Contract/
│   │   ├── ConnectorInterface.php   # Contrato: todo conector debe implementarlo
│   │   └── NormalizerInterface.php  # Contrato: mapeo de campos por sistema
│   ├── Connector/
│   │   ├── ConnectorFactory.php     # Crea conector/normalizador según system_type
│   │   └── SolarWinds/
│   │       ├── SolarWindsClient.php # Cliente HTTP Samanage (implements ConnectorInterface)
│   │       └── SamanageNormalizer.php # Mapeo de campos (implements NormalizerInterface)
│   ├── Resolver/
│   │   └── GlpiResolver.php         # Busca entidades/categorías/grupos/usuarios por nombre
│   ├── Migration/
│   │   ├── IncidentMapper.php       # Combina normalizador + resolver → ticket GLPI
│   │   ├── MappedIncident.php       # Value object: ticket + followups + solution + warnings
│   │   ├── MigrationEngine.php      # Orquesta el proceso completo con deduplicación
│   │   ├── MigrationRecord.php      # Log de auditoría (CommonDBTM)
│   │   └── MigrationResult.php      # Resultado: creados / fallidos / saltados
│   └── Page/
│       ├── ConfigPage.php           # UI: lista de conexiones + formulario
│       ├── DryRunPage.php           # UI: selector de recurso + tabla de resolución
│       ├── MigratePage.php          # UI: formulario de migración + resultados
│       └── HistoryPage.php          # UI: historial + acciones de purge
├── locales/                         # Traducciones: en_GB, es_ES, pt_BR
├── tools/
│   ├── compile-mo.php               # Compila .po → .mo sin msgfmt
│   └── import-test-scenario.sh      # Importa datos del cliente a entorno local
└── tests/
    ├── bootstrap.php                # Stubs GLPI para tests sin instancia real
    ├── units/                       # Tests unitarios (sin red, sin GLPI)
    └── api/                         # Tests de contrato contra la API real
        └── SolarWindsApiContractTest.php
```

---

## Flujo de trabajo

### Configurar una conexión

1. **Configuración → Configuración General → pestaña Bridge**
2. Completar: nombre, URL base, tipo de auth, token/secreto
3. Entidad fallback (cuando un site no se resuelve por nombre)
4. Grupo fallback (cuando el asignado no se encuentra)
5. Clic en **🔌** (Test) — verifica auth + conectividad en <1 segundo
6. Clic en **📡** (Scan) — muestra JSON raw de los primeros incidentes

### Dry-run (previsualización)

Clic en **🟡** → elige tipo de recurso → tabla de 20 incidentes con:
- ID GLPI de entidad, categoría, grupo y solicitante resueltos
- Warnings de los que no se encontraron por nombre
- Conteo de comentarios (sin fetchearlos para ser rápido)

### Migración real

Clic en **🔵** (Migrate) → formulario con dos modos:

#### Modo: Por filtros / paginación

| Campo | Descripción |
|---|---|
| Tipo de recurso | Incidents ✅ · Changes ⏳ · Problems ⏳ |
| Estado | Filtra por estado en SolarWinds |
| Created after | Fecha mínima de creación |
| Updated after | Útil para sincronización incremental |
| **Start from page** | La API devuelve los más recientes primero. Usa ~200 para tickets de abril 2026, ~1870 para abril 2024 |
| Límite | Máx tickets por ejecución (1–500) |

#### Modo: Por IDs específicos

Pega los IDs de SolarWinds separados por coma (`181695325, 181695326`). Ignora filtros y paginación. Útil para migrar tickets concretos o validar la migración antes de un lote masivo.

#### Opciones de contenido (ambos modos)

| Opción | Descripción |
|---|---|
| Default requester | Usuario GLPI usado cuando el origen no tiene email de solicitante |
| Comments → Followups | Crea ITILFollowup por cada comentario |
| Attachments → Documents | Descarga archivos y los vincula como Document |
| Preserve private flag | Mantiene `is_private` de los comentarios (por defecto todos quedan públicos) |

#### Lo que se crea por ticket

1. **Ticket** con entidad, categoría, asignado y solicitante resueltos por nombre/email
2. **ITILFollowup** por cada comentario (ordenados por `date_creation` = fecha original de SolarWinds)
3. **ITILSolution** con el contenido de `resolution_description`, `resolution_code`, o el nombre del estado si no hay texto explícito
4. **Document** por cada adjunto descargado y vinculado al followup y al ticket

### Historial

Clic en **⚪** (History) → tabla filtrable con:
- Status: success / failed / skipped
- Link directo al ticket GLPI creado
- **Retry failed**: purga los fallidos para re-procesarlos
- **Purge all**: reset completo para re-migrar desde cero

---

## Resolución de actores

### Assignee
1. Si es **usuario** (`assignee.is_user = true`): busca por `email` en `glpi_useremails`
2. Si es **grupo** (`assignee.is_user = false`): busca por nombre en `glpi_groups` (match fuzzy sin acentos)
3. Si no se resuelve: usa el **grupo fallback** configurado en la conexión

### Requester (solicitante)
| Situación | Resultado en GLPI |
|---|---|
| Email encontrado en GLPI | Usuario vinculado normalmente |
| Email existe pero no tiene cuenta GLPI | Se guarda como `alternative_email` — visible en el ticket |
| Sin email en origen + fallback configurado | Usuario fallback del formulario de migración |
| Sin email y sin fallback | Campo vacío |

---

## Comportamiento del timeline en GLPI

- **Orden**: followups y solución se ordenan por `date_creation` = fecha original de SolarWinds, no por la fecha en que se ejecutó la migración
- **Solución**: tickets cerrados sin `resolution_description` ni `resolution_code` reciben una solución mínima con el nombre del estado (`"Closed"`, `"Solucionado"`) para mantener la integridad del timeline
- **Comentarios privados**: por defecto todos los followups migrados son públicos; activar "Preserve private flag" para respetar el `is_private` original

---

## Nota sobre autenticación SolarWinds

La API Samanage usa `X-Samanage-Authorization: Bearer <token>`, **no** el estándar `Authorization: Bearer` (devuelve 401). El plugin gestiona esto automáticamente.

---

## Añadir un nuevo sistema fuente

1. Implementar `ConnectorInterface` en `src/Connector/{Sistema}/{Sistema}Client.php`
2. Implementar `NormalizerInterface` en `src/Connector/{Sistema}/{Sistema}Normalizer.php`
3. Añadir una entrada en `ConnectorFactory::make()` y `makeNormalizer()`
4. Registrar en `Connection::getSupportedSystems()`

El resto del plugin (resolver, motor, UI, historial) funciona sin cambios.

---

## API SolarWinds — contexto (servicios.daycohost.com)

### Endpoints disponibles

| Endpoint | Registros | Notas |
|----------|-----------|-------|
| `/incidents.json` | 187 500+ | Recurso principal |
| `/changes.json` | 4 500+ | — |
| `/users.json` | 1 550 | — |
| `/problems.json` | 82 | — |
| `/groups.json` | 377 | — |
| `/sites.json` | 363 | Se mapean a entidades GLPI |
| `/departments.json` | 15 | — |
| `/hardware.json` | ❌ 404 | No disponible |

### Paginación

La API siempre devuelve los registros más recientes primero e **ignora** `sort_order=asc`. Para acceder a tickets históricos usar `page=N`:
- Página 1 → mayo 2026 (más recientes)
- Página ~200 → abril 2026 (tickets manuales con `resolution_description`)
- Página ~1870 → abril 2024

### Mapeo de campos

| Campo Samanage | Campo GLPI | Notas |
|---|---|---|
| `number` + `name` | name | Formato `[ SD #<number> ] <name>` para trazabilidad |
| `is_service_request` | type | `false` → 1 (Incident) · `true` → 2 (Service Request) |
| `state` Pending Assignment | status = 1 (New) | — |
| `state` En Proceso | status = 2 (Assigned) | — |
| `state` Gestión Proveedor | status = 2 (Assigned) | — |
| `state` Pendiente Acción Cliente | status = 4 (Pending) | — |
| `state` Solucionado | status = 5 (Solved) | — |
| `state` Closed | status = 6 (Closed) | — |
| `priority` Low/Medium/High/Critical | priority 2/3/4/5 | — |
| `origin` web/api/external/email | requesttypes_id 1/6/6/7 | — |
| `site.name` | entities_id | Match fuzzy sin acentos; strips sufijo `(Alias)` |
| `category.name` + `subcategory.name` | itilcategories_id | Subcategoría tiene prioridad |
| `assignee` (user o group) | _users_id_assign / _groups_id_assign | Match por email o nombre |
| `requester.email` | _users_id_requester / alternative_email | Ver tabla de resolución de actores |
| `created_at` | date + date_creation | Convertido a timezone del servidor |
| `updated_at` | solvedate / closedate | Solo para estados resueltos/cerrados |
| `resolution_description` | ITILSolution.content | Prioridad 1 |
| `resolution_code` | ITILSolution.content | Prioridad 2 (ej. "Alarma Mitigada") |
| `state` (sin resolución) | ITILSolution.content | Prioridad 3 — mínima para consistencia |
| Comment `body` | ITILFollowup.content | Comentarios anteriores a la solución |
| Comment `is_private` | ITILFollowup.is_private | Respetado cuando "Preserve private flag" está activo (default: sí) |
| Comment `inline_attachments` | Document + reemplazo de `<img src>` | Imagen descargada; src reemplazado con URL de GLPI |
| Comment `attachments` | Document + Document_Item | URLs relativas se completan con baseUrl; filenames URL-decoded |

### Mapeo de campos — Problems

| Campo Samanage | Campo GLPI | Notas |
|---|---|---|
| `number` + `name` | name | Formato `[ SD #<number> ] <name>` |
| `description_no_html` | content | — |
| `root_cause` | causecontent | — |
| `symptoms` | symptomcontent | — |
| `workaround` | ITILFollowup privado | No existe campo directo en GLPI |
| `state` / `priority` / fechas | igual que Incidents | — |
| Actores | `glpi_problems_users` / `glpi_groups_problems` | Misma lógica que Tickets |

### Deduplicación

Cada registro migrado se guarda en `glpi_plugin_bridge_migrations` con `source_id`. Si se corre la migración dos veces, el segundo pase salta los que ya tienen `status=success`.

---

## Tests

```bash
# Tests unitarios (153 tests, sin red, sin GLPI)
composer test

# Tests de contrato contra la API real
BRIDGE_API_URL=https://servicios.daycohost.com \
BRIDGE_API_TOKEN='<plain_token>' \
composer test:api
```

### Escenario de prueba local

Para importar la estructura de entidades/categorías/grupos del cliente al GLPI local:

```bash
# 1. Exportar del servidor del cliente
sshpass -p 'PASS' ssh -p 1122 user@SERVER \
  "echo 'PASS' | sudo -S mysqldump glpi \
    --no-tablespaces --skip-add-drop-table --single-transaction \
    glpi_entities glpi_itilcategories glpi_groups glpi_users glpi_useremails \
    2>/dev/null" > /tmp/bridge_testdata.sql

# 2. Importar al local
bash tools/import-test-scenario.sh /tmp/bridge_testdata.sql
```

---

## Traducciones

Idiomas: `en_GB`, `es_ES`, `pt_BR`. Para recompilar `.mo`:

```bash
php tools/compile-mo.php
```

---

## Licencia

GPLv3+. Ver [LICENSE](LICENSE).
