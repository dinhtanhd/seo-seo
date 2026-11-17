<?php

namespace App\Http\Controllers;

use App\Domain\Ingestion\SourceTypeService;
use App\Domain\Sites\MovieCategoryPresetService;
use App\Domain\Support\Access;
use App\Domain\Support\AdminSiteLabels;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Json;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SourceTypeController extends Controller
{
    private function context(Request $request, int $id, bool $write = false): array
    {
        $source = DB::table('source_connectors')->whereNull('deleted_at')->find($id);
        abort_unless($source, 404);
        $request->validate(['site_id' => 'nullable|integer|min:1']);
        if ($write && ! $request->filled('site_id')) {
            throw new \InvalidArgumentException('Chọn rõ tên miền nhận dữ liệu trước khi lưu chính sách thể loại.');
        }
        $sites = CatalogAvailability::filter(DB::table('sites'), 'sites')->whereIn('id', DB::table('source_site_bindings')->where('connector_id', $id)->whereNull('deleted_at')->select('site_id'))
            ->when(! $request->user()->is_global_admin, fn ($query) => $query->whereIn('id', DB::table('site_memberships')->where('user_id', $request->user()->id)->select('site_id')))->orderBy('id')->get();
        $sites = app(AdminSiteLabels::class)->sortSites($sites);
        $siteId = $request->integer('site_id') ?: $sites->first()?->id;
        abort_unless($siteId && $sites->contains('id', $siteId), 403, 'Nguồn chưa được gắn vào website có quyền truy cập.');
        Access::site($request->user()->id, $siteId);
        $site = $sites->firstWhere('id', $siteId);
        $role = DB::table('site_memberships')->where('site_id', $siteId)->where('user_id', $request->user()->id)->value('role');
        $canWrite = $request->user()->is_global_admin || $role === 'owner';
        abort_unless(! $write || $canWrite, 403, 'Chỉ owner website hoặc quản trị toàn hệ thống được lưu lựa chọn thể loại.');

        return compact('source', 'sites', 'site', 'canWrite') + ['scope' => '?site_id='.$siteId];
    }

    public function show(Request $request, int $id, SourceTypeService $service)
    {
        $context = $this->context($request, $id);
        $catalog = $service->catalog($id, $request->user()->id);
        $configuration = $service->configuration($id, $context['site']->id, $request->user()->id);
        $categories = CatalogAvailability::filter(DB::table('categories'), 'categories')->where('site_id', $context['site']->id)->orderBy('id')->get(['id', 'name', 'parent_id']);
        $categoryOptions = $this->treeRows($categories->all());
        $sourceTypeRows = $this->treeRows($catalog['types']);
        $categorySuggestions = MovieCategoryPresetService::suggestedMappings($catalog['types'], $categories);
        $sourceConfig = Json::decode($context['source']->config_json);
        $hasCatalog = ! empty($catalog['fetched_at']) && ! empty($catalog['hash']);
        $catalogStaleMessage = $hasCatalog && $catalog['config_hash'] !== $catalog['current_config_hash']
            ? 'Cấu hình nguồn đã thay đổi. Cập nhật danh sách API trước khi lưu.'
            : 'Danh sách API đã quá 24 giờ. Cập nhật danh sách trước khi lưu.';
        $formContext = 'source-types:'.$id.':'.$context['site']->id;
        $hasOldForm = $request->old('form_context') === $formContext;
        $oldSelected = $hasOldForm ? $request->old('selected_type_ids', []) : $configuration['selected_type_ids'];
        $oldMap = $hasOldForm ? $request->old('category_map', []) : $configuration['category_map'];
        $oldMode = $hasOldForm ? $request->old('mode') : $configuration['mode'];
        $oldVersion = $hasOldForm ? $request->old('mapping_version', $configuration['mapping_version']) : $configuration['mapping_version'];
        $formState = [
            'mode' => in_array($oldMode, ['all', 'selected'], true) ? $oldMode : $configuration['mode'],
            'selected_type_ids' => is_array($oldSelected) ? array_values(array_filter($oldSelected, 'is_scalar')) : [],
            'category_map' => is_array($oldMap) ? array_filter($oldMap, fn ($value) => $value === null || is_scalar($value)) : [],
            'mapping_version' => is_scalar($oldVersion) ? $oldVersion : '',
        ];
        $savedTypeIds = array_unique(array_merge($configuration['selected_type_ids'], $formState['selected_type_ids'], array_keys(array_filter($configuration['category_map'], fn ($value) => is_numeric($value) && (int) $value > 0)), array_keys(array_filter($formState['category_map'], fn ($value) => is_numeric($value) && (int) $value > 0))));
        $missingSavedTypeIds = $hasCatalog ? array_values(array_diff($savedTypeIds, array_column($catalog['types'], 'id'))) : [];

        return view('admin.source-types', $context + compact('catalog', 'configuration', 'categories', 'categoryOptions', 'sourceTypeRows', 'sourceConfig', 'hasCatalog', 'missingSavedTypeIds', 'categorySuggestions', 'formContext', 'formState', 'catalogStaleMessage'));
    }

    /** Keep each parent adjacent to its descendants, including malformed API hierarchies exactly once. */
    private function treeRows(array $nodes): array
    {
        $indexed = [];
        foreach ($nodes as $node) {
            $node = (array) $node;
            $key = 'id:'.(string) $node['id'];
            $indexed[$key] ??= $node;
        }
        $children = [];
        $roots = [];
        foreach ($indexed as $key => $node) {
            $parentId = $node['parent_id'] ?? null;
            $parentKey = 'id:'.(string) $parentId;
            if ($parentId === null || (string) $parentId === '0' || $parentId === '' || $parentKey === $key || ! isset($indexed[$parentKey])) {
                $roots[] = $key;
            } else {
                $children[$parentKey][] = $key;
            }
        }
        $rows = [];
        $visited = [];
        foreach ([...$roots, ...array_keys($indexed)] as $root) {
            $stack = [[$root, []]];
            while ($stack !== []) {
                [$key, $ancestors] = array_pop($stack);
                if (isset($visited[$key])) {
                    continue;
                }
                $visited[$key] = true;
                $node = $indexed[$key];
                $names = [...$ancestors, (string) $node['name']];
                $rows[] = ['node' => $node, 'depth' => count($ancestors), 'label' => implode(' → ', $names), 'ancestors' => $ancestors];
                foreach (array_reverse($children[$key] ?? []) as $child) {
                    $stack[] = [$child, $names];
                }
            }
        }

        return $rows;
    }

    public function refresh(Request $request, int $id, SourceTypeService $service)
    {
        $context = $this->context($request, $id, true);
        $formInput = null;
        if ($request->has('mode')) {
            $data = $request->validate([
                'mapping_version' => 'required|integer|min:1', 'mode' => 'required|in:all,selected',
                'selected_type_ids' => 'nullable|array|max:5000', 'selected_type_ids.*' => 'required|string|max:100',
                'category_map' => 'nullable|array|max:5000', 'category_map.*' => 'nullable|integer|min:1',
            ]);
            $formInput = $data + ['selected_type_ids' => [], 'category_map' => []];
            $formInput['form_context'] = 'source-types:'.$id.':'.$context['site']->id;
            $formInput['site_id'] = $context['site']->id;
            $request->session()->flashInput($formInput);
        }
        $result = $service->refresh($id, $request->user()->id);
        $redirect = redirect('/admin/sources/'.$id.'/types?site_id='.$context['site']->id);
        if ($formInput !== null) {
            $redirect->withInput($formInput);
        }
        if (! empty($result['error'])) {
            return $redirect->withErrors(['catalog_refresh' => 'Chưa cập nhật được danh sách API: '.$result['error']]);
        }
        if (! $result['supported']) {
            return $redirect->withErrors(['catalog_refresh' => 'Adapter của nguồn này chưa hỗ trợ danh sách thể loại API.']);
        }

        return $redirect->with('status', 'Đã cập nhật '.count($result['types']).' thể loại từ API. Chưa thay đổi lựa chọn nhận dữ liệu.');
    }

    public function save(Request $request, int $id, SourceTypeService $service)
    {
        $context = $this->context($request, $id, true);
        $data = $request->validate([
            'mapping_version' => 'required|integer|min:1', 'catalog_hash' => 'required|string|size:64',
            'mode' => 'required|in:all,selected', 'selected_type_ids' => 'nullable|array',
            'selected_type_ids.*' => 'required|string|max:100', 'category_map' => 'nullable|array',
            'category_map.*' => 'nullable|integer|min:1',
        ]);
        $data['selected_type_ids'] = $data['selected_type_ids'] ?? [];
        $data['category_map'] = $data['category_map'] ?? [];
        $service->saveSelection($id, $context['site']->id, $data, $request->user()->id);

        return redirect('/admin/sources/'.$id.'/types?site_id='.$context['site']->id)->with('status', 'Đã lưu lựa chọn cho website này. Chính sách áp dụng cho lượt nhận dữ liệu tiếp theo; snapshot live giữ nguyên.');
    }

    public function completeCategories(Request $request, int $id, SourceTypeService $service)
    {
        $context = $this->context($request, $id, true);
        $result = $service->completeCategories($id, (int) $context['site']->id,
            $request->only(['mapping_version', 'catalog_hash', 'mode', 'selected_type_ids', 'category_map']), (int) $request->user()->id);

        return redirect('/admin/sources/'.$id.'/types?site_id='.$context['site']->id)
            ->withInput($result['form'] + ['form_context' => 'source-types:'.$id.':'.$context['site']->id, 'site_id' => $context['site']->id])
            ->with('status', 'Đã thêm '.count($result['created']).' danh mục và điền '.$result['filled'].' ô ghép còn trống. Chưa lưu chính sách nguồn; kiểm tra rồi nhấn “Lưu lựa chọn & ghép danh mục”.');
    }
}
