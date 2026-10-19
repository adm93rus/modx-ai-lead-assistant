<?php

namespace AiAssistant\Chat;

class ResponseNormalizer
{
    /**
     * @param string $content
     * @return array<string, mixed>|null
     */
    public function decode($content)
    {
        $content = trim((string) $content);
        if ($content === '') {
            return null;
        }

        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', (string) $content);
        $content = trim((string) $content);

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
        }

        if (!is_array($decoded)) {
            return null;
        }

        $products = array();
        if (isset($decoded['products']) && is_array($decoded['products'])) {
            foreach ($decoded['products'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $article = trim((string) (isset($row['article']) ? $row['article'] : (isset($row['sku']) ? $row['sku'] : '')));
                if ($article === '') {
                    continue;
                }
                $products[] = array(
                    'article' => $article,
                    'qty' => isset($row['qty']) ? $row['qty'] : (isset($row['quantity']) ? $row['quantity'] : 1),
                    'reason' => trim((string) (isset($row['reason']) ? $row['reason'] : '')),
                );
            }
        }

        return array(
            'reply' => trim((string) (isset($decoded['reply']) ? $decoded['reply'] : '')),
            'set_title' => trim((string) (isset($decoded['set_title']) ? $decoded['set_title'] : '')),
            'products' => $products,
        );
    }
}
