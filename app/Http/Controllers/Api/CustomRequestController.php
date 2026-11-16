<?php

namespace App\Http\Controllers\Api;

use App\Catalog\ImageProcessor;
use App\Http\Controllers\Controller;
use App\Jobs\SendCustomerMessage;
use App\Models\CustomRequest;
use App\Models\User;
use App\Notify\Message;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** "Make this for me" form: no account needed; photos re-encoded; rate-limited with a spam trap. */
class CustomRequestController extends Controller
{
    public function store(Request $request, ImageProcessor $images): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'phone' => ['required', 'string', 'max:20'], 'email' => ['nullable', 'email', 'max:160'],
            'city' => ['required', 'string', 'max:80'], 'address' => ['required', 'string', 'max:500'],
            'description' => ['required', 'string', 'min:15', 'max:3000'], 'budget' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'photos' => ['nullable', 'array', 'max:3'], 'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'website' => ['nullable', 'max:0'],
        ], ['description.min' => 'Please tell us a little more (size, colour, what it is for).']);
        $phone = Phone::normalize($data['phone']) ?? throw ValidationException::withMessages(['phone' => 'Enter a valid phone number (09…).']);
        $paths = [];
        foreach ($request->file('photos', []) as $file) {
            $paths[] = $images->photo($file, 'custom-requests', 1200);
        }
        $user = auth('sanctum')->user();
        $req = CustomRequest::query()->create([
            'user_id' => $user instanceof User ? $user->id : null, 'name' => strip_tags($data['name']), 'phone' => $phone, 'email' => $data['email'] ?? null,
            'city' => strip_tags($data['city']), 'address' => strip_tags($data['address']), 'description' => $data['description'], 'photos' => $paths ?: null,
            'budget' => isset($data['budget']) ? (int) round(((float) $data['budget']) * 100) : null,
        ]);
        if (($support = Settings::string('site.support_email')) !== '') {
            SendCustomerMessage::dispatch(new Message('Custom order request #'.$req->id, "From {$req->name} ({$req->phone}), {$req->city}:\n\n{$req->description}\n\nAnswer it in Admin → Custom orders.", null, $support));
        }

        return response()->json(['data' => ['message' => 'Thank you! We will look at it and send you a price within 2 working days.']], 201);
    }
}
