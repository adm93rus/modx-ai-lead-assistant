<?php

namespace AiAssistant\Chat;

use AiAssistant\Support\Text;

class ReplyComposer
{
    /**
     * @param array<string, mixed> $set
     * @param array<string, mixed> $criteria
     */
    public function compose(array $set, array $criteria, $currency, $lang)
    {
        $products = isset($set['products']) && is_array($set['products']) ? $set['products'] : array();
        if (!$products) {
            return $this->clarify($lang);
        }

        $parts = array();
        foreach ($products as $product) {
            $label = $product['name'] . ' (арт. ' . $product['article'] . ')';
            $qty = isset($product['qty']) ? (int) $product['qty'] : 1;
            if ($qty > 1) {
                $label .= ' × ' . $qty;
            }
            $parts[] = $label;
        }

        $list = $this->joinHuman($parts, $lang);
        $perSet = Text::formatMoney(isset($set['per_set']) ? $set['per_set'] : 0, $currency);
        $quantity = isset($criteria['quantity']) ? (int) $criteria['quantity'] : 0;

        if ($lang === 'en') {
            $text = 'I suggest this set: ' . $list . '. One set costs ' . $perSet . '.';
            if ($quantity > 1) {
                $order = Text::formatMoney(isset($set['order_total']) ? $set['order_total'] : 0, $currency);
                $text .= ' For a run of ' . $quantity . ', the order total is ' . $order . '.';
            }
            $text .= ' Click “Request a specification” to send this selection for a free visualization.';

            return $text;
        }

        $text = 'Предлагаю сет: ' . $list . '. Стоимость одного комплекта — ' . $perSet . '.';
        if ($quantity > 1) {
            $order = Text::formatMoney(isset($set['order_total']) ? $set['order_total'] : 0, $currency);
            $text .= ' При тираже ' . $quantity . ' шт. сумма заказа составит ' . $order . '.';
        }
        $text .= ' Нажмите «Оформить спецификацию», чтобы отправить состав на бесплатную визуализацию.';

        return $text;
    }

    public function clarify($lang)
    {
        if ($lang === 'en') {
            return 'Tell me the budget per set, the quantity, the color, and the occasion — I will assemble a set from the catalog.';
        }

        return 'Напишите бюджет на один сет, тираж, цвет и повод — соберу комплект из каталога.';
    }

    public function emptyCatalog($lang)
    {
        if ($lang === 'en') {
            return 'The product catalog is not connected yet. Add a JSON or XML feed in the component settings.';
        }

        return 'Каталог товаров пока не подключён. Укажите ссылку на JSON или XML-фид в настройках компонента.';
    }

    public function noMatches($lang)
    {
        if ($lang === 'en') {
            return 'I could not find catalog items that fit these conditions. Try another color or a higher budget.';
        }

        return 'В каталоге нет товаров под эти условия. Попробуйте другой цвет или больший бюджет на комплект.';
    }

    /**
     * @param string[] $parts
     */
    private function joinHuman(array $parts, $lang)
    {
        $count = count($parts);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);
        $conjunction = $lang === 'en' ? ' and ' : ' и ';

        return implode(', ', $parts) . $conjunction . $last;
    }
}
