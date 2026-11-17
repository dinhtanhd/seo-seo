<input type="hidden" name="source_form_present" value="1">
<input type="hidden" name="source_form_id" value="{{ $sourceFormId }}">
<fieldset class="configuration-source-fields" x-show="remote" :disabled="!remote" style="border:0;padding:0;margin:0">
    <legend><strong>Kết nối nguồn</strong></legend>
    <label>Endpoint HTTPS, chưa gồm tham số query
        <input type="url" name="source_form[endpoint]" x-model="endpoint" @blur="splitEndpointQuery()" value="{{ $sourceFormValues['endpoint'] ?? '' }}" maxlength="4096" :required="remote" placeholder="https://nguon.example.com/api.php/provide/vod/">
    </label>
    <div class="formrow">
        <div><label>Hostname được phép · mỗi dòng một hostname
            <textarea name="source_form[allowed_hosts]" x-model="allowedHosts" rows="3" maxlength="10000" placeholder="xsd.sdzyapi.com">{{ $sourceFormValues['allowed_hosts'] ?? '' }}</textarea>
        </label><button type="button" class="secondary" @click="addEndpointHost()">Thêm hostname từ endpoint</button><small x-text="hostMessage" role="status"></small><small>Ghi đúng hostname, không URL hoặc wildcard. Redirect chỉ được đi tới hostname trong danh sách này.</small></div>
        <div><label>Port được phép · mỗi dòng một số
            <textarea name="source_form[allowed_ports]" rows="3" maxlength="200">{{ $sourceFormValues['allowed_ports'] ?? '443' }}</textarea>
        </label><small>HTTPS thường dùng 443. Địa chỉ nội bộ và DNS nội bộ bị chặn khi kết nối.</small></div>
    </div>
    <div class="formrow three">
        <div><label>Múi giờ của nguồn<input name="source_form[source_timezone]" value="{{ $sourceFormValues['source_timezone'] ?? 'Asia/Shanghai' }}" maxlength="100" required placeholder="Asia/Shanghai"></label></div>
        <div><label>Số trang mới nhất mỗi lần cập nhật<select name="latest_max_pages">@foreach([3,4,5] as $pageCount)<option value="{{ $pageCount }}" @selected((string)($oldSourceLatestPages ?? 3)===(string)$pageCount)>{{ $pageCount }} trang mới nhất</option>@endforeach</select></label><small>Lịch tự động luôn lấy từ trang 1 với số trang này. Lấy toàn bộ lần đầu dùng thao tác riêng phía trên.</small></div>
        <div><label>Khoảng cách yêu cầu cùng host (ms)<input type="number" name="source_form[min_host_interval_ms]" value="{{ $sourceFormValues['min_host_interval_ms'] ?? 1000 }}" min="250" max="5000" required></label></div>
    </div>
    <div class="formrow three">
        <div><label>Timeout toàn yêu cầu (giây)<input type="number" name="source_form[total_timeout]" value="{{ $sourceFormValues['total_timeout'] ?? 25 }}" min="1" max="60" required></label></div>
        <div><label>Số redirect tối đa<input type="number" name="source_form[max_redirects]" value="{{ $sourceFormValues['max_redirects'] ?? 2 }}" min="0" max="5" required></label></div>
        <div><label>Kích thước phản hồi tối đa (byte)<input type="number" name="source_form[max_bytes]" value="{{ $sourceFormValues['max_bytes'] ?? 5242880 }}" min="1024" max="20971520" required></label></div>
    </div>
    <details>
        <summary>Tham số query · <span x-text="queryRows.length"></span> dòng</summary>
        <input type="hidden" name="source_form[query_rows_present]" value="1">
        <p class="muted">Các giá trị được mã hóa vào URL. MacCMS tự gửi ac, pg, h và t từ chức năng crawl và hai ô lọc bên dưới.</p>
        <div class="tablewrap"><table><thead><tr><th>Tên tham số</th><th>Giá trị</th><th>Thao tác</th></tr></thead><tbody>
            <template x-for="(row,index) in queryRows" :key="row.uid"><tr>
                <td><input :name="'source_form[query_rows]['+index+'][key]'" x-model="row.key" maxlength="150" aria-label="Tên tham số query" placeholder="token"></td>
                <td><input :name="'source_form[query_rows]['+index+'][value]'" x-model="row.value" maxlength="2000" aria-label="Giá trị tham số query"></td>
                <td><button class="secondary" type="button" @click="remove(queryRows,row.uid)">Xóa dòng</button></td>
            </tr></template>
        </tbody></table></div>
        <button type="button" class="secondary" @click="addQuery()" :disabled="queryRows.length>=100">Thêm tham số</button>
    </details>
</fieldset>
<fieldset class="configuration-source-fields" x-show="macCms" :disabled="!macCms" style="border:0;padding:0;margin:16px 0">
    <legend><strong>Bộ lọc API MacCMS / Shandian</strong></legend>
    <div class="formrow">
        <div><label>Chỉ lấy cập nhật trong số giờ gần nhất<input type="number" name="source_form[hours]" value="{{ $sourceFormValues['hours'] ?? '' }}" min="1" max="720" placeholder="Để trống: không lọc giờ"></label></div>
        <div><label>Giới hạn một type_id trên API<input type="number" name="source_form[category_id]" value="{{ $sourceFormValues['category_id'] ?? '' }}" min="1" max="2147483647" placeholder="Để trống: nhận mọi loại từ API"></label></div>
    </div>
    <p class="muted">Đây là bộ lọc nguồn chung. Muốn nhận nhiều thể loại và phân chia theo từng website, để trống type_id rồi dùng “Chọn thể loại từ API & ghép danh mục”.</p>
    <label>MIME JSON được chấp nhận · mỗi dòng một giá trị
        <textarea name="source_form[json_mime_types]" rows="3" maxlength="2000">{{ $sourceFormValues['json_mime_types'] ?? '' }}</textarea>
    </label><small>Shandian hiện trả JSON với MIME text/html, nên allowlist của nguồn này có text/html.</small>
