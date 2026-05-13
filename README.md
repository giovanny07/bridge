# Bridge — GLPI 11 Plugin

Plugin para **GLPI 11** que permite explorar y migrar datos ITSM/ITAM desde plataformas externas (actualmente SolarWinds Service Desk / Samanage) hacia GLPI.

---

## Estado actual — v0.1.0

| Paso | Estado |
|------|--------|
| Plugin base (setup, hook, PSR-4) | ✅ |
| Pestaña de configuración en GLPI | ✅ |
| Gestión de conexiones (CRUD + cifrado de secretos) | ✅ |
| Test de conexión (inline, sin recarga) | ✅ |
| Scan de incidentes (descubrimiento, solo lectura) | ✅ |
| Normalización de campos (estado, prioridad, origen, fechas) | ✅ |
| Mapping configurable por conexión | 🔄 próximo |
| Migración dry-run | ⏳ |
| Migración real controlada | ⏳ |
| Soporte de requerimientos (service requests) | ⏳ |

---

## Requisitos

| | |
|---|---|
| GLPI | 11.0.0 – 11.0.99 |
| PHP | ≥ 8.1 |
| Extensión PHP | `curl` (con fallback a streams) |

---

## Instalación

```bash
# Clonar dentro del directorio de plugins de GLPI
cd /var/lib/glpi/plugins
git clone git@github.com:giovanny07/bridge.git bridge
cd bridge
composer install --no-dev
```

Luego en GLPI: **Configuración → Plugins → Bridge → Instalar → Activar**.

---

## Estructura

```
bridge/
├── setup.php                  # Declaración del plugin, hooks de inicialización
├── hook.php                   # Instalación / desinstalación de tablas
├── ajax/
│   └── test_connection.php    # Endpoint AJAX para probar conectividad
├── front/
│   ├── config.php             # Redirect al tab de configuración
│   ├── config.form.php        # Handler POST (add / update / purge de conexiones)
│   └── scan.php               # Página de resultados del scan
├── src/
│   ├── Config.php             # Tab Bridge en Configuración General de GLPI
│   ├── Connection.php         # Modelo de conexión (CommonDBTM)
│   ├── Connector/
│   │   └── SolarWindsClient.php   # Cliente HTTP para la API Samanage/SolarWinds
│   ├── Normalizer/
│   │   └── SamanageNormalizer.php # Mapeo de campos Samanage → GLPI
│   └── Page/
│       └── ConfigPage.php     # UI de la pestaña (lista + formulario)
├── locales/                   # Traducciones: en_GB, es_ES, pt_BR
├── tools/
│   └── compile-mo.php         # Compila .po → .mo sin necesitar msgfmt
└── tests/
    ├── bootstrap.php          # Stubs de GLPI para tests sin instancia real
    ├── units/                 # Tests unitarios (sin red)
    │   ├── ConfigTest.php
    │   ├── ConnectionTest.php
    │   ├── SamanageNormalizerTest.php
    │   └── SolarWindsClientTest.php
    └── api/                   # Tests de contrato contra la API real
        └── SolarWindsApiContractTest.php
```

---

## Configuración de una conexión

1. Ir a **Configuración → Configuración General → pestaña Bridge**.
2. Completar el formulario: nombre, URL base, tipo de autenticación y token.
3. Usar el botón **🔌** (Test) para verificar conectividad sin salir de la página.
4. Usar el botón **📡** (Scan) para ver los primeros incidentes en formato JSON.

### Nota sobre la autenticación SolarWinds

La API Samanage requiere el header `X-Samanage-Authorization: Bearer <token>`, **no** el estándar `Authorization: Bearer`. El plugin lo gestiona automáticamente al seleccionar el tipo *Bearer token*.

---

## API SolarWinds — contexto descubierto

Endpoints disponibles en la instancia de referencia:

| Endpoint | Registros aprox. |
|----------|-----------------|
| `/incidents.json` | 187 500+ |
| `/changes.json` | 4 500+ |
| `/users.json` | 1 550 |
| `/problems.json` | 82 |
| `/groups.json` | 377 |
| `/sites.json` | 363 |
| `/departments.json` | 15 |

Filtros soportados: `per_page`, `page`, `state`, `created_after`, `updated_after`, `sort_by`, `sort_order`.  
Total de registros disponible en el header de respuesta `X-Total-Count`.

### Mapeo de campos implementado

| Campo Samanage | Campo GLPI |
|---|---|
| `state` → `Pending Assignment` | status = 1 (New) |
| `state` → `En Proceso` | status = 2 (Assigned) |
| `state` → `Pendiente Acción Cliente` | status = 4 (Pending) |
| `state` → `Solucionado` | status = 5 (Solved) |
| `state` → `Closed` | status = 6 (Closed) |
| `priority` → `Low / Medium / High / Critical` | priority = 2 / 3 / 4 / 5 |
| `origin` → `web / api / external / email` | requesttypes_id = 1 / 6 / 6 / 7 |
| `created_at` (ISO 8601 + offset) | `date` (UTC MySQL datetime) |

---

## Tests

```bash
# Tests unitarios (sin red, sin GLPI)
composer test

# Tests de contrato contra la API real
BRIDGE_API_URL=https://your-instance.example.com \
BRIDGE_API_TOKEN='your-plain-token' \
composer test:api
```

---

## Traducciones

Los archivos `.po` están en `locales/`. Para recompilar los `.mo`:

```bash
php tools/compile-mo.php
```

Idiomas incluidos: `en_GB`, `es_ES`, `pt_BR`.

---

## Licencia

GPLv3+. Ver [LICENSE](LICENSE).
