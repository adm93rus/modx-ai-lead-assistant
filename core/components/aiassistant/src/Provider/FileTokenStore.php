<?php

namespace AiAssistant\Provider;

class FileTokenStore implements TokenStore
{
    /** @var string */
    private $path;

    public function __construct($path)
    {
        $this->path = (string) $path;
    }

    public function get($key)
    {
        $data = $this->read();
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return '';
        }
        $expires = isset($data[$key]['expires_at']) ? (int) $data[$key]['expires_at'] : 0;
        if ($expires <= time()) {
            return '';
        }

        return isset($data[$key]['value']) ? (string) $data[$key]['value'] : '';
    }

    public function set($key, $value, $expiresAt)
    {
        $data = $this->read();
        $data[$key] = array(
            'value' => (string) $value,
            'expires_at' => (int) $expiresAt,
        );
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }

        $encoded = json_encode($data);
        if (!is_string($encoded)) {
            return;
        }

        file_put_contents($this->path, $encoded, LOCK_EX);
        @chmod($this->path, 0600);
    }

    /**
     * @return array<string, mixed>
     */
    private function read()
    {
        if (!is_file($this->path)) {
            return array();
        }
        $raw = file_get_contents($this->path);
        if (!is_string($raw) || $raw === '') {
            return array();
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : array();
    }
}
