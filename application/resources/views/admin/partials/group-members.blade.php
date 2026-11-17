@php
    $memberRows = collect($memberRows)->map(fn ($row) => (array) $row)->values()->all();
    $memberChoices = collect($memberChoices)->map(fn ($row) => (array) $row)->values()->all();
    $memberIds = array_map(fn ($row) => (string) $row['id'], $memberRows);
    $memberChanged = false;
    if (old('form_context') === $memberContext && is_string(old('keyword_ids_json'))) {
        try {
            $oldMembers = json_decode(old('keyword_ids_json'), true, 32, JSON_THROW_ON_ERROR);
            if (is_array($oldMembers) && array_is_list($oldMembers) && count($oldMembers) <= 10000 && !count(array_filter($oldMembers, fn ($id) => !is_scalar($id) || !ctype_digit((string) $id)))) {
                $memberIds = array_map('strval', $oldMembers);
                $memberChanged = true;
            }
        } catch (\JsonException) {
        }
    }
@endphp
<div x-data="{
    selected: @js($memberIds), known: @js($memberRows), results: @js($memberChoices), q: '', selectedQ: '', loading: false, error: '', changed: @js($memberChanged), sequence: 0,
    init() { this.remember(this.results); },
    remember(rows) { const existing = new Set(this.known.map(row => String(row.id))); rows.forEach(row => { if (!existing.has(String(row.id))) { this.known.push(row); existing.add(String(row.id)); } }); },
    selectedRows() { const ids = new Set(this.selected); const text = this.selectedQ.toLowerCase(); return this.known.filter(row => ids.has(String(row.id)) &amp;&amp; (!text || row.original_text.toLowerCase().includes(text))).slice(0, 100); },
    remove(id) { this.selected = this.selected.filter(value => value !== String(id)); this.changed = true; },
    async search() { const current = ++this.sequence; this.loading = true; this.error = ''; try { const response = await fetch('/admin/library/keyword-options?pool_id={{ $memberPoolId }}&amp;q=' + encodeURIComponent(this.q), { headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error('Không tải được keyword. Hãy thử lại.'); const body = await response.json(); if (current !== this.sequence) return; this.results = body.data; this.remember(this.results); } catch (error) { if (current === this.sequence) this.error = error.message; } finally { if (current === this.sequence) this.loading = false; } }
}">
    <input type="hidden" name="keyword_ids_json" :value="JSON.stringify(selected)" :disabled="!changed">
    <h3>Thành viên keyword · <span x-text="selected.length"></span> đã chọn</h3>
    <p class="muted">Danh sách được giữ nguyên nếu chỉ sửa tên hoặc trạng thái. Thay đổi thành viên cần bấm Lưu nhóm; keyword đã xóa từ trước vẫn được giữ cho đến khi bạn bỏ chọn.</p>
    <div class="formrow">
        <label>Tìm keyword trong kho<input type="search" x-model="q" maxlength="200" placeholder="Tìm để thêm thành viên…" @keydown.enter.prevent="search()"></label>
        <div class="actions"><button type="button" class="secondary" @click="search()" :disabled="loading">Tìm keyword</button><span class="muted" x-show="loading">Đang tìm…</span></div>
    </div>
    <p class="errornotice" x-show="error" x-text="error" x-cloak></p>
    <div class="tablewrap"><table><thead><tr><th>Chọn</th><th>Keyword</th><th>Vai trò / trạng thái</th></tr></thead><tbody>
        <template x-for="row in results" :key="row.id"><tr><td><input type="checkbox" :value="String(row.id)" x-model="selected" @change="changed = true" :aria-label="'Chọn ' + row.original_text"></td><td x-text="row.original_text"></td><td><span x-text="row.role"></span> · <span x-text="row.status"></span></td></tr></template>
        <tr x-show="!results.length"><td colspan="3" class="empty">Không có keyword phù hợp. Thử từ khóa khác.</td></tr>
    </tbody></table></div>
    <p class="muted">Mỗi lần tìm hiển thị tối đa 50 keyword; lựa chọn trước đó vẫn được giữ khi đổi từ khóa tìm kiếm.</p>
    <details><summary>Xem và bỏ thành viên đã chọn</summary><label>Lọc thành viên đã chọn<input type="search" x-model="selectedQ" placeholder="Tìm trong danh sách đã chọn…"></label>
        <div class="tablewrap"><table><thead><tr><th>Keyword</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
            <template x-for="row in selectedRows()" :key="row.id"><tr><td><span x-text="row.original_text"></span> · <small x-text="row.role"></small></td><td x-text="row.deleted_at ? 'Đã xóa keyword' : row.status"></td><td><button type="button" class="secondary" @click="remove(row.id)">Bỏ khỏi nhóm</button></td></tr></template>
        </tbody></table></div><p class="muted">Hiển thị tối đa 100 thành viên mỗi lần lọc. Tổng số đã chọn ở trên là số thực sẽ lưu, tối đa 10.000.</p>
        <button type="button" class="secondary" @click="selected = []; changed = true">Bỏ tất cả thành viên</button>
    </details>
    <p class="muted" x-show="changed">Danh sách thành viên đã thay đổi; chưa lưu.</p>
</div>
