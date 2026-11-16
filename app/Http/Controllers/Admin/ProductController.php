<?php

namespace App\Http\Controllers\Admin;

use App\Catalog\ImageProcessor;
use App\Catalog\ProductCsv;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductImage;
use App\Models\Tag;
use App\Pricing\ProfitCheck;
use App\Support\Activity;
use App\Support\Settings;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(Product::STATUSES)],
            'category_id' => ['nullable', 'integer'], 'low_stock' => ['nullable', 'boolean'],
            'featured' => ['nullable', 'boolean'], 'members_only' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'max:200'],
        ]);
        $low = Settings::int('catalog.low_stock');

        $page = Product::query()->with(['category:id,name', 'images', 'variants'])
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', "%$v%")->orWhere('slug', 'like', "%$v%")))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('category_id'), fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->has('featured'), fn ($q) => $q->where('is_featured', $request->boolean('featured')))
            ->when($request->has('members_only'), fn ($q) => $q->where('is_members_only', $request->boolean('members_only')))
            ->when($request->boolean('low_stock'), fn ($q) => $q->where('stock_mode', Product::STOCK)->where(fn ($w) => $w
                ->where(fn ($p) => $p->doesntHave('variants')->where('stock', '<=', $low))
                ->orWhereHas('variants', fn ($v) => $v->where('is_active', true)->where('stock', '<=', $low))))
            ->tap(fn ($q) => TableQuery::sort($q, $request, ['name' => 'name', 'price' => 'price', 'status' => 'status', 'stock' => 'stock', 'created_at' => 'created_at', 'id' => 'id'], '-created_at'))
            ->paginate((int) $request->input('per_page', 25));

        $page->getCollection()->transform(fn (Product $p) => $this->present($p));

        return response()->json($page);
    }

    /** Files and private video links of a digital product. */
    public function files(Product $product): JsonResponse
    {
        return response()->json(['data' => $product->files()->get()->map(fn (ProductFile $f) => $f->only(['id', 'kind', 'title', 'size', 'position'])
            + ['has_link' => $f->video_url !== null])]);
    }

    public function addFile(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'file' => ['required_without:video_url', 'nullable', 'file', 'max:204800', 'mimes:pdf,zip,epub,mp3,m4a,mp4,jpg,jpeg,png,webp,docx,xlsx,pptx'],
            'video_url' => ['required_without:file', 'nullable', 'url:https', 'max:500'],
        ]);
        $file = new ProductFile(['product_id' => $product->id, 'title' => strip_tags($data['title']), 'position' => $product->files()->count()]);
        if ($request->hasFile('file')) {
            $upload = $request->file('file');
            $file->fill(['kind' => 'file', 'size' => $upload->getSize(),
                'path' => $upload->storeAs('digital/'.$product->id, Str::random(32).'.'.strtolower($upload->getClientOriginalExtension()), 'local')]);
        } else {
            $file->fill(['kind' => 'video', 'video_url' => $data['video_url']]);
        }
        $file->save();
        Activity::log('product.file_added', $product, ['file' => $file->id]);

        return response()->json(['data' => $file->only(['id', 'kind', 'title', 'size'])], 201);
    }

    public function deleteFile(Product $product, ProductFile $file): JsonResponse
    {
        abort_if($file->product_id !== $product->id, 404);
        if ($file->path) {
            Storage::disk('local')->delete($file->path);
        }
        $file->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->present($product->load(['category', 'tags', 'images', 'variants']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $product = DB::transaction(function () use ($data) {
            $product = Product::query()->create($this->attributes($data));
            $this->syncRelations($product, $data);

            return $product;
        });
        Activity::log('product.created', $product);

        return response()->json(['data' => $this->present($product->fresh(['category', 'tags', 'images', 'variants']))], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validated($request, $product);
        DB::transaction(function () use ($product, $data) {
            $product->update($this->attributes($data));
            $this->syncRelations($product, $data);
        });
        Activity::log('product.updated', $product, ['changed' => array_keys($product->getChanges())]);

        return response()->json(['data' => $this->present($product->fresh(['category', 'tags', 'images', 'variants']))]);
    }

    /** Products are archived, never deleted (orders keep pointing at them). */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['activate', 'archive', 'draft', 'feature', 'unfeature', 'category', 'price_percent', 'stock'])],
            'value' => ['nullable', 'numeric'],
        ]);
        $value = $data['value'] ?? null;
        if (in_array($data['action'], ['price_percent', 'stock'], true) && $value === null) {
            throw ValidationException::withMessages(['value' => 'Type a number.']);
        }
        if ($data['action'] === 'price_percent' && ($value < -90 || $value > 500)) {
            throw ValidationException::withMessages(['value' => 'Change prices by −90% to +500%.']);
        }
        if ($data['action'] === 'category' && $value !== null && ! Category::query()->whereKey((int) $value)->exists()) {
            throw ValidationException::withMessages(['value' => 'Choose a category.']);
        }
        $count = 0;
        DB::transaction(function () use ($data, $value, &$count) {
            Product::query()->whereKey($data['ids'])->each(function (Product $p) use ($data, $value, &$count) {
                $p->update(match ($data['action']) {
                    'activate' => ['status' => 'active'], 'archive' => ['status' => 'archived'], 'draft' => ['status' => 'draft'],
                    'feature' => ['is_featured' => true], 'unfeature' => ['is_featured' => false],
                    'category' => ['category_id' => $value !== null ? (int) $value : null],
                    // Round to whole ETB so prices stay tidy.
                    'price_percent' => ['price' => max(100, (int) round($p->price * (100 + (float) $value) / 100 / 100) * 100)],
                    'stock' => ['stock' => max(0, (int) $value)],
                    default => [],
                });
                $count++;
            });
        });
        Activity::log('product.bulk_'.$data['action'], null, ['ids' => $data['ids'], 'value' => $value]);

        return response()->json(['updated' => $count]);
    }

    public function export(ProductCsv $csv): StreamedResponse
    {
        Activity::log('product.csv_exported', null);

        return $csv->export();
    }

    /** Preview (apply=0) or save (apply=1) a CSV of products. */
    public function import(Request $request, ProductCsv $csv): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'], 'apply' => ['nullable', 'boolean']]);

        return response()->json(['data' => $csv->import($request->file('file'), (bool) ($data['apply'] ?? false), (int) $request->user()->id)]);
    }

    public function uploadImage(Request $request, Product $product, ImageProcessor $images): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
            'alt' => ['nullable', 'string', 'max:200'],
        ]);
        $image = $images->store($product, $request->file('image'), $data['alt'] ?? null);

        return response()->json(['data' => $this->presentImage($image)], 201);
    }

    public function deleteImage(Product $product, ProductImage $image, ImageProcessor $images): JsonResponse
    {
        abort_unless($image->product_id === $product->id, 404);
        $images->delete($image);

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'alpha_dash', 'max:180', Rule::unique('products', 'slug')->ignore($product?->id)],
            'short_description' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'price' => ['required', 'integer', 'min:100', 'max:10000000000'],
            'cost_materials' => ['nullable', 'integer', 'min:0'], 'cost_labour' => ['nullable', 'integer', 'min:0'],
            'cost_packaging' => ['nullable', 'integer', 'min:0'],
            'weight_grams' => ['required', 'integer', 'min:0', 'max:100000'],
            'stock_mode' => ['required', Rule::in([Product::STOCK, Product::MADE_TO_ORDER])],
            'stock' => ['nullable', 'integer', 'min:0'],
            'making_days' => ['nullable', Rule::requiredIf(fn () => $request->input('stock_mode') === Product::MADE_TO_ORDER && $request->input('kind') !== Product::DIGITAL), 'integer', 'min:1', 'max:365'],
            'kind' => ['nullable', Rule::in([Product::PHYSICAL, Product::DIGITAL, Product::ADDON])],
            'member_discount' => ['boolean'],
            'booking_at' => ['nullable', 'date'],
            'preorder_deposit_bps' => ['nullable', 'integer', 'min:1000', 'max:9000'],
            'preorder_ships_on' => ['nullable', 'date'],
            'low_stock_alert' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_featured' => ['boolean'], 'is_members_only' => ['boolean'],
            'release_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(Product::STATUSES)],
            'seo_title' => ['nullable', 'string', 'max:160'], 'seo_description' => ['nullable', 'string', 'max:300'],
            'tags' => ['nullable', 'array', 'max:30'], 'tags.*' => ['string', 'max:60'],
            'variants' => ['nullable', 'array', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.name' => ['required', 'string', 'max:120'],
            'variants.*.options' => ['nullable', 'array'], 'variants.*.options.*' => ['string', 'max:60'],
            'variants.*.price' => ['nullable', 'integer', 'min:100'],
            'variants.*.weight_grams' => ['nullable', 'integer', 'min:0'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['boolean'],
        ]);
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']).'-'.Str::lower(Str::random(4));
        $data['kind'] ??= Product::PHYSICAL;
        if ($data['kind'] === Product::DIGITAL) {
            // Nothing to keep in stock or to ship.
            $data['stock_mode'] = Product::MADE_TO_ORDER;
            $data['making_days'] = null;
            $data['weight_grams'] = 0;
        }
        if ($data['kind'] !== Product::ADDON) {
            $data['booking_at'] = null;
        }

        return $data;
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return collect($data)->except(['tags', 'variants'])->all();
    }

    /** @param array<string, mixed> $data */
    private function syncRelations(Product $product, array $data): void
    {
        if (array_key_exists('tags', $data)) {
            /** @var list<string> $tags */
            $tags = $data['tags'] ?? [];
            $ids = collect($tags)->map(fn ($name) => Tag::query()->firstOrCreate(
                ['slug' => Str::slug($name)], ['name' => $name])->id);
            $product->tags()->sync($ids);
        }
        if (array_key_exists('variants', $data)) {
            $keep = [];
            foreach (array_values($data['variants'] ?? []) as $i => $v) {
                /** @var array<string, mixed> $v */
                $attrs = collect($v)->except('id')->put('position', $i)->all();
                $variant = isset($v['id']) ? $product->variants()->whereKey($v['id'])->first() : null;
                $variant ? $variant->update($attrs) : $variant = $product->variants()->create($attrs);
                $keep[] = $variant->id;
            }
            // Removed variants are deactivated, not deleted (order history).
            $product->variants()->whereNotIn('id', $keep)->update(['is_active' => false]);
        }
    }

    /** @return array<string, mixed> */
    private function present(Product $p): array
    {
        return array_merge($p->makeVisible(['cost_materials', 'cost_labour', 'cost_packaging'])->toArray(), [
            'total_cost' => $p->totalCost(),
            'available_stock' => $p->availableStock(),
            'warnings' => app(ProfitCheck::class)->warnings($p),
            'profit' => app(ProfitCheck::class)->worstCase($p),
            'images' => $p->images->map(fn ($i) => $this->presentImage($i))->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function presentImage(ProductImage $i): array
    {
        return ['id' => $i->id, 'alt' => $i->alt, 'width' => $i->width, 'height' => $i->height,
            'thumb' => $i->url(min($i->widths), in_array('webp', $i->formats, true) ? 'webp' : 'jpg'), 'srcsets' => $i->srcsets()];
    }
}