</fieldset>
<fieldset class="configuration-source-fields" x-show="kind==='html'" :disabled="kind!=='html'" style="border:0;padding:0;margin:16px 0">
    <legend><strong>Selector HTML</strong></legend>
    <div class="formrow three">
        <div><label>Kiểu selector<select name="source_form[selector_type]">@foreach(['css'=>'CSS','xpath'=>'XPath'] as $value=>$label)<option value="{{ $value }}" @selected(($sourceFormValues['selector_type'] ?? 'css')===$value)>{{ $label }}</option>@endforeach</select></label></div>
        <div><label>Selector của mỗi bản ghi<input name="source_form[record_selector]" value="{{ $sourceFormValues['record_selector'] ?? '' }}" maxlength="1000" :required="kind==='html'" placeholder=".movie-item"></label></div>
        <div><label>Selector liên kết trang tiếp theo<input name="source_form[next_selector]" value="{{ $sourceFormValues['next_selector'] ?? '' }}" maxlength="1000" placeholder="a.next"></label></div>
    </div>
    <label>Loại nội dung<select name="source_form[content_kind]">@foreach(['movie'=>'Phim','novel'=>'Tiểu thuyết','episode'=>'Tập phim','chapter'=>'Chương','editorial'=>'Bài viết'] as $value=>$label)<option value="{{ $value }}" @selected(($sourceFormValues['content_kind'] ?? 'movie')===$value)>{{ $label }}</option>@endforeach</select></label>
    <input type="hidden" name="source_form[field_rows_present]" value="1">
    <p class="muted">Selector của từng trường được áp dụng trong bản ghi. Bắt buộc có external_id và title. Thuộc tính dùng để lấy href, src hoặc data-id; để trống thuộc tính để lấy văn bản hoặc HTML đã được làm sạch khi nhập.</p>
    <div class="tablewrap"><table><thead><tr><th>Trường đích</th><th>Selector</th><th>Thuộc tính</th><th>Kiểu lấy</th><th>Thao tác</th></tr></thead><tbody>
        <template x-for="(row,index) in fieldRows" :key="row.uid"><tr>
            <td><input :name="'source_form[field_rows]['+index+'][field]'" x-model="row.field" maxlength="100" aria-label="Trường đích HTML" placeholder="title"></td>
            <td><input :name="'source_form[field_rows]['+index+'][selector]'" x-model="row.selector" maxlength="1000" aria-label="Selector trường HTML" placeholder="h2"></td>
            <td><input :name="'source_form[field_rows]['+index+'][attribute]'" x-model="row.attribute" maxlength="100" aria-label="Thuộc tính HTML" placeholder="src"></td>
            <td><select :name="'source_form[field_rows]['+index+'][mode]'" x-model="row.mode" aria-label="Kiểu lấy trường HTML"><option value="text">Văn bản</option><option value="html">HTML</option></select></td>
            <td><button type="button" class="secondary" @click="remove(fieldRows,row.uid)">Xóa dòng</button></td>
        </tr></template>
    </tbody></table></div>
    <button type="button" class="secondary" @click="addField()" :disabled="fieldRows.length>=100">Thêm trường HTML</button>
</fieldset>
<details style="margin:16px 0">
    <summary>Ghép cột/key khi nhập file JSON hoặc CSV · <span x-text="fileMappingRows.length"></span> dòng</summary>
    <input type="hidden" name="source_form[file_mapping_rows_present]" value="1">
    <p class="muted">Tên cột/key nguồn là tên trực tiếp trong file, không đường dẫn lồng nhau. Nếu file đã dùng external_id, kind, title và các trường chuẩn thì không cần ghép. Dữ liệu đích sẽ thay giá trị cùng trường khi key nguồn có trong bản ghi.</p>
    <div class="tablewrap"><table><thead><tr><th>Trường đích của Trạm SEO</th><th>Cột/key trong file</th><th>Thao tác</th></tr></thead><tbody>
        <template x-for="(row,index) in fileMappingRows" :key="row.uid"><tr>
            <td><input :name="'source_form[file_mapping_rows]['+index+'][canonical]'" x-model="row.canonical" maxlength="100" aria-label="Trường đích nhập file" placeholder="external_id"></td>
            <td><input :name="'source_form[file_mapping_rows]['+index+'][source]'" x-model="row.source" maxlength="200" aria-label="Cột hoặc key nguồn trong file" placeholder="vod_id"></td>
            <td><button type="button" class="secondary" @click="remove(fileMappingRows,row.uid)">Xóa dòng</button></td>
        </tr></template>
    </tbody></table></div>
    <button type="button" class="secondary" @click="addFileMapping()" :disabled="fileMappingRows.length>=100">Thêm mapping cột</button>
    <small>Các trường thường dùng: external_id, kind, title, genre, year, area, language, remarks, actors, directors, body_html, poster_url, source_url, episodes.</small>
</details>
<div class="note" x-show="!remote">Adapter này nhập metadata từ file JSON/CSV; không có thao tác crawl từ endpoint.</div>
<div class="note" x-show="kind==='fixture'">Fixture dành cho seed, kiểm thử hoặc demo. Không coi fixture là nguồn crawl production hoặc bằng chứng nhận dữ liệu thật.</div>
<small>Các khóa cấu hình khác đang lưu trên máy chủ được giữ khi lưu form này.</small>
