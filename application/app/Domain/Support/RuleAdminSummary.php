<?php

namespace App\Domain\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only explanations of saved overrides; this never resolves or changes keyword assignments. */
final class RuleAdminSummary
{
    /** @return array<int, array{group:string,effect:string,warning:?string,kind_label:string,overrides_seo:bool}> */
    public function summarize(Collection $rules): array
    {
        $configs = [];
        $poolIds = $siteIds = [];
        $hasGlobal = false;
        foreach ($rules as $rule) {
            $config = $rule->config_json ?? null;
            $configs[$rule->id] = is_string($config) ? json_decode($config, true, 32, JSON_THROW_ON_ERROR) : $config;
            if (! $this->activeAllocation($rule, $configs[$rule->id])) {
                continue;
            }
            $poolIds = [...$poolIds, ...$this->ids($configs[$rule->id]['pool_ids'] ?? []), ...$this->ids($configs[$rule->id]['fallback_pool_ids'] ?? [])];
            if ($rule->site_id === null) {
                $hasGlobal = true;
            } else {
                $siteIds[] = (int) $rule->site_id;
            }
        }
        $poolIds = array_values(array_unique($poolIds));
        $pools = $poolIds === [] ? collect() : DB::table('keyword_pools')->whereIn('id', $poolIds)->get(['id', 'name', 'status', 'deleted_at'])->keyBy('id');
        $bindings = $poolIds === [] ? collect() : DB::table('keyword_bindings')->whereIn('pool_id', $poolIds)->where('status', 'active')
            ->when(! $hasGlobal, fn ($query) => $query->whereIn('site_id', array_values(array_unique($siteIds))))->get(['pool_id', 'site_id']);
        $bound = $anyBound = [];
        foreach ($bindings as $binding) {
            $bound[(int) $binding->site_id][(int) $binding->pool_id] = true;
            $anyBound[(int) $binding->pool_id] = true;
        }
        $result = [];
        foreach ($rules as $rule) {
            $slot = $rule->slot;
            $allocation = str_starts_with($slot, 'allocation.');
            $group = $allocation ? 'allocation' : (in_array($slot, ['managed_body', 'related_links'], true) ? 'links' : 'seo');
            $label = $this->label($slot);
            $config = $configs[$rule->id];
            if ($config === true && $group === 'links') {
                $config = ['enabled' => true];
            }
            $row = ['group' => $group, 'effect' => '', 'warning' => null, 'kind_label' => $label, 'overrides_seo' => false];
            if (($rule->status ?? 'active') === 'archived') {
                $row['effect'] = 'Đã lưu trữ; không áp dụng cho cấu hình mới.';
            } elseif (($rule->status ?? 'active') === 'paused') {
                $row['effect'] = 'Tạm dừng; không áp dụng cho cấu hình mới.';
            } elseif ($config === null) {
                $row['effect'] = 'Kế thừa cấu hình cấp trước.';
            } elseif ($config === false || is_array($config) && ($config['enabled'] ?? true) === false) {
                $row['effect'] = 'Tắt '.mb_strtolower($label).'.';
                $row['overrides_seo'] = $group === 'seo';
            } elseif (! is_array($config)) {
                $row['effect'] = 'Cấu hình cũ cần được kiểm tra trong trình sửa nâng cao.';
                $row['warning'] = 'Cấu hình hiện có không đúng định dạng được hỗ trợ.';
            } elseif ($allocation) {
                $count = $this->count($slot, $config);
                if ($count === 0) {
                    $row['effect'] = 'Không '.mb_strtolower($label).' (0 keyword).';
                } else {
                    $mode = ['weighted' => 'Theo trọng số', 'random-once' => 'Ngẫu nhiên một lần', 'sequential' => 'Theo thứ tự', 'manual' => 'Chọn thủ công'][$config['mode'] ?? 'weighted'] ?? 'Chế độ cũ';
                    $role = str_replace('Cấp ', '', $label);
                    $row['effect'] = 'Cấp tối đa '.$count.' '.$role.' · '.$mode.' · '.(($config['required'] ?? false) ? 'Bắt buộc đủ' : 'Không bắt buộc đủ');
                    $priorities = $this->ids($config['pool_ids'] ?? []);
                    $fallbacks = $this->ids($config['fallback_pool_ids'] ?? []);
                    $poolLabel = fn (int $id): string => ($pools->get($id)?->name ?? 'Kho').' #'.$id;
                    $row['effect'] .= ' · Kho ưu tiên: '.($priorities === [] ? 'không có' : implode(', ', array_map($poolLabel, $priorities)));
                    if ($fallbacks !== []) {
                        $row['effect'] .= ' · Dự phòng: '.implode(', ', array_map($poolLabel, $fallbacks));
                    }
                    $warnings = [];
                    if ($priorities === [] && $fallbacks === []) {
                        $warnings[] = 'Ngoại lệ chưa chọn kho keyword; không lấy được keyword từ cấu hình này.';
                    }
                    foreach (array_unique([...$priorities, ...$fallbacks]) as $id) {
                        $pool = $pools->get($id);
                        if (! $pool) {
                            $warnings[] = 'Kho #'.$id.' không tồn tại.';
                        } elseif ($pool->deleted_at !== null) {
                            $warnings[] = $poolLabel($id).' đã xóa.';
                        } elseif ($pool->status === 'archived') {
                            $warnings[] = $poolLabel($id).' đã lưu trữ.';
                        } elseif ($pool->status !== 'active') {
                            $warnings[] = $poolLabel($id).' đang tạm dừng.';
                        } elseif ($rule->site_id === null ? ! isset($anyBound[$id]) : ! isset($bound[(int) $rule->site_id][$id])) {
                            $warnings[] = $poolLabel($id).($rule->site_id === null ? ' chưa gắn với domain nào.' : ' không được gắn với domain #'.$rule->site_id.'.');
                        }
                    }
                    $row['warning'] = $warnings === [] ? null : implode(' ', $warnings);
                }
            } elseif ($slot === 'managed_body') {
                $anchors = max(0, (int) ($config['max_anchors'] ?? 3));
                $perKeyword = max(0, (int) ($config['per_keyword'] ?? 1));
                $row['effect'] = $anchors === 0 || $perKeyword === 0 ? 'Không chèn liên kết trong nội dung.'
                    : 'Chèn tối đa '.$anchors.' liên kết anchor đã gán; mỗi keyword tối đa '.$perKeyword.' lần.';
            } elseif ($slot === 'related_links') {
                $row['effect'] = 'Hiển thị liên kết keyword anchor đã gán, có URL đích an toàn.';
            } else {
                $row['effect'] = 'Dùng công thức riêng cho '.mb_strtolower($label).'.';
                $row['overrides_seo'] = true;
                $row['warning'] = 'Ghi đè công thức trong Template SEO.';
            }
            $result[(int) $rule->id] = $row;
        }

        return $result;
    }

