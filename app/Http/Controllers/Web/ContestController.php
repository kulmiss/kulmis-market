<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\User;
use App\Rewards\Challenges;
use App\Rewards\Contests;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContestController extends Controller
{
    public function index(Request $request, Contests $contests, Challenges $challenges): View
    {
        abort_if(Features::disabled('referral') || (Features::disabled('referral.contests') && Features::disabled('referral.challenges')), 404);
        /** @var User $user */
        $user = $request->user();
        $list = Features::enabled('referral.contests')
            ? Contest::query()->where('status', '!=', 'cancelled')->where('ends_at', '>', now()->subDays(60))->orderBy('ends_at')->get()
            : collect();

        return view('member.contests', [
            'contests' => $list->map(fn (Contest $c) => ['contest' => $c, 'phase' => $c->phase(),
                'board' => in_array($c->phase(), ['running', 'checking'], true) ? $contests->leaderboard($c) : [],
                'mine' => in_array($c->phase(), ['running', 'checking'], true) ? $contests->mine($c, $user) : null]),
            'challenges' => $challenges->progress($user),
            'user' => $user,
        ]);
    }
}
