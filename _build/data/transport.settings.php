<?php

/** @var modX $modx */

$rows = require dirname(__DIR__, 2) . '/core/components/aiassistant/config/settings.php';
$settings = array();

foreach ($rows as $row) {
    $setting = $modx->newObject('modSystemSetting');
    $setting->fromArray(array(
        'key' => $row['key'],
        'value' => $row['value'],
        'xtype' => $row['xtype'],
        'namespace' => 'aiassistant',
        'area' => $row['area'],
    ), '', true, true);
    $settings[] = $setting;
}

return $settings;
