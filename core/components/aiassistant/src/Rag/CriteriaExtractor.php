<?php

namespace AiAssistant\Rag;

use AiAssistant\Support\Text;

/**
 * Pulls budget, print run, color and theme hints out of a visitor message.
 * This is the retrieval query for the catalog, before any model call.
 */
class CriteriaExtractor
{
    /** @var array<string, string[]> */
    private $themes;

    /** @var array<string, string[]> */
    private $colors;

    /** @var array<string, bool> */
    private $stopwords;

    /**
     * @param array<string, string[]>|null $themes
     */
    public function __construct($themes = null)
    {
        $this->themes = is_array($themes) && $themes ? $themes : self::defaultThemes();
        $this->colors = self::colorStems();
        $this->stopwords = array_fill_keys(self::stopwords(), true);
    }

    /**
     * @param string $text
     * @return array<string, mixed>
     */
    public function extract($text)
    {
        $normalized = Text::normalize($text);
        $words = Text::words($normalized);

        return array(
            'budget' => $this->budget($normalized),
            'quantity' => $this->quantity($normalized),
            'color' => $this->color($words),
            'keywords' => $this->keywords($words),
            'themes' => $this->themes($normalized, $words),
            'text' => $normalized,
        );
    }

    /**
     * @return array<string, string[]>
     */
    public static function defaultThemes()
    {
        return array(
            'программист' => array('кабель', 'флеш', 'термостакан', 'заряд', 'блокнот', 'рулетк', 'аккумулятор', 'powerbank'),
            'разработчик' => array('кабель', 'флеш', 'термостакан', 'заряд', 'блокнот'),
            'айти' => array('кабель', 'флеш', 'термостакан', 'заряд'),
            'it' => array('кабель', 'флеш', 'термостакан', 'заряд'),
            'приветствен' => array('термостакан', 'блокнот', 'ручка', 'кружка', 'пакет', 'ежедневник', 'шоппер'),
            'онбординг' => array('термостакан', 'блокнот', 'ручка', 'бейдж'),
            'сотрудник' => array('термостакан', 'блокнот', 'ручка', 'кружка'),
            'конференц' => array('ручка', 'блокнот', 'бейдж', 'пакет', 'флеш'),
            'новый год' => array('календар', 'кружка', 'елка', 'шар'),
            'vip' => array('ежедневник', 'термостакан', 'ручка', 'набор'),
        );
    }

    /**
     * @return array<string, string[]>
     */
    public static function colorStems()
    {
        return array(
            'серебряный' => array('серебр', 'silver'),
            'золотой' => array('золот', 'gold'),
            'красный' => array('красн', 'red', 'алый', 'бордов'),
            'синий' => array('син', 'blue', 'navy'),
            'голубой' => array('голуб'),
            'черный' => array('черн', 'black'),
            'белый' => array('бел', 'white'),
            'зеленый' => array('зелен', 'green'),
            'желтый' => array('желт', 'yellow'),
            'оранжевый' => array('оранж', 'orange'),
            'фиолетовый' => array('фиолет', 'purple', 'violet'),
            'розовый' => array('розов', 'pink'),
            'бежевый' => array('беж', 'beige'),
            'коричневый' => array('коричн', 'brown'),
            'бирюзовый' => array('бирюз', 'teal'),
            'серый' => array('серы', 'серая', 'серое', 'сером', 'серого', 'серый', 'grey', 'gray'),
        );
    }

    /**
     * @return string[]
     */
    public static function stopwords()
    {
        return array(
            'и', 'в', 'во', 'на', 'для', 'до', 'за', 'по', 'с', 'со', 'из', 'или', 'а', 'но', 'это',
            'нужен', 'нужна', 'нужно', 'нужны', 'набор', 'сет', 'пак', 'цвет', 'цвета', 'цвете',
            'бюджет', 'бюджета', 'руб', 'рубль', 'рубля', 'рублей', 'тираж', 'тиража', 'тиражом',
            'штук', 'штука', 'штуки', 'шт', 'комплект', 'комплекта', 'привет', 'здравствуйте',
            'пожалуйста', 'хочу', 'хотим', 'можно', 'какой', 'какая', 'какие', 'что', 'как',
            'меня', 'нам', 'вас', 'ваш', 'под', 'над', 'от', 'без', 'при', 'же', 'бы', 'ли',
            'не', 'ни', 'да', 'ок', 'сувенир', 'сувениры', 'товар', 'товары', 'заказать',
            'подберите', 'подбери', 'помоги', 'помогите', 'клиента', 'клиенту',
        );
    }

