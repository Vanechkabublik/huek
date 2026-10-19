/* ==========================================================================
   Админ-панель (hash-роутер): дашборд, пользователи, объявления, категории и их характеристики,
   отзывы, журнал действий, интеграции. Общие помощники берутся из window.Board (app.js).
   ========================================================================== */
(() => {
    'use strict';

    const {
        $, $$, el, icon, debounce, plural, ApiError, apiGet, apiPost, handleApiError, toast, setLoading, withLoading,
        field, openModal, confirmDialog, barChart, emptyState,
    } = window.Board;

    const STATUS_TABS = [['', 'Все'], ['pending', 'На модерации'], ['approved', 'Одобрены'], ['rejected', 'Отклонены'], ['blocked', 'Заблокированы']];
    const STATUS_LABELS = { approved: 'Одобрено', rejected: 'Отклонено', blocked: 'Заблокировано', pending: 'На модерации' };

    function initAdmin() {
        const content = $('#adminContent');
        const navLinks = $$('.admin__nav a');
        const navPending = $('#navPending');

        const pill = (status, label) => el('span', { class: `pill pill--${status}` }, label);
        const setPending = (n) => { navPending.textContent = n; navPending.hidden = !n; };
        const adminApi = (action, params) => apiGet('admin', { action, ...params });
        const adminPost = (action, data) => apiPost('admin', action, data);
        const header = (title, subtitle, ...actions) =>
            el('div', { class: 'admin-title' }, el('div', {}, el('h1', {}, title), subtitle ? el('p', {}, subtitle) : null), actions.length ? el('div', { class: 'admin-title__actions' }, ...actions) : null);
        const exportLink = (action, params = {}, label = 'Экспорт CSV') => {
            const sp = new URLSearchParams({ action, ...Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v != null)) });
            return el('a', { class: 'btn btn--outline btn--sm', href: `/api/admin.php?${sp}`, download: '' }, icon('download'), label);
        };

        function tableShell(heads, cls = '') {
            const tbody = el('tbody');
            const headRow = el('tr', {}, ...heads.map((h) => (h instanceof Node ? h : el('th', {}, h))));
            const wrap = el('div', { class: 'table-wrap' }, el('table', { class: `table ${cls}`.trim() }, el('thead', {}, headRow), tbody));
            return { wrap, tbody, headRow };
        }

        const avatarCell = (u) => u.avatar
            ? el('span', { class: 'avatar avatar--img' }, el('img', { src: u.avatar, alt: '', referrerpolicy: 'no-referrer' }))
            : el('span', { class: 'avatar' }, (u.name || '?').charAt(0).toUpperCase());

        const timeline = (items) => el('div', { class: 'timeline' }, ...(items.length ? items : [{ label: 'Записей пока нет', details: {}, date_text: '', actor_name: '' }]).map((h) =>
            el('div', { class: 'tl-item' }, el('b', {}, h.label), h.details && Object.keys(h.details).length ? ` — ${detailsText(h.details)}` : '',
                h.date_text ? el('small', {}, `${h.date_text} · ${h.actor_name}`) : null)));

        const DETAIL_KEYS = { title: 'название', reason: 'причина', name: 'имя', email: 'email', from: 'было', to: 'стало', code: 'код', label: 'поле', action: 'действие', done: 'выполнено', failed: 'ошибок', orphaned_ads: 'без категории', review_id: 'отзыв', author_id: 'автор', attribute_id: 'поле #' };
        const detailsText = (d) => Object.entries(d).map(([k, v]) => `${DETAIL_KEYS[k] || k}: ${v}`).join('; ');

        /** Выбор из списка: подпись + select. */
        const selectField = (name, label, options, value = '') => {
            const select = el('select', { class: 'select', name }, ...options.map(([v, t]) => el('option', { value: v }, t)));
            select.value = value;
            return el('div', { class: 'field' }, el('label', { class: 'field__label' }, label), select, el('p', { class: 'field__error', 'data-error-for': name }));
        };
        const checkField = (name, label, checked = false) =>
            el('label', { class: 'checkbox' }, el('input', { class: 'check', type: 'checkbox', name, checked }), label);

        /* ------------------------------------------------------------------ дашборд */

        async function viewStats() {
            content.replaceChildren(header('Дашборд', 'Сводка по площадке'), el('div', { class: 'stats' }, ...[1, 2, 3, 4].map(() => el('div', { class: 'stat' }, el('div', { class: 'sk', style: { height: '70px' } })))));
            const d = await adminApi('dashboard');
            const s = d.stats;
            setPending(s.pending);
            const tile = (cls, ico, label, value, href) =>
                el('a', { class: `stat stat--${cls}`, href }, el('div', { class: 'stat__label' }, icon(ico), label), el('div', { class: 'stat__num' }, String(value)));

            const css = getComputedStyle(document.documentElement);
            const color = (v) => css.getPropertyValue(v).trim();

            const queue = el('div', { class: 'mini-list' });
            if (!d.queue.length) queue.append(el('div', { class: 'mini-empty' }, '🎉 Очередь модерации пуста'));
            d.queue.forEach((ad) => {
                const row = el('div', { class: 'mini-row' },
                    el('div', { class: 'mini-row__img' }, ad.cover ? el('img', { src: ad.cover, alt: '' }) : null),
                    el('div', { class: 'mini-row__body' }, el('a', { href: ad.url, target: '_blank', rel: 'noopener' }, ad.title), el('div', { class: 'mini-row__sub' }, `${ad.price_text} · ${ad.user_email} · ${ad.date_text}`)),
                    el('button', { class: 'btn btn--success btn--xs', type: 'button', onClick: async (e) => {
                        await withLoading(e.currentTarget, async () => {
                            await adminPost('ad_moderate', { id: ad.id, status: 'approved' });
                            toast('Объявление одобрено');
                            row.remove();
                            s.pending -= 1; setPending(s.pending);
                            if (!$('.mini-row', queue)) queue.append(el('div', { class: 'mini-empty' }, '🎉 Очередь модерации пуста'));
                        });
                    } }, icon('check'), 'Одобрить'));
                queue.append(row);
            });

            const maxCat = Math.max(1, ...d.top_categories.map((c) => c.count));
            const hbars = el('div', { class: 'hbars' }, ...d.top_categories.map((c) =>
                el('div', { class: 'hbar' }, el('span', {}, c.icon), el('div', { class: 'hbar__track' }, el('div', { class: 'hbar__fill', style: { width: (c.count / maxCat) * 100 + '%' } }), ),
                    el('b', {}, String(c.count)))));

            const audit = el('div', { class: 'mini-list' }, ...(d.audit.length ? d.audit.map((a) =>
                el('div', { class: 'mini-row' }, el('div', { class: 'mini-row__body' }, el('b', {}, `${a.actor_name}: `), a.label, a.details && Object.keys(a.details).length ? el('div', { class: 'mini-row__sub' }, detailsText(a.details)) : null, el('div', { class: 'mini-row__sub' }, a.date_text))))
                : [el('div', { class: 'mini-empty' }, 'Действий пока нет')]));

            content.replaceChildren(
                header('Дашборд', 'Сводка по площадке'),
                el('div', { class: 'stats' },
                    tile('blue', 'users', 'Всего пользователей', s.users, '#/users'),
                    tile('green', 'list', 'Всего объявлений', s.ads, '#/ads'),
                    tile('yellow', 'clock', 'На модерации', s.pending, '#/ads?status=pending'),
                    tile('red', 'ban', 'Заблокировано', s.blocked, '#/ads?status=blocked')),
                el('div', { class: 'stats stats--small' },
                    tile('green', 'check-circle', 'Одобрено', s.approved, '#/ads?status=approved'),
                    tile('red', 'x-circle', 'Отклонено', s.rejected, '#/ads?status=rejected'),
                    tile('blue', 'tag', 'Категорий', s.categories, '#/categories'),
                    tile('blue', 'message', 'Диалогов в чате', `${s.chats}`, '#/stats'),
                    tile('yellow', 'send', 'Сообщений', s.messages, '#/stats'),
                    tile('yellow', 'star', 'Отзывов', s.reviews, '#/reviews')),
                el('div', { class: 'admin-grid' },
                    el('div', { class: 'panel-card' }, el('div', { class: 'panel-card__head' }, el('h2', {}, 'Новые пользователи и объявления'),
                        el('span', { class: 'legend' }, el('span', {}, el('i', { class: 'legend__dot' }), 'Пользователи'), el('span', {}, el('i', { class: 'legend__dot legend__dot--yellow' }), 'Объявления'))),
                        el('div', { class: 'chart' }, barChart(d.days, [{ key: 'users', label: 'Пользователи', color: color('--blue') }, { key: 'ads', label: 'Объявления', color: color('--yellow') }], { width: 520 }))),
                    el('div', { class: 'panel-card' }, el('div', { class: 'panel-card__head' }, el('h2', {}, 'Сообщения в чате'), el('span', { class: 'muted' }, '14 дней')),
                        el('div', { class: 'chart' }, barChart(d.days, [{ key: 'messages', label: 'Сообщения', color: color('--green') }], { width: 520 }))),
                    el('div', { class: 'panel-card' }, el('div', { class: 'panel-card__head' }, el('h2', {}, 'Очередь модерации'), el('a', { href: '#/ads?status=pending' }, 'Все →')), queue),
                    el('div', { class: 'panel-card' }, el('div', { class: 'panel-card__head' }, el('h2', {}, 'Популярные категории'), el('span', { class: 'muted' }, 'опубликовано')), hbars),
                    el('div', { class: 'panel-card' }, el('div', { class: 'panel-card__head' }, el('h2', {}, 'Последние действия'), el('a', { href: '#/audit' }, 'Журнал →')), audit)));
        }

        /* -------------------------------------------------------------- пользователи */

        async function viewUsers(params) {
            const state = { q: '', filter: params.get('filter') || 'all', page: 1, items: [], total: 0, hasMore: false, token: 0 };
            const search = el('input', { class: 'input', type: 'search', placeholder: 'Поиск по email, имени, телефону или #id…', autocomplete: 'off', 'aria-label': 'Поиск пользователей' });
            const filterSel = el('select', { class: 'select', 'aria-label': 'Фильтр' },
                ...[['all', 'Все пользователи'], ['active', 'Активные'], ['blocked', 'Заблокированные'], ['admin', 'Администраторы'], ['vk', 'Вошли через VK']].map(([v, t]) => el('option', { value: v }, t)));
            filterSel.value = state.filter;
            const { wrap, tbody } = tableShell(['Пользователь', 'Телефон', 'Регистрация', 'Последний вход', 'Статус', 'Объявл.', 'Действия'], 'table--users');
            const foot = el('div', { class: 'table-foot' });
            const more = el('button', { class: 'btn btn--outline btn--sm', type: 'button', onClick: () => load(true) }, 'Показать ещё');
            const exportBox = el('div');

            content.replaceChildren(
                header('Пользователи', 'Мгновенный поиск по email, имени, телефону', exportBox),
                el('div', { class: 'toolbar' }, el('div', { class: 'search-big' }, icon('search'), search), filterSel),
                el('div', { class: 'panel' }, wrap, foot));
            exportBox.append(exportLink('export_users'));

            function render() {
                tbody.replaceChildren(...state.items.map(userRow));
                if (!state.items.length) tbody.append(el('tr', {}, el('td', { colspan: 7 }, el('div', { class: 'table-empty' }, state.q ? 'Ничего не найдено' : 'Пользователей пока нет'))));
                foot.replaceChildren(...[el('span', {}, `Найдено: ${state.total}`), state.hasMore && more].filter(Boolean));
            }

            function userRow(u) {
                const isAdmin = u.role === 'admin';
                const actions = el('div', { class: 'row-actions' },
                    el('button', { class: 'btn btn--outline btn--xs', type: 'button', onClick: () => userModal(u.id) }, icon('info'), 'Подробнее'),
                    el('button', { class: 'btn btn--outline btn--xs', type: 'button', onClick: () => (location.hash = `#/ads?user=${u.id}`) }, icon('list'), 'Объявления'));
                if (!isAdmin) {
                    actions.append(
                        el('button', { class: 'btn btn--outline btn--xs', type: 'button', onClick: () => changePassword(u) }, icon('key'), 'Пароль'),
                        el('button', { class: `btn btn--xs ${u.is_blocked ? 'btn--success' : 'btn--dark'}`, type: 'button', onClick: (e) => toggleBlock(u, e.currentTarget) },
                            icon(u.is_blocked ? 'unlock' : 'lock'), u.is_blocked ? 'Разблокировать' : 'Заблокировать'),
                        el('button', { class: 'btn btn--danger btn--xs', type: 'button', onClick: () => removeUser(u) }, icon('trash'), 'Удалить'));
                }
                return el('tr', {},
                    el('td', { 'data-label': 'Пользователь' }, el('div', { class: 'cell-user' }, avatarCell(u),
                        el('div', { class: 'cell-stack' }, el('span', { class: 'cell-title' }, u.name), el('span', { class: 'cell-sub' }, u.email || '— (вход через VK)'),
                            isAdmin ? pill('admin', 'Администратор') : null, u.has_vk ? pill('admin', 'VK') : null))),
                    el('td', { class: 'nowrap', 'data-label': 'Телефон' }, u.phone || '—'),
                    el('td', { class: 'nowrap', 'data-label': 'Регистрация' }, u.created_at),
                    el('td', { class: 'nowrap', 'data-label': 'Последний вход' }, u.last_login),
                    el('td', { 'data-label': 'Статус' }, u.is_blocked ? pill('blocked', 'Заблокирован') : pill('active', 'Активен')),
                    el('td', { class: 'num', 'data-label': 'Объявлений' }, String(u.ads_count)),
                    el('td', {}, actions));
            }

            async function load(append = false) {
                const my = ++state.token;
                if (append) state.page += 1; else state.page = 1;
                try {
                    const data = await adminApi('users', { q: state.q, filter: state.filter, page: state.page });
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
                if (block && !(await confirmDialog(`${u.name} не сможет войти на сайт, а его объявления пропадут из ленты.`, { title: 'Заблокировать пользователя?', confirmText: 'Заблокировать' }))) return;
                await withLoading(btn, async () => {
                    await adminPost(block ? 'user_block' : 'user_unblock', { id: u.id });
                    u.is_blocked = block;
                    toast(block ? 'Пользователь заблокирован' : 'Пользователь разблокирован');
                    render();
                });
            }

            async function changePassword(u) {
                await openModal({
                    title: 'Сменить пароль', text: u.email || u.name, submitText: 'Сменить пароль',
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
                const ok = await confirmDialog(`Пользователь ${u.name}, все его объявления (${u.ads_count}), чаты и фото будут удалены безвозвратно.`, { title: 'Удалить пользователя?', confirmText: 'Удалить' });
                if (!ok) return false;
                try {
                    await adminPost('user_delete', { id: u.id });
                    state.items = state.items.filter((x) => x.id !== u.id);
                    state.total -= 1;
                    toast('Пользователь удалён');
                    render();
                    return true;
                } catch (err) {
                    handleApiError(err);
                    return false;
                }
            }

            /** Карточка пользователя: профиль, сводка, последние объявления, история действий. */
            async function userModal(id) {
                let d;
                try { d = await adminApi('user_detail', { id }); } catch (err) { handleApiError(err); return; }
                const u = d.user;
                const row = state.items.find((x) => x.id === id) || { id, name: u.name, email: u.email, ads_count: d.ads.all, is_blocked: u.is_blocked };
                const isAdmin = u.role === 'admin';
                const recent = el('div', { class: 'mini-list' }, ...(d.recent.length ? d.recent.map((ad) =>
                    el('div', { class: 'mini-row' }, el('div', { class: 'mini-row__img' }, ad.cover ? el('img', { src: ad.cover, alt: '' }) : null),
                        el('div', { class: 'mini-row__body' }, el('a', { href: ad.url, target: '_blank', rel: 'noopener' }, ad.title), el('div', { class: 'mini-row__sub' }, `${ad.price_text} · ${ad.status_label}`))))
                    : [el('div', { class: 'mini-empty' }, 'Объявлений нет')]));
                const body = el('div', {},
                    el('div', { class: 'detail-head' }, avatarCell({ name: u.name, avatar: u.avatar }),
                        el('div', {}, el('h3', {}, u.name), el('div', { class: 'cell-sub' }, u.email || 'Вход через VK, email не указан')),
                        isAdmin ? pill('admin', 'Администратор') : null, u.is_blocked ? pill('blocked', 'Заблокирован') : null),
                    el('div', { class: 'detail-grid' },
                        el('div', {},
                            el('dl', { class: 'kv' }, el('dt', {}, 'ID'), el('dd', {}, `#${u.id}`), el('dt', {}, 'Телефон'), el('dd', {}, u.phone || '—'),
                                el('dt', {}, 'Регистрация'), el('dd', {}, u.created_at), el('dt', {}, 'Последний вход'), el('dd', {}, u.last_login),
                                el('dt', {}, 'VK'), el('dd', {}, u.vk_id ? `привязан (id ${u.vk_id})` : 'нет'), el('dt', {}, 'О себе'), el('dd', {}, u.about || '—')),
                            el('div', { class: 'detail-section-title' }, 'Активность'),
                            el('div', { class: 'stat-row' },
                                el('span', {}, 'Объявлений: ', el('b', {}, String(d.ads.all))), el('span', {}, 'на модерации: ', el('b', {}, String(d.ads.pending))),
                                el('span', {}, 'в избранном: ', el('b', {}, String(d.favorites))), el('span', {}, 'диалогов: ', el('b', {}, String(d.chats))),
                                el('span', {}, 'отзывов: ', el('b', {}, `${d.reviews.count}${d.reviews.count ? ` (★ ${d.reviews.avg})` : ''}`)))),
                        el('div', {}, el('div', { class: 'detail-section-title', style: { marginTop: 0 } }, 'Последние объявления'), recent)),
                    el('div', { class: 'detail-section-title' }, 'История действий администраторов'), timeline(d.history));

                const closeBtn = () => $('.modal-overlay.is-open [type="submit"]')?.click();
                const actions = [];
                if (!isAdmin) {
                    actions.push({ label: u.is_blocked ? 'Разблокировать' : 'Заблокировать', cls: u.is_blocked ? 'btn--success' : 'btn--dark', run: () => toggleBlock(row, null) });
                    actions.push({ label: 'Сменить пароль', cls: 'btn--outline', run: () => changePassword(row) });
                    actions.push({ label: 'Сделать админом', cls: 'btn--outline', run: () => setRole(u, 'admin') });
                    actions.push({ label: 'Удалить', cls: 'btn--danger', run: () => removeUser(row) });
                } else if (u.id !== window.Board.APP.user.id) {
                    actions.push({ label: 'Снять права админа', cls: 'btn--outline', run: () => setRole(u, 'user') });
                }
                const bar = el('div', { class: 'modal__actions', style: { flexWrap: 'wrap', justifyContent: 'flex-start' } },
                    ...actions.map((a) => el('button', { class: `btn btn--sm ${a.cls}`, type: 'button', onClick: async () => { closeBtn(); await a.run(); load(); } }, a.label)));
                body.append(bar);
                await openModal({ title: 'Пользователь', content: body, submitText: 'Закрыть', hideCancel: true, modalClass: 'modal--xl' });
            }

            async function setRole(u, role) {
                const ok = await confirmDialog(role === 'admin' ? `${u.name} получит полный доступ к админ-панели.` : `${u.name} потеряет доступ к админ-панели.`, { title: role === 'admin' ? 'Назначить администратором?' : 'Снять права администратора?', confirmText: 'Подтвердить', danger: role !== 'admin' });
                if (!ok) return;
                try { await adminPost('user_role', { id: u.id, role }); toast('Роль изменена'); } catch (err) { handleApiError(err); }
            }

            search.addEventListener('input', debounce(() => { state.q = search.value.trim(); load(); }, 300));
            filterSel.addEventListener('change', () => { state.filter = filterSel.value; load(); });
            search.focus();
            await load();
        }

        /* --------------------------------------------------------------- объявления */

        async function viewAds(params) {
            const state = {
                status: params.get('status') || '', user: params.get('user') || '', q: '', category: params.get('category') || '', sort: 'date_desc',
                page: 1, items: [], total: 0, counts: {}, hasMore: false, token: 0, selected: new Set(),
            };
            let categories = [];
            try { categories = (await adminApi('categories')).items; } catch { /* фильтр по категории необязателен */ }

            const tabsBox = el('div', { class: 'tabs' });
            const chips = el('div', { class: 'toolbar' });
            const search = el('input', { class: 'input', type: 'search', placeholder: 'Поиск по заголовку, email автора или #id…', autocomplete: 'off', 'aria-label': 'Поиск объявлений' });
            const catSel = el('select', { class: 'select', 'aria-label': 'Категория' }, el('option', { value: '' }, 'Все категории'), el('option', { value: '-1' }, 'Без категории'),
                ...categories.map((c) => el('option', { value: String(c.id) }, `${c.icon} ${c.name}`)));
            catSel.value = state.category;
            const sortSel = el('select', { class: 'select', 'aria-label': 'Сортировка' },
                ...[['date_desc', 'Сначала новые'], ['date_asc', 'Сначала старые'], ['price_desc', 'Цена ↓'], ['price_asc', 'Цена ↑'], ['views_desc', 'По просмотрам']].map(([v, t]) => el('option', { value: v }, t)));
            const checkAll = el('input', { class: 'check', type: 'checkbox', 'aria-label': 'Выбрать все' });
            const { wrap, tbody } = tableShell([el('th', { class: 'col-check' }, checkAll), 'ID', 'Объявление', 'Пользователь', 'Цена', 'Статус', 'Просм.', 'Дата', 'Действия'], 'table--ads table--sticky');
            const foot = el('div', { class: 'table-foot' });
            const bulkSlot = el('div');
            const exportBox = el('div');
            const more = el('button', { class: 'btn btn--outline btn--sm', type: 'button', onClick: () => load(true) }, 'Показать ещё');

            content.replaceChildren(
                header('Объявления', 'Модерация и управление', exportBox),
                el('div', { class: 'toolbar' }, el('div', { class: 'search-big' }, icon('search'), search), catSel, sortSel),
                chips, tabsBox,
                el('div', { class: 'panel' }, wrap, foot), bulkSlot);

            const adParams = () => ({ status: state.status, user: state.user, q: state.q, category: state.category });

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
                exportBox.replaceChildren(exportLink('export_ads', adParams()));
            }

            function renderBulk() {
                const n = state.selected.size;
                checkAll.checked = n > 0 && n === state.items.length;
                checkAll.indeterminate = n > 0 && n < state.items.length;
                if (!n) { bulkSlot.replaceChildren(); return; }
                const bar = el('div', { class: 'bulk-bar' },
                    el('strong', {}, `Выбрано: ${n}`),
                    el('button', { class: 'btn btn--success btn--sm', type: 'button', onClick: () => bulk('approve') }, icon('check'), 'Одобрить'),
                    el('button', { class: 'btn btn--danger btn--sm', type: 'button', onClick: () => bulk('reject') }, icon('x'), 'Отклонить'),
                    el('button', { class: 'btn btn--dark btn--sm', type: 'button', onClick: () => bulk('block') }, icon('ban'), 'Заблокировать'),
                    el('button', { class: 'btn btn--outline btn--sm btn--danger-text', type: 'button', onClick: () => bulk('delete') }, icon('trash'), 'Удалить'),
                    el('button', { class: 'btn btn--text btn--sm', style: { color: '#fff' }, type: 'button', onClick: () => { state.selected.clear(); render(); } }, 'Снять выбор'));
                bulkSlot.replaceChildren(bar);
            }

            function render() {
                renderTabs();
                tbody.replaceChildren(...state.items.map(adRow));
                if (!state.items.length) tbody.append(el('tr', {}, el('td', { colspan: 9 }, el('div', { class: 'table-empty' }, 'Объявлений не найдено'))));
                foot.replaceChildren(...[el('span', {}, `Найдено: ${state.total}`), state.hasMore && more].filter(Boolean));
                setPending(state.counts.pending ?? 0);
                renderBulk();
            }

            function adRow(ad) {
                const act = el('div', { class: 'row-actions' });
                act.append(el('button', { class: 'btn btn--outline btn--xs', type: 'button', 'aria-label': 'Подробнее', title: 'Подробнее', onClick: () => adModal(ad.id) }, icon('info')));
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
                const check = el('input', { class: 'check', type: 'checkbox', checked: state.selected.has(ad.id), 'aria-label': `Выбрать объявление ${ad.id}` });
                check.addEventListener('change', () => { check.checked ? state.selected.add(ad.id) : state.selected.delete(ad.id); renderBulk(); });
                return el('tr', {},
                    el('td', { class: 'col-check' }, check),
                    el('td', { class: 'num', 'data-label': 'ID' }, '#' + ad.id),
                    el('td', { 'data-label': 'Объявление' }, el('div', { class: 'cell-stack' },
                        el('a', { class: 'cell-title', href: ad.url, target: '_blank', rel: 'noopener', title: ad.title }, ad.title),
                        el('span', { class: 'cell-sub' }, `${ad.category_icon} ${ad.category_name} · ${ad.city}`))),
                    el('td', { 'data-label': 'Пользователь' }, el('div', { class: 'cell-stack' }, el('span', { class: 'cell-title', title: ad.user_name }, ad.user_email || ad.user_name), ad.user_blocked ? pill('blocked', 'Автор заблокирован') : null)),
                    el('td', { class: 'price', 'data-label': 'Цена' }, ad.price_text),
                    el('td', { 'data-label': 'Статус' }, el('div', { class: 'cell-stack' }, pill(ad.status, ad.status_label), ad.rejection_reason && ad.status !== 'approved' ? el('span', { class: 'cell-reason' }, ad.rejection_reason) : null)),
                    el('td', { class: 'num', 'data-label': 'Просмотры' }, String(ad.views)),
                    el('td', { class: 'nowrap', title: ad.date_full, 'data-label': 'Дата' }, ad.date_text),
                    el('td', {}, act));
            }

            /** Меняет статус в локальном состоянии и пересчитывает табы без повторного запроса. */
            function applyStatus(ad, status, reason) {
                state.counts[ad.status] -= 1;
                state.counts[status] = (state.counts[status] || 0) + 1;
                ad.status = status;
                ad.status_label = STATUS_LABELS[status];
                ad.rejection_reason = reason || null;
                if (state.status && state.status !== status) {
                    state.items = state.items.filter((a) => a !== ad);
                    state.total -= 1;
                    state.selected.delete(ad.id);
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

            /** Модалка «Укажите причину». Резолвится причиной или null. */
            function askReasonModal(title, text, submitKind, run) {
                return openModal({
                    title, text, submitText: 'Подтвердить', danger: true, submitKind,
                    content: field({ name: 'reason', rows: 4, maxlength: 1000, placeholder: 'Например: добавьте реальные фото и опишите состояние…' }),
                    onSubmit: async (form) => {
                        const reason = form.elements.reason.value.trim();
                        if (reason.length < 3) throw new ApiError('', 422, { reason: 'Укажите причину (минимум 3 символа)' });
                        await run(reason);
                        return reason;
                    },
                });
            }

            async function reject(ad, status) {
                const isBlock = status === 'blocked';
                const reason = await askReasonModal('Укажите причину',
                    `${isBlock ? 'Заблокировать' : 'Отклонить'} объявление «${ad.title}». Автор увидит причину в «Моих объявлениях».`,
                    isBlock ? 'btn--dark' : 'btn--danger', (r) => adminPost('ad_moderate', { id: ad.id, status, reason: r }));
                if (reason) {
                    toast(isBlock ? 'Объявление заблокировано' : 'Объявление отклонено');
                    applyStatus(ad, status, reason);
                }
            }

            async function bulk(kind) {
                const ids = [...state.selected];
                const verb = { approve: 'Одобрить', reject: 'Отклонить', block: 'Заблокировать', delete: 'Удалить' }[kind];
                const done = (r) => {
                    toast(`${verb}: выполнено ${r.done}${r.failed ? `, ошибок ${r.failed}` : ''}`, r.failed ? 'warning' : 'success');
                    state.selected.clear();
                    load();
                };
                if (kind === 'reject' || kind === 'block') {
                    const reason = await askReasonModal('Укажите причину', `${verb} выбранные объявления (${ids.length}). Причина будет показана авторам.`, kind === 'block' ? 'btn--dark' : 'btn--danger',
                        async (r) => done(await adminPost('ads_bulk', { ids, action_type: kind, reason: r })));
                    return reason;
                }
                if (kind === 'delete' && !(await confirmDialog(`Выбранные объявления (${ids.length}) и их фото будут удалены безвозвратно.`, { title: 'Удалить объявления?', confirmText: 'Удалить' }))) return;
                try { done(await adminPost('ads_bulk', { ids, action_type: kind })); } catch (err) { handleApiError(err); }
            }

            /** Подробная карточка объявления с историей модерации и статистикой. */
            async function adModal(id) {
                let d;
                try { d = await adminApi('ad_detail', { id }); } catch (err) { handleApiError(err); return; }
                const a = d.ad;
                const s = d.stats;
                const body = el('div', {},
                    a.images.length ? el('div', { class: 'gallery-strip' }, ...a.images.map((i) => el('img', { src: i.thumb, alt: '' }))) : null,
                    el('div', { class: 'detail-head' }, el('div', {}, el('h3', {}, a.title), el('div', { class: 'cell-sub' }, `${a.price_text} · ${a.city} · ${a.category_name}`)), pill(a.status, a.status_label)),
                    a.rejection_reason ? el('div', { class: 'card__reason' }, el('b', {}, 'Причина'), a.rejection_reason) : null,
                    el('div', { class: 'detail-grid' },
                        el('div', {},
                            el('dl', { class: 'kv' }, el('dt', {}, 'ID'), el('dd', {}, `#${a.id}`),
                                el('dt', {}, 'Автор'), el('dd', {}, el('a', { href: `#/ads?user=${a.user_id}` }, `${a.user_name} (${a.seller_email || '—'})`)),
                                el('dt', {}, 'Создано'), el('dd', {}, a.date_full),
                                ...a.attribute_list.flatMap((x) => [el('dt', {}, x.label), el('dd', {}, x.value)])),
                            el('div', { class: 'detail-section-title' }, 'Статистика'),
                            el('div', { class: 'stat-row' }, el('span', {}, 'Просмотры: ', el('b', {}, String(s.views))), el('span', {}, 'в избранном: ', el('b', {}, String(s.favorites))),
                                el('span', {}, 'телефон: ', el('b', {}, String(s.phones))), el('span', {}, 'диалоги: ', el('b', {}, String(s.chats))))),
                        el('div', {}, el('div', { class: 'detail-section-title', style: { marginTop: 0 } }, 'Описание'), el('p', { style: { whiteSpace: 'pre-line' } }, a.description))),
                    el('div', { class: 'detail-section-title' }, 'История модерации'), timeline(d.history));
                const row = state.items.find((x) => x.id === id) || { id, title: a.title, status: a.status };
                const closeBtn = () => $('.modal-overlay.is-open [type="submit"]')?.click();
                const bar = el('div', { class: 'modal__actions', style: { flexWrap: 'wrap', justifyContent: 'flex-start' } });
                if (a.status !== 'approved') bar.append(el('button', { class: 'btn btn--success btn--sm', type: 'button', onClick: async () => { closeBtn(); try { await adminPost('ad_moderate', { id, status: 'approved' }); toast('Объявление одобрено'); load(); } catch (e) { handleApiError(e); } } }, a.status === 'blocked' ? 'Разблокировать' : 'Одобрить'));
                if (a.status === 'pending' || a.status === 'approved') bar.append(el('button', { class: 'btn btn--danger btn--sm', type: 'button', onClick: () => { closeBtn(); reject(row, 'rejected').then(load); } }, 'Отклонить'));
                if (a.status !== 'blocked') bar.append(el('button', { class: 'btn btn--dark btn--sm', type: 'button', onClick: () => { closeBtn(); reject(row, 'blocked').then(load); } }, 'Заблокировать'));
                bar.append(el('button', { class: 'btn btn--outline btn--sm btn--danger-text', type: 'button', onClick: async () => {
                    closeBtn();
                    if (!(await confirmDialog('Объявление и все его фото будут удалены безвозвратно.', { title: 'Удалить объявление?', confirmText: 'Удалить' }))) return;
                    try { await adminPost('ad_delete', { id }); toast('Объявление удалено'); load(); } catch (e) { handleApiError(e); }
                } }, icon('trash'), 'Удалить'));
                body.append(bar);
                await openModal({ title: `Объявление #${a.id}`, content: body, submitText: 'Закрыть', hideCancel: true, modalClass: 'modal--xl' });
            }

            async function load(append = false) {
                const my = ++state.token;
                if (append) state.page += 1; else { state.page = 1; state.selected.clear(); }
                try {
                    const data = await adminApi('ads', { ...adParams(), sort: state.sort, page: state.page });
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

            checkAll.addEventListener('change', () => {
                if (checkAll.checked) state.items.forEach((a) => state.selected.add(a.id)); else state.selected.clear();
                render();
            });
            search.addEventListener('input', debounce(() => { state.q = search.value.trim(); load(); }, 300));
            catSel.addEventListener('change', () => { state.category = catSel.value; load(); });
            sortSel.addEventListener('change', () => { state.sort = sortSel.value; load(); });
            renderTabs();
            await load();
        }

        /* ------------------------------------------------------------------ категории */

        async function viewCategories() {
            const list = el('div', { class: 'cat-list' });
            const addBtn = el('button', { class: 'btn btn--primary', type: 'button', onClick: () => categoryModal() }, icon('plus'), 'Добавить категорию');
            content.replaceChildren(header('Категории', 'Разделы доски и их характеристики (авто — марка и пробег, техника — бренд и состояние …)', addBtn), el('div', { class: 'panel' }, list));

            let items = [];
            const render = () => {
                list.replaceChildren(...items.map(row));
                if (!items.length) list.append(el('div', { class: 'table-empty' }, 'Категорий пока нет'));
            };

            function row(cat) {
                return el('div', { class: 'cat-row' },
                    el('div', { class: 'cat-row__icon' }, cat.icon),
                    el('div', { class: 'cat-row__name' }, cat.name),
                    el('div', { class: 'cat-row__count' }, `${cat.total_ads} ${plural(cat.total_ads, 'объявление', 'объявления', 'объявлений')}`),
                    el('div', { class: 'row-actions' },
                        el('button', { class: 'btn btn--outline btn--sm', type: 'button', onClick: () => attributesModal(cat) }, icon('sliders'), 'Характеристики'),
                        el('button', { class: 'btn btn--outline btn--sm', type: 'button', onClick: (e) => edit(cat, e.currentTarget.closest('.cat-row')) }, icon('edit'), 'Изменить'),
                        el('button', { class: 'btn btn--danger btn--sm', type: 'button', onClick: () => remove(cat) }, icon('trash'), 'Удалить')));
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

            /** Редактор характеристик категории: список полей + добавление/правка/удаление. */
            async function attributesModal(cat) {
                const box = el('div', { class: 'panel', style: { boxShadow: 'none', border: '1px solid var(--border-2)' } });
                const addBtn = el('button', { class: 'btn btn--primary btn--sm', type: 'button', onClick: () => attrForm(null) }, icon('plus'), 'Добавить характеристику');
                const body = el('div', {}, el('p', { class: 'modal__text' }, 'Эти поля появятся в форме объявления и в фильтрах категории «' + cat.name + '».'), box, el('div', { style: { marginTop: '14px' } }, addBtn));
                let defs = [];

                function renderDefs() {
                    box.replaceChildren(...(defs.length ? defs.map((d) =>
                        el('div', { class: 'attr-def' },
                            el('div', {}, el('div', { class: 'attr-def__title' }, d.label, d.unit ? ` (${d.unit})` : ''),
                                el('div', { class: 'attr-def__meta' }, el('span', { class: 'chip-tag' }, { text: 'текст', number: 'число', select: 'список' }[d.type]),
                                    d.required ? el('span', { class: 'chip-tag chip-tag--blue' }, 'обязательное') : null, d.filterable ? el('span', { class: 'chip-tag chip-tag--blue' }, 'в фильтрах') : null,
                                    `код: ${d.code}`, d.type === 'select' ? ` · ${d.options.length} вариантов` : '', d.type === 'number' && (d.min !== null || d.max !== null) ? ` · ${d.min ?? '…'}–${d.max ?? '…'}` : '')),
                            el('div', { class: 'row-actions' },
                                el('button', { class: 'btn btn--outline btn--xs', type: 'button', onClick: () => attrForm(d) }, icon('edit'), 'Изменить'),
                                el('button', { class: 'btn btn--danger btn--xs', type: 'button', onClick: () => delAttr(d) }, icon('trash')))))
                        : [el('div', { class: 'table-empty' }, 'Характеристик пока нет')]));
                }
                async function reloadDefs() { defs = (await adminApi('attributes', { category_id: cat.id })).items; renderDefs(); }

                async function delAttr(d) {
                    if (!(await confirmDialog(`Поле «${d.label}» исчезнет из формы и фильтров. Уже введённые значения останутся в объявлениях, но не будут показываться.`, { title: 'Удалить характеристику?', confirmText: 'Удалить' }))) return;
                    try { await adminPost('attribute_delete', { id: d.id, category_id: cat.id }); toast('Характеристика удалена'); await reloadDefs(); } catch (err) { handleApiError(err); }
                }

                async function attrForm(d) {
                    const typeSel = selectField('type', 'Тип поля', [['text', 'Текст'], ['number', 'Число'], ['select', 'Выбор из списка']], d ? d.type : 'text');
                    const optionsField = field({ name: 'options', label: 'Варианты (по одному в строке)', rows: 4, value: d ? d.options.join('\n') : '' });
                    const numberRow = el('div', { class: 'field-row' }, field({ name: 'min', label: 'Минимум', value: d && d.min !== null ? String(d.min) : '' }), field({ name: 'max', label: 'Максимум', value: d && d.max !== null ? String(d.max) : '' }));
                    const content = el('div', {},
                        el('div', { class: 'field-row' }, field({ name: 'label', label: 'Название', value: d ? d.label : '', placeholder: 'Например, Пробег', maxlength: 80 }), field({ name: 'code', label: 'Код (латиница)', value: d ? d.code : '', placeholder: 'mileage', maxlength: 40 })),
                        el('div', { class: 'field-row' }, typeSel, field({ name: 'unit', label: 'Единица (необязательно)', value: d && d.unit ? d.unit : '', placeholder: 'км', maxlength: 16 })),
                        optionsField, numberRow,
                        el('div', { style: { display: 'flex', gap: '22px', flexWrap: 'wrap', margin: '4px 0 8px' } }, checkField('required', 'Обязательное', d ? d.required : false), checkField('filterable', 'Показывать в фильтрах', d ? d.filterable : true)));
                    const sync = () => { optionsField.hidden = typeSel.querySelector('select').value !== 'select'; numberRow.hidden = typeSel.querySelector('select').value !== 'number'; };
                    typeSel.querySelector('select').addEventListener('change', sync);
                    sync();
                    const ok = await openModal({
                        title: d ? 'Изменить характеристику' : 'Новая характеристика', submitText: 'Сохранить', content,
                        onSubmit: async (form) => {
                            await adminPost('attribute_save', {
                                category_id: cat.id, id: d ? d.id : 0, label: form.elements.label.value, code: form.elements.code.value, type: form.elements.type.value,
                                unit: form.elements.unit.value, options: form.elements.options.value, min: form.elements.min.value, max: form.elements.max.value,
                                required: form.elements.required.checked ? 1 : 0, filterable: form.elements.filterable.checked ? 1 : 0, sort_order: d ? d.sort_order : (defs.length + 1) * 10,
                            });
                        },
                    });
                    if (ok) { toast('Характеристика сохранена'); await reloadDefs(); }
                }

                await reloadDefs();
                // Основное окно не закрываем при открытии вложенных модалок: просто показываем список
                await openModal({ title: `Характеристики: ${cat.icon} ${cat.name}`, content: body, submitText: 'Готово', hideCancel: true, modalClass: 'modal--wide' });
            }

            async function refresh() {
                items = (await adminApi('categories')).items;
                render();
            }
            await refresh();
        }

        /* --------------------------------------------------------------------- отзывы */

        async function viewReviews() {
            const state = { page: 1, items: [], total: 0, hasMore: false };
            const { wrap, tbody } = tableShell(['Дата', 'Продавец', 'Автор', 'Оценка', 'Отзыв', 'Действия']);
            const foot = el('div', { class: 'table-foot' });
            const more = el('button', { class: 'btn btn--outline btn--sm', type: 'button', onClick: () => load(true) }, 'Показать ещё');
            content.replaceChildren(header('Отзывы', 'Модерация отзывов о продавцах'), el('div', { class: 'panel' }, wrap, foot));

            const stars = (n) => el('span', { class: 'stars' }, ...[1, 2, 3, 4, 5].map((i) => icon('star', i <= n ? 'is-on' : '')));
            function render() {
                tbody.replaceChildren(...state.items.map((r) => el('tr', {},
                    el('td', { class: 'nowrap', 'data-label': 'Дата' }, r.date_text),
                    el('td', { 'data-label': 'Продавец' }, el('a', { href: `/seller.php?id=${r.seller_id}`, target: '_blank', rel: 'noopener' }, r.seller_name)),
                    el('td', { 'data-label': 'Автор' }, r.author_name),
                    el('td', { 'data-label': 'Оценка' }, stars(r.rating)),
                    el('td', { 'data-label': 'Отзыв', style: { whiteSpace: 'normal', maxWidth: '420px' } }, r.body),
                    el('td', {}, el('button', { class: 'btn btn--danger btn--xs', type: 'button', onClick: () => remove(r) }, icon('trash'), 'Удалить')))));
                if (!state.items.length) tbody.append(el('tr', {}, el('td', { colspan: 6 }, el('div', { class: 'table-empty' }, 'Отзывов пока нет'))));
                foot.replaceChildren(...[el('span', {}, `Всего: ${state.total}`), state.hasMore && more].filter(Boolean));
            }
            async function remove(r) {
                if (!(await confirmDialog(`Отзыв от ${r.author_name} о продавце ${r.seller_name} будет удалён.`, { title: 'Удалить отзыв?', confirmText: 'Удалить' }))) return;
                try { await adminPost('review_delete', { id: r.id }); state.items = state.items.filter((x) => x.id !== r.id); state.total -= 1; toast('Отзыв удалён'); render(); } catch (err) { handleApiError(err); }
            }
            async function load(append = false) {
                if (append) state.page += 1;
                try {
                    const data = await adminApi('reviews', { page: state.page });
                    state.items = append ? state.items.concat(data.items) : data.items;
                    state.total = data.total; state.hasMore = data.has_more;
                    render();
                } catch (err) { if (append) state.page -= 1; handleApiError(err); }
            }
            await load();
        }

        /* --------------------------------------------------------------------- журнал */

        async function viewAudit(params) {
            const state = { filter: params.get('filter') || '', page: 1, items: [], total: 0, hasMore: false, token: 0 };
            const filterSel = el('select', { class: 'select', 'aria-label': 'Тип действий' },
                ...[['', 'Все действия'], ['ad.', 'Объявления'], ['user.', 'Пользователи'], ['category.', 'Категории'], ['attribute.', 'Характеристики'], ['review.', 'Отзывы'], ['bulk', 'Массовые действия']].map(([v, t]) => el('option', { value: v }, t)));
            filterSel.value = state.filter;
            const { wrap, tbody } = tableShell(['Когда', 'Кто', 'Действие', 'Объект', 'Детали']);
            const foot = el('div', { class: 'table-foot' });
            const more = el('button', { class: 'btn btn--outline btn--sm', type: 'button', onClick: () => load(true) }, 'Показать ещё');
            content.replaceChildren(header('Журнал действий', 'Кто и что менял в панели администратора'), el('div', { class: 'toolbar' }, filterSel), el('div', { class: 'panel' }, wrap, foot));

            const target = (a) => {
                if (!a.target_type || !a.target_id) return '—';
                if (a.target_type === 'ad') return el('a', { href: `/ad.php?id=${a.target_id}`, target: '_blank', rel: 'noopener' }, `объявление #${a.target_id}`);
                if (a.target_type === 'user') return el('a', { href: `#/users` }, `пользователь #${a.target_id}`);
                return `категория #${a.target_id}`;
            };
            function render() {
                tbody.replaceChildren(...state.items.map((a) => el('tr', {},
                    el('td', { class: 'nowrap', 'data-label': 'Когда' }, a.date_text), el('td', { 'data-label': 'Кто' }, a.actor_name),
                    el('td', { 'data-label': 'Действие' }, a.label), el('td', { 'data-label': 'Объект' }, target(a)),
                    el('td', { 'data-label': 'Детали' }, el('span', { class: 'audit-details' }, detailsText(a.details))))));
                if (!state.items.length) tbody.append(el('tr', {}, el('td', { colspan: 5 }, el('div', { class: 'table-empty' }, 'Записей нет'))));
                foot.replaceChildren(...[el('span', {}, `Всего: ${state.total}`), state.hasMore && more].filter(Boolean));
            }
            async function load(append = false) {
                const my = ++state.token;
                if (append) state.page += 1; else state.page = 1;
                try {
                    const data = await adminApi('audit', { filter: state.filter, page: state.page });
                    if (my !== state.token) return;
                    state.items = append ? state.items.concat(data.items) : data.items;
                    state.total = data.total; state.hasMore = data.has_more;
                    render();
                } catch (err) { if (append) state.page -= 1; handleApiError(err); }
            }
            filterSel.addEventListener('change', () => { state.filter = filterSel.value; load(); });
            await load();
        }

        /* --------------------------------------------------------------------- интеграции */

        async function copyText(text) {
            try {
                await navigator.clipboard.writeText(text);
            } catch {
                const t = el('textarea', { style: { position: 'fixed', opacity: '0' } });
                t.value = text;
                document.body.append(t);
                t.select();
                try { document.execCommand('copy'); } catch { /* нет доступа к буферу */ }
                t.remove();
            }
            toast('Скопировано', 'success', 1500);
        }

        /** Строка «значение + кнопка копирования». */
        const copyBox = (text, { block = false } = {}) => el('div', { class: `copybox${block ? ' copybox--block' : ''}` },
            el('code', {}, text),
            el('button', { class: 'btn btn--outline btn--xs', type: 'button', onClick: () => copyText(text) }, 'Копировать'));

        async function viewIntegrations() {
            const d = await adminApi('integrations');
            const vk = d.vk;
            const yesNo = (ok, yes, no) => pill(ok ? 'active' : 'pending', ok ? yes : no);

            const vkSteps = el('ol', { class: 'steps' },
                el('li', {}, 'Откройте ', el('a', { href: 'https://id.vk.ru/about/business/go', target: '_blank', rel: 'noopener' }, 'кабинет VK ID для бизнеса'),
                    ' и войдите своим аккаунтом VK (если попросят — создайте профиль бизнеса, это бесплатно).'),
                el('li', {}, 'Раздел «Мои приложения» → «Добавить приложение». Платформа — ', el('b', {}, 'Web'), '.'),
                el('li', {}, 'Поле «Базовый домен»: ', copyBox(vk.base_domain)),
                el('li', {}, 'Поле «Доверенный Redirect URL»: ', copyBox(vk.redirect_uri), el('small', { class: 'muted' }, 'Ровно так — с https и путём, без лишних символов.')),
                el('li', {}, 'Создайте приложение. В карточке: «Приложение» → «Информация о приложении» → ', el('b', {}, 'ID приложения'), ' (это число).'),
                el('li', {}, 'На сервере выполните (подставьте свой ID):', copyBox('cd ~/huek/avito-clone && VK_CLIENT_ID=ВАШ_ID bash bin/server-update.sh', { block: true }),
                    el('small', { class: 'muted' }, 'Защищённый и сервисный ключи VK сайту не нужны — никому их не показывайте.')));

            const vkCard = el('div', { class: 'panel-card' },
                el('div', { class: 'panel-card__head' }, el('h2', {}, 'Вход через VK'), yesNo(vk.enabled, 'Включён', 'Не настроен')),
                vk.enabled
                    ? el('dl', { class: 'kv' },
                        el('dt', {}, 'ID приложения'), el('dd', {}, el('code', {}, vk.client_id)),
                        el('dt', {}, 'Redirect URL'), el('dd', {}, copyBox(vk.redirect_uri)),
                        el('dt', {}, 'Базовый домен'), el('dd', {}, copyBox(vk.base_domain)),
                        el('dt', {}, 'Права'), el('dd', {}, el('code', {}, vk.scope)),
                        el('dt', {}, 'Вошли через VK'), el('dd', {}, `${vk.users} ${plural(vk.users, 'пользователь', 'пользователя', 'пользователей')}`))
                    : el('p', { class: 'muted' }, 'Пока ID приложения не задан, кнопки «Войти через VK» на сайте скрыты. Как подключить:'),
                !vk.https ? el('p', { class: 'note note--warn' }, 'Сайт открыт не по https — VK принимает боевые Redirect URL только с https (для проверки на компьютере используйте домен localhost).') : null,
                vk.enabled ? el('details', { class: 'fold' }, el('summary', {}, 'Где лежат настройки приложения VK'), vkSteps) : vkSteps,
                el('p', { class: 'muted' }, 'Подробная инструкция с разбором ошибок: файл docs/VK_LOGIN.md в репозитории.'));

            const wsCard = el('div', { class: 'panel-card' },
                el('div', { class: 'panel-card__head' }, el('h2', {}, 'Чат в реальном времени (WebSocket)'), yesNo(d.ws.ok, 'Работает', 'Не отвечает')),
                el('dl', { class: 'kv' },
                    el('dt', {}, 'Адрес для браузера'), el('dd', {}, d.ws.public_url)),
                d.ws.ok ? null : el('p', { class: 'note note--warn' }, 'WebSocket-сервер не отвечает. Чат продолжит работать (сообщения подтянутся опросом раз в несколько секунд), но без мгновенной доставки. Проверьте контейнер ws: docker compose logs --tail=50 ws'));

            const sys = d.app;
            const sysCard = el('div', { class: 'panel-card' },
                el('div', { class: 'panel-card__head' }, el('h2', {}, 'Окружение')),
                el('dl', { class: 'kv' },
                    el('dt', {}, 'Адрес сайта'), el('dd', {}, sys.url),
                    el('dt', {}, 'PHP'), el('dd', {}, sys.php),
                    el('dt', {}, 'PostgreSQL'), el('dd', {}, sys.db),
                    el('dt', {}, 'APP_SECRET'), el('dd', {}, sys.secret_set ? pill('active', 'задан') : pill('pending', 'не задан — подпись выводится из пароля БД')),
                    el('dt', {}, 'Демо-режим'), el('dd', {}, sys.demo ? pill('pending', 'включён — на боевом сервере выключите (APP_DEMO=0)') : pill('active', 'выключен')),
                    el('dt', {}, 'Отладка'), el('dd', {}, sys.debug ? pill('pending', 'включена — выключите (APP_DEBUG=0)') : pill('active', 'выключена'))));

            content.replaceChildren(header('Интеграции', 'Вход через VK, чат и состояние сервера'), el('div', { class: 'stack' }, vkCard, wsCard, sysCard));
        }

        /* --------------------------------------------------------------------- роутер */

        const routes = { stats: viewStats, users: viewUsers, ads: viewAds, categories: viewCategories, reviews: viewReviews, audit: viewAudit, integrations: viewIntegrations };
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

    window.Board.pages.admin = initAdmin;
})();
