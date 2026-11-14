<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public product shape. Never exposes cost fields. @mixin Product */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Product $p */
        $p = $this->resource;
        $stock = $p->availableStock();
        $isMember = (bool) $request->attributes->get('is_member', false);

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'short_description' => $p->short_description,
            'description' => $this->when($request->routeIs('api.products.show'), $p->description),
            'category' => $p->category ? ['name' => $p->category->name, 'slug' => $p->category->slug] : null,
            'tags' => $p->relationLoaded('tags') ? $p->tags->pluck('name') : [],
            'price' => $p->price,
            'currency' => config('shop.currency'),
            'weight_grams' => $p->weight_grams,
            'made_to_order' => $p->isMadeToOrder(),
            'making_days' => $p->making_days,
            'in_stock' => $stock === null || $stock > 0,
            'is_featured' => $p->is_featured,
            'is_members_only' => $p->is_members_only,
            'early_access' => $p->inEarlyAccess(),
            'release_at' => $p->release_at?->toIso8601String(),
            'purchasable' => $p->isPurchasableBy($isMember),
            'images' => $p->images->map(fn (ProductImage $i) => [
                'alt' => $i->alt, 'width' => $i->width, 'height' => $i->height, 'srcsets' => $i->srcsets(),
                'src' => $i->url(in_array(800, $i->widths, true) ? 800 : max($i->widths), in_array('webp', $i->formats, true) ? 'webp' : 'jpg'),
            ]),
            'variants' => $p->variants->where('is_active', true)->values()->map(fn ($v) => [
                'id' => $v->id, 'name' => $v->name, 'options' => $v->options,
                'price' => $v->price ?? $p->price,
                'in_stock' => $p->isMadeToOrder() || (int) $v->stock > 0,
            ]),
        ];
    }
}
