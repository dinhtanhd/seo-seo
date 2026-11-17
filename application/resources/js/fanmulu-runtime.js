// Shared public behavior; this adapter never requests film data or media.
export function safeScopeUrl(value, base, prefix, trustedPaths = [], allowLegacyIntents = true) {
    if (typeof value !== 'string' || typeof prefix !== 'string' || !prefix.startsWith('/') || prefix.endsWith('/')
        || /[\u0000-\u0020\\]/.test(value) || /%(?:2f|5c|2e)/i.test(value)) return null;
    try {
        const origin = new URL(base);
        const candidate = new URL(value, origin);
        if (!['http:', 'https:'].includes(candidate.protocol) || candidate.origin !== origin.origin || candidate.username || candidate.password
            || candidate.href.length > 4096) return null;
        const paths = trustedPaths instanceof Set ? Array.from(trustedPaths) : trustedPaths;
        const trusted = Array.isArray(paths) && paths.some(path => typeof path === 'string'
            && path.startsWith('/') && !path.startsWith('//') && !/[\u0000-\u0020\\?#]/.test(path) && !/%(?:2f|5c|2e)/i.test(path)
            && new URL(path, origin).pathname === path
            && (candidate.pathname === path || candidate.pathname.startsWith(path + '/_scope/')));
        const escapedPrefix = prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const intent = allowLegacyIntents && new RegExp('^' + escapedPrefix + '/[1-9][0-9]*-fm[1-9][0-9]*-[a-f0-9]{24}-(?:0|[1-9][0-9]{0,2})(?:\\.[a-z][a-z0-9]{0,11})?$').test(candidate.pathname);
        const currentRoot = origin.pathname.split('/_scope/')[0];
        const current = currentRoot.startsWith(prefix + '/')
            && (candidate.pathname === currentRoot || candidate.pathname.startsWith(currentRoot + '/_scope/'));
        if (!intent && !current && !trusted) return null;
        return candidate.href;
    } catch { return null; }
}

export function readEntries(storage, key, base, prefix, limit = 10, trustedPaths = [], allowLegacyIntents = true) {
    try {
        const raw = storage.getItem(key) || '[]';
        if (raw.length > 131072) return [];
        const data = JSON.parse(raw);
        if (!Array.isArray(data)) return [];
        const seen = new Set();
        return data.filter(entry => {
            const url = safeScopeUrl(entry?.url, base, prefix, trustedPaths, allowLegacyIntents);
            if (!url || typeof entry.title !== 'string' || !entry.title.trim() || seen.has(url)) return false;
            seen.add(url);
            return true;
        }).slice(0, limit).map(entry => ({title: entry.title.slice(0, 500), url: safeScopeUrl(entry.url, base, prefix, trustedPaths, allowLegacyIntents), episode: String(entry.episode || '').slice(0, 150)}));
    } catch { return []; }
}

export function rememberEntry(storage, key, entry, base, prefix, limit = 10, trustedPaths = [], allowLegacyIntents = true) {
    const url = safeScopeUrl(entry?.url, base, prefix, trustedPaths, allowLegacyIntents);
    if (!url || typeof entry?.title !== 'string' || !entry.title.trim()) return readEntries(storage, key, base, prefix, limit, trustedPaths, allowLegacyIntents);
    const entries = [{title: entry.title.slice(0, 500), url, episode: String(entry.episode || '').slice(0, 150)},
        ...readEntries(storage, key, base, prefix, limit, trustedPaths, allowLegacyIntents).filter(item => item.url !== url)].slice(0, limit);
    try { storage.setItem(key, JSON.stringify(entries)); } catch { /* Local storage is optional. */ }
    return entries;
}

// The adapter runs at the end of body. Freeze only server-marked destinations
// already present at bootstrap; later theme inserts cannot extend this set.
function initialMarkedPaths(doc, base) {
    const paths = new Set();
    try {
        const origin = new URL(base);
        for (const node of doc.querySelectorAll('a[data-tram-fm][href], area[data-tram-fm][href]')) {
            const value = node.getAttribute('href');
            if (typeof value !== 'string' || !value || (!value.startsWith('/') && !/^https?:\/\//i.test(value))
                || value.startsWith('//') || /[\u0000-\u0020\\]/.test(value) || /%(?:2f|5c|2e)/i.test(value)
                || /(?:^|\/)\.\.(?:\/|$)/.test(value)) continue;
            const url = new URL(value, origin);
            if (!['http:', 'https:'].includes(url.protocol) || url.origin !== origin.origin || url.username || url.password || url.href.length > 4096) continue;
            paths.add(url.pathname);
        }
    } catch { /* Missing DOM support or malformed context must not grant paths. */ }
    return paths;
}

function safeFriendLink(anchor, base) {
    if (!anchor?.closest?.('[data-tram-friend-links="true"]')) return false;
    const value = anchor.getAttribute('href');
    if (typeof value !== 'string' || !/^https?:\/\//i.test(value) || /[\u0000-\u0020\\]/.test(value) || value.length > 4096) return false;
    try {
        const origin = new URL(base), destination = new URL(value);
        return ['http:', 'https:'].includes(destination.protocol) && destination.origin !== origin.origin
            && !destination.username && !destination.password;
    } catch { return false; }
}

export function mountFanmuluRuntime(win, doc) {
    const body = doc?.body;
    const prefix = body?.getAttribute('data-fanmulu-prefix');
    const root = body?.getAttribute('data-fanmulu-root');
    const keyword = body?.getAttribute('data-fanmulu-keyword');
    const base = win?.location?.href;
    const legacyContext = body?.getAttribute('data-fanmulu-scope') === 'true' || body?.getAttribute('data-fanmulu-targets') != null;
    const trustedPaths = legacyContext ? new Set() : initialMarkedPaths(doc, base);
    trustedPaths.add(root);
    if (legacyContext) {
        try {
            const raw = body?.getAttribute('data-fanmulu-targets') || '[]';
            const parsed = raw.length <= 262144 ? JSON.parse(raw) : [];
            if (Array.isArray(parsed)) for (const path of parsed) trustedPaths.add(path);
        } catch { /* Historical publications retain their bounded optional list. */ }
    }
    if (!prefix || !keyword || !safeScopeUrl(root, base, prefix, [root]) || body.getAttribute('data-fanmulu-runtime-mounted') === 'true') return null;
    const safe = (value, from = base) => safeScopeUrl(value, from, prefix, trustedPaths, legacyContext);
    body.setAttribute('data-fanmulu-runtime-mounted', 'true');
    const historyKey = 'tram.fanmulu.history.v2:' + prefix;
    const favoriteKey = 'tram.fanmulu.favorites.v2:' + prefix;
    const previous = win.FzhmUI;
    let storage;
    try { storage = win.localStorage; } catch { storage = {getItem: () => null, setItem: () => {}}; }
    const notice = message => previous?.notice?.(message);
    const draw = () => {
        const list = doc.getElementById('tram_fanmulu_history') || doc.getElementById('stui_history');
        if (!list) return;
        const entries = readEntries(storage, historyKey, base, prefix, 10, trustedPaths, legacyContext);
        const signature = JSON.stringify(entries);
        if (list.getAttribute('data-fanmulu-history') === signature
            && Array.from(list.children).every(item => item.getAttribute('data-fanmulu-history-entry') === 'true')) return;
        const children = entries.map(entry => {
            const item = doc.createElement('li'), anchor = doc.createElement('a'), part = doc.createElement('span');
            item.className = 'top-line'; item.setAttribute('data-fanmulu-history-entry', 'true');
            anchor.href = entry.url; anchor.title = entry.title;
            anchor.appendChild(doc.createTextNode(entry.title));
            part.className = 'pull-right text-red'; part.textContent = entry.episode;
            anchor.appendChild(part); item.appendChild(anchor); return item;
        });
        if (!children.length) {
            const empty = doc.createElement('li'); empty.className = 'col-pd text-muted'; empty.textContent = '您还没有看过影片哦';
            empty.setAttribute('data-fanmulu-history-entry', 'true'); children.push(empty);
        }
        list.setAttribute('data-fanmulu-history', signature); list.replaceChildren(...children);
    };
    const record = entry => {
        const current = safe(win.location.href);
        const url = safe(entry?.url);
        if (!url) return;
        rememberEntry(storage, historyKey, {...entry, title: url === current ? keyword : entry.title}, base, prefix, 10, trustedPaths, legacyContext);
        draw();
    };
    // The original adapter exposes a replaceable API. Keep its other controls.
    win.FzhmUI = Object.freeze({...previous, record, history: draw});
    const onClick = event => {
        const action = event.target?.closest?.('[data-fzhm-action]');
        if (action?.dataset.fzhmAction === 'history-clear') {
            event.preventDefault(); event.stopImmediatePropagation();
            try { storage.setItem(historyKey, '[]'); } catch {}
            draw(); return;
        }
        if (action?.dataset.fzhmAction === 'favorite') {
            event.preventDefault(); event.stopImmediatePropagation();
            const url = safe(win.location.href);
            if (!url) return;
            const entries = readEntries(storage, favoriteKey, base, prefix, 100, trustedPaths, legacyContext);
            const found = entries.some(entry => entry.url === url);
            try { storage.setItem(favoriteKey, JSON.stringify(found ? entries.filter(entry => entry.url !== url)
                : [{url, title: keyword, episode: ''}, ...entries].slice(0, 100))); } catch {}
            action.setAttribute('aria-pressed', found ? 'false' : 'true');
            notice(found ? '已取消本机收藏。' : '已加入本机收藏。'); return;
        }
        const anchor = event.target?.closest?.('a[href]');
        if (anchor && !safeFriendLink(anchor, win.location.href) && !safe(anchor.getAttribute('href'), win.location.href)) {
            event.preventDefault(); event.stopImmediatePropagation(); draw();
        }
    };
    const onSubmit = event => {
        const action = event.target?.getAttribute?.('action') || win.location.href;
        if (!safe(action, win.location.href)) { event.preventDefault(); event.stopImmediatePropagation(); }
    };
    let observer;
    const initialize = () => {
        const list = doc.getElementById('tram_fanmulu_history') || doc.getElementById('stui_history');
        if (list && win.MutationObserver) { observer = new win.MutationObserver(draw); observer.observe(list, {childList: true, subtree: true}); }
        draw();
        const state = doc.getElementById('fzhm-runtime-state');
        if (state) record({url: win.location.href, title: keyword, episode: state.dataset.episodeTitle || ''});
    };
    doc.addEventListener('click', onClick, true);
    doc.addEventListener('submit', onSubmit, true);
    const onStorage = event => { if (event.key === historyKey) draw(); };
    win.addEventListener('storage', onStorage);
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', initialize, {once: true}); else initialize();
    return {historyKey, favoriteKey, draw, record, destroy() {
        observer?.disconnect(); doc.removeEventListener('click', onClick, true); doc.removeEventListener('submit', onSubmit, true);
        doc.removeEventListener('DOMContentLoaded', initialize); win.removeEventListener('storage', onStorage);
        if (win.FzhmUI?.record === record) win.FzhmUI = previous;
        body.removeAttribute('data-fanmulu-runtime-mounted');
    }};
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') mountFanmuluRuntime(window, document);
