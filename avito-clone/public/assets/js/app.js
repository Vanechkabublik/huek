/* ==========================================================================
   Авито-клон — клиентская логика (vanilla JS, fetch, WebSocket)

   1. Утилиты и DOM-хелперы      6. Страница объявления
   2. API-клиент                 7. Вход / регистрация
   3. UI: тосты, модалки         8. Создание / редактирование объявления
   4. Шапка, тема, избранное,    9. Мои объявления, избранное, профиль продавца,
      realtime, города, графики     настройки, статистика объявления
   5. Карточки и главная

   Страницы чата и админки живут в отдельных файлах (chat.js, admin.js) и берут помощники
   из window.Board. Весь пользовательский текст попадает в DOM через textContent / createElement —
   innerHTML не используется, поэтому XSS через данные объявлений невозможен.
   ========================================================================== */
(() => {
    'use strict';

    /* ======================================================================
       1. Утилиты и DOM-хелперы
       ====================================================================== */

    const APP = window.__APP__ || { csrf: '', user: null };
    const SVG_NS = 'http://www.w3.org/2000/svg';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

    /** Создаёт элемент: el('div', {class: 'x', onClick: fn}, 'текст', childNode, ...). */
    function el(tag, props, ...children) {
        const node = document.createElement(tag);
        for (const [key, value] of Object.entries(props || {})) {
            if (value === null || value === undefined || value === false) continue;
            if (key === 'class') node.className = value;
            else if (key === 'dataset') Object.assign(node.dataset, value);
            else if (key === 'style' && typeof value === 'object') Object.assign(node.style, value);
            else if (/^on[A-Z]/.test(key) && typeof value === 'function') node.addEventListener(key.slice(2).toLowerCase(), value);
            else node.setAttribute(key, value === true ? '' : String(value));
        }
        for (const child of children.flat(Infinity)) {
            if (child === null || child === undefined || child === false) continue;
            node.append(child instanceof Node ? child : document.createTextNode(String(child)));
        }
        return node;
    }

    /** SVG-иконка из спрайта на странице (templates/icons.php). */
    function icon(name, cls = '') {
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', ('icon ' + cls).trim());
        svg.setAttribute('aria-hidden', 'true');
        const use = document.createElementNS(SVG_NS, 'use');
        use.setAttribute('href', '#i-' + name);
        svg.append(use);
        return svg;
    }

    const debounce = (fn, ms = 300) => {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        };
    };

    const plural = (n, one, few, many) => {
        const a = Math.abs(n) % 100;
        const b = a % 10;
        if (a > 10 && a < 20) return many;
        if (b > 1 && b < 5) return few;
        return b === 1 ? one : many;
    };

    const clamp = (n, min, max) => Math.min(max, Math.max(min, n));
    const qs = (params) => {
        const sp = new URLSearchParams();
        for (const [k, v] of Object.entries(params || {})) {
            if (v !== '' && v !== null && v !== undefined) sp.set(k, v);
        }
        const s = sp.toString();
        return s ? '?' + s : '';
    };

    /** Безопасный редирект: только на относительные пути своего сайта. */
    const safeNext = (value, fallback = '/index.php') =>
        value && value.startsWith('/') && !value.startsWith('//') ? value : fallback;

    /* ======================================================================
       2. API-клиент
       ====================================================================== */

    class ApiError extends Error {
        constructor(message, status = 0, errors = {}) {
            super(message);
            this.status = status;
            this.errors = errors;
        }
    }

    async function api(url, { method = 'GET', data = null, form = null } = {}) {
        const headers = { Accept: 'application/json' };
        const opts = { method, headers, credentials: 'same-origin' };
        if (method !== 'GET') headers['X-CSRF-Token'] = APP.csrf;
        if (form) {
            opts.body = form;
        } else if (data) {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(data);
        }

        let res;
        try {
            res = await fetch(url, opts);
        } catch {
            throw new ApiError('Нет соединения с сервером. Проверьте интернет и повторите.', 0);
        }
        let json = null;
        try { json = await res.json(); } catch { /* ответ не JSON */ }

        if (!res.ok || !json || json.ok === false) {
            if (res.status === 419) {
                toast('Сессия устарела — обновляем страницу…', 'warning');
                setTimeout(() => location.reload(), 1200);
            }
            throw new ApiError((json && json.error) || `Ошибка сервера (${res.status})`, res.status, (json && json.errors) || {});
        }
        return json;
    }

    const apiGet = (file, params) => api(`/api/${file}.php${qs(params)}`);
    const apiPost = (file, action, data = {}) => api(`/api/${file}.php?action=${action}`, { method: 'POST', data });

    /** Показывает ошибку запроса: если сессия кончилась — ведёт на страницу входа. */
    function handleApiError(err) {
        if (err instanceof ApiError && err.status === 401) {
            toast(err.message, 'warning');
            setTimeout(() => (location.href = '/login.php?next=' + encodeURIComponent(location.pathname + location.search)), 900);
            return;
        }
        toast(err.message || 'Что-то пошло не так', 'error');
    }

    /* ======================================================================
       3. UI: тосты, кнопки-загрузки, ошибки форм, модальные окна
       ====================================================================== */

    const TOAST_ICONS = { success: 'check-circle', error: 'x-circle', warning: 'clock', info: 'alert-circle' };

    /** Всплывающее уведомление сверху справа; исчезает через 3 секунды. */
    function toast(message, type = 'success', ms = 3000) {
        const root = $('#toasts');
        if (!root) return null;
        const node = el('div', { class: `toast toast--${type}`, role: 'status' }, icon(TOAST_ICONS[type] || 'alert-circle'), el('span', {}, message));
        root.append(node);
        while (root.children.length > 4) root.firstElementChild.remove();
        const hide = () => {
            node.classList.add('is-leaving');
            setTimeout(() => node.remove(), 240);
        };
        const timer = setTimeout(hide, ms);
        node.addEventListener('click', () => { clearTimeout(timer); hide(); });
        return node;
    }

    function setLoading(btn, on) {
        if (!btn) return;
        btn.classList.toggle('is-loading', on);
        btn.disabled = on;
    }

    /** Оборачивает действие кнопки: спиннер на время запроса + обработка ошибок. */
    async function withLoading(btn, fn) {
        setLoading(btn, true);
        try {
            return await fn();
        } catch (err) {
            handleApiError(err);
        } finally {
            setLoading(btn, false);
        }
    }

    function clearErrors(root) {
        $$('[data-error-for]', root).forEach((p) => (p.textContent = ''));
        $$('.is-invalid', root).forEach((n) => n.classList.remove('is-invalid'));
    }

    /** Подсвечивает поля и пишет ошибки под ними. Возвращает true, если нашлось куда писать. */
    function showErrors(root, errors) {
        let first = null;
        for (const [name, message] of Object.entries(errors || {})) {
            const p = $(`[data-error-for="${name}"]`, root);
            const input = $(`[name="${name}"]`, root) || (name === 'images' ? $('#dropzone', root) : null);
            if (p) p.textContent = message;
            if (input) input.classList.add('is-invalid');
            first = first || input || p;
        }
        if (first && first.scrollIntoView) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
        return Boolean(first);
    }

    /** Снимает красную подсветку поля, как только пользователь начал править. */
    function liveClear(root) {
        root.addEventListener('input', (e) => {
            const t = e.target;
            if (!t.name) return;
            t.classList.remove('is-invalid');
            const p = $(`[data-error-for="${t.name}"]`, root);
            if (p) p.textContent = '';
        });
    }

    /** Блок поля формы для модалок: подпись + контрол + место под ошибку. */
    function field({ name, label, type = 'text', value = '', placeholder = '', rows = 0, maxlength = 0, hint = '', autocomplete = 'off' }) {
        const control = rows
            ? el('textarea', { class: 'textarea', name, rows, placeholder, maxlength: maxlength || null })
            : el('input', { class: 'input', name, type, placeholder, maxlength: maxlength || null, autocomplete });
        control.value = value;
        if (rows) control.style.minHeight = '110px';
        return el('div', { class: 'field' },
            label ? el('label', { class: 'field__label' }, label) : null,
            control,
            hint ? el('p', { class: 'field__hint' }, hint) : null,
            el('p', { class: 'field__error', 'data-error-for': name }));
    }

    /**
     * Универсальная модалка. Возвращает Promise: результат onSubmit (или true) при подтверждении,
     * false — если закрыли крестиком/Esc/кликом по фону.
     * onSubmit(form) может бросить ApiError — тогда ошибки покажутся под полями, окно останется открытым.
     */
    function openModal({ title, text = '', content = null, submitText = 'Подтвердить', cancelText = 'Отмена', danger = false, hideCancel = false, submitKind = '', modalClass = '', onSubmit = null }) {
        return new Promise((resolve) => {
            const root = $('#modalRoot');
            const overlay = el('div', { class: 'modal-overlay', role: 'dialog', 'aria-modal': 'true', 'aria-label': title });
            const cancelBtn = el('button', { type: 'button', class: 'btn btn--outline' }, cancelText);
            const submitBtn = el('button', { type: 'submit', class: `btn ${submitKind || (danger ? 'btn--danger' : 'btn--primary')}` }, submitText);
            const form = el('form', { class: `modal${modalClass ? ' ' + modalClass : ''}`, novalidate: true },
                el('h3', { class: 'modal__title' }, title),
                text ? el('p', { class: 'modal__text' }, text) : null,
                content,
                el('div', { class: 'modal__actions' }, hideCancel ? null : cancelBtn, submitBtn));
            overlay.append(form);

            let closed = false;
            const onKey = (e) => {
                // Esc закрывает только верхнее из открытых окон
                const open = $$('.modal-overlay.is-open');
                if (e.key === 'Escape' && open[open.length - 1] === overlay) close(false);
            };
            function close(result) {
                if (closed) return;
                closed = true;
                document.removeEventListener('keydown', onKey);
                overlay.classList.remove('is-open');
                if (!$('.modal-overlay.is-open')) document.body.classList.remove('has-overlay');
                setTimeout(() => overlay.remove(), 230);
                resolve(result);
            }

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!onSubmit) return close(true);
                clearErrors(form);
                setLoading(submitBtn, true);
                try {
                    const result = await onSubmit(form);
                    if (result !== false) close(result === undefined ? true : result);
                } catch (err) {
                    if (!(err instanceof ApiError && showErrors(form, err.errors))) handleApiError(err);
                } finally {
                    setLoading(submitBtn, false);
                }
            });
            liveClear(form);
            cancelBtn.addEventListener('click', () => close(false));
            overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(false); });
            document.addEventListener('keydown', onKey);

            root.append(overlay);
            document.body.classList.add('has-overlay');
            requestAnimationFrame(() => {
                overlay.classList.add('is-open');
                (form.querySelector('input, textarea') || submitBtn).focus({ preventScroll: true });
            });
        });
    }

    /** confirm() в стиле сайта. */
    const confirmDialog = (text, { title = 'Подтвердите действие', confirmText = 'Подтвердить', danger = true } = {}) =>
        openModal({ title, text, submitText: confirmText, danger }).then(Boolean);

    /** Модалка «Укажите причину» (отклонение / блокировка). Резолвится строкой причины или null. */
    async function askReason({ title = 'Укажите причину', text = '', submitText = 'Подтвердить', danger = true }) {
        let reason = null;
        const ok = await openModal({
            title, text, submitText, danger,
            content: field({ name: 'reason', rows: 4, maxlength: 1000, placeholder: 'Объясните автору, что нужно исправить' }),
            onSubmit: (form) => {
                const value = form.elements.reason.value.trim();
                if (value.length < 3) throw new ApiError('', 422, { reason: 'Укажите причину (минимум 3 символа)' });
                reason = value;
            },
        });
        return ok ? reason : null;
    }

    /* ======================================================================
       4. Шапка: меню, выход, тема, избранное, realtime-бейдж
       ====================================================================== */

    /** Применяет тему оформления (светлая/тёмная) и запоминает выбор. */
    function applyTheme(theme, save = true) {
        document.documentElement.setAttribute('data-theme', theme);
        const meta = $('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', theme === 'dark' ? '#0f1318' : '#00aaff');
        if (save) {
            try { localStorage.setItem('theme', theme); } catch { /* приватный режим */ }
        }
    }

    function initTheme() {
        $$('#themeToggle').forEach((btn) => btn.addEventListener('click', () => {
            const dark = document.documentElement.getAttribute('data-theme') === 'dark';
            applyTheme(dark ? 'light' : 'dark');
        }));
        // Если пользователь не выбирал тему вручную — следуем за системной
        try {
            const mq = window.matchMedia('(prefers-color-scheme: dark)');
            mq.addEventListener('change', (e) => { if (!localStorage.getItem('theme')) applyTheme(e.matches ? 'dark' : 'light', false); });
        } catch { /* старые браузеры */ }
        applyTheme(document.documentElement.getAttribute('data-theme') || 'light', false);
    }

    /** Сердечко «в избранное»: серверная и клиентская кнопки помечаются атрибутом data-fav-ad. */
    function favBtn(adId, active, large = false) {
        return el('button', {
            type: 'button', class: `fav-btn${large ? ' fav-btn--lg' : ''}${active ? ' is-active' : ''}`,
            'data-fav-ad': adId, 'aria-pressed': String(Boolean(active)), title: 'В избранное', 'aria-label': 'В избранное',
        }, icon('heart'));
    }

    function setFavState(adId, on, animate = false) {
        $$(`[data-fav-ad="${adId}"]`).forEach((b) => {
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', String(on));
            if (animate && on) { b.classList.remove('is-pop'); void b.offsetWidth; b.classList.add('is-pop'); }
        });
    }

    function initFavoriteClicks() {
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-fav-ad]');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            if (!APP.user) {
                toast('Войдите, чтобы добавлять в избранное', 'info');
                setTimeout(() => (location.href = '/login.php?next=' + encodeURIComponent(location.pathname + location.search)), 800);
                return;
            }
            const id = Number(btn.dataset.favAd);
            const was = btn.classList.contains('is-active');
            setFavState(id, !was, true);                       // оптимистично
            try {
                const res = await apiPost('favorites', 'toggle', { ad_id: id });
                setFavState(id, res.is_favorite);
                toast(res.is_favorite ? 'Добавлено в избранное' : 'Убрано из избранного', 'success', 1800);
                document.dispatchEvent(new CustomEvent('fav:changed', { detail: { id, active: res.is_favorite } }));
            } catch (err) {
                setFavState(id, was);
                handleApiError(err);
            }
        });
    }

    function initHeader() {
        const menu = $('#userMenu');
        if (menu) {
            const btn = $('.user-menu__btn', menu);
            const set = (open) => {
                menu.classList.toggle('is-open', open);
                btn.setAttribute('aria-expanded', String(open));
            };
            btn.addEventListener('click', (e) => { e.stopPropagation(); set(!menu.classList.contains('is-open')); });
            document.addEventListener('click', (e) => { if (!menu.contains(e.target)) set(false); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') set(false); });
        }

        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-action="logout"]');
            if (!btn) return;
            await withLoading(btn, async () => {
                await apiPost('auth', 'logout');
                location.href = '/login.php';
            });
        });

        initTheme();
        initFavoriteClicks();

        // Одноразовые сообщения после редиректов (вход через VK, профиль и т. п.)
        if (APP.flash && APP.flash.error) toast(APP.flash.error, 'error', 5000);
        if (APP.flash && APP.flash.ok) toast(APP.flash.ok, 'success', 3500);
    }

    /* ======================================================================
       4b. Realtime: WebSocket с автопереподключением (события чата)
       ====================================================================== */

    const realtime = (() => {
        const handlers = {};
        let ws = null;
        let open = false;
        let attempt = 0;
        let pingTimer = null;
        let reconnectTimer = null;
        const baseTitle = document.title;

        const on = (type, fn) => { (handlers[type] ||= []).push(fn); };
        const emit = (type, data) => (handlers[type] || []).forEach((fn) => { try { fn(data); } catch (e) { console.error(e); } });

        function setBadge(count) {
            const n = Number(count) || 0;
            const text = n > 9 ? '9+' : String(n);
            ['chatBadge', 'chatBadgeMobile'].forEach((id) => {
                const b = document.getElementById(id);
                if (b) { b.textContent = text; b.hidden = n === 0; }
            });
            const m = document.getElementById('menuChatCount');
            if (m) { m.textContent = String(n); m.hidden = n === 0; }
            document.title = (n > 0 ? `(${n}) ` : '') + baseTitle.replace(/^\(\d+\)\s*/, '');
        }

        async function connect() {
            if (!APP.user || ws) return;
            try {
                const { ticket, url, port } = await apiGet('chat', { action: 'ticket' });
                const base = url || `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.hostname}:${port}`;
                ws = new WebSocket(`${base}${base.includes('?') ? '&' : '?'}ticket=${encodeURIComponent(ticket)}`);
            } catch {
                scheduleReconnect();
                return;
            }
            ws.onopen = () => {
                open = true;
                attempt = 0;
                clearInterval(pingTimer);
                pingTimer = setInterval(() => send({ t: 'ping' }), 25000);
                emit('open', {});
            };
            ws.onmessage = (ev) => {
                let msg;
                try { msg = JSON.parse(ev.data); } catch { return; }
                if (msg.t === 'hello') setBadge(msg.unread);
                if (msg.t === 'unread') setBadge(msg.count);
                emit(msg.t, msg);
            };
            ws.onclose = () => {
                const wasOpen = open;
                open = false;
                ws = null;
                clearInterval(pingTimer);
                if (wasOpen) emit('close', {});
                scheduleReconnect();
            };
            ws.onerror = () => { try { ws && ws.close(); } catch { /* уже закрыт */ } };
        }

        function scheduleReconnect() {
            clearTimeout(reconnectTimer);
            attempt += 1;
            const delay = Math.min(30000, 1000 * 2 ** Math.min(attempt, 5)) + Math.random() * 500;
            reconnectTimer = setTimeout(connect, delay);
            if (attempt === 2) emit('close', {});          // WebSocket недоступен — страницы могут включить опрос
        }

        function send(obj) {
            if (ws && open) { ws.send(JSON.stringify(obj)); return true; }
            return false;
        }

        function start() {
            if (!APP.user) return;
            connect();
            // Реакция на новые сообщения вне страницы чата: всплывающее уведомление
            on('message', (m) => {
                if (document.body.dataset.page === 'chat' || m.message.sender_id === APP.user.id) return;
                const who = m.conversation && m.conversation.peer ? m.conversation.peer.name : 'Новое сообщение';
                const t = toast(`${who}: ${m.message.body.slice(0, 80)}`, 'info', 5000);
                if (t) { t.style.cursor = 'pointer'; t.addEventListener('click', () => (location.href = `/chat.php?c=${m.message.conversation_id}`)); }
            });
            document.addEventListener('visibilitychange', () => { if (!document.hidden && !ws) { attempt = 0; connect(); } });
        }

        return { on, send, start, setBadge, get isOpen() { return open; } };
    })();

    /* ======================================================================
       4c. Подсказки городов (список лежит в assets/data/cities.json)
       ====================================================================== */

    let citiesPromise = null;
    const loadCities = () => (citiesPromise ||= fetch('/assets/data/cities.json').then((r) => r.json()).catch(() => []));

    /** Выпадающий список подсказок под полем ввода: ↑↓ Enter Esc, подсветка совпадения. */
    function attachCityAutocomplete(input, known = []) {
        const wrap = el('div', { class: 'suggest' });
        input.parentNode.insertBefore(wrap, input);
        wrap.append(input);
        const list = el('div', { class: 'suggest__list', role: 'listbox', hidden: true });
        wrap.append(list);
        let items = [];
        let active = -1;
        let muted = false;

        const norm = (s) => s.toLowerCase().replaceAll('ё', 'е');
        const close = () => { list.hidden = true; active = -1; };
        const pick = (name) => {
            input.value = name;
            muted = true;                                   // событие input нужно форме (сброс ошибок), но не списку подсказок
            input.dispatchEvent(new Event('input', { bubbles: true }));
            muted = false;
            close();
        };
        const highlight = () => $$('.suggest__item', list).forEach((n, i) => {
            n.classList.toggle('is-active', i === active);
            if (i === active) n.scrollIntoView({ block: 'nearest' });
        });

        async function render() {
            if (muted) return;
            const q = norm(input.value.trim());
            const all = await loadCities();
            const knownSet = new Set(known);
            const pool = [...known, ...all.filter((c) => !knownSet.has(c))];
            const starts = pool.filter((c) => norm(c).startsWith(q));
            const contains = pool.filter((c) => !norm(c).startsWith(q) && norm(c).includes(q));
            items = (q ? [...starts, ...contains] : pool).slice(0, 8);
            list.replaceChildren(...items.map((name) => {
                const row = el('div', { class: 'suggest__item', role: 'option' });
                const i = norm(name).indexOf(q);
                if (q && i >= 0) row.append(name.slice(0, i), el('mark', {}, name.slice(i, i + q.length)), name.slice(i + q.length));
                else row.append(name);
                if (knownSet.has(name)) row.append(el('small', {}, 'есть объявления'));
                row.addEventListener('mousedown', (e) => { e.preventDefault(); pick(name); });
                return row;
            }));
            list.hidden = items.length === 0;
            active = -1;
        }

        input.addEventListener('input', render);
        input.addEventListener('focus', render);
        input.addEventListener('blur', () => setTimeout(close, 120));
        input.addEventListener('keydown', (e) => {
            if (list.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % items.length; highlight(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + items.length) % items.length; highlight(); }
            else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); pick(items[active]); }
            else if (e.key === 'Escape') close();
        });
    }

    /* ======================================================================
       4d. Графики (SVG без библиотек)
       ====================================================================== */

    /**
     * Столбчатая диаграмма. days: [{label, <series.key>: число}], series: [{key, label, color}].
     * Несколько серий рисуются сгруппированными столбцами.
     */
    function barChart(days, series, { height = 220, width = 720 } = {}) {
        const W = width;
        const m = { l: 34, r: 8, t: 10, b: 28 };
        const plotW = W - m.l - m.r;
        const plotH = height - m.t - m.b;
        const rawMax = Math.max(1, ...days.flatMap((d) => series.map((s) => d[s.key] || 0)));
        const step = [1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000].find((s) => rawMax / s <= 4) || Math.ceil(rawMax / 4);
        const max = Math.ceil(rawMax / step) * step;
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', `0 0 ${W} ${height}`);
        svg.setAttribute('role', 'img');
        const add = (name, attrs, text) => {
            const n = document.createElementNS(SVG_NS, name);
            for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
            if (text !== undefined) n.textContent = text;
            svg.append(n);
            return n;
        };
        for (let v = 0; v <= max; v += step) {
            const y = m.t + plotH - (v / max) * plotH;
            add('line', { x1: m.l, x2: W - m.r, y1: y, y2: y, class: 'grid-line' });
            add('text', { x: m.l - 6, y: y + 4, 'text-anchor': 'end' }, String(v));
        }
        const group = plotW / days.length;
        const barW = Math.min(26, (group * 0.72) / series.length);
        days.forEach((d, i) => {
            const gx = m.l + i * group + (group - barW * series.length) / 2;
            series.forEach((s, j) => {
                const val = d[s.key] || 0;
                const h = (val / max) * plotH;
                const rect = add('rect', {
                    x: gx + j * barW, y: m.t + plotH - h, width: Math.max(2, barW - 2), height: Math.max(h, val > 0 ? 2 : 0), rx: 3, class: 'bar',
                });
                rect.style.fill = s.color;
                const title = document.createElementNS(SVG_NS, 'title');
                title.textContent = `${d.label}: ${s.label} — ${val}`;
                rect.append(title);
            });
            if (days.length <= 8 || (days.length - 1 - i) % 2 === 0) {   // подписи через одну, начиная с последнего дня
                add('text', { x: m.l + i * group + group / 2, y: height - 8, 'text-anchor': 'middle' }, d.label);
            }
        });
        return svg;
    }

    /* ======================================================================
       5. Карточки объявлений и главная страница
       ====================================================================== */

    /**
     * Карточка объявления. owner = true добавляет бейдж статуса, причину, статистику и кнопки управления.
     */
    function adCard(ad, { owner = false, index = 0, onDelete = null } = {}) {
        const media = el('div', { class: 'card__media' });
        if (ad.cover) media.append(el('img', { src: ad.cover, alt: ad.title, loading: 'lazy', decoding: 'async' }));
        else media.append(el('div', { class: 'card__noimg' }, icon('image')));

        const badges = el('div', { class: 'card__badges' });
        if (owner || ad.status !== 'approved') badges.append(el('span', { class: `badge badge--${ad.status}` }, ad.status_label));
        if (ad.is_hit && ad.status === 'approved') badges.append(el('span', { class: 'badge badge--hit' }, icon('flame'), 'Хит'));
        if (badges.children.length) media.append(badges);
        if (ad.images_count > 1) media.append(el('span', { class: 'card__photos' }, icon('camera'), String(ad.images_count)));

        const card = el('article', { class: 'card', style: { animationDelay: Math.min(index, 11) * 35 + 'ms' } },
            el('a', { class: 'card__link', href: ad.url },
                media,
                el('div', { class: 'card__body' },
                    el('h3', { class: 'card__title' }, ad.title),
                    ad.specs ? el('div', { class: 'card__specs' }, ad.specs) : null,
                    el('div', { class: 'card__price' }, ad.price_text),
                    el('div', { class: 'card__meta' }, ad.city, el('br'), ad.date_text))));

        if (!owner && ad.status === 'approved') card.append(favBtn(ad.id, ad.is_favorite));

        if (owner) {
            if (ad.status === 'rejected' || ad.status === 'blocked') {
                card.append(el('div', { class: `card__reason card__reason--${ad.status}` },
                    el('b', {}, ad.status === 'rejected' ? 'Причина отклонения' : 'Причина блокировки'),
                    ad.rejection_reason || 'Причина не указана'));
            } else if (ad.status === 'pending') {
                card.append(el('div', { class: 'card__reason card__reason--pending' }, 'Объявление проверяет модератор — обычно это занимает до нескольких часов.'));
            }
            card.append(el('div', { class: 'card__reason card__reason--stat' },
                el('span', {}, icon('eye'), ` ${ad.views}`),
                el('span', {}, icon('heart'), ` ${ad.fav_count || 0}`)));
            const actions = el('div', { class: 'card__actions' });
            if (ad.status !== 'blocked') {
                actions.append(el('a', { class: 'btn btn--outline btn--sm', href: `/create-ad.php?id=${ad.id}` }, icon('edit'), 'Изменить'));
            }
            actions.append(el('a', { class: 'btn btn--outline btn--sm', href: `/ad-stats.php?id=${ad.id}` }, icon('trending'), 'Статистика'));
            actions.append(el('button', { type: 'button', class: 'btn btn--outline btn--sm btn--danger-text', 'aria-label': 'Удалить', onClick: () => onDelete && onDelete(ad, card) }, icon('trash')));
            card.append(actions);
        }
        return card;
    }

    function skeletonCards(n = 8) {
        return Array.from({ length: n }, () =>
            el('article', { class: 'card is-skeleton', 'aria-hidden': 'true' },
                el('div', { class: 'card__link' },
                    el('div', { class: 'card__media' }, el('div', { class: 'sk' })),
                    el('div', { class: 'card__body' },
                        el('div', { class: 'sk sk-line', style: { width: '90%' } }),
                        el('div', { class: 'sk sk-line', style: { width: '55%', height: '22px' } }),
                        el('div', { class: 'sk sk-line', style: { width: '40%' } })))));
    }

    function emptyState({ emoji = '🔍', title, text, action = null }) {
        return el('div', { class: 'empty' },
            el('div', { class: 'empty__icon' }, emoji),
            el('h3', {}, title),
            el('p', {}, text),
            action);
    }

    /** Главная: фильтры (включая характеристики категории), поиск с debounce, «Показать ещё». Состояние живёт в URL. */
    async function initIndex() {
        const form = $('#filtersForm');
        const grid = $('#adsGrid');
        const searchInput = $('#searchInput');
        const searchForm = $('#searchForm');
        const loadMoreBox = $('#loadMoreBox');
        const loadMoreBtn = $('#loadMore');
        const filters = $('#filters');
        const overlay = $('#sheetOverlay');
        const attrBox = $('#attrFilters');
        const state = { page: 1, token: 0 };

        const categoryNames = Object.fromEntries($$('#fCategory option').map((o) => [o.value, o.textContent.replace(/^\S+\s/, '')]));
        let attrDefs = {};                                            // id категории → определения характеристик
        try {
            const meta = await apiGet('listings', { action: 'meta' });
            attrDefs = Object.fromEntries(meta.categories.map((c) => [String(c.id), (c.attributes || []).filter((a) => a.filterable)]));
        } catch { /* без фильтров по характеристикам сайт всё равно работает */ }

        /** Рисует поля фильтров по характеристикам выбранной категории. */
        function renderAttrFilters(catId, values = {}) {
            const defs = attrDefs[String(catId)] || [];
            attrBox.replaceChildren();
            if (!defs.length) return;
            attrBox.append(el('div', { class: 'filters__section-title' }, 'Характеристики'));
            for (const d of defs) {
                const unit = d.unit ? `, ${d.unit}` : '';
                if (d.type === 'number') {
                    attrBox.append(el('div', { class: 'field' },
                        el('span', { class: 'field__label' }, d.label + unit),
                        el('div', { class: 'range' },
                            el('input', { class: 'input', type: 'number', min: d.min ?? '', max: d.max ?? '', inputmode: 'numeric', name: `a_${d.code}_min`, placeholder: 'от', value: values[`a_${d.code}_min`] ?? '', 'aria-label': `${d.label} от` }),
                            el('span', { class: 'range__dash' }, '—'),
                            el('input', { class: 'input', type: 'number', min: d.min ?? '', max: d.max ?? '', inputmode: 'numeric', name: `a_${d.code}_max`, placeholder: 'до', value: values[`a_${d.code}_max`] ?? '', 'aria-label': `${d.label} до` }))));
                } else {
                    const select = el('select', { class: 'select', name: `a_${d.code}`, 'aria-label': d.label },
                        el('option', { value: '' }, 'Любой'),
                        ...d.options.map((o) => el('option', { value: o }, o)));
                    select.value = values[`a_${d.code}`] ?? '';
                    attrBox.append(el('div', { class: 'field' }, el('label', { class: 'field__label' }, d.label), select));
                }
            }
        }

        const readAttrs = () => Object.fromEntries($$('[name]', attrBox).map((n) => [n.name, n.value.trim()]).filter(([, v]) => v !== ''));

        const readParams = () => {
            const fd = new FormData(form);
            return {
                q: searchInput.value.trim(),
                category: fd.get('category') === '0' ? '' : fd.get('category'),
                city: fd.get('city'),
                price_min: fd.get('price_min'),
                price_max: fd.get('price_max'),
                sort: fd.get('sort') === 'date' ? '' : fd.get('sort'),
                user: fd.get('user'),
                ...readAttrs(),
            };
        };

        const syncUrl = (p) => history.replaceState(null, '', location.pathname + qs(p));

        const syncChips = () => {
            const cat = form.elements.category.value;
            $$('#catBar .cat-chip').forEach((chip) => chip.classList.toggle('is-active', chip.dataset.category === cat));
        };

        const setSheet = (open) => {
            filters.classList.toggle('is-open', open);
            overlay.classList.toggle('is-open', open);
            document.body.classList.toggle('has-overlay', open);
        };

        function renderHead(p, total) {
            const title = p.q ? `Результаты по запросу «${p.q}»` : (p.category ? categoryNames[p.category] || 'Объявления' : 'Объявления');
            $('#resultsTitle').textContent = title;
            $('#resultsCount').textContent = total === 0 ? 'Ничего не найдено' : `Найдено ${total} ${plural(total, 'объявление', 'объявления', 'объявлений')}`;
        }

        async function load({ append = false } = {}) {
            const params = readParams();
            const token = ++state.token;
            if (append) {
                state.page += 1;
            } else {
                state.page = 1;
                grid.replaceChildren(...skeletonCards(8));
                loadMoreBox.hidden = true;
                syncUrl(params);
                syncChips();
            }

            try {
                const data = await apiGet('listings', { action: 'list', ...params, page: state.page });
                if (token !== state.token) return;          // пришёл устаревший ответ

                if (!append) grid.replaceChildren();
                const offset = grid.children.length;
                data.items.forEach((ad, i) => grid.append(adCard(ad, { index: i })));
                renderHead(params, data.total);

                if (data.total === 0) {
                    grid.append(emptyState({
                        emoji: '🔍',
                        title: 'По вашему запросу ничего не найдено',
                        text: 'Попробуйте изменить запрос или сбросить фильтры.',
                        action: el('button', { class: 'btn btn--primary', type: 'button', onClick: reset }, 'Сбросить фильтры'),
                    }));
                }
                loadMoreBox.hidden = !data.has_more;
                $('#shownInfo').textContent = `Показано ${offset + data.items.length} из ${data.total}`;
            } catch (err) {
                if (token !== state.token) return;
                if (append) state.page -= 1;
                else grid.replaceChildren(emptyState({
                    emoji: '😵', title: 'Не удалось загрузить объявления', text: err.message,
                    action: el('button', { class: 'btn btn--primary', type: 'button', onClick: () => load() }, 'Повторить'),
                }));
                toast(err.message, 'error');
            } finally {
                setLoading(loadMoreBtn, false);
            }
        }

        function reset() {
            form.reset();
            form.elements.city.value = '';
            form.elements.category.value = '0';
            form.elements.price_min.value = '';
            form.elements.price_max.value = '';
            form.elements.user.value = '';
            form.elements.sort.value = 'date';
            searchInput.value = '';
            attrBox.replaceChildren();
            $('#sellerBanner')?.remove();
            setSheet(false);
            load();
        }

        // фильтры
        form.addEventListener('submit', (e) => { e.preventDefault(); setSheet(false); load(); });
        $('#filtersReset').addEventListener('click', reset);
        form.elements.category.addEventListener('change', () => { renderAttrFilters(form.elements.category.value); syncChips(); });

        // поиск: живой (debounce 300 мс) и по Enter
        const liveSearch = debounce(() => load(), 300);
        searchInput.addEventListener('input', liveSearch);
        searchForm.addEventListener('submit', (e) => { e.preventDefault(); searchInput.blur(); load(); });

        // категории в полосе под шапкой
        $('#catBar').addEventListener('click', (e) => {
            const chip = e.target.closest('.cat-chip');
            if (!chip) return;
            e.preventDefault();
            form.elements.category.value = chip.dataset.category;
            renderAttrFilters(chip.dataset.category);
            load();
        });

        loadMoreBtn.addEventListener('click', () => { setLoading(loadMoreBtn, true); load({ append: true }); });

        // мобильная шторка фильтров
        $('#filtersOpen').addEventListener('click', () => setSheet(true));
        $('#filtersClose').addEventListener('click', () => setSheet(false));
        overlay.addEventListener('click', () => setSheet(false));

        // Начальное состояние характеристик берём из URL (?category=1&a_year_min=2015…)
        renderAttrFilters(form.elements.category.value, Object.fromEntries(new URLSearchParams(location.search)));
        load();
    }

    /* ======================================================================
       6. Страница объявления
       ====================================================================== */

    function initAdPage() {
        const root = $('.ad-page');
        const adId = Number(root.dataset.adId);

        initGallery();

        // --- телефон (один раз на человека: повторный показ не раздувает статистику)
        const phoneBtn = $('#showPhoneBtn');
        if (phoneBtn) {
            phoneBtn.addEventListener('click', async () => {
                if (phoneBtn.dataset.tel) {                       // номер уже показан — звоним
                    location.href = 'tel:' + phoneBtn.dataset.tel;
                    return;
                }
                await withLoading(phoneBtn, async () => {
                    const res = await apiPost('listings', 'phone', { id: adId });
                    if (!res.phone) {
                        toast('Продавец не указал телефон — напишите ему сообщение', 'warning', 3500);
                        return;
                    }
                    phoneBtn.dataset.tel = res.tel;
                    phoneBtn.classList.add('is-revealed');
                    phoneBtn.replaceChildren(icon('phone'), el('span', {}, res.phone));
                });
            });
        }

        // --- поделиться
        $('#shareBtn').addEventListener('click', async () => {
            const url = location.href.split('#')[0];
            if (navigator.share) {
                try { await navigator.share({ title: document.title, url }); return; } catch { /* пользователь отменил */ }
            }
            try {
                await navigator.clipboard.writeText(url);
                toast('Ссылка скопирована');
            } catch {
                window.prompt('Скопируйте ссылку:', url);
            }
        });

        // --- удаление (владелец)
        const delBtn = $('#deleteAdBtn');
        if (delBtn) {
            delBtn.addEventListener('click', async () => {
                if (!(await confirmDialog('Объявление и все его фото будут удалены без возможности восстановления.', { title: 'Удалить объявление?', confirmText: 'Удалить' }))) return;
                await withLoading(delBtn, async () => {
                    await apiPost('listings', 'delete', { id: adId });
                    toast('Объявление удалено');
                    setTimeout(() => (location.href = '/my-ads.php'), 600);
                });
            });
        }

        // --- похожие объявления
        const related = $('#related');
        if (related) {
            apiGet('listings', { action: 'list', category: root.dataset.category !== '0' ? root.dataset.category : '', exclude: adId })
                .then((data) => {
                    const items = data.items.slice(0, 4);
                    if (!items.length) return;
                    $('#relatedGrid').replaceChildren(...items.map((ad, i) => adCard(ad, { index: i })));
                    related.hidden = false;
                })
                .catch(() => { /* блок необязательный */ });
        }
    }

    /** Галерея: scroll-snap карусель + миниатюры + полноэкранный просмотр. */
    function initGallery() {
        const track = $('#galleryTrack');
        if (!track) return;
        const slides = $$('.gallery__slide', track);
        const thumbs = $$('.gallery__thumb');
        const counter = $('#galleryCounter');
        const prev = $('.gallery__nav--prev');
        const next = $('.gallery__nav--next');
        let current = 0;

        const update = (i) => {
            current = i;
            thumbs.forEach((t, n) => t.classList.toggle('is-active', n === i));
            if (counter) counter.textContent = `${i + 1} / ${slides.length}`;
            if (prev) prev.disabled = i === 0;
            if (next) next.disabled = i === slides.length - 1;
            // прокручиваем только полосу миниатюр (scrollIntoView дёргал бы всю страницу)
            const active = thumbs[i];
            const strip = active && active.parentElement;
            if (strip) strip.scrollTo({ left: active.offsetLeft - (strip.clientWidth - active.clientWidth) / 2, behavior: 'smooth' });
        };
        const go = (i) => {
            const n = clamp(i, 0, slides.length - 1);
            track.scrollTo({ left: n * track.clientWidth, behavior: 'smooth' });
            update(n);
        };

        let ticking = false;
        track.addEventListener('scroll', () => {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(() => {
                ticking = false;
                const i = Math.round(track.scrollLeft / track.clientWidth);
                if (i !== current) update(i);
            });
        });
        prev?.addEventListener('click', () => go(current - 1));
        next?.addEventListener('click', () => go(current + 1));
        thumbs.forEach((t, i) => t.addEventListener('click', () => go(i)));
        slides.forEach((s, i) => s.addEventListener('click', () => openLightbox(i)));
        update(0);

        function openLightbox(start) {
            let idx = start;
            const img = el('img', { alt: '' });
            const count = el('div', { class: 'lightbox__count' });
            const show = (i) => {
                idx = (i + slides.length) % slides.length;
                img.src = $('img', slides[idx]).src;
                count.textContent = `${idx + 1} / ${slides.length}`;
            };
            const close = () => {
                box.classList.remove('is-open');
                document.removeEventListener('keydown', onKey);
                document.body.classList.remove('has-overlay');
                go(idx);
                setTimeout(() => box.remove(), 220);
            };
            const onKey = (e) => {
                if (e.key === 'Escape') close();
                else if (e.key === 'ArrowLeft') show(idx - 1);
                else if (e.key === 'ArrowRight') show(idx + 1);
            };
            const box = el('div', { class: 'lightbox', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Просмотр фото' },
                el('button', { class: 'lightbox__close', type: 'button', 'aria-label': 'Закрыть', onClick: close }, icon('x')),
                slides.length > 1 ? el('button', { class: 'lightbox__prev', type: 'button', 'aria-label': 'Назад', onClick: () => show(idx - 1) }, icon('chevron-left')) : null,
                slides.length > 1 ? el('button', { class: 'lightbox__next', type: 'button', 'aria-label': 'Вперёд', onClick: () => show(idx + 1) }, icon('chevron-right')) : null,
                img, slides.length > 1 ? count : null);
            box.addEventListener('click', (e) => { if (e.target === box) close(); });
            document.body.append(box);
            document.body.classList.add('has-overlay');
            document.addEventListener('keydown', onKey);
            show(idx);
            requestAnimationFrame(() => box.classList.add('is-open'));
        }
    }

    /* ======================================================================
       7. Вход и регистрация
       ====================================================================== */

    function initAuthForm(form, action) {
        liveClear(form);
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            clearErrors(form);
            const data = Object.fromEntries(new FormData(form));
            const submit = $('[type="submit"]', form);

            // быстрая клиентская проверка; окончательная — на сервере
            const errors = {};
            if (!data.email) errors.email = 'Введите email';
            else if (!/^\S+@\S+\.\S+$/.test(data.email)) errors.email = 'Введите корректный email';
            if (!data.password) errors.password = 'Введите пароль';
            if (action === 'register') {
                if (!data.name || data.name.trim().length < 2) errors.name = 'Введите имя';
                if (data.password && data.password.length < 6) errors.password = 'Пароль: минимум 6 символов';
                if (data.password !== data.password2) errors.password2 = 'Пароли не совпадают';
            }
            if (Object.keys(errors).length) { showErrors(form, errors); return; }

            setLoading(submit, true);
            try {
                const res = await apiPost('auth', action, data);
                APP.csrf = res.csrf || APP.csrf;
                toast(action === 'login' ? 'С возвращением!' : 'Аккаунт создан — добро пожаловать!');
                const next = safeNext(new URLSearchParams(location.search).get('next'), res.user.role === 'admin' && action === 'login' ? '/admin.php' : '/index.php');
                setTimeout(() => (location.href = next), 500);
            } catch (err) {
                setLoading(submit, false);
                if (!(err instanceof ApiError && showErrors(form, err.errors))) handleApiError(err);
            }
        });
    }

    function initLogin() {
        const form = $('#loginForm');
        initAuthForm(form, 'login');
        $$('[data-fill]').forEach((btn) => btn.addEventListener('click', () => {
            const [email, password] = btn.dataset.fill.split('|');
            form.elements.email.value = email;
            form.elements.password.value = password;
            clearErrors(form);
        }));
    }

    /* ======================================================================
       8. Создание / редактирование объявления
       ====================================================================== */

    const MAX_IMAGES = 10;
    const MAX_BYTES = 5 * 1024 * 1024;
    const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    async function initCreateAd() {
        const form = $('#adForm');
        const initial = JSON.parse(form.dataset.form);
        const isEdit = initial.id > 0;
        const dropzone = $('#dropzone');
        const fileInput = $('#fileInput');
        const previews = $('#previews');
        const submitBtn = $('#submitBtn');
        const attrBox = $('#attrBox');
        const attrFields = $('#attrFields');

        /** Фото: {key, filename, url, status: 'uploading'|'ready'|'error', existing} */
        let images = initial.images.map((i, n) => ({ key: 'e' + n, filename: i.filename, url: i.url, status: 'ready', existing: true }));
        let uid = 0;

        // --- начальные значения (режим редактирования)
        form.elements.title.value = initial.title;
        form.elements.category_id.value = initial.category_id || '';
        form.elements.price.value = initial.price !== '' ? formatPrice(initial.price) : '';
        form.elements.city.value = initial.city;
        form.elements.description.value = initial.description;

        // --- подсказки городов
        const cityInput = form.elements.city;
        let known = [];
        try { known = JSON.parse(cityInput.dataset.knownCities || '[]'); } catch { /* нет подсказок из БД */ }
        attachCityAutocomplete(cityInput, known);

        // --- характеристики зависят от категории
        let categories = [];
        try { categories = (await apiGet('listings', { action: 'meta' })).categories; } catch { /* форма работает и без них */ }
        let attrValues = { ...(initial.attributes || {}) };

        function currentAttrValues() {
            const out = {};
            $$('[name^="attr_"]', attrFields).forEach((n) => { out[n.name.slice(5)] = n.value; });
            return out;
        }

        function renderAttrFields() {
            const cat = categories.find((c) => String(c.id) === form.elements.category_id.value);
            const defs = cat ? cat.attributes || [] : [];
            attrValues = { ...attrValues, ...currentAttrValues() };
            attrFields.replaceChildren();
            attrBox.hidden = defs.length === 0;
            if (!defs.length) return;
            $('#attrTitle').textContent = `Характеристики: ${cat.name}`;
            for (const d of defs) {
                const name = `attr_${d.code}`;
                let control;
                if (d.type === 'select') {
                    control = el('select', { class: 'select', name },
                        el('option', { value: '' }, 'Не выбрано'),
                        ...d.options.map((o) => el('option', { value: o }, o)));
                } else if (d.type === 'number') {
                    control = el('input', { class: 'input', name, type: 'text', inputmode: 'decimal', autocomplete: 'off', placeholder: d.min !== null && d.max !== null ? `${d.min}–${d.max}` : '' });
                } else {
                    control = el('input', { class: 'input', name, type: 'text', maxlength: 100, autocomplete: 'off' });
                }
                if (attrValues[d.code] !== undefined) control.value = attrValues[d.code];
                const label = el('label', { class: 'field__label' }, d.label, d.unit ? ` (${d.unit})` : '', d.required ? el('span', { class: 'req' }, ' *') : null);
                attrFields.append(el('div', { class: 'field' }, label, control, el('p', { class: 'field__error', 'data-error-for': name })));
            }
        }
        form.elements.category_id.addEventListener('change', renderAttrFields);
        renderAttrFields();

        // --- счётчики символов
        $$('[data-counter-for]').forEach((c) => {
            const input = form.elements[c.dataset.counterFor];
            const update = () => (c.textContent = `${input.value.length} / ${input.maxLength}`);
            input.addEventListener('input', update);
            update();
        });

        // --- цена: пробелы между разрядами «1 500 000»
        function formatPrice(v) {
            const digits = String(v).replace(/\D/g, '');
            return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        }
        form.elements.price.addEventListener('input', (e) => { e.target.value = formatPrice(e.target.value); });
        liveClear(form);

        // --- загрузка фото
        const renderPreviews = () => {
            previews.replaceChildren(...images.map((img, i) =>
                el('div', { class: `preview${img.status === 'uploading' ? ' is-uploading' : ''}${img.status === 'error' ? ' is-error' : ''}` },
                    el('img', { src: img.url, alt: `Фото ${i + 1}` }),
                    i === 0 && img.status === 'ready' ? el('span', { class: 'preview__main' }, 'Главное') : null,
                    el('button', { type: 'button', class: 'preview__remove', 'aria-label': 'Удалить фото', onClick: () => removeImage(img) }, icon('x')))));
            dropzone.classList.toggle('is-full', images.length >= MAX_IMAGES);
            if (images.length) {
                dropzone.classList.remove('is-invalid');
                $('[data-error-for="images"]', form).textContent = '';
            }
        };

        async function uploadImage(img, file) {
            const fd = new FormData();
            fd.append('file', file);
            try {
                const res = await api('/api/upload.php?action=upload', { method: 'POST', form: fd });
                URL.revokeObjectURL(img.url);
                Object.assign(img, { filename: res.filename, url: res.thumb, status: 'ready' });
            } catch (err) {
                img.status = 'error';
                toast(`${file.name}: ${err.message}`, 'error', 4000);
                setTimeout(() => { images = images.filter((i) => i !== img); renderPreviews(); }, 1600);
            }
            renderPreviews();
        }

        function addFiles(fileList) {
            const files = [...fileList];
            const free = MAX_IMAGES - images.length;
            if (files.length > free) toast(`Можно добавить ещё ${free} ${plural(free, 'фото', 'фото', 'фото')} (максимум ${MAX_IMAGES})`, 'warning');
            for (const file of files.slice(0, Math.max(0, free))) {
                if (!IMAGE_TYPES.includes(file.type)) { toast(`${file.name}: допустимы только JPG, PNG, WEBP, GIF`, 'error'); continue; }
                if (file.size > MAX_BYTES) { toast(`${file.name}: файл больше 5 МБ`, 'error'); continue; }
                const img = { key: 'n' + uid++, filename: null, url: URL.createObjectURL(file), status: 'uploading', existing: false };
                images.push(img);
                uploadImage(img, file);
            }
            renderPreviews();
        }

        function removeImage(img) {
            images = images.filter((i) => i !== img);
            // Только что загруженное, но не сохранённое фото можно сразу стереть с сервера
            if (img.filename && !img.existing) {
                api('/api/upload.php?action=delete', { method: 'POST', data: { filename: img.filename } }).catch(() => {});
            }
            renderPreviews();
        }

        dropzone.addEventListener('click', () => fileInput.click());
        dropzone.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });
        fileInput.addEventListener('change', () => { addFiles(fileInput.files); fileInput.value = ''; });
        ['dragenter', 'dragover'].forEach((ev) => dropzone.addEventListener(ev, (e) => { e.preventDefault(); dropzone.classList.add('is-drag'); }));
        ['dragleave', 'drop'].forEach((ev) => dropzone.addEventListener(ev, (e) => { e.preventDefault(); dropzone.classList.remove('is-drag'); }));
        dropzone.addEventListener('drop', (e) => addFiles(e.dataTransfer.files));
        renderPreviews();

        // --- отправка
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            clearErrors(form);

            const data = {
                title: form.elements.title.value.trim(),
                category_id: form.elements.category_id.value,
                price: form.elements.price.value.replace(/\D/g, ''),
                city: form.elements.city.value.trim(),
                description: form.elements.description.value.trim(),
                attributes: currentAttrValues(),
            };

            const errors = {};
            if (data.title.length < 5) errors.title = data.title ? 'Заголовок: минимум 5 символов' : 'Введите заголовок';
            if (!data.category_id) errors.category_id = 'Выберите категорию';
            if (data.price === '') errors.price = 'Укажите цену (0 — бесплатно)';
            if (data.city.length < 2) errors.city = 'Укажите город';
            if (data.description.length < 20) errors.description = data.description ? 'Описание: минимум 20 символов' : 'Опишите товар или услугу';
            if (!images.some((i) => i.status === 'ready')) errors.images = 'Добавьте хотя бы одно фото';
            const cat = categories.find((c) => String(c.id) === data.category_id);
            for (const d of (cat ? cat.attributes || [] : [])) {
                if (d.required && !String(data.attributes[d.code] ?? '').trim()) errors[`attr_${d.code}`] = `Заполните поле «${d.label}»`;
            }
            if (Object.keys(errors).length) { showErrors(form, errors); return; }

            if (images.some((i) => i.status === 'uploading')) {
                toast('Дождитесь окончания загрузки фото', 'warning');
                return;
            }

            data.images = images.filter((i) => i.status === 'ready').map((i) => i.filename);
            if (isEdit) data.id = initial.id;

            setLoading(submitBtn, true);
            try {
                const res = await apiPost('listings', isEdit ? 'update' : 'create', data);
                showSuccess(res);
            } catch (err) {
                setLoading(submitBtn, false);
                if (!(err instanceof ApiError && showErrors(form, err.errors))) handleApiError(err);
            }
        });

        function showSuccess(res) {
            toast(res.message, 'warning', 3500);
            $('#adFormCard').replaceChildren(
                el('div', { class: 'success-panel' },
                    el('div', { class: 'success-panel__icon' }, '🕒'),
                    el('span', { class: 'badge badge--pending' }, icon('clock'), 'На модерации'),
                    el('h2', {}, res.message),
                    el('p', {}, 'Модератор проверит объявление — после одобрения оно появится в ленте. Статус и комментарии модератора видны в разделе «Мои объявления».'),
                    el('div', { class: 'success-panel__actions' },
                        el('a', { class: 'btn btn--primary btn--lg', href: '/my-ads.php' }, 'Мои объявления'),
                        isEdit ? null : el('a', { class: 'btn btn--outline btn--lg', href: '/create-ad.php' }, 'Подать ещё'),
                        el('a', { class: 'btn btn--text btn--lg', href: '/index.php' }, 'На главную'))));
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    /* ======================================================================
       9. Мои объявления, избранное, профиль продавца, настройки, статистика
       ====================================================================== */

    function initMyAds() {
        const grid = $('#myGrid');
        const tabs = $$('#myTabs .tab');
        let status = '';
        let token = 0;

        async function load() {
            const my = ++token;
            grid.replaceChildren(...skeletonCards(4));
            try {
                const data = await apiGet('listings', { action: 'mine', status });
                if (my !== token) return;
                tabs.forEach((t) => {
                    const key = t.dataset.status || 'all';
                    $('.tab__count', t).textContent = data.counts[key] ?? 0;
                });
                $('#myCount').textContent = data.counts.all ? `${data.counts.all} ${plural(data.counts.all, 'объявление', 'объявления', 'объявлений')}` : 'Пока ничего нет';

                if (!data.items.length) {
                    grid.replaceChildren(emptyState({
                        emoji: status ? '📭' : '📝',
                        title: status ? 'В этом разделе пока пусто' : 'У вас пока нет объявлений',
                        text: status ? 'Попробуйте другой статус.' : 'Разместите первое объявление — это быстро и бесплатно.',
                        action: status ? null : el('a', { class: 'btn btn--primary btn--lg', href: '/create-ad.php' }, icon('plus'), 'Разместить объявление'),
                    }));
                    return;
                }
                grid.replaceChildren(...data.items.map((ad, i) => adCard(ad, { owner: true, index: i, onDelete: removeAd })));
            } catch (err) {
                if (my !== token) return;
                grid.replaceChildren(emptyState({ emoji: '😵', title: 'Не удалось загрузить', text: err.message }));
                handleApiError(err);
            }
        }

        async function removeAd(ad) {
            if (!(await confirmDialog(`«${ad.title}» и все его фото будут удалены без возможности восстановления.`, { title: 'Удалить объявление?', confirmText: 'Удалить' }))) return;
            try {
                await apiPost('listings', 'delete', { id: ad.id });
                toast('Объявление удалено');
                load();
            } catch (err) {
                handleApiError(err);
            }
        }

        tabs.forEach((tab) => tab.addEventListener('click', () => {
            tabs.forEach((t) => t.classList.toggle('is-active', t === tab));
            status = tab.dataset.status;
            load();
        }));
        load();
    }

    /** Страница «Избранное». */
    function initFavorites() {
        const grid = $('#favGrid');
        const moreBox = $('#favMoreBox');
        const moreBtn = $('#favMore');
        let page = 1;
        let total = 0;

        const setCount = () => {
            $('#favCount').textContent = total ? `${total} ${plural(total, 'объявление', 'объявления', 'объявлений')}` : 'Пока пусто';
        };
        const empty = () => grid.replaceChildren(emptyState({
            emoji: '💙', title: 'В избранном пока ничего нет',
            text: 'Нажимайте на сердечко у объявлений, чтобы не потерять их.',
            action: el('a', { class: 'btn btn--primary btn--lg', href: '/index.php' }, 'Смотреть объявления'),
        }));

        async function load(append = false) {
            if (!append) grid.replaceChildren(...skeletonCards(4));
            try {
                const data = await apiGet('favorites', { action: 'list', page });
                total = data.total;
                if (!append) grid.replaceChildren();
                if (!data.items.length && !append) { empty(); setCount(); moreBox.hidden = true; return; }
                data.items.forEach((ad, i) => grid.append(adCard(ad, { index: i })));
                moreBox.hidden = !data.has_more;
                setCount();
            } catch (err) {
                handleApiError(err);
            } finally {
                setLoading(moreBtn, false);
            }
        }
        moreBtn.addEventListener('click', () => { page += 1; setLoading(moreBtn, true); load(true); });

        // Убрали сердечко — карточка исчезает со страницы
        document.addEventListener('fav:changed', (e) => {
            if (e.detail.active) return;
            const btn = $(`[data-fav-ad="${e.detail.id}"]`);
            const card = btn && btn.closest('.card');
            if (card) { card.remove(); total = Math.max(0, total - 1); setCount(); if (!grid.children.length) empty(); }
        });
        load();
    }

    const starsRow = (n) => el('span', { class: 'stars', 'aria-label': `Оценка ${n} из 5` },
        ...[1, 2, 3, 4, 5].map((i) => icon('star', i <= n ? 'is-on' : '')));

    /** Публичный профиль продавца: объявления и отзывы. */
    function initSeller() {
        const root = $('.seller-page');
        const sellerId = Number(root.dataset.seller);
        const tabs = $$('#sellerTabs .tab');
        const panels = { ads: $('#tabAds'), reviews: $('#tabReviews') };
        const state = { ads: { page: 1, loaded: false }, reviews: { page: 1, loaded: false } };

        function showTab(name) {
            tabs.forEach((t) => t.classList.toggle('is-active', t.dataset.tab === name));
            Object.entries(panels).forEach(([k, p]) => (p.hidden = k !== name));
            if (!state[name].loaded) { state[name].loaded = true; (name === 'ads' ? loadAds() : loadReviews()); }
            history.replaceState(null, '', name === 'reviews' ? '#reviews' : location.pathname + location.search);
        }
        tabs.forEach((t) => t.addEventListener('click', () => showTab(t.dataset.tab)));
        $$('[data-tab-link]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); showTab('reviews'); }));

        // --- объявления продавца
        const grid = $('#sellerGrid');
        const moreBox = $('#sellerMoreBox');
        const moreBtn = $('#sellerMore');
        async function loadAds(append = false) {
            if (!append) grid.replaceChildren(...skeletonCards(4));
            try {
                const data = await apiGet('listings', { action: 'list', user: sellerId, page: state.ads.page });
                if (!append) grid.replaceChildren();
                data.items.forEach((ad, i) => grid.append(adCard(ad, { index: i })));
                if (!data.total) grid.replaceChildren(emptyState({ emoji: '📭', title: 'Пока нет объявлений', text: 'У этого продавца нет опубликованных объявлений.' }));
                moreBox.hidden = !data.has_more;
            } catch (err) {
                handleApiError(err);
            } finally {
                setLoading(moreBtn, false);
            }
        }
        moreBtn.addEventListener('click', () => { state.ads.page += 1; setLoading(moreBtn, true); loadAds(true); });

        // --- отзывы
        const list = $('#reviewsList');
        const summaryBox = $('#reviewsSummary');
        const formBox = $('#reviewForm');
        const revMoreBox = $('#reviewsMoreBox');
        const revMore = $('#reviewsMore');

        function renderSummary(s) {
            summaryBox.replaceChildren(
                el('div', { class: 'rev-score' }, el('b', {}, s.count ? String(s.avg).replace('.', ',') : '—'), starsRow(Math.round(s.avg))),
                el('p', { class: 'muted' }, s.count ? `${s.count} ${plural(s.count, 'отзыв', 'отзыва', 'отзывов')}` : 'Пока нет отзывов'),
                el('div', { class: 'rev-bars' }, ...[5, 4, 3, 2, 1].map((n) =>
                    el('div', { class: 'rev-bar' }, el('span', {}, String(n)),
                        el('div', { class: 'rev-bar__track' }, el('div', { class: 'rev-bar__fill', style: { width: (s.count ? (s.dist[n] / s.count) * 100 : 0) + '%' } })),
                        el('span', {}, String(s.dist[n]))))));
        }

        function reviewNode(r) {
            const canDelete = APP.user && (APP.user.id === r.author.id || APP.user.role === 'admin');
            const avatar = r.author.avatar
                ? el('span', { class: 'avatar avatar--img' }, el('img', { src: r.author.avatar, alt: '', referrerpolicy: 'no-referrer' }))
                : el('span', { class: 'avatar' }, r.author.initial);
            return el('article', { class: 'review' }, avatar,
                el('div', {},
                    el('div', { class: 'review__head' }, el('strong', {}, r.author.name), starsRow(r.rating), el('span', { class: 'muted' }, r.date_text),
                        canDelete ? el('button', { class: 'btn btn--text btn--sm review__del', type: 'button', onClick: () => removeReview(r) }, icon('trash'), 'Удалить') : null),
                    el('p', { class: 'review__body' }, r.body)));
        }

        async function removeReview(r) {
            if (!(await confirmDialog('Отзыв будет удалён.', { title: 'Удалить отзыв?', confirmText: 'Удалить' }))) return;
            try {
                await apiPost('reviews', 'delete', { id: r.id });
                toast('Отзыв удалён');
                state.reviews.page = 1;
                loadReviews();
            } catch (err) { handleApiError(err); }
        }

        async function renderReviewForm() {
            let can;
            try { can = await apiGet('reviews', { action: 'can', seller: sellerId }); } catch { return; }
            formBox.replaceChildren();
            if (!can.can) {
                const hint = { login: ['Чтобы оставить отзыв, ', el('a', { href: '/login.php?next=' + encodeURIComponent(location.pathname + location.search + '#reviews') }, 'войдите в аккаунт'), '.'],
                    no_chat: ['Отзыв можно оставить после общения с продавцом в чате — так мы защищаем рейтинг от накрутки.'], self: [] }[can.reason] || [];
                if (hint.length) formBox.append(el('div', { class: 'review-hint' }, ...hint));
                return;
            }
            let rating = can.mine ? can.mine.rating : 0;
            const picker = el('div', { class: 'star-picker', role: 'radiogroup', 'aria-label': 'Оценка' });
            const paint = () => $$('button', picker).forEach((b, i) => b.classList.toggle('is-on', i < rating));
            [1, 2, 3, 4, 5].forEach((n) => picker.append(el('button', { type: 'button', 'aria-label': `${n} из 5`, onClick: () => { rating = n; paint(); } }, icon('star'))));
            paint();
            const body = el('textarea', { class: 'textarea', name: 'body', rows: 3, maxlength: 1000, placeholder: 'Как всё прошло? Совпало ли с описанием, быстро ли договорились?' });
            body.value = can.mine ? can.mine.body : '';
            const form = el('form', { class: 'review-form', novalidate: true },
                el('h3', {}, can.mine ? 'Ваш отзыв' : 'Оставить отзыв'), picker,
                el('p', { class: 'field__error', 'data-error-for': 'rating' }),
                body, el('p', { class: 'field__error', 'data-error-for': 'body' }),
                el('button', { class: 'btn btn--primary', type: 'submit', style: { marginTop: '12px' } }, can.mine ? 'Сохранить отзыв' : 'Отправить отзыв'));
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                clearErrors(form);
                const btn = $('[type="submit"]', form);
                setLoading(btn, true);
                try {
                    await apiPost('reviews', 'save', { seller_id: sellerId, rating, body: body.value });
                    toast('Спасибо за отзыв!');
                    state.reviews.page = 1;
                    await loadReviews();
                } catch (err) {
                    if (!(err instanceof ApiError && showErrors(form, err.errors))) handleApiError(err);
                } finally { setLoading(btn, false); }
            });
            liveClear(form);
            formBox.append(form);
        }

        async function loadReviews(append = false) {
            try {
                const data = await apiGet('reviews', { action: 'list', seller: sellerId, page: state.reviews.page });
                renderSummary(data.summary);
                $('.tab__count', tabs[1]).textContent = data.summary.count;
                if (!append) { list.replaceChildren(); await renderReviewForm(); }
                data.items.forEach((r) => list.append(reviewNode(r)));
                if (!data.total) list.replaceChildren(emptyState({ emoji: '⭐', title: 'Отзывов пока нет', text: 'Будьте первым, кто оценит этого продавца.' }));
                revMoreBox.hidden = !data.has_more;
            } catch (err) { handleApiError(err); } finally { setLoading(revMore, false); }
        }
        revMore.addEventListener('click', () => { state.reviews.page += 1; setLoading(revMore, true); loadReviews(true); });

        showTab(location.hash === '#reviews' ? 'reviews' : 'ads');
    }

    /** Настройки профиля: данные, аватар, пароль, привязка VK. */
    function initProfile() {
        const profileForm = $('#profileForm');
        const passForm = $('#passwordForm');
        liveClear(profileForm);
        if (passForm) liveClear(passForm);

        $$('[data-counter-for]').forEach((c) => {
            const input = profileForm.elements[c.dataset.counterFor];
            const update = () => (c.textContent = `${input.value.length} / ${input.maxLength}`);
            input.addEventListener('input', update);
            update();
        });

        profileForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            clearErrors(profileForm);
            const btn = $('[type="submit"]', profileForm);
            setLoading(btn, true);
            try {
                await apiPost('profile', 'update', Object.fromEntries(new FormData(profileForm)));
                toast('Профиль сохранён');
            } catch (err) {
                if (!(err instanceof ApiError && showErrors(profileForm, err.errors))) handleApiError(err);
            } finally { setLoading(btn, false); }
        });

        if (passForm) {
            passForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                clearErrors(passForm);
                const btn = $('[type="submit"]', passForm);
                setLoading(btn, true);
                try {
                    await apiPost('profile', 'password', Object.fromEntries(new FormData(passForm)));
                    toast('Пароль сохранён');
                    passForm.reset();
                } catch (err) {
                    if (!(err instanceof ApiError && showErrors(passForm, err.errors))) handleApiError(err);
                } finally { setLoading(btn, false); }
            });
        }

        // --- аватар
        const file = $('#avatarFile');
        const preview = $('#avatarPreview');
        const removeBtn = $('#avatarRemove');
        const paintAvatars = (url) => {
            const mk = (cls) => url
                ? el('span', { class: `avatar avatar--img ${cls}`.trim() }, el('img', { src: url, alt: '', referrerpolicy: 'no-referrer' }))
                : el('span', { class: `avatar ${cls}`.trim() }, (APP.user.name || '?').charAt(0).toUpperCase());
            preview.replaceChildren(mk('avatar--xl'));
            const headerBtn = $('.user-menu__btn');
            if (headerBtn) headerBtn.replaceChildren(mk(''));
            removeBtn.hidden = !url;
        };
        $('#avatarPick').addEventListener('click', () => file.click());
        file.addEventListener('change', async () => {
            if (!file.files[0]) return;
            if (file.files[0].size > MAX_BYTES) { toast('Файл больше 5 МБ', 'error'); file.value = ''; return; }
            const fd = new FormData();
            fd.append('file', file.files[0]);
            file.value = '';
            try {
                const res = await api('/api/profile.php?action=avatar', { method: 'POST', form: fd });
                paintAvatars(res.avatar);
                toast(res.message);
            } catch (err) { handleApiError(err); }
        });
        removeBtn.addEventListener('click', async () => {
            try { await apiPost('profile', 'avatar_delete'); paintAvatars(null); toast('Фото удалено'); } catch (err) { handleApiError(err); }
        });

        // --- VK
        const unlink = $('#vkUnlink');
        if (unlink) {
            unlink.addEventListener('click', async () => {
                if (!(await confirmDialog('Вы больше не сможете входить через VK. Войти по email и паролю при этом можно.', { title: 'Отвязать VK?', confirmText: 'Отвязать' }))) return;
                try { await apiPost('profile', 'vk_unlink'); toast('VK отвязан'); setTimeout(() => location.reload(), 600); } catch (err) { handleApiError(err); }
            });
        }
    }

    /** Статистика объявления для автора: плитки и графики за 14 дней. */
    async function initAdStats() {
        const id = Number($('.stats-page').dataset.ad);
        const tiles = $('#statTiles');
        tiles.replaceChildren(...[1, 2, 3, 4].map(() => el('div', { class: 'tile' }, el('div', { class: 'sk', style: { height: '64px' } }))));
        try {
            const { totals: t, days } = await apiGet('listings', { action: 'stats', id });
            const tile = (cls, ico, label, value, sub) => el('div', { class: `tile tile--${cls}` },
                el('div', { class: 'tile__label' }, icon(ico), label), el('div', { class: 'tile__num' }, String(value)), sub ? el('div', { class: 'tile__sub' }, sub) : null);
            tiles.replaceChildren(
                tile('blue', 'eye', 'Просмотры', t.views, 'уникальные посетители'),
                tile('red', 'heart', 'В избранном', t.favorites, 'сейчас'),
                tile('green', 'phone', 'Показали телефон', t.phones, 'уникальные'),
                tile('yellow', 'message', 'Диалоги в чате', t.chats, `конверсия в контакт — ${String(t.contact_rate).replace('.', ',')}%`));
            const css = getComputedStyle(document.documentElement);
            const color = (v) => css.getPropertyValue(v).trim() || v;
            $('#chartViews').replaceChildren(barChart(days, [{ key: 'views', label: 'Просмотры', color: color('--blue') }]));
            $('#chartInterest').replaceChildren(barChart(days, [
                { key: 'contacts', label: 'Контакты', color: color('--yellow') },
                { key: 'favorites', label: 'В избранное', color: color('--red') },
            ]));
        } catch (err) {
            handleApiError(err);
            tiles.replaceChildren(emptyState({ emoji: '😵', title: 'Не удалось загрузить статистику', text: err.message }));
        }
    }

    /* ======================================================================
       Запуск: общий код + модуль текущей страницы (по <body data-page>)
       ====================================================================== */

    const pages = {
        index: initIndex,
        ad: initAdPage,
        login: initLogin,
        register: () => initAuthForm($('#registerForm'), 'register'),
        'create-ad': initCreateAd,
        'my-ads': initMyAds,
        favorites: initFavorites,
        seller: initSeller,
        profile: initProfile,
        'ad-stats': initAdStats,
    };

    // Общий набор помощников для page-скриптов (chat.js, admin.js)
    window.Board = {
        APP, $, $$, el, icon, debounce, plural, clamp, qs, safeNext,
        ApiError, api, apiGet, apiPost, handleApiError,
        toast, setLoading, withLoading, clearErrors, showErrors, liveClear, field, openModal, confirmDialog, askReason,
        adCard, skeletonCards, emptyState, barChart, favBtn, realtime, attachCityAutocomplete, pages, applyTheme,
    };

    document.addEventListener('DOMContentLoaded', () => {
        initHeader();
        realtime.start();
        const init = pages[document.body.dataset.page];
        if (init) init();
    });
})();
