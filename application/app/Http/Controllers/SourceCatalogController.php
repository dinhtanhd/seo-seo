<?php

namespace App\Http\Controllers;

use App\Domain\Ingestion\SourceCatalogService;
use App\Domain\Support\AdminSiteLabels;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Json;
use App\Domain\Support\SourceConfigurationForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SourceCatalogController extends Controller
{
    public function index(Request $request, SourceCatalogService $service): View
    {
        $context = $this->context($request, $service);
        $filters = $request->validate(['q' => 'nullable|string|max:200', 'kind' => 'nullable|in:maccms,shandian,json,csv,html,fixture', 'status' => 'nullable|in:active,paused,archived', 'deleted' => 'nullable|in:active,trash,all']);
        $filters += ['q' => '', 'kind' => '', 'status' => '', 'deleted' => 'active'];
        foreach ($filters as $key => $value) {
            $filters[$key] = $value ?? ($key === 'deleted' ? 'active' : '');
        }
        $context['sources'] = $context['sources']->filter(function ($source) use ($filters, $context): bool {
            $deleted = $source->deleted_at !== null;

            return (! $context['canManageSources'] || $filters['deleted'] === 'all' || ($filters['deleted'] === 'trash' ? $deleted : ! $deleted))
                && ($filters['q'] === '' || mb_stripos($source->name.' '.$source->id, $filters['q']) !== false)
                && ($filters['kind'] === '' || $source->kind === $filters['kind'])
                && ($filters['status'] === '' || $source->status === $filters['status']);
        })->values();

        return view('admin.sources', $context + compact('filters'));
    }

    public function create(Request $request, SourceCatalogService $service): View
    {
        $service->authorizeManagement($request->user()->id);

        return view('admin.sources.editor', $this->editorContext(null) + $this->managementContext());
    }

    public function edit(Request $request, int $id, SourceCatalogService $service): View
    {
        $source = $service->sourceForManagement($id, $request->user()->id);

        return view('admin.sources.editor', $this->editorContext($source) + $this->managementContext());
    }

    public function show(Request $request, int $id, SourceCatalogService $service): View
    {
        $context = $this->context($request, $service, $id);
        $source = $context['sources']->firstWhere('id', $id);
        abort_unless($source && $source->deleted_at === null, 404);
        $context['sources'] = collect([$source]);
        $context['runs'] = $context['runs']->where('connector_id', $id);
        $context['quarantine'] = $context['quarantine']->whereIn('run_id', $context['runs']->pluck('id'));

        return view('admin.sources.workspace', $context + ['selectedSource' => $source]);
    }

    public function history(Request $request, SourceCatalogService $service): View
    {
        return view('admin.sources.history', $this->context($request, $service));
    }

    private function editorContext(?object $source): array
    {
        $sourceFormId = $source ? 'source:'.$source->id : 'create';
        $isOldSourceForm = old('source_form_id') === $sourceFormId;
        $createKind = $source?->kind ?? ($isOldSourceForm && is_string(old('kind')) ? old('kind') : 'shandian');
        if (! in_array($createKind, ['maccms', 'shandian', 'json', 'csv', 'html', 'fixture'], true)) {
            $createKind = 'shandian';
        }
        $configuration = $source ? Json::decode($source->config_json) : [];
        $sourceFormValues = SourceConfigurationForm::values($configuration, $createKind);
        if ($isOldSourceForm && is_array(old('source_form'))) {
            $sourceFormValues = array_replace($sourceFormValues, array_intersect_key(old('source_form'), $sourceFormValues));
            foreach ($sourceFormValues as $key => $value) {
                if (! in_array($key, ['query_rows', 'field_rows', 'file_mapping_rows'], true) && ! is_scalar($value) && $value !== null) {
                    $sourceFormValues[$key] = '';
                }
            }
        }
        $oldSourceLatestPages = $isOldSourceForm && is_scalar(old('latest_max_pages')) ? old('latest_max_pages') : ($source->latest_max_pages ?? 3);

        return compact('source', 'sourceFormId', 'isOldSourceForm', 'createKind', 'configuration', 'sourceFormValues', 'oldSourceLatestPages');
    }

    private function managementContext(): array
    {
        return ['site' => null, 'sites' => collect(), 'globalManagement' => true, 'managementLabel' => 'Cài đặt nguồn dùng chung'];
    }

    private function context(Request $request, SourceCatalogService $service, ?int $connectorId = null): array
    {
        $actor = $request->user();
        $canManageSources = $service->canManage($actor->id);
        $sources = $service->catalog($actor->id, $canManageSources);
        $sourceBindingRows = CatalogAvailability::filter(DB::table('source_site_bindings as binding')->join('sites', 'sites.id', '=', 'binding.site_id'), 'sites')
            ->whereNull('binding.deleted_at')
            ->when(! $canManageSources, fn ($query) => $query->whereIn('binding.site_id', DB::table('site_memberships')->where('user_id', $actor->id)->select('site_id')))
            ->select('binding.*')->orderBy('binding.id')->get();
        $bindingLabels = app(AdminSiteLabels::class)->labels($sourceBindingRows->pluck('site_id'));
        $sourceBindings = $sourceBindingRows->sort(fn ($left, $right) => strcasecmp($bindingLabels->get($left->site_id, ''), $bindingLabels->get($right->site_id, '')) ?: $left->id <=> $right->id)->groupBy('connector_id');
        $operateSites = DB::table('site_memberships')->where('user_id', $actor->id)->whereIn('role', ['owner', 'operator'])->pluck('site_id');
        $activeBindings = DB::table('source_site_bindings')->where('status', 'active')->whereNull('deleted_at')->get()->groupBy('connector_id');
        $sourceCanOperate = $sources->mapWithKeys(function ($source) use ($canManageSources, $activeBindings, $operateSites) {
            $bindings = $activeBindings[$source->id] ?? collect();

            return [$source->id => $canManageSources || ($bindings->isNotEmpty() && $bindings->every(fn ($binding) => $operateSites->contains($binding->site_id)))];
        })->all();
        $runs = DB::table('ingest_runs')->whereIn('connector_id', $canManageSources ? $sources->pluck('id') : array_keys(array_filter($sourceCanOperate)))
            ->when($connectorId !== null, fn ($query) => $query->where('connector_id', $connectorId))->orderByDesc('id')->limit(30)->get();
        $quarantine = DB::table('ingestion_quarantine')->whereIn('run_id', $runs->pluck('id'))->orderByDesc('id')->limit(100)->get();
        $queuedRunIds = DB::table('outbox_events')->whereIn('aggregate_id', $runs->pluck('id'))->where('event_type', 'crawl.requested')->whereNull('delivered_at')->pluck('aggregate_id')->all();

        return compact('sources', 'sourceBindings', 'sourceCanOperate', 'runs', 'quarantine', 'canManageSources', 'queuedRunIds') + $this->managementContext();
    }

    public function store(Request $request, SourceCatalogService $service): RedirectResponse
    {
        $service->authorizeManagement($request->user()->id);
        $data = $request->validate(['name' => 'required|string|max:200', 'kind' => 'required|in:maccms,shandian,json,csv,html,fixture', 'endpoint' => 'nullable|url:https|max:4096', 'latest_max_pages' => 'sometimes|required|integer|in:3,4,5']);
        $data += $request->only(['schedule_mode', 'schedule_daily_time', 'schedule_timezone', 'schedule_interval_minutes']);
        $configuration = $this->configuration($request, $data['kind']);
        if (! $request->has('source_form_present') && ! empty($data['endpoint'])) {
            $configuration['endpoint'] = $data['endpoint'];
            $configuration['allowed_hosts'] = [parse_url($data['endpoint'], PHP_URL_HOST)];
        }
        $source = $service->create($data, $configuration, $request->user()->id);

        return redirect('/admin/sources')->with('status', 'Đã tạo nguồn dùng chung #'.$source->id.'. Gắn nguồn cho website trong cấu hình website.');
    }

    public function update(Request $request, int $id, SourceCatalogService $service): RedirectResponse
    {
        $source = $service->sourceForManagement($id, $request->user()->id);
        $data = $request->validate(['name' => 'required|string|max:200', 'status' => 'required|in:active,paused,archived', 'row_version' => 'required|integer|min:1', 'latest_max_pages' => 'sometimes|required|integer|in:3,4,5']);
        $data += $request->only(['schedule_mode', 'schedule_daily_time', 'schedule_timezone', 'schedule_interval_minutes']);
        $configuration = $this->configuration($request, $source->kind, Json::decode($source->config_json));
        $service->update($id, $data, $configuration, $request->user()->id);

        return redirect('/admin/sources')->with('status', 'Đã lưu cấu hình nguồn dùng chung. Liên kết, thể loại và chính sách của từng website được giữ nguyên.');
    }

    private function configuration(Request $request, string $kind, array $current = []): array
    {
        try {
            return SourceConfigurationForm::fromRequest($request, $kind, $current);
        } catch (ValidationException $error) {
            if (! $request->has('source_form_present')) {
                abort(422, 'Cấu hình nguồn phải là một đối tượng JSON hợp lệ.');
            }
            throw $error;
        }
    }
}
