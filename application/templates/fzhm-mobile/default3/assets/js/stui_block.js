/* Reviewed Trạm SEO adapter for the original STUI DOM. Original source is retained in originals/source-tree. */
(function (window, document, $) {
    'use strict';
    if (!$) return;
    var historyKey = 'tram.fzhm.history.v1';
    var favoriteKey = 'tram.fzhm.favorites.v1';
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    function url(value) {
        if (typeof value !== 'string' || value.trim() === '') return '';
        try { var candidate = new URL(value, window.location.href); return /^https?:$/.test(candidate.protocol) && candidate.origin === window.location.origin ? candidate.href : ''; }
        catch (_) { return ''; }
    }
    function read(key) {
        try { var data = JSON.parse(window.localStorage.getItem(key) || '[]'); return Array.isArray(data) ? data.slice(0, 100) : []; }
        catch (_) { return []; }
    }
    function write(key, value) { try { window.localStorage.setItem(key, JSON.stringify(value)); } catch (_) { /* Storage can be denied by browser settings. */ } }
    function notice(message) {
        $('.fzhm-runtime-notice').remove();
        var node = document.createElement('div'); node.className = 'fzhm-runtime-notice'; node.setAttribute('role', 'status'); node.textContent = message; document.body.appendChild(node);
        window.setTimeout(function () { node.remove(); }, 4000);
    }
    function history() {
        var list = document.getElementById('stui_history'); if (!list) return;
        list.replaceChildren();
        var entries = read(historyKey).filter(function (entry) { return entry && typeof entry.title === 'string' && url(entry.url); }).slice(0, 10);
        entries.forEach(function (entry) {
            var item = document.createElement('li'), anchor = document.createElement('a'), part = document.createElement('span'); item.className = 'top-line'; anchor.href = url(entry.url); anchor.title = entry.title;
            anchor.appendChild(document.createTextNode(entry.title)); part.className = 'pull-right text-red'; part.textContent = typeof entry.episode === 'string' ? entry.episode : ''; anchor.appendChild(part); item.appendChild(anchor); list.appendChild(item);
        });
        if (!entries.length) { var empty = document.createElement('li'); empty.className = 'col-pd text-muted'; empty.textContent = '您还没有看过影片哦'; list.appendChild(empty); }
    }
    function record(entry) {
        var safe = url(entry.url); if (!safe || !entry.title) return;
        var entries = read(historyKey).filter(function (item) { return item && item.url !== safe; });
        entries.unshift({title: String(entry.title).slice(0, 250), url: safe, episode: String(entry.episode || '').slice(0, 150)}); write(historyKey, entries.slice(0, 10)); history();
    }
    function qr(target, size) {
        if (!target || typeof window.QRCode !== 'function') return;
        target.replaceChildren(); new window.QRCode(target, {text: window.location.href, width: size, height: size, colorDark: '#000000', colorLight: '#ffffff', correctLevel: window.QRCode.CorrectLevel.H});
    }
    async function copy() {
        try {
            if (window.navigator.clipboard && window.isSecureContext) await window.navigator.clipboard.writeText(window.location.href);
            else { var input = document.createElement('textarea'); input.value = window.location.href; input.style.position = 'fixed'; input.style.opacity = '0'; document.body.appendChild(input); input.select(); var copied = document.execCommand('copy'); input.remove(); if (!copied) throw new Error('Clipboard unavailable'); }
            notice('地址复制成功，赶快分享给小伙伴吧！');
        } catch (_) { notice('浏览器未允许复制，请从地址栏复制本页链接。'); }
    }
    function share() {
        if (document.querySelector('.fzhm-share-panel')) return;
        var overlay = document.createElement('div'), panel = document.createElement('div'), title = document.createElement('h3'), code = document.createElement('div');
        overlay.className = 'fzhm-share-panel'; overlay.setAttribute('role', 'dialog'); overlay.setAttribute('aria-modal', 'true'); overlay.setAttribute('aria-label', '分享影片'); title.textContent = '扫码用手机访问'; code.className = 'fzhm-share-qr'; panel.appendChild(title); panel.appendChild(code);
        [['复制链接', copy], ['关闭', function () { overlay.remove(); }]].forEach(function (item) { var button = document.createElement('button'); button.className = 'btn btn-default'; button.type = 'button'; button.textContent = item[0]; button.addEventListener('click', item[1]); panel.appendChild(button); });
        overlay.appendChild(panel); document.body.appendChild(overlay); qr(code, 160); overlay.addEventListener('click', function (event) { if (event.target === overlay) overlay.remove(); }); overlay.querySelector('button').focus();
    }
    function favorite() {
        var state = document.getElementById('fzhm-runtime-state'), current = url(window.location.href);
        var entries = read(favoriteKey).filter(function (entry) { return entry && typeof entry === 'object' && url(entry.url); });
        var found = entries.some(function (entry) { return entry.url === current; });
        if (found) entries = entries.filter(function (entry) { return entry.url !== current; }); else entries.unshift({url: current, title: state && state.dataset.title || document.title});
        write(favoriteKey, entries.slice(0, 100)); notice(found ? '已取消本机收藏。' : '已加入本机收藏。'); $('[data-fzhm-action="favorite"]').attr('aria-pressed', found ? 'false' : 'true');
    }
    function preloadCarousel(carousel) {
        $(carousel).find('[data-original]').slice(0, 10).each(function () {
            var node = this, candidate;
            try { candidate = new URL(node.getAttribute('data-original'), window.location.href); if (!/^https?:$/.test(candidate.protocol)) return; } catch (_) { return; }
            var image = new Image();
            image.onload = function () {
                if (node.tagName === 'IMG') node.src = candidate.href;
                else node.style.backgroundImage = 'url(' + JSON.stringify(candidate.href) + ')';
            };
            image.src = candidate.href;
        });
    }
    function motionPreference() {
        $.fx.off = reducedMotion.matches;
        if ($.fn.flickity) $('.carousel_center,.carousel_default,.carousel_wide').each(function () {
            if ($(this).data('flickity')) $(this).flickity(reducedMotion.matches ? 'stopPlayer' : 'playPlayer');
        });
    }
    function initialize() {
        $.fx.off = reducedMotion.matches;
        if ($.fn.lazyload) $('.lazyload').lazyload({effect: 'fadeIn', threshold: 200, failurelimit: 15, skip_invisible: false});
        $('.carousel').each(function () { preloadCarousel(this); });
        if ($.fn.flickity) {
            $('.carousel_center').flickity({cellAlign: 'center', contain: true, wrapAround: true, autoPlay: !reducedMotion.matches, prevNextButtons: false});
            $('.carousel_right').flickity({cellAlign: 'left', wrapAround: true, contain: true, pageDots: false});
            $('.carousel_default').flickity({cellAlign: 'left', contain: true, wrapAround: true, autoPlay: !reducedMotion.matches, prevNextButtons: false});
            $('.carousel_wide').flickity({cellAlign: 'center', contain: true, wrapAround: true, autoPlay: !reducedMotion.matches});
            if (window.matchMedia('(max-width:767px)').matches) $('.type-slide').each(function () { $(this).flickity({cellAlign: 'left', freeScroll: true, contain: true, prevNextButtons: false, pageDots: false, initialIndex: Math.max(0, $(this).find('.active').index() - 3)}); });
        }
        if (window.Headroom && document.getElementById('header-top')) new window.Headroom(document.getElementById('header-top'), {tolerance: 5, offset: 205, classes: {initial: 'top-fixed', pinned: 'top-fixed-up', unpinned: 'top-fixed-down'}}).init();
        qr(document.getElementById('qrcode'), 150); qr(document.getElementById('qrcode2'), 160); history();
        function updateBacktop() { $('.backtop').css('display', window.scrollY > 500 ? '' : 'none'); }
        window.addEventListener('scroll', updateBacktop, {passive: true}); updateBacktop();
        $('.stui-content__playlist').each(function () { if ($(this).children('li').length > 5) $(this).addClass('column8'); if ($(this).children('li').length > 30) $(this).css({'max-height':'300px','overflow-y':'auto'}); });
        var location = url(window.location.href);
        $('[data-episode-url]').each(function () { var active = url(this.dataset.episodeUrl) === location; $(this).toggleClass('is-current', active).parent().toggleClass('active', active); });
        $('[data-fzhm-nav]').each(function () { if (!this.getAttribute('href')) { this.setAttribute('aria-disabled', 'true'); this.removeAttribute('href'); } });
        $('.detail-more').on('click', function (event) { event.preventDefault(); $(this).parent().find('.detail-sketch').addClass('hide'); $(this).parent().find('.detail-content').show(); $(this).remove(); });
        $('.share.bdsharebuttonbox').each(function () { this.classList.add('fzhm-share-actions'); [['复制链接', copy], ['二维码分享', share]].forEach(function (item) { var button = document.createElement('button'); button.type = 'button'; button.textContent = item[0]; button.addEventListener('click', item[1]); this.appendChild(button); }, this); });
        $(document).on('click', '[data-fzhm-action]', function (event) {
            var action = this.dataset.fzhmAction; event.preventDefault();
            if (action === 'history-toggle') $(this).closest('li').find('.dropdown').toggle();
            if (action === 'history-clear') { write(historyKey, []); history(); }
            if (action === 'copy') copy(); if (action === 'share') share(); if (action === 'favorite') favorite(); if (action === 'top') window.scrollTo({top: 0, behavior: reducedMotion.matches ? 'auto' : 'smooth'}); if (action === 'back') window.location.assign(this.href);
            if (action === 'report') notice('此站尚未配置报错接收服务；请联系网站管理员并提供本页链接。');
        });
        $('.open-popup').on('click', function (event) { event.preventDefault(); $('.popup').addClass('popup-visible'); }); $('.close-popup').on('click', function () { $('.popup').removeClass('popup-visible'); });
        window.addEventListener('keydown', function (event) { if (event.key === 'Escape') $('.fzhm-share-panel').remove(); }); window.addEventListener('storage', function (event) { if (event.key === historyKey) history(); });
        if (reducedMotion.addEventListener) reducedMotion.addEventListener('change', motionPreference); else reducedMotion.addListener(motionPreference);
    }
    window.FzhmUI = Object.freeze({record: record, history: history, notice: notice});
    $(initialize);
})(window, document, window.jQuery);
