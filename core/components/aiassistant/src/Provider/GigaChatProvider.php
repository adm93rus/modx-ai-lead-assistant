<?php

namespace AiAssistant\Provider;

class GigaChatProvider implements ProviderInterface
{
    /** @var HttpClient */
    private $http;

    /** @var TokenStore */
    private $tokens;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $scope;

    /** @var string */
    private $model;

    /** @var string */
    private $base;

    /** @var string */
    private $oauthUrl;

    /** @var float */
    private $temperature;

    /** @var int */
    private $maxTokens;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(HttpClient $http, TokenStore $tokens, array $config)
    {
        $this->http = $http;
        $this->tokens = $tokens;
        $this->apiKey = trim((string) (isset($config['api_key']) ? $config['api_key'] : ''));
        $scope = trim((string) (isset($config['gigachat_scope']) ? $config['gigachat_scope'] : ''));
        $this->scope = $scope !== '' ? $scope : 'GIGACHAT_API_PERS';
        $model = trim((string) (isset($config['model']) ? $config['model'] : ''));
        $this->model = $model !== '' ? $model : 'GigaChat';
        $base = trim((string) (isset($config['api_base']) ? $config['api_base'] : ''));
        $this->base = rtrim($base !== '' ? $base : 'https://gigachat.devices.sberbank.ru/api/v1', '/');
        $oauth = trim((string) (isset($config['oauth_url']) ? $config['oauth_url'] : ''));
        $this->oauthUrl = $oauth !== '' ? $oauth : 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth';
        $this->temperature = isset($config['temperature']) ? (float) $config['temperature'] : 0.3;
        $this->maxTokens = isset($config['max_tokens']) ? (int) $config['max_tokens'] : 1800;
    }

    public function complete(array $messages)
    {
        if ($this->apiKey === '') {
            return $this->fail('GigaChat authorization key is empty', 0);
        }

        $token = $this->token();
        if ($token === '') {
            return $this->fail('Could not obtain a GigaChat token', 0);
        }

        $payload = array(
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->mapMessages($messages),
        );
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return $this->fail('Could not encode GigaChat request', 0);
        }

        $response = $this->http->request(
            'POST',
            $this->base . '/chat/completions',
            $body,
            array(
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            )
        );

        if (empty($response['ok'])) {
            return $this->fail(isset($response['error']) ? (string) $response['error'] : 'GigaChat request failed', (int) $response['status']);
        }

        $json = isset($response['json']) ? $response['json'] : null;
        $content = '';
        if (is_array($json) && isset($json['choices'][0]['message']['content'])) {
            $content = trim((string) $json['choices'][0]['message']['content']);
        }
        if ($content === '') {
            return $this->fail('GigaChat returned an empty response', (int) $response['status']);
        }

        return array(
            'ok' => true,
            'content' => $content,
            'error' => '',
            'status' => (int) $response['status'],
        );
    }

    private function token()
    {
        $cached = $this->tokens->get('gigachat');
        if ($cached !== '') {
            return $cached;
        }

        $response = $this->http->request(
            'POST',
            $this->oauthUrl,
            'scope=' . rawurlencode($this->scope),
            array(
                'Authorization: Basic ' . $this->apiKey,
                'RqUID: ' . $this->uuid(),
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            )
        );

        $json = isset($response['json']) ? $response['json'] : null;
        if (empty($response['ok']) || !is_array($json) || empty($json['access_token'])) {
            return '';
        }

        $token = (string) $json['access_token'];
        $expires = isset($json['expires_at']) ? (int) $json['expires_at'] : (time() + 1500);
        if ($expires > 20000000000) {
            $expires = (int) floor($expires / 1000);
        }
        $this->tokens->set('gigachat', $token, $expires - 60);

        return $token;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function mapMessages(array $messages)
    {
        $mapped = array();
        foreach ($messages as $message) {
            $role = isset($message['role']) ? (string) $message['role'] : 'user';
            if (!in_array($role, array('system', 'user', 'assistant'), true)) {
                $role = 'user';
            }
            $mapped[] = array(
                'role' => $role,
                'content' => (string) $message['content'],
            );
        }

        return $mapped;
    }

    private function uuid()
    {
        $data = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @return array{ok: bool, content: string, error: string, status: int}
     */
    private function fail($error, $status)
    {
        return array(
            'ok' => false,
            'content' => '',
            'error' => (string) $error,
            'status' => (int) $status,
        );
    }
}
