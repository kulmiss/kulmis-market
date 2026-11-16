<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendCustomerMessage;
use App\Models\ContactMessage;
use App\Models\User;
use App\Notify\Message;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Help centre contact form: no account needed; rate-limited with a hidden spam trap. */
class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'contact' => ['required', 'string', 'max:160'],
            'topic' => ['nullable', Rule::in(array_keys(ContactMessage::TOPICS))],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            'website' => ['nullable', 'max:0'], // spam trap: people never see this field
        ], ['message.min' => 'Please write a little more (at least 10 letters) so we can help.']);
        $contact = trim($data['contact']);
        if (! filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $phone = Phone::normalize($contact);
            if ($phone === null) {
                throw ValidationException::withMessages(['contact' => 'Type a phone number (09…) or an email address so we can answer you.']);
            }
            $contact = $phone;
        }
        $user = auth('sanctum')->user();
        $msg = ContactMessage::query()->create([
            'user_id' => $user instanceof User ? $user->id : null,
            'name' => strip_tags($data['name']), 'contact' => $contact, 'topic' => $data['topic'] ?? null,
            'message' => $data['message'], 'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
        ]);
        $support = Settings::string('site.support_email');
        if ($support !== '') {
            SendCustomerMessage::dispatch(new Message('Help centre: new message #'.$msg->id,
                "From: {$msg->name} ({$msg->contact})\nTopic: ".(ContactMessage::TOPICS[$msg->topic ?? 'other'] ?? 'Something else')."\n\n".$msg->message
                ."\n\nAnswer it, then mark it answered in Admin → Help centre.", null, $support));
        }

        return response()->json(['data' => ['message' => 'Thank you! We got your message and will answer within one working day.']], 201);
    }
}
