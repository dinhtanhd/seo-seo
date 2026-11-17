import Alpine from 'alpinejs';
import { sourceConfiguration } from './source-configuration';
window.Alpine = Alpine;
window.tramSourceConfiguration = sourceConfiguration;
window.tramRuleEditor = (siteId, choices, initial = {}) => ({
    scopeType: initial.scopeType || 'site', slot: initial.slot || 'title', mode: initial.mode || 'on',
    allocationMode: initial.allocation_mode || 'weighted', expression: initial.rule_form?.template || '',
    h2Name: initial.h2Name || 'summary', kwQuery: '', keywordChoices: [...choices], keywordIds: (initial.keyword_ids || []).map(String), keywordError: '', keywordRequest: 0,
    pageId: initial.pageId ? String(initial.pageId) : '', pageChoices: [...(initial.pageChoices || [])], pageQuery: '', pageError: '', pageRequest: 0,
    init() {
        this.keywordIds.forEach(id => { if (!this.keywordChoices.some(keyword => String(keyword.id) === id)) this.keywordChoices.push({ id, role: this.slot.replace('allocation.', ''), original_text: 'Keyword đã chọn #' + id, pool_id: '—' }); });
        this.$watch('slot', (value, previous) => {
            const defaults = { title: '{{content.title}} - {{kw.primary}} - {{site.name}}', description: '{{content.title}}资料、简介与相关内容。', h1: '{{content.title}}', intro: '收录{{kw.primary}}相关信息。' };
            if (!initial.isExisting && this.expression === (defaults[previous] || '')) this.expression = defaults[value] || '';
            if (this.allocationMode === 'manual' && this.allocationEnabled) this.loadKeywords();
        });
        this.$watch('scopeType', () => {
            this.keywordRequest++;
            this.pageRequest++;
            if (this.allocationMode === 'manual' && this.allocationEnabled) this.loadKeywords();
            if (this.scopeType === 'page') this.loadPages();
        });
        if (this.scopeType === 'page' && !initial.isExisting) this.loadPages();
        if (this.allocationMode === 'manual' && this.allocationEnabled) this.loadKeywords();
        this.$watch('allocationMode', value => { if (value === 'manual') this.loadKeywords(); });
    },
    get allocationEnabled() { return this.slot.startsWith('allocation.') && this.mode === 'on'; },
    get submittedSlot() { return this.slot === 'h2.custom' ? 'h2.' + this.h2Name : this.slot; },
    get visibleKeywords() { return this.keywordChoices.filter(keyword => keyword.role === this.slot.replace('allocation.', '') && (this.keywordIds.includes(String(keyword.id)) || keyword.original_text.includes(this.kwQuery))); },
    async loadPages() {
        if (this.scopeType !== 'page' || !siteId) return;
        const requestId = ++this.pageRequest;
        try {
            const params = new URLSearchParams({ site_id: String(siteId), q: this.pageQuery });
            const response = await fetch('/admin/rule-page-options?' + params.toString(), { headers: { 'Accept': 'application/json' } });
            if (!response.ok) throw new Error('page-search');
            const result = await response.json();
            if (requestId !== this.pageRequest || this.scopeType !== 'page') return;
            const selected = this.pageChoices.filter(page => String(page.id) === this.pageId);
            this.pageChoices = [...new Map([...selected, ...result.data].map(page => [String(page.id), page])).values()];
            this.pageError = result.has_more ? 'Đang hiện 50 kết quả. Gõ thêm đường dẫn để thu hẹp.' : '';
        } catch {
            if (requestId === this.pageRequest) this.pageError = 'Chưa tìm được trang. Lựa chọn trước đó vẫn được giữ.';
        }
    },
    async loadKeywords() {
        if (!this.slot.startsWith('allocation.') || this.allocationMode !== 'manual') return;
        const requestId = ++this.keywordRequest;
        const params = new URLSearchParams({ role: this.slot.replace('allocation.', ''), q: this.kwQuery });
        if (this.scopeType === 'global') params.set('scope_type', 'global');
        else params.set('site_id', String(siteId));
        try {
            const response = await fetch('/admin/keyword-options?' + params.toString(), { headers: { 'Accept': 'application/json' } });
            if (!response.ok) throw new Error('keyword-search');
            const result = await response.json();
            if (requestId !== this.keywordRequest) return;
            const retained = this.keywordChoices.filter(keyword => this.keywordIds.includes(String(keyword.id)));
            const merged = new Map([...retained, ...result.data].map(keyword => [String(keyword.id), keyword]));
            this.keywordChoices = [...merged.values()]; this.keywordError = '';
        } catch { if (requestId === this.keywordRequest) this.keywordError = 'Chưa tải được kết quả tìm kiếm. Các keyword đã chọn vẫn được giữ.'; }
    }
});
window.tramThemeEditor = (views, css) => ({
    activeTab: 'detail', selectedField: 'detail', views, css,
    insert(text) {
        const field = this.$refs[this.selectedField];
        if (!field || field.readOnly) return;
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? start;
        const next = field.value.slice(0, start) + text + field.value.slice(end);
        if (this.selectedField === 'css') this.css = next;
        else this.views[this.selectedField] = next;
        this.$nextTick(() => { field.focus(); field.setSelectionRange(start + text.length, start + text.length); });
    }
});

