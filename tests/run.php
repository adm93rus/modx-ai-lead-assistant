<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/RecordingProvider.php';

use AiAssistant\Catalog\FeedParser;
use AiAssistant\Chat\AssistantEngine;
use AiAssistant\Chat\PromptBuilder;
use AiAssistant\Provider\GigaChatProvider;
use AiAssistant\Provider\HttpClient;
use AiAssistant\Provider\MemoryTokenStore;
use AiAssistant\Provider\OpenAiProvider;
use AiAssistant\Provider\YandexGptProvider;
use AiAssistant\Rag\CatalogRetriever;
use AiAssistant\Rag\CriteriaExtractor;
use AiAssistant\Support\Text;

$catalog = test_catalog();
test_assert(count($catalog) === 9, 'sample catalog size');

$byArticle = array();
foreach ($catalog as $product) {
    $byArticle[$product['article']] = $product;
}
test_same($byArticle['11391.40']['id'], 202, 'resource id is kept when article differs');
test_same($byArticle['11391.40']['price'], 790.0, 'price parsed');

$parser = new FeedParser();
$yml = $parser->parse(file_get_contents(__DIR__ . '/fixtures/catalog.yml'), 'yml');
test_same($yml['error'], '', 'yml error empty');
test_same(count($yml['products']), 2, 'unavailable offer is skipped');
$ymlByArticle = array();
foreach ($yml['products'] as $product) {
    $ymlByArticle[$product['article']] = $product;
}
test_same($ymlByArticle['6925.50']['color'], 'красный', 'yml color param');
test_same($ymlByArticle['6925.50']['category'], 'Блокноты', 'yml category lookup');
test_same($ymlByArticle['6925.50']['id'], 101, 'yml numeric offer id with vendorCode');
test_same($ymlByArticle['11391.50']['price'], 790.0, 'yml price');
test_assert(strpos($ymlByArticle['6925.50']['url'], 'https://') === 0, 'yml url kept');

$encoded = iconv('UTF-8', 'Windows-1251//IGNORE', file_get_contents(__DIR__ . '/fixtures/catalog.yml'));
$encoded = preg_replace('/encoding="UTF-8"/', 'encoding="windows-1251"', $encoded);
$win = $parser->parse($encoded, 'xml');
test_same(count($win['products']), 2, 'windows-1251 yml parsed');
test_same($win['products'][0]['name'], 'Блокнот Nota Bene', 'windows-1251 name');

$wrapped = $parser->parse(json_encode(array('catalog' => array('products' => array(
    array('sku' => '1', 'title' => 'Ручка', 'cost' => '1 250,50', 'properties' => array('Цвет' => 'синий')),
)))), 'auto');
test_same($wrapped['products'][0]['article'], '1', 'sku alias');
test_same($wrapped['products'][0]['price'], 1250.5, 'russian price');
test_same($wrapped['products'][0]['color'], 'синий', 'property color');
test_same($wrapped['products'][0]['id'], 0, 'article-only id is not a cart id');

$extractor = new CriteriaExtractor();
$marketing = 'Нужен приветственный пак для программистов в синем цвете, бюджет до 1500 рублей за сет, тираж 50 штук';
$criteria = $extractor->extract($marketing);
test_same($criteria['budget'], 1500.0, 'marketing budget');
test_same($criteria['quantity'], 50, 'marketing quantity');
test_same($criteria['color'], 'синий', 'marketing color');
test_assert(in_array('кабель', $criteria['themes'], true), 'programmer theme includes cable');
test_assert(in_array('термостакан', $criteria['themes'], true), 'welcome theme includes tumbler');
test_assert(!in_array('синем', $criteria['keywords'], true), 'color word is not a keyword');

$red = $extractor->extract('красный набор до 1200 руб');
test_same($red['budget'], 1200.0, 'short budget');
test_same($red['color'], 'красный', 'short color');
test_same($red['quantity'], 0, 'quantity absent');

$qtyOnly = $extractor->extract('до 50 штук');
test_same($qtyOnly['budget'], null, 'quantity is not a budget');
test_same($qtyOnly['quantity'], 50, 'pieces quantity');

$hello = $extractor->extract('Привет');
test_same($hello['budget'], null, 'greeting budget');
test_same($hello['color'], '', 'greeting color');
test_same($hello['keywords'], array(), 'greeting keywords');

