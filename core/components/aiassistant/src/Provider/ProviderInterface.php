<?php

namespace AiAssistant\Provider;

interface ProviderInterface
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{ok: bool, content: string, error: string, status: int}
     */
    public function complete(array $messages);
}
