<?php

namespace App\Http\Controllers\Admin;

use App\Badges\BadgeIcons;
use App\Badges\BadgeRules;
use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Super Admin: edit every badge (name, icon, colour, rule, threshold, on/off) and add new ones from the rules list. */
class BadgeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Badge::query()->withCount('holders')->orderBy('rule')->orderBy('threshold')->get(),
            'rules' => collect(BadgeRules::RULES)->map(fn ($r, $k) => ['key' => $k, 'label' => $r['label'], 'unit' => $r['unit']])->values(),
            'icons' => BadgeIcons::BADGE_ICONS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $data['key'] = $this->uniqueKey($data['name']);
        $badge = Badge::query()->create($data);
        Activity::log('badge.created', $badge, $data);

        return response()->json(['data' => $badge->loadCount('holders')], 201);
    }

    public function update(Request $request, Badge $badge): JsonResponse
    {
        $data = $this->validated($request, false);
        $before = $badge->only(array_keys($data));
        $badge->update($data);
        Activity::log('badge.updated', $badge, ['from' => $before, 'to' => $data]);

        return response()->json(['data' => $badge->loadCount('holders')]);
    }

    /** Badges members already hold can only be switched off, so nobody loses one. */
    public function destroy(Badge $badge): JsonResponse
    {
        if ($badge->holders()->exists()) {
            throw ValidationException::withMessages(['badge' => 'Members already have this badge. Switch it off instead of deleting it.']);
        }
        Activity::log('badge.deleted', null, ['key' => $badge->key, 'name' => $badge->name]);
        $badge->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$req, 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'icon' => [$req, Rule::in(BadgeIcons::BADGE_ICONS)],
            'color' => [$req, 'regex:/^#[0-9a-fA-F]{6}$/'],
            'rule' => [$req, Rule::in(array_keys(BadgeRules::RULES))],
            'threshold' => [$req, 'integer', 'min:1', 'max:1000000000000'],
            'rank' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]) + ($creating ? ['description' => ''] : []);
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 30, '') ?: 'badge';
        $key = $base;
        for ($i = 2; Badge::query()->where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        return $key;
    }
}
