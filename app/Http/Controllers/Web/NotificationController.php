<?php

namespace App\Http\Controllers\Web;

use App\Cart\CartManager;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\User;
use App\Models\UserNotification;
use App\Notify\Inbox;
use App\Notify\NotifyPrefs;
use App\Referral\Goals;
use App\Signals\SignalStats;
use App\Support\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        abort_if(Features::disabled('account.notifications'), 404);
        /** @var User $user */
        $user = $request->user();
        $items = UserNotification::query()->shown()->where('user_id', $user->id)->latest('id')->paginate(30);
        $unreadIds = $items->getCollection()->whereNull('read_at')->pluck('id')->all();
        Inbox::markAllRead($user); // seen now; the list still shows which ones were new

        return view('member.notifications', ['items' => $items, 'unreadIds' => $unreadIds, 'user' => $user]);
    }

    public function settings(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $values = [];
        foreach (array_keys(NotifyPrefs::DEFAULTS) as $key) {
            if (str_starts_with($key, 'push.')) {
                continue; // push has its own switch on the phone
            }
            $field = str_replace('.', '_', $key);
            if ($request->has($field)) { // only what the form showed
                $values[$key] = $request->boolean($field);
            }
        }
        NotifyPrefs::set($user, $values);

        return back()->with('status', 'Saved. You will only hear about what you ticked.');
    }

    public function signalAlerts(Request $request): RedirectResponse
    {
        abort_if(Features::disabled('signals') || Features::disabled('account.notifications'), 404);
        $data = $request->validate(['on' => ['required', 'boolean'], 'pairs' => ['nullable', 'array', 'max:50'], 'pairs.*' => ['string', 'max:40']]);
        /** @var User $user */
        $user = $request->user();
        NotifyPrefs::set($user, ['bell.signal' => (bool) $data['on']]);
        $known = app(SignalStats::class)->pairs();
        NotifyPrefs::setPairs($user->fresh() ?? $user, array_values(array_intersect($data['pairs'] ?? [], $known)));

        return back()->with('status', $data['on'] ? 'Saved. New signals will show in your bell.' : 'Saved. No signal notes.');
    }

    public function goal(Request $request): RedirectResponse
    {
        abort_if(Features::disabled('account.goals'), 404);
        $data = $request->validate(['goal' => ['nullable', 'numeric', 'min:0', 'max:'.(Goals::MAX / 100)]]);
        $amount = (int) round(((float) ($data['goal'] ?? 0)) * 100);
        if ($amount > 0 && $amount < Goals::MIN) {
            return back()->withErrors(['goal' => 'Set at least 100 ETB, or leave it empty to remove the goal.']);
        }
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['goal_amount' => $amount ?: null])->save();

        return back()->with('status', $amount ? 'Goal saved. You can do it!' : 'Goal removed.');
    }

    /** The link in the cart reminder: opens that cart on this phone. */
    public function resumeCart(Request $request, Cart $cart): RedirectResponse
    {
        $user = $request->user();
        abort_if($user !== null && $cart->user_id !== null && $cart->user_id !== $user->getAuthIdentifier(), 403);
        Cookie::queue(Cookie::make(CartManager::COOKIE, $cart->token, 60 * 24 * 60, '/', null, null, true, false, 'lax'));

        return redirect('/cart');
    }

    /** "To stop these" link: no sign-in needed (it is signed for this person). */
    public function stopCartReminders(User $user): View
    {
        NotifyPrefs::set($user, ['cart_reminder' => false]);

        return view('store.notice', ['title' => 'Cart reminders stopped', 'text' => 'Done. We will not send you cart reminders any more. You can turn them back on in your account under Notifications.']);
    }
}
