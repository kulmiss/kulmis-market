<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Page;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentController extends Controller
{
    public function pages(): JsonResponse
    {
        return response()->json(['data' => Page::query()->orderBy('position')->orderBy('title')->get()]);
    }

    public function savePage(Request $request, ?Page $page = null): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:120', Rule::unique('pages', 'slug')->ignore($page?->id)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:100000'],
            'seo_description' => ['nullable', 'string', 'max:300'],
            'is_published' => ['boolean'], 'in_footer' => ['boolean'], 'position' => ['nullable', 'integer'],
        ]);
        $page ??= new Page;
        $page->fill($data)->save();
        Activity::log('page.saved', $page, ['slug' => $page->slug]);

        return response()->json(['data' => $page], $page->wasRecentlyCreated ? 201 : 200);
    }

    public function banners(): JsonResponse
    {
        return response()->json(['data' => Banner::query()->orderBy('placement')->orderBy('position')->get()]);
    }

    public function saveBanner(Request $request, ?Banner $banner = null): JsonResponse
    {
        $data = $request->validate([
            'placement' => ['required', Rule::in(['home', 'shop', 'membership', 'signals', 'top_bar'])],
            'title' => ['required', 'string', 'max:160'], 'text' => ['nullable', 'string', 'max:300'],
            'link_url' => ['nullable', 'string', 'max:255', 'regex:#^(/|https://)#'], 'link_text' => ['nullable', 'string', 'max:60'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date'],
            'is_active' => ['boolean'], 'position' => ['nullable', 'integer'],
        ]);
        $banner ??= new Banner;
        $banner->fill($data)->save();
        Activity::log('banner.saved', $banner);

        return response()->json(['data' => $banner], $banner->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteBanner(Banner $banner): JsonResponse
    {
        $banner->delete();
        Activity::log('banner.deleted', null, ['id' => $banner->id]);

        return response()->json(['ok' => true]);
    }
}
