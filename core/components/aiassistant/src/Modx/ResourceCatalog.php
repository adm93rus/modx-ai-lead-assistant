<?php

namespace AiAssistant\Modx;

use AiAssistant\Support\Text;

/**
 * Builds a flat product catalog from miniShop2 or from ordinary MODX resources and TVs.
 * pdoTools is not required: the component reads the same published resources directly.
 */
class ResourceCatalog
{
    /** @var \modX */
    private $modx;

    /** @var array<string, mixed> */
    private $config;

    /**
     * @param \modX $modx
     * @param array<string, mixed> $config
     */
    public function __construct($modx, array $config)
    {
        $this->modx = $modx;
        $this->config = $config;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function load()
    {
        if ($this->tableExists('ms2_products')) {
            $products = $this->fromMiniShop();
            if ($products) {
                return $products;
            }
        }

        return $this->fromResources();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fromMiniShop()
    {
        $context = $this->context();
        $ids = $this->ids();
        $classKey = trim((string) $this->configValue('class_key', 'msProduct'));
        $products = $this->queryMiniShop($context, $ids, $classKey);
        if (!$products && $classKey !== '') {
            $products = $this->queryMiniShop($context, $ids, '');
        }
        if (!$products) {
            return array();
        }

        $colors = $this->optionValues(array_keys($this->indexIds($products)), (string) $this->configValue('option_color', 'color'));
        foreach ($products as $index => $product) {
            $id = (int) $product['id'];
            if ($product['color'] === '' && isset($colors[$id])) {
                $products[$index]['color'] = $colors[$id];
            }
            $products[$index]['url'] = $this->url($id, $context);
            $products[$index]['image'] = $this->imageUrl($product['image']);
        }

        return array_values($products);
    }

    /**
     * @param int[]|null $ids
     * @return array<int, array<string, mixed>>
     */
    private function queryMiniShop($context, $ids, $classKey)
    {
        $prefix = (string) $this->modx->getOption('table_prefix');
        $where = array('c.published = 1', 'c.deleted = 0');
        if ($context !== '') {
            $where[] = 'c.context_key = ' . $this->modx->quote($context);
        }
        if ($classKey !== '') {
            $where[] = 'c.class_key = ' . $this->modx->quote($classKey);
        }
        $template = (int) $this->configValue('template', 0);
        if ($template > 0) {
            $where[] = 'c.template = ' . $template;
        }
        if (is_array($ids)) {
            if (!$ids) {
                return array();
            }
            $where[] = 'c.id IN (' . implode(',', array_map('intval', $ids)) . ')';
        }

        $sql = 'SELECT c.id, c.pagetitle, c.introtext, c.parent, p.pagetitle AS category_name,'
            . ' d.article, d.price, d.image, d.thumb'
            . ' FROM ' . $prefix . 'site_content c'
            . ' INNER JOIN ' . $prefix . 'ms2_products d ON d.id = c.id'
            . ' LEFT JOIN ' . $prefix . 'site_content p ON p.id = c.parent'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY c.pagetitle ASC'
            . ' LIMIT 5000';

        $statement = $this->modx->query($sql);
        if (!$statement) {
            return array();
        }

        $products = array();
        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $article = trim((string) $row['article']);
            $id = (int) $row['id'];
            if ($article === '') {
                $article = (string) $id;
            }
            $image = trim((string) $row['image']);
            if ($image === '') {
                $image = trim((string) $row['thumb']);
            }
            $products[] = $this->product(
                $id,
                $article,
                (string) $row['pagetitle'],
                Text::money($row['price']),
                '',
                (string) $row['category_name'],
                '',
                '',
                (string) $row['introtext']
            );
            $products[count($products) - 1]['image'] = $image;
        }

        return $products;
    }

    /**
     * @param int[] $ids
     * @return array<int, string>
     */
    private function optionValues(array $ids, $key)
    {
        $colors = array();
        if (!$ids || $key === '' || !$this->tableExists('ms2_product_options')) {
            return $colors;
        }

        $prefix = (string) $this->modx->getOption('table_prefix');
        $chunks = array_chunk($ids, 400);
        foreach ($chunks as $chunk) {
            $sql = 'SELECT product_id, `value` FROM ' . $prefix . 'ms2_product_options'
                . ' WHERE `key` = ' . $this->modx->quote($key)
                . ' AND product_id IN (' . implode(',', array_map('intval', $chunk)) . ')';
            $statement = $this->modx->query($sql);
            if (!$statement) {
                continue;
            }
            while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
                $productId = (int) $row['product_id'];
                if (!isset($colors[$productId]) && trim((string) $row['value']) !== '') {
                    $colors[$productId] = trim((string) $row['value']);
                }
            }
        }

        return $colors;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fromResources()
    {
        $ids = $this->ids();
        $where = array('published' => 1, 'deleted' => 0);
        $context = $this->context();
        if ($context !== '') {
            $where['context_key'] = $context;
        }
        $classKey = trim((string) $this->configValue('class_key', ''));
        if ($classKey !== '' && !$this->tableExists('ms2_products')) {
            $where['class_key'] = $classKey;
        }
        $template = (int) $this->configValue('template', 0);
        if ($template > 0) {
            $where['template'] = $template;
        }
        if (is_array($ids)) {
            if (!$ids) {
                return array();
            }
            $where['id:IN'] = $ids;
        }

        $query = $this->modx->newQuery('modResource');
        $query->where($where);
        $query->limit(5000);
        $query->sortby('pagetitle', 'ASC');
        $collection = $this->modx->getCollection('modResource', $query);
        $products = array();
        $articleTv = (string) $this->configValue('tv_article', 'article');
        $priceTv = (string) $this->configValue('tv_price', 'price');
        $colorTv = (string) $this->configValue('tv_color', 'color');
        $imageTv = (string) $this->configValue('tv_image', 'image');
        $extraTvs = $this->extraTvs();

        foreach ($collection as $resource) {
            $id = (int) $resource->get('id');
            $article = $this->tv($resource, $articleTv);
            if ($article === '') {
                $article = (string) $id;
            }
            $parent = (int) $resource->get('parent');
            $category = '';
            if ($parent > 0) {
                $parentResource = $this->modx->getObject('modResource', $parent);
                if ($parentResource) {
                    $category = (string) $parentResource->get('pagetitle');
                }
            }
            $attributes = array();
            foreach ($extraTvs as $name) {
                $value = $this->tv($resource, $name);
                if ($value !== '') {
                    $attributes[$name] = $value;
                }
            }
            $product = $this->product(
                $id,
                $article,
                (string) $resource->get('pagetitle'),
                Text::money($this->tv($resource, $priceTv)),
                $this->tv($resource, $colorTv),
                $category,
                $this->url($id, $context),
                $this->imageUrl($this->tv($resource, $imageTv)),
                (string) $resource->get('introtext')
            );
            $product['attributes'] = $attributes;
            $products[] = $product;
        }

        return $products;
    }

    /**
     * @return array<string, mixed>
     */
    private function product($id, $article, $name, $price, $color, $category, $url, $image, $description)
    {
        return array(
            'id' => (int) $id,
            'article' => trim((string) $article),
            'name' => Text::strip($name),
            'price' => (float) $price,
            'color' => Text::strip($color),
            'category' => Text::strip($category),
            'url' => (string) $url,
            'image' => (string) $image,
            'description' => Text::truncate(Text::strip($description), 400),
            'attributes' => array(),
        );
    }

    /**
     * @return int[]|null
     */
    private function ids()
    {
        $parents = trim((string) $this->configValue('parents', ''));
        if ($parents === '') {
            return null;
        }

        $depth = (int) $this->configValue('depth', 10);
        if ($depth < 1) {
            $depth = 1;
        }
        $context = $this->context();
        $ids = array();
        foreach (explode(',', $parents) as $parent) {
            $parent = (int) trim($parent);
            if ($parent < 0) {
                continue;
            }
            $children = $this->modx->getChildIds($parent, $depth, array('context' => $context));
            if (is_array($children)) {
                foreach ($children as $id) {
                    $ids[(int) $id] = (int) $id;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param int[] $products ignored, helper below
     * @param array<int, array<string, mixed>> $products
     * @return array<int, int>
     */
    private function indexIds(array $products)
    {
        $ids = array();
        foreach ($products as $product) {
            $ids[(int) $product['id']] = (int) $product['id'];
        }

        return $ids;
    }

    private function url($id, $context)
    {
        $url = $this->modx->makeUrl((int) $id, $context !== '' ? $context : null, '', 'full');
        if (!is_string($url) || $url === '') {
            return '';
        }

        return Text::safeUrl($url);
    }

    private function imageUrl($path)
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }
        $safe = Text::safeUrl($path);
        if ($safe !== '') {
            return $safe;
        }

        $base = trim((string) $this->configValue('image_base', ''));
        if ($base === '') {
            $base = '/assets/images/products/';
        }
        if (substr($base, -1) !== '/') {
            $base .= '/';
        }

        return Text::safeUrl($base . ltrim($path, '/'));
    }

    /**
     * @param \modResource $resource
     */
    private function tv($resource, $name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        $value = $resource->getTVValue($name);

        return trim((string) $value);
    }

    /**
     * @return string[]
     */
    private function extraTvs()
    {
        $raw = trim((string) $this->configValue('include_tvs', ''));
        if ($raw === '') {
            return array();
        }
        $names = array();
        foreach (explode(',', $raw) as $name) {
            $name = trim($name);
            if ($name !== '' && preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function context()
    {
        $context = trim((string) $this->configValue('context', ''));
        if ($context === '' && $this->modx->context) {
            $context = (string) $this->modx->context->get('key');
        }
        if ($context === 'mgr') {
            return 'web';
        }

        return $context;
    }

    private function tableExists($suffix)
    {
        $name = (string) $this->modx->getOption('table_prefix') . $suffix;
        $statement = $this->modx->query('SHOW TABLES LIKE ' . $this->modx->quote($name));
        if (!$statement) {
            return false;
        }
        $row = $statement->fetch(\PDO::FETCH_NUM);

        return !empty($row);
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function configValue($key, $default)
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }
}