window.tramSitePresentation = (themes, themeId, routes, views, baseUrl, movieKey = 'source_id', categoryKey = 'id', suffixes = {}) => ({
    themes, themeId: String(themeId || ''), routes: { ...routes }, views: { ...views }, movieKey, categoryKey, suffixes: { home: '', list: '', detail: '', play: '', search: '', ...suffixes }, baseUrl: String(baseUrl || '').replace(/\/$/, ''), initial: '',
    init() { if (this.views.list === 'category') this.views.list = 'list'; this.initial = this.valueFingerprint(); },
    valueFingerprint() { return JSON.stringify({ themeId: this.themeId, routes: this.routes, views: this.views, movieKey: this.movieKey, categoryKey: this.categoryKey, suffixes: this.suffixes }); },
    get dirty() { return this.initial !== this.valueFingerprint(); },
    get selectedTheme() { return this.themes.find(theme => String(theme.id) === this.themeId) || null; },
    fileLabel(key) {
        if (!this.selectedTheme) return 'Hệ thống · ' + key;
        const keys = this.selectedTheme.view_keys;
        if (key === 'list' && !keys.includes('list') && keys.includes('category')) return 'html/category.html · alias danh sách';
        if (key === 'play' && !keys.includes('play')) return 'play · fallback hệ thống';
        return 'html/' + key + '.html';
    },
    example(role) {
        const prefix = this.routes[role] || '/';
        const path = prefix === '/' ? '' : prefix.replace(/\/$/, '');
        const key = { pinyin_id: '/{pinyin}-{source_id}-{external_id}', pinyin_compact_id: '/{pinyin_lienchu}-{source_id}-{external_id}' }[this.movieKey] || '/{source_id}-{external_id}';
        const category = { pinyin_id: '/{pinyin}-{category_id}', pinyin_compact_id: '/{pinyin_lienchu}-{category_id}' }[this.categoryKey] || '/{category_id}';
        const extension = this.suffixes[role] || '';
        if (role === 'home') return this.baseUrl + (prefix === '/' && extension ? '/index' : prefix) + extension;
        const tail = { list: category, detail: key, play: key + '/1', search: '' }[role] || '';
        return this.baseUrl + path + tail + extension + (role === 'search' ? '?q=关键词' : '');
    }
});

