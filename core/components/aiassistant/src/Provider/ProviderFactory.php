<?php

namespace AiAssistant\Provider;

class ProviderFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function make(array $config, HttpClient $http = null, TokenStore $tokens = null)
    {
        if ($http === null) {
            $http = new HttpClient($config);
        }

        $name = strtolower(trim((string) (isset($config['provider']) ? $config['provider'] : 'demo')));
        if ($name === 'openai') {
            return new OpenAiProvider($http, $config);
        }
        if ($name === 'yandex' || $name === 'yandexgpt') {
            return new YandexGptProvider($http, $config);
        }
        if ($name === 'gigachat') {
            if ($tokens === null) {
                $path = isset($config['token_cache_path']) ? trim((string) $config['token_cache_path']) : '';
                $tokens = $path !== '' ? new FileTokenStore($path) : new MemoryTokenStore();
            }

            return new GigaChatProvider($http, $tokens, $config);
        }
        if ($name === 'compatible' || $name === 'openai_compatible') {
            return new CompatibleProvider($http, $config);
        }

        return null;
    }
}
