<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Membership\Gifts;
use App\Models\MembershipGift;
use App\Models\User;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use InvalidArgumentException;

class GiftController extends Controller
{
    /** The "give a month" page (also for guests). */
    public function form(): View
    {
        abort_if(Features::disabled('membership') || Features::disabled('membership.gifts'), 404);

        return view('store.gift', ['bank' => Settings::string('payments.bank_details'), 'telebirr' => Settings::string('payments.telebirr_details')]);
    }

    /** The receiver's private page. Never cached (it has the private code in it). */
    public function show(Request $request, string $code): Response
    {
        abort_if(Features::disabled('membership') || Features::disabled('membership.gifts'), 404);
        $gift = MembershipGift::query()->where('code', $code)->first();
        abort_if(! $gift || in_array($gift->status, ['pending', 'refunded'], true), 404);

        return response()->view('store.gift-redeem', ['gift' => $gift, 'user' => $request->user()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function redeem(Request $request, Gifts $gifts, string $code): RedirectResponse
    {
        abort_if(Features::disabled('membership') || Features::disabled('membership.gifts'), 404);
        /** @var User $user */
        $user = $request->user();
        try {
            $gifts->redeem($code, $user);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['gift' => $e->getMessage()]);
        }

        return redirect('/account')->with('status', 'Your gift month has started. Welcome! 🎉');
    }
}
