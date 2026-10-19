<?php

namespace AiAssistant\Provider;

class MemoryTokenStore implements TokenStore
{
    /** @var array<string, array{value: string, expires_at: int}> */
    private $items = array();

    public function get($key)
    {
        if (!isset($this->items[$key])) {
            return '';
        }
        if ($this->items[$key]['expires_at'] <= time()) {
            unset($this->items[$key]);
            return '';
        }

        return $this->items[$key]['value'];
    }

    public function set($key, $value, $expiresAt)
    {
        $this->items[$key] = array(
            'value' => (string) $value,
            'expires_at' => (int) $expiresAt,
        );
    }
}
