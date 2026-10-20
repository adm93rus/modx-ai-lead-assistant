<?php

/**
 * Registers the component inside an existing MODX 2.8+ or MODX 3 site.
 *
 * Usage, from the MODX root after the core/ and assets/ directories are in place:
 *   php core/components/aiassistant/install.php
 *   php core/components/aiassistant/install.php --modx=/var/www/site
 *
 * Re-running updates snippets, the plugin, and the rules chunk.
 * Existing system setting values are kept unless --force-settings is passed.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this installer from the command line.\n");
    exit(1);
}

$root = null;
$forceSettings = false;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--modx=') === 0) {
        $root = substr($arg, 7);
    }
    if ($arg === '--force-settings') {
        $forceSettings = true;
    }
}

if ($root === null) {
    $current = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        if (is_file($current . '/config.core.php')) {
            $root = $current;
            break;
        }
        $parent = dirname($current);
        if ($parent === $current) {
            break;
        }
        $current = $parent;
    }
}

if ($root === null || !is_file(rtrim($root, '/\\') . '/config.core.php')) {
    fwrite(STDERR, "config.core.php was not found. Pass --modx=/path/to/modx.\n");
    exit(1);
}

$root = rtrim($root, '/\\');
define('MODX_API_MODE', true);
require_once $root . '/config.core.php';

if (is_file(MODX_CORE_PATH . 'vendor/autoload.php')) {
    require_once MODX_CORE_PATH . 'vendor/autoload.php';
    $modxClass = class_exists('MODX\\Revolution\\modX') ? 'MODX\\Revolution\\modX' : 'modX';
} else {
    require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
    $modxClass = 'modX';
}

$modx = new $modxClass();
$modx->initialize('mgr');
$component = rtrim(MODX_CORE_PATH, '/\\') . '/components/aiassistant';
$settings = require $component . '/config/settings.php';

$namespace = $modx->getObject('modNamespace', 'aiassistant');
if (!$namespace) {
    $namespace = $modx->newObject('modNamespace');
    $namespace->set('name', 'aiassistant');
}
$namespace->set('path', '{core_path}components/aiassistant/');
$namespace->set('assets_path', '{assets_path}components/aiassistant/');
$namespace->save();

$category = $modx->getObject('modCategory', array('category' => 'AI Assistant'));
if (!$category) {
    $category = $modx->newObject('modCategory');
    $category->set('category', 'AI Assistant');
    $category->save();
}
$categoryId = (int) $category->get('id');

saveElement($modx, 'modSnippet', 'name', 'aiAssistant', array(
    'description' => 'Плавающий AI-ассистент подбора сетов',
    'snippet' => readComponent($component . '/elements/snippets/aiassistant.snippet.php'),
    'category' => $categoryId,
));
saveElement($modx, 'modSnippet', 'name', 'aiAssistantFeed', array(
    'description' => 'JSON-фид каталога для AI-ассистента',
    'snippet' => readComponent($component . '/elements/snippets/aiassistantfeed.snippet.php'),
    'category' => $categoryId,
));
saveElement($modx, 'modChunk', 'name', 'aiassistant.rules', array(
    'description' => 'Правила подбора сетов для промпта',
    'snippet' => readComponent($component . '/elements/chunks/aiassistant.rules.tpl'),
    'category' => $categoryId,
));

$plugin = saveElement($modx, 'modPlugin', 'name', 'aiAssistant', array(
    'description' => 'Встраивает AI-ассистента в публичные страницы',
    'plugincode' => readComponent($component . '/elements/plugins/aiassistant.plugin.php'),
    'category' => $categoryId,
    'disabled' => 0,
));
$event = $modx->getObject('modPluginEvent', array(
    'pluginid' => (int) $plugin->get('id'),
    'event' => 'OnWebPagePrerender',
));
if (!$event) {
    $event = $modx->newObject('modPluginEvent');
    $event->fromArray(array(
        'pluginid' => (int) $plugin->get('id'),
        'event' => 'OnWebPagePrerender',
        'priority' => 0,
        'propertyset' => 0,
    ), '', true, true);
    $event->save();
}

$created = 0;
$kept = 0;
foreach ($settings as $row) {
    $setting = $modx->getObject('modSystemSetting', array('key' => $row['key']));
    if ($setting && !$forceSettings) {
        $kept++;
        continue;
    }
    if (!$setting) {
        $setting = $modx->newObject('modSystemSetting');
        $created++;
    }
    $setting->fromArray(array(
        'key' => $row['key'],
        'value' => $row['value'],
        'xtype' => $row['xtype'],
        'namespace' => 'aiassistant',
        'area' => $row['area'],
    ), '', true, true);
    $setting->save();
}

$modx->cacheManager->refresh();
echo "AI Assistant installed in {$root}\n";
echo "Settings created: {$created}, kept: {$kept}\n";
echo "Open System Settings and filter the namespace aiassistant.\n";

/**
 * @param modX $modx
 * @return object
 */
function saveElement($modx, $class, $key, $name, array $fields)
{
    $element = $modx->getObject($class, array($key => $name));
    if (!$element) {
        $element = $modx->newObject($class);
        $element->set($key, $name);
    }
    foreach ($fields as $field => $value) {
        $element->set($field, $value);
    }
    $element->save();

    return $element;
}

function readComponent($path)
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        fwrite(STDERR, "Missing component file: {$path}\n");
        exit(1);
    }

    return preg_replace('/^<\?php\s*/', '', $content, 1);
}
