<?php

namespace App\Http\Controllers\Admin;

use App\Catalog\ImageProcessor;
use App\Http\Controllers\Controller;
use App\Models\ShareKitItem;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ShareKitController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ShareKitItem::query()->orderBy('position')->orderBy('id')->get()
            ->map(fn (ShareKitItem $i) => $i->only(['id', 'title', 'target', 'text', 'position', 'is_active']) + ['image' => $i->imageUrl(), 'has_image' => $i->image_path !== null]),
            'targets' => ShareKitItem::TARGETS]);
    }

    /** POST (also for updates: phones and browsers send files only with POST). */
    public function save(Request $request, ImageProcessor $images, ?ShareKitItem $item = null): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'], 'target' => ['required', Rule::in(array_keys(ShareKitItem::TARGETS))],
            'text' => ['required', 'string', 'max:1000', 'regex:/\{link\}/'], 'position' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'remove_image' => ['nullable', 'boolean'],
        ], ['text.regex' => 'Keep {link} in the text: it becomes the member\'s own link.']);
        $item ??= new ShareKitItem;
        $old = $item->image_path;
        $item->fill(['title' => $data['title'], 'target' => $data['target'], 'text' => $data['text'],
            'position' => (int) ($data['position'] ?? 0), 'is_active' => $request->boolean('is_active', true)]);
        if ($request->hasFile('image')) {
            $item->image_path = $images->photo($request->file('image'), 'share-kit', 1080);
        } elseif ($request->boolean('remove_image')) {
            $item->image_path = null;
        }
        $item->save();
        if ($old && $old !== $item->image_path) {
            Storage::disk('public')->delete($old);
        }
        Activity::log('share_kit.saved', $item);

        return response()->json(['data' => $item], $item->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(ShareKitItem $item): JsonResponse
    {
        if ($item->image_path) {
            Storage::disk('public')->delete($item->image_path);
        }
        $item->delete();
        Activity::log('share_kit.deleted', $item);

        return response()->json(['data' => ['ok' => true]]);
    }
}
