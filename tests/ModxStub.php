<?php

/**
 * Tiny stand-in for the MODX user and option bag used by the assistant service.
 */
class AiAssistantTestModx
{
    /** @var AiAssistantTestUser */
    public $user;

    /** @var null */
    public $resource = null;

    /** @var AiAssistantTestContext */
    public $context;

    /** @var array<string, mixed> */
    public $options;

    /** @var array<int, array{0: int, 1: string}> */
    public $logs = array();

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options, $managerLoggedIn)
    {
        $this->options = $options;
        $this->user = new AiAssistantTestUser($managerLoggedIn);
        $this->context = new AiAssistantTestContext();
    }

    public function getOption($key, $options = null, $default = null)
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        return $default;
    }

    public function getObject($class, $criteria = null)
    {
        return null;
    }

    public function log($level, $message)
    {
        $this->logs[] = array((int) $level, (string) $message);
    }
}

class AiAssistantTestUser
{
    /** @var bool */
    private $manager;

    public function __construct($managerLoggedIn)
    {
        $this->manager = (bool) $managerLoggedIn;
    }

    public function hasSessionContext($context)
    {
        return $context === 'mgr' && $this->manager;
    }

    public function get($key)
    {
        if ($key === 'id') {
            return $this->manager ? 1 : 0;
        }

        return '';
    }
}

class AiAssistantTestContext
{
    public function get($key)
    {
        return $key === 'key' ? 'web' : '';
    }
}
