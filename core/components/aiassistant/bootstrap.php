<?php

/**
 * PSR-4 autoload for the component. MODX does not need Composer for this.
 */

spl_autoload_register(static function ($class) {
    $prefix = 'AiAssistant\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});