$retriever = new CatalogRetriever(40);
$candidates = $retriever->retrieve($catalog, $criteria);
$candidateArticles = array();
foreach ($candidates as $product) {
    $candidateArticles[] = $product['article'];
}
test_assert(in_array('11391.40', $candidateArticles, true), 'blue tumbler retrieved');
test_assert(in_array('7212.40', $candidateArticles, true), 'blue cable retrieved');
test_assert(!in_array('11391.50', $candidateArticles, true), 'red tumbler filtered out');
test_assert(!in_array('5500.00', $candidateArticles, true), 'over budget diary filtered out');
test_assert(!in_array('2204.20', $candidateArticles, true), 'white mug filtered out');
test_assert($candidates[0]['article'] === '7212.40', 'cable ranks first for programmers');

$engine = new AssistantEngine(null, array('provider' => 'demo', 'currency' => '₽'));
$demo = $engine->handle(array(), $marketing, $catalog);
test_assert($demo['ok'] === true, 'demo ok');
test_same($demo['source'], 'demo', 'demo source');
$demoArticles = array();
foreach ($demo['products'] as $product) {
    $demoArticles[] = $product['article'];
}
test_assert(in_array('11391.40', $demoArticles, true), 'demo set contains tumbler');
test_assert(in_array('7212.40', $demoArticles, true), 'demo set contains cable');
test_assert(!in_array('5500.00', $demoArticles, true), 'demo set excludes diary');
test_same($demo['set']['per_set'], 1350.0, 'demo per set price');
test_same($demo['set']['order_total'], 67500.0, 'demo order total');
test_assert(strpos($demo['message'], '11391.40') !== false, 'reply contains tumbler article');
test_assert(strpos($demo['message'], '7212.40') !== false, 'reply contains cable article');
test_assert($demo['set']['within_budget'] === true, 'set is within budget');

$greet = $engine->handle(array(), 'Привет', $catalog);
test_same($greet['products'], array(), 'greeting does not dump the catalog');
test_assert(strpos($greet['message'], 'бюджет') !== false, 'greeting asks for budget');

$empty = $engine->handle(array(), $marketing, array());
test_same($empty['products'], array(), 'missing catalog');
test_assert(strpos($empty['message'], 'фид') !== false, 'missing catalog explains the feed');

$provider = new RecordingProvider('{"reply":"Предлагаю термостакан Forma (арт. 11391.40) и кабель (арт. 7212.40).","set_title":"Приветственный пак","products":[{"article":"11391.40","qty":1},{"article":"7212.40","qty":1},{"article":"0000.99","qty":1},{"article":"5500.00","qty":1}]}');
$model = (new AssistantEngine($provider, array(
    'provider' => 'openai',
    'currency' => '₽',
    'fallback' => false,
)))->handle(array(), $marketing, $catalog);
test_same($model['source'], 'model', 'model source');
$modelArticles = array();
foreach ($model['products'] as $product) {
    $modelArticles[] = $product['article'];
}
test_same($modelArticles, array('11391.40', '7212.40'), 'unknown and over-budget articles dropped');
test_same($model['set']['per_set'], 1350.0, 'model prices come from the catalog');
test_same($model['products'][0]['id'], 202, 'cart id preserved');
test_same($model['set']['title'], 'Приветственный пак', 'set title kept');
test_assert(count($provider->calls) === 1, 'model called once');
$promptBlob = json_encode($provider->calls[0], JSON_UNESCAPED_UNICODE);
test_assert(strpos($promptBlob, '11391.40') !== false, 'prompt contains a matching article');
test_assert(strpos($promptBlob, '11391.50') === false, 'prompt hides the red tumbler');
test_assert(strpos($promptBlob, '5500.00') === false, 'prompt hides the expensive diary');
test_assert(strpos($promptBlob, 'sk-secret') === false, 'prompt has no api key');

$conflict = new RecordingProvider('{"reply":"Берите арт. 9999.99","products":[{"article":"11391.40","qty":1}]}');
$conflicted = (new AssistantEngine($conflict, array('provider' => 'yandexgpt', 'fallback' => false)))
    ->handle(array(), $marketing, $catalog);
test_assert(strpos($conflicted['message'], '9999.99') === false, 'invented article is removed from the reply');
test_assert(strpos($conflicted['message'], '11391.40') !== false, 'validated article stays in the reply');