window.tramBulkWorkspace = (selectionKey, visibleIds, matchingCount) => ({
    selectionKey, visibleIds: visibleIds.map(String), matchingCount, selected: [], selectionMode: 'ids',
    init() {
        try { const value = JSON.parse(sessionStorage.getItem(this.selectionKey) || '[]'); this.selected = [...new Set(value.filter(id => /^\d+$/.test(String(id))).map(String))].slice(0, 10000); } catch { this.selected = []; }
        this.$watch('selected', value => { try { sessionStorage.setItem(this.selectionKey, JSON.stringify(value)); } catch {} });
    },
    toggle(id, checked) { this.selectionMode = 'ids'; const next = new Set(this.selected); if (checked) next.add(String(id)); else next.delete(String(id)); this.selected = [...next]; },
    selectVisible(checked = true) { this.selectionMode = 'ids'; const next = new Set(this.selected); this.visibleIds.forEach(id => checked ? next.add(id) : next.delete(id)); this.selected = [...next].slice(0, 10000); },
    clearSelection() { this.selectionMode = 'ids'; this.selected = []; },
    get allVisible() { return this.visibleIds.length > 0 && this.visibleIds.every(id => this.selected.includes(id)); },
    get total() { return this.selectionMode === 'matching' ? this.matchingCount : this.selected.length; },
    get valid() { return this.total > 0 && this.total <= 10000; }
});

window.tramCampaignProgress = (status, counts) => ({
    status, lastCounts: JSON.stringify(counts), pollError: '',
    init() {
        if (!['preparing', 'queued', 'applying', 'running'].includes(this.status)) return;
        const interval = window.setInterval(async () => {
            try {
                const response = await fetch(window.location.href, { headers: { Accept: 'application/json' } });
                if (!response.ok) { this.pollError = 'Chưa tải được tiến độ mới. Bấm tải lại để kiểm tra.'; window.clearInterval(interval); return; }
                const result = await response.json();
                if (result.status !== this.status || JSON.stringify(result.counts) !== this.lastCounts) { window.clearInterval(interval); window.location.reload(); }
            } catch { this.pollError = 'Kết nối bị gián đoạn. Trạng thái đã ghi vẫn giữ; bấm tải lại để kiểm tra.'; window.clearInterval(interval); }
        }, 6000);
    }
});

window.tramSourceTypes = (types, mode, selected, categoryMap, categories, categorySuggestions = {}) => ({
    types, mode, selected: (selected || []).map(String), categoryMap: { ...(categoryMap || {}) }, categories, categorySuggestions,
    query: '', suggestionMessage: '',
    init() { this.types.forEach(type => { if (this.categoryMap[type.id] == null) this.categoryMap[type.id] = ''; }); },
    normalize(value) { return String(value || '').normalize('NFC').trim().toLocaleLowerCase(); },
    matches(type) { const query = this.normalize(this.query); return !query || this.normalize(type.name).includes(query) || String(type.id).includes(query); },
    get filtered() { return this.types.filter(type => this.matches(type)); },
    selectedBase() { return this.mode === 'all' ? this.types.map(type => String(type.id)) : [...this.selected]; },
    toggle(id, checked) {
        const next = new Set(this.selectedBase());
        if (checked) next.add(String(id)); else next.delete(String(id));
        this.mode = 'selected'; this.selected = [...next];
    },
    selectFiltered() { this.selected = [...new Set([...this.selectedBase(), ...this.filtered.map(type => String(type.id))])]; this.mode = 'selected'; },
    clearFiltered() { const ids = new Set(this.filtered.map(type => String(type.id))); this.selected = this.selectedBase().filter(id => !ids.has(id)); this.mode = 'selected'; },
    suggestMatches() {
        let count = 0;
        this.types.forEach(type => {
            if (this.categoryMap[type.id]) return;
            const suggestion = this.categorySuggestions[type.id];
            if (suggestion && this.categories.some(category => String(category.id) === String(suggestion))) {
                this.categoryMap[type.id] = String(suggestion); count++;
            }
        });
        this.suggestionMessage = 'Đã điền ' + count + ' gợi ý. Chưa lưu; hãy kiểm tra các ô ghép danh mục.';
    }
});

Alpine.start();
