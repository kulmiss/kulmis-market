<?php

namespace App\Console\Commands;

use App\Demo\Story;
use App\Support\TestClock;
use Illuminate\Console\Command;
use RuntimeException;

/** Local testing: the people of TESTING_GUIDE.md (Abdi and his team). */
class StoryCommand extends Command
{
    protected $signature = 'km:story {--team : Also sign up Sara → Hana → Omar → Ali (not paid), to skip the sign-up steps}';

    protected $description = 'Local only: make the people of the testing guide (Abdi, his first 3 invites, the owner)';

    public function handle(Story $story): int
    {
        if (! TestClock::allowed()) {
            $this->error('Only on your own computer (APP_ENV=local).');

            return self::FAILURE;
        }
        if (TestClock::active()) {
            $this->warn('The test clock is moved forward. Run: php artisan km:test-clock --reset (and start clean) first.');

            return self::FAILURE;
        }
        try {
            $people = $story->build((bool) $this->option('team'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Day 0 is now ('.now()->format('j M Y H:i').'). Password for everyone: '.Story::PASSWORD);
        $this->table(['Who', 'Email', 'Code', 'Notes'], collect($people)->map(fn ($u, $k) => [
            $u->first_name, $u->email, $u->referral_code ?? '', match ($k) {
                'owner' => 'Super Admin (/admin)', 'abdi' => 'member until day 30, 2,250 ETB ready, Bronze badge',
                'bekele', 'chaltu', 'dawit' => "Abdi's first invites", default => 'signed up, not paid',
            },
        ])->values()->all());
        $this->line('Last month for the badge bonus: '.now()->startOfMonth()->subMonthNoOverflow()->format('Y-m'));

        return self::SUCCESS;
    }
}
