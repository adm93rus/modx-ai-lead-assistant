<?php

/** @var modX $modx */

function aiassistantBuildSnippet($modx, $name, $description, $file)
{
    $snippet = $modx->newObject('modSnippet');
    $snippet->fromArray(array(
        'name' => $name,
        'description' => $description,
        'snippet' => aiassistantElementCode($file),
    ), '', true, true);

    return $snippet;
}

function aiassistantElementCode($file)
{
    $root = dirname(__DIR__, 2);
    $content = file_get_contents($root . '/core/components/aiassistant/elements/' . $file);
    if (!is_string($content)) {
        throw new RuntimeException('Missing element file: ' . $file);
    }

    return preg_replace('/^<\?php\s*/', '', $content, 1);
}

return array(
    aiassistantBuildSnippet($modx, 'aiAssistant', 'Плавающий AI-ассистент подбора сетов', 'snippets/aiassistant.snippet.php'),
    aiassistantBuildSnippet($modx, 'aiAssistantFeed', 'JSON-фид каталога для AI-ассистента', 'snippets/aiassistantfeed.snippet.php'),
);
