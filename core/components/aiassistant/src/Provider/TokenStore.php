<?php

namespace AiAssistant\Provider;

interface TokenStore
{
    /**
     * @param string $key
     * @return string
     */
    public function get($key);

    /**
     * @param string $key
     * @param string $value
     * @param int $expiresAt unix time
     */
    public function set($key, $value, $expiresAt);
}
