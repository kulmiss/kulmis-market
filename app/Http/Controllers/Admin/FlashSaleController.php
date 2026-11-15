<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FlashSale;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Pricing\ProfitCheck;
use App\Support\Activity;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FlashSaleController extends Controller
{
    public function __construct(private readonly ProfitCheck $profit) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:live,scheduled,ended,sold_out,off'], 'q' => ['nullable', 'string', 'max:100']]);
        $now = now();
        $page = FlashSale::query()->with(['product.variants', 'variant'])
            ->when($request->input('q'), fn ($q, $v) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%$v%")))
            ->when($request->input('status'), fn ($q, $v) => match ($v) {
                'live' => $q->where('is_active', true)->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
                    ->where(fn ($w) => $w->whereNull('stock_limit')->orWhereColumn('sold', '<', 'stock_limit')),
                'scheduled' => $q->where('is_active', true)->where('starts_at', '>', $now),
                'ended' => $q->where('ends_at', '<=', $now),
                'sold_out' => $q->whereNotNull('stock_limit')->whereColumn('sold', '>=', 'stock_limit'),
                default => $q->where('is_active', false),
            })
            ->tap(fn ($q) => TableQuery::sort($q, $request, ['price' => 'price', 'starts' => 'starts_at', 'ends' => 'ends_at', 'sold' => 'sold'], '-starts_at'))
            ->paginate(min(200, (int) $request->input('per_page', 50)));
        $page->getCollection()->transform(fn (FlashSale $s) => $this->present($s));

        return response()->json($page);
    }

    /** Live profit preview for the form. */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer'], 'variant_id' => ['nullable', 'integer'], 'price' => ['required', 'integer', 'min:0']]);
        $product = Product::query()->with('variants')->findOrFail($data['product_id']);
        $w = $this->profit->worstCase($product, (int) $data['price']);

        return response()->json(['data' => $w + ['line' => ProfitCheck::line($w['profit']), 'regular' => $this->regular($product, $data['variant_id'] ?? null)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $sale = new FlashSale;
        $sale->fill($this->validated($request));
        $sale->created_by = (int) $request->user()->id;
        $sale->save();
        Activity::log('flash_sale.created', $sale);

        return response()->json(['data' => $this->present($sale->load(['product.variants', 'variant']))], 201);
    }

    public function update(Request $request, FlashSale $flashSale): JsonResponse
    {
        $flashSale->update($this->validated($request, $flashSale));
        Activity::log('flash_sale.updated', $flashSale);

        return response()->json(['data' => $this->present($flashSale->load(['product.variants', 'variant']))]);
    }

    public function destroy(FlashSale $flashSale): JsonResponse
    {
        // Sales that sold something stay for the records; they are just switched off.
        $flashSale->sold > 0 ? $flashSale->update(['is_active' => false]) : $flashSale->delete();
        Activity::log('flash_sale.removed', $flashSale);

        return response()->json(['data' => ['ok' => true]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?FlashSale $sale = null): array
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer'],
            'price' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'stock_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['boolean'],
            'accept_loss' => ['boolean'],
        ]);
        $product = Product::query()->with('variants')->findOrFail($data['product_id']);
        $variantId = $data['product_variant_id'] ?? null;
        if ($variantId !== null && ! $product->variants->contains('id', $variantId)) {
            throw ValidationException::withMessages(['product_variant_id' => 'That option does not belong to this product.']);
        }
        $regular = $this->regular($product, $variantId);
        if ($data['price'] >= $regular) {
            throw ValidationException::withMessages(['price' => 'The sale price must be lower than the normal price.']);
        }
        if ($sale !== null && $sale->stock_limit !== null && isset($data['stock_limit']) && $data['stock_limit'] < $sale->sold) {
            throw ValidationException::withMessages(['stock_limit' => "{$sale->sold} are already sold at this price."]);
        }
        $overlap = FlashSale::query()->where('product_id', $product->id)->where('product_variant_id', $variantId)
            ->where('is_active', true)->when($sale, fn ($q) => $q->whereKeyNot($sale->id))
            ->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists();
        if ($overlap && ($data['is_active'] ?? true)) {
            throw ValidationException::withMessages(['starts_at' => 'This product already has a sale at that time.']);
        }
        $w = $this->profit->worstCase($product, (int) $data['price']);
        if ($w['profit'] < 0 && ! ($data['accept_loss'] ?? false)) {
            throw ValidationException::withMessages(['profit' => ProfitCheck::line($w['profit']).' Tick "I know it loses money" to save anyway.']);
        }
        unset($data['accept_loss']);

        return $data + ['product_variant_id' => null];
    }

    private function regular(Product $product, ?int $variantId): int
    {
        $variant = $variantId !== null ? $product->variants->firstWhere('id', $variantId) : null;

        return $variant instanceof ProductVariant && $variant->price !== null ? (int) $variant->price : (int) $product->price;
    }

    /** @return array<string, mixed> */
    private function present(FlashSale $s): array
    {
        $w = $this->profit->worstCase($s->product, $s->price);

        return [
            'id' => $s->id, 'product_id' => $s->product_id, 'product_variant_id' => $s->product_variant_id,
            'product' => $s->product->name, 'variant' => $s->variant?->name, 'price' => $s->price,
            'regular_price' => $this->regular($s->product, $s->product_variant_id),
            'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
            'stock_limit' => $s->stock_limit, 'sold' => $s->sold, 'is_active' => $s->is_active, 'status' => $s->status(),
            'profit' => $w['profit'], 'profit_line' => ProfitCheck::line($w['profit']),
        ];
    }
}
