<?php

namespace App\Catalog;

use App\Member\MemberTable;
use App\Models\Category;
use App\Models\Product;
use App\Support\Activity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Products to and from a spreadsheet (CSV). Import always shows a preview
 * first (what is new, what changes, what is wrong) and saves nothing until
 * every row is OK.
 */
class ProductCsv
{
    public const COLUMNS = ['id', 'name', 'slug', 'status', 'category', 'price', 'stock', 'cost_materials', 'cost_labour', 'cost_packaging', 'weight_grams', 'kind', 'is_featured', 'short_description'];

    private const MONEY = ['price', 'cost_materials', 'cost_labour', 'cost_packaging'];

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::COLUMNS);
            Product::query()->with('category:id,name')->orderBy('id')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $p) {
                    /** @var Product $p */
                    fputcsv($out, array_map(fn ($v) => MemberTable::safe((string) $v), [$p->id, $p->name, $p->slug, $p->status, $p->category?->name,
                        number_format($p->price / 100, 2, '.', ''), $p->stock, number_format($p->cost_materials / 100, 2, '.', ''),
                        number_format($p->cost_labour / 100, 2, '.', ''), number_format($p->cost_packaging / 100, 2, '.', ''), $p->weight_grams,
                        $p->kind, $p->is_featured ? 'yes' : 'no', $p->short_description]));
                }
            });
            fclose($out);
        }, 'products-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{rows: list<array{line:int, action:string, name:string, changes:array<string, array{0:mixed,1:mixed}>, errors:list<string>}>, ok:bool, applied:bool, summary:array<string,int>} */
    public function import(UploadedFile $file, bool $apply, int $actorId): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $header = $handle ? fgetcsv($handle) : false;
        if (! $handle || ! is_array($header)) {
            return ['rows' => [], 'ok' => false, 'applied' => false, 'summary' => ['errors' => 1]];
        }
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h) ?? '')), $header);
        $categories = Category::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower((string) $name) => $id]);
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false && count($rows) < 2000) {
            $line++;
            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }
            $raw = [];
            foreach ($header as $i => $h) {
                if (in_array($h, self::COLUMNS, true)) {
                    $raw[$h] = trim((string) ($cells[$i] ?? ''));
                }
            }
            $rows[] = $this->check($line, $raw, $categories->all());
        }
        fclose($handle);
        $ok = $rows !== [] && collect($rows)->every(fn ($r) => $r['errors'] === []);
        $summary = ['create' => collect($rows)->where('action', 'create')->count(), 'update' => collect($rows)->where('action', 'update')->count(),
            'same' => collect($rows)->where('action', 'same')->count(), 'errors' => collect($rows)->filter(fn ($r) => $r['errors'] !== [])->count()];
        if ($apply && $ok) {
            DB::transaction(function () use ($rows) {
                foreach ($rows as $r) {
                    if ($r['action'] === 'create') {
                        Product::query()->create($r['data']);
                    } elseif ($r['action'] === 'update') {
                        Product::query()->whereKey($r['id'])->firstOrFail()->update($r['data']);
                    }
                }
            });
            Activity::log('product.csv_imported', null, $summary, null, $actorId);
        }

        return ['rows' => array_map(fn ($r) => collect($r)->except(['data', 'id'])->all(), $rows), 'ok' => $ok, 'applied' => $apply && $ok, 'summary' => $summary];
    }

    /**
     * @param  array<string, string>  $raw
     * @param  array<string, int>  $categories
     * @return array<string, mixed>
     */
    private function check(int $line, array $raw, array $categories): array
    {
        $existing = ($raw['id'] ?? '') !== '' ? Product::query()->find((int) $raw['id'])
            : (($raw['slug'] ?? '') !== '' ? Product::query()->where('slug', $raw['slug'])->first() : null);
        $errors = [];
        if (($raw['id'] ?? '') !== '' && ! $existing) {
            $errors[] = 'No product with id '.$raw['id'].'.';
        }
        $data = [];
        foreach ($raw as $k => $v) {
            if ($k === 'id' || ($v === '' && $existing)) {
                continue; // empty cell = keep what is there
            }
            $data[$k] = match (true) {
                in_array($k, self::MONEY, true) => is_numeric(str_replace(',', '', $v)) ? (int) round(((float) str_replace(',', '', $v)) * 100) : $v,
                in_array($k, ['stock', 'weight_grams'], true) => is_numeric($v) ? (int) $v : $v,
                $k === 'is_featured' => in_array(mb_strtolower($v), ['1', 'yes', 'true', 'y'], true),
                $k === 'category' => $v,
                default => $v,
            };
        }
        if (array_key_exists('category', $data)) {
            $cat = $data['category'];
            unset($data['category']);
            if ($cat !== '') {
                $cid = $categories[mb_strtolower((string) $cat)] ?? null;
                $cid === null ? $errors[] = "Unknown category “{$cat}”." : $data['category_id'] = $cid;
            }
        }
        $v = Validator::make($data, [
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:160'],
            'slug' => ['sometimes', 'alpha_dash', 'max:180'],
            'status' => ['sometimes', 'in:'.implode(',', Product::STATUSES)],
            'price' => [$existing ? 'sometimes' : 'required', 'integer', 'min:100', 'max:10000000000'],
            'stock' => ['sometimes', 'integer', 'min:0'], 'weight_grams' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'cost_materials' => ['sometimes', 'integer', 'min:0'], 'cost_labour' => ['sometimes', 'integer', 'min:0'], 'cost_packaging' => ['sometimes', 'integer', 'min:0'],
            'kind' => ['sometimes', 'in:physical,digital,addon'], 'short_description' => ['sometimes', 'nullable', 'string', 'max:300'],
        ], ['price.min' => 'Price must be at least 1 ETB.', 'name.required' => 'A new product needs a name.', 'price.required' => 'A new product needs a price.']);
        $errors = array_merge($errors, $v->errors()->all());
        if (isset($data['slug']) && Product::query()->where('slug', $data['slug'])->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
            $errors[] = 'Another product already has the link name “'.$data['slug'].'”.';
        }
        $changes = [];
        foreach ($data as $k => $new) {
            $old = $existing?->getAttribute($k);
            if ($existing === null || $old != $new) {
                $changes[$k] = [$old, $new];
            }
        }
        if (! $existing) {
            $data['slug'] ??= Str::slug((string) ($data['name'] ?? 'product')).'-'.Str::lower(Str::random(4));
            $data += ['status' => 'draft', 'stock' => 0, 'weight_grams' => 0, 'stock_mode' => Product::STOCK];
        }

        return ['line' => $line, 'action' => $existing ? ($changes === [] ? 'same' : 'update') : 'create', 'name' => (string) ($data['name'] ?? $existing->name ?? ''),
            'changes' => $changes, 'errors' => $errors, 'data' => $data, 'id' => $existing?->id];
    }
}
