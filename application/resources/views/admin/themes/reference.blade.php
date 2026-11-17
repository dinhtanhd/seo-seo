<section class="card theme-reference">
    <h2>Biến và vòng lặp</h2>
    <p class="muted">Chọn file home/list/detail/play/search hoặc CSS rồi bấm biến để chèn tại vị trí con trỏ. Nội dung phim lấy từ dữ liệu gốc; SEO dùng assignment đã lưu. Biến URL và player dùng cấu hình của website trong snapshot.</p>
    @foreach($variableGroups as $group => $variables)
        <h3>{{ $group }}</h3>
        <div class="actions">
            @foreach($variables as $expression => $description)
                <button type="button" class="secondary code" @click="insert(@js($expression))" title="{{ $description }}" :disabled="{{ auth()->user()->is_global_admin ? 'false' : 'true' }} || (selectedField === 'css' && {{ str_starts_with($expression, '{'.'{asset.') ? 'false' : 'true' }})">{{ $expression }}</button>
            @endforeach
        </div>
    @endforeach
    <p class="note">Giá trị thông thường được escape. HTML nội dung phải dùng đúng vùng được engine hỗ trợ. @if($hasReviewedRuntime ?? false)Bộ này chỉ chạy các file JavaScript đã duyệt trong manifest. Không hỗ trợ PHP, Blade, script chèn trực tiếp hoặc JavaScript tùy ý.@else Gói giao diện không chạy PHP, Blade hoặc JavaScript.@endif</p>
</section>
