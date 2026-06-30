<?php

namespace GlpiPlugin\Bridge;

use CommonGLPI;
use DbUtils;
use Html;
use ProfileRight;
use Session;

class Profile extends CommonGLPI
{
    public static $rightname = 'profile';

    public const RIGHT_CONFIG    = 'plugin_bridge_config';
    public const RIGHT_MIGRATION = 'plugin_bridge_migration';

    public static function getTypeName($nb = 0): string
    {
        return __('Bridge', 'bridge');
    }

    public static function getIcon(): string
    {
        return 'ti ti-arrows-transfer-up';
    }

    public static function canConfigure(): bool
    {
        return Session::haveRight(self::RIGHT_CONFIG, UPDATE)
            || Session::haveRight('config', UPDATE);
    }

    public static function canMigrate(int $right = UPDATE): bool
    {
        return Session::haveRight(self::RIGHT_MIGRATION, $right)
            || Session::haveRight('config', UPDATE);
    }

    public static function checkConfigure(): void
    {
        if (!self::canConfigure()) {
            Session::checkRight(self::RIGHT_CONFIG, UPDATE);
        }
    }

    public static function checkMigrate(int $right = UPDATE): void
    {
        if (!self::canMigrate($right)) {
            Session::checkRight(self::RIGHT_MIGRATION, $right);
        }
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item->getType() === 'Profile') {
            return self::createTabEntry(self::getTypeName(), 0, $item->getType(), self::getIcon());
        }

        return '';
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ): bool {
        if ($item->getType() === 'Profile') {
            $profilesId = (int) $item->getID();
            self::addDefaultProfileInfos($profilesId, self::defaultRights());
            (new self())->showForm($profilesId);
        }

        return true;
    }

    public static function getAllRights(bool $all = false): array
    {
        return [
            [
                'itemtype' => Config::class,
                'label'    => __('Bridge configuration', 'bridge'),
                'field'    => self::RIGHT_CONFIG,
                'rights'   => [
                    READ   => __('Read'),
                    UPDATE => __('Update'),
                ],
            ],
            [
                'itemtype' => Config::class,
                'label'    => __('Bridge migrations', 'bridge'),
                'field'    => self::RIGHT_MIGRATION,
                'rights'   => [
                    READ   => __('Read'),
                    CREATE => __('Create'),
                    UPDATE => __('Update'),
                    PURGE  => __('Purge'),
                ],
            ],
        ];
    }

    public static function initProfile(): void
    {
        global $DB;

        $dbu = new DbUtils();
        foreach (self::getAllRights(true) as $right) {
            if ($dbu->countElementsInTable('glpi_profilerights', ['name' => $right['field']]) === 0) {
                ProfileRight::addProfileRights([$right['field']]);
            }
        }

        if (!isset($_SESSION['glpiactiveprofile']['id'])) {
            return;
        }

        foreach ($DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => [
                'profiles_id' => (int) $_SESSION['glpiactiveprofile']['id'],
                'name'        => array_column(self::getAllRights(true), 'field'),
            ],
        ]) as $right) {
            $_SESSION['glpiactiveprofile'][$right['name']] = (int) $right['rights'];
        }
    }

    public static function createFirstAccess(int $profilesId): void
    {
        self::addDefaultProfileInfos($profilesId, [
            self::RIGHT_CONFIG    => READ | UPDATE,
            self::RIGHT_MIGRATION => READ | CREATE | UPDATE | PURGE,
        ], true);
    }

    public static function removeRights(): void
    {
        ProfileRight::deleteProfileRights(array_column(self::getAllRights(true), 'field'));
        self::removeRightsFromSession();
    }

    public static function removeRightsFromSession(): void
    {
        foreach (self::getAllRights(true) as $right) {
            unset($_SESSION['glpiactiveprofile'][$right['field']]);
        }
    }

    public static function addDefaultProfileInfos(int $profilesId, array $rights, bool $dropExisting = false): void
    {
        if ($profilesId <= 0) {
            return;
        }

        $dbu = new DbUtils();
        $profileRight = new ProfileRight();

        foreach ($rights as $right => $value) {
            $criteria = ['profiles_id' => $profilesId, 'name' => $right];

            if ($dropExisting && $dbu->countElementsInTable('glpi_profilerights', $criteria) > 0) {
                $profileRight->deleteByCriteria($criteria);
            }

            if ($dbu->countElementsInTable('glpi_profilerights', $criteria) === 0) {
                $profileRight->add([
                    'profiles_id' => $profilesId,
                    'name'        => $right,
                    'rights'      => $value,
                ]);
            }

            if (isset($_SESSION['glpiactiveprofile']['id']) && (int) $_SESSION['glpiactiveprofile']['id'] === $profilesId) {
                $_SESSION['glpiactiveprofile'][$right] = $value;
            }
        }
    }

    public function showForm(int $profilesId = 0, bool $openform = true, bool $closeform = true): void
    {
        echo '<div class="firstbloc">';

        $canEdit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);
        if ($canEdit && $openform) {
            $profile = new \Profile();
            echo '<form method="post" action="' . $profile->getFormURL() . '">';
        }

        $profile = new \Profile();
        $profile->getFromDB($profilesId);
        if ($profile->getField('interface') === 'central') {
            $profile->displayRightsChoiceMatrix(self::getAllRights(), [
                'canedit'       => $canEdit,
                'default_class' => 'tab_bg_2',
                'title'         => __('Bridge', 'bridge'),
            ]);
        }

        if ($canEdit && $closeform) {
            echo '<div class="center">';
            echo Html::hidden('id', ['value' => $profilesId]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
            Html::closeForm();
        }

        echo '</div>';
    }

    private static function defaultRights(): array
    {
        return [
            self::RIGHT_CONFIG    => 0,
            self::RIGHT_MIGRATION => 0,
        ];
    }
}
