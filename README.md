# Bridge — GLPI 11 Plugin

Plugin para **GLPI 11** que permite explorar y migrar datos ITSM desde plataformas externas hacia GLPI. Actualmente soporta **SolarWinds Service Desk (Samanage)**.

---

## Estado — v0.1.0

| Paso | Estado |
|------|--------|
| Plugin base (setup, hook, PSR-4) | ✅ |
| Pestaña de configuración en GLPI | ✅ |
| Gestión de conexiones (CRUD + cifrado GLPIKey) | ✅ |
| Test de conexión inline | ✅ |
| Scan de incidentes (descubrimiento, solo lectura) | ✅ |
| Resolver de entidades / categorías / grupos / usuarios por nombre | ✅ |
| Dry-run con selector de tipo de recurso | ✅ |
| Motor de migración — tickets + followups + solución + adjuntos | ✅ |
| Historial de migración con purge/retry | ✅ |
| Migración de requerimientos (service requests) | ⏳ |
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
    ├── units/                       # Tests unitarios (sin red)
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

Clic en **🔵** (Migrate) → formulario con:

| Campo | Descripción |
|---|---|
| Tipo de recurso | Incidents ✅ · Changes ❌ · Problems ❌ |
| Estado | Filtra por estado en SolarWinds |
| Created after | Fecha mínima de creación |
| Updated after | Útil para sincronización incremental |
| **Start from page** | La API devuelve los más recientes primero. Usa página 200 para tickets de abril 2026, página 1870 para abril 2024 |
| Límite | Máx tickets por ejecución (1–500) |
| Comentarios | Comentarios → ITILFollowup |
| Adjuntos | Descarga archivos → Document + Document_Item |

Lo que se crea por ticket:
1. **Ticket** con entidad, categoría, asignado y solicitante resueltos por nombre
2. **ITILFollowup** por cada comentario intermedio
3. **ITILSolution** si el ticket tiene `resolution_description` o `resolution_code`
4. **Document** por cada adjunto descargado (PNG, PDF, etc.)

### Historial

Clic en **⚪** (History) → tabla filtrable con:
- Status: success / failed / skipped
- Link directo al ticket GLPI creado
- **Retry failed**: purga los fallidos para re-procesarlos
- **Purge all**: reset completo para re-migrar desde cero

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
- Página ~200 → abril 2026 (tickets manuales con resolution_description)
- Página ~1870 → abril 2024

### Mapeo de campos

| Campo Samanage | Campo GLPI | Notas |
|---|---|---|
| `state` Pending Assignment | status = 1 (New) | — |
| `state` En Proceso | status = 2 (Assigned) | — |
| `state` Gestión Proveedor | status = 2 (Assigned) | — |
| `state` Pendiente Acción Cliente | status = 4 (Pending) | — |
| `state` Solucionado | status = 5 (Solved) | — |
| `state` Closed | status = 6 (Closed) | — |
| `priority` Low/Medium/High/Critical | priority 2/3/4/5 | — |
| `origin` web/api/external/email | requesttypes_id 1/6/6/7 | — |
| `site.name` | entities_id | Match por nombre (fuzzy, sin acentos) |
| `category.name` + `subcategory.name` | itilcategories_id | Subcategoría tiene prioridad |
| `assignee` (user o group) | _users_id_assign / _groups_id_assign | Match por email o nombre |
| `requester.email` | _users_id_requester | Match por email |
| `created_at` | date | Convertido a timezone del servidor |
| `updated_at` | solvedate / closedate | Solo para estados resueltos/cerrados |
| `resolution_description` | ITILSolution.content | Prioridad 1 |
| `resolution_code` | ITILSolution.content | Prioridad 2 (ej. "Alarma Mitigada") |
| Comment `body` | ITILFollowup.content | Todos excepto el que es solución |
| Comment `attachments` | Document + Document_Item | URLs relativas se completan con baseUrl |

### Deduplicación

Cada registro migrado se guarda en `glpi_plugin_bridge_migrations` con `source_id`. Si se corre la migración dos veces, el segundo pase salta los que ya tienen `status=success`.

---

## Tests

```bash
# Tests unitarios (138 tests, sin red, sin GLPI)
composer test

# Tests de contrato contra la API real (33 tests)
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
