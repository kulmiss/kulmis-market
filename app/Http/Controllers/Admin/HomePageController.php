<?php

namespace App\Http\Controllers\Admin;

use App\Help\HelpTokens;
use App\Home\HomePageView;
use App\Home\HomePublisher;
use App\Home\HomeTemplates;
use App\Home\SafeLink;
use App\Home\Sections;
use App\Home\SectionTypes;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Faq;
use App\Models\HomePageVersion;
use App\Models\Product;
use App\Models\User;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Admin → Home page: the builder's API. The editor keeps the sections in
 * the browser, autosaves the draft here and asks for a preview (HTML made
 * by the same Blade partials as the real page).
 */
class HomePageController extends Controller
{
    public function __construct(private readonly HomePublisher $publisher) {}

    public function show(): JsonResponse
    {
        $w = $this->publisher->working();
        $live = HomePageVersion::live();
        $scheduled = HomePageVersion::scheduled();

        return response()->json([
            'enabled' => Features::enabled('site.home_builder'),
            'draft' => $w,
            'problems' => Sections::problems($w['sections'], $w['seo']),
            'live' => $live ? $this->summary($live) : null,
            'scheduled' => $scheduled ? [...$this->summary($scheduled), 'publish_at' => $scheduled->publish_at?->toIso8601String()] : null,
            'types' => $this->types(),
            'templates' => HomeTemplates::list(),
            'tokens' => collect(HelpTokens::all())->map(fn ($t, $k) => ['token' => '{'.$k.'}', 'value' => $t[0], 'label' => $t[1]])->values(),
            'link_help' => SafeLink::HELP,
        ]);
    }

    public function saveDraft(Request $request): JsonResponse
    {
        $data = $request->validate(['sections' => ['present', 'array', 'max:'.Sections::MAX_SECTIONS], 'seo' => ['nullable', 'array'], 'revision' => ['required', 'integer', 'min:0']]);
        $draft = $this->publisher->saveDraft($data['sections'], $data['seo'] ?? [], (int) $data['revision'], $this->user($request));
        $sections = Sections::normalize($draft->sections);

        return response()->json([
            'revision' => $draft->revision, 'updated_at' => $draft->updated_at->toIso8601String(),
            'sections' => $sections, 'problems' => Sections::problems($sections, Sections::seo($draft->seo)),
        ]);
    }

    public function discard(): JsonResponse
    {
        $this->publisher->discard();

        return response()->json(['draft' => $this->publisher->working()]);
    }

    /** The draft as a visitor would see it (not saved). For the editor's preview frame. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['sections' => ['present', 'array', 'max:'.Sections::MAX_SECTIONS], 'seo' => ['nullable', 'array'], 'dark' => ['boolean']]);

        return response()->json(['html' => $this->asGuest(fn () => HomePageView::make(Sections::normalize($data['sections']), $data['seo'] ?? [], false, true, $request->boolean('dark'))->render())]);
    }

    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);
        $v = $this->publisher->publish($this->user($request), $data['note'] ?? null);

        return response()->json(['live' => $this->summary($v), 'draft' => $this->publisher->working()]);
    }

    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate(['publish_at' => ['required', 'date']]);
        $v = $this->publisher->schedule($this->user($request), Carbon::parse($data['publish_at'])->timezone((string) config('app.timezone')));

        return response()->json(['scheduled' => [...$this->summary($v), 'publish_at' => $v->publish_at?->toIso8601String()], 'draft' => $this->publisher->working()]);
    }

    public function cancelSchedule(): JsonResponse
    {
        $this->publisher->cancelSchedule();

        return response()->json(['draft' => $this->publisher->working()]);
    }

    public function versions(): JsonResponse
    {
        $rows = HomePageVersion::query()->where('status', HomePageVersion::PUBLISHED)->with('publisher:id,first_name,last_name')
            ->orderByDesc('published_at')->orderByDesc('id')->limit(100)->get();
        $liveId = HomePageVersion::live()?->id;

        return response()->json(['data' => $rows->map(fn (HomePageVersion $v) => [...$this->summary($v), 'live' => $v->id === $liveId])]);
    }

    public function versionPreview(Request $request, HomePageVersion $version): JsonResponse
    {
        abort_unless(in_array($version->status, [HomePageVersion::PUBLISHED, HomePageVersion::SCHEDULED], true), 404);

        return response()->json(['html' => $this->asGuest(fn () => HomePageView::make(Sections::normalize($version->sections), $version->seo, false, true, $request->boolean('dark'))->render())]);
    }

    public function restore(Request $request, HomePageVersion $version): JsonResponse
    {
        $v = $this->publisher->restore($version, $this->user($request));

        return response()->json(['live' => $this->summary($v)]);
    }

    public function template(string $key): JsonResponse
    {
        abort_unless(isset(HomeTemplates::list()[$key]), 404);

        return response()->json(['sections' => HomeTemplates::sections($key)]);
    }

    public function newSection(string $type): JsonResponse
    {
        abort_unless(SectionTypes::get($type) !== null, 404);

        return response()->json(['section' => Sections::make($type)]);
    }

    /** Things to pick in the side panel (categories, products, help questions). */
    public function options(string $kind): JsonResponse
    {
        $rows = match ($kind) {
            'categories' => Category::query()->orderBy('position')->get(['id', 'name', 'is_active'])
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->name.($c->is_active ? '' : ' (hidden)')]),
            'products' => Product::query()->where('status', 'active')->orderBy('name')->limit(500)->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'label' => $p->name]),
            'faqs' => Faq::query()->orderBy('position')->get(['id', 'question', 'is_published'])
                ->map(fn ($f) => ['id' => $f->id, 'label' => $f->question.($f->is_published ? '' : ' (not published)')]),
            default => abort(404),
        };

        return response()->json(['data' => $rows->values()]);
    }

    /** @return array<string, mixed> */
    private function summary(HomePageVersion $v): array
    {
        return [
            'id' => $v->id, 'note' => $v->note, 'sections' => count($v->sections), 'restored_from' => $v->restored_from_id,
            'published_at' => $v->published_at?->toIso8601String(), 'by' => $v->publisher?->name,
        ];
    }

    /** @return list<array<string, mixed>> the section library for the editor, with what is hidden now and why */
    private function types(): array
    {
        return collect(SectionTypes::all())->map(fn ($t, $key) => [
            'key' => $key, 'label' => $t['label'], 'group' => $t['group'], 'icon' => $t['icon'], 'description' => $t['description'],
            'note' => $t['note'] ?? null, 'fields' => $t['fields'], 'defaults' => $t['defaults'],
            'off' => ($off = SectionTypes::offFeatures($key)) === [] ? null : 'Hidden while '.Sections::switchNames($off).' is off.',
        ])->values()->all();
    }

    private function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }

    /**
     * Render as a visitor would see it: not signed in (no account menu, guest prices).
     *
     * @param  callable(): string  $render
     */
    private function asGuest(callable $render): string
    {
        $guard = Auth::guard('web');
        $user = $guard->user();
        $guard->forgetUser();
        try {
            return $render();
        } finally {
            if ($user) {
                $guard->setUser($user);
            }
        }
    }
}
