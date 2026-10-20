<?php

namespace AiAssistant\Provider;

class YandexGptProvider implements ProviderInterface
{
    /** @var HttpClient */
    private $http;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $folderId;

    /** @var string */
    private $model;

    /** @var string */
    private $endpoint;

    /** @var string */
    private $authMode;

    /** @var float */
    private $temperature;

    /** @var int */
    private $maxTokens;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(HttpClient $http, array $config)
    {
        $this->http = $http;
        $this->apiKey = trim((string) (isset($config['api_key']) ? $config['api_key'] : ''));
        $this->folderId = trim((string) (isset($config['folder_id']) ? $config['folder_id'] : ''));
        $model = trim((string) (isset($config['model']) ? $config['model'] : ''));
        $this->model = $model !== '' ? $model : 'yandexgpt/latest';
        $endpoint = trim((string) (isset($config['api_base']) ? $config['api_base'] : ''));
        $this->endpoint = $endpoint !== ''
            ? $endpoint
            : 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';
        $mode = strtolower(trim((string) (isset($config['auth_mode']) ? $config['auth_mode'] : 'auto')));
        $this->authMode = in_array($mode, array('auto', 'api_key', 'bearer'), true) ? $mode : 'auto';
        $this->temperature = isset($config['temperature']) ? (float) $config['temperature'] : 0.3;
        $this->maxTokens = isset($config['max_tokens']) ? (int) $config['max_tokens'] : 1800;
    }

    public function complete(array $messages)
    {
        if ($this->apiKey === '') {
            return $this->fail('YandexGPT API key is empty', 0);
        }

        $modelUri = $this->modelUri();
        if ($modelUri === '') {
            return $this->fail('YandexGPT folder id is empty', 0);
        }

        $payload = array(
            'modelUri' => $modelUri,
            'completionOptions' => array(
                'stream' => false,
                'temperature' => $this->temperature,
                'maxTokens' => (string) max(200, $this->maxTokens),
            ),
            'messages' => $this->mapMessages($messages),
        );

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return $this->fail('Could not encode YandexGPT request', 0);
        }

        $response = $this->http->request(
            'POST',
            $this->endpoint,
            $body,
            array(
                'Authorization: ' . $this->authorization(),
                'Content-Type: application/json',
                'Accept: application/json',
                'x-data-logging-enabled: false',
            )
        );

        if (empty($response['ok'])) {
            return $this->fail(isset($response['error']) ? (string) $response['error'] : 'YandexGPT request failed', (int) $response['status']);
        }

        $json = isset($response['json']) ? $response['json'] : null;
        $content = '';
        if (is_array($json) && isset($json['result']['alternatives'][0]['message']['text'])) {
            $content = trim((string) $json['result']['alternatives'][0]['message']['text']);
        }

        if ($content === '') {
            return $this->fail('YandexGPT returned an empty response', (int) $response['status']);
        }

        return array(
            'ok' => true,
            'content' => $content,
            'error' => '',
            'status' => (int) $response['status'],
        );
    }

    private function modelUri()
    {
        if (strpos($this->model, 'gpt://') === 0) {
            return $this->model;
        }
        if ($this->folderId === '') {
            return '';
        }

        return 'gpt://' . $this->folderId . '/' . ltrim($this->model, '/');
    }

    private function authorization()
    {
        if ($this->authMode === 'bearer' || ($this->authMode === 'auto' && substr_count($this->apiKey, '.') >= 2)) {
            return 'Bearer ' . $this->apiKey;
        }

        return 'Api-Key ' . $this->apiKey;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array{role: string, text: string}>
     */
    private function mapMessages(array $messages)
    {
        $system = array();
        $dialog = array();

        foreach ($messages as $message) {
            $role = isset($message['role']) ? (string) $message['role'] : 'user';
            $content = trim((string) (isset($message['content']) ? $message['content'] : ''));
            if ($content === '') {
                continue;
            }
            if ($role === 'system') {
                $system[] = $content;
                continue;
            }
            if ($role !== 'assistant') {
                $role = 'user';
            }
            $dialog[] = array(
                'role' => $role,
                'text' => $content,
            );
        }

        $mapped = array();
        if ($system) {
            $mapped[] = array(
                'role' => 'system',
                'text' => implode("\n\n", $system),
            );
        }

        return array_merge($mapped, $dialog);
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
