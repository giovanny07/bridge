#!/usr/bin/env bash
# Imports client GLPI reference tables into the local GLPI instance for testing.
# Usage: bash tools/import-test-scenario.sh /tmp/bridge_testdata.sql
#
# Tables replaced: glpi_entities, glpi_itilcategories, glpi_groups,
#                  glpi_users (client users appended), glpi_useremails
# The local GLPI admin user (login=glpi) is preserved.

set -euo pipefail

DUMP="${1:-/tmp/bridge_testdata.sql}"
DB_USER="glpi"
DB_PASS="glpi"
DB_NAME="glpi"
MYSQL="mysql -u${DB_USER} -p${DB_PASS} ${DB_NAME}"

if [ ! -f "$DUMP" ]; then
  echo "ERROR: dump file not found: $DUMP"
  exit 1
fi

echo "=== Bridge test scenario import ==="
echo "Source: $DUMP"
echo ""

# 1. Save local admin user before wiping users table
echo "[1/7] Saving local admin user..."
LOCAL_ADMIN=$($MYSQL -se "SELECT id,name,password,email,authtype,is_active,profiles_id FROM glpi_users WHERE name='glpi' LIMIT 1;" 2>/dev/null | head -1)
LOCAL_ADMIN_EMAIL=$($MYSQL -se "SELECT email FROM glpi_useremails WHERE users_id=2 LIMIT 1;" 2>/dev/null | head -1 || echo "")

# 2. Install bridge plugin tables if missing
echo "[2/7] Ensuring bridge plugin tables exist..."
$MYSQL 2>/dev/null << 'EOF'
CREATE TABLE IF NOT EXISTS `glpi_plugin_bridge_migrations` (
    `id`             int unsigned NOT NULL AUTO_INCREMENT,
    `connections_id` int unsigned NOT NULL DEFAULT 0,
    `source_type`    varchar(64) NOT NULL DEFAULT 'incidents',
    `source_id`      varchar(64) NOT NULL DEFAULT '',
    `source_number`  varchar(64) NOT NULL DEFAULT '',
    `tickets_id`     int unsigned NOT NULL DEFAULT 0,
    `status`         varchar(16) NOT NULL DEFAULT 'success',
    `error_message`  text,
    `migrated_at`    datetime NOT NULL,
    `migrated_by`    int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `connections_id` (`connections_id`),
    KEY `source_lookup`  (`connections_id`, `source_type`, `source_id`),
    KEY `status`         (`status`),
    KEY `migrated_at`    (`migrated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
EOF

# Also add default_groups_id to connections table if missing
$MYSQL 2>/dev/null -e "
ALTER TABLE glpi_plugin_bridge_connections
  ADD COLUMN IF NOT EXISTS default_groups_id int unsigned NOT NULL DEFAULT 0,
  ADD KEY IF NOT EXISTS default_groups_id (default_groups_id);
" || true

# 3. Import dump (disabling FK checks to avoid constraint errors)
echo "[3/7] Importing client tables (entities, categories, groups, users)..."
$MYSQL 2>/dev/null << ENDSQL
SET FOREIGN_KEY_CHECKS = 0;
SET SESSION sql_mode = '';
ENDSQL

# Strip CREATE TABLE IF NOT EXISTS → TRUNCATE + re-insert
# We use the dump as-is but truncate first
$MYSQL 2>/dev/null -e "SET FOREIGN_KEY_CHECKS=0;"

for tbl in glpi_entities glpi_itilcategories glpi_groups glpi_users glpi_useremails; do
  echo "  Truncating $tbl..."
  $MYSQL 2>/dev/null -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE $tbl;"
done

echo "  Importing dump..."
$MYSQL 2>/dev/null -e "SET FOREIGN_KEY_CHECKS=0;" && \
  grep -v "^/\*" "$DUMP" | \
  sed 's/^CREATE TABLE IF NOT EXISTS/CREATE TABLE IF NOT EXISTS -- skipped/g' | \
  $MYSQL 2>/dev/null --force || true

$MYSQL 2>/dev/null -e "SET FOREIGN_KEY_CHECKS=1;"

# 4. Restore local admin user
echo "[4/7] Restoring local admin user..."
if [ -n "$LOCAL_ADMIN" ]; then
  IFS=$'\t' read -r uid name password email authtype is_active profiles_id <<< "$LOCAL_ADMIN"
  $MYSQL 2>/dev/null -e "
    INSERT IGNORE INTO glpi_users (id,name,password,email,authtype,is_active,profiles_id,is_deleted)
    VALUES ($uid,'$name','$password','$email',$authtype,$is_active,$profiles_id,0)
    ON DUPLICATE KEY UPDATE password='$password', is_active=1, is_deleted=0;
  "
  if [ -n "$LOCAL_ADMIN_EMAIL" ]; then
    $MYSQL 2>/dev/null -e "
      INSERT IGNORE INTO glpi_useremails (users_id, email, is_default)
      VALUES ($uid,'$LOCAL_ADMIN_EMAIL',1)
      ON DUPLICATE KEY UPDATE email='$LOCAL_ADMIN_EMAIL';
    "
  fi
fi

# 5. Create / update DaycoHost connection
echo "[5/7] Creating DaycoHost test connection..."
$MYSQL 2>/dev/null -e "
INSERT INTO glpi_plugin_bridge_connections
  (name, system_type, base_url, auth_type, entities_id, default_groups_id, is_active)
VALUES
  ('DaycoHost (Test)', 'solarwinds', 'https://servicios.daycohost.com', 'bearer', 0, 0, 1)
ON DUPLICATE KEY UPDATE name=name;
" || true

# 6. Verify
echo "[6/7] Verifying import..."
$MYSQL 2>/dev/null -e "
SELECT 'entities'        AS tbl, COUNT(*) AS rows FROM glpi_entities
UNION ALL
SELECT 'itilcategories', COUNT(*) FROM glpi_itilcategories
UNION ALL
SELECT 'groups',         COUNT(*) FROM glpi_groups
UNION ALL
SELECT 'users',          COUNT(*) FROM glpi_users
UNION ALL
SELECT 'useremails',     COUNT(*) FROM glpi_useremails
UNION ALL
SELECT 'bridge_connections', COUNT(*) FROM glpi_plugin_bridge_connections
UNION ALL
SELECT 'bridge_migrations',  COUNT(*) FROM glpi_plugin_bridge_migrations;
"

echo ""
echo "[7/7] Done! Test scenario ready."
echo "      → Log in to GLPI and configure the bearer token on the DaycoHost connection."
echo "      → Then run the dry-run to verify the mapping."
