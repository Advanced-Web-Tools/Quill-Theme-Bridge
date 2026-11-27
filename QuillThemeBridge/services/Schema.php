<?php
namespace QuillThemeBridge\services;

use database\creator\{TableWizard, ColumnCreator as C};

final class Schema
{
    public static function install(int $packageId): void
    {
        $wizard = TableWizard::create($packageId);
        if (!$wizard->tableExists('quill_theme_bridge_link')) {
            foreach ([C::VARCHAR('source_key', '190')->primary(), C::INT('theme_key'), C::VARCHAR('theme_page_key', '64'), C::INT('quill_page_id')->index()] as $column) $wizard->addColumn($column);
            if (!$wizard->createTable('quill_theme_bridge_link')) throw new \RuntimeException('Cannot create bridge link table.');
        }
        if ($wizard->tableExists('quill_theme_bridge_recovery')) return;
        foreach ([C::INT('theme_key')->primary(), C::INT('previous_theme_key')->unique(), C::VARCHAR('theme_name', '190')] as $column) $wizard->addColumn($column);
        if (!$wizard->createTable('quill_theme_bridge_recovery')) throw new \RuntimeException('Cannot create bridge recovery table.');
    }
}