$down = new RecordingProvider('', false, 'upstream timeout');
$fallback = (new AssistantEngine($down, array('provider' => 'gigachat', 'fallback' => true)))
    ->handle(array(), $marketing, $catalog);
test_same($fallback['source'], 'fallback', 'provider failure uses the catalog set');
test_assert($fallback['ok'] === true, 'fallback is successful');
test_same($fallback['set']['per_set'], 1350.0, 'fallback price');

$strict = (new AssistantEngine($down, array('provider' => 'gigachat', 'fallback' => false)))
    ->handle(array(), 'Привет', $catalog);
test_same($strict['ok'], false, 'failure without a signal is an error');

$calls = array();
$http = new HttpClient(array(), static function ($method, $url, $body, $headers) use (&$calls) {
    $calls[] = array(
        'method' => $method,
        'url' => $url,
        'body' => $body,
        'headers' => $headers,
    );
    if (strpos($url, '/oauth') !== false) {
        return array(
            'ok' => true,
            'status' => 200,
            'body' => '',
            'json' => array('access_token' => 'token-1', 'expires_at' => (time() + 600) * 1000),
            'error' => '',
        );
    }
    if (strpos($url, 'foundationModels') !== false) {
        return array(
            'ok' => true,
            'status' => 200,
            'body' => '',
            'json' => array('result' => array('alternatives' => array(array('message' => array('text' => '{"reply":"ok","products":[]}'))))),
            'error' => '',
        );
    }

    return array(
        'ok' => true,
        'status' => 200,
        'body' => '',
        'json' => array('choices' => array(array('message' => array('content' => '{"reply":"ok","products":[]}')))),
        'error' => '',
    );
});

$openAi = new OpenAiProvider($http, array('api_key' => 'sk-test', 'model' => 'gpt-4o-mini', 'json_mode' => true));
$openAiResult = $openAi->complete(array(
    array('role' => 'system', 'content' => 'rules'),
    array('role' => 'user', 'content' => 'catalog'),
));
test_same($openAiResult['ok'], true, 'openai ok');
$openAiBody = json_decode($calls[0]['body'], true);
test_same($calls[0]['url'], 'https://api.openai.com/v1/chat/completions', 'openai url');
test_same($openAiBody['model'], 'gpt-4o-mini', 'openai model');
test_same($openAiBody['response_format']['type'], 'json_object', 'openai json mode');
test_assert(in_array('Authorization: Bearer sk-test', $calls[0]['headers'], true), 'openai bearer');

$calls = array();
$yandex = new YandexGptProvider($http, array(
    'api_key' => 'AQVN-test-key',
    'folder_id' => 'b1gfolder',
    'model' => 'yandexgpt/latest',
));
$yandexResult = $yandex->complete(array(
    array('role' => 'system', 'content' => 'rules'),
    array('role' => 'user', 'content' => 'catalog'),
));
test_same($yandexResult['content'], '{"reply":"ok","products":[]}', 'yandex content');
$yandexBody = json_decode($calls[0]['body'], true);
test_same($yandexBody['modelUri'], 'gpt://b1gfolder/yandexgpt/latest', 'yandex model uri');
test_same($yandexBody['messages'][0]['role'], 'system', 'yandex system role');
test_same($yandexBody['messages'][0]['text'], 'rules', 'yandex uses text');
test_assert(in_array('Authorization: Api-Key AQVN-test-key', $calls[0]['headers'], true), 'yandex api key header');

$calls = array();
$giga = new GigaChatProvider($http, new MemoryTokenStore(), array('api_key' => 'base64key', 'model' => 'GigaChat'));
$giga->complete(array(array('role' => 'user', 'content' => 'catalog')));
$gigaAgain = $giga->complete(array(array('role' => 'user', 'content' => 'catalog-2')));
test_same($gigaAgain['ok'], true, 'gigachat second call');
test_same(count($calls), 3, 'oauth happens once and is cached');
test_assert(strpos($calls[0]['url'], '/oauth') !== false, 'gigachat oauth url');
test_assert(strpos($calls[0]['body'], 'scope=GIGACHAT_API_PERS') !== false, 'gigachat scope');
test_assert(in_array('Authorization: Basic base64key', $calls[0]['headers'], true), 'gigachat basic auth');
test_assert(in_array('Authorization: Bearer token-1', $calls[1]['headers'], true), 'gigachat bearer');
test_same($calls[1]['url'], 'https://gigachat.devices.sberbank.ru/api/v1/chat/completions', 'gigachat chat url');

