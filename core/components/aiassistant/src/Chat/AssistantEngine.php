<?php

namespace AiAssistant\Chat;

use AiAssistant\Provider\ProviderInterface;
use AiAssistant\Rag\CatalogRetriever;
use AiAssistant\Rag\CriteriaExtractor;
use AiAssistant\Rag\SetAssembler;
use AiAssistant\Support\Text;

class AssistantEngine
{
    /** @var ProviderInterface|null */
    private $provider;

    /** @var string */
    private $providerName;

    /** @var bool */
    private $fallback;

    /** @var string */
    private $currency;

    /** @var string */
    private $lang;

    /** @var array<string, mixed> */
    private $promptConfig;

    /** @var CriteriaExtractor */
    private $extractor;

    /** @var CatalogRetriever */
    private $retriever;

    /** @var SetAssembler */
    private $assembler;

    /** @var PromptBuilder */
    private $prompts;

    /** @var ResponseNormalizer */
    private $normalizer;

    /** @var ReplyComposer */
    private $composer;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct($provider, array $config)
    {
        $this->provider = $provider instanceof ProviderInterface ? $provider : null;
        $this->providerName = strtolower(trim((string) (isset($config['provider']) ? $config['provider'] : 'demo')));
        if (!isset($config['fallback'])) {
            $this->fallback = true;
        } elseif (is_bool($config['fallback'])) {
            $this->fallback = $config['fallback'];
        } else {
            $this->fallback = !in_array(strtolower(trim((string) $config['fallback'])), array('0', 'false', 'no', 'off'), true);
        }
        $this->currency = trim((string) (isset($config['currency']) ? $config['currency'] : '₽'));
        if ($this->currency === '') {
            $this->currency = '₽';
        }
        $this->lang = (isset($config['lang']) && $config['lang'] === 'en') ? 'en' : 'ru';
        $themes = null;
        if (isset($config['themes']) && is_array($config['themes'])) {
            $themes = $config['themes'];
        }
        $this->extractor = new CriteriaExtractor($themes);
        $this->retriever = new CatalogRetriever(isset($config['max_candidates']) ? $config['max_candidates'] : 40);
        $this->assembler = new SetAssembler(isset($config['max_in_set']) ? $config['max_in_set'] : 4);
        $this->prompts = new PromptBuilder();
        $this->normalizer = new ResponseNormalizer();
        $this->composer = new ReplyComposer();
        $this->promptConfig = array(
            'rules' => isset($config['rules']) ? (string) $config['rules'] : '',
            'assistant_name' => isset($config['assistant_name']) ? (string) $config['assistant_name'] : 'AI-ассистент',
            'max_messages' => isset($config['max_messages']) ? (int) $config['max_messages'] : 8,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @param array<int, array<string, mixed>> $catalog
     * @return array<string, mixed>
     */
    public function handle(array $history, $message, array $catalog)
    {
        $message = trim((string) $message);
        $lang = Text::mostlyLatin($message) ? 'en' : $this->lang;
        $criteria = $this->extractor->extract($this->corpus($history, $message));

        if (!$catalog) {
            return $this->ok($this->composer->emptyCatalog($lang), array(), $criteria, $this->emptySet($criteria), 'catalog');
        }

        $candidates = $this->retriever->retrieve($catalog, $criteria);
        $useDemo = $this->providerName === 'demo' || $this->provider === null;

        if ($useDemo) {
            return $this->fromSuggestion($candidates, $criteria, $lang, 'demo');
        }

        $promptConfig = $this->promptConfig;
        $promptConfig['categories'] = $this->retriever->categories($catalog);
        $messages = $this->prompts->build($history, $message, $candidates, $criteria, $promptConfig);
        $response = $this->provider->complete($messages);

        if (empty($response['ok'])) {
            if ($this->fallback && $this->retriever->hasSignal($criteria)) {
                return $this->fromSuggestion($candidates, $criteria, $lang, 'fallback');
            }

            return $this->fail(
                isset($response['error']) ? (string) $response['error'] : 'Assistant request failed',
                $criteria
            );
        }

        $decoded = $this->normalizer->decode(isset($response['content']) ? $response['content'] : '');
        if ($decoded === null) {
            if ($this->fallback && $this->retriever->hasSignal($criteria)) {
                return $this->fromSuggestion($candidates, $criteria, $lang, 'fallback');
            }

            return $this->fail('Invalid assistant response', $criteria);
        }

        $validated = $this->assembler->validate($decoded['products'], $catalog, $criteria);
        if (!empty($decoded['set_title'])) {
            $validated['title'] = $decoded['set_title'];
        }

        if (!$validated['products']) {
            if (!$this->retriever->hasSignal($criteria) && $decoded['reply'] !== '') {
                return $this->ok($decoded['reply'], array(), $criteria, $validated, 'model');
            }
            if ($this->retriever->hasSignal($criteria)) {
                $suggested = $this->fromSuggestion($candidates, $criteria, $lang, 'fallback');
                if (!empty($suggested['products'])) {
                    return $suggested;
                }
            }
            $reply = $decoded['reply'] !== '' ? $decoded['reply'] : $this->composer->noMatches($lang);

            return $this->ok($reply, array(), $criteria, $validated, 'model');
        }

        $reply = $decoded['reply'];
        if ($reply === '' || $this->replyConflicts($reply, $validated['products'], $catalog)) {
            $reply = $this->composer->compose($validated, $criteria, $this->currency, $lang);
        }

        return $this->ok($reply, $validated['products'], $criteria, $validated, 'model');
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $catalog
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public function specification(array $lines, array $catalog, array $criteria)
    {
        $set = $this->assembler->validate($lines, $catalog, $criteria);
        $lang = $this->lang;

        return $this->ok(
            $set['products'] ? $this->composer->compose($set, $criteria, $this->currency, $lang) : $this->composer->noMatches($lang),
            $set['products'],
            $criteria,
            $set,
            'specification'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private function fromSuggestion(array $candidates, array $criteria, $lang, $source)
    {
        if (!$this->retriever->hasSignal($criteria)) {
            return $this->ok($this->composer->clarify($lang), array(), $criteria, $this->emptySet($criteria), $source);
        }

        $set = $this->assembler->suggest($candidates, $criteria);
        if (!$set['products']) {
            return $this->ok($this->composer->noMatches($lang), array(), $criteria, $set, $source);
        }

        return $this->ok(
            $this->composer->compose($set, $criteria, $this->currency, $lang),
            $set['products'],
            $criteria,
            $set,
            $source
        );
    }

    /**
     * @param array<int, array<string, mixed>> $history
     */
    private function corpus(array $history, $message)
    {
        $parts = array();
        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((isset($item['role']) ? $item['role'] : '') !== 'user') {
                continue;
            }
            $content = trim((string) (isset($item['content']) ? $item['content'] : ''));
            if ($content !== '') {
                $parts[] = $content;
            }
        }
        $parts[] = trim((string) $message);

        return trim(implode("\n", $parts));
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<int, array<string, mixed>> $catalog
     */
    private function replyConflicts($reply, array $products, array $catalog)
    {
        $allowed = array();
        foreach ($products as $product) {
            $allowed[Text::articleKey($product['article'])] = true;
        }

        if (preg_match_all('/арт\.?\s*([0-9][0-9A-Za-z.\-]{2,})/u', $reply, $matches)) {
            foreach ($matches[1] as $article) {
                if (!isset($allowed[Text::articleKey($article)])) {
                    return true;
                }
            }
        }

        $known = array();
        foreach ($catalog as $product) {
            if (is_array($product) && !empty($product['article'])) {
                $known[Text::articleKey($product['article'])] = true;
            }
        }

        if (preg_match_all('/\d{2,}[.,]\d{2,}/u', $reply, $found)) {
            foreach ($found[0] as $article) {
                $key = Text::articleKey($article);
                if (isset($known[$key]) && !isset($allowed[$key])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private function emptySet(array $criteria)
    {
        return array(
            'title' => '',
            'products' => array(),
            'quantity' => isset($criteria['quantity']) ? (int) $criteria['quantity'] : 0,
            'per_set' => 0,
            'order_total' => 0,
            'budget' => isset($criteria['budget']) ? $criteria['budget'] : null,
            'within_budget' => true,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $set
     * @return array<string, mixed>
     */
    private function ok($message, array $products, array $criteria, array $set, $source)
    {
        return array(
            'ok' => true,
            'error' => '',
            'message' => (string) $message,
            'products' => $products,
            'set' => array(
                'title' => isset($set['title']) ? (string) $set['title'] : '',
                'quantity' => isset($set['quantity']) ? (int) $set['quantity'] : 0,
                'per_set' => isset($set['per_set']) ? (float) $set['per_set'] : 0,
                'order_total' => isset($set['order_total']) ? (float) $set['order_total'] : 0,
                'budget' => isset($set['budget']) ? $set['budget'] : null,
                'within_budget' => !empty($set['within_budget']),
                'currency' => $this->currency,
            ),
            'criteria' => array(
                'budget' => isset($criteria['budget']) ? $criteria['budget'] : null,
                'quantity' => isset($criteria['quantity']) ? (int) $criteria['quantity'] : 0,
                'color' => isset($criteria['color']) ? (string) $criteria['color'] : '',
            ),
            'source' => (string) $source,
        );
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private function fail($error, array $criteria)
    {
        return array(
            'ok' => false,
            'error' => (string) $error,
            'message' => '',
            'products' => array(),
            'set' => $this->emptySet($criteria),
            'criteria' => $criteria,
            'source' => 'error',
        );
    }
}
