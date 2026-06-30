<?php

namespace GlpiPlugin\Bridge;

use CommonGLPI;
use GlpiPlugin\Bridge\Page\ConfigPage;
use Session;

class Config extends CommonGLPI
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return __('Bridge', 'bridge');
    }

    public static function getIcon(): string
    {
        return 'ti ti-arrows-transfer-up';
    }

    public static function canView(): bool
    {
        return Profile::canConfigure() || Profile::canMigrate(READ);
    }

    public static function canUpdate(): bool
    {
        return Profile::canConfigure();
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item->getType() === 'Config' && self::canView()) {
            return self::createTabEntry(self::getTypeName(), 0, $item->getType(), self::getIcon());
        }

        return '';
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ): bool {
        if ($item->getType() === 'Config' && self::canView()) {
            ConfigPage::show();
        }

        return true;
    }
}
