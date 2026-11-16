<?php

namespace App\Http\Controllers\Admin;

use App\Help\HelpArticles;
use App\Help\HelpTokens;
use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Support\Activity;
use App\Support\FeatureText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Member help articles: change, hide, reset or add (defaults come from resources/help/*.md). */
class HelpArticleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => HelpArticles::all(), 'topics' => HelpArticles::TOPICS,
            'tokens' => collect(HelpTokens::all())->map(fn ($t, $k) => ['token' => '{'.$k.'}', 'value' => $t[0], 'label' => $t[1]])->values()]);
    }

    /** What members would see (live numbers, switched-off parts removed). Nothing is saved. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:160'], 'body' => ['required', 'string', 'max:20000']]);

        return response()->json(['data' => ['title' => HelpTokens::apply((string) ($data['title'] ?? '')), 'html' => HelpArticles::html($data['body']),
            'mentions_off' => FeatureText::mentionsOff($data['body'])]]);
    }

    public function save(Request $request, ?string $slug = null): JsonResponse
    {
        $data = $request->validate([
            'slug' => [$slug ? 'prohibited' : 'required', 'string', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'max:80'],
            'topic' => ['required', 'string', 'max:60'], 'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:20000'], 'feature' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_.,\s]*$/'],
            'position' => ['nullable', 'integer', 'min:0', 'max:999'], 'is_published' => ['boolean'],
        ]);
        $slug ??= $data['slug'];
        $isDefault = isset(HelpArticles::defaults()[$slug]);
        if ($request->isMethod('post') && ($isDefault || HelpArticle::query()->where('slug', $slug)->exists())) {
            throw ValidationException::withMessages(['slug' => 'An article with this address already exists.']);
        }
        if ($request->isMethod('put') && ! $isDefault && ! HelpArticle::query()->where('slug', $slug)->exists()) {
            abort(404);
        }
        $article = HelpArticle::query()->updateOrCreate(['slug' => $slug], [
            'topic' => $data['topic'], 'title' => $data['title'], 'body' => $data['body'], 'feature' => $data['feature'] ?? null,
            'position' => $data['position'] ?? 50, 'is_published' => $data['is_published'] ?? true, 'updated_by' => $request->user()?->id,
        ]);
        Activity::log($article->wasRecentlyCreated ? 'help.article_created' : 'help.article_updated', $article);

        return $this->index();
    }

    /** A default goes back to the text that came with the shop; a new article is deleted. */
    public function destroy(string $slug): JsonResponse
    {
        $article = HelpArticle::query()->where('slug', $slug)->firstOrFail();
        $article->delete();
        Activity::log('help.article_reset', null, ['slug' => $slug]);

        return $this->index();
    }
}
