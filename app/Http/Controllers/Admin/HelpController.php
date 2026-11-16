<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Faq;
use App\Support\Activity;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HelpController extends Controller
{
    public function faqs(Request $request): JsonResponse
    {
        $page = Faq::query()
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('question', 'like', "%$v%")->orWhere('answer', 'like', "%$v%")))
            ->when($request->has('published'), fn ($q) => $q->where('is_published', $request->boolean('published')))
            ->tap(fn ($q) => TableQuery::sort($q, $request, ['category' => 'category', 'position' => 'position', 'question' => 'question'], 'category'))
            ->orderBy('position')->paginate(min(200, (int) $request->input('per_page', 100)));

        return response()->json($page);
    }

    public function saveFaq(Request $request, ?Faq $faq = null): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:60'], 'question' => ['required', 'string', 'max:200'],
            'answer' => ['required', 'string', 'max:4000'], 'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['boolean'],
        ]);
        $faq ??= new Faq;
        $faq->fill($data + ['position' => 0])->save();
        Activity::log($faq->wasRecentlyCreated ? 'faq.created' : 'faq.updated', $faq);

        return response()->json(['data' => $faq], $faq->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteFaq(Faq $faq): JsonResponse
    {
        $faq->delete();
        Activity::log('faq.deleted', $faq);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function messages(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in([...ContactMessage::STATUSES, 'all'])]]);
        $page = ContactMessage::query()
            ->when($request->input('status', 'new'), fn ($q, $v) => $v === 'all' ? $q : $q->where('status', $v))
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', "%$v%")->orWhere('contact', 'like', "%$v%")->orWhere('message', 'like', "%$v%")))
            ->tap(fn ($q) => TableQuery::sort($q, $request, ['created' => 'created_at', 'status' => 'status'], '-created_at'))
            ->paginate(min(200, (int) $request->input('per_page', 50)));
        $page->getCollection()->transform(fn (ContactMessage $m) => $m->only(['id', 'name', 'contact', 'topic', 'message', 'status']) + [
            'topic_label' => ContactMessage::TOPICS[$m->topic ?? 'other'] ?? 'Something else', 'created_at' => $m->created_at?->toIso8601String(),
        ]);

        return response()->json($page);
    }

    public function updateMessage(Request $request, ContactMessage $message): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(ContactMessage::STATUSES)]]);
        $message->forceFill(['status' => $data['status'], 'answered_by' => $data['status'] === 'answered' ? $request->user()->id : null,
            'answered_at' => $data['status'] === 'answered' ? now() : null])->save();
        Activity::log('contact.'.$data['status'], $message);

        return response()->json(['data' => ['status' => $message->status]]);
    }
}
