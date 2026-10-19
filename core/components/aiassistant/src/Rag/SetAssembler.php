<?php

namespace AiAssistant\Rag;

use AiAssistant\Support\Text;

/**
 * Turns model-selected articles into a priced set, using only catalog rows.
 * Prices always come from the catalog.
 */
class SetAssembler
{
    /** @var int */
    private $maxInSet;

    /**
     * @param int $maxInSet
     */
    public function __construct($maxInSet = 4)
    {
        $this->maxInSet = max(1, min(8, (int) $maxInSet));
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     * @return array<string, array<string, mixed>>
     */
    public function index(array $catalog)
    {
        $index = array();
        foreach ($catalog as $product) {
            if (!is_array($product) || empty($product['article'])) {
                continue;
            }
            $index[Text::articleKey($product['article'])] = $product;
        }

        return $index;
    }

    /**
     * @param array<int, array<string, mixed>> $picked
     * @param array<int, array<string, mixed>> $catalog
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public function validate(array $picked, array $catalog, array $criteria)
    {
        $index = $this->index($catalog);
        $budget = $this->budget($criteria);
        $accepted = array();
        $used = array();
        $spent = 0.0;

        foreach ($picked as $row) {
            if (count($accepted) >= $this->maxInSet) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }
            $article = trim((string) (isset($row['article']) ? $row['article'] : ''));
            $key = Text::articleKey($article);
            if ($key === '' || !isset($index[$key]) || isset($used[$key])) {
                continue;
            }

            $qty = $this->lineQty(isset($row['qty']) ? $row['qty'] : 1);
            $product = $index[$key];
            $line = round(((float) $product['price']) * $qty, 2);
            if ($budget !== null && ($line > $budget || ($spent + $line) - $budget > 0.001)) {
                continue;
            }

            $accepted[] = $this->present($product, $qty, isset($row['reason']) ? (string) $row['reason'] : '');
            $used[$key] = true;
            $spent = round($spent + $line, 2);
        }

        return $this->finish($accepted, $criteria, $budget);
    }

    /**
     * Greedy set used when the model is off or returns nothing usable.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public function suggest(array $candidates, array $criteria)
    {
        $budget = $this->budget($criteria);
        $ranked = $candidates;
        usort($ranked, static function ($left, $right) {
            $leftScore = isset($left['_score']) ? (int) $left['_score'] : 0;
            $rightScore = isset($right['_score']) ? (int) $right['_score'] : 0;
            if ($leftScore === $rightScore) {
                $leftPrice = isset($left['price']) ? (float) $left['price'] : 0;
                $rightPrice = isset($right['price']) ? (float) $right['price'] : 0;
                if ($leftPrice === $rightPrice) {
                    return strcmp((string) $left['article'], (string) $right['article']);
                }

                return $leftPrice < $rightPrice ? 1 : -1;
            }

            return $leftScore < $rightScore ? 1 : -1;
        });

        $accepted = $this->pick($ranked, $budget, true);
        if (count($accepted) < 2) {
            $accepted = $this->pick($ranked, $budget, false);
        }

        return $this->finish($accepted, $criteria, $budget);
    }

    /**
     * @param array<int, array<string, mixed>> $ranked
     * @param float|null $budget
     * @return array<int, array<string, mixed>>
     */
    private function pick(array $ranked, $budget, $uniqueCategories)
    {
        $accepted = array();
        $categories = array();
        $spent = 0.0;

        foreach ($ranked as $product) {
            if (count($accepted) >= $this->maxInSet) {
                break;
            }
            if (!is_array($product) || empty($product['article'])) {
                continue;
            }

            $category = Text::normalize(isset($product['category']) ? $product['category'] : '');
            if ($uniqueCategories && $category !== '' && isset($categories[$category])) {
                continue;
            }

            $line = round((float) $product['price'], 2);
            if ($line <= 0) {
                continue;
            }
            if ($budget !== null && ($spent + $line) - $budget > 0.001) {
                continue;
            }

            if ($budget !== null && count($accepted) >= 2 && ($budget - ($spent + $line)) < ($budget * 0.25) && ($spent + $line) > $budget * 0.7) {
                $accepted[] = $this->present($product, 1, '');
                break;
            }

            $accepted[] = $this->present($product, 1, '');
            if ($category !== '') {
                $categories[$category] = true;
            }
            $spent = round($spent + $line, 2);

            if ($budget !== null && count($accepted) >= 2 && ($budget - $spent) < ($budget * 0.25)) {
                break;
            }
        }

        return $accepted;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function present(array $product, $qty, $reason)
    {
        return array(
            'id' => isset($product['id']) ? (int) $product['id'] : 0,
            'article' => (string) $product['article'],
            'name' => (string) $product['name'],
            'price' => round((float) $product['price'], 2),
            'qty' => (int) $qty,
            'url' => isset($product['url']) ? (string) $product['url'] : '',
            'image' => isset($product['image']) ? (string) $product['image'] : '',
            'color' => isset($product['color']) ? (string) $product['color'] : '',
            'category' => isset($product['category']) ? (string) $product['category'] : '',
            'description' => isset($product['description']) ? (string) $product['description'] : '',
            'reason' => trim($reason),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed> $criteria
     * @param float|null $budget
     * @return array<string, mixed>
     */
    private function finish(array $products, array $criteria, $budget)
    {
        $perSet = 0.0;
        foreach ($products as $product) {
            $perSet += ((float) $product['price']) * ((int) $product['qty']);
        }
        $perSet = round($perSet, 2);
        $quantity = isset($criteria['quantity']) ? (int) $criteria['quantity'] : 0;
        $multiplier = $quantity > 0 ? $quantity : 1;

        return array(
            'title' => '',
            'products' => $products,
            'quantity' => $quantity,
            'per_set' => $perSet,
            'order_total' => round($perSet * $multiplier, 2),
            'budget' => $budget,
            'within_budget' => $budget === null || $perSet - $budget <= 0.001,
        );
    }

    /**
     * @param array<string, mixed> $criteria
     * @return float|null
     */
    private function budget(array $criteria)
    {
        if (!isset($criteria['budget']) || $criteria['budget'] === null || $criteria['budget'] === '') {
            return null;
        }

        $budget = (float) $criteria['budget'];

        return $budget > 0 ? $budget : null;
    }

    private function lineQty($value)
    {
        $qty = (int) $value;
        if ($qty < 1) {
            return 1;
        }
        if ($qty > 20) {
            return 20;
        }

        return $qty;
    }
}
