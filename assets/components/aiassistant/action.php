<?php

/**
 * Public chat endpoint for the catalog assistant.
 * MODX 2 and MODX 3: the root is four levels above this file
 * (assets/components/aiassistant/action.php).
 */

define('MODX_API_MODE', true);

$root = dirname(__DIR__, 4);
if (is_file(__DIR__ . '/config.php')) {
    $configured = require __DIR__ . '/config.php';
    if (is_string($configured) && $configured !== '') {
        $root = $configured;
    }
}

$configCore = rtrim((string) $root, '/\\') . '/config.core.php';
if (!is_file($configCore)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => 'MODX не найден'), JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $configCore;

if (is_file(MODX_CORE_PATH . 'vendor/autoload.php')) {
    require_once MODX_CORE_PATH . 'vendor/autoload.php';
    $modxClass = class_exists('MODX\\Revolution\\modX') ? 'MODX\\Revolution\\modX' : 'modX';
} else {
    require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
    $modxClass = 'modX';
}

/** @var modX $modx */
$modx = new $modxClass();
$modx->initialize('web');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$corePath = $modx->getOption('core_path') . 'components/aiassistant/';
/** @var AiAssistant|null $assistant */
$assistant = $modx->getService('aiassistant', 'AiAssistant', $corePath . 'model/aiassistant/');
if (!$assistant) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'Компонент не установлен'), JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'Метод не поддерживается'), JSON_UNESCAPED_UNICODE);
    exit;
}

$origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '') {
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $requestHost = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($originHost === '' || $requestHost === '' || $originHost !== $requestHost) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'Чужой источник запроса'), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') === false) {
    http_response_code(415);
    echo json_encode(array('ok' => false, 'error' => 'Ожидается JSON'), JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
    http_response_code(413);
    echo json_encode(array('ok' => false, 'error' => 'Слишком большой запрос'), JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Некорректный JSON'), JSON_UNESCAPED_UNICODE);
    exit;
}

$result = $assistant->handleApi($data);
http_response_code((int) $result['status']);
echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
