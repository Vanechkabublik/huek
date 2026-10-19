/* ==========================================================================
   Авито-клон — клиентская логика (vanilla JS, fetch)

   1. Утилиты и DOM-хелперы      6. Страница объявления
   2. API-клиент                 7. Вход / регистрация
   3. UI: тосты, модалки         8. Создание / редактирование объявления
   4. Шапка                      9. Мои объявления, сообщения
   5. Карточки и главная        10. Админ-панель

   Весь пользовательский текст попадает в DOM через textContent / createElement —
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
        if (!root) return;
        const node = el('div', { class: `toast toast--${type}`, role: 'status' }, icon(TOAST_ICONS[type] || 'alert-circle'), el('span', {}, message));
        root.append(node);
        while (root.children.length > 4) root.firstElementChild.remove();
        const hide = () => {
            node.classList.add('is-leaving');
            setTimeout(() => node.remove(), 240);
        };
        const timer = setTimeout(hide, ms);
        node.addEventListener('click', () => { clearTimeout(timer); hide(); });
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
    function openModal({ title, text = '', content = null, submitText = 'Подтвердить', cancelText = 'Отмена', danger = false, hideCancel = false, submitKind = '', onSubmit = null }) {
        return new Promise((resolve) => {
            const root = $('#modalRoot');
            const overlay = el('div', { class: 'modal-overlay', role: 'dialog', 'aria-modal': 'true', 'aria-label': title });
            const cancelBtn = el('button', { type: 'button', class: 'btn btn--outline' }, cancelText);
            const submitBtn = el('button', { type: 'submit', class: `btn ${submitKind || (danger ? 'btn--danger' : 'btn--primary')}` }, submitText);
            const form = el('form', { class: 'modal', novalidate: true },
                el('h3', { class: 'modal__title' }, title),
                text ? el('p', { class: 'modal__text' }, text) : null,
                content,
                el('div', { class: 'modal__actions' }, hideCancel ? null : cancelBtn, submitBtn));
            overlay.append(form);

            let closed = false;
            const onKey = (e) => { if (e.key === 'Escape') close(false); };
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
       4. Шапка: меню пользователя, выход
       ====================================================================== */

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
    }

    /* ======================================================================
       5. Карточки объявлений и главная страница
       ====================================================================== */

    /**
     * Карточка объявления. owner = true добавляет бейдж статуса, причину и кнопки управления.
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
                    el('div', { class: 'card__price' }, ad.price_text),
                    el('div', { class: 'card__meta' }, ad.city, el('br'), ad.date_text))));

        if (owner) {
            if (ad.status === 'rejected' || ad.status === 'blocked') {
                card.append(el('div', { class: `card__reason card__reason--${ad.status}` },
                    el('b', {}, ad.status === 'rejected' ? 'Причина отклонения' : 'Причина блокировки'),
                    ad.rejection_reason || 'Причина не указана'));
            } else if (ad.status === 'pending') {
                card.append(el('div', { class: 'card__reason card__reason--pending' }, 'Объявление проверяет модератор — обычно это занимает до нескольких часов.'));
            }
            const actions = el('div', { class: 'card__actions' });
            if (ad.status !== 'blocked') {
                actions.append(el('a', { class: 'btn btn--outline btn--sm', href: `/create-ad.php?id=${ad.id}` }, icon('edit'), 'Редактировать'));
            }
            actions.append(el('button', { type: 'button', class: 'btn btn--outline btn--sm btn--danger-text', onClick: () => onDelete && onDelete(ad, card) }, icon('trash'), 'Удалить'));
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

    /** Главная: фильтры, поиск с debounce, «Показать ещё». Состояние живёт в URL. */
    function initIndex() {
        const form = $('#filtersForm');
        const grid = $('#adsGrid');
        const searchInput = $('#searchInput');
        const searchForm = $('#searchForm');
        const loadMoreBox = $('#loadMoreBox');
        const loadMoreBtn = $('#loadMore');
        const filters = $('#filters');
        const overlay = $('#sheetOverlay');
        const state = { page: 1, token: 0 };

        const categoryNames = Object.fromEntries($$('#fCategory option').map((o) => [o.value, o.textContent.replace(/^\S+\s/, '')]));

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
            $('#sellerBanner')?.remove();
            setSheet(false);
            load();
        }

        // фильтры
        form.addEventListener('submit', (e) => { e.preventDefault(); setSheet(false); load(); });
        $('#filtersReset').addEventListener('click', reset);
        form.elements.category.addEventListener('change', syncChips);

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
            load();
        });

        loadMoreBtn.addEventListener('click', () => { setLoading(loadMoreBtn, true); load({ append: true }); });

        // мобильная шторка фильтров
        $('#filtersOpen').addEventListener('click', () => setSheet(true));
        $('#filtersClose').addEventListener('click', () => setSheet(false));
        overlay.addEventListener('click', () => setSheet(false));

        load();
    }

    /* ======================================================================
       6. Страница объявления
       ====================================================================== */

    function initAdPage() {
        const root = $('.ad-page');
        const adId = Number(root.dataset.adId);

        initGallery();

        // --- телефон
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

        // --- сообщение
        const writeBtn = $('#writeBtn');
        if (writeBtn) {
            writeBtn.addEventListener('click', () => {
                if (!APP.user) {
                    toast('Войдите, чтобы написать продавцу', 'info');
                    setTimeout(() => (location.href = '/login.php?next=' + encodeURIComponent(location.pathname + location.search)), 800);
                    return;
                }
                sendMessageModal({ adId, title: 'Сообщение продавцу' });
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

    /** Модалка «Написать сообщение» (покупатель → продавец или ответ владельца). */
    function sendMessageModal({ adId, toUserId = 0, title }) {
        return openModal({
            title,
            text: 'Продавец увидит сообщение в разделе «Сообщения».',
            submitText: 'Отправить',
            content: field({ name: 'body', rows: 4, maxlength: 2000, placeholder: 'Здравствуйте! Объявление ещё актуально?' }),
            onSubmit: async (form) => {
                const body = form.elements.body.value.trim();
                if (body.length < 2) throw new ApiError('', 422, { body: 'Введите текст сообщения' });
                await apiPost('messages', 'send', { ad_id: adId, to_user_id: toUserId, body });
                toast('Сообщение отправлено');
            },
        });
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

    function initCreateAd() {
        const form = $('#adForm');
        const initial = JSON.parse(form.dataset.form);
        const isEdit = initial.id > 0;
        const dropzone = $('#dropzone');
        const fileInput = $('#fileInput');
        const previews = $('#previews');
        const submitBtn = $('#submitBtn');

        /** Фото: {key, filename, url, status: 'uploading'|'ready'|'error', existing} */
        let images = initial.images.map((i, n) => ({ key: 'e' + n, filename: i.filename, url: i.url, status: 'ready', existing: true }));
        let uid = 0;

        // --- начальные значения (режим редактирования)
        form.elements.title.value = initial.title;
        form.elements.category_id.value = initial.category_id || '';
        form.elements.price.value = initial.price !== '' ? formatPrice(initial.price) : '';
        form.elements.city.value = initial.city;
        form.elements.description.value = initial.description;

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
            };

            const errors = {};
            if (data.title.length < 5) errors.title = data.title ? 'Заголовок: минимум 5 символов' : 'Введите заголовок';
            if (!data.category_id) errors.category_id = 'Выберите категорию';
            if (data.price === '') errors.price = 'Укажите цену (0 — бесплатно)';
            if (data.city.length < 2) errors.city = 'Укажите город';
            if (data.description.length < 20) errors.description = data.description ? 'Описание: минимум 20 символов' : 'Опишите товар или услугу';
            if (!images.some((i) => i.status === 'ready')) errors.images = 'Добавьте хотя бы одно фото';
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
       9. Мои объявления и сообщения
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

    function initMessages() {
        const list = $('#msgList');
        const tabs = $$('#msgTabs .tab');
        let box = 'in';
        let token = 0;

        async function load() {
            const my = ++token;
            list.replaceChildren(el('div', { class: 'sk', style: { height: '84px', borderRadius: '12px' } }), el('div', { class: 'sk', style: { height: '84px', borderRadius: '12px' } }));
            try {
                const data = await apiGet('messages', { action: 'inbox', box });
                if (my !== token) return;
                if (!data.items.length) {
                    list.replaceChildren(emptyState({
                        emoji: box === 'in' ? '📬' : '✉️',
                        title: box === 'in' ? 'Входящих сообщений нет' : 'Вы ещё никому не писали',
                        text: box === 'in' ? 'Когда покупатель напишет по вашему объявлению, сообщение появится здесь.' : 'Нажмите «Написать сообщение» на странице любого объявления.',
                    }));
                    return;
                }
                list.replaceChildren(...data.items.map(messageRow));
            } catch (err) {
                if (my === token) list.replaceChildren(emptyState({ emoji: '😵', title: 'Не удалось загрузить', text: err.message }));
            }
        }

        function messageRow(m) {
            return el('article', { class: `msg${box === 'in' && !m.is_read ? ' is-unread' : ''}` },
                el('span', { class: 'avatar' }, (m.peer_name || '?').charAt(0).toUpperCase()),
                el('div', { class: 'msg__body' },
                    el('div', { class: 'msg__top' },
                        el('strong', {}, (box === 'in' ? 'От: ' : 'Кому: ') + m.peer_name),
                        el('a', { class: 'msg__ad', href: m.ad_url }, m.ad_title),
                        el('span', { class: 'msg__date' }, m.date_text)),
                    el('p', { class: 'msg__text' }, m.body)),
                m.can_reply ? el('button', {
                    class: 'btn btn--outline btn--sm', type: 'button',
                    onClick: () => sendMessageModal({ adId: m.ad_id, toUserId: m.peer_id, title: `Ответ: ${m.peer_name}` }),
                }, icon('message'), 'Ответить') : null);
        }

        tabs.forEach((tab) => tab.addEventListener('click', () => {
            tabs.forEach((t) => t.classList.toggle('is-active', t === tab));
            box = tab.dataset.box;
            load();
        }));
        load();
    }

    /* ======================================================================
       10. Админ-панель
       ====================================================================== */

    function initAdmin() {
        const content = $('#adminContent');
        const navLinks = $$('.admin__nav a');
        const navPending = $('#navPending');
        const STATUS_TABS = [['', 'Все'], ['pending', 'На модерации'], ['approved', 'Одобрены'], ['rejected', 'Отклонены'], ['blocked', 'Заблокированы']];

        const pill = (status, label) => el('span', { class: `pill pill--${status}` }, label);
        const setPending = (n) => { navPending.textContent = n; navPending.hidden = !n; };
        const adminApi = (action, params) => apiGet('admin', { action, ...params });
        const adminPost = (action, data) => apiPost('admin', action, data);
        const header = (title, subtitle, ...actions) =>
            el('div', { class: 'admin-title' }, el('div', {}, el('h1', {}, title), subtitle ? el('p', {}, subtitle) : null), actions.length ? el('div', {}, ...actions) : null);

        function tableShell(heads, cls = '') {
            const tbody = el('tbody');
            const wrap = el('div', { class: 'table-wrap' },
                el('table', { class: `table ${cls}`.trim() }, el('thead', {}, el('tr', {}, ...heads.map((h) => el('th', {}, h)))), tbody));
            return { wrap, tbody };
        }

        // ------------------------------------------------------------ статистика
        async function viewStats() {
            content.replaceChildren(header('Статистика', 'Сводка по площадке'), el('div', { class: 'stats' }, ...[1, 2, 3, 4].map(() => el('div', { class: 'stat' }, el('div', { class: 'sk', style: { height: '70px' } })))));
            const { stats } = await adminApi('stats');
            setPending(stats.pending);
            const tile = (cls, ico, label, value, href) =>
                el('a', { class: `stat stat--${cls}`, href }, el('div', { class: 'stat__label' }, icon(ico), label), el('div', { class: 'stat__num' }, String(value)));
            content.replaceChildren(
                header('Статистика', 'Сводка по площадке'),
                el('div', { class: 'stats' },
                    tile('blue', 'users', 'Всего пользователей', stats.users, '#/users'),
                    tile('green', 'list', 'Всего объявлений', stats.ads, '#/ads'),
                    tile('yellow', 'clock', 'На модерации', stats.pending, '#/ads?status=pending'),
                    tile('red', 'ban', 'Заблокировано', stats.blocked, '#/ads?status=blocked')),
                el('div', { class: 'stats stats--small' },
                    tile('green', 'check-circle', 'Одобрено', stats.approved, '#/ads?status=approved'),
                    tile('red', 'x-circle', 'Отклонено', stats.rejected, '#/ads?status=rejected'),
                    tile('blue', 'tag', 'Категорий', stats.categories, '#/categories')));
        }

        // ---------------------------------------------------------- пользователи
        async function viewUsers() {
            const state = { q: '', page: 1, items: [], total: 0, hasMore: false, token: 0 };
            const search = el('input', { class: 'input', type: 'search', placeholder: 'Поиск по email…', autocomplete: 'off', 'aria-label': 'Поиск пользователей по email' });
            const { wrap, tbody } = tableShell(['Email', 'Имя', 'Регистрация', 'Статус', 'Объявл.', 'Действия'], 'table--users');
            const foot = el('div', { class: 'table-foot' });
            const more = el('button', { class: 'btn btn--outline btn--sm', type: 'button', onClick: () => load(true) }, 'Показать ещё');

            content.replaceChildren(
                header('Пользователи', 'Мгновенный поиск по email'),
                el('div', { class: 'toolbar' }, el('div', { class: 'search-big' }, icon('search'), search)),
                el('div', { class: 'panel' }, wrap, foot));

            function render() {
                tbody.replaceChildren(...state.items.map(userRow));
                if (!state.items.length) tbody.append(el('tr', {}, el('td', { colspan: 6 }, el('div', { class: 'table-empty' }, state.q ? 'Пользователи с таким email не найдены' : 'Пользователей пока нет'))));
                foot.replaceChildren(...[el('span', {}, `Найдено: ${state.total}`), state.hasMore && more].filter(Boolean));
            }

            function userRow(u) {
                const isAdmin = u.role === 'admin';
                const actions = el('div', { class: 'row-actions' },
                    el('button', { class: 'btn btn--outline btn--xs', type: 'button', onClick: () => (location.hash = `#/ads?user=${u.id}`) }, icon('list'), 'Объявления'));
                if (!isAdmin) {
                    actions.append(
                        el('button', { class: 'btn btn--outline btn--xs', type: 'button', onClick: () => changePassword(u) }, icon('key'), 'Пароль'),
                        el('button', { class: `btn btn--xs ${u.is_blocked ? 'btn--success' : 'btn--dark'}`, type: 'button', onClick: (e) => toggleBlock(u, e.currentTarget) },
                            icon(u.is_blocked ? 'unlock' : 'lock'), u.is_blocked ? 'Разблокировать' : 'Заблокировать'),
                        el('button', { class: 'btn btn--danger btn--xs', type: 'button', onClick: () => removeUser(u) }, icon('trash'), 'Удалить'));
                }
                return el('tr', {},
                    el('td', { 'data-label': 'Email' }, el('div', { class: 'cell-stack' }, el('span', { class: 'cell-title' }, u.email), isAdmin ? pill('admin', 'Администратор') : null)),
                    el('td', { 'data-label': 'Имя' }, u.name),
                    el('td', { class: 'nowrap', 'data-label': 'Регистрация' }, u.created_at),
                    el('td', { 'data-label': 'Статус' }, u.is_blocked ? pill('blocked', 'Заблокирован') : pill('active', 'Активен')),
                    el('td', { class: 'num', 'data-label': 'Объявлений' }, String(u.ads_count)),
                    el('td', {}, actions));
            }

            async function load(append = false) {
                const my = ++state.token;
                if (append) state.page += 1; else state.page = 1;
                try {
                    const data = await adminApi('users', { q: state.q, page: state.page });
                    if (my !== state.token) return;
                    state.items = append ? state.items.concat(data.items) : data.items;
                    state.total = data.total;
                    state.hasMore = data.has_more;
                    render();
                } catch (err) {
                    if (append) state.page -= 1;
                    handleApiError(err);
                }
            }

            async function toggleBlock(u, btn) {
                const block = !u.is_blocked;
                if (block && !(await confirmDialog(`${u.email} не сможет войти на сайт, а его объявления пропадут из ленты.`, { title: 'Заблокировать пользователя?', confirmText: 'Заблокировать' }))) return;
                await withLoading(btn, async () => {
                    await adminPost(block ? 'user_block' : 'user_unblock', { id: u.id });
                    u.is_blocked = block;
                    toast(block ? 'Пользователь заблокирован' : 'Пользователь разблокирован');
                    render();
                });
            }

            async function changePassword(u) {
                await openModal({
                    title: 'Сменить пароль',
                    text: u.email,
                    submitText: 'Сменить пароль',
                    content: field({ name: 'password', type: 'password', placeholder: 'Новый пароль (от 6 символов)', autocomplete: 'new-password' }),
                    onSubmit: async (form) => {
                        const password = form.elements.password.value;
                        if (password.length < 6) throw new ApiError('', 422, { password: 'Пароль: минимум 6 символов' });
                        await adminPost('user_password', { id: u.id, password });
                        toast('Пароль изменён');
                    },
                });
            }

            async function removeUser(u) {
                const ok = await confirmDialog(`Пользователь ${u.email}, все его объявления (${u.ads_count}) и фото будут удалены безвозвратно.`, { title: 'Удалить пользователя?', confirmText: 'Удалить' });
                if (!ok) return;
                try {
                    await adminPost('user_delete', { id: u.id });
                    state.items = state.items.filter((x) => x.id !== u.id);
                    state.total -= 1;
                    toast('Пользователь удалён');
                    render();
                } catch (err) {
                    handleApiError(err);
                }
            }

            search.addEventListener('input', debounce(() => { state.q = search.value.trim(); load(); }, 300));
            search.focus();
            await load();
        }

        // ------------------------------------------------------------ объявления
        async function viewAds(params) {
            const state = { status: params.get('status') || '', user: params.get('user') || '', q: '', page: 1, items: [], total: 0, counts: {}, hasMore: false, token: 0 };
            const tabsBox = el('div', { class: 'tabs' });
            const chips = el('div', { class: 'toolbar' });
            const search = el('input', { class: 'input', type: 'search', placeholder: 'Поиск по заголовку или email автора…', autocomplete: 'off', 'aria-label': 'Поиск объявлений' });
            const { wrap, tbody } = tableShell(['ID', 'Объявление', 'Пользователь', 'Цена', 'Город', 'Статус', 'Дата', 'Действия'], 'table--ads table--sticky');
            const foot = el('div', { class: 'table-foot' });
            const more = el('button', { class: 'btn btn--outline btn--sm', type: 'button', onClick: () => load(true) }, 'Показать ещё');

            content.replaceChildren(
                header('Объявления', 'Модерация и управление'),
                el('div', { class: 'toolbar' }, el('div', { class: 'search-big' }, icon('search'), search)),
                chips, tabsBox,
                el('div', { class: 'panel' }, wrap, foot));

            function renderTabs() {
                tabsBox.replaceChildren(...STATUS_TABS.map(([key, label]) =>
                    el('button', { type: 'button', class: `tab${state.status === key ? ' is-active' : ''}`, onClick: () => { state.status = key; renderTabs(); load(); } },
                        label, el('span', { class: 'tab__count' }, String(state.counts[key || 'all'] ?? 0)))));
                chips.replaceChildren();
                if (state.user) {
                    const owner = state.items.find((a) => String(a.user_id) === state.user);
                    chips.append(el('span', { class: 'filter-chip' }, `Пользователь: ${owner ? owner.user_email : '#' + state.user}`,
                        el('button', { type: 'button', 'aria-label': 'Сбросить фильтр', onClick: () => { state.user = ''; load(); } }, icon('x'))));
                }
                chips.hidden = !state.user;
            }

            function render() {
                renderTabs();
                tbody.replaceChildren(...state.items.map(adRow));
                if (!state.items.length) tbody.append(el('tr', {}, el('td', { colspan: 8 }, el('div', { class: 'table-empty' }, 'Объявлений не найдено'))));
                foot.replaceChildren(...[el('span', {}, `Найдено: ${state.total}`), state.hasMore && more].filter(Boolean));
                setPending(state.counts.pending ?? 0);
            }

            function adRow(ad) {
                const act = el('div', { class: 'row-actions' });
                if (ad.status !== 'approved') {
                    act.append(el('button', { class: 'btn btn--success btn--xs', type: 'button', onClick: (e) => approve(ad, e.currentTarget) },
                        icon('check'), ad.status === 'blocked' ? 'Разблокировать' : 'Одобрить'));
                }
                if (ad.status === 'pending' || ad.status === 'approved') {
                    act.append(el('button', { class: 'btn btn--danger btn--xs', type: 'button', onClick: () => reject(ad, 'rejected') }, icon('x'), 'Отклонить'));
                }
                if (ad.status !== 'blocked') {
                    act.append(el('button', { class: 'btn btn--dark btn--xs', type: 'button', onClick: () => reject(ad, 'blocked') }, icon('ban'), 'Заблокировать'));
                }
                return el('tr', {},
                    el('td', { class: 'num', 'data-label': 'ID' }, '#' + ad.id),
                    el('td', { 'data-label': 'Объявление' }, el('div', { class: 'cell-stack' },
                        el('a', { class: 'cell-title', href: ad.url, target: '_blank', rel: 'noopener', title: ad.title }, ad.title),
                        el('span', { class: 'cell-sub' }, `${ad.category_icon} ${ad.category_name}`))),
                    el('td', { 'data-label': 'Пользователь' }, el('div', { class: 'cell-stack' }, el('span', { class: 'cell-title', title: ad.user_name }, ad.user_email), ad.user_blocked ? pill('blocked', 'Автор заблокирован') : null)),
                    el('td', { class: 'price', 'data-label': 'Цена' }, ad.price_text),
                    el('td', { 'data-label': 'Город' }, ad.city),
                    el('td', { 'data-label': 'Статус' }, el('div', { class: 'cell-stack' }, pill(ad.status, ad.status_label), ad.rejection_reason && ad.status !== 'approved' ? el('span', { class: 'cell-reason' }, ad.rejection_reason) : null)),
                    el('td', { class: 'nowrap', title: ad.date_full, 'data-label': 'Дата' }, ad.date_text),
                    el('td', {}, act));
            }

            /** Меняет статус в локальном состоянии и пересчитывает табы без повторного запроса. */
            function applyStatus(ad, status, reason) {
                state.counts[ad.status] -= 1;
                state.counts[status] = (state.counts[status] || 0) + 1;
                ad.status = status;
                ad.status_label = { approved: 'Одобрено', rejected: 'Отклонено', blocked: 'Заблокировано' }[status];
                ad.rejection_reason = reason || null;
                if (state.status && state.status !== status) {
                    state.items = state.items.filter((a) => a !== ad);
                    state.total -= 1;
                }
                render();
            }

            async function approve(ad, btn) {
                await withLoading(btn, async () => {
                    await adminPost('ad_moderate', { id: ad.id, status: 'approved' });
                    toast('Объявление одобрено');
                    applyStatus(ad, 'approved', null);
                });
            }

            async function reject(ad, status) {
                const isBlock = status === 'blocked';
                let reason = null;
                const ok = await openModal({
                    title: 'Укажите причину',
                    text: `${isBlock ? 'Заблокировать' : 'Отклонить'} объявление «${ad.title}». Автор увидит причину в «Моих объявлениях».`,
                    submitText: 'Подтвердить',
                    danger: true,
                    submitKind: isBlock ? 'btn--dark' : 'btn--danger',
                    content: field({ name: 'reason', rows: 4, maxlength: 1000, placeholder: isBlock ? 'Например: нарушает правила размещения…' : 'Например: добавьте реальные фото и опишите состояние…' }),
                    onSubmit: async (form) => {
                        reason = form.elements.reason.value.trim();
                        if (reason.length < 3) throw new ApiError('', 422, { reason: 'Укажите причину (минимум 3 символа)' });
                        await adminPost('ad_moderate', { id: ad.id, status, reason });
                    },
                });
                if (ok) {
                    toast(isBlock ? 'Объявление заблокировано' : 'Объявление отклонено');
                    applyStatus(ad, status, reason);
                }
            }

            async function load(append = false) {
                const my = ++state.token;
                if (append) state.page += 1; else state.page = 1;
                try {
                    const data = await adminApi('ads', { status: state.status, user: state.user, q: state.q, page: state.page });
                    if (my !== state.token) return;
                    state.items = append ? state.items.concat(data.items) : data.items;
                    state.total = data.total;
                    state.counts = data.counts;
                    state.hasMore = data.has_more;
                    render();
                } catch (err) {
                    if (append) state.page -= 1;
                    handleApiError(err);
                }
            }

            search.addEventListener('input', debounce(() => { state.q = search.value.trim(); load(); }, 300));
            renderTabs();
            await load();
        }

        // ------------------------------------------------------------ категории
        async function viewCategories() {
            const list = el('div', { class: 'cat-list' });
            const addBtn = el('button', { class: 'btn btn--primary', type: 'button', onClick: () => categoryModal() }, icon('plus'), 'Добавить категорию');
            content.replaceChildren(header('Категории', 'Разделы доски объявлений', addBtn), el('div', { class: 'panel' }, list));

            let items = [];
            const render = () => {
                list.replaceChildren(...items.map(row));
                if (!items.length) list.append(el('div', { class: 'table-empty' }, 'Категорий пока нет'));
            };

            function row(cat) {
                const view = el('div', { class: 'cat-row' },
                    el('div', { class: 'cat-row__icon' }, cat.icon),
                    el('div', { class: 'cat-row__name' }, cat.name),
                    el('div', { class: 'cat-row__count' }, `${cat.total_ads} ${plural(cat.total_ads, 'объявление', 'объявления', 'объявлений')}`),
                    el('div', { class: 'row-actions' },
                        el('button', { class: 'btn btn--outline btn--sm', type: 'button', onClick: () => edit(cat, view) }, icon('edit'), 'Изменить'),
                        el('button', { class: 'btn btn--danger btn--sm', type: 'button', onClick: () => remove(cat) }, icon('trash'), 'Удалить')));
                return view;
            }

            /** Inline-редактирование: строка превращается в поля ввода. */
            function edit(cat, view) {
                const iconInput = el('input', { class: 'input cat-row__icon-input', value: cat.icon, maxlength: 8, 'aria-label': 'Иконка' });
                const nameInput = el('input', { class: 'input', value: cat.name, maxlength: 80, 'aria-label': 'Название' });
                const err = el('span', { class: 'field__error', style: { gridColumn: '2 / -1' } });
                const save = el('button', { class: 'btn btn--primary btn--sm', type: 'button' }, icon('check'), 'Сохранить');
                const cancel = el('button', { class: 'btn btn--text btn--sm', type: 'button', onClick: () => editRow.replaceWith(row(cat)) }, 'Отмена');
                const run = async () => {
                    setLoading(save, true);
                    try {
                        await adminPost('category_update', { id: cat.id, name: nameInput.value, icon: iconInput.value });
                        Object.assign(cat, { name: nameInput.value.trim(), icon: iconInput.value.trim() || '📦' });
                        toast('Категория сохранена');
                        editRow.replaceWith(row(cat));
                    } catch (e) {
                        setLoading(save, false);
                        if (e instanceof ApiError && e.errors) err.textContent = e.errors.name || e.errors.icon || e.message;
                        else handleApiError(e);
                    }
                };
                save.addEventListener('click', run);
                nameInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') run(); if (e.key === 'Escape') cancel.click(); });
                const editRow = el('div', { class: 'cat-row' }, iconInput, nameInput, el('div', { class: 'row-actions' }, save, cancel), err);
                editRow.style.gridTemplateColumns = '56px minmax(0, 1fr) auto';
                view.replaceWith(editRow);
                nameInput.focus();
                nameInput.select();
            }

            async function remove(cat) {
                const warn = cat.total_ads
                    ? `В категории ${cat.total_ads} ${plural(cat.total_ads, 'объявление', 'объявления', 'объявлений')}. Они не удалятся, но останутся без категории.`
                    : 'Категория пуста — её можно безопасно удалить.';
                if (!(await confirmDialog(warn, { title: `Удалить «${cat.name}»?`, confirmText: 'Удалить' }))) return;
                try {
                    await adminPost('category_delete', { id: cat.id });
                    items = items.filter((c) => c.id !== cat.id);
                    toast('Категория удалена');
                    render();
                } catch (err) {
                    handleApiError(err);
                }
            }

            async function categoryModal() {
                const body = el('div', {}, field({ name: 'name', label: 'Название', placeholder: 'Например, Спорт и отдых', maxlength: 80 }), field({ name: 'icon', label: 'Иконка (эмодзи)', placeholder: '⚽', maxlength: 8 }));
                body.firstElementChild.style.marginBottom = '14px';
                const done = await openModal({
                    title: 'Новая категория', submitText: 'Добавить', content: body,
                    onSubmit: async (form) => { await adminPost('category_create', { name: form.elements.name.value, icon: form.elements.icon.value }); },
                });
                if (done) { toast('Категория добавлена'); await refresh(); }
            }

            async function refresh() {
                items = (await adminApi('categories')).items;
                render();
            }
            await refresh();
        }

        // ---------------------------------------------------------------- роутер
        const routes = { stats: viewStats, users: viewUsers, ads: viewAds, categories: viewCategories };
        let routeToken = 0;

        async function route() {
            const hash = location.hash.replace(/^#\/?/, '');
            const [name, query = ''] = hash.split('?');
            const section = routes[name] ? name : 'stats';
            const my = ++routeToken;
            navLinks.forEach((a) => a.classList.toggle('is-active', a.dataset.section === section));
            window.scrollTo({ top: 0 });
            try {
                await routes[section](new URLSearchParams(query));
            } catch (err) {
                if (my === routeToken) {
                    content.replaceChildren(emptyState({ emoji: '😵', title: 'Не удалось загрузить раздел', text: err.message }));
                    handleApiError(err);
                }
            }
        }

        window.addEventListener('hashchange', route);
        // счётчик «на модерации» в меню виден сразу, независимо от раздела
        adminApi('stats').then((d) => setPending(d.stats.pending)).catch(() => {});
        route();
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
        messages: initMessages,
        admin: initAdmin,
    };

    document.addEventListener('DOMContentLoaded', () => {
        initHeader();
        const init = pages[document.body.dataset.page];
        if (init) init();
    });
})();
