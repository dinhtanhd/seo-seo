@php
    $urlRule = old('fanmulu.url_rule', $urlRuleSettings['url_rule'] ?? (($newUrlRule ?? false) ? ['mode' => 'random'] : ['mode' => 'legacy']));
    $urlRule = is_array($urlRule) ? $urlRule : ['mode' => 'legacy'];
    $urlRule += ['alphabet' => 'alnum', 'length' => 16, 'group_length' => 0, 'separator' => '-', 'date_format' => 'Ymd', 'template' => '{ngaythangnam}/{6chucai}{4chuso}'];
    $urlRule['mode'] = in_array($urlRule['mode'] ?? null, ['legacy', 'random', 'pattern', 'tokens'], true) ? $urlRule['mode'] : 'legacy';
    $urlRule['alphabet'] = in_array($urlRule['alphabet'], ['alnum', 'letters', 'digits'], true) ? $urlRule['alphabet'] : 'alnum';
    $urlRule['length'] = is_scalar($urlRule['length']) ? $urlRule['length'] : 16;
    $groupLength = filter_var($urlRule['group_length'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 16]]);
    $urlRule['group_length'] = $groupLength !== false && $groupLength !== 1 ? $groupLength : 0;
    $randomSeparator = in_array($urlRule['separator'], ['-', '_'], true) ? $urlRule['separator'] : '-';
    $dateSeparator = in_array($urlRule['separator'], ['', '-', '_', '/'], true) ? $urlRule['separator'] : '-';
    $dateFormats = ['Ymd', 'Y-m-d', 'Y/m/d', 'dmY', 'd-m-Y'];
    $urlRule['date_format'] = in_array($urlRule['date_format'], $dateFormats, true) ? $urlRule['date_format'] : 'Ymd';
    $urlRule['template'] = is_string($urlRule['template']) ? $urlRule['template'] : '{ngaythangnam}/{6chucai}{4chuso}';
    $presets = [
        'legacy' => 'Kiểu cũ (ID phim + mã)',
        'letters' => 'Chữ ngẫu nhiên · kmpxqnvr',
        'digits' => 'Số ngẫu nhiên · 73916284',
        'alnum' => 'Chữ và số ngẫu nhiên · k7m2x9p4',
        'date_letters' => 'Ngày tháng năm + chữ ngẫu nhiên',
        'date_digits' => 'Ngày tháng năm + số ngẫu nhiên',
        'date_alnum' => 'Ngày tháng năm + chữ và số ngẫu nhiên',
        'custom' => 'Tự ghép bằng biến',
    ];
    $derivedPreset = $urlRule['mode'] === 'legacy' ? 'legacy' : ($urlRule['mode'] === 'tokens' ? 'custom' : ($urlRule['mode'] === 'pattern' ? 'date_' : '').$urlRule['alphabet']);
    $preset = is_string($urlRule['preset'] ?? null) && array_key_exists($urlRule['preset'], $presets) ? $urlRule['preset'] : $derivedPreset;
    $isDateStyle = str_starts_with($preset, 'date_');
    $ruleMode = $preset === 'legacy' ? 'legacy' : ($preset === 'custom' ? 'tokens' : ($isDateStyle ? 'pattern' : 'random'));
    $alphabet = $isDateStyle ? substr($preset, 5) : (in_array($preset, ['legacy', 'custom'], true) ? 'alnum' : $preset);
    $sampleDate = now('Asia/Shanghai');
    $samplePrefix = old('fanmulu.prefix', $urlRuleSettings['prefix'] ?? '/f');
    $samplePrefix = is_string($samplePrefix) ? rtrim($samplePrefix, '/') : '/f';
    $sampleSuffix = old('fanmulu.suffix', $urlRuleSettings['suffix'] ?? '');
    $sampleSuffix = is_string($sampleSuffix) ? $sampleSuffix : '';
    $sampleLength = filter_var($urlRule['length'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 8, 'max_range' => 64]]);
    $sampleLength = $sampleLength === false ? 16 : $sampleLength;
    $sampleSeed = ['alnum' => 'k7m2x9p4q6n3v8r5', 'letters' => 'kmxpqnvrstbcdhfg', 'digits' => '7391628450286174'][$alphabet];
    $sampleKey = substr(str_repeat($sampleSeed, 4), 0, $sampleLength);
    if ($ruleMode === 'random' && $urlRule['group_length'] >= 2) {
        $sampleKey = implode($randomSeparator, str_split($sampleKey, $urlRule['group_length']));
    }
    $sampleKey = $ruleMode === 'legacy' ? 'id-chu-de' : ($isDateStyle ? $sampleDate->format($urlRule['date_format']).$dateSeparator.$sampleKey : $sampleKey);
    $customSampleValid = true;
    if ($preset === 'custom') {
        $customRandomCount = 0;
        $customOrdinal = 0;
        $sampleKey = preg_replace_callback('/\{([^{}]+)\}/', function ($match) use ($sampleDate, &$customRandomCount, &$customOrdinal, &$customSampleValid) {
            $dateTokens = ['ngay' => 'd', 'thang' => 'm', 'nam' => 'Y', 'ngaythang' => 'dm', 'ngaythangnam' => 'dmY', 'namthangngay' => 'Ymd'];
            if (isset($dateTokens[$match[1]])) {
                return $sampleDate->format($dateTokens[$match[1]]);
            }
            if (! preg_match('/^([1-9][0-9]?)(chuso|chucai)$/D', $match[1], $parts) || (int) $parts[1] > 64) {
                $customSampleValid = false;
                return '';
            }
            $length = (int) $parts[1];
            $customRandomCount += $length;
            $seed = $parts[2] === 'chuso' ? '7391628450286174' : 'kmxpqnvrstbcdhfg';
            return substr(str_repeat($seed, 8), $customOrdinal++ % strlen($seed), $length);
        }, $urlRule['template']);
        $customSampleValid = $customSampleValid && strlen($urlRule['template']) <= 180 && $customRandomCount >= 1 && $customRandomCount <= 64
            && preg_match('~(?:^|/)_scope(?:/|$)~i', $urlRule['template']) !== 1
            && strlen($sampleKey) <= 240 && preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $sampleKey) === 1;
    }
    $sampleUrl = $customSampleValid ? $samplePrefix.'/'.$sampleKey.$sampleSuffix : 'Mẫu URL chưa hợp lệ — hãy dùng các nút biến bên dưới.';
