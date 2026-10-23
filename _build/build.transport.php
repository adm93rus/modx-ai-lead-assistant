<?php

/**
 * Builds a MODX transport package from this repository.
 * Run inside a MODX 2.8+ or MODX 3 installation:
 *   cp build.config.sample.php build.config.php
 *   php build.transport.php
 */

$tstart = microtime(true);
set_time_limit(0);
require_once dirname(__FILE__) . '/build.config.php';

if (defined('MODX_BASE_PATH') && !defined('MODX_BASE_URL')) {
    define('MODX_BASE_URL', '/');
}

if (is_file(MODX_CORE_PATH . 'vendor/autoload.php')) {
    require_once MODX_CORE_PATH . 'vendor/autoload.php';
    $modxClass = class_exists('MODX\\Revolution\\modX') ? 'MODX\\Revolution\\modX' : 'modX';
} else {
    require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
    $modxClass = 'modX';
}

$modx = new $modxClass();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_INFO);
$modx->setLogTarget('ECHO');

$root = dirname(__DIR__);
$sources = array(
    'root' => $root . '/',
    'build' => $root . '/_build/',
    'data' => $root . '/_build/data/',
    'docs' => $root . '/core/components/aiassistant/docs/',
    'source_core' => $root . '/core/components/aiassistant',
    'source_assets' => $root . '/assets/components/aiassistant',
);

$builderClass = class_exists('MODX\\Revolution\\Transport\\modPackageBuilder')
    ? 'MODX\\Revolution\\Transport\\modPackageBuilder'
    : 'modPackageBuilder';
if (!class_exists($builderClass)) {
    $modx->loadClass('transport.modPackageBuilder', '', false, true);
    $builderClass = 'modPackageBuilder';
}

/** @var modPackageBuilder $builder */
$builder = new $builderClass($modx);
$builder->createPackage('aiassistant', '1.0.0', 'pl');
$builder->registerNamespace('aiassistant', false, true, '{core_path}components/aiassistant/');

$category = $modx->newObject('modCategory');
$category->set('category', 'AI Assistant');
$snippets = include $sources['data'] . 'transport.snippets.php';
$chunks = include $sources['data'] . 'transport.chunks.php';
$plugins = include $sources['data'] . 'transport.plugins.php';
$category->addMany($snippets);
$category->addMany($chunks);
$category->addMany($plugins);

$attributes = array(
    xPDOTransport::UNIQUE_KEY => 'category',
    xPDOTransport::PRESERVE_KEYS => false,
    xPDOTransport::UPDATE_OBJECT => true,
    xPDOTransport::RELATED_OBJECTS => true,
    xPDOTransport::RELATED_OBJECT_ATTRIBUTES => array(
        'Snippets' => array(
            xPDOTransport::PRESERVE_KEYS => false,
            xPDOTransport::UPDATE_OBJECT => true,
            xPDOTransport::UNIQUE_KEY => 'name',
        ),
        'Chunks' => array(
            xPDOTransport::PRESERVE_KEYS => false,
            xPDOTransport::UPDATE_OBJECT => true,
            xPDOTransport::UNIQUE_KEY => 'name',
        ),
        'Plugins' => array(
            xPDOTransport::PRESERVE_KEYS => false,
            xPDOTransport::UPDATE_OBJECT => true,
            xPDOTransport::UNIQUE_KEY => 'name',
        ),
        'PluginEvents' => array(
            xPDOTransport::PRESERVE_KEYS => true,
            xPDOTransport::UPDATE_OBJECT => false,
            xPDOTransport::UNIQUE_KEY => array('pluginid', 'event'),
        ),
    ),
);

$vehicle = $builder->createVehicle($category, $attributes);
$vehicle->resolve('file', array(
    'source' => $sources['source_core'],
    'target' => "return MODX_CORE_PATH . 'components/';",
));
$vehicle->resolve('file', array(
    'source' => $sources['source_assets'],
    'target' => "return MODX_ASSETS_PATH . 'components/';",
));
$builder->putVehicle($vehicle);

foreach (include $sources['data'] . 'transport.settings.php' as $setting) {
    $vehicle = $builder->createVehicle($setting, array(
        xPDOTransport::UNIQUE_KEY => 'key',
        xPDOTransport::PRESERVE_KEYS => true,
        xPDOTransport::UPDATE_OBJECT => false,
    ));
    $builder->putVehicle($vehicle);
}

$builder->setPackageAttributes(array(
    'license' => file_get_contents($sources['docs'] . 'license.txt'),
    'readme' => file_get_contents($sources['docs'] . 'readme.txt'),
    'changelog' => file_get_contents($sources['docs'] . 'changelog.txt'),
));

$builder->pack();
echo 'Package built in ' . round(microtime(true) - $tstart, 2) . "s\n";
