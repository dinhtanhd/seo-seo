/* Reviewed first-party adapter for captured MyTheme/EWave DOM; source CMS scripts stay inert. */
(function (window, document) {
    'use strict';
    var motion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : {matches:false};
    var key = 'tram.imported.history.v1';
    var favoritesKey = 'tram.imported.favorites.v1';
    var $ = window.jQuery;
    var imageObserver;
    var searchStyles = new WeakMap();
    var siblingStyles = new WeakMap();
    var descriptionTrigger;
    var historyStyles = new WeakMap();
    function local(value) {
        if (typeof value !== 'string' || !value.trim() || value.length > 4000 || /[\u0000-\u0020\u007f\\]/.test(value)) return '';
        try {
            var url = new URL(value, window.location.href);
            return /^https?:$/.test(url.protocol) && url.origin === window.location.origin && !url.username && !url.password ? url.href : '';
        } catch (_) { return ''; }
    }
    function read(name) {
        try {
            var raw = window.localStorage.getItem(name) || '[]'; if (raw.length > 131072) return [];
            var value = JSON.parse(raw); if (!Array.isArray(value)) return [];
            return value.filter(function (item) { return item && typeof item.title === 'string' && item.title.trim() && local(item.url); })
                .slice(0,100).map(function (item) { return {title:item.title.slice(0,250),url:local(item.url)}; });
        } catch (_) { return []; }
    }
    function write(name, items) { try { window.localStorage.setItem(name,JSON.stringify(items)); } catch (_) { /* Optional browser storage. */ } }
    function notice(message) {
        var previous = document.querySelector('.tram-theme-notice'); if (previous) previous.remove();
        var node = document.createElement('div'); node.className = 'tram-theme-notice'; node.setAttribute('role','status'); node.textContent = message;
        Object.assign(node.style,{position:'fixed',bottom:'24px',left:'50%',transform:'translateX(-50%)',background:'#222',color:'#fff',padding:'12px 20px',borderRadius:'6px',zIndex:'99999'});
        document.body.appendChild(node); window.setTimeout(function () { node.remove(); },3000);
    }
    function historyWidget() {
        document.querySelectorAll('.js-history.ewave-history').forEach(function (host) {
            if (host.children.length || host.querySelector('.ewave-history-dropdown')) return;
            var dropdown = document.createElement('div'); dropdown.className = 'ewave-dropdown ewave-history-dropdown';
            var button = document.createElement('a'); button.className = 'ewave-history-text'; button.href = '#'; button.setAttribute('data-tram-action','history-toggle'); button.setAttribute('aria-expanded','false'); button.setAttribute('role','button');
            var icon = document.createElement('i'); icon.className = 'fa fa-history'; button.appendChild(icon);
            var label = document.createElement('span'); label.textContent = '播放记录'; button.appendChild(label); dropdown.appendChild(button);
            var box = document.createElement('div'); box.className = 'ewave-dropdown-box ewave-history-box';
            var empty = document.createElement('div'); empty.className = 'ewave-history-empty'; empty.textContent = '暂无播放记录'; box.appendChild(empty); dropdown.appendChild(box); host.appendChild(dropdown);
        });
    }
    function history() {
        historyWidget();
        document.querySelectorAll('.ewave-history-box').forEach(function (box) {
            if (box.querySelector('.ewave-history-list')) return;
            var list = document.createElement('ul'); list.className = 'ewave-history-list'; box.appendChild(list);
        });
        var entries = read(key).slice(0,10);
        document.querySelectorAll('.history-list, .ewave-history-list, [data-tram-history]').forEach(function (list) {
            list.replaceChildren();
            entries.forEach(function (entry) {
                var item = document.createElement(list.matches('ul,ol') ? 'li' : 'p'); var a = document.createElement('a');
                a.href = local(entry.url); a.title = entry.title; a.textContent = entry.title; if (list.classList.contains('ewave-history-list')) { item.className = 'ewave-history-item'; a.className = 'text-overflow ewave-history-name'; } item.appendChild(a); list.appendChild(item);
            });
            if (!entries.length) { var empty = document.createElement('p'); empty.className = 'text-muted'; empty.textContent = '暂无播放记录'; list.appendChild(empty); }
        });
        document.querySelectorAll('.ewave-history-empty').forEach(function (node) { node.hidden = entries.length > 0; });
        document.querySelectorAll('.ewave-history-box').forEach(function (box) {
            if (!entries.length || box.querySelector('[data-tram-action="history-clear"]')) return;
            var button = document.createElement('button'); button.type = 'button'; button.className = 'ewave-history-clear';
            button.setAttribute('data-tram-action','history-clear'); button.textContent = '清空记录'; box.appendChild(button);
        });
    }
    function closeDescription() {
        var overlay = document.querySelector('.tram-theme-description-overlay'); if (overlay) overlay.remove();
        if (descriptionTrigger && descriptionTrigger.focus) descriptionTrigger.focus(); descriptionTrigger = null;
    }
    function description(trigger) {
        var source = document.querySelector('[data-tram-description]'); if (!source) return;
        closeDescription(); descriptionTrigger = trigger;
        var overlay = document.createElement('div'); overlay.className = 'tram-theme-description-overlay';
        Object.assign(overlay.style,{position:'fixed',inset:'0',zIndex:'99998',background:'rgba(0,0,0,.55)',display:'flex',alignItems:'center',justifyContent:'center',padding:'4vw'});
        var panel = document.createElement('section'); panel.className = 'tram-theme-description-panel'; panel.setAttribute('role','dialog'); panel.setAttribute('aria-modal','true'); panel.setAttribute('aria-label','剧情简介');
        Object.assign(panel.style,{width:'720px',maxWidth:'100%',maxHeight:'80vh',overflowY:'auto',background:'var(--bg-color-1, #fff)',color:'var(--text-color-1, #222)',padding:'24px',borderRadius:'8px',boxShadow:'0 4px 30px #0006'});
        var heading = document.createElement('h2'); heading.textContent = '剧情简介'; panel.appendChild(heading);
        // This marked container is rendered through the server HTML sanitizer. Clone its DOM;
        // do not parse strings, move the original content or enable source CMS scripts.
        var content = source.cloneNode(true); content.removeAttribute('id'); content.removeAttribute('hidden'); content.removeAttribute('data-tram-description'); content.hidden = false; content.classList.add('tram-theme-description-content');
        content.querySelectorAll('[id]').forEach(function (node) { node.removeAttribute('id'); }); panel.appendChild(content);
        var close = document.createElement('button'); close.type = 'button'; close.setAttribute('data-tram-action','description-close'); close.textContent = '关闭'; panel.appendChild(close);
        overlay.appendChild(panel); document.body.appendChild(overlay); if (close.focus) close.focus();
    }
    function remember() {
        var page = document.querySelector('[data-tram-page="detail"], [data-tram-page="play"], .play-source');
        var heading = document.querySelector('h1'); if (!page || !heading || !heading.textContent.trim()) return;
        var canonical = document.querySelector('link[rel="canonical"]'); var href = local(canonical ? canonical.href : window.location.href);
        if (!href) return;
        var entries = read(key).filter(function (item) { return item.url !== href; });
        entries.unshift({title:heading.textContent.trim().slice(0,250),url:href}); write(key,entries.slice(0,10));
    }
    function hydrate(node) {
        var value = node.getAttribute('data-original') || node.getAttribute('data-src'); if (!value) return;
        try {
            var url = new URL(value,window.location.href); if (!/^https?:$/.test(url.protocol) || url.username || url.password) return;
            if (node.tagName === 'IMG') { node.src = url.href; node.loading = 'lazy'; }
            else node.style.backgroundImage = 'url(' + JSON.stringify(url.href) + ')';
            node.classList.add('loaded');
        } catch (_) { /* Invalid image URL keeps the theme placeholder. */ }
    }
    function lazy() {
        if (typeof window.IntersectionObserver === 'function') imageObserver = new window.IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { if (entry.isIntersecting) { hydrate(entry.target); imageObserver.unobserve(entry.target); } });
        },{rootMargin:'300px'});
        document.querySelectorAll('[data-original], [data-src]').forEach(function (node) { if (imageObserver) imageObserver.observe(node); else hydrate(node); });
    }
    function makeQr(node) {
        if (node.getAttribute('data-tram-qr-ready') === 'true') return true;
        try {
            node.replaceChildren();
            if (typeof window.QRCode === 'function') new window.QRCode(node,{text:window.location.href,width:160,height:160});
            else if ($ && $.fn.qrcode) $(node).qrcode({text:window.location.href,width:160,height:160});
            else return false;
            node.setAttribute('data-tram-qr-ready','true'); return true;
        } catch (_) { return false; }
    }
    function qr(trigger) {
        var old = document.querySelector('.tram-theme-qr-panel'); if (old) { old.remove(); return; }
        var panel = document.createElement('div'); panel.className = 'tram-theme-qr-panel'; panel.setAttribute('role','dialog'); panel.setAttribute('aria-label','二维码分享');
        Object.assign(panel.style,{position:'fixed',top:'50%',left:'50%',transform:'translate(-50%,-50%)',background:'#fff',color:'#222',padding:'22px',border:'1px solid #ddd',borderRadius:'8px',zIndex:'99999',boxShadow:'0 4px 30px #0006',maxWidth:'90vw'});
        var image = document.createElement('div'); image.className = 'tram-theme-qr-image'; panel.appendChild(image);
        var text = document.createElement('p'); text.textContent = window.location.href; text.style.overflowWrap = 'anywhere'; panel.appendChild(text);
        var close = document.createElement('button'); close.type = 'button'; close.setAttribute('data-tram-action','qr-close'); close.textContent = '关闭'; panel.appendChild(close);
        document.body.appendChild(panel);
        if (!makeQr(image)) notice('请复制地址栏链接分享');
        if (close.focus) close.focus();
    }
    function plugins() {
        if ($ && $.fn.flickity) $('.flickity, .carousel, .nav-slide').each(function () {
            if ($(this).data('flickity') || !this.children.length) return;
            var navigation = this.classList.contains('nav-slide');
            $(this).flickity({cellAlign:this.getAttribute('data-align') || 'left',contain:true,wrapAround:!navigation,freeScroll:navigation,
                autoPlay:!navigation && !motion.matches && this.getAttribute('data-play') === 'true' ? 5000 : false,
                pageDots:!navigation && this.getAttribute('data-dots') === 'true',prevNextButtons:!navigation && this.getAttribute('data-next') === 'true'});
        });
        if (typeof window.Swiper === 'function') document.querySelectorAll('.swiper-container, .swiper, .ewave-swiper').forEach(function (node) {
            if (node.swiper || !node.querySelector('.swiper-wrapper')) return;
            var banner = !!node.closest('.banner, .ibanner, .banner-area, .slider');
            var cards = !banner && !!node.closest('.vods-box') && !!node.querySelector('.myui-vodlist');
            var ewaveImage = node.classList.contains('ewave-swiper-image');
            var lazyClass = node.getAttribute('data-lazy-class') || 'swiper-lazy'; if (!/^[A-Za-z0-9_-]+$/.test(lazyClass)) lazyClass = 'swiper-lazy';
            var pagination = node.querySelector('.swiper-pagination'); var paginationSelector = node.getAttribute('data-pagination');
            if (/^[.#][A-Za-z0-9_-]+$/.test(paginationSelector || '')) pagination = node.querySelector(paginationSelector) || pagination;
            var options = {slidesPerView:banner ? 1 : 'auto',spaceBetween:banner || cards ? 0 : 10,watchOverflow:true,freeMode:!banner && !cards,
                preloadImages:false,lazy:{elementClass:lazyClass,loadPrevNext:true,loadPrevNextAmount:8},
                autoplay:(banner || cards || ewaveImage) && !motion.matches && node.getAttribute('data-autoplay') !== 'false' ? {delay:5000,disableOnInteraction:false,pauseOnMouseEnter:true} : false,
                pagination:{el:pagination,clickable:true},
                navigation:{nextEl:node.querySelector('.swiper-button-next'),prevEl:node.querySelector('.swiper-button-prev')}};
            if (cards) {
                // Captured native MYTHEME26 initializer uses three cards through 768px,
                // eight above 768px. Explicit widths prevent Swiper's 100% slide rule
                // overriding the source grid classes when slidesPerView is 'auto'.
                options.slidesPerView = (window.innerWidth || 1024) <= 768 ? 3 : 8; options.slidesPerGroup = options.slidesPerView;
                options.breakpoints = {0:{slidesPerView:3,slidesPerGroup:3},769:{slidesPerView:8,slidesPerGroup:8}};
                options.loop = true; options.loopFillGroupWithBlank = true;
            } else if (banner || ewaveImage) {
                options.loop = node.getAttribute('data-loop') !== 'false'; options.centeredSlides = !banner && node.getAttribute('data-center') !== 'false'; options.centeredSlidesBounds = true;
                options.effect = node.getAttribute('data-effect') === 'fade' ? 'fade' : 'slide'; options.fadeEffect = {crossFade:true};
            }
            if (ewaveImage && !banner) options.spaceBetween = 0;
            var bulletClass = node.getAttribute('data-pagination-class'); var activeClass = node.getAttribute('data-pagination-active-class');
            if (/^[A-Za-z0-9_-]+$/.test(bulletClass || '')) options.pagination.bulletClass = bulletClass;
            if (/^[A-Za-z0-9_-]+$/.test(activeClass || '')) options.pagination.bulletActiveClass = activeClass;
            if (pagination && bulletClass === 'name') {
                var sourceSlides = Array.from(node.querySelector('.swiper-wrapper').children).filter(function (slide) { return slide.classList.contains('swiper-slide'); });
                options.pagination.renderBullet = function () { return '<span class="name"></span>'; };
                options.on = {paginationRender:function () { Array.from(pagination.children).forEach(function (bullet,index) { var slide = sourceSlides[index]; if (!slide) return; var link = slide.matches('a') ? slide : slide.querySelector('a'); var title = link && (link.getAttribute('title') || link.textContent.trim()); if (!title) return; var heading = document.createElement('h3'); heading.textContent = title; bullet.replaceChildren(heading); var metadata = slide.querySelector('.swiper-pagination-html'); var remarks = metadata && metadata.querySelector('p'); if (remarks && remarks.textContent.trim()) { var text = document.createElement('p'); text.textContent = remarks.textContent; bullet.appendChild(text); } }); }};
            }
            try { new window.Swiper(node,options); } catch (_) { /* Static navigation/cards stay usable. */ }
        });
        if (typeof window.Headroom === 'function') {
            var header = document.querySelector('#header-top, .ewave-headroom');
            if (header) { try { var myHeader = header.classList.contains('myui-header__top'); var settings = myHeader ? {offset:205,tolerance:5,classes:{initial:'top-fixed',pinned:'top-fixed-up',unpinned:'top-fixed-down'}} : {offset:80,tolerance:10}; new window.Headroom(header,settings).init(); } catch (_) { /* Static header stays available. */ } }
        }
        document.querySelectorAll('#qrcode, .ewave-qrcode').forEach(makeQr);
    }
    function headerState() {
        var header = document.getElementById('header-top'); if (!header || !header.classList.contains('myui-header__top')) return;
        function update() { header.classList.toggle('top',(window.scrollY || document.documentElement.scrollTop || 0) <= 10); }
        update(); if (window.addEventListener) window.addEventListener('scroll',update,{passive:true});
    }
    function setSkin(dark, persist) {
        document.body.classList.toggle('dark',dark); document.body.classList.toggle('night',dark);
        document.documentElement.setAttribute('data-theme-skin',dark ? 'dark' : 'light'); document.documentElement.setAttribute('data-theme',dark ? '1' : '0');
        document.querySelectorAll('input[data-tram-action="skin"], input.wb-switch').forEach(function (input) { input.checked = dark; });
        document.querySelectorAll('link[rel="stylesheet"]').forEach(function (link) {
            if (!link.href.includes('mytheme-color1.css')) return;
            var alternate = document.getElementById('tram-alternate-skin');
            if (!alternate) { alternate = document.createElement('link'); alternate.id = 'tram-alternate-skin'; alternate.rel = 'stylesheet'; alternate.href = link.href.replace('mytheme-color1.css','mytheme-color2.css'); document.head.appendChild(alternate); }
            alternate.disabled = !dark;
        });
        if (persist) { try { window.localStorage.setItem('tram.imported.skin.v1',dark ? 'dark' : 'light'); } catch (_) { /* Optional preference. */ } }
    }
    function search(open) {
        var region = document.getElementById('navbar-search') || document.querySelector('.mytheme-header__search, .myui-header__search') || document.querySelector('.a2.search'); if (!region) return;
        var header = region.closest('.header');
        var isMyTheme = region.matches('.mytheme-header__search, .myui-header__search');
        if (open === false && !region.classList.contains('soopen') && !region.classList.contains('search-open') && !(header && header.classList.contains('search-open'))) return;
        var value = typeof open === 'boolean' ? open : (header ? !header.classList.contains('search-open') : !region.classList.contains('soopen'));
        region.classList.toggle('soopen',value); region.classList.toggle('search-open',value); region.setAttribute('aria-hidden',value ? 'false' : 'true');
        if (region.id === 'navbar-search') region.classList.toggle('sooff',value);
        if (header) header.classList.toggle('search-open',value);
        if (isMyTheme) {
            region.classList.toggle('active',value);
            if (value && !searchStyles.has(region)) searchStyles.set(region,{top:region.style.top || '',display:region.style.display || ''});
            if (value) { region.style.top = '0'; region.style.display = 'block'; }
            else if (searchStyles.has(region)) { var original = searchStyles.get(region); region.style.top = original.top; region.style.display = original.display; searchStyles.delete(region); }
            if (region.parentElement) Array.from(region.parentElement.children).forEach(function (sibling) {
                if (sibling === region) return;
                if (value && !siblingStyles.has(sibling)) { siblingStyles.set(sibling,sibling.style.display || ''); sibling.style.display = 'none'; }
                else if (!value && siblingStyles.has(sibling)) { sibling.style.display = siblingStyles.get(sibling); siblingStyles.delete(sibling); }
            });
            if (!value) region.querySelectorAll('.dropdown-box').forEach(function (box) { box.style.display = ''; });
        }
        var button = document.getElementById('soStats'); if (button) { button.setAttribute('aria-expanded',value ? 'true' : 'false'); var icon = button.querySelector('#soico'); if (icon) { icon.classList.toggle('fa-search',!value); icon.classList.toggle('fa-times-circle',value); } }
        document.querySelectorAll('.js-search, .open-search').forEach(function (node) { node.setAttribute('aria-expanded',value ? 'true' : 'false'); });
        if (value) { var input = region.querySelector('[name="q"], input[type="text"]'); if (input && input.focus) input.focus(); }
    }
    function offcanvas(open, trigger) {
        var sidebar = document.querySelector('.sidebar-offcanvas');
        var selector = trigger && trigger.getAttribute('data-target');
        var target = /^#[A-Za-z0-9_-]+$/.test(selector || '') ? document.getElementById(selector.slice(1)) : null;
        if (target && !target.classList.contains('ewave-offcanvas-content')) target = null;
        var region = target || sidebar;
        var value = typeof open === 'boolean' ? open : !!region && !region.classList.contains('active');
        document.querySelectorAll('.ewave-offcanvas-content').forEach(function (node) { var active = value && node === target; node.classList.toggle('active',active); node.setAttribute('aria-hidden',active ? 'false' : 'true'); });
        document.querySelectorAll('.ewave-offcanvas-modal').forEach(function (node) { node.classList.toggle('active',value && !!target); });
        if (sidebar) { var sidebarActive = value && region === sidebar; sidebar.classList.toggle('active',sidebarActive); sidebar.setAttribute('aria-hidden',sidebarActive ? 'false' : 'true'); document.body.classList.toggle('active',sidebarActive); document.body.classList.toggle('tram-menu-open',sidebarActive); }
        document.querySelectorAll('.mobile-overlay').forEach(function (node) { node.classList.toggle('active',value && region === sidebar); });
        document.querySelectorAll('[data-toggle="offcanvas"], .js-m-navlist, .ewave-offcanvas').forEach(function (node) { var active = value && (node === trigger || (region === sidebar && !node.matches('.ewave-offcanvas'))); node.setAttribute('aria-expanded',active ? 'true' : 'false'); });
    }
    function closeHistory(box) {
        box.classList.remove('active');
        var original = historyStyles.get(box); if (original) { Object.assign(box.style,original); historyStyles.delete(box); } else box.style.display = '';
        var dropdown = box.closest('.ewave-history-dropdown, .hist'); var button = dropdown && dropdown.querySelector('.ewave-history-text, [data-tram-action="history-toggle"]'); if (button) button.setAttribute('aria-expanded','false');
    }
    function fitHistory(box) {
        if (!box.getBoundingClientRect) return;
        var viewport = document.documentElement.clientWidth || window.innerWidth; if (!viewport || viewport < 32) return;
        var rect = box.getBoundingClientRect(); if (rect.right <= viewport - 8 && rect.left >= 8) return;
        var width = Math.min(rect.width,viewport - 16); var left = Math.max(8,Math.min(rect.left,viewport - width - 8));
        Object.assign(box.style,{position:'fixed',left:left + 'px',right:'auto',top:Math.max(8,rect.top) + 'px',transform:'none',maxWidth:(viewport - 16) + 'px',boxSizing:'border-box'});
    }
    function historyToggle(node) {
        var region = node.closest('.ewave-history-dropdown, .hist, .dropdown-hover') || node.parentElement;
        var box = region && region.querySelector('.ewave-history-box, .dropdown-box'); if (!box) return;
        if (box.classList.contains('active')) { closeHistory(box); return; }
        var original = {}; ['display','position','left','right','top','transform','maxWidth','boxSizing'].forEach(function (name) { original[name] = box.style[name] || ''; }); historyStyles.set(box,original);
        box.classList.add('active'); box.style.display = 'block'; node.setAttribute('aria-expanded','true'); history(); fitHistory(box);
    }
    function collapse(node) {
        var region = node.closest('.detail-info, .ewave-collapse, .mytheme-content__detail, .myui-content__detail'); if (!region) return;
        var active = node.getAttribute('aria-expanded') !== 'true'; node.setAttribute('aria-expanded',active ? 'true' : 'false');
        region.querySelectorAll('.ewave-collapse-item, .ewave-collapse-content').forEach(function (item) { item.classList.toggle('active',active); });
        node.querySelectorAll('.fa-angle-up, .fa-angle-down').forEach(function (icon) { icon.classList.toggle('fa-angle-up',active); icon.classList.toggle('fa-angle-down',!active); });
        region.querySelectorAll('.detail-info-actor').forEach(function (item) { item.classList.toggle('text-row-2',!active); });
    }
    function heroSearch(input) {
        if (!input || !input.value.trim()) { if (input && input.focus) input.focus(); return; }
        var form = document.querySelector('form[action]'); var href = local(input.getAttribute('data-tram-search-url') || (form && form.getAttribute('action'))); if (!href) return;
        var url = new URL(href); url.searchParams.set('q',input.value.trim()); url.searchParams.delete('page'); url.searchParams.delete('cursor'); window.location.assign(url.href);
    }
    function filter(node) {
        var name = node.getAttribute('data-tram-filter'); if (!['genre','area','year','language'].includes(name)) return false;
        var href = local(node.getAttribute('href')); if (!href) return false;
        var url = new URL(href); var current = new URL(window.location.href);
        ['q','genre','area','year','language'].forEach(function (key) { if (current.searchParams.has(key) && !url.searchParams.has(key)) url.searchParams.set(key,current.searchParams.get(key)); });
        var value = node.getAttribute('data-tram-value') || ''; if (value) url.searchParams.set(name,value); else url.searchParams.delete(name);
        url.searchParams.delete('page'); url.searchParams.delete('cursor'); window.location.assign(url.href); return true;
    }
    function click(event) {
        if (!event.target || !event.target.closest) return;
        if (event.target.matches('.tram-theme-description-overlay')) { event.preventDefault(); closeDescription(); return; }
        if (!event.target.closest('.ewave-offcanvas, .ewave-offcanvas-content, [data-toggle="offcanvas"], .js-m-navlist, .sidebar-offcanvas')) offcanvas(false);
        if (!event.target.closest('.header, .js-search, .open-search, .myui-header__search, .mytheme-header__search, #soStats, #navbar-search')) search(false);
        var node = event.target.closest('a,button,[data-tram-action],.navbar-search-close,.search-close,.mobile-overlay,.ewave-offcanvas-modal,.js-search,.ewave-tab,.ewave-sort,.ewave-search-dropdown-text,.ewave-dropdown-text'); if (!node) return;
        var action = node.getAttribute('data-tram-action') || '';
        if (node.hasAttribute('data-tram-filter')) { event.preventDefault(); filter(node); return; }
        if (action === 'reload') { event.preventDefault(); window.location.reload(); return; }
        if (action === 'description') { event.preventDefault(); description(node); return; }
        if (action === 'description-close') { event.preventDefault(); closeDescription(); return; }
        if (action === 'history-clear' || node.matches('.historyclean, .ewave-history-clear')) { event.preventDefault(); write(key,[]); history(); return; }
        if (action === 'skin' || node.closest('.skin-switch') || node.matches('.btnskin, .js-night, .ewave-night, .dark-switch, .wb-switch')) { event.preventDefault(); setSkin(!document.body.classList.contains('dark'),true); return; }
        if (action === 'qr-close') { event.preventDefault(); var panel = node.closest('.tram-theme-qr-panel'); if (panel) panel.remove(); return; }
        if (action === 'qr' || node.matches('#share-weixin, .ewave-share-qr, .mytheme-share-qr')) { event.preventDefault(); qr(node); return; }
        if (action === 'copy' || node.matches('.myui-copy-link, .ewave-copy-link, .copylink')) {
            event.preventDefault(); if (window.navigator.clipboard) window.navigator.clipboard.writeText(window.location.href).then(function () { notice('链接已复制'); },function () { notice('请从地址栏复制链接'); }); else notice('请从地址栏复制链接'); return;
        }
        if (action === 'favorite' || node.matches('.ewave-favorite, .myui-favorite, .collection')) {
            event.preventDefault(); var title = document.querySelector('h1'); var entries = read(favoritesKey); var href = local(window.location.href);
            if (title && href) { entries = entries.filter(function (entry) { return entry.url !== href; }); entries.unshift({title:title.textContent.trim().slice(0,250),url:href}); write(favoritesKey,entries.slice(0,100)); notice('已收藏到此浏览器'); } return;
        }
        if (node.matches('.ewave-sort')) { var sortTarget = node.getAttribute('data-target'); var sortList = /^#[A-Za-z0-9_-]+$/.test(sortTarget || '') ? document.getElementById(sortTarget.slice(1)) : null; if (sortList && sortList.matches('.ewave-playlist-content, .ewave-playlist-sort-content')) { event.preventDefault(); Array.from(sortList.children).reverse().forEach(function (item) { sortList.appendChild(item); }); } return; }
        if (action === 'reverse-episodes') { event.preventDefault(); var playlist = node.closest('.myui-panel, .mytheme-panel, .tab-pane, .ewave-playlist-content'); if (playlist) playlist.querySelectorAll('.myui-content__list, .mytheme-content__list, .ewave-playlist').forEach(function (list) { Array.from(list.children).reverse().forEach(function (item) { list.appendChild(item); }); }); return; }
        if (action === 'top' || node.matches('#scroll-to-top, .backtop, .ewave-backtop, .js-backtop') || /返回顶部|回到顶部/.test(node.getAttribute('title') || '')) { event.preventDefault(); window.scrollTo({top:0,behavior:motion.matches ? 'instant' : 'smooth'}); return; }
        if (node.closest('.js-search') || node.matches('#soStats, .sostats_click, .js-search, .search-toggle, .ewave-search-toggle, .open-search')) { event.preventDefault(); search(); return; }
        if (node.matches('.navbar-search-close, .search-close')) { event.preventDefault(); search(false); return; }
        if (node.matches('[data-toggle="offcanvas"], .js-m-navlist, .mobile-overlay, .ewave-offcanvas, .ewave-offcanvas-modal')) { event.preventDefault(); offcanvas(node.matches('.mobile-overlay, .ewave-offcanvas-modal') ? false : undefined,node); return; }
        if (action === 'history-toggle' || node.matches('.ewave-history-text, .hist > a')) { event.preventDefault(); historyToggle(node); return; }
        if (node.matches('.ewave-collapse-toggle, [data-tram-action="collapse"]')) { event.preventDefault(); collapse(node); return; }
        var tab = node.closest('.ewave-tab, [data-toggle="tab"]');
        var targetId = tab && (tab.getAttribute('data-target') || tab.getAttribute('href'));
        if (tab && /^#[A-Za-z0-9_-]+$/.test(targetId || '')) {
            var target = document.getElementById(targetId.slice(1));
            if (target) { event.preventDefault(); var region = target.closest('.tab-content, .ewave-tab-content-group') || target.parentElement;
                Array.from(region.children).forEach(function (item) { if (!item.classList.contains('tab-pane') && !item.classList.contains('ewave-tab-content')) return; item.classList.toggle('active',item === target); item.classList.toggle('in',item === target); if (item.classList.contains('ewave-tab-content')) item.style.display = item === target ? '' : 'none'; });
                var list = tab.closest('ul'); if (list) Array.from(list.children).forEach(function (item) { item.classList.toggle('active',item.contains(node)); }); return;
            }
        }
        var toggle = event.target.closest('.dropdown-sub-menu, .top-type-icon');
        if (toggle) { var item = toggle.closest('li'); var submenu = item && Array.from(item.children).find(function (child) { return child.tagName === 'UL'; });
            if (submenu) { event.preventDefault(); var active = !item.classList.contains('open'); item.classList.toggle('open',active); submenu.style.display = active ? 'block' : 'none'; node.setAttribute('aria-expanded',active ? 'true' : 'false'); return; }
        }
        if (node.matches('.ewave-search-dropdown-text, .ewave-dropdown-text')) { event.preventDefault(); var dropdown = node.closest('.ewave-dropdown'); var box = dropdown && dropdown.querySelector('.ewave-dropdown-box'); if (box) { var opened = !box.classList.contains('active'); box.classList.toggle('active',opened); box.style.display = opened ? 'block' : ''; node.setAttribute('aria-expanded',opened ? 'true' : 'false'); } return; }
        if (node.matches('.dropdown-toggle, [data-toggle="dropdown"]')) { event.preventDefault(); var parent = node.parentElement; parent.classList.toggle('open'); node.setAttribute('aria-expanded',parent.classList.contains('open') ? 'true' : 'false'); return; }
        var href = node.getAttribute('href'); if (/^#[A-Za-z0-9_-]+$/.test(href || '')) { var anchor = document.getElementById(href.slice(1)); if (anchor && anchor.scrollIntoView) { event.preventDefault(); anchor.scrollIntoView({behavior:motion.matches ? 'instant' : 'smooth',block:'start'}); } }
    }
    function ready() {
        if (document.body.hasAttribute('data-fanmulu-root')) { key += '.fanmulu'; favoritesKey += '.fanmulu'; }
        remember(); history(); lazy(); plugins(); headerState(); document.addEventListener('click',click);
        document.querySelectorAll('form').forEach(function (form) { var input = form.querySelector('[name="q"]'); if (input) form.addEventListener('submit',function (event) { if (!input.value.trim()) { event.preventDefault(); input.focus(); } }); });
        document.addEventListener('focusin',function (event) { if (event.target && event.target.matches && event.target.matches('#soblur, .search_wd, .b-banner-search-input, .jheader-search-input')) document.querySelectorAll('.top_so, .search-hot-big, .hot-list').forEach(function (node) { node.classList.add('top_ac'); }); });
        document.addEventListener('focusout',function (event) { if (event.target && event.target.matches && event.target.matches('#soblur, .search_wd, .b-banner-search-input, .jheader-search-input')) document.querySelectorAll('.top_so, .search-hot-big, .hot-list').forEach(function (node) { node.classList.remove('top_ac'); }); });
        document.addEventListener('keydown',function (event) {
            if (event.key === 'Escape') { closeDescription(); search(false); offcanvas(false); document.querySelectorAll('.ewave-history-box.active').forEach(closeHistory); document.querySelectorAll('.hist').forEach(function (host) { var box = host.querySelector('.dropdown-box.active'); if (box) closeHistory(box); }); var panel = document.querySelector('.tram-theme-qr-panel'); if (panel) panel.remove(); }
            if (event.key === 'Enter' && event.target && event.target.matches && event.target.matches('.b-banner-search-input, .ibanner-search-input, .home-search-inp') && !event.target.closest('form')) { event.preventDefault(); heroSearch(event.target); }
        });
        try { var savedSkin = window.localStorage.getItem('tram.imported.skin.v1'); setSkin(savedSkin ? savedSkin === 'dark' : document.documentElement.getAttribute('data-theme') === '1',false); } catch (_) { /* Optional preference. */ }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',ready,{once:true}); else ready();
})(window,document);
