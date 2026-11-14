<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Settings::all()]);
    }

    /** Bulk update: {"settings": {"membership.price": 550000}, "reason": "..."} — all or nothing. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        foreach (array_keys($data['settings']) as $key) {
            if (! Settings::exists((string) $key)) {
                throw ValidationException::withMessages(["settings.$key" => 'Unknown setting.']);
            }
        }
        DB::transaction(function () use ($data, $request) {
            foreach ($data['settings'] as $key => $value) {
                Settings::set((string) $key, $value, $request->user()?->id, $data['reason'] ?? null);
            }
        });
        Settings::flush();

        return $this->index();
    }
}
