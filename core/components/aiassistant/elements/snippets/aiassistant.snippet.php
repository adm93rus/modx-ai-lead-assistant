<?php
/**
 * Renders the catalog AI assistant widget.
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
    return '';
}

$modx->setPlaceholder('aiassistant.rendered', '1');

return $assistant->getWidgetHtml();