$prompt = (new PromptBuilder())->build(array(), 'hi', array(), array('budget' => null, 'quantity' => 0, 'color' => ''), array(
    'rules' => 'Не обещай скидку.',
    'categories' => array('Блокноты'),
));
test_assert(strpos($prompt[0]['content'], 'Не обещай скидку.') !== false, 'shop rules included');
test_assert(strpos($prompt[0]['content'], 'ЖЁСТКИЙ КОНТРАКТ') !== false, 'contract included');

$widget = (new AiAssistant\Widget\WidgetRenderer())->render(array(
    'assistant_name' => '<script>alert(1)</script>',
    'endpoint' => '/action.php',
    'assets_url' => '/assets/components/aiassistant/',
    'position' => 'right',
));
test_assert(strpos($widget, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'widget escapes the assistant name');
test_assert(strpos($widget, 'is-right') !== false, 'widget defaults to the right');
test_assert(strpos($widget, 'data-endpoint="/action.php"') !== false, 'widget endpoint');
test_assert(strpos($widget, 'is-debug') === false, 'widget hides the debug badge by default');
$debugWidget = (new AiAssistant\Widget\WidgetRenderer())->render(array(
    'assistant_name' => 'AI-ассистент',
    'endpoint' => '/action.php',
    'assets_url' => '/assets/components/aiassistant/',
    'debug' => true,
));
test_assert(strpos($debugWidget, 'is-debug') !== false, 'debug widget is marked');
test_assert(strpos($debugWidget, 'fw-assistant-debug') !== false, 'debug widget shows the badge');
test_assert(isset($model['debug']['candidates']) && count($model['debug']['candidates']) > 0, 'model trace lists candidates');
test_assert(isset($model['debug']['prompt'][0]['content']), 'model trace keeps the prompt');
test_assert(strpos(json_encode($model['debug'], JSON_UNESCAPED_UNICODE), '11391.50') === false, 'trace hides the red tumbler');
test_assert(strpos($model['debug']['criteria']['color'], 'синий') !== false, 'trace keeps the color');

require __DIR__ . '/ModxStub.php';
require dirname(__DIR__) . '/core/components/aiassistant/model/aiassistant/aiassistant.class.php';

$debugRoot = sys_get_temp_dir() . '/aiassistant-debug-test-' . getmypid();
$debugModx = new AiAssistantTestModx(array(
    'core_path' => $debugRoot . '/',
    'assets_url' => '/assets/',
    'aiassistant.core_path' => dirname(__DIR__) . '/core/components/aiassistant/',
    'aiassistant.debug' => '1',
    'aiassistant.enabled' => '1',
    'aiassistant.provider' => 'demo',
    'aiassistant.catalog_source' => 'feed',
    'aiassistant.feed_url' => 'https://catalog.example/products.json',
    'aiassistant.feed_format' => 'json',
    'aiassistant.feed_cache_ttl' => '3600',
    'aiassistant.api_key' => 'sk-super-secret-value',
    'aiassistant.feed_basic_pass' => 'feed-password-value',
    'aiassistant.currency' => '₽',
    'aiassistant.fallback' => '1',
    'aiassistant.rules_chunk' => '',
), false);
$debugAssistant = new AiAssistant($debugModx, array('endpoint' => '/debug-action.php'));
$signatureMethod = new ReflectionMethod($debugAssistant, 'catalogSignature');
$signatureMethod->setAccessible(true);
$debugSignature = md5((string) json_encode($signatureMethod->invoke($debugAssistant)));
if (!is_dir($debugRoot . '/cache/aiassistant') && !mkdir($debugRoot . '/cache/aiassistant', 0700, true)) {
    fwrite(STDERR, "Cannot create debug cache\n");
    exit(1);
}
file_put_contents($debugRoot . '/cache/aiassistant/catalog.json', json_encode(array(
    'signature' => $debugSignature,
    'fetched_at' => time(),
    'products' => $catalog,
), JSON_UNESCAPED_UNICODE));
test_same($debugAssistant->getWidgetHtml(), '', 'debug hides the widget from visitors');
$denied = $debugAssistant->handleApi(array(
    'action' => 'message',
    'conversation_id' => 'debugconversation01',
    'message' => 'секретный запрос посетителя sk-super-secret-value',
));
test_same($denied['status'], 403, 'debug rejects a visitor request');
test_assert(strpos($denied['body']['error'], 'администратор') !== false, 'debug denial explains the lock');
$deniedLog = (string) file_get_contents($debugRoot . '/cache/aiassistant/debug.log');
test_assert(strpos($deniedLog, '"event":"denied"') !== false, 'debug log records a denied request');
test_assert(strpos($deniedLog, 'секретный запрос') === false, 'denied request body is not stored');
test_assert(strpos($deniedLog, 'sk-super-secret-value') === false, 'denied log has no api key');

$debugModx->user = new AiAssistantTestUser(true);
$adminHtml = $debugAssistant->getWidgetHtml();
test_assert(strpos($adminHtml, 'id="fwAssistant"') !== false, 'debug shows the widget to a manager');
test_assert(strpos($adminHtml, 'is-debug') !== false, 'manager widget is marked as debug');
test_assert(strpos($adminHtml, 'data-endpoint="/debug-action.php"') !== false, 'widget endpoint can be overridden');
$answered = $debugAssistant->handleApi(array(
    'action' => 'message',
    'conversation_id' => 'debugconversation01',
    'message' => 'Нужен приветственный пак для программистов в синем цвете, бюджет до 1500 рублей за сет, тираж 50 штук',
    'page_url' => 'https://shop.example/catalog',
));
test_same($answered['status'], 200, 'manager request succeeds in debug mode');
test_assert(!isset($answered['body']['debug']), 'api response hides the debug trace');
test_assert(strpos($answered['body']['message'], '7212.40') !== false, 'manager still receives the cable');
$adminLog = (string) file_get_contents($debugRoot . '/cache/aiassistant/debug.log');
test_assert(strpos($adminLog, '"event":"message"') !== false, 'debug log records the message');
test_assert(strpos($adminLog, '7212.40') !== false, 'debug log records the chosen article');
test_assert(strpos($adminLog, '11391.40') !== false, 'debug log records the tumbler');
test_assert(strpos($adminLog, 'синий') !== false, 'debug log records the color criterion');
test_assert(strpos($adminLog, 'sk-super-secret-value') === false, 'debug log redacts the api key');
test_assert(strpos($adminLog, 'feed-password-value') === false, 'debug log redacts the feed password');
$infoLogged = false;
foreach ($debugModx->logs as $row) {
    if (strpos($row[1], '[aiassistant:debug] message') !== false && strpos($row[1], '7212.40') !== false) {
        $infoLogged = true;
    }
}
test_assert($infoLogged, 'debug mode also writes a MODX log line');

$publicRoot = sys_get_temp_dir() . '/aiassistant-public-test-' . getmypid();
$publicModx = new AiAssistantTestModx(array(
    'core_path' => $publicRoot . '/',
    'assets_url' => '/assets/',
    'aiassistant.core_path' => dirname(__DIR__) . '/core/components/aiassistant/',
    'aiassistant.debug' => '0',
    'aiassistant.enabled' => '1',
    'aiassistant.provider' => 'demo',
    'aiassistant.catalog_source' => 'feed',
    'aiassistant.feed_url' => '',
    'aiassistant.rules_chunk' => '',
), false);
$publicAssistant = new AiAssistant($publicModx);
test_assert(strpos($publicAssistant->getWidgetHtml(), 'id="fwAssistant"') !== false, 'debug off shows the widget to visitors');
test_assert(strpos($publicAssistant->getWidgetHtml(), 'is-debug') === false, 'debug off has no debug badge');

$spec = $engine->specification(array(
    array('article' => '11391.40', 'qty' => 1),
    array('article' => '7212.40', 'qty' => 1),
    array('article' => '0000.99', 'qty' => 3),
), $catalog, $criteria);
test_same($spec['set']['per_set'], 1350.0, 'specification recalculates the price');
test_same(count($spec['products']), 2, 'specification drops unknown articles');

echo $testFailed === 0
    ? "OK {$testCount} assertions\n"
    : "FAILED {$testFailed} of {$testCount} assertions\n";

exit($testFailed === 0 ? 0 : 1);
