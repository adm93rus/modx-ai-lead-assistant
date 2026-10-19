<?php
/**
 * JSON catalog export. Put it in an empty template with content type application/json:
 * [[!aiAssistantFeed]]
 *
 * @var modX $modx
 */
$corePath = $modx->getOption(
    'aiassistant.core_path',
    null,
    $modx->getOption('core_path') . 'components/aiassistant/'
);
/** @var AiAssistant|null $assistant */
$assistant = $modx->getService('aiassistant', 'AiAssistant', $corePath . 'model/aiassistant/');
if (!$assistant) {
    return '[]';
}

$json = json_encode(
    $assistant->loadCatalog(),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

return is_string($json) ? $json : '[]';
