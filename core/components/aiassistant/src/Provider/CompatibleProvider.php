<?php

namespace AiAssistant\Provider;

/**
 * OpenAI-compatible Chat Completions: Groq, Yandex OpenAI mode, local gateways.
 */
class CompatibleProvider extends OpenAiProvider
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(HttpClient $http, array $config)
    {
        $config['require_base'] = true;
        parent::__construct($http, $config);
    }

    public function complete(array $messages)
    {
        return parent::complete($messages);
    }
}
