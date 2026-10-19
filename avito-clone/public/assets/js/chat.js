/* ==========================================================================
   Чат: диалоги цепочками, статусы «прочитано / не прочитано», обновление в реальном времени.

   Транспорт:
     • отправка и «прочитано» — обычные POST-запросы (api/chat.php): так работают CSRF и все проверки;
     • входящие события приходят по WebSocket (bin/ws-server.php): message, read, read_self, typing, unread;
     • если WebSocket недоступен — страница автоматически опрашивает сервер каждые 8 секунд.
   ========================================================================== */
(() => {
    'use strict';

    const B = window.Board;
    const { APP, $, $$, el, icon, apiGet, apiPost, handleApiError, toast, realtime, setLoading, ApiError } = B;

    const POLL_MS = 8000;
    const timeFmt = new Intl.DateTimeFormat('ru', { hour: '2-digit', minute: '2-digit' });
    const dayFmt = new Intl.DateTimeFormat('ru', { day: 'numeric', month: 'long' });
    const dayFmtYear = new Intl.DateTimeFormat('ru', { day: 'numeric', month: 'long', year: 'numeric' });

    const avatarEl = (peer, cls = '') => peer.avatar
        ? el('span', { class: `avatar avatar--img ${cls}`.trim() }, el('img', { src: peer.avatar, alt: '', referrerpolicy: 'no-referrer' }))
        : el('span', { class: `avatar ${cls}`.trim() }, peer.initial);

    /** Время для списка диалогов в часовом поясе браузера (так же, как в самой переписке). */
    function shortTime(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        const day = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
        const diff = Math.round((day(new Date()) - day(d)) / 86400000);
        if (diff === 0) return timeFmt.format(d);
        if (diff === 1) return 'Вчера';
        return (d.getFullYear() === new Date().getFullYear() ? new Intl.DateTimeFormat('ru', { day: 'numeric', month: 'short' }) : new Intl.DateTimeFormat('ru', { day: 'numeric', month: 'short', year: 'numeric' })).format(d).replace('.', '');
    }

    function dayLabel(iso) {
        const d = new Date(iso);
        const start = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
        const diff = Math.round((start(new Date()) - start(d)) / 86400000);
        if (diff === 0) return 'Сегодня';
        if (diff === 1) return 'Вчера';
        return (d.getFullYear() === new Date().getFullYear() ? dayFmt : dayFmtYear).format(d);
    }

    function initChat() {
        const me = APP.user.id;
        const root = $('#chat');
        const listBox = $('#chatItems');
        const threadBox = $('#chatThread');

        let convs = [];                 // сводки диалогов
        let active = null;              // открытый диалог (может быть «черновиком» без id)
        let msgs = [];                  // сообщения открытого диалога
        let hasMore = false;
        let typingTimer = null;
        let lastTypingSent = 0;
        let pollTimer = null;
        let tmpSeq = 0;
        let ui = {};                    // ссылки на элементы открытого диалога

        /* ------------------------------------------------------------ список */

        const sortConvs = () => convs.sort((a, b) => (b.updated || '').localeCompare(a.updated || ''));

        function convNode(c) {
            const last = c.last;
            const mine = last && last.mine;
            const preview = last ? (mine ? 'Вы: ' : '') + last.body : 'Нет сообщений';
            return el('div', {
                class: `conv${active && active.id === c.id ? ' is-active' : ''}${c.unread ? ' is-unread' : ''}`, 'data-id': c.id,
                role: 'button', tabindex: '0', onClick: () => openConversation(c.id),
            },
            avatarEl(c.peer),
            el('div', { class: 'conv__main' },
                el('div', { class: 'conv__top' }, el('span', { class: 'conv__name' }, c.peer.name), el('span', { class: 'conv__time' }, last ? shortTime(last.at) : '')),
                el('div', { class: 'conv__ad' }, c.ad.title),
                el('div', { class: 'conv__last' },
                    mine ? el('span', { class: `tick${last.read ? ' is-read' : ''}`, title: last.read ? 'Прочитано' : 'Не прочитано' }, icon(last.read ? 'checks' : 'check')) : null,
                    el('span', {}, preview),
                    c.unread ? el('span', { class: 'conv__badge' }, String(c.unread)) : null)));
        }

        function renderList() {
            sortConvs();
            if (!convs.length) {
                listBox.replaceChildren(el('div', { class: 'chat__none' },
                    el('div', { class: 'empty__icon' }, '📭'),
                    el('p', {}, 'Диалогов пока нет. Откройте объявление и нажмите «Написать сообщение».')));
                return;
            }
            listBox.replaceChildren(...convs.map(convNode));
            $$('.conv', listBox).forEach((n) => n.addEventListener('keydown', (e) => { if (e.key === 'Enter') n.click(); }));
        }

        function upsertConv(summary) {
            if (!summary) return;
            const i = convs.findIndex((c) => c.id === summary.id);
            if (i >= 0) convs[i] = summary; else convs.push(summary);
        }

        async function loadList() {
            const data = await apiGet('chat', { action: 'list' });
            convs = data.conversations;
            realtime.setBadge(data.unread);
            renderList();
        }

        /* ----------------------------------------------------------- диалог */

        const nearBottom = () => ui.body && ui.body.scrollHeight - ui.body.scrollTop - ui.body.clientHeight < 120;
        const toBottom = () => { if (ui.body) ui.body.scrollTop = ui.body.scrollHeight; };

        function bubbleNode(m) {
            const out = m.sender_id === me;
            const time = timeFmt.format(new Date(m.created_at));
            const meta = el('div', { class: 'bubble__meta' }, time);
            if (out) {
                const pending = String(m.id).startsWith('tmp');
                meta.append(el('span', { class: `tick${m.read ? ' is-read' : ''}`, title: pending ? 'Отправляется…' : (m.read ? `Прочитано ${timeFmt.format(new Date(m.read_at))}` : 'Не прочитано') },
                    icon(pending ? 'clock' : (m.read ? 'checks' : 'check'))));
            }
            return el('div', { class: `bubble ${out ? 'bubble--out' : 'bubble--in'}`, 'data-id': m.id }, m.body, meta);
        }

        function renderMessages() {
            if (!ui.body) return;
            const keepBottom = nearBottom();
            const prevHeight = ui.body.scrollHeight;
            const nodes = [];
            if (hasMore) {
                nodes.push(el('button', { class: 'btn btn--outline btn--sm thread__more', type: 'button', onClick: loadOlder }, 'Показать более ранние'));
            }
            if (!msgs.length) {
                nodes.push(el('p', { class: 'draft-note' }, active && active.id === null
                    ? `Напишите первое сообщение — ${active.peer.name} получит его в чате.` : 'Сообщений пока нет'));
            }
            let lastDay = '';
            msgs.forEach((m) => {
                const label = dayLabel(m.created_at);
                if (label !== lastDay) { lastDay = label; nodes.push(el('div', { class: 'day-sep' }, label)); }
                nodes.push(bubbleNode(m));
            });
            // Подпись статуса под последним моим сообщением
            const lastMine = [...msgs].reverse().find((m) => m.sender_id === me);
            if (lastMine && lastMine === msgs[msgs.length - 1] && !String(lastMine.id).startsWith('tmp')) {
                nodes.push(el('div', { class: `read-label${lastMine.read ? ' is-read' : ''}` }, lastMine.read ? 'Прочитано' : 'Не прочитано'));
            }
            ui.body.replaceChildren(...nodes);
            if (keepBottom) toBottom(); else ui.body.scrollTop += ui.body.scrollHeight - prevHeight;
        }

        function buildThread() {
            const c = active;
            const back = el('button', { class: 'icon-btn thread__back', type: 'button', 'aria-label': 'К списку диалогов', onClick: closeThread }, icon('arrow-left'));
            const sub = el('span', { class: 'thread__sub' }, c.role === 'seller' ? 'Покупатель' : 'Продавец');
            const peer = el('a', { class: 'thread__peer', href: c.peer.url }, avatarEl(c.peer), el('div', {}, el('strong', {}, c.peer.name), sub));
            const adLink = el('a', { class: 'thread__ad', href: c.ad.url },
                el('span', { class: 'thread__ad-img' }, c.ad.cover ? el('img', { src: c.ad.cover, alt: '' }) : null),
                el('span', { class: 't' }, el('b', {}, c.ad.title), el('small', {}, c.ad.price_text)));
            const body = el('div', { class: 'thread__body' });
            const banner = el('div', { class: 'chat__banner', hidden: true }, 'Нет соединения в реальном времени — обновляем сообщения автоматически');

            const input = el('textarea', { class: 'composer__input', rows: 1, maxlength: 2000, placeholder: 'Напишите сообщение…', 'aria-label': 'Сообщение' });
            const send = el('button', { class: 'btn btn--primary composer__send', type: 'submit', 'aria-label': 'Отправить' }, icon('send'));
            const composer = el('form', { class: 'composer' }, input, send);

            const autosize = () => { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 140) + 'px'; };
            input.addEventListener('input', () => {
                autosize();
                const now = Date.now();
                if (active && active.id && now - lastTypingSent > 2000 && input.value.trim()) {
                    lastTypingSent = now;
                    realtime.send({ t: 'typing', c: active.id });
                }
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey && window.matchMedia('(pointer: fine)').matches) { e.preventDefault(); composer.requestSubmit(); }
            });
            composer.addEventListener('submit', (e) => { e.preventDefault(); submit(input, send, autosize); });

            threadBox.replaceChildren(el('div', { class: 'thread' }, el('div', { class: 'thread__head' }, back, peer, adLink), banner, body, composer));
            ui = { body, input, sub, banner, send };
            ui.banner.hidden = realtime.isOpen;
            input.focus({ preventScroll: true });
        }

        async function openConversation(id, { adId = 0 } = {}) {
            try {
                const data = await apiGet('chat', adId ? { action: 'thread', ad: adId } : { action: 'thread', c: id });
                active = data.conversation;
                msgs = data.messages;
                hasMore = data.has_more;
            } catch (err) {
                handleApiError(err);
                return;
            }
            root.classList.add('is-thread');
            history.replaceState(null, '', active.id ? `/chat.php?c=${active.id}` : `/chat.php?ad=${active.ad.id}`);
            buildThread();
            renderMessages();
            toBottom();
            renderList();
            markRead();
        }

        function closeThread() {
            active = null;
            msgs = [];
            root.classList.remove('is-thread');
            history.replaceState(null, '', '/chat.php');
            threadBox.replaceChildren($('#chatEmpty') || el('div', { class: 'chat__empty' }, el('h2', {}, 'Выберите диалог')));
            ui = {};
            renderList();
        }

        async function loadOlder() {
            if (!msgs.length || !active.id) return;
            try {
                const data = await apiGet('chat', { action: 'thread', c: active.id, before: msgs[0].id });
                msgs = [...data.messages, ...msgs];
                hasMore = data.has_more;
                renderMessages();
            } catch (err) { handleApiError(err); }
        }

        /** Помечает входящие прочитанными — собеседник увидит «Прочитано» сразу, по WebSocket. */
        async function markRead() {
            if (!active || !active.id || document.hidden) return;
            const c = convs.find((x) => x.id === active.id);
            const hasUnread = msgs.some((m) => m.sender_id !== me && !m.read) || (c && c.unread > 0);
            if (!hasUnread) return;
            try {
                const res = await apiPost('chat', 'read', { conversation_id: active.id });
                msgs.forEach((m) => { if (m.sender_id !== me) m.read = true; });
                if (c) c.unread = 0;
                realtime.setBadge(res.unread);
                renderList();
            } catch { /* не критично — повторим при следующем событии */ }
        }

        async function submit(input, sendBtn, autosize) {
            const body = input.value.trim();
            if (!body || !active) return;
            const tmp = { id: `tmp${++tmpSeq}`, conversation_id: active.id, sender_id: me, body, created_at: new Date().toISOString(), read_at: null, read: false };
            msgs.push(tmp);
            input.value = '';
            autosize();
            renderMessages();
            toBottom();
            setLoading(sendBtn, true);
            try {
                const res = await apiPost('chat', 'send', active.id ? { conversation_id: active.id, body } : { ad_id: active.ad.id, body });
                msgs = msgs.filter((m) => m !== tmp);
                if (!msgs.some((m) => m.id === res.message.id)) msgs.push(res.message);
                active = res.conversation;
                upsertConv(res.conversation);
                history.replaceState(null, '', `/chat.php?c=${active.id}`);
                renderMessages();
                toBottom();
                renderList();
            } catch (err) {
                msgs = msgs.filter((m) => m !== tmp);
                input.value = body;
                renderMessages();
                if (!(err instanceof ApiError && err.errors && err.errors.body)) handleApiError(err);
                else toast(err.errors.body, 'error');
            } finally {
                setLoading(sendBtn, false);
                input.focus({ preventScroll: true });
            }
        }

        /* ------------------------------------------------------------- realtime */

        realtime.on('message', ({ conversation, message }) => {
            upsertConv(conversation);
            if (active && active.id === message.conversation_id) {
                if (!msgs.some((m) => m.id === message.id)) {
                    const stick = nearBottom() || message.sender_id === me;
                    msgs = msgs.filter((m) => !(String(m.id).startsWith('tmp') && m.body === message.body && m.sender_id === message.sender_id));
                    msgs.push(message);
                    renderMessages();
                    if (stick) toBottom();
                }
                if (message.sender_id !== me) {
                    if (ui.sub) { ui.sub.classList.remove('is-typing'); ui.sub.textContent = active.role === 'seller' ? 'Покупатель' : 'Продавец'; }
                    markRead();
                }
            }
            renderList();
        });

        realtime.on('read', ({ c, upto }) => {
            let changed = false;
            if (active && active.id === c) {
                msgs.forEach((m) => { if (m.sender_id === me && m.id <= upto && !m.read) { m.read = true; m.read_at = new Date().toISOString(); changed = true; } });
                if (changed) renderMessages();
            }
            const conv = convs.find((x) => x.id === c);
            if (conv && conv.last && conv.last.mine) { conv.last.read = true; renderList(); }
        });

        realtime.on('read_self', ({ c }) => {
            const conv = convs.find((x) => x.id === c);
            if (conv && conv.unread) { conv.unread = 0; renderList(); }
        });

        realtime.on('typing', ({ c }) => {
            if (!active || active.id !== c || !ui.sub) return;
            ui.sub.textContent = 'печатает…';
            ui.sub.classList.add('is-typing');
            clearTimeout(typingTimer);
            typingTimer = setTimeout(() => {
                if (!ui.sub) return;
                ui.sub.classList.remove('is-typing');
                ui.sub.textContent = active.role === 'seller' ? 'Покупатель' : 'Продавец';
            }, 3000);
        });

        /** Догоняем пропущенное после переподключения и при опросе. */
        async function resync() {
            try {
                await loadList();
                if (active && active.id) {
                    const data = await apiGet('chat', { action: 'thread', c: active.id });
                    const fresh = data.messages;
                    const pending = msgs.filter((m) => String(m.id).startsWith('tmp'));
                    const changed = JSON.stringify(fresh.map((m) => [m.id, m.read])) !== JSON.stringify(msgs.filter((m) => !String(m.id).startsWith('tmp')).slice(-fresh.length).map((m) => [m.id, m.read]));
                    if (changed) {
                        const older = msgs.filter((m) => !String(m.id).startsWith('tmp') && m.id < (fresh[0] ? fresh[0].id : Infinity));
                        msgs = [...older, ...fresh, ...pending];
                        renderMessages();
                    }
                    markRead();
                }
            } catch { /* сеть недоступна — попробуем в следующий раз */ }
        }

        const startPolling = () => { if (!pollTimer) pollTimer = setInterval(resync, POLL_MS); if (ui.banner) ui.banner.hidden = false; };
        const stopPolling = () => { clearInterval(pollTimer); pollTimer = null; if (ui.banner) ui.banner.hidden = true; };
        realtime.on('open', () => { stopPolling(); resync(); });
        realtime.on('close', startPolling);
        if (!realtime.isOpen) setTimeout(() => { if (!realtime.isOpen) startPolling(); }, 4000);

        document.addEventListener('visibilitychange', () => { if (!document.hidden) markRead(); });
        window.addEventListener('focus', markRead);

        /* ---------------------------------------------------------------- старт */

        (async () => {
            listBox.replaceChildren(...[1, 2, 3].map(() => el('div', { class: 'conv' }, el('div', { class: 'sk', style: { width: '40px', height: '40px', borderRadius: '50%' } }), el('div', { class: 'sk', style: { height: '36px' } }))));
            try { await loadList(); } catch (err) { handleApiError(err); return; }
            const wantConv = Number(root.dataset.openConv);
            const wantAd = Number(root.dataset.openAd);
            if (wantConv) await openConversation(wantConv);
            else if (wantAd) await openConversation(0, { adId: wantAd });
        })();
    }

    window.Board.pages.chat = initChat;
})();
