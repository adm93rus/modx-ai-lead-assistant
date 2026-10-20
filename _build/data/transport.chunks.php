<?php

/** @var modX $modx */

$chunk = $modx->newObject('modChunk');
$content = file_get_contents(dirname(__DIR__, 2) . '/core/components/aiassistant/elements/chunks/aiassistant.rules.tpl');
$chunk->fromArray(array(
    'name' => 'aiassistant.rules',
    'description' => 'Правила подбора сетов для промпта',
    'snippet' => is_string($content) ? $content : '',
), '', true, true);

return array($chunk);
