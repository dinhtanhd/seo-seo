import Hls from 'hls.js';

const players = new WeakMap();
const livePlayers = new Set();
const messages = {
    idle: '点击播放，开始加载视频。', loading: '正在加载视频…', ready: '视频已就绪，请点击播放。',
    playing: '正在播放', paused: '已暂停', invalid: '播放地址不可用，请选择其他线路。',
    unsupported: '当前浏览器无法播放此视频，请更换浏览器或打开来源链接。',
    error: '视频暂时无法播放，请重试或切换播放线路。', timeout: '视频加载超时，请重试或切换播放线路。',
};

function publicHost(hostname) {
    const host = hostname.replace(/^\[|\]$/g, '').toLowerCase().replace(/\.$/, '');
    if (host.includes(':')) {
        const pieces = host.split('::');
        if (pieces.length > 2) return false;
        const left = pieces[0] ? pieces[0].split(':') : [];
        const right = pieces.length > 1 && pieces[1] ? pieces[1].split(':') : [];
        const missing = 8 - left.length - right.length;
        if (missing < 0 || (pieces.length === 1 && missing !== 0)) return false;
        const groups = [...left, ...Array(missing).fill('0'), ...right];
        if (groups.length !== 8 || groups.some(part => !/^[0-9a-f]{1,4}$/.test(part))) return false;
        const values = groups.map(part => parseInt(part, 16));
        if (values[0] < 0x2000 || values[0] > 0x3fff || values[0] === 0x2002) return false;
        if (values[0] === 0x2001 && (values[1] === 0xdb8 || values[1] === 0 || values[1] === 2
            || (values[1] >= 0x10 && values[1] <= 0x2f))) return false;
        return !(values[0] === 0x3fff && values[1] < 0x1000);
    }
    if (/^\d+\.\d+\.\d+\.\d+$/.test(host)) {
        const [a, b, c] = host.split('.').map(Number);
        return !(a === 0 || a === 10 || a === 127 || a >= 224 || (a === 100 && b >= 64 && b <= 127)
            || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31)
            || (a === 192 && (b === 168 || (b === 0 && (c === 0 || c === 2)) || (b === 88 && c === 99)))
            || (a === 198 && (b === 18 || b === 19 || (b === 51 && c === 100))) || (a === 203 && b === 0 && c === 113));
    }
    if (!host.includes('.') || host.length > 253 || /(?:^|\.)(?:localhost|localdomain|local|internal|lan|home|test|invalid)$/.test(host)) return false;
    return host.split('.').every(label => /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(label));
}

function validatedMediaUrl(raw, baseUrl, allowedPorts) {
    if (typeof raw !== 'string' || raw.length > 8192 || /[\u0000-\u0020\u007f]/.test(raw)) return null;
    try {
        const url = new URL(raw, baseUrl);
        if (url.protocol !== 'https:' || url.username || url.password || !allowedPorts.includes(url.port) || !publicHost(url.hostname)) return null;
        return url.href;
    } catch { return null; }
}

export function safeMediaUrl(raw, baseUrl = globalThis.location?.href) {
    return validatedMediaUrl(raw, baseUrl, ['', '443']);
}

// Reviewed HLS feeds serve child playlists, encryption keys and segments on HTTPS 65.
export function safeHlsRequestUrl(raw, baseUrl = globalThis.location?.href) {
    return validatedMediaUrl(raw, baseUrl, ['', '443', '65']);
}

function sourceFrom(element, fallback = 'hls') {
    return { url: element?.getAttribute('data-tram-src') ?? element?.getAttribute('data-source') ?? '',
        type: element?.getAttribute('data-tram-type') ?? element?.getAttribute('data-kind') ?? fallback };
}

