<?php
/**
 * PHPUnit bootstrap for Bridge plugin tests.
 *
 * Runs outside of the GLPI request cycle. GLPI core classes are stubbed so
 * that plugin classes can be loaded and their pure-PHP logic tested without a
 * live GLPI installation. Set the GLPI_ROOT env var to point to a real GLPI
 * installation if you need integration-level coverage.
 */

// ---- GLPI core stubs -------------------------------------------------- //

if (!class_exists('CommonDBTM')) {
    class CommonDBTM
    {
        public static $rightname = '';
        public array $fields = [];

        public static function getTypeName(int $nb = 0): string { return ''; }

        public static function getTable(): string
        {
            $short = substr(static::class, strrpos(static::class, '\\') + 1);
            $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
            return 'glpi_plugin_bridge_' . $snake . 's';
        }

        public static function canView(): bool   { return true; }
        public static function canCreate(): bool { return true; }
        public static function canUpdate(): bool { return true; }
        public static function canDelete(): bool { return true; }
        public static function canPurge(): bool  { return true; }

        public function add(array $input): int|false { return 1; }
        public function update(array $input): bool   { return true; }
        public function getFromDB(int $id): bool      { return false; }
    }
}

if (!class_exists('CommonGLPI')) {
    class CommonGLPI
    {
        public array $fields = [];
        public static function getTypeName(int $nb = 0): string { return ''; }
        public function getType(): string { return static::class; }
        protected static function createTabEntry(string $name, int $nb = 0, ?string $form_itemtype = null, string $icon = ''): string
        {
            return $icon !== '' ? '<i class="' . $icon . '"></i>' . $name : $name;
        }
    }
}

if (!class_exists('Config')) {
    class Config extends CommonGLPI
    {
        public static function canUpdate(): bool { return true; }
        public static function getFormURL(bool $full = true): string { return '/front/config.form.php'; }
    }
}

if (!class_exists('Plugin')) {
    class Plugin
    {
        public static function getWebDir(string $plugin, bool $full = true): string
        {
            return ($full ? 'http://localhost' : '') . '/plugins/' . $plugin;
        }
        public static function registerClass(string $class, array $options = []): void {}
    }
}

if (!class_exists('Session')) {
    class Session
    {
        public static function haveRight(string $right, int $value): bool { return true; }
        public static function addMessageAfterRedirect(string $msg, bool $check = false, int $type = 0): void {}
        public static function getNewCSRFToken(): string { return 'test-token'; }
    }
}

if (!class_exists('GLPIKey')) {
    class GLPIKey
    {
        public function encrypt(string $value): string { return base64_encode($value); }
        public function decrypt(string $value): string { return base64_decode($value); }
    }
}

if (!class_exists('Migration')) {
    class Migration
    {
        public function __construct(string $version) {}
        public function displayMessage(string $msg): void {}
        public function dropTable(string $table): void {}
    }
}

if (!class_exists('DBConnection')) {
    class DBConnection
    {
        public static function getDefaultCharset(): string          { return 'utf8mb4'; }
        public static function getDefaultCollation(): string        { return 'utf8mb4_unicode_ci'; }
        public static function getDefaultPrimaryKeySignOption(): string { return 'unsigned'; }
    }
}

if (!class_exists('Ticket')) {
    class Ticket extends CommonDBTM {}
}

if (!class_exists('Problem')) {
    class Problem extends CommonDBTM {}
}

if (!class_exists('Change')) {
    class Change extends CommonDBTM {}
}

if (!class_exists('ChangeTask')) {
    class ChangeTask extends CommonDBTM
    {
        public static array $addedInputs = [];

        public function add(array $input): int|false
        {
            self::$addedInputs[] = $input;
            return count(self::$addedInputs);
        }
    }
}

if (!class_exists('ChangeValidation')) {
    class ChangeValidation extends CommonDBTM
    {
        public static array $addedInputs = [];

        public function add(array $input): int|false
        {
            self::$addedInputs[] = $input;
            return count(self::$addedInputs);
        }
    }
}

if (!class_exists('ITILFollowup')) {
    class ITILFollowup extends CommonDBTM {}
}

if (!class_exists('ITILSolution')) {
    class ITILSolution extends CommonDBTM {}
}

if (!function_exists('__')) {
    function __(string $str, string $domain = ''): string { return $str; }
}

if (!defined('READ'))   { define('READ',   1); }
if (!defined('UPDATE')) { define('UPDATE', 4); }
if (!defined('CREATE')) { define('CREATE', 2); }
if (!defined('PURGE'))  { define('PURGE',  8); }
if (!defined('INFO'))   { define('INFO',   1); }
if (!defined('ERROR'))  { define('ERROR',  3); }

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(function (string $class): void {
        $prefix = 'GlpiPlugin\\Bridge\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = dirname(__DIR__) . '/src/' . $rel . '.php';
        if (file_exists($file)) {
            require $file;
        }
    });
}
