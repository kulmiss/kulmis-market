<?php

namespace App\Http\Controllers\Web;

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
            ->orderBy('category')->orderBy('position')->orderBy('id')->get();
        $whatsapp = preg_replace('/\D+/', '', Settings::string('site.whatsapp') ?: Settings::string('site.support_phone'));
        $telegram = ltrim(Settings::string('site.telegram_support'), '@');

        return view('store.help', [
            'faqs' => $faqs->groupBy(fn (Faq $f) => $f->category ?: 'Other questions'),
            'q' => $q, 'topics' => ContactMessage::TOPICS,
            'whatsapp' => $whatsapp !== '' ? 'https://wa.me/'.$whatsapp : null,
            'telegram' => $telegram !== '' && preg_match('/^[A-Za-z0-9_]{4,40}$/', $telegram) ? 'https://t.me/'.$telegram : null,
            'email' => Settings::string('site.support_email'),
        ]);
    }
}
