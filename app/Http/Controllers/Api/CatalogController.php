<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function products(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'string', 'max:140'],
            'tag' => ['nullable', 'string', 'max:80'], 'featured' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
        $member = $this->isMember($request);

        $page = Product::query()->visibleTo($member)->with(['category', 'images', 'variants'])
            ->when($request->input('q'), fn ($q, $v) => $q->where('name', 'like', "%$v%"))
            ->when($request->input('category'), fn ($q, $v) => $q->whereHas('category', fn ($c) => $c->where('slug', $v)))
            ->when($request->input('tag'), fn ($q, $v) => $q->whereHas('tags', fn ($t) => $t->where('slug', $v)))
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->tap(fn ($q) => match ($request->input('sort', 'newest')) {
                'price_asc' => $q->orderBy('price'), 'price_desc' => $q->orderByDesc('price'),
                'name' => $q->orderBy('name'), default => $q->orderByDesc('release_at')->orderByDesc('id'),
            })
            ->paginate((int) $request->input('per_page', 24));

        return ProductResource::collection($page);
    }

    public function product(Request $request, string $slug): ProductResource
    {
        $member = $this->isMember($request);
        $product = Product::query()->visibleTo($member)->where('slug', $slug)
            ->with(['category', 'tags', 'images', 'variants'])->firstOrFail();

        return new ProductResource($product);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => Category::query()->where('is_active', true)
            ->orderBy('position')->orderBy('name')->get(['id', 'parent_id', 'name', 'slug', 'description'])]);
    }

    private function isMember(Request $request): bool
    {
        $user = auth('sanctum')->user() ?? $request->user();
        $member = $user instanceof User && $user->isActiveMember();
        $request->attributes->set('is_member', $member);

        return $member;
    }
}
