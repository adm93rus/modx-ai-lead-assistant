<?php

namespace AiAssistant\Provider;

class HttpClient
{
    /** @var int */
    private $timeout;

    /** @var int */
    private $connectTimeout;

    /** @var bool */
    private $sslVerify;

    /** @var string */
    private $caInfo;

    /** @var callable|null */
    private $handler;

    /**
     * @param array<string, mixed> $config
     * @param callable|null $handler
     */
    public function __construct(array $config = array(), $handler = null)
    {
        $this->timeout = isset($config['timeout']) ? (int) $config['timeout'] : 40;
        if ($this->timeout < 5) {
            $this->timeout = 5;
        }
        if ($this->timeout > 90) {
            $this->timeout = 90;
        }
        $this->connectTimeout = isset($config['connect_timeout']) ? (int) $config['connect_timeout'] : 8;
        $this->sslVerify = !isset($config['ssl_verify']) || self::bool($config['ssl_verify']);
        $this->caInfo = isset($config['ssl_cainfo']) ? trim((string) $config['ssl_cainfo']) : '';
        $this->handler = is_callable($handler) ? $handler : null;
    }

    /**
     * @param string $method
     * @param string $url
     * @param string|null $body
     * @param string[] $headers
     * @return array{ok: bool, status: int, body: string, json: array<string, mixed>|null, error: string}
     */
    public function request($method, $url, $body, array $headers)
    {
        if ($this->handler !== null) {
            $handler = $this->handler;
            $result = $handler($method, $url, $body, $headers);
            if (!is_array($result)) {
                return $this->failure(0, '', 'Invalid HTTP handler response');
            }

            return $result;
        }

        if (!function_exists('curl_init')) {
            return $this->failure(0, '', 'PHP cURL extension is not available');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return $this->failure(0, '', 'Could not init HTTP client');
        }

        $options = array(
            CURLOPT_CUSTOMREQUEST => strtoupper((string) $method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'ModxAiAssistant/1.0',
        );

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        if ($this->sslVerify) {
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
            if ($this->caInfo !== '' && is_file($this->caInfo)) {
                $options[CURLOPT_CAINFO] = $this->caInfo;
            }
        } else {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false) {
            return $this->failure($status, '', $error !== '' ? $error : 'HTTP request failed');
        }

        return $this->decode($status, (string) $result);
    }

    /**
     * @return array{ok: bool, status: int, body: string, json: array<string, mixed>|null, error: string}
     */
    private function decode($status, $body)
    {
        $json = json_decode($body, true);
        $ok = $status >= 200 && $status < 300;

        return array(
            'ok' => $ok,
            'status' => $status,
            'body' => $body,
            'json' => is_array($json) ? $json : null,
            'error' => $ok ? '' : $this->errorFrom($json, $status),
        );
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function errorFrom($json, $status)
    {
        if (is_array($json)) {
            if (isset($json['error']) && is_array($json['error']) && isset($json['error']['message'])) {
                return (string) $json['error']['message'];
            }
            if (isset($json['error']) && is_string($json['error'])) {
                return $json['error'];
            }
            if (isset($json['message']) && is_string($json['message'])) {
                return $json['message'];
            }
        }

        return 'HTTP ' . (int) $status;
    }

    /**
     * @return array{ok: bool, status: int, body: string, json: array<string, mixed>|null, error: string}
     */
    private function failure($status, $body, $error)
    {
        return array(
            'ok' => false,
            'status' => (int) $status,
            'body' => (string) $body,
            'json' => null,
            'error' => (string) $error,
        );
    }

    /**
     * @param mixed $value
     */
    public static function bool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), array('1', 'true', 'yes', 'on'), true);
    }
}
