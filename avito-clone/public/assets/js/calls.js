/* ==========================================================================
   Звонки: голос и видео между покупателем и продавцом (WebRTC).

   Как это устроено:
     • «Знакомит» два браузера WebSocket-сервер сайта (src/CallManager.php): передаёт вызов, ответ, отбой и служебные
       данные WebRTC (offer / answer / ICE-кандидаты). Сам разговор идёт напрямую между браузерами;
       если прямое соединение невозможно (мобильные сети, строгий NAT) — через TURN-сервер (coturn).
     • Звонит всегда тот, кто нажал кнопку; он же создаёт offer. Микрофон и камера включаются/выключаются без
       пересогласования: в звонке сразу есть и аудио-, и видео-«слот», а дорожка подставляется через replaceTrack.
     • «Пинг» в углу окна — время отклика до собеседника (RTT): по DataChannel «туда-обратно» и по статистике
       WebRTC. Клик по значку открывает подробности: тип соединения, потери, джиттер, разрешение, скорость.
     • Против лагов: звук имеет приоритет над видео, у видео ограничен битрейт, при потерях или большой задержке
       качество картинки автоматически снижается, а потом возвращается; при обрыве сети выполняется ICE restart.

   Работает на любой странице: входящий вызов появляется поверх сайта. Закрытие страницы завершает звонок.
   ========================================================================== */
