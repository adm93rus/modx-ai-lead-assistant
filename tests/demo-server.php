<?php

/**
 * Local shop page that runs the real widget, catalog filter, and set builder
 * without a MODX installation.
 *
 *   php -S 127.0.0.1:8765 tests/demo-server.php
 */

require dirname(__DIR__) . '/core/components/aiassistant/bootstrap.php';

use AiAssistant\Catalog\FeedParser;
use AiAssistant\Chat\AssistantEngine;
use AiAssistant\Widget\WidgetRenderer;

$catalogRaw = file_get_contents(dirname(__DIR__) . '/assets/components/aiassistant/sample/catalog.sample.json');
$catalog = (new FeedParser())->parse($catalogRaw, 'json');
$products = $catalog['products'];
$rules = file_get_contents(dirname(__DIR__) . '/core/components/aiassistant/elements/chunks/aiassistant.rules.tpl');

function demo_engine($rules)
{
    return new AssistantEngine(null, array(
        'provider' => 'demo',
        'currency' => '₽',
        'rules' => is_string($rules) ? $rules : '',
        'assistant_name' => 'AI-ассистент',
    ));
}

function demo_json($payload, $status)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function demo_session()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/action.php') {
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        demo_json(array('ok' => false, 'error' => 'Некорректный JSON'), 400);
    }
    $action = isset($data['action']) ? (string) $data['action'] : 'message';
    $engine = demo_engine($rules);
    if ($action === 'specification') {
        $email = trim((string) (isset($data['email']) ? $data['email'] : ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            demo_json(array('ok' => false, 'error' => 'Укажите корректный email'), 422);
        }
        $lines = isset($data['products']) && is_array($data['products']) ? $data['products'] : array();
        $result = $engine->specification($lines, $products, array(
            'budget' => null,
            'quantity' => isset($data['quantity']) ? (int) $data['quantity'] : 0,
            'color' => '',
            'keywords' => array(),
            'themes' => array(),
        ));
        if (empty($result['products'])) {
            demo_json(array('ok' => false, 'error' => 'Товары не найдены в каталоге'), 422);
        }
        demo_json(array(
            'ok' => true,
            'message' => 'Заявка отправлена. Мы подготовим визуализацию и свяжемся с вами.',
        ), 200);
    }

    $message = trim((string) (isset($data['message']) ? $data['message'] : ''));
    if ($message === '') {
        demo_json(array('ok' => false, 'error' => 'Некорректное сообщение'), 422);
    }
    $conversation = preg_replace('/[^A-Za-z0-9_-]/', '', (string) (isset($data['conversation_id']) ? $data['conversation_id'] : 'demo'));
    demo_session();
    if (!isset($_SESSION['demo'][$conversation]) || !is_array($_SESSION['demo'][$conversation])) {
        $_SESSION['demo'][$conversation] = array();
    }
    $history = $_SESSION['demo'][$conversation];
    $result = $engine->handle($history, $message, $products);
    $_SESSION['demo'][$conversation][] = array('role' => 'user', 'content' => $message);
    $_SESSION['demo'][$conversation][] = array('role' => 'assistant', 'content' => (string) $result['message']);
    unset($result['debug']);
    demo_json(array(
        'ok' => !empty($result['ok']),
        'message' => $result['message'],
        'products' => $result['products'],
        'set' => $result['set'],
        'error' => $result['error'],
    ), !empty($result['ok']) ? 200 : 502);
}

if ($path === '/cart') {
    demo_json(array('success' => true, 'message' => 'added', 'data' => array('total_count' => 1)), 200);
}

if (preg_match('#^/assets/components/aiassistant/(css|js)/assistant\.(css|js)$#', $path, $match)) {
    $file = dirname(__DIR__) . '/assets/components/aiassistant/' . $match[1] . '/assistant.' . $match[2];
    header('Content-Type: ' . ($match[2] === 'css' ? 'text/css' : 'application/javascript') . '; charset=utf-8');
    readfile($file);
    exit;
}

$byUrl = array();
foreach ($products as $product) {
    $byUrl[$product['url']] = $product;
}

if (isset($byUrl[$path])) {
    demo_page(demo_product($byUrl[$path]), $products);
}

if ($path === '/' || $path === '/index.html') {
    demo_page(demo_catalog($products), $products);
}

if ($path === '/debug-visitor' || $path === '/debug-admin') {
    $manager = $path === '/debug-admin';
    setcookie('aiassistant_demo_mgr', $manager ? '1' : '0', 0, '/');
    $_COOKIE['aiassistant_demo_mgr'] = $manager ? '1' : '0';
    $assistant = demo_debug_assistant($manager);
    demo_page(demo_catalog($products), $products, $assistant->getWidgetHtml());
}

if ($path === '/debug-action.php') {
    $manager = isset($_COOKIE['aiassistant_demo_mgr']) && $_COOKIE['aiassistant_demo_mgr'] === '1';
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        demo_json(array('ok' => false, 'error' => 'Некорректный JSON'), 400);
    }
    $response = demo_debug_assistant($manager)->handleApi($data);
    demo_json($response['body'], $response['status']);
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
exit;