    private function activeAllocation(object $rule, mixed $config): bool
    {
        return ($rule->status ?? 'active') === 'active' && str_starts_with($rule->slot, 'allocation.')
            && is_array($config) && ($config['enabled'] ?? true) !== false && $this->count($rule->slot, $config) > 0;
    }

    private function count(string $slot, array $config): int
    {
        return max(0, min(in_array($slot, ['allocation.primary', 'allocation.fanmulu_title'], true) ? 1 : 20, (int) ($config['count'] ?? 1)));
    }

    private function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('intval', array_filter($value, fn ($id): bool => (is_int($id) || is_string($id))
            && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false))) : [];
    }

    private function label(string $slot): string
    {
        return ['allocation.primary' => 'Cấp keyword chính', 'allocation.secondary' => 'Cấp keyword phụ', 'allocation.anchor' => 'Cấp keyword anchor',
            'allocation.fanmulu_title' => 'Cấp keyword tên fanmulu', 'title' => 'Tiêu đề SEO', 'description' => 'Mô tả SEO',
            'meta_keywords' => 'Meta keywords', 'h1' => 'Tiêu đề H1', 'intro' => 'Đoạn giới thiệu',
            'managed_body' => 'Chèn liên kết trong nội dung', 'related_links' => 'Liên kết liên quan'][$slot]
            ?? (str_starts_with($slot, 'h2.') ? 'Tiêu đề H2 · '.substr($slot, 3) : $slot);
    }
}
