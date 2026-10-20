<?php

require dirname(__DIR__) . '/core/components/aiassistant/bootstrap.php';

$testFailed = 0;
$testCount = 0;

function test_assert($condition, $message)
{
    global $testFailed, $testCount;
    $testCount++;
    if (!$condition) {
        $testFailed++;
        echo "FAIL: {$message}\n";
    }
}

function test_same($actual, $expected, $message)
{
    test_assert($actual === $expected, $message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
}

function test_catalog()
{
    $raw = file_get_contents(dirname(__DIR__) . '/assets/components/aiassistant/sample/catalog.sample.json');
    $parsed = (new AiAssistant\Catalog\FeedParser())->parse($raw, 'json');

    return $parsed['products'];
}