    /**
     * @param string $text
     * @return float|null
     */
    private function budget($text)
    {
        $patterns = array(
            '/(?:бюджет\p{L}*|не\s+дороже|не\s+более|максимум|в\s+пределах)\s*(?:до|на)?\s*(\d[\d\s]{0,12})(?:[.,]\d{1,2})?/u',
            '/до\s*(\d[\d\s]{0,12})(?:[.,]\d{1,2})?\s*(?:руб(?:л\p{L}*)?|₽|р\.)/u',
            '/(\d[\d\s]{0,12})(?:[.,]\d{1,2})?\s*(?:руб(?:л\p{L}*)?|₽)/u',
        );

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $text, $matches)) {
                continue;
            }
            $amount = Text::money($matches[1]);
            if ($amount <= 0) {
                continue;
            }
            if ($amount >= 1900 && $amount <= 2100 && !preg_match('/руб|₽/u', $matches[0])) {
                continue;
            }

            return $amount;
        }

        return null;
    }

    /**
     * @param string $text
     */
    private function quantity($text)
    {
        if (preg_match('/тираж\p{L}*\s*(\d{1,7})/u', $text, $matches)) {
            return $this->boundQuantity($matches[1]);
        }

        if (preg_match('/(\d{1,7})\s*(?:шт(?:ук\p{L}*)?|комплект\p{L}*|набор\p{L}*)/u', $text, $matches)) {
            return $this->boundQuantity($matches[1]);
        }

        return 0;
    }

    private function boundQuantity($value)
    {
        $quantity = (int) $value;
        if ($quantity < 1) {
            return 0;
        }
        if ($quantity > 100000) {
            return 100000;
        }

        return $quantity;
    }

    /**
     * @param string[] $words
     */
    private function color(array $words)
    {
        foreach ($this->colors as $canonical => $stems) {
            foreach ($words as $word) {
                foreach ($stems as $stem) {
                    if ($this->stemMatches($word, $stem)) {
                        return $canonical;
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param string[] $words
     * @return string[]
     */
    private function keywords(array $words)
    {
        $keywords = array();
        $colorStems = array();
        foreach ($this->colors as $stems) {
            foreach ($stems as $stem) {
                $colorStems[] = $stem;
            }
        }

        foreach ($words as $word) {
            if (Text::len($word) < 3) {
                continue;
            }
            if (isset($this->stopwords[$word])) {
                continue;
            }
            if (preg_match('/^\d+$/', $word)) {
                continue;
            }
            $isColor = false;
            foreach ($colorStems as $stem) {
                if ($this->stemMatches($word, $stem)) {
                    $isColor = true;
                    break;
                }
            }
            if ($isColor) {
                continue;
            }
            $keywords[$word] = true;
        }

        return array_keys($keywords);
    }

    /**
     * @param string $text
     * @param string[] $words
     * @return string[]
     */
    private function themes($text, array $words)
    {
        $matched = array();

        foreach ($this->themes as $trigger => $terms) {
            $trigger = Text::normalize($trigger);
            if ($trigger === '') {
                continue;
            }
            if ($this->triggerMatches($text, $words, $trigger)) {
                foreach ($terms as $term) {
                    $term = Text::normalize($term);
                    if ($term !== '') {
                        $matched[$term] = true;
                    }
                }
            }
        }

        return array_keys($matched);
    }

    /**
     * @param string[] $words
     */
    private function triggerMatches($text, array $words, $trigger)
    {
        if (preg_match('/^[a-z0-9]+$/', $trigger)) {
            return (bool) preg_match('/(?<![a-z0-9])' . preg_quote($trigger, '/') . '(?![a-z0-9])/', $text);
        }

        if (Text::contains($text, $trigger)) {
            return true;
        }

        foreach ($words as $word) {
            if ($this->stemMatches($word, $trigger)) {
                return true;
            }
        }

        return false;
    }

    private function stemMatches($word, $stem)
    {
        $word = Text::normalize($word);
        $stem = Text::normalize($stem);
        if ($word === '' || $stem === '') {
            return false;
        }

        return $word === $stem || Text::startsWith($word, $stem);
    }
}
