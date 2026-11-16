<?php

namespace App\Http\Controllers\Admin;

use App\Badges\ProfitGuard;
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
            'confirm_currency_change' => ['sometimes', 'boolean'],
        ]);
        foreach (array_keys($data['settings']) as $key) {
            if (! Settings::editable((string) $key)) {
                throw ValidationException::withMessages(["settings.$key" => 'Unknown setting.']);
            }
        }
        $currencyChanges = array_filter($data['settings'], fn ($v, $k) => str_starts_with((string) $k, 'currency.') && Settings::get((string) $k) !== $v, ARRAY_FILTER_USE_BOTH);
        if ($currencyChanges !== [] && ! ($data['confirm_currency_change'] ?? false)) {
            throw ValidationException::withMessages(['confirm_currency_change' => 'Changing the currency does not convert existing prices, balances or earnings. '
                .'They keep their numbers and only the symbol and format change (5,000 ETB becomes 5,000 in the new currency). Confirm to save.']);
        }
        try {
            DB::transaction(function () use ($data, $request) {
                foreach ($data['settings'] as $key => $value) {
                    Settings::set((string) $key, $value, $request->user()?->id, $data['reason'] ?? null);
                }
                if (array_filter(array_keys($data['settings']), fn ($k) => preg_match(ProfitGuard::PATTERN, (string) $k))) {
                    ProfitGuard::assertSafe(); // you always keep at least 65% of every membership
                }
                if (Settings::string('currency.thousands') === Settings::string('currency.decimal_point')) {
                    throw ValidationException::withMessages(['settings.currency.decimal_point' => 'The decimal separator must be different from the thousands separator.']);
                }
            });
        } finally {
            Settings::flush(); // values read inside a rolled-back transaction must not stay cached
        }

        return $this->index();
    }
}
