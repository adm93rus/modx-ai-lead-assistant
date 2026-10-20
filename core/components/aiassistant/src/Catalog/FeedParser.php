<?php

namespace AiAssistant\Catalog;

use AiAssistant\Support\Text;

/**
 * Normalizes a JSON, XML or Yandex Market YML product feed into a flat catalog.
 */
class FeedParser
{
    /**
     * @param string $raw
     * @param string $format auto|json|xml|yml
     * @return array{products: array<int, array<string, mixed>>, error: string}
     */
    public function parse($raw, $format = 'auto')
    {
        $raw = $this->toUtf8((string) $raw);
        $format = strtolower(trim((string) $format));
        if ($format === '' || $format === 'auto') {
            $format = $this->detect($raw);
        }

        if ($format === 'json') {
            return $this->parseJson($raw);
        }

        if ($format === 'xml' || $format === 'yml') {
            return $this->parseXml($raw);
        }

        return array(
            'products' => array(),
            'error' => 'Unknown feed format',
        );
    }

    private function detect($raw)
    {
        $sample = ltrim(substr($raw, 0, 400));
        if ($sample === '') {
            return 'json';
        }

        if ($sample[0] === '<' || stripos($sample, '<?xml') === 0) {
            return 'xml';
        }

        return 'json';
    }

    private function toUtf8($raw)
    {
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }

        $head = substr($raw, 0, 500);
        if (preg_match('/encoding=["\']([^"\']+)["\']/i', $head, $matches)) {
            $encoding = strtoupper(trim($matches[1]));
            if ($encoding !== 'UTF-8' && $encoding !== 'UTF8' && function_exists('iconv')) {
                $converted = @iconv($encoding, 'UTF-8//IGNORE', $raw);
                if (is_string($converted) && $converted !== '') {
                    $raw = preg_replace('/encoding=["\'][^"\']+["\']/i', 'encoding="UTF-8"', $converted, 1);
                    if (!is_string($raw)) {
                        $raw = $converted;
                    }
                }
            }
        }

