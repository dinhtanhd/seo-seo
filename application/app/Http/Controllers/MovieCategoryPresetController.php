<?php

namespace App\Http\Controllers;

use App\Domain\Sites\MovieCategoryPresetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MovieCategoryPresetController extends Controller
{
    public function store(Request $request, int $siteId, MovieCategoryPresetService $service): RedirectResponse
    {
        abort_unless($request->user()->is_global_admin, 403);
        $data = $request->validate(['config_epoch' => 'required|integer|min:1', 'category_fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/']);
        $result = $service->apply($siteId, $request->user()->id, (int) $data['config_epoch'], $data['category_fingerprint']);

        return redirect('/admin/sites/'.$siteId.'/edit?tab=categories')->with('status', 'Đã thêm '.count($result['created']).' danh mục, sắp xếp '.count($result['reparented']).' mục con và giữ '.count($result['reused']).' mục có sẵn.')->with('movie_category_preset_result', $result);
    }
}
