<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ShareKitItem;
use App\Models\User;
use App\Referral\ReferralCodes;
use App\Referral\ShareLinks;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShareController extends Controller
{
    public function index(Request $request): View
    {
        abort_if(Features::disabled('referral') || Features::disabled('referral.share_kit'), 404);
        /** @var User $user */
        $user = $request->user();
        app(ReferralCodes::class)->assign($user);
        $items = ShareKitItem::query()->where('is_active', true)->orderBy('position')->orderBy('id')->get()->filter(fn (ShareKitItem $i) => $i->shownNow())
            ->map(fn (ShareKitItem $i) => ['item' => $i, 'text' => $i->textFor($user), 'link' => ShareLinks::for($user, $i->path()),
                'buttons' => ShareLinks::buttons(ShareLinks::for($user, $i->path()), $i->textFor($user))]);

        return view('member.share', ['items' => $items, 'user' => $user]);
    }
}