        return $raw;
    }

    /**
     * @return array{products: array<int, array<string, mixed>>, error: string}
     */
    private function parseJson($raw)
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return array(
                'products' => array(),
                'error' => 'Feed JSON is invalid',
            );
        }

        $list = $this->findList($data);
        $products = array();
        $seen = array();

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $product = $this->normalizeRow($row);
            if ($product === null) {
                continue;
            }
            $key = Text::articleKey($product['article']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $products[] = $product;
        }

        return array(
            'products' => $products,
            'error' => '',
        );
    }

    /**
     * @param array<mixed> $data
     * @return array<int, mixed>
     */
    private function findList(array $data)
    {
        if ($this->isList($data)) {
            return $data;
        }

        $keys = array('products', 'items', 'offers', 'goods', 'catalog', 'data');
        foreach ($keys as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                continue;
            }
            if ($this->isList($data[$key])) {
                return $data[$key];
            }
            $nested = $this->findList($data[$key]);
            if ($nested) {
                return $nested;
            }
        }

        return array();
    }

    /**
     * @param array<mixed> $data
     */
    private function isList(array $data)
    {
        if ($data === array()) {
            return true;
        }

        $index = 0;
        foreach (array_keys($data) as $key) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }

        return true;
    }

    /**
     * @return array{products: array<int, array<string, mixed>>, error: string}
     */
    private function parseXml($raw)
    {
        if (!function_exists('simplexml_load_string')) {
            return array(
                'products' => array(),
                'error' => 'SimpleXML is not available',
            );
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$xml instanceof \SimpleXMLElement) {
            return array(
                'products' => array(),
                'error' => 'Feed XML is invalid',
            );
        }

        $categories = $this->categories($xml);
        $nodes = $xml->xpath('//offer | //product | //item | //товар');
        if (!is_array($nodes) || !$nodes) {
            $nodes = array($xml);
        }

        $products = array();
        $seen = array();

        foreach ($nodes as $node) {
            if (!$node instanceof \SimpleXMLElement) {
                continue;
            }
            $name = strtolower($node->getName());
            if (in_array($name, array('yml_catalog', 'catalog', 'shop', 'offers', 'categories'), true)) {
                continue;
            }

            $available = strtolower(trim((string) $node['available']));
            if ($available === 'false' || $available === '0') {
                continue;
            }

            $product = $this->normalizeRow($this->elementToRow($node), $categories);
            if ($product === null) {
                continue;
            }
            $key = Text::articleKey($product['article']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $products[] = $product;
        }

        return array(
            'products' => $products,
            'error' => '',
        );
    }

    /**
     * @return array<string, string>
     */
    private function categories(\SimpleXMLElement $xml)
    {
        $categories = array();
        $nodes = $xml->xpath('//category');
        if (!is_array($nodes)) {
            return $categories;
        }

        foreach ($nodes as $node) {
            $id = trim((string) $node['id']);
            $title = Text::strip((string) $node);
            if ($id !== '' && $title !== '') {
                $categories[$id] = $title;
            }
        }

        return $categories;
    }

    /**
     * @return array<string, mixed>
     */
    private function elementToRow(\SimpleXMLElement $element)
    {
        $row = array();

        foreach ($element->attributes() as $name => $value) {
            $row[(string) $name] = (string) $value;
        }

        foreach ($element->children() as $child) {
            if (!$child instanceof \SimpleXMLElement) {
                continue;
            }
            $name = $child->getName();
            if ($name === 'param') {
                $paramName = trim((string) $child['name']);
                if ($paramName !== '') {
                    if (!isset($row['params']) || !is_array($row['params'])) {
                        $row['params'] = array();
                    }
                    $row['params'][$paramName] = trim((string) $child);
                }
                continue;
            }

            $text = trim((string) $child);
            if (!isset($row[$name])) {
                $row[$name] = $text;
                continue;
            }

            if (!is_array($row[$name])) {
                $row[$name] = array($row[$name]);
            }
            $row[$name][] = $text;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $categories
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row, array $categories = array())
    {
        $article = $this->firstString($row, array(
            'article', 'sku', 'articul', 'vendorCode', 'vendor_code', 'code', 'art',
        ));
        $explicitId = trim((string) (isset($row['id']) ? $row['id'] : ''));
        if ($article === '' && $explicitId !== '') {
            $article = $explicitId;
        }

        $name = $this->firstString($row, array('name', 'title', 'pagetitle', 'model'));
        if ($article === '' || $name === '') {
            return null;
        }

        $attributes = $this->attributes($row);
        $color = $this->firstString($row, array('color', 'colour', 'цвет'));
        if ($color === '') {
            $color = $this->attributeValue($attributes, array('цвет', 'color', 'colour'));
        }

        $category = $this->firstString($row, array(
            'category', 'category_name', 'categoryName', 'group',
        ));
        $categoryId = trim((string) (isset($row['categoryId']) ? $row['categoryId'] : ''));
        if ($category === '' && $categoryId !== '' && isset($categories[$categoryId])) {
            $category = $categories[$categoryId];
        }

        $description = Text::truncate(Text::strip($this->firstString($row, array(
            'description', 'desc', 'content', 'preview_text',
        ))), 400);

        return array(
            'id' => $this->resourceId($row, $article),
            'article' => $article,
            'name' => Text::strip($name),
            'price' => Text::money($this->firstRaw($row, array('price', 'cost', 'retail_price'))),
            'color' => Text::strip($color),
            'category' => Text::strip($category),
            'url' => Text::safeUrl($this->firstString($row, array('url', 'link', 'href'))),
            'image' => Text::safeUrl($this->firstString($row, array('image', 'picture', 'img', 'thumb', 'preview'))),
            'description' => $description,
            'attributes' => $attributes,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resourceId(array $row, $article)
    {
        foreach (array('resource_id', 'modx_id', 'product_id', 'ms2_id') as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $value = trim((string) $row[$key]);
            if (preg_match('/^\d+$/', $value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        if (!isset($row['id'])) {
            return 0;
        }

        $value = trim((string) $row['id']);
        if (!preg_match('/^\d+$/', $value)) {
            return 0;
        }

        if (Text::articleKey($article) === Text::articleKey($value)) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     * @param string[] $keys
     */
    private function firstRaw(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if (is_array($value)) {
                $value = reset($value);
            }
            if ($value === null || $value === '') {
                continue;
            }

            return $value;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @param string[] $keys
     */
    private function firstString(array $row, array $keys)
    {
        $value = $this->firstRaw($row, $keys);
        if (is_array($value)) {
            $value = reset($value);
        }

        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function attributes(array $row)
    {
        $attributes = array();
        $bags = array('params', 'param', 'characteristics', 'properties', 'attributes', 'options');

        foreach ($bags as $bag) {
            if (!isset($row[$bag]) || !is_array($row[$bag])) {
                continue;
            }
            foreach ($row[$bag] as $key => $value) {
                if (is_int($key) && is_array($value)) {
                    $name = '';
                    foreach (array('name', 'key', 'title') as $nameKey) {
                        if (isset($value[$nameKey])) {
                            $name = trim((string) $value[$nameKey]);
                            break;
                        }
                    }
                    $paramValue = '';
                    foreach (array('value', 'text', 'content') as $valueKey) {
                        if (isset($value[$valueKey])) {
                            $paramValue = trim((string) $value[$valueKey]);
                            break;
                        }
                    }
                    if ($name !== '' && $paramValue !== '') {
                        $attributes[$name] = $paramValue;
                    }
                    continue;
                }

                if (is_string($key) && (is_scalar($value) || $value === null)) {
                    $paramValue = trim((string) $value);
                    if ($paramValue !== '') {
                        $attributes[$key] = $paramValue;
                    }
                }
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, string> $attributes
     * @param string[] $names
     */
    private function attributeValue(array $attributes, array $names)
    {
        $wanted = array();
        foreach ($names as $name) {
            $wanted[Text::lower($name)] = true;
        }

        foreach ($attributes as $name => $value) {
            if (isset($wanted[Text::lower($name)])) {
                return $value;
            }
        }

        return '';
    }
}
