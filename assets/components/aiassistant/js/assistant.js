document.addEventListener('DOMContentLoaded', () => {
    const assistant = document.getElementById('fwAssistant');
    if (!assistant) {
        return;
    }

    const endpoint = assistant.dataset.endpoint || '/assets/components/aiassistant/action.php';
    const trigger = document.getElementById('fwAssistantTrigger');
    const windowElement = document.getElementById('fwAssistantWindow');
    const closeButton = assistant.querySelector('.fw-assistant-close');
    const body = document.getElementById('fwAssistantBody');
    const messages = document.getElementById('fwAssistantMessages');
    const chatForm = document.getElementById('fwAssistantChatForm');
    const chatInput = document.getElementById('fwAssistantInput');
    const sendButton = assistant.querySelector('.fw-assistant-send');
    const hint = assistant.querySelector('.fw-assistant-hint');
    const text = {
        requestError: assistant.dataset.textRequestError || 'Не получилось получить ответ. Попробуйте ещё раз.',
        busy: assistant.dataset.textBusy || 'Ассистент занят. Попробуйте через минуту.',
        added: assistant.dataset.textAdded || 'Добавлено в корзину',
        cartError: assistant.dataset.textCartError || 'Не удалось добавить в корзину',
        specSent: assistant.dataset.textSpecSent || 'Заявка отправлена. Мы подготовим визуализацию и свяжемся с вами.',
        specError: assistant.dataset.textSpecError || 'Не удалось отправить спецификацию.',
        openChat: assistant.dataset.textOpenChat || 'Открыть AI-ассистента',
        closeChat: assistant.dataset.textCloseChat || 'Закрыть AI-ассистента',
        cart: assistant.dataset.textCart || 'Добавить в корзину',
        spec: assistant.dataset.textSpec || 'Оформить спецификацию',
        perSet: assistant.dataset.textPerSet || 'Комплект',
        run: assistant.dataset.textRun || 'Тираж'
    };

    let isReplying = false;
    let typingElement = null;

    function createConversationId() {
        if (window.crypto && window.crypto.getRandomValues) {
            const bytes = new Uint8Array(18);
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        }

        return [Date.now().toString(36), Math.random().toString(36).slice(2), Math.random().toString(36).slice(2)].join('');
    }

    function conversationId() {
        let id = sessionStorage.getItem('fwAssistantConversationId');
        if (!id) {
            id = createConversationId();
            sessionStorage.setItem('fwAssistantConversationId', id);
        }
        return id;
    }

    function openAssistant() {
        assistant.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
        trigger.setAttribute('aria-label', text.closeChat);
        windowElement.setAttribute('aria-hidden', 'false');
        if (!chatInput.disabled) {
            setTimeout(() => chatInput.focus(), 300);
        }
    }

    function closeAssistant() {
        assistant.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-label', text.openChat);
        windowElement.setAttribute('aria-hidden', 'true');
    }

    function scrollToBottom() {
        requestAnimationFrame(() => {
            body.scrollTo({ top: body.scrollHeight, behavior: 'smooth' });
        });
    }

    function avatar() {
        const node = document.createElement('div');
        node.className = 'fw-assistant-chat-avatar';
        node.appendChild(document.createElement('span'));
        return node;
    }

    function addMessage(role, value) {
        const row = document.createElement('div');
        row.className = `fw-assistant-chat-row ${role === 'assistant' ? 'is-assistant' : 'is-user'}`;
        if (role === 'assistant') {
            row.appendChild(avatar());
        }
        const bubble = document.createElement('div');
        bubble.className = 'fw-assistant-chat-bubble';
        bubble.textContent = value;
        row.appendChild(bubble);
        messages.appendChild(row);
        scrollToBottom();
    }

    function showTyping() {
        if (typingElement) {
            return;
        }
        const row = document.createElement('div');
        row.className = 'fw-assistant-chat-row is-assistant';
        row.appendChild(avatar());
        const bubble = document.createElement('div');
        bubble.className = 'fw-assistant-chat-bubble';
        const typing = document.createElement('div');
        typing.className = 'fw-assistant-typing';
        for (let i = 0; i < 3; i += 1) {
            typing.appendChild(document.createElement('span'));
        }
        bubble.appendChild(typing);
        row.appendChild(bubble);
        messages.appendChild(row);
        typingElement = row;
        scrollToBottom();
    }

    function hideTyping() {
        if (!typingElement) {
            return;
        }
        typingElement.remove();
        typingElement = null;
    }

    function safeUrl(url) {
        url = String(url || '').trim();
        if (url.indexOf('/') === 0 && url.indexOf('//') !== 0 && url.indexOf('/\\') !== 0) {
            return url;
        }
        if (/^https?:\/\//i.test(url)) {
            return url;
        }
        return '';
    }

    function formatMoney(amount) {
        const currency = assistant.dataset.currency || '₽';
        const number = Number(amount) || 0;
        const digits = Math.abs(number - Math.round(number)) < 0.001 ? 0 : 2;
        const formatted = new Intl.NumberFormat('ru-RU', {
            minimumFractionDigits: digits,
            maximumFractionDigits: 2
        }).format(number);
        return `${formatted} ${currency}`.trim();
    }

    function runCount(set) {
        const quantity = Number(set && set.quantity) || 0;
        return quantity > 0 ? quantity : 1;
    }

    function renderSet(payload) {
        const products = Array.isArray(payload.products) ? payload.products : [];
        if (!products.length) {
            return;
        }

        const box = document.createElement('div');
        box.className = 'fw-assistant-set';
        const list = document.createElement('div');
        list.className = 'fw-assistant-products';

        products.forEach(product => {
            const row = document.createElement('div');
            row.className = 'fw-assistant-product';
            const href = safeUrl(product.url);
            const link = document.createElement(href ? 'a' : 'div');
            link.className = 'fw-assistant-product-link';
            if (href) {
                link.href = href;
                if (/^https?:\/\//i.test(href)) {
                    link.target = '_blank';
                    link.rel = 'noopener';
                }
            }
            const image = safeUrl(product.image);
            if (image) {
                const img = document.createElement('img');
                img.src = image;
                img.alt = '';
                link.appendChild(img);
            }
            const meta = document.createElement('span');
            meta.className = 'fw-assistant-product-meta';
            const name = document.createElement('strong');
            name.textContent = product.name || product.article || '';
            const article = document.createElement('em');
            article.textContent = product.article ? `арт. ${product.article}` : '';
            const price = document.createElement('b');
            price.textContent = formatMoney(product.price);
            meta.appendChild(name);
            meta.appendChild(article);
            meta.appendChild(price);
            const details = [product.category, product.color].filter(Boolean).join(' · ');
            if (details) {
                const extra = document.createElement('small');
                extra.textContent = details;
                meta.appendChild(extra);
            }
            link.appendChild(meta);
            row.appendChild(link);
            list.appendChild(row);
        });
        box.appendChild(list);

        const set = payload.set || {};
        const total = document.createElement('div');
        total.className = 'fw-assistant-set-total';
        const lines = [`${text.perSet}: ${formatMoney(set.per_set)}`];
        if (Number(set.quantity) > 1) {
            lines.push(`${text.run} ${set.quantity} шт.: ${formatMoney(set.order_total)}`);
        }
        total.textContent = lines.join(' · ');
        box.appendChild(total);

        const actions = document.createElement('div');
        actions.className = 'fw-assistant-set-actions';
        const cartItems = products
            .filter(product => Number(product.id) > 0)
            .map(product => ({
                id: Number(product.id),
                article: product.article,
                count: (Number(product.qty) || 1) * runCount(set)
            }));

        if (assistant.dataset.cartEnabled !== '0' && cartItems.length) {
            const cart = document.createElement('button');
            cart.type = 'button';
            cart.className = 'fw-assistant-cart';
            cart.textContent = text.cart;
            cart.addEventListener('click', () => addToCart(cartItems, cart));
            actions.appendChild(cart);
        }

        if (assistant.dataset.specEnabled !== '0') {
            const spec = document.createElement('button');
            spec.type = 'button';
            spec.className = 'fw-assistant-spec';
            spec.textContent = text.spec;
            spec.addEventListener('click', () => openSpecification(payload, box, spec));
            actions.appendChild(spec);
        }

        if (actions.childNodes.length) {
            box.appendChild(actions);
        }
        messages.appendChild(box);
        scrollToBottom();
    }

    async function postCart(item) {
        if (window.miniShop2 && typeof window.miniShop2.send === 'function') {
            return new Promise(resolve => {
                window.miniShop2.send({
                    action: 'cart/add',
                    id: item.id,
                    count: item.count,
                    ctx: assistant.dataset.context || 'web'
                }, response => resolve(response || { success: true }));
            });
        }

        const mode = assistant.dataset.cartMode || 'minishop2';
        const url = assistant.dataset.cartUrl || '/assets/components/minishop2/action.php';
        let response;
        if (mode === 'custom') {
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ items: [item], ctx: assistant.dataset.context || 'web' })
            });
        } else {
            const body = new URLSearchParams();
            body.set('action', 'cart/add');
            body.set('id', String(item.id));
            body.set('count', String(item.count));
            body.set('ctx', assistant.dataset.context || 'web');
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json'
                },
                body
            });
        }

        const data = await response.json();
        if (!response.ok || data.success === false || data.ok === false) {
            throw new Error(data.message || data.error || text.cartError);
        }
        return data;
    }

    async function addToCart(items, button) {
        const original = button.textContent;
        button.disabled = true;
        try {
            for (let i = 0; i < items.length; i += 1) {
                await postCart(items[i]);
            }
            button.textContent = text.added;
        } catch (error) {
            button.disabled = false;
            button.textContent = original;
            addMessage('assistant', error.message || text.cartError);
        }
    }

    function field(label, name, type) {
        const wrap = document.createElement('label');
        wrap.className = 'fw-assistant-spec-field';
        wrap.textContent = label;
        const input = document.createElement(type === 'textarea' ? 'textarea' : 'input');
        if (type !== 'textarea') {
            input.type = type;
        }
        input.name = name;
        input.required = type === 'email';
        wrap.appendChild(input);
        return wrap;
    }

    function openSpecification(payload, box, button) {
        if (box.querySelector('.fw-assistant-spec-form')) {
            return;
        }
        button.disabled = true;
        const form = document.createElement('form');
        form.className = 'fw-assistant-spec-form';
        form.appendChild(field('Имя', 'name', 'text'));
        form.appendChild(field('Компания', 'company', 'text'));
        form.appendChild(field('Email', 'email', 'email'));
        form.appendChild(field('Телефон', 'phone', 'tel'));
        form.appendChild(field('Комментарий', 'comment', 'textarea'));
        const submit = document.createElement('button');
        submit.type = 'submit';
        submit.textContent = text.spec;
        form.appendChild(submit);
        const error = document.createElement('div');
        error.className = 'fw-assistant-spec-error';
        form.appendChild(error);

        form.addEventListener('submit', async event => {
            event.preventDefault();
            error.textContent = '';
            const specData = new FormData(form);
            const email = String(specData.get('email') || '').trim();
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
                error.textContent = 'Укажите корректный email.';
                return;
            }
            submit.disabled = true;
            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                        action: 'specification',
                        conversation_id: conversationId(),
                        page_url: window.location.href,
                        name: specData.get('name') || '',
                        company: specData.get('company') || '',
                        email,
                        phone: specData.get('phone') || '',
                        comment: specData.get('comment') || '',
                        quantity: payload.set ? payload.set.quantity : 0,
                        products: (payload.products || []).map(product => ({
                            article: product.article,
                            qty: product.qty || 1
                        }))
                    })
                });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.error || text.specError);
                }
                form.replaceWith(Object.assign(document.createElement('div'), {
                    className: 'fw-assistant-spec-success',
                    textContent: data.message || text.specSent
                }));
            } catch (reason) {
                submit.disabled = false;
                error.textContent = reason.message || text.specError;
            }
        });

        box.appendChild(form);
        scrollToBottom();
    }

    async function requestReply(message) {
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                action: 'message',
                message,
                page_url: window.location.href,
                conversation_id: conversationId()
            })
        });

        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error('Invalid server response');
        }
        if (!response.ok || !data.ok) {
            const failure = new Error(data.error || 'Assistant request failed');
            failure.status = response.status;
            throw failure;
        }
        return data;
    }

    function resizeTextarea() {
        chatInput.style.height = 'auto';
        chatInput.style.height = `${Math.min(chatInput.scrollHeight, 110)}px`;
    }

    function updateSendButton() {
        sendButton.disabled = !chatInput.value.trim() || isReplying || chatInput.disabled;
    }

    trigger.addEventListener('click', () => {
        assistant.classList.contains('is-open') ? closeAssistant() : openAssistant();
    });
    closeButton.addEventListener('click', closeAssistant);
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && assistant.classList.contains('is-open')) {
            closeAssistant();
        }
    });

    chatInput.addEventListener('input', () => {
        resizeTextarea();
        updateSendButton();
    });
    chatInput.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            if (!sendButton.disabled) {
                if (typeof chatForm.requestSubmit === 'function') {
                    chatForm.requestSubmit();
                } else {
                    chatForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                }
            }
        }
    });

    chatForm.addEventListener('submit', async event => {
        event.preventDefault();
        const value = chatInput.value.trim();
        if (!value || isReplying || chatInput.disabled) {
            return;
        }

        addMessage('user', value);
        chatInput.value = '';
        resizeTextarea();
        isReplying = true;
        updateSendButton();
        showTyping();

        try {
            const result = await requestReply(value);
            hideTyping();
            addMessage('assistant', result.message || '');
            renderSet(result);
        } catch (error) {
            hideTyping();
            addMessage('assistant', error.status === 429 ? text.busy : text.requestError);
        } finally {
            isReplying = false;
            updateSendButton();
            if (!chatInput.disabled) {
                chatInput.focus();
            }
        }
    });

    setTimeout(() => {
        if (!assistant.classList.contains('is-open')) {
            trigger.classList.add('fw-assistant-attention');
            if (hint) {
                hint.style.opacity = '1';
                hint.style.visibility = 'visible';
                hint.style.transform = 'translateX(0)';
            }
            setTimeout(() => trigger.classList.remove('fw-assistant-attention'), 1500);
        }
    }, 4000);
});
