<?php

namespace AiAssistant\Rag;

use AiAssistant\Support\Text;

/**
 * Filters the flat catalog down to the rows the model is allowed to see.
 */
class CatalogRetriever
{
    /** @var int */
    private $maxCandidates;

    /**
     * @param int $maxCandidates
     */
    public function __construct($maxCandidates = 40)
    {
        $this->maxCandidates = max(5, min(80, (int) $maxCandidates));
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     * @param array<string, mixed> $criteria
     * @return array<int, array<string, mixed>>
     */
    public function retrieve(array $catalog, array $criteria)
    {
        if (!$this->hasSignal($criteria)) {
            return array();
        }

        $scored = array();
        foreach ($catalog as $product) {
            if (!is_array($product)) {
                continue;
            }
            $price = isset($product['price']) ? (float) $product['price'] : 0.0;
            if ($price < 0) {
                continue;
            }
            if (isset($criteria['budget']) && $criteria['budget'] !== null && $price > (float) $criteria['budget']) {
                continue;
            }
            if (!empty($criteria['color']) && !$this->productMatchesColor($product, (string) $criteria['color'])) {
                continue;
            }

            $product['_score'] = $this->score($product, $criteria);
            $scored[] = $product;
        }

        if (!$scored) {
            return array();
        }

        $topScore = 0;
        foreach ($scored as $product) {
            if ($product['_score'] > $topScore) {
                $topScore = $product['_score'];
            }
        }

        if ($topScore > 0) {
            usort($scored, static function ($left, $right) {
                if ($left['_score'] === $right['_score']) {
                    if ($left['price'] === $right['price']) {
                        return strcmp((string) $left['article'], (string) $right['article']);
                    }

                    return $left['price'] < $right['price'] ? 1 : -1;
                }

                return $left['_score'] < $right['_score'] ? 1 : -1;
            });

            return array_slice($scored, 0, $this->maxCandidates);
        }

        return $this->diverse($scored);
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     * @return string[]
     */
    public function categories(array $catalog)
    {
        $categories = array();
        foreach ($catalog as $product) {
            if (!is_array($product)) {
                continue;
            }
            $category = trim((string) (isset($product['category']) ? $product['category'] : ''));
            if ($category !== '') {
                $categories[$category] = true;
            }
        }

        $names = array_keys($categories);
        sort($names);

        return array_slice($names, 0, 40);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function hasSignal(array $criteria)
    {
        if (isset($criteria['budget']) && $criteria['budget'] !== null && (float) $criteria['budget'] > 0) {
            return true;
        }
        if (!empty($criteria['color'])) {
            return true;
        }
        if (!empty($criteria['keywords'])) {
            return true;
        }
        if (!empty($criteria['themes'])) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $criteria
     */
    private function score(array $product, array $criteria)
    {
        $score = 0;
        $name = Text::normalize(isset($product['name']) ? $product['name'] : '');
        $category = Text::normalize(isset($product['category']) ? $product['category'] : '');
        $description = Text::normalize(isset($product['description']) ? $product['description'] : '');
        $color = Text::normalize(isset($product['color']) ? $product['color'] : '');

        $keywords = isset($criteria['keywords']) && is_array($criteria['keywords']) ? $criteria['keywords'] : array();
        foreach ($keywords as $keyword) {
            $score += $this->fieldScore($keyword, $name, 5, $category, 3, $description, 2);
        }

        $themes = isset($criteria['themes']) && is_array($criteria['themes']) ? $criteria['themes'] : array();
        foreach ($themes as $theme) {
            $score += $this->fieldScore($theme, $name, 6, $category, 3, $description, 2);
        }

        if (!empty($criteria['color']) && $color !== '') {
            $score += 2;
        }

        return $score;
    }

    private function fieldScore($needle, $name, $nameScore, $category, $categoryScore, $description, $descriptionScore)
    {
        if ($this->fieldHas($name, $needle)) {
            return $nameScore;
        }
        if ($this->fieldHas($category, $needle)) {
            return $categoryScore;
        }
        if ($this->fieldHas($description, $needle)) {
            return $descriptionScore;
        }

        return 0;
    }

    private function fieldHas($field, $needle)
    {
        $needle = Text::normalize($needle);
        if ($needle === '' || $field === '') {
            return false;
        }

        foreach (Text::words($field) as $word) {
            if ($word === $needle || Text::startsWith($word, $needle) || Text::startsWith($needle, $word)) {
                if (Text::len($word) >= 4 && Text::len($needle) >= 4) {
                    return true;
                }
                if ($word === $needle) {
                    return true;
                }
            }
        }

        return Text::contains($field, $needle) && Text::len($needle) >= 4;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function productMatchesColor(array $product, $canonical)
    {
        $stems = CriteriaExtractor::colorStems();
        if (!isset($stems[$canonical])) {
            return Text::contains(isset($product['color']) ? $product['color'] : '', $canonical);
        }

        $words = Text::words(isset($product['color']) ? $product['color'] : '');
        if (!$words) {
            return false;
        }

        foreach ($words as $word) {
            foreach ($stems[$canonical] as $stem) {
                $stem = Text::normalize($stem);
                if ($word === $stem || Text::startsWith($word, $stem)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private function diverse(array $products)
    {
        $groups = array();
        foreach ($products as $product) {
            $category = trim((string) (isset($product['category']) ? $product['category'] : ''));
            if ($category === '') {
                $category = '—';
            }
            $groups[$category][] = $product;
        }

        foreach ($groups as $category => $group) {
            usort($group, static function ($left, $right) {
                if ($left['price'] === $right['price']) {
                    return strcmp((string) $left['name'], (string) $right['name']);
                }

                return $left['price'] < $right['price'] ? -1 : 1;
            });
            $groups[$category] = $group;
        }

        $picked = array();
        while (count($picked) < $this->maxCandidates && $groups) {
            foreach (array_keys($groups) as $category) {
                if (count($picked) >= $this->maxCandidates) {
                    break;
                }
                $next = array_shift($groups[$category]);
                if ($next) {
                    $picked[] = $next;
                }
                if (!$groups[$category]) {
                    unset($groups[$category]);
                }
            }
        }

        return $picked;
    }
}