@endphp
<div data-fanmulu-url-rule x-data="{
    preset: @js($preset), length: @js($urlRule['length']), group: @js($urlRule['group_length']), randomSeparator: @js($randomSeparator), dateSeparator: @js($dateSeparator), dateFormat: @js($urlRule['date_format']), sampleDate: @js($sampleDate->format('Y-m-d')), template: @js($urlRule['template']), insertError: '', refresh: 0,
    isDate() { return this.preset.startsWith('date_'); },
    mode() { return this.preset === 'legacy' ? 'legacy' : (this.preset === 'custom' ? 'tokens' : (this.isDate() ? 'pattern' : 'random')); },
    alphabet() { return this.isDate() ? this.preset.slice(5) : (['legacy', 'custom'].includes(this.preset) ? 'alnum' : this.preset); },
    formattedDate() {
        const [year, month, day] = this.sampleDate.split('-');
        return { Ymd: year + month + day, 'Y-m-d': year + '-' + month + '-' + day, 'Y/m/d': year + '/' + month + '/' + day, dmY: day + month + year, 'd-m-Y': day + '-' + month + '-' + year }[this.dateFormat] || year + month + day;
    },
    customSample() {
        if (typeof this.template !== 'string' || this.template.length > 180 || this.template.split('/').some(part => part.toLowerCase() === '_scope')) return null;
        const [year, month, day] = this.sampleDate.split('-');
        const dates = { ngay: day, thang: month, nam: year, ngaythang: day + month, ngaythangnam: day + month + year, namthangngay: year + month + day };
        let count = 0, ordinal = 0, valid = true;
        const key = this.template.replace(/\{([^{}]+)\}/g, (_, token) => {
            if (Object.hasOwn(dates, token)) return dates[token];
            const match = token.match(/^([1-9][0-9]?)(chuso|chucai)$/);
            if (!match || Number(match[1]) > 64) { valid = false; return ''; }
            const size = Number(match[1]);
            count += size;
            const seed = match[2] === 'chuso' ? '7391628450286174' : 'kmxpqnvrstbcdhfg';
            const start = ordinal++ % seed.length;
            return seed.repeat(8).slice(start, start + size);
        });
        return valid && count >= 1 && count <= 64 && key.length <= 240 && /^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/.test(key) ? key : null;
    },
    insertToken(token) {
        this.preset = 'custom';
        this.$nextTick(() => {
            const input = this.$refs.customTemplate;
            const start = input.selectionStart ?? this.template.length;
            const end = input.selectionEnd ?? start;
            const next = this.template.slice(0, start) + token + this.template.slice(end);
            if (next.length > 180) { this.insertError = 'Mẫu tối đa 180 ký tự.'; return; }
            this.insertError = '';
            this.template = next;
            input.value = next;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
            input.setSelectionRange(start + token.length, start + token.length);
        });
    },
    sample() {
        this.refresh;
        const form = this.$el.closest('form');
        const prefix = (form.elements.namedItem('fanmulu[prefix]')?.value || '/f').replace(/\/+$/, '');
        const suffix = form.elements.namedItem('fanmulu[suffix]')?.value || '';
        if (this.preset === 'legacy') return prefix + '/id-chu-de' + suffix;
        if (this.preset === 'custom') {
            const key = this.customSample();
            return key === null ? 'Mẫu URL chưa hợp lệ — hãy dùng các nút biến bên dưới.' : prefix + '/' + key + suffix;
        }
        const seed = { alnum: 'k7m2x9p4q6n3v8r5', letters: 'kmxpqnvrstbcdhfg', digits: '7391628450286174' }[this.alphabet()] || 'k7m2x9p4q6n3v8r5';
        const size = Math.max(8, Math.min(64, Number(this.length) || 16));
        let key = seed.repeat(4).slice(0, size);
        if (this.isDate()) key = this.formattedDate() + this.dateSeparator + key;
        else if (Number(this.group) >= 2) key = Array.from({ length: Math.ceil(key.length / this.group) }, (_, index) => key.slice(index * this.group, (index + 1) * this.group)).join(this.randomSeparator);
        return prefix + '/' + key + suffix;
    }
}" @input.window="refresh++" @change.window="refresh++">
    <h3>Quy tắc tạo URL fanmulu mới</h3>
    <label>Kiểu URL<select name="fanmulu[url_rule][preset]" x-model="preset">
        @foreach($presets as $value => $label)<option value="{{ $value }}" @selected($preset === $value)>{{ $label }}</option>@endforeach
    </select></label>
    <input type="hidden" name="fanmulu[url_rule][mode]" value="{{ $ruleMode }}" :value="mode()">
    <input type="hidden" name="fanmulu[url_rule][alphabet]" value="{{ $alphabet }}" :value="alphabet()" :disabled="['legacy', 'custom'].includes(preset)" @disabled(in_array($ruleMode, ['legacy', 'tokens'], true))>
    <div x-show="!['legacy', 'custom'].includes(preset)" @if(in_array($ruleMode, ['legacy', 'tokens'], true)) style="display:none" @endif>
        <label>Số ký tự ngẫu nhiên<input type="number" name="fanmulu[url_rule][length]" value="{{ $urlRule['length'] }}" x-model.number="length" min="8" max="64" :disabled="['legacy', 'custom'].includes(preset)" @disabled(in_array($ruleMode, ['legacy', 'tokens'], true))><small>Từ 8 đến 64 ký tự; phần ngày tháng được tính riêng.</small></label>
    </div>
    <div x-show="preset === 'custom'" @if($preset !== 'custom') style="display:none" @endif>
        <label>Mẫu URL tùy biến<input name="fanmulu[url_rule][template]" value="{{ $urlRule['template'] }}" x-ref="customTemplate" x-model="template" maxlength="180" placeholder="{ngaythangnam}/{6chucai}{4chuso}" :disabled="preset !== 'custom'" @disabled($preset !== 'custom')></label>
        <p class="muted">Đặt con trỏ vào mẫu rồi bấm biến để chèn tại vị trí đó. Có thể ghép nhiều biến, chữ cố định và dấu ngăn cách.</p>
        <div class="actions">
            @foreach(['{1chuso}', '{2chuso}', '{4chuso}', '{1chucai}', '{2chucai}', '{6chucai}', '{ngay}', '{thang}', '{nam}', '{ngaythang}', '{ngaythangnam}', '{namthangngay}', '-', '_', '/'] as $token)
                <button type="button" class="secondary" @click="insertToken(@js($token))">{{ $token }}</button>
            @endforeach
        </div>
        <p class="muted">Biến ngày lấy ngày tạo liên kết theo giờ Trung Quốc (UTC+8). Mẫu cần ít nhất một biến chữ hoặc số, tổng tối đa 64 ký tự ngẫu nhiên. Đường dẫn gốc và đuôi như .html chọn ở các trường riêng, không nhập vào mẫu.</p>
        <p class="muted">Nên dùng tổng từ 8 ký tự ngẫu nhiên khi tạo nhiều URL; mẫu quá ngắn có thể hết tổ hợp.</p>
        <p class="note" x-show="insertError" x-text="insertError" style="display:none"></p>
    </div>
    <div class="formrow" x-show="isDate()" @if(!$isDateStyle) style="display:none" @endif>
        <label>Dạng ngày<select name="fanmulu[url_rule][date_format]" x-model="dateFormat" :disabled="!isDate()" @disabled(!$isDateStyle)>
            @foreach(['Ymd' => 'Năm tháng ngày', 'Y-m-d' => 'Năm-tháng-ngày', 'Y/m/d' => 'Năm/tháng/ngày', 'dmY' => 'Ngày tháng năm', 'd-m-Y' => 'Ngày-tháng-năm'] as $value => $label)<option value="{{ $value }}" @selected($urlRule['date_format'] === $value)>{{ $label }} · {{ $sampleDate->format($value) }}</option>@endforeach
        </select></label>
        <label>Dấu giữa ngày và phần ngẫu nhiên<select name="fanmulu[url_rule][separator]" x-model="dateSeparator" :disabled="!isDate()" @disabled(!$isDateStyle)>
            @foreach(['' => 'Viết liền', '-' => 'Dấu -', '_' => 'Dấu _', '/' => 'Dấu / · thư mục riêng'] as $value => $label)<option value="{{ $value }}" @selected($dateSeparator === $value)>{{ $label }}</option>@endforeach
        </select></label>
    </div>
    <p class="muted" x-show="isDate()" @if(!$isDateStyle) style="display:none" @endif>Ngày được lấy một lần khi tạo liên kết, theo giờ Trung Quốc (UTC+8). Luôn kèm phần ngẫu nhiên để nhiều trang trong cùng ngày không trùng URL; URL đã lưu không đổi khi sang ngày mới.</p>
    <details x-show="mode() === 'random'" @if($ruleMode !== 'random') style="display:none" @endif @if($urlRule['group_length'] > 0) open @endif>
        <summary>Tùy chọn chia nhóm ký tự</summary>
        <div class="formrow">
            <label>Số ký tự mỗi nhóm<select name="fanmulu[url_rule][group_length]" x-model.number="group" :disabled="mode() !== 'random'" @disabled($ruleMode !== 'random')>
                @foreach([0, ...range(2, 16)] as $size)<option value="{{ $size }}" @selected($urlRule['group_length'] === $size)>{{ $size === 0 ? 'Viết liền, không chia nhóm' : $size.' ký tự mỗi nhóm' }}</option>@endforeach
            </select></label>
            <label>Dấu ngăn nhóm<select name="fanmulu[url_rule][separator]" x-model="randomSeparator" :disabled="mode() !== 'random'" @disabled($ruleMode !== 'random')><option value="-" @selected($randomSeparator === '-')>Dấu -</option><option value="_" @selected($randomSeparator === '_')>Dấu _</option></select></label>
        </div>
    </details>
    <p class="note">Ví dụ URL: <output class="code" data-fanmulu-url-example x-text="sample()">{{ $sampleUrl }}</output></p>
    <p class="muted">Chọn kiểu có sẵn hoặc tự ghép bằng các nút biến; không cần sửa JSON. Ví dụ chỉ minh họa, không tạo trang hay giữ keyword. Quy tắc mới chỉ dùng cho liên kết tạo sau khi lưu; URL, keyword và nội dung đã lưu được giữ nguyên.</p>
</div>
