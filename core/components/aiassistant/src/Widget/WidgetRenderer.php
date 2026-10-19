<?php

namespace AiAssistant\Widget;

class WidgetRenderer
{
    /**
     * @param array<string, mixed> $config
     */
    public function render(array $config)
    {
        $position = (isset($config['position']) && $config['position'] === 'left') ? 'left' : 'right';
        $accent = $this->accent(isset($config['accent']) ? $config['accent'] : '');
        $name = $this->text(isset($config['assistant_name']) ? $config['assistant_name'] : 'AI-ассистент', 'AI-ассистент');
        $welcome = $this->text(
            isset($config['welcome']) ? $config['welcome'] : '',
            'Здравствуйте! Помогу собрать набор сувениров. Напишите бюджет на один сет, тираж, цвет и повод.'
        );
        $placeholder = $this->text(isset($config['placeholder']) ? $config['placeholder'] : '', 'Бюджет, тираж, цвет, повод…');
        $hint = $this->text(isset($config['hint']) ? $config['hint'] : '', 'Подберу сет');
        $status = $this->text(isset($config['status']) ? $config['status'] : '', 'Онлайн');
        $assets = rtrim((string) (isset($config['assets_url']) ? $config['assets_url'] : '/assets/components/aiassistant/'), '/') . '/';
        $version = '1.0.0';
        $cartEnabled = !empty($config['cart_enabled']);
        $specEnabled = !empty($config['spec_enabled']);

        $attrs = array(
            'class' => 'fw-assistant is-' . $position,
            'id' => 'fwAssistant',
            'style' => '--fw-assistant-accent:' . $accent . ';--fw-assistant-accent-2:' . $accent . ';',
            'data-endpoint' => (string) (isset($config['endpoint']) ? $config['endpoint'] : $assets . 'action.php'),
            'data-cart-url' => (string) (isset($config['cart_url']) ? $config['cart_url'] : '/assets/components/minishop2/action.php'),
            'data-cart-mode' => (isset($config['cart_mode']) && $config['cart_mode'] === 'custom') ? 'custom' : 'minishop2',
            'data-cart-enabled' => $cartEnabled ? '1' : '0',
            'data-spec-enabled' => $specEnabled ? '1' : '0',
            'data-currency' => $this->text(isset($config['currency']) ? $config['currency'] : '₽', '₽'),
            'data-context' => $this->text(isset($config['context']) ? $config['context'] : 'web', 'web'),
            'data-text-request-error' => 'Не получилось получить ответ. Попробуйте ещё раз.',
            'data-text-busy' => 'Ассистент занят. Попробуйте через минуту.',
            'data-text-added' => 'Добавлено в корзину',
            'data-text-cart-error' => 'Не удалось добавить в корзину',
            'data-text-spec-sent' => 'Заявка отправлена. Мы подготовим визуализацию и свяжемся с вами.',
            'data-text-spec-error' => 'Не удалось отправить спецификацию. Проверьте email и попробуйте ещё раз.',
            'data-text-open-chat' => 'Открыть AI-ассистента',
            'data-text-close-chat' => 'Закрыть AI-ассистента',
            'data-text-cart' => 'Добавить в корзину',
            'data-text-spec' => 'Оформить спецификацию',
            'data-text-per-set' => 'Комплект',
            'data-text-run' => 'Тираж',
        );

        $html = '<link rel="stylesheet" href="' . $this->e($assets . 'css/assistant.css?v=' . $version) . '">' . "\n";
        $html .= '<div';
        foreach ($attrs as $key => $value) {
            $html .= ' ' . $key . '="' . $this->e($value) . '"';
        }
        $html .= '>' . "\n";
        $html .= $this->window($name, $status, $welcome, $placeholder);
        $html .= $this->trigger($hint);
        $html .= '</div>' . "\n";
        $html .= '<script src="' . $this->e($assets . 'js/assistant.js?v=' . $version) . '" defer></script>' . "\n";

        return $html;
    }

    private function window($name, $status, $welcome, $placeholder)
    {
        return <<<HTML
    <div class="fw-assistant-window" id="fwAssistantWindow" role="dialog" aria-label="{$this->e($name)}" aria-hidden="true">
        <div class="fw-assistant-header">
            <div class="fw-assistant-profile">
                <div class="fw-assistant-avatar"><span class="fw-assistant-avatar-core"></span></div>
                <div class="fw-assistant-profile-text">
                    <strong>{$this->e($name)}</strong>
                    <span><i></i> {$this->e($status)}</span>
                </div>
            </div>
            <button class="fw-assistant-close" type="button" aria-label="Свернуть чат">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </button>
        </div>
        <div class="fw-assistant-body" id="fwAssistantBody">
            <div class="fw-assistant-messages" id="fwAssistantMessages" aria-live="polite">
                <div class="fw-assistant-welcome">
                    <div class="fw-assistant-mini-avatar"><span></span></div>
                    <div class="fw-assistant-message"><p>{$this->e($welcome)}</p></div>
                </div>
            </div>
        </div>
        <div class="fw-assistant-footer">
            <div class="fw-assistant-composer">
                <form class="fw-assistant-chat-form" id="fwAssistantChatForm">
                    <textarea id="fwAssistantInput" rows="1" maxlength="2000" placeholder="{$this->e($placeholder)}" aria-label="Сообщение"></textarea>
                    <button class="fw-assistant-send" type="submit" aria-label="Отправить" disabled>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12L20 4l-5 16-3.5-6.5L4 12z"/><path d="M11.5 13.5L20 4"/></svg>
                    </button>
                </form>
            </div>
            <div class="fw-assistant-powered"><span class="fw-assistant-powered-dot"></span> Подбор сетов по каталогу</div>
        </div>
    </div>

HTML;
    }

    private function trigger($hint)
    {
        return <<<HTML
    <button class="fw-assistant-trigger" id="fwAssistantTrigger" type="button" aria-label="Открыть AI-ассистента" aria-expanded="false">
        <span class="fw-assistant-pulse"></span>
        <span class="fw-assistant-trigger-inner">
            <span class="fw-assistant-orb"><span></span></span>
            <svg class="fw-assistant-trigger-close-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </span>
        <span class="fw-assistant-notification">1</span>
    </button>
    <div class="fw-assistant-hint">{$this->e($hint)}</div>

HTML;
    }

    private function accent($value)
    {
        $value = trim((string) $value);
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            return $value;
        }

        return '#1d4ed8';
    }

    private function text($value, $fallback)
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    private function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
