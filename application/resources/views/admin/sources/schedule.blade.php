@php
    $scheduleValues = App\Domain\Ingestion\SourceSchedule::values($source ? App\Domain\Ingestion\SourceSchedule::forSource($source) : []);
    $scheduleMode = $isOldSourceForm && is_string(old('schedule_mode')) && in_array(old('schedule_mode'), ['off','daily','interval'], true) ? old('schedule_mode') : $scheduleValues['mode'];
    $scheduleTime = $isOldSourceForm && is_string(old('schedule_daily_time')) ? old('schedule_daily_time') : $scheduleValues['time'];
    $scheduleTimezone = $isOldSourceForm && is_string(old('schedule_timezone')) ? old('schedule_timezone') : $scheduleValues['timezone'];
    $scheduleInterval = $isOldSourceForm && is_scalar(old('schedule_interval_minutes')) ? old('schedule_interval_minutes') : $scheduleValues['interval'];
    $scheduleRemote = in_array($createKind, ['maccms','shandian','html'], true);
    $scheduleTimezones = ['Asia/Shanghai'=>'Trung Quốc · UTC+8', 'Asia/Ho_Chi_Minh'=>'Việt Nam · UTC+7', 'UTC'=>'UTC · UTC+0'];
@endphp
<section id="schedule" style="margin:20px 0" x-data="{ scheduleMode: @js($scheduleRemote ? $scheduleMode : 'off') }" x-init="$watch('remote', value => { if (!value) scheduleMode = 'off' })">
    <h2>Tự động cập nhật nguồn</h2>
    <input type="hidden" name="schedule_mode" value="off" @disabled($scheduleRemote) :disabled="remote">
    <label>Chế độ cập nhật
        <select name="schedule_mode" x-model="scheduleMode" @disabled(!$scheduleRemote) :disabled="!remote">
            <option value="off" @selected(!$scheduleRemote || $scheduleMode === 'off')>Tắt tự động cập nhật</option>
            <option value="daily" @selected($scheduleRemote && $scheduleMode === 'daily')>Hằng ngày · chọn giờ chạy</option>
            <option value="interval" @selected($scheduleRemote && $scheduleMode === 'interval')>Theo chu kỳ · số phút</option>
        </select>
    </label>
    <p class="muted" x-show="!remote" @if($scheduleRemote) x-cloak @endif>Adapter nhập file hoặc fixture không có lịch crawl. Chọn API MacCMS, Shandian hoặc HTML để dùng cập nhật tự động.</p>
    <div class="formrow" x-show="remote && scheduleMode === 'daily'" @if(!$scheduleRemote || $scheduleMode !== 'daily') x-cloak @endif>
        <label>Giờ cập nhật mỗi ngày
            <input type="time" name="schedule_daily_time" value="{{ $scheduleTime }}" step="60" @disabled(!$scheduleRemote || $scheduleMode !== 'daily') :disabled="!remote || scheduleMode !== 'daily'" :required="remote && scheduleMode === 'daily'">
        </label>
        <label>Múi giờ của lịch
            <select name="schedule_timezone" @disabled(!$scheduleRemote || $scheduleMode !== 'daily') :disabled="!remote || scheduleMode !== 'daily'" :required="remote && scheduleMode === 'daily'">
                @foreach($scheduleTimezones as $timezone=>$label)<option value="{{ $timezone }}" @selected($scheduleTimezone === $timezone)>{{ $label }}</option>@endforeach
            </select>
        </label>
    </div>
    <div x-show="remote && scheduleMode === 'interval'" @if(!$scheduleRemote || $scheduleMode !== 'interval') x-cloak @endif>
        <label>Khoảng cách giữa các lượt cập nhật (phút)
            <input type="number" name="schedule_interval_minutes" value="{{ $scheduleInterval ?: 1440 }}" min="5" max="10080" @disabled(!$scheduleRemote || $scheduleMode !== 'interval') :disabled="!remote || scheduleMode !== 'interval'" :required="remote && scheduleMode === 'interval'">
        </label>
        <small>Tối thiểu 5 phút. Muốn chạy cố định cùng một giờ mỗi ngày, chọn Hằng ngày.</small>
    </div>
    <input type="hidden" name="schedule_interval_minutes" value="0" @disabled($scheduleRemote && $scheduleMode === 'interval') :disabled="remote && scheduleMode === 'interval'">
    <p class="muted" x-show="remote && scheduleMode !== 'off'" @if(!$scheduleRemote || $scheduleMode === 'off') x-cloak @endif>Mỗi lượt chỉ lấy 3–5 trang mới nhất từ trang 1, theo số trang chọn ở phần Kết nối nguồn. Không tự lấy lại toàn bộ. Nếu nguồn đang crawl, lịch chờ lượt đó xong; một ngày chỉ xếp một lượt khi dùng lịch hằng ngày.</p>
    <small x-show="remote" @if(!$scheduleRemote) x-cloak @endif>Lịch chỉ chạy khi nguồn đang hoạt động. Nếu bật lịch sau giờ đã chọn, hệ thống có thể xếp lượt của hôm nay ngay. Cách đưa phim lên website vẫn theo cài đặt nguồn của từng domain.</small>
</section>
