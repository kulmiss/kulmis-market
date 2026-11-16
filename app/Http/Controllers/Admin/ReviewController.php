<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Shop\Reviews;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = ProductReview::query()->with(['product:id,name,slug', 'user:id,first_name,last_name,phone'])
            ->when($request->input('status', 'pending'), fn ($q, $v) => $v === 'all' ? $q : $q->where('status', $v))
            ->when($request->input('rating'), fn ($q, $v) => $q->where('rating', (int) $v))
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('body', 'like', "%$v%")->orWhere('title', 'like', "%$v%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%$v%"))))
            ->tap(fn ($q) => TableQuery::sort($q, $request, ['rating' => 'rating', 'created' => 'created_at', 'status' => 'status']))
            ->paginate((int) $request->input('per_page', 25));
        $page->getCollection()->transform(fn (ProductReview $r) => [
            'id' => $r->id, 'rating' => $r->rating, 'title' => $r->title, 'body' => $r->body, 'status' => $r->status,
            'reject_reason' => $r->reject_reason, 'is_demo' => $r->is_demo, 'created_at' => $r->created_at?->toIso8601String(),
            'product' => $r->product?->only(['id', 'name', 'slug']), 'author' => $r->authorName(), 'phone' => $r->user?->phone,
            'photos' => array_map(fn ($p) => Storage::disk('public')->url($p), $r->photos ?? []),
        ]);

        return response()->json($page);
    }

    public function approve(Request $request, Reviews $reviews, ProductReview $review): JsonResponse
    {
        $reviews->decide($review, true, (int) $request->user()->id);

        return response()->json(['data' => ['status' => $review->status]]);
    }

    public function reject(Request $request, Reviews $reviews, ProductReview $review): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $reviews->decide($review, false, (int) $request->user()->id, $data['reason']);

        return response()->json(['data' => ['status' => $review->status]]);
    }
}
