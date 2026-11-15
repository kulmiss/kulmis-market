<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\BundleItem;
use App\Models\Product;
use App\Pricing\ProfitCheck;
use App\Support\Activity;
use App\Support\Money;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BundleController extends Controller
{
    public function __construct(private readonly ProfitCheck $profit) {}

    public function index(Request $request): JsonResponse
    {
        $page = Bundle::query()->with(['items.product', 'items.variant'])
            ->when($request->input('q'), fn ($q, $v) => $q->where('name', 'like', "%$v%"))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->tap(fn ($q) => TableQuery::sort($q, $request, ['name' => 'name', 'price' => 'price', 'created' => 'created_at'], '-created_at'))
            ->paginate(min(200, (int) $request->input('per_page', 50)));
        $page->getCollection()->transform(fn (Bundle $b) => $this->present($b));

        return response()->json($page);
    }

    /** Live profit preview for the form (nothing is saved). */
    public function check(Request $request): JsonResponse
    {
        $bundle = $this->build($request, null, false);

        return response()->json(['data' => $this->present($bundle)]);
    }

    public function store(Request $request): JsonResponse
    {
        $bundle = DB::transaction(fn () => $this->build($request, null, true));
        Activity::log('bundle.created', $bundle);

        return response()->json(['data' => $this->present($bundle)], 201);
    }

    public function update(Request $request, Bundle $bundle): JsonResponse
    {
        $bundle = DB::transaction(fn () => $this->build($request, $bundle, true));
        Activity::log('bundle.updated', $bundle);

        return response()->json(['data' => $this->present($bundle)]);
    }

    public function destroy(Bundle $bundle): JsonResponse
    {
        $bundle->delete();
        Activity::log('bundle.deleted', $bundle);

        return response()->json(['data' => ['ok' => true]]);
    }

    private function build(Request $request, ?Bundle $bundle, bool $save): Bundle
    {
        $data = $request->validate([
            'name' => [$save ? 'required' : 'nullable', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9-]+$/', Rule::unique('bundles', 'slug')->ignore($bundle?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'items' => ['required', 'array', 'min:2', 'max:10'],
            'items.*.product_id' => ['required', 'integer', 'distinct:strict', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'accept_loss' => ['boolean'],
        ]);
        $products = Product::query()->with('variants')->whereKey(array_column($data['items'], 'product_id'))->get()->keyBy('id');
        $items = [];
        foreach ($data['items'] as $n => $row) {
            $p = $products->get($row['product_id']);
            if (! $p instanceof Product) {
                throw ValidationException::withMessages(["items.$n.product_id" => 'That product does not exist.']);
            }
            $vid = $row['product_variant_id'] ?? null;
            $active = $p->variants->where('is_active', true);
            if ($vid === null && $active->isNotEmpty()) {
                throw ValidationException::withMessages(["items.$n.product_variant_id" => "Choose an option for “{$p->name}”."]);
            }
            if ($vid !== null && ! $active->contains('id', $vid)) {
                throw ValidationException::withMessages(["items.$n.product_variant_id" => 'That option does not belong to this product.']);
            }
            $item = new BundleItem(['product_id' => $row['product_id'], 'product_variant_id' => $vid, 'quantity' => $row['quantity']]);
            $item->setRelation('product', $p);
            $item->setRelation('variant', $vid ? $p->variants->firstWhere('id', $vid) : null);
            $items[] = $item;
        }

        $b = $bundle ?? new Bundle;
        $b->fill([
            'name' => $data['name'] ?? 'Preview', 'description' => $data['description'] ?? null, 'price' => $data['price'],
            'is_active' => $data['is_active'] ?? true, 'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null,
            'slug' => $data['slug'] ?? $b->slug ?? $this->slug($data['name'] ?? 'bundle'),
        ]);
        $b->setRelation('items', collect($items));
        if ($data['price'] >= $b->regularPrice()) {
            throw ValidationException::withMessages(['price' => 'The bundle price must be lower than buying the pieces one by one ('.Money::format($b->regularPrice()).').']);
        }
        if (! $save) {
            return $b;
        }
        $w = $this->profit->bundle($b);
        if ($w['profit'] < 0 && ! ($data['accept_loss'] ?? false)) {
            throw ValidationException::withMessages(['profit' => ProfitCheck::line($w['profit']).' Tick "I know it loses money" to save anyway.']);
        }
        $b->unsetRelation('items');
        $b->save();
        $b->items()->delete();
        foreach ($items as $i) {
            $b->items()->create($i->only(['product_id', 'product_variant_id', 'quantity']));
        }

        return $b->load(['items.product', 'items.variant']);
    }

    private function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'bundle';
        $slug = $base;
        for ($n = 2; Bundle::query()->where('slug', $slug)->exists(); $n++) {
            $slug = "$base-$n";
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function present(Bundle $b): array
    {
        $w = $this->profit->bundle($b);

        return [
            'id' => $b->id, 'name' => $b->name, 'slug' => $b->slug, 'description' => $b->description, 'price' => $b->price,
            'regular_price' => $b->regularPrice(), 'is_active' => $b->is_active,
            'starts_at' => $b->starts_at?->toIso8601String(), 'ends_at' => $b->ends_at?->toIso8601String(),
            'items' => $b->items->map(fn (BundleItem $i) => ['product_id' => $i->product_id, 'product_variant_id' => $i->product_variant_id,
                'quantity' => $i->quantity, 'name' => $i->product->name.($i->variant ? ' — '.$i->variant->name : '')])->values(),
            'profit' => $w['profit'], 'profit_line' => ProfitCheck::line($w['profit']),
        ];
    }
}