function demo_page($content, array $products, $widget = null)
{
    if ($widget === null) {
        $widget = (new WidgetRenderer())->render(array(
            'assets_url' => '/assets/components/aiassistant/',
            'endpoint' => '/action.php',
            'assistant_name' => 'AI-ассистент',
            'position' => 'right',
            'accent' => '#1d4ed8',
            'currency' => '₽',
            'cart_enabled' => true,
            'cart_mode' => 'minishop2',
            'cart_url' => '/cart',
            'spec_enabled' => true,
            'context' => 'web',
        ));
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Корпоративные сувениры</title>'
        . '<style>'
        . 'body{margin:0;font-family:Inter,Segoe UI,sans-serif;background:#f6f7fb;color:#1f2937}'
        . 'header{padding:28px 7vw 8px}h1{margin:0 0 8px;font-size:32px}header p{margin:0;max-width:640px;color:#4b5563}'
        . 'main{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;padding:28px 7vw 80px}'
        . 'article{background:#fff;border-radius:16px;padding:16px;box-shadow:0 8px 24px rgba(15,23,42,.06)}'
        . 'article a{color:#1d4ed8;font-weight:700;text-decoration:none}article span{display:block;color:#6b7280;font-size:13px;margin-top:6px}'
        . '.product{max-width:720px;margin:40px auto;background:#fff;border-radius:18px;padding:28px}'
        . '</style></head><body>' . $content . $widget . '</body></html>';
    exit;
}

function demo_catalog(array $products)
{
    $cards = '';
    foreach ($products as $product) {
        $cards .= '<article><a href="' . htmlspecialchars($product['url'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') . '</a>'
            . '<span>' . htmlspecialchars($product['article'], ENT_QUOTES, 'UTF-8') . ' · '
            . htmlspecialchars($product['color'], ENT_QUOTES, 'UTF-8') . ' · '
            . htmlspecialchars((string) $product['price'], ENT_QUOTES, 'UTF-8') . ' ₽</span>'
            . '<span>' . htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8') . '</span></article>';
    }

    return '<header><h1>Корпоративные сувениры</h1>'
        . '<p>Соберите приветственный пак в чате: бюджет, тираж, цвет и повод.</p></header>'
        . '<main>' . $cards . '</main>';
}

function demo_product(array $product)
{
    return '<article class="product"><p><a href="/">Каталог</a></p><h1>'
        . htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') . '</h1><p>Артикул '
        . htmlspecialchars($product['article'], ENT_QUOTES, 'UTF-8') . '</p><p>'
        . htmlspecialchars((string) $product['price'], ENT_QUOTES, 'UTF-8')
        . ' ₽ · ' . htmlspecialchars($product['color'], ENT_QUOTES, 'UTF-8') . '</p><p>'
        . htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8') . '</p></article>';
}

function demo_debug_assistant($manager)
{
    static $assistants = array();
    $key = $manager ? 'mgr' : 'web';
    if (isset($assistants[$key])) {
        return $assistants[$key];
    }

    require_once __DIR__ . '/ModxStub.php';
    require_once dirname(__DIR__) . '/core/components/aiassistant/model/aiassistant/aiassistant.class.php';
    $root = sys_get_temp_dir() . '/aiassistant-demo-debug';
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Cannot create debug demo directory');
    }
    $modx = new AiAssistantTestModx(array(
        'core_path' => $root . '/',
        'assets_url' => '/assets/',
        'aiassistant.core_path' => dirname(__DIR__) . '/core/components/aiassistant/',
        'aiassistant.debug' => '1',
        'aiassistant.enabled' => '1',
        'aiassistant.provider' => 'demo',
        'aiassistant.catalog_source' => 'feed',
        'aiassistant.feed_url' => 'https://catalog.example/products.json',
        'aiassistant.feed_format' => 'json',
        'aiassistant.feed_cache_ttl' => '86400',
        'aiassistant.api_key' => 'sk-demo-should-not-leak',
        'aiassistant.currency' => '₽',
        'aiassistant.fallback' => '1',
        'aiassistant.rules_chunk' => '',
    ), $manager);
    $assistant = new AiAssistant($modx, array('endpoint' => '/debug-action.php'));
    demo_seed_catalog($assistant, $root);
    $assistants[$key] = $assistant;

    return $assistant;
}

function demo_seed_catalog($assistant, $root)
{
    $method = new ReflectionMethod($assistant, 'catalogSignature');
    $method->setAccessible(true);
    $signature = md5((string) json_encode($method->invoke($assistant)));
    $dir = rtrim($root, '/') . '/cache/aiassistant';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }
    $raw = file_get_contents(dirname(__DIR__) . '/assets/components/aiassistant/sample/catalog.sample.json');
    $parsed = (new FeedParser())->parse($raw, 'json');
    file_put_contents($dir . '/catalog.json', json_encode(array(
        'signature' => $signature,
        'fetched_at' => time(),
        'products' => $parsed['products'],
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
