<?php

use AiAssistant\Provider\ProviderInterface;

class RecordingProvider implements ProviderInterface
{
    public $calls = array();
    private $content;
    private $ok;
    private $error;

    public function __construct($content, $ok = true, $error = '')
    {
        $this->content = $content;
        $this->ok = $ok;
        $this->error = $error;
    }

    public function complete(array $messages)
    {
        $this->calls[] = $messages;

        return array(
            'ok' => $this->ok,
            'content' => $this->content,
            'error' => $this->error,
            'status' => $this->ok ? 200 : 502,
        );
    }
}