function preferNativeHls(navigatorInfo) {
    const agent = navigatorInfo?.userAgent ?? '';
    const ios = /iPad|iPhone|iPod/.test(agent) || (navigatorInfo?.platform === 'MacIntel' && navigatorInfo?.maxTouchPoints > 1);
    return ios || (/Safari\//.test(agent) && !/Chrome|Chromium|Edg|OPR|Android/.test(agent));
}

export function createPlayer(root, { HlsClass = Hls, navigatorInfo = globalThis.navigator, timeoutMs = 20000, setTimer = setTimeout, clearTimer = clearTimeout } = {}) {
    if (players.has(root)) return players.get(root);
    const video = root.querySelector('[data-tram-video]');
    if (!video) return null;
    const status = root.querySelector('[data-tram-status]');
    const playButton = root.querySelector('[data-tram-play]');
    const retryButton = root.querySelector('[data-tram-retry]');
    const listeners = [];
    let current = sourceFrom(root), hls = null, started = false, destroyed = false, requestedPlay = false, timer = null, generation = 0;
    video.controls = true;
    video.playsInline = true;
    video.preload = 'none';
    video.autoplay = false;
    video.removeAttribute('autoplay');
    // The trusted component never adopts an existing third-party video URL or ID.
    video.removeAttribute('src');

    function on(target, event, handler) {
        if (!target) return;
        target.addEventListener(event, handler);
        listeners.push(() => target.removeEventListener(event, handler));
    }
    function clearTimeoutState() {
        if (timer !== null) clearTimer(timer);
        timer = null;
    }
    function state(value, text = messages[value], retry = false) {
        if (destroyed) return;
        root.setAttribute('data-tram-player-state', value);
        root.setAttribute('aria-busy', value === 'loading' ? 'true' : 'false');
        if (status) status.textContent = text;
        if (retryButton) retryButton.hidden = !retry;
        if (playButton) playButton.hidden = value === 'playing' || value === 'error' || value === 'unsupported';
    }
    function releaseMedia() {
        started = false;
        generation++;
        clearTimeoutState();
        if (hls) { const previous = hls; hls = null; previous.destroy(); }
        video.pause();
        video.removeAttribute('src');
        video.load();
    }
    function fail(message = messages.error, retry = true) {
        releaseMedia();
        state('error', message, retry);
    }
    function loadingTimeout() {
        clearTimeoutState();
        timer = setTimer(() => { if (started && !destroyed) fail(messages.timeout); }, timeoutMs);
    }
    function requestPlayback(token) {
        if (!requestedPlay || destroyed || token !== generation) return;
        try {
            Promise.resolve(video.play()).catch(() => {
                if (!destroyed && token === generation && root.getAttribute('data-tram-player-state') !== 'error') {
                    state('ready');
                }
            });
        } catch { state('ready'); }
    }
    function start({ userInitiated = false } = {}) {
        if (destroyed || !userInitiated) return;
        requestedPlay = true;
        if (started) { requestPlayback(generation); return; }
        const url = safeMediaUrl(current.url, root.ownerDocument?.baseURI);
        if (!url || !['hls', 'video', 'mp4'].includes(current.type)) { fail(messages.invalid, false); return; }
        releaseMedia();
        started = true;
        const token = generation;
        state('loading');
        loadingTimeout();
        const nativeHls = Boolean(video.canPlayType('application/vnd.apple.mpegurl'));
        const mseHls = Boolean(HlsClass?.isSupported?.());
        if (current.type !== 'hls' || (nativeHls && (preferNativeHls(navigatorInfo) || !mseHls))) {
            video.src = url;
            video.preload = 'metadata';
            video.load();
            requestPlayback(token);
            return;
        }
        if (!mseHls) {
            releaseMedia();
            state('unsupported');
            return;
        }
        try {
            const instance = new HlsClass({ enableWorker: false, maxBufferLength: 30, backBufferLength: 30,
                xhrSetup(xhr, requestUrl) {
                    if (!safeHlsRequestUrl(requestUrl, root.ownerDocument?.baseURI)) throw new Error('Unsupported media endpoint');
                    xhr.withCredentials = false;
                } });
            hls = instance;
            instance.on(HlsClass.Events.MANIFEST_PARSED, () => {
                if (destroyed || hls !== instance || token !== generation) return;
                clearTimeoutState();
                state('ready');
                requestPlayback(token);
            });
            instance.on(HlsClass.Events.ERROR, (_event, error) => {
                if (!destroyed && hls === instance && token === generation && error?.fatal) fail();
            });
            instance.loadSource(url);
            instance.attachMedia(video);
        } catch { fail(); }
    }
    function choose(element) {
        if (destroyed) return;
        const next = sourceFrom(element, current.type);
        if (!safeMediaUrl(next.url, root.ownerDocument?.baseURI)) { fail(messages.invalid, false); return; }
        releaseMedia();
        current = next;
        root.setAttribute('data-tram-src', current.url);
        root.setAttribute('data-tram-type', current.type);
        for (const candidate of root.querySelectorAll('[data-tram-source]')) candidate.setAttribute('aria-pressed', candidate === element ? 'true' : 'false');
        start({ userInitiated: true });
    }
    on(playButton, 'click', () => start({ userInitiated: true }));
    on(retryButton, 'click', () => { releaseMedia(); start({ userInitiated: true }); });
    on(root, 'click', event => {
        const choice = event.target?.closest?.('[data-tram-source]');
        if (choice && root.contains(choice) && choice.closest('[data-tram-player]') === root) {
            if (!choice.getAttribute('data-tram-src') && !choice.getAttribute('data-source')) return;
            event.preventDefault();
            choose(choice);
        }
    });
    on(root, 'change', event => {
        if (event.target?.matches?.('[data-tram-source-select]')) {
            const option = event.target.selectedOptions?.[0];
            if (option) choose(option);
        }
    });
    on(video, 'playing', () => { if (started) { clearTimeoutState(); state('playing'); } });
    on(video, 'canplay', () => { if (started) { clearTimeoutState(); if (video.paused) state('ready'); } });
    on(video, 'waiting', () => { if (started) { state('loading'); loadingTimeout(); } });
    on(video, 'stalled', () => { if (started) { state('loading'); loadingTimeout(); } });
    on(video, 'pause', () => { if (started) { clearTimeoutState(); state('paused'); } });
    on(video, 'ended', () => { if (started) { clearTimeoutState(); state('ready'); } });
    on(video, 'error', () => { if (started) fail(); });
    state('idle');
    const controller = { root, start, destroy() {
        if (destroyed) return;
        releaseMedia();
        destroyed = true;
        requestedPlay = false;
        for (const remove of listeners) remove();
        players.delete(root);
        livePlayers.delete(controller);
        root.setAttribute('data-tram-player-state', 'destroyed');
    } };
    players.set(root, controller);
    livePlayers.add(controller);
    return controller;
}

export function mountPlayers(scope = document) {
    const roots = [...scope.querySelectorAll('[data-tram-player]')];
    if (scope.matches?.('[data-tram-player]')) roots.unshift(scope);
    return roots.map(root => createPlayer(root)).filter(Boolean);
}

if (typeof document !== 'undefined') {
    let observer = null;
    const boot = () => {
        mountPlayers(document);
        if (!observer && typeof MutationObserver !== 'undefined' && document.body) {
            observer = new MutationObserver(() => {
                for (const player of livePlayers) if (!player.root.isConnected) player.destroy();
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
    window.addEventListener('pagehide', () => {
        observer?.disconnect(); observer = null;
        for (const player of [...livePlayers]) player.destroy();
    });
    window.addEventListener('pageshow', event => { if (event.persisted) boot(); });
}
