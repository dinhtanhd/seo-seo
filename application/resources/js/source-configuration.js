export const sourceConfiguration = (kind, values) => ({
    kind,
    endpoint: typeof values.endpoint === 'string' ? values.endpoint : '',
    allowedHosts: typeof values.allowed_hosts === 'string' ? values.allowed_hosts : '', hostMessage: '',
    queryRows: [], fieldRows: [], fileMappingRows: [], sequence: 0,
    init() {
        this.queryRows = this.prepare(values.query_rows, ['key', 'value']);
        this.fieldRows = this.prepare(values.field_rows, ['field', 'selector', 'attribute', 'mode']);
        this.fileMappingRows = this.prepare(values.file_mapping_rows, ['canonical', 'source']);
        if (this.fieldRows.length === 0) {
            this.addField('external_id', 'data-id');
            this.addField('title');
        }
    },
    get remote() { return ['maccms', 'shandian', 'html'].includes(this.kind); },
    get macCms() { return ['maccms', 'shandian'].includes(this.kind); },
    prepare(rows, fields) {
        return (Array.isArray(rows) ? rows : []).map(row => {
            const item = { uid: ++this.sequence };
            fields.forEach(field => { item[field] = typeof row?.[field] === 'string' ? row[field] : ''; });
            return item;
        });
    },
    addQuery() { if (this.queryRows.length < 100) this.queryRows.push({ uid: ++this.sequence, key: '', value: '' }); },
    addField(field = '', attribute = '') { if (this.fieldRows.length < 100) this.fieldRows.push({ uid: ++this.sequence, field, selector: '', attribute, mode: 'text' }); },
    addFileMapping() { if (this.fileMappingRows.length < 100) this.fileMappingRows.push({ uid: ++this.sequence, canonical: '', source: '' }); },
    addEndpointHost() {
        try {
            const url = new URL(this.endpoint);
            if (url.protocol !== 'https:') throw new Error();
            const hosts = this.allowedHosts.split(/[\r\n,]+/).map(value => value.trim().toLowerCase()).filter(Boolean);
            this.allowedHosts = [...new Set([...hosts, url.hostname.toLowerCase()])].join('\n');
            this.hostMessage = 'Đã thêm hostname của endpoint; máy chủ sẽ kiểm tra khi lưu.';
        } catch { this.hostMessage = 'Hãy nhập endpoint HTTPS hợp lệ trước.'; }
    },
    splitEndpointQuery() {
        const position = this.endpoint.indexOf('?');
        if (position < 0) return;
        const params = new URLSearchParams(this.endpoint.slice(position + 1));
        const rows = [...params.entries()];
        if (rows.length > 100) { this.hostMessage = 'URL có quá 100 tham số; hãy rút gọn trước khi lưu.'; return; }
        const keys = new Set(rows.map(([key]) => key));
        this.queryRows = this.queryRows.filter(row => !keys.has(row.key));
        rows.forEach(([key, value]) => this.queryRows.push({ uid: ++this.sequence, key, value }));
        this.endpoint = this.endpoint.slice(0, position);
    },
    remove(rows, uid) { const index = rows.findIndex(row => row.uid === uid); if (index >= 0) rows.splice(index, 1); }
});