(() => {
    'use strict';

    const B = window.Board;
    if (!B || !B.APP || !B.APP.user) return;
    const { $, el, icon, apiGet, toast, realtime } = B;

    const START_TIMEOUT = 8000;       // сервер должен ответить на «позвонить» за 8 секунд
    const CONNECT_TIMEOUT = 25000;    // после ответа соединение должно установиться за 25 секунд
    const GIVE_UP_AFTER = 4;          // столько раз пробуем ICE restart, потом сдаёмся
    const END_SCREEN_MS = 1800;
    const AUDIO_CONSTRAINTS = { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 };

    const isMobile = () => window.matchMedia('(pointer: coarse)').matches || window.innerWidth < 700;
    const videoConstraints = () => (isMobile()
        ? { width: { ideal: 640 }, height: { ideal: 480 }, frameRate: { ideal: 24, max: 30 }, facingMode: 'user' }
        : { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30, max: 30 } });
    const videoBitrate = () => (isMobile() ? 900_000 : 1_500_000);
    const LOW_BITRATE = 350_000;

    const pad = (n) => String(n).padStart(2, '0');
    const clock = (sec) => (sec >= 3600 ? `${Math.floor(sec / 3600)}:` : '') + `${pad(Math.floor(sec / 60) % 60)}:${pad(sec % 60)}`;

    let S = null;          // идущий звонок
    let cancelledStart = 0; // нажали «отмена», пока сервер ещё не прислал id вызова
    let ui = null;         // окно звонка (может пережить звонок на время экрана «завершён»)
    let iceCache = null;

    const sendWs = (obj) => realtime.send({ t: 'call', ...obj });

    /* ------------------------------------------------------------------ звуки */

    const tones = (() => {
        let ctx = null;
        let timer = null;
        let vib = null;
        const ensure = () => {
            try {
                ctx ||= new (window.AudioContext || window.webkitAudioContext)();
                if (ctx.state === 'suspended') ctx.resume();
            } catch { ctx = null; }
            return ctx;
        };
        function beep(freq, at, dur, vol = 0.07) {
            const c = ensure();
            if (!c) return;
            const o = c.createOscillator();
            const g = c.createGain();
            const t0 = c.currentTime + at;
            o.type = 'sine';
            o.frequency.value = freq;
            g.gain.setValueAtTime(0, t0);
            g.gain.linearRampToValueAtTime(vol, t0 + 0.03);
            g.gain.setValueAtTime(vol, t0 + Math.max(0.04, dur - 0.05));
            g.gain.linearRampToValueAtTime(0, t0 + dur);
            o.connect(g).connect(c.destination);
            o.start(t0);
            o.stop(t0 + dur + 0.02);
        }
        // Браузер разрешает вибрацию только после касания страницы пользователем — иначе пишет предупреждение в консоль
        const canVibrate = () => Boolean(navigator.vibrate) && (!navigator.userActivation || navigator.userActivation.hasBeenActive);
        function stop() {
            clearInterval(timer);
            clearInterval(vib);
            timer = vib = null;
            if (canVibrate()) navigator.vibrate(0);
        }
        function start(kind) {
            stop();
            const play = kind === 'in'
                ? () => { beep(659, 0, 0.32); beep(784, 0.38, 0.32); beep(659, 0.9, 0.32); beep(784, 1.28, 0.32); }
                : () => beep(425, 0, 1.0, 0.05);
            play();
            timer = setInterval(play, kind === 'in' ? 3000 : 4000);
            if (kind === 'in' && canVibrate()) {
                const v = () => navigator.vibrate([350, 200, 350]);
                v();
                vib = setInterval(v, 3000);
            }
        }
        const hangup = () => { stop(); beep(480, 0, 0.14); beep(380, 0.17, 0.24); };
        return { start, stop, hangup };
    })();

    /* ------------------------------------------------------------- устройства */

    const mediaErrorText = (errors) => {
        const name = errors[0] && errors[0][1] && errors[0][1].name;
        if (name === 'NotAllowedError' || name === 'SecurityError') return 'Нет доступа к микрофону. Разрешите его в настройках браузера (значок замка рядом с адресом сайта).';
        if (name === 'NotFoundError' || name === 'OverconstrainedError') return 'Микрофон не найден. Подключите его и попробуйте снова.';
        if (name === 'NotReadableError') return 'Микрофон занят другим приложением.';
        return 'Не удалось включить микрофон.';
    };

    /** Захватывает микрофон (и камеру, если нужна). Без камеры звонок всё равно состоится; без микрофона — только слушаем. */
    async function getMedia(wantVideo) {
        const m = { audio: null, video: null, errors: [] };
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            m.errors.push(['audio', { name: 'SecurityError' }]);
            return m;
        }
        const gum = (c) => navigator.mediaDevices.getUserMedia(c);
        if (wantVideo) {
            try {
                const s = await gum({ audio: AUDIO_CONSTRAINTS, video: videoConstraints() });
                m.audio = s.getAudioTracks()[0] || null;
                m.video = s.getVideoTracks()[0] || null;
            } catch { /* пробуем по отдельности ниже */ }
        }
        if (!m.audio) {
            try { m.audio = (await gum({ audio: AUDIO_CONSTRAINTS })).getAudioTracks()[0]; } catch (e) { m.errors.push(['audio', e]); }
        }
        if (wantVideo && !m.video) {
            try { m.video = (await gum({ video: videoConstraints() })).getVideoTracks()[0]; } catch (e) { m.errors.push(['video', e]); }
        }
        if (m.audio) m.audio.contentHint = 'speech';
        if (m.video) m.video.contentHint = 'motion';
        return m;
    }

    const stopMedia = (m) => { if (m) [m.audio, m.video].forEach((t) => { try { t && t.stop(); } catch { /* уже остановлена */ } }); };

    /* ------------------------------------------------------------ состояние */

    function newCall(init) {
        return {
            id: null, conv: 0, ad: null, peer: { name: '…', initial: '?', avatar: null }, dir: 'out', video: false,
            state: 'starting',     // starting → ringing | incoming → connecting → active → ended
            media: { audio: null, video: null, errors: [] },
            mic: true, cam: false, peerMic: true, peerCam: false, peerOnline: true, reconnecting: false,
            pc: null, dc: null, senders: null, remote: new MediaStream(), pending: [], chain: Promise.resolve(),
            restarts: 0, wasActive: false, startedAt: 0,
            stats: { rtt: null, dcRtt: null, loss: 0, jitter: null, type: '', inV: '', bitIn: 0, bitOut: 0, quality: 0 },
            lowMode: false, goodStreak: 0, badStreak: 0, timers: {},
            ...init,
        };
    }

    async function getIce() {
        if (iceCache && Date.now() - iceCache.at < 30 * 60000) return iceCache.cfg;
        const cfg = await apiGet('chat', { action: 'ice' });
        iceCache = { cfg, at: Date.now() };
        return cfg;
    }

    /* ---------------------------------------------------------------- старт */

    /** Начать звонок: из чата (conv) или со страницы объявления (ad). */
    async function start({ conv = 0, ad = 0, video = false, peer = null } = {}) {
        if (S) { toast('Вы уже разговариваете', 'warning'); return; }
        if (!realtime.isOpen) { toast('Нет соединения с сервером. Подождите секунду и попробуйте снова.', 'warning', 4000); return; }
        getIce().catch(() => {});
        const media = await getMedia(video);
        if (!media.audio) { stopMedia(media); toast(mediaErrorText(media.errors), 'error', 7000); return; }
        if (S) { stopMedia(media); return; }
        if (video && !media.video) toast('Камера недоступна — звонок будет голосовым.', 'warning', 4000);
        const init = { dir: 'out', conv, ad: ad ? { id: ad } : null, video: video && !!media.video, media, cam: !!media.video };
        if (peer) init.peer = peer;
        S = newCall(init);
        render();
        sendWs({ k: 'start', c: conv || 0, ad: ad || 0, v: S.video });
        S.timers.start = setTimeout(() => { if (S && S.state === 'starting') end('Сервер не отвечает. Попробуйте ещё раз.'); }, START_TIMEOUT);
    }

    function onRinging(m) {
        const c = S;
        if (!c && Date.now() - cancelledStart < 10000) {        // успели нажать «отмена» до ответа сервера — отменяем созданный вызов
            cancelledStart = 0;
            sendWs({ k: 'end', id: m.id });
            return;
        }
        if (!c || c.state !== 'starting') return;
        clearTimeout(c.timers.start);
        Object.assign(c, { id: m.id, conv: m.conv, peer: m.peer, ad: m.ad, state: 'ringing' });
        tones.start('out');
        render();
    }

    function onDenied(m) {
        if (!S || S.state !== 'starting') return;
        const text = m.message || 'Звонок невозможен';
        end('');
        toast(text, 'warning', 6000);
    }

    function onIncoming(m) {
        if (S) {
            if (S.id !== m.id) sendWs({ k: 'decline', id: m.id });
            return;
        }
        getIce().catch(() => {});
        S = newCall({ dir: 'in', id: m.id, conv: m.conv, ad: m.ad, video: !!m.v, peer: m.from, state: 'incoming', cam: false, peerCam: !!m.v });
        S.ringLeft = m.left;
        tones.start('in');
        blinkTitle(`Звонок: ${m.from.name}`);
        render();
    }

    async function accept(withVideo) {
        const c = S;
        if (!c || c.state !== 'incoming') return;
        c.state = 'connecting';
        tones.stop();
        unblinkTitle();
        render();
        const media = await getMedia(withVideo && c.video);
        if (S !== c) { stopMedia(media); return; }
        c.media = media;
        c.mic = !!media.audio;
        c.cam = !!media.video;
        if (!media.audio) toast('Микрофон недоступен — собеседник вас не услышит.', 'warning', 6000);
        sendWs({ k: 'accept', id: c.id });
        c.timers.connect = setTimeout(() => giveUp(c), CONNECT_TIMEOUT);
        render();
    }

    function decline() {
        const c = S;
        if (!c) return;
        if (c.state === 'incoming') sendWs({ k: 'decline', id: c.id });
        end('');
    }

    /** Нажали «положить трубку» (или «отменить» во время вызова). */
    function hangup() {
        const c = S;
        if (!c) return;
        if (c.id) sendWs({ k: 'end', id: c.id });
        else cancelledStart = Date.now();
        tones.hangup();
        end(c.state === 'active' ? `Звонок завершён · ${clock(secondsActive(c))}` : '');
    }

    const secondsActive = (c) => (c.startedAt ? Math.round((Date.now() - c.startedAt) / 1000) : 0);

    /* --------------------------------------------------------------- WebRTC */

    async function setupPc(c) {
        const cfg = await getIce();
        const policy = localStorage.getItem('callRelay') === '1' ? 'relay' : cfg.policy;     // callRelay=1 в localStorage — проверить TURN
        const pc = new RTCPeerConnection({ iceServers: cfg.iceServers, iceTransportPolicy: policy, bundlePolicy: 'max-bundle', rtcpMuxPolicy: 'require' });
        c.pc = pc;
        pc.ontrack = (e) => {
            if (!c.remote.getTracks().includes(e.track)) c.remote.addTrack(e.track);
            render();
            if (ui && ui.remote.srcObject !== c.remote) ui.remote.srcObject = c.remote;
            ui && ui.remote.play().catch(() => {});
        };
        pc.onicecandidate = (e) => { if (e.candidate) sendSig(c, { cand: e.candidate.toJSON() }); };
        pc.oniceconnectionstatechange = () => onIceState(c);
        const dc = pc.createDataChannel('ping', { negotiated: true, id: 1, ordered: false, maxRetransmits: 0 });
        c.dc = dc;
        dc.onopen = () => { sendMedia(c); };
        dc.onmessage = (e) => {
            let d;
            try { d = JSON.parse(e.data); } catch { return; }
            if (d.p !== undefined && dc.readyState === 'open') dc.send(JSON.stringify({ r: d.p }));
            else if (d.r !== undefined) {
                const rtt = Math.max(0, performance.now() - d.r);
                c.stats.dcRtt = c.stats.dcRtt === null ? rtt : c.stats.dcRtt * 0.6 + rtt * 0.4;
            }
        };
        return pc;
    }

    const sendSig = (c, d) => { if (c.id) sendWs({ k: 'sig', id: c.id, d }); };
    const sendMedia = (c) => { if (c.id) sendWs({ k: 'media', id: c.id, mic: c.mic && !!c.media.audio, cam: c.cam && !!c.media.video }); };

    /** Звонящий: создаёт предложение (первое и при ICE restart). */
    async function makeOffer(c, iceRestart) {
        const pc = c.pc;
        if (!c.senders) {
            const a = pc.addTransceiver(c.media.audio || 'audio', { direction: 'sendrecv' });
            const v = pc.addTransceiver(c.media.video || 'video', { direction: 'sendrecv' });
            c.senders = { audio: a.sender, video: v.sender };
        }
        const offer = await pc.createOffer({ iceRestart });
        await pc.setLocalDescription(offer);
        sendSig(c, { type: 'offer', sdp: pc.localDescription.sdp });
    }

    async function onAccepted() {
        const c = S;
        if (!c || c.dir !== 'out') return;
        tones.stop();
        c.state = 'connecting';
        render();
        clearTimeout(c.timers.connect);
        c.timers.connect = setTimeout(() => giveUp(c), CONNECT_TIMEOUT);
        try {
            await setupPc(c);
            if (S === c) await makeOffer(c, false);
        } catch (e) {
            console.error(e);
            giveUp(c);
        }
    }

    /** Служебные сообщения WebRTC обрабатываем строго по очереди. */
    function onSig(m) {
        const c = S;
        if (!c || c.id !== m.id) return;
        c.chain = c.chain.then(() => handleSig(c, m.d)).catch((e) => console.error('sig', e));
    }

    async function handleSig(c, d) {
        if (S !== c) return;
        if (d.type === 'offer') {
            if (!c.pc) await setupPc(c);
            const pc = c.pc;
            await pc.setRemoteDescription({ type: 'offer', sdp: d.sdp });
            if (!c.senders) {
                c.senders = {};
                for (const tr of pc.getTransceivers()) {
                    const kind = tr.receiver.track.kind;
                    tr.direction = 'sendrecv';
                    c.senders[kind] = tr.sender;
                    if (c.media[kind]) await tr.sender.replaceTrack(c.media[kind]);
                }
            }
            await flushCandidates(c);
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            sendSig(c, { type: 'answer', sdp: pc.localDescription.sdp });
        } else if (d.type === 'answer') {
            if (c.pc) { await c.pc.setRemoteDescription({ type: 'answer', sdp: d.sdp }); await flushCandidates(c); }
        } else if (d.cand) {
            if (c.pc && c.pc.remoteDescription) c.pc.addIceCandidate(d.cand).catch(() => {});
            else c.pending.push(d.cand);
        }
    }

    async function flushCandidates(c) {
        const list = c.pending.splice(0);
        for (const cand of list) { try { await c.pc.addIceCandidate(cand); } catch { /* устаревший кандидат */ } }
    }

    function onIceState(c) {
        if (S !== c || !c.pc) return;
        const st = c.pc.iceConnectionState;
        if (st === 'connected' || st === 'completed') {
            clearTimeout(c.timers.drop);
            clearTimeout(c.timers.connect);
            if (c.state === 'connecting') activate(c);
            else if (c.reconnecting) { c.reconnecting = false; c.restarts = 0; render(); }
        } else if (st === 'disconnected') {
            clearTimeout(c.timers.drop);
            c.timers.drop = setTimeout(() => { if (S === c) { c.reconnecting = true; render(); restartIce(c); } }, 2500);
        } else if (st === 'failed') {
            c.reconnecting = true;
            render();
            restartIce(c);
        }
    }

    /** При обрыве сети заново договариваемся о маршруте (инициирует звонивший, второй просто отвечает на offer). */
    function restartIce(c) {
        if (S !== c || !c.pc) return;
        if (c.dir === 'out') {
            if (++c.restarts > GIVE_UP_AFTER) { giveUp(c); return; }
            c.chain = c.chain.then(() => makeOffer(c, true)).catch((e) => console.error('restart', e));
            clearTimeout(c.timers.drop);
            c.timers.drop = setTimeout(() => { if (S === c && c.reconnecting) restartIce(c); }, 6000);
        } else {
            clearTimeout(c.timers.drop);
            c.timers.drop = setTimeout(() => { if (S === c && c.reconnecting) giveUp(c); }, 30000);
        }
    }

    function giveUp(c) {
        if (S !== c) return;
        sendWs({ k: 'failed', id: c.id, ok: c.wasActive ? 1 : 0 });
        tones.hangup();
        end(c.wasActive ? 'Связь потеряна' : 'Не удалось соединиться. Проверьте интернет и попробуйте ещё раз.');
    }

    function activate(c) {
        c.state = 'active';
        c.wasActive = true;
        c.reconnecting = false;
        c.restarts = 0;
        c.startedAt = Date.now();
        c.timers.clock = setInterval(() => render(true), 1000);
        c.timers.stats = setInterval(() => pollStats(c), 1000);
        c.timers.ping = setInterval(() => { if (c.dc && c.dc.readyState === 'open') c.dc.send(JSON.stringify({ p: performance.now() })); }, 1500);
        tuneSenders(c, false);
        sendMedia(c);
        try { if (navigator.wakeLock) navigator.wakeLock.request('screen').then((l) => { c.wakeLock = l; }).catch(() => {}); } catch { /* не поддерживается */ }
        render();
    }

    /** Звук важнее картинки; у видео — потолок битрейта, при плохой сети он снижается. */
    async function tuneSenders(c, low) {
        for (const kind of ['audio', 'video']) {
            const sender = c.senders && c.senders[kind];
            if (!sender) continue;
            try {
                const p = sender.getParameters();
                if (!p.encodings || !p.encodings.length) p.encodings = [{}];
                const e = p.encodings[0];
                if (kind === 'audio') {
                    e.maxBitrate = 40_000;
                    e.priority = 'high';
                    e.networkPriority = 'high';
                } else {
                    e.maxBitrate = low ? LOW_BITRATE : videoBitrate();
                    e.maxFramerate = low ? 20 : 30;
                    e.scaleResolutionDownBy = low ? 2 : 1;
                    e.priority = 'low';
                    e.networkPriority = 'medium';
                    p.degradationPreference = 'maintain-framerate';
                }
                await sender.setParameters(p);
            } catch { /* часть параметров поддерживается не везде */ }
        }
    }

    /** Раз в секунду собираем статистику: пинг, потери, джиттер, тип соединения, разрешение, скорость. */
    async function pollStats(c) {
        if (S !== c || !c.pc) return;
        let report;
        try { report = await c.pc.getStats(); } catch { return; }
        const st = c.stats;
        const byId = new Map();
        report.forEach((r) => byId.set(r.id, r));
        let pair = null;
        report.forEach((r) => { if (r.type === 'transport' && r.selectedCandidatePairId) pair = byId.get(r.selectedCandidatePairId); });
        if (!pair) report.forEach((r) => { if (r.type === 'candidate-pair' && r.state === 'succeeded' && (r.nominated || !pair)) pair = r; });
        if (pair) {
            if (typeof pair.currentRoundTripTime === 'number') st.rtt = pair.currentRoundTripTime * 1000;
            const lc = byId.get(pair.localCandidateId);
            const rc = byId.get(pair.remoteCandidateId);
            st.type = (lc && lc.candidateType === 'relay') || (rc && rc.candidateType === 'relay') ? 'relay' : 'direct';
        }
        let recv = 0, lost = 0, jitter = null, bytesIn = 0, bytesOut = 0, inV = '';
        report.forEach((r) => {
            if (r.type === 'inbound-rtp' && !r.isRemote) {
                recv += r.packetsReceived || 0;
                lost += r.packetsLost || 0;
                bytesIn += r.bytesReceived || 0;
                if (typeof r.jitter === 'number') jitter = Math.max(jitter || 0, r.jitter * 1000);
                if (r.kind === 'video' && r.frameWidth) inV = `${r.frameWidth}×${r.frameHeight}` + (r.framesPerSecond ? ` · ${Math.round(r.framesPerSecond)} к/с` : '');
            } else if (r.type === 'outbound-rtp' && !r.isRemote) {
                bytesOut += r.bytesSent || 0;
            }
        });
        const prev = c.prevStats || { recv, lost, bytesIn, bytesOut, at: performance.now() };
        const dt = Math.max(0.5, (performance.now() - prev.at) / 1000);
        const dRecv = Math.max(0, recv - prev.recv);
        const dLost = Math.max(0, lost - prev.lost);
        st.loss = dRecv + dLost > 0 ? (dLost / (dRecv + dLost)) * 100 : 0;
        st.bitIn = Math.max(0, ((bytesIn - prev.bytesIn) * 8) / dt);
        st.bitOut = Math.max(0, ((bytesOut - prev.bytesOut) * 8) / dt);
        st.jitter = jitter;
        st.inV = inV;
        c.prevStats = { recv, lost, bytesIn, bytesOut, at: performance.now() };

        const ping = c.stats.dcRtt !== null ? c.stats.dcRtt : st.rtt;
        st.ping = ping;
        st.quality = ping === null ? 0 : (ping < 100 && st.loss < 2 ? 4 : ping < 200 && st.loss < 4 ? 3 : ping < 350 && st.loss < 8 ? 2 : 1);

        // Адаптация картинки: три плохие секунды подряд — снижаем качество видео, восемь хороших — возвращаем
        if (st.quality === 1 || st.quality === 2 && st.loss >= 6) { c.badStreak++; c.goodStreak = 0; } else { c.goodStreak++; c.badStreak = 0; }
        if (!c.lowMode && c.badStreak >= 3) { c.lowMode = true; tuneSenders(c, true); }
        else if (c.lowMode && c.goodStreak >= 8) { c.lowMode = false; tuneSenders(c, false); }
        render(true);
    }

    /* --------------------------------------------------- микрофон и камера */

    function toggleMic() {
        const c = S;
        if (!c || !c.media.audio) return;
        c.mic = !c.mic;
        c.media.audio.enabled = c.mic;
        sendMedia(c);
        render();
    }

    async function toggleCam() {
        const c = S;
        if (!c || !c.senders || !c.senders.video || c.busyCam) return;
        c.busyCam = true;
        try {
            if (c.cam && c.media.video) {
                c.media.video.stop();                         // камера выключается совсем — гаснет индикатор
                c.media.video = null;
                await c.senders.video.replaceTrack(null);
                c.cam = false;
            } else {
                const track = (await navigator.mediaDevices.getUserMedia({ video: videoConstraints() })).getVideoTracks()[0];
                track.contentHint = 'motion';
                await c.senders.video.replaceTrack(track);
                c.media.video = track;
                c.cam = true;
                tuneSenders(c, c.lowMode);
            }
            sendMedia(c);
        } catch (e) {
            toast(e && e.name === 'NotAllowedError' ? 'Нет доступа к камере. Разрешите её в настройках браузера.' : 'Не удалось включить камеру.', 'error', 5000);
        } finally {
            c.busyCam = false;
            render();
        }
    }

    /** Следующая камера (на телефоне — фронтальная/основная). */
    async function flipCam() {
        const c = S;
        if (!c || !c.cam || !c.media.video || c.busyCam) return;
        c.busyCam = true;
        try {
            const cams = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
            if (cams.length < 2) return;
            const curId = c.media.video.getSettings().deviceId;
            const next = cams[(Math.max(0, cams.findIndex((d) => d.deviceId === curId)) + 1) % cams.length];
            c.media.video.stop();
            const track = (await navigator.mediaDevices.getUserMedia({ video: { ...videoConstraints(), deviceId: { exact: next.deviceId } } })).getVideoTracks()[0];
            track.contentHint = 'motion';
            await c.senders.video.replaceTrack(track);
            c.media.video = track;
        } catch { toast('Не удалось переключить камеру.', 'error', 4000); } finally { c.busyCam = false; render(); }
    }

    /* ------------------------------------------------------------ завершение */

    /** Останавливает всё (медиа, соединение, таймеры) и показывает итог на экране; sendWs уже выполнен вызывающим. */
    function end(message) {
        const c = S;
        if (!c) return;
        S = null;
        tones.stop();
        Object.values(c.timers).forEach((t) => { clearTimeout(t); clearInterval(t); });
        try { c.dc && c.dc.close(); } catch { /* уже закрыт */ }
        try { c.pc && c.pc.close(); } catch { /* уже закрыт */ }
        stopMedia(c.media);
        try { c.wakeLock && c.wakeLock.release(); } catch { /* уже освобождён */ }
        unblinkTitle();
        c.state = 'ended';
        c.endMessage = message;
        if (message && ui) {
            render();
            const done = ui;
            setTimeout(() => { if (ui === done) removeUi(); }, END_SCREEN_MS);
        } else removeUi();
    }

    const endText = (m, c) => {
        const dur = m.dur ? ` · ${clock(m.dur)}` : '';
        switch (m.reason) {
            case 'declined': return c.dir === 'out' ? 'Звонок отклонён' : '';
            case 'timeout': return c.dir === 'out' ? 'Нет ответа' : '';
            case 'cancelled': case 'gone': return '';
            case 'lost': return c.wasActive ? 'Связь потеряна' : 'Не удалось соединиться';
            case 'banned': return 'Звонок прерван';
            case 'ice': return c.wasActive ? 'Связь потеряна' : 'Не удалось соединиться';
            default: return c.wasActive ? `Звонок завершён${dur}` : '';
        }
    };

    function onEnded(m) {
        const c = S;
        if (!c || (c.id && c.id !== m.id)) return;
        if (!c.id && c.state === 'starting') return;      // ответ про другой звонок
        if (m.reason !== 'gone') tones.hangup();
        end(endText(m, c));
    }

    /* ------------------------------------------------------------ интерфейс */

    let baseTitle = null;
    let blinkTimer = null;
    function blinkTitle(text) {
        baseTitle ??= document.title;
        let on = false;
        clearInterval(blinkTimer);
        blinkTimer = setInterval(() => { on = !on; document.title = on ? text : baseTitle; }, 900);
    }
    function unblinkTitle() {
        clearInterval(blinkTimer);
        if (baseTitle !== null) { document.title = baseTitle; baseTitle = null; }
    }

    function removeUi() {
        if (ui) { ui.root.remove(); ui = null; }
        document.documentElement.classList.remove('has-call');
    }

    const avatarNode = (peer, cls) => (peer.avatar
        ? el('span', { class: `avatar avatar--img ${cls}` }, el('img', { src: peer.avatar, alt: '', referrerpolicy: 'no-referrer' }))
        : el('span', { class: `avatar ${cls}` }, peer.initial));

    function roundBtn(name, label, onClick, extra = '') {
        const b = el('button', { class: `cbtn ${extra}`.trim(), type: 'button', 'aria-label': label, title: label, onClick });
        b.append(icon(name), el('span', { class: 'cbtn__label' }, label));
        return b;
    }

    function buildUi() {
        removeUi();
        const remote = el('video', { class: 'call__remote', autoplay: true, playsinline: true });
        const local = el('video', { class: 'call__local', autoplay: true, playsinline: true });
        local.muted = true;
        remote.muted = false;
        const name = el('strong', { class: 'call__name' });
        const sub = el('span', { class: 'call__sub' });
        const bars = el('span', { class: 'ping__bars' }, ...[1, 2, 3, 4].map((i) => el('i', { class: `b${i}` })));
        const pingText = el('span', { class: 'ping__text' }, '— мс');
        const ping = el('button', { class: 'call__ping', type: 'button', 'aria-label': 'Качество связи', title: 'Качество связи', onClick: () => { ui.details.hidden = !ui.details.hidden; render(true); } }, bars, pingText);
        const details = el('div', { class: 'call__details', hidden: true });
        const mini = el('span', { class: 'call__mini' });
        const btnMin = el('button', { class: 'call__tool call__tool--min', type: 'button', 'aria-label': 'Свернуть', title: 'Свернуть', onClick: () => {
            const min = ui.root.dataset.min !== '1';
            ui.root.dataset.min = min ? '1' : '0';
            iconUse(btnMin, min ? 'maximize' : 'minimize');
            btnMin.title = btnMin.ariaLabel = min ? 'Развернуть' : 'Свернуть';
        } }, icon('minimize'));
        const btnFull = document.fullscreenEnabled
            ? el('button', { class: 'call__tool call__tool--full', type: 'button', 'aria-label': 'На весь экран', title: 'На весь экран', onClick: () => { if (document.fullscreenElement) document.exitFullscreen(); else ui.root.requestFullscreen().catch(() => {}); } }, icon('maximize'))
            : null;
        const avatarBox = el('div', { class: 'call__who' });
        const status = el('div', { class: 'call__status', role: 'status', 'aria-live': 'polite' });
        const note = el('div', { class: 'call__note', hidden: true });
        const mic = roundBtn('mic', 'Микрофон', toggleMic, 'cbtn--mic');
        const cam = roundBtn('video', 'Камера', toggleCam, 'cbtn--cam');
        const flip = roundBtn('refresh', 'Сменить камеру', flipCam, 'cbtn--flip');
        const endBtn = roundBtn('phone', 'Завершить', hangup, 'cbtn--end');
        const cancelBtn = roundBtn('phone', 'Отменить', hangup, 'cbtn--end cbtn--cancel');
        const declineBtn = roundBtn('phone', 'Отклонить', decline, 'cbtn--end cbtn--decline');
        const acceptBtn = roundBtn('phone', 'Ответить', () => accept(true), 'cbtn--accept');
        const acceptAudioBtn = roundBtn('mic', 'Без видео', () => accept(false), 'cbtn--audio');
        const controls = el('div', { class: 'call__controls' }, mic, cam, flip, endBtn, cancelBtn, declineBtn, acceptAudioBtn, acceptBtn);
        const root = el('div', { class: 'call', role: 'dialog', 'aria-label': 'Звонок', 'data-min': '0', 'data-state': 'starting', 'data-mode': 'audio' },
            el('div', { class: 'call__panel' },
                el('header', { class: 'call__top' }, ping, el('div', { class: 'call__title' }, name, sub), mini, btnMin, btnFull),
                details,
                el('div', { class: 'call__stage' }, remote, el('div', { class: 'call__idle' }, avatarBox, status, note), local),
                controls));
        document.body.append(root);
        document.documentElement.classList.add('has-call');
        ui = { root, remote, local, name, sub, mini, ping, bars, pingText, details, avatarBox, status, note, mic, cam, flip, endBtn, cancelBtn, declineBtn, acceptBtn, acceptAudioBtn, avatarPeerId: null };
        return ui;
    }

    function setBtn(btn, { show = true, off = false, label = null, disabled = false } = {}) {
        btn.hidden = !show;
        btn.classList.toggle('is-off', off);
        btn.disabled = disabled;
        if (label) {
            btn.setAttribute('aria-label', label);
            btn.title = label;
            const l = btn.querySelector('.cbtn__label');
            if (l) l.textContent = label;
        }
    }

    const iconUse = (btn, name) => { const use = btn.querySelector('use'); if (use) use.setAttribute('href', `#i-${name}`); };

    /** Перерисовывает окно под текущее состояние. light = true — только таймер и пинг (раз в секунду). */
    function render(light = false) {
        const c = S || (ui && ui.last);
        if (!c) return;
        if (!ui) buildUi();
        if (S) ui.last = c;
        const u = ui;
        const st = c.state;
        const hasVideoIn = c.peerCam && c.remote.getVideoTracks().length > 0;
        const showMedia = ['connecting', 'active'].includes(st);
        u.root.dataset.state = st;
        u.root.dataset.mode = (c.video || c.cam || hasVideoIn) ? 'video' : 'audio';
        u.root.dataset.remote = hasVideoIn && st === 'active' ? '1' : '0';
        u.root.dataset.local = c.cam && showMedia ? '1' : '0';

        // Заголовок и подзаголовок
        u.name.textContent = c.peer.name;
        u.sub.textContent = c.ad && c.ad.title ? c.ad.title : '';

        // Статус
        let text = '';
        if (st === 'starting') text = 'Соединяем…';
        else if (st === 'ringing') text = 'Вызов…';
        else if (st === 'incoming') text = c.video ? 'Входящий видеозвонок' : 'Входящий звонок';
        else if (st === 'connecting') text = 'Соединение…';
        else if (st === 'active') text = !c.peerOnline ? 'Собеседник переподключается…' : c.reconnecting ? 'Переподключение…' : clock(secondsActive(c));
        else if (st === 'ended') text = c.endMessage || '';
        u.status.textContent = text;
        u.mini.textContent = text;
        u.status.classList.toggle('is-timer', st === 'active' && !c.reconnecting && c.peerOnline);
        u.status.classList.toggle('is-warn', c.reconnecting || !c.peerOnline);

        // Пометки о состоянии собеседника
        const notes = [];
        if (st === 'active' && !c.peerMic) notes.push('Собеседник выключил микрофон');
        if (st === 'active' && c.video && !c.peerCam) notes.push('Камера собеседника выключена');
        if (st === 'active' && c.lowMode) notes.push('Слабая связь — качество видео снижено');
        u.note.hidden = notes.length === 0;
        u.note.textContent = notes.join(' · ');

        // Пинг
        const ping = c.stats.ping;
        u.ping.hidden = !['connecting', 'active'].includes(st) || (st === 'connecting' && ping == null);
        u.ping.dataset.q = String(c.stats.quality || 0);
        u.pingText.textContent = ping == null ? '— мс' : `${Math.round(ping)} мс`;
        [...u.bars.children].forEach((b, i) => b.classList.toggle('on', i < (c.stats.quality || 0)));
        if (!u.details.hidden) renderDetails(c);
        if (light) return;

        // Аватар
        if (u.avatarPeerId !== c.peer.id || !u.avatarBox.firstChild) {
            u.avatarBox.replaceChildren(avatarNode(c.peer, 'avatar--call'));
            u.avatarPeerId = c.peer.id;
        }

        // Кнопки
        const live = st === 'connecting' || st === 'active';
        const canCam = !!(c.senders && c.senders.video) && st === 'active' && navigator.mediaDevices && !!navigator.mediaDevices.getUserMedia;
        setBtn(u.mic, { show: live, off: !c.mic || !c.media.audio, disabled: !c.media.audio, label: c.mic && c.media.audio ? 'Выключить микрофон' : 'Включить микрофон' });
        iconUse(u.mic, c.mic && c.media.audio ? 'mic' : 'mic-off');
        setBtn(u.cam, { show: live, off: !c.cam, disabled: !canCam, label: c.cam ? 'Выключить камеру' : 'Включить камеру' });
        iconUse(u.cam, c.cam ? 'video' : 'video-off');
        setBtn(u.flip, { show: live && c.cam });
        setBtn(u.endBtn, { show: live });
        setBtn(u.cancelBtn, { show: st === 'starting' || st === 'ringing' });
        setBtn(u.declineBtn, { show: st === 'incoming' });
        setBtn(u.acceptBtn, { show: st === 'incoming', label: c.video ? 'Ответить с видео' : 'Ответить' });
        setBtn(u.acceptAudioBtn, { show: st === 'incoming' && c.video });
        u.root.querySelector('.call__tool--min')?.toggleAttribute('hidden', st === 'incoming' || st === 'ended');

        // Видео: собственное превью и картинка собеседника
        if (c.cam && c.media.video) {
            if (!u.local.srcObject || u.local.srcObject.getVideoTracks()[0] !== c.media.video) u.local.srcObject = new MediaStream([c.media.video]);
        } else if (u.local.srcObject) u.local.srcObject = null;
        if (u.remote.srcObject !== c.remote && showMedia) u.remote.srcObject = c.remote;
        if (st === 'incoming') u.acceptBtn.focus({ preventScroll: true });
    }

    function renderDetails(c) {
        const s = c.stats;
        const mbit = (v) => (v >= 1e6 ? `${(v / 1e6).toFixed(1)} Мбит/с` : `${Math.round(v / 1000)} кбит/с`);
        const rows = [
            ['Пинг', s.ping == null ? '—' : `${Math.round(s.ping)} мс`],
            ['Потери пакетов', `${s.loss.toFixed(1)} %`],
            ['Джиттер', s.jitter == null ? '—' : `${Math.round(s.jitter)} мс`],
            ['Соединение', s.type === 'relay' ? 'через сервер (TURN)' : s.type === 'direct' ? 'напрямую' : '—'],
            ['Видео собеседника', s.inV || '—'],
            ['Скорость', `↓ ${mbit(s.bitIn)} · ↑ ${mbit(s.bitOut)}`],
        ];
        ui.details.replaceChildren(...rows.map(([k, v]) => el('div', { class: 'call__row' }, el('span', {}, k), el('b', {}, v))));
    }

    /* --------------------------------------------------------- события сервера */

    realtime.on('call', (m) => {
        switch (m.k) {
            case 'ringing': onRinging(m); break;
            case 'denied': onDenied(m); break;
            case 'incoming': onIncoming(m); break;
            case 'accepted': onAccepted(); break;
            case 'sig': onSig(m); break;
            case 'media':
                if (S && S.id === m.id) { S.peerMic = !!m.mic; S.peerCam = !!m.cam; render(); }
                break;
            case 'peer':
                if (S && S.id === m.id) { S.peerOnline = !!m.on; render(); }
                break;
            case 'taken':
                if (S && S.id === m.id && S.state === 'incoming') { end(''); toast('Звонок принят на другом устройстве', 'info', 3000); }
                break;
            case 'resumed':
                break;
            case 'ended': onEnded(m); break;
        }
    });

    // После (пере)подключения WebSocket: возвращаем идущий звонок или спрашиваем, нет ли для нас входящего
    realtime.on('open', () => {
        if (S && S.id && ['ringing', 'connecting', 'active'].includes(S.state)) sendWs({ k: 'resume', id: S.id });
        else if (!S) sendWs({ k: 'sync' });
    });

    // Закрытие страницы завершает звонок; пока идёт разговор — просим подтвердить
    window.addEventListener('beforeunload', (e) => {
        if (S && ['connecting', 'active'].includes(S.state)) { e.preventDefault(); e.returnValue = ''; }
    });
    // Звонящий или участник разговора ушёл со страницы — отбой. Входящий вызов страницей не отклоняется: после обновления он вернётся (sync)
    window.addEventListener('pagehide', () => { if (S && S.id && S.state !== 'incoming') sendWs({ k: 'end', id: S.id }); });

    window.Board.calls = {
        start,
        get active() { return !!S; },
        _call: () => S,                  // для автотестов
    };
})();
