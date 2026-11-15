<?php

namespace App\Http\Controllers\Admin;

use App\Demo\Demo;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Super Admin: show/hide demo data, or delete all of it. */
class DemoController extends Controller
{
    public const CONFIRM = 'DELETE DEMO DATA';

    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'visible' => Demo::visible(),
            'exists' => Demo::exists(),
            'counts' => array_filter(Demo::counts()),
            'confirm_text' => self::CONFIRM,
            'can_seed' => ! app()->environment('production'),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['show' => ['required', 'boolean']]);
        Settings::set('demo.show', (bool) $data['show'], $request->user()?->id);

        return $this->show();
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSuperAdmin(), 403);
        $data = $request->validate(['confirm' => ['required', 'string']]);
        if ($data['confirm'] !== self::CONFIRM) {
            throw ValidationException::withMessages(['confirm' => 'Type '.self::CONFIRM.' to confirm.']);
        }
        if ($user->getAttribute('is_demo')) {
            throw ValidationException::withMessages(['confirm' => 'Sign in with your own Super Admin account to delete demo data.']);
        }
        $deleted = Demo::purge($user->id);

        return response()->json(['data' => ['deleted' => $deleted] + $this->show()->getData(true)['data']]);
    }
}
