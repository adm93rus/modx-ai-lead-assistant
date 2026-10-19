<?php

/** @var modX $modx */

$code = file_get_contents(dirname(__DIR__, 2) . '/core/components/aiassistant/elements/plugins/aiassistant.plugin.php');
$code = is_string($code) ? preg_replace('/^<\?php\s*/', '', $code, 1) : '';

$plugin = $modx->newObject('modPlugin');
$plugin->fromArray(array(
    'name' => 'aiAssistant',
    'description' => 'Встраивает AI-ассистента в публичные страницы',
    'plugincode' => $code,
    'disabled' => 0,
), '', true, true);

$event = $modx->newObject('modPluginEvent');
$event->fromArray(array(
    'event' => 'OnWebPagePrerender',
    'priority' => 0,
    'propertyset' => 0,
), '', true, true);
$plugin->addMany(array($event));

return array($plugin);
