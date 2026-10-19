<?php
/**
 * Injects the catalog assistant into public HTML pages.
 *
 * @var modX $modx
 */
if ($modx->event->name !== 'OnWebPagePrerender') {
    return;
}

$contextKey = $modx->context ? (string) $modx->context->get('key') : '';
if ($contextKey === '' || $contextKey === 'mgr') {
    return;
}

if ($modx->getPlaceholder('aiassistant.rendered')) {
    return;
}

if (!$modx->resource || !is_string($modx->resource->_output)) {
    return;
}

$contentType = $modx->resource->getOne('ContentType');
if ($contentType && stripos((string) $contentType->get('mime_type'), 'html') === false) {
    return;
}

$corePath = $modx->getOption(
    'aiassistant.core_path',
    null,
    $modx->getOption('core_path') . 'components/aiassistant/'
);
/** @var AiAssistant|null $assistant */
$assistant = $modx->getService('aiassistant', 'AiAssistant', $corePath . 'model/aiassistant/');
if (!$assistant) {
    return;
}

$html = $assistant->getWidgetHtml();
if ($html === '') {
    return;
}

$output = $modx->resource->_output;
$position = strripos($output, '</body>');
if ($position === false) {
    return;
}

$modx->resource->_output = substr($output, 0, $position) . $html . substr($output, $position);
$modx->setPlaceholder('aiassistant.rendered', '1');
