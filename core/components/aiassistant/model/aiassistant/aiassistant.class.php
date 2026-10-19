<?php

require_once dirname(__DIR__, 2) . '/bootstrap.php';

class AiAssistant
{
    /** @var modX */
    public $modx;

    /** @var array<string, mixed> */
    public $config = array();

    /** @var bool */
    private $loadingCatalog = false;

    /**
     * @param modX $modx
     * @param array<string, mixed> $config
     */
    public function __construct(&$modx, array $config = array())
    {
        $this->modx =& $modx;
        $corePath = $this->modx->getOption(
            'aiassistant.core_path',
            $config,
            $this->modx->getOption('core_path') . 'components/aiassistant/'
        );
        $assetsUrl = $this->modx->getOption(
            'aiassistant.assets_url',
            $config,
            $this->modx->getOption('assets_url') . 'components/aiassistant/'
        );
        $this->config = array_merge(array(
            'corePath' => $corePath,
            'assetsUrl' => $assetsUrl,
        ), $config);

        if (!class_exists('AiAssistant\\Chat\\AssistantEngine', false)) {
            require_once rtrim((string) $corePath, '/\\') . '/bootstrap.php';
        }
    }

    public function getWidgetHtml()
    {
        if (!$this->enabled() || !$this->visibleOnResource()) {
            return '';
        }

        $renderer = new AiAssistant\Widget\WidgetRenderer();
        $cartUrl = trim($this->option('cart_url', ''));
        if ($cartUrl === '') {
            $cartUrl = $this->modx->getOption('assets_url') . 'components/minishop2/action.php';
        }

        return $renderer->render(array(
            'assets_url' => $this->config['assetsUrl'],
            'endpoint' => rtrim((string) $this->config['assetsUrl'], '/') . '/action.php',
            'assistant_name' => $this->option('assistant_name', 'AI-ассистент'),
            'welcome' => $this->option('welcome_text', ''),
            'placeholder' => $this->option('placeholder', ''),
            'hint' => $this->option('hint', ''),
            'status' => $this->option('status_text', ''),
            'position' => $this->option('position', 'right'),
            'accent' => $this->option('accent', '#1d4ed8'),
            'currency' => $this->option('currency', '₽'),
            'cart_enabled' => $this->boolOption('cart_enabled', true),
            'cart_mode' => $this->option('cart_mode', 'minishop2'),
            'cart_url' => $cartUrl,
            'spec_enabled' => $this->boolOption('spec_enabled', true),
            'context' => $this->modx->context ? (string) $this->modx->context->get('key') : 'web',
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handleApi(array $data)
    {
        if (!$this->enabled()) {
            return $this->respond(array('ok' => false, 'error' => 'Ассистент выключен'), 503);
        }

        $action = trim((string) (isset($data['action']) ? $data['action'] : 'message'));
        $conversationId = trim((string) (isset($data['conversation_id']) ? $data['conversation_id'] : ''));
        if (!$this->validConversationId($conversationId)) {
            return $this->respond(array('ok' => false, 'error' => 'Некорректный диалог'), 422);
        }

        if ($action === 'specification') {
            return $this->specification($data, $conversationId);
        }
        if ($action !== 'message') {
            return $this->respond(array('ok' => false, 'error' => 'Некорректное действие'), 422);
        }

        $message = trim((string) (isset($data['message']) ? $data['message'] : ''));
        $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
        if ($message === '' || $length > 2000) {
            return $this->respond(array('ok' => false, 'error' => 'Некорректное сообщение'), 422);
        }
        if ($this->limited()) {
            return $this->respond(array('ok' => false, 'error' => 'Слишком много сообщений'), 429);
        }

        $pageUrl = $this->clip(isset($data['page_url']) ? $data['page_url'] : '', 1000);
        $history = $this->history($conversationId);
        $engine = $this->engine();
        if (!$engine['ok']) {
            return $this->respond(array('ok' => false, 'error' => $engine['error']), 500);
        }

        $result = $engine['engine']->handle($history, $message, $this->loadCatalog());
        if (empty($result['ok'])) {
            $this->log('Provider error: ' . (isset($result['error']) ? $result['error'] : 'unknown'));

            return $this->respond(array(
                'ok' => false,
                'error' => 'Ассистент временно недоступен',
            ), 502);
        }

        $this->remember($conversationId, $message, (string) $result['message'], $pageUrl);

        return $this->respond($this->publicResult($result), 200);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadCatalog()
    {
        if ($this->loadingCatalog) {
            return array();
        }
        $this->loadingCatalog = true;

        try {
            $signature = md5((string) json_encode($this->catalogSignature()));
            $ttl = (int) $this->option('feed_cache_ttl', 3600);
            if ($ttl > 0) {
                $cached = $this->readCache($signature, $ttl);
                if ($cached !== null) {
                    return $cached;
                }
            }

            $source = strtolower(trim($this->option('catalog_source', 'feed')));
            if ($source === 'resources' || $source === 'modx') {
                $products = (new AiAssistant\Modx\ResourceCatalog($this->modx, $this->catalogConfig()))->load();
            } else {
                $products = $this->loadFeed();
            }

            if ($ttl > 0) {
                $this->writeCache($signature, $products);
            }

            return $products;
        } finally {
            $this->loadingCatalog = false;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: array<string, mixed>}
     */
    private function specification(array $data, $conversationId)
    {
        if (!$this->boolOption('spec_enabled', true)) {
            return $this->respond(array('ok' => false, 'error' => 'Спецификация выключена'), 403);
        }
        if ($this->limited()) {
            return $this->respond(array('ok' => false, 'error' => 'Слишком много сообщений'), 429);
        }

        $email = trim((string) (isset($data['email']) ? $data['email'] : ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            return $this->respond(array('ok' => false, 'error' => 'Укажите корректный email'), 422);
        }

        $lines = array();
        $rows = isset($data['products']) && is_array($data['products']) ? $data['products'] : array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = array(
                'article' => isset($row['article']) ? $row['article'] : '',
                'qty' => isset($row['qty']) ? $row['qty'] : 1,
            );
        }
        if (!$lines) {
            return $this->respond(array('ok' => false, 'error' => 'В спецификации нет товаров'), 422);
        }

        $criteria = array(
            'budget' => null,
            'quantity' => isset($data['quantity']) ? (int) $data['quantity'] : 0,
            'color' => '',
            'keywords' => array(),
            'themes' => array(),
        );
        $engine = $this->engine();
        if (!$engine['ok']) {
            return $this->respond(array('ok' => false, 'error' => $engine['error']), 500);
        }

        $result = $engine['engine']->specification($lines, $this->loadCatalog(), $criteria);
        if (empty($result['products'])) {
            return $this->respond(array('ok' => false, 'error' => 'Товары не найдены в каталоге'), 422);
        }

        $lead = array(
            'name' => $this->clip(isset($data['name']) ? $data['name'] : '', 200),
            'company' => $this->clip(isset($data['company']) ? $data['company'] : '', 200),
            'email' => $email,
            'phone' => $this->clip(isset($data['phone']) ? $data['phone'] : '', 80),
            'comment' => $this->clip(isset($data['comment']) ? $data['comment'] : '', 2000),
            'page_url' => $this->clip(isset($data['page_url']) ? $data['page_url'] : '', 1000),
            'conversation_id' => $conversationId,
        );

        $sent = $this->sendSpecification($lead, $result);
        $this->writeSpecLog($lead, $result, $sent);
        if (!$sent) {
            return $this->respond(array('ok' => false, 'error' => 'Не удалось отправить заявку'), 502);
        }

        return $this->respond(array(
            'ok' => true,
            'message' => 'Заявка отправлена. Мы подготовим визуализацию и свяжемся с вами.',
        ), 200);
    }

    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $result
     */
    private function sendSpecification(array $lead, array $result)
    {
        $emailTo = trim($this->option('spec_email', ''));
        if ($emailTo === '') {
            $emailTo = trim((string) $this->modx->getOption('emailsender'));
        }
        if (!filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
            $this->log('Specification email is not configured.');
            return false;
        }

        $rows = '';
        foreach ($result['products'] as $product) {
            $rows .= '<tr>'
                . '<td>' . $this->e($product['article']) . '</td>'
                . '<td>' . $this->e($product['name']) . '</td>'
                . '<td>' . $this->e((string) $product['qty']) . '</td>'
                . '<td>' . $this->e((string) $product['price']) . '</td>'
                . '<td>' . $this->e($product['url']) . '</td>'
                . '</tr>';
        }

        $set = $result['set'];
        $html = '<div style="font-family:Arial,sans-serif;color:#222;line-height:1.5">'
            . '<h2>Спецификация из AI-ассистента</h2>'
            . '<p><strong>Имя:</strong> ' . $this->e($lead['name']) . '<br>'
            . '<strong>Компания:</strong> ' . $this->e($lead['company']) . '<br>'
            . '<strong>Email:</strong> ' . $this->e($lead['email']) . '<br>'
            . '<strong>Телефон:</strong> ' . $this->e($lead['phone']) . '<br>'
            . '<strong>Страница:</strong> ' . $this->e($lead['page_url']) . '</p>'
            . '<p>' . nl2br($this->e($lead['comment'])) . '</p>'
            . '<p>Комплект: ' . $this->e((string) $set['per_set']) . ' '
            . $this->e((string) $set['currency'])
            . '. Тираж: ' . $this->e((string) $set['quantity'])
            . '. Сумма: ' . $this->e((string) $set['order_total']) . ' '
            . $this->e((string) $set['currency']) . '.</p>'
            . '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse">'
            . '<tr><th>Артикул</th><th>Товар</th><th>В сете</th><th>Цена</th><th>Ссылка</th></tr>'
            . $rows
            . '</table></div>';

        $mail = $this->mailer();
        if (!$mail) {
            return false;
        }

        $sender = trim((string) $this->modx->getOption('emailsender'));
        $site = trim((string) $this->modx->getOption('site_name', null, 'MODX'));
        $subject = '[Спецификация] ' . ($lead['company'] !== '' ? $lead['company'] : $lead['email']);
        $mailClass = class_exists('MODX\\Revolution\\Mail\\modMail')
            ? 'MODX\\Revolution\\Mail\\modMail'
            : 'modMail';

        $mail->set(constant($mailClass . '::MAIL_BODY'), $html);
        $mail->set(constant($mailClass . '::MAIL_FROM'), $sender);
        $mail->set(constant($mailClass . '::MAIL_FROM_NAME'), $site);
        $mail->set(constant($mailClass . '::MAIL_SENDER'), $sender);
        $mail->set(constant($mailClass . '::MAIL_SUBJECT'), $subject);
        $mail->address('to', $emailTo);
        $mail->address('reply-to', $lead['email']);
        $mail->setHTML(true);
        $sent = (bool) $mail->send();
        if (!$sent) {
            $this->log('Could not send specification email.');
        }
        $mail->reset();

        return $sent;
    }

    private function mailer()
    {
        if (class_exists('MODX\\Revolution\\Mail\\modPHPMailer')) {
            $class = 'MODX\\Revolution\\Mail\\modPHPMailer';
            return new $class($this->modx);
        }

        $mail = $this->modx->getService('mail', 'mail.modPHPMailer');

        return $mail ? $mail : null;
    }

    /**
     * @return array{ok: bool, engine: AiAssistant\Chat\AssistantEngine|null, error: string}
     */
    private function engine()
    {
        $providerName = strtolower(trim($this->option('provider', 'demo')));
        $provider = null;
        if ($providerName !== 'demo' && $providerName !== 'off' && $providerName !== 'none') {
            $config = $this->providerConfig($providerName);
            $missingBase = $providerName === 'compatible' && trim((string) $config['api_base']) === '';
            $missingKey = trim((string) $config['api_key']) === '';
            if ($missingBase || $missingKey) {
                $this->log($missingKey ? 'API key is empty.' : 'API base URL is empty.');
                if (!$this->boolOption('fallback', true)) {
                    return array(
                        'ok' => false,
                        'engine' => null,
                        'error' => $missingKey ? 'Не указан ключ API' : 'Не указан адрес API',
                    );
                }
                $providerName = 'demo';
            } else {
                $provider = AiAssistant\Provider\ProviderFactory::make($config);
                if (!$provider) {
                    return array('ok' => false, 'engine' => null, 'error' => 'Неизвестный провайдер');
                }
            }
        }

        return array(
            'ok' => true,
            'engine' => new AiAssistant\Chat\AssistantEngine($provider, array(
                'provider' => $providerName,
                'currency' => $this->option('currency', '₽'),
                'fallback' => $this->boolOption('fallback', true),
                'rules' => $this->rules(),
                'assistant_name' => $this->option('assistant_name', 'AI-ассистент'),
                'max_messages' => (int) $this->option('max_messages', 12),
                'max_candidates' => (int) $this->option('max_candidates', 40),
                'max_in_set' => (int) $this->option('max_in_set', 4),
                'themes' => $this->themes(),
            )),
            'error' => '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function providerConfig($providerName)
    {
        $cacheDir = rtrim((string) $this->modx->getOption('core_path'), '/\\') . '/cache/aiassistant';

        return array(
            'provider' => $providerName,
            'api_key' => $this->option('api_key', ''),
            'api_base' => $this->option('api_base', ''),
            'model' => $this->option('model', ''),
            'folder_id' => $this->option('folder_id', ''),
            'auth_mode' => $this->option('auth_mode', 'auto'),
            'gigachat_scope' => $this->option('gigachat_scope', 'GIGACHAT_API_PERS'),
            'oauth_url' => $this->option('oauth_url', ''),
            'temperature' => (float) $this->option('temperature', 0.3),
            'max_tokens' => (int) $this->option('max_tokens', 1800),
            'timeout' => (int) $this->option('timeout', 40),
            'ssl_verify' => $this->boolOption('ssl_verify', true),
            'ssl_cainfo' => $this->option('ssl_cainfo', ''),
            'json_mode' => $this->boolOption('json_mode', true),
            'token_cache_path' => $cacheDir . '/gigachat-token.json',
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadFeed()
    {
        $url = trim($this->option('feed_url', ''));
        if (!preg_match('#^https?://#i', $url)) {
            $this->log('Feed URL is empty or not http(s).');
            return array();
        }

        $headers = array('Accept: application/json, application/xml, text/xml, */*');
        $user = trim($this->option('feed_basic_user', ''));
        if ($user !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $this->option('feed_basic_pass', ''));
        }

        $http = new AiAssistant\Provider\HttpClient(array(
            'timeout' => (int) $this->option('timeout', 40),
            'ssl_verify' => $this->boolOption('ssl_verify', true),
            'ssl_cainfo' => $this->option('ssl_cainfo', ''),
        ));
        $response = $http->request('GET', $url, null, $headers);
        if (empty($response['ok'])) {
            $this->log('Feed request failed: ' . (isset($response['error']) ? $response['error'] : 'unknown'));
            return array();
        }
        if (strlen((string) $response['body']) > 8 * 1024 * 1024) {
            $this->log('Feed is larger than 8 MB.');
            return array();
        }

        $parsed = (new AiAssistant\Catalog\FeedParser())->parse(
            (string) $response['body'],
            $this->option('feed_format', 'auto')
        );
        if ($parsed['error'] !== '') {
            $this->log('Feed parse error: ' . $parsed['error']);
        }

        return $parsed['products'];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogConfig()
    {
        return array(
            'parents' => $this->option('parents', ''),
            'depth' => (int) $this->option('depth', 10),
            'context' => $this->option('context', ''),
            'class_key' => $this->option('class_key', 'msProduct'),
            'template' => (int) $this->option('template', 0),
            'tv_article' => $this->option('tv_article', 'article'),
            'tv_price' => $this->option('tv_price', 'price'),
            'tv_color' => $this->option('tv_color', 'color'),
            'tv_image' => $this->option('tv_image', 'image'),
            'option_color' => $this->option('option_color', 'color'),
            'image_base' => $this->option('image_base', ''),
            'include_tvs' => $this->option('include_tvs', ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogSignature()
    {
        return array(
            'source' => $this->option('catalog_source', 'feed'),
            'feed_url' => $this->option('feed_url', ''),
            'feed_format' => $this->option('feed_format', 'auto'),
            'catalog' => $this->catalogConfig(),
        );
    }

    /**
     * @return array<string, string[]>|null
     */
    private function themes()
    {
        $raw = trim($this->option('themes', ''));
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->log('aiassistant.themes is not valid JSON. Default themes are used.');
            return null;
        }

        return $decoded;
    }

    private function rules()
    {
        $name = trim($this->option('rules_chunk', 'aiassistant.rules'));
        if ($name === '') {
            return '';
        }
        $chunk = $this->modx->getObject('modChunk', array('name' => $name));
        if (!$chunk) {
            return '';
        }
        $content = method_exists($chunk, 'getContent')
            ? (string) $chunk->getContent()
            : (string) $chunk->get('snippet');
        $content = trim($content);
        if (function_exists('mb_strlen') && mb_strlen($content, 'UTF-8') > 12000) {
            $content = mb_substr($content, 0, 12000, 'UTF-8');
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function publicResult(array $result)
    {
        $products = array();
        foreach ($result['products'] as $product) {
            $products[] = array(
                'id' => (int) $product['id'],
                'article' => (string) $product['article'],
                'name' => (string) $product['name'],
                'price' => (float) $product['price'],
                'qty' => (int) $product['qty'],
                'url' => (string) $product['url'],
                'image' => (string) $product['image'],
                'color' => (string) $product['color'],
                'category' => (string) $product['category'],
            );
        }

        return array(
            'ok' => true,
            'message' => (string) $result['message'],
            'products' => $products,
            'set' => $result['set'],
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function history($conversationId)
    {
        $this->session();
        $all = $this->sessionBag();
        if (!isset($all[$conversationId]['history']) || !is_array($all[$conversationId]['history'])) {
            return array();
        }

        return $all[$conversationId]['history'];
    }

    private function remember($conversationId, $message, $reply, $pageUrl)
    {
        $this->session();
        if (!isset($_SESSION['aiassistant']) || !is_array($_SESSION['aiassistant'])) {
            $_SESSION['aiassistant'] = array();
        }
        if (!isset($_SESSION['aiassistant'][$conversationId]) || !is_array($_SESSION['aiassistant'][$conversationId])) {
            $_SESSION['aiassistant'][$conversationId] = array('history' => array());
        }
        $history = $_SESSION['aiassistant'][$conversationId]['history'];
        $history[] = array('role' => 'user', 'content' => $message);
        $history[] = array('role' => 'assistant', 'content' => $reply);
        $limit = max(4, min(40, (int) $this->option('max_messages', 12)));
        if (count($history) > $limit) {
            $history = array_slice($history, -$limit);
        }
        $_SESSION['aiassistant'][$conversationId]['history'] = $history;
        $_SESSION['aiassistant'][$conversationId]['page_url'] = $pageUrl;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionBag()
    {
        if (!isset($_SESSION['aiassistant']) || !is_array($_SESSION['aiassistant'])) {
            return array();
        }

        return $_SESSION['aiassistant'];
    }

    private function limited()
    {
        $this->session();
        $limit = (int) $this->option('rate_limit', 12);
        if ($limit < 1) {
            $limit = 12;
        }
        $now = time();
        if (!isset($_SESSION['aiassistant_rate']) || !is_array($_SESSION['aiassistant_rate'])) {
            $_SESSION['aiassistant_rate'] = array();
        }
        $fresh = array();
        foreach ($_SESSION['aiassistant_rate'] as $timestamp) {
            if (is_int($timestamp) && $timestamp > ($now - 60)) {
                $fresh[] = $timestamp;
            }
        }
        if (count($fresh) >= $limit) {
            $_SESSION['aiassistant_rate'] = $fresh;
            return true;
        }
        $fresh[] = $now;
        $_SESSION['aiassistant_rate'] = $fresh;

        return false;
    }

    private function session()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!headers_sent()) {
            session_start();
        }
    }

    private function enabled()
    {
        return $this->boolOption('enabled', true);
    }

    private function visibleOnResource()
    {
        if (!$this->modx->resource) {
            return true;
        }
        $template = (int) $this->modx->resource->get('template');
        $show = $this->idList('show_templates');
        $hide = $this->idList('hide_templates');
        if ($show && !in_array($template, $show, true)) {
            return false;
        }
        if ($hide && in_array($template, $hide, true)) {
            return false;
        }

        return true;
    }

    /**
     * @return int[]
     */
    private function idList($key)
    {
        $ids = array();
        foreach (explode(',', $this->option($key, '')) as $id) {
            $id = (int) trim($id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<int, array<string, mixed>>|null $products
     * @return array<int, array<string, mixed>>|null
     */
    private function readCache($signature, $ttl)
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['signature']) || $data['signature'] !== $signature) {
            return null;
        }
        if (!isset($data['fetched_at']) || (time() - (int) $data['fetched_at']) > $ttl) {
            return null;
        }
        if (!isset($data['products']) || !is_array($data['products'])) {
            return null;
        }

        return $data['products'];
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function writeCache($signature, array $products)
    {
        $path = $this->cachePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }
        $encoded = json_encode(array(
            'signature' => $signature,
            'fetched_at' => time(),
            'products' => $products,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            file_put_contents($path, $encoded, LOCK_EX);
        }
    }

    private function cachePath()
    {
        return rtrim((string) $this->modx->getOption('core_path'), '/\\') . '/cache/aiassistant/catalog.json';
    }

    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $result
     */
    private function writeSpecLog(array $lead, array $result, $sent)
    {
        if (!$this->boolOption('log_enabled', true)) {
            return;
        }
        $dir = rtrim((string) $this->modx->getOption('core_path'), '/\\') . '/cache/aiassistant/specs';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }
        $row = array(
            'datetime' => gmdate('c'),
            'email_sent' => (bool) $sent,
            'lead' => $lead,
            'set' => $result['set'],
            'products' => $result['products'],
        );
        $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            file_put_contents($dir . '/' . gmdate('Y-m') . '-specs.jsonl', $encoded . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function validConversationId($value)
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{16,80}$/', (string) $value);
    }

    private function clip($value, $limit)
    {
        $value = trim((string) $value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
    }

    private function option($key, $default)
    {
        $value = $this->modx->getOption('aiassistant.' . $key, null, $default);
        if ($value === null) {
            return $default;
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function boolOption($key, $default)
    {
        $value = $this->option($key, $default ? '1' : '0');
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), array('1', 'true', 'yes', 'on'), true);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function respond(array $body, $status)
    {
        return array(
            'status' => (int) $status,
            'body' => $body,
        );
    }

    private function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private function log($message)
    {
        $message = '[aiassistant] ' . $message;
        if (isset($this->modx) && method_exists($this->modx, 'log')) {
            $level = defined('modX::LOG_LEVEL_ERROR') ? constant('modX::LOG_LEVEL_ERROR') : 1;
            $this->modx->log($level, $message);
            return;
        }
        error_log($message);
    }
}
