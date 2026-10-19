<?php

namespace AiAssistant\Provider;

class OpenAiProvider implements ProviderInterface
{
    /** @var HttpClient */
    private $http;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $base;

    /** @var string */
    private $model;

    /** @var float */
    private $temperature;

    /** @var int */
    private $maxTokens;

    /** @var bool */
    private $jsonMode;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(HttpClient $http, array $config)
    {
        $this->http = $http;
        $this->apiKey = trim((string) (isset($config['api_key']) ? $config['api_key'] : ''));
        $base = trim((string) (isset($config['api_base']) ? $config['api_base'] : ''));
        if ($base === '' && empty($config['require_base'])) {
            $base = 'https://api.openai.com/v1';
        }
        $this->base = rtrim($base, '/');
        $model = trim((string) (isset($config['model']) ? $config['model'] : ''));
        $this->model = $model !== '' ? $model : 'gpt-4o-mini';
        $this->temperature = isset($config['temperature']) ? (float) $config['temperature'] : 0.3;
        $this->maxTokens = isset($config['max_tokens']) ? (int) $config['max_tokens'] : 1800;
        $this->jsonMode = !isset($config['json_mode']) || HttpClient::bool($config['json_mode']);
    }

    public function complete(array $messages)
    {
        if ($this->base === '') {
            return $this->fail('API base URL is empty', 0);
        }
        if ($this->apiKey === '') {
            return $this->fail('API key is empty', 0);
        }

        $payload = array(
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->mapMessages($messages),
        );
        if ($this->jsonMode) {
            $payload['response_format'] = array('type' => 'json_object');
        }

        $result = $this->send($payload);
        if (!$result['ok'] && $this->jsonMode && $result['status'] === 400) {
            unset($payload['response_format']);
            $result = $this->send($payload);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, content: string, error: string, status: int}
     */
    private function send(array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return $this->fail('Could not encode OpenAI request', 0);
        }

        $response = $this->http->request(
            'POST',
            $this->base . '/chat/completions',
            $body,
            array(
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            )
        );

        if (empty($response['ok'])) {
            return $this->fail(isset($response['error']) ? (string) $response['error'] : 'OpenAI request failed', (int) $response['status']);
        }

        $content = $this->content(isset($response['json']) ? $response['json'] : null);
        if ($content === '') {
            return $this->fail('OpenAI returned an empty response', (int) $response['status']);
        }

        return array(
            'ok' => true,
            'content' => $content,
            'error' => '',
            'status' => (int) $response['status'],
        );
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function content($json)
    {
        if (!is_array($json) || empty($json['choices'][0]['message'])) {
            return '';
        }

        $message = $json['choices'][0]['message'];
        if (!is_array($message)) {
            return '';
        }

        $content = isset($message['content']) ? $message['content'] : '';
        if (is_string($content)) {
            return trim($content);
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = array();
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
                continue;
            }
            if (is_array($part) && isset($part['text'])) {
                $parts[] = (string) $part['text'];
            }
        }

        return trim(implode("\n", $parts));
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
