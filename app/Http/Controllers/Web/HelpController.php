<?php

namespace App\Http\Controllers\Web;

use App\Help\HelpArticles;
use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Faq;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HelpController extends Controller
{
    public function index(Request $request): View
    {
        abort_if(Features::disabled('site.help_centre'), 404);
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 80));
        $faqs = Faq::query()->where('is_published', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('question', 'like', "%$q%")->orWhere('answer', 'like', "%$q%")))
            ->orderBy('category')->orderBy('position')->orderBy('id')->get()->filter(fn (Faq $f) => $f->shownNow())->values();
        $articles = HelpArticles::search(HelpArticles::visible(), $q);

        return view('store.help', [
            'articles' => collect($articles)->groupBy('topic'),
            'faqs' => $faqs->groupBy(fn (Faq $f) => $f->category ?: 'Other questions'),
            'q' => $q,
        ] + $this->contact());
    }

    public function show(string $slug): View
    {
        abort_if(Features::disabled('site.help_centre'), 404);
        $article = HelpArticles::find($slug);
        abort_if($article === null, 404);
        $related = array_values(array_filter(HelpArticles::visible(), fn ($a) => $a['topic'] === $article['topic'] && $a['slug'] !== $slug));

        return view('store.help-article', [
            'article' => $article,
            'html' => HelpArticles::html($article['body']),
            'related' => array_slice($related, 0, 5),
        ] + $this->contact());
    }

    /** @return array<string, mixed> */
    private function contact(): array
    {
        $whatsapp = preg_replace('/\D+/', '', Settings::string('site.whatsapp') ?: Settings::string('site.support_phone'));
        $telegram = ltrim(Settings::string('site.telegram_support'), '@');

        return [
            'topics' => ContactMessage::topics(),
            'whatsapp' => $whatsapp !== '' ? 'https://wa.me/'.$whatsapp : null,
            'telegram' => $telegram !== '' && preg_match('/^[A-Za-z0-9_]{4,40}$/', $telegram) ? 'https://t.me/'.$telegram : null,
            'email' => Settings::string('site.support_email'),
        ];
    }
}
