<?php

namespace App\Home;

use App\Models\HomePageVersion;
use App\Models\User;
use App\Storefront\PageCache;
use App\Support\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Draft → publish (now or at a set time) → history → restore.
 * Every publish clears the page cache and is written in the activity log.
 */
final class HomePublisher
{
    /**
     * What the editor starts from: the draft, else the live page, else the current fixed page.
     *
     * @return array{sections:list<array{id:string, type:string, on:bool, data:array<string, mixed>}>, seo:array{title:string, description:string, image:?int}, revision:int, saved:bool, updated_at:?string}
     */
    public function working(): array
    {
        $draft = HomePageVersion::draft();
        $from = $draft ?? HomePageVersion::scheduled() ?? HomePageVersion::live();

        return [
            'sections' => $from ? Sections::normalize($from->sections) : HomeTemplates::sections('current'),
            'seo' => Sections::seo($from?->seo),
            'revision' => $draft->revision ?? 0,
            'saved' => $draft !== null,
            'updated_at' => $draft?->updated_at->toIso8601String(),
        ];
    }

    /**
     * Autosave. $revision is the one the editor last saw: an older one means the
     * draft was changed in another tab or by someone else (409, nothing is lost).
     *
     * @param  array<string, mixed>  $seo
     */
    public function saveDraft(mixed $sections, mixed $seo, int $revision, User $user): HomePageVersion
    {
        return DB::transaction(function () use ($sections, $seo, $revision, $user) {
            $draft = HomePageVersion::query()->where('status', HomePageVersion::DRAFT)->lockForUpdate()->latest('id')->first();
            if ($draft && $draft->revision !== $revision) {
                throw new ConflictHttpException('This draft was changed in another window. Reload to see the newest version.');
            }
            $draft ??= new HomePageVersion(['status' => HomePageVersion::DRAFT, 'revision' => 0]);
            $draft->fill([
                'sections' => Sections::normalize($sections), 'seo' => Sections::seo($seo),
                'revision' => $draft->revision + 1, 'created_by' => $user->id,
            ])->save();

            return $draft;
        });
    }

    public function discard(): void
    {
        HomePageVersion::query()->where('status', HomePageVersion::DRAFT)->delete();
    }

    /** Publish the draft (or what the editor shows when there is no draft yet). */
    public function publish(User $user, ?string $note = null): HomePageVersion
    {
        $v = DB::transaction(function () use ($user, $note) {
            $w = $this->working();
            $this->assertPublishable($w['sections'], $w['seo']);
            // The editor showed the planned version (no draft): publishing it now replaces the plan.
            $statuses = HomePageVersion::draft() ? [HomePageVersion::DRAFT] : [HomePageVersion::DRAFT, HomePageVersion::SCHEDULED];
            HomePageVersion::query()->whereIn('status', $statuses)->delete();

            return HomePageVersion::query()->create([
                'status' => HomePageVersion::PUBLISHED, 'sections' => $w['sections'], 'seo' => $w['seo'],
                'note' => $note !== null ? mb_substr(trim($note), 0, 200) : null,
                'published_at' => now(), 'created_by' => $user->id, 'published_by' => $user->id,
            ]);
        });
        $this->published($v, 'home.published');

        return $v;
    }

    /** The draft is published by itself at $at (the scheduler checks every minute). */
    public function schedule(User $user, Carbon $at): HomePageVersion
    {
        if ($at->lte(now()->addMinute()) || $at->gt(now()->addYear())) {
            throw ValidationException::withMessages(['publish_at' => 'Pick a time in the future (within a year).']);
        }

        return DB::transaction(function () use ($user, $at) {
            $w = $this->working();
            $this->assertPublishable($w['sections'], $w['seo']);
            HomePageVersion::query()->whereIn('status', [HomePageVersion::DRAFT, HomePageVersion::SCHEDULED])->delete();
            $v = HomePageVersion::query()->create([
                'status' => HomePageVersion::SCHEDULED, 'sections' => $w['sections'], 'seo' => $w['seo'],
                'publish_at' => $at, 'created_by' => $user->id, 'published_by' => $user->id,
            ]);
            Activity::log('home.scheduled', $v, ['publish_at' => $at->toIso8601String()]);

            return $v;
        });
    }

    /** Stop a planned publish: it becomes the draft again (unless there is a newer draft). */
    public function cancelSchedule(): void
    {
        DB::transaction(function () {
            $s = HomePageVersion::scheduled();
            if (! $s) {
                return;
            }
            if (HomePageVersion::draft()) {
                throw ValidationException::withMessages(['schedule' => 'You have a newer draft. Publish or discard it first, then cancel the planned publish.']);
            }
            $s->forceFill(['status' => HomePageVersion::DRAFT, 'publish_at' => null, 'revision' => 1])->save();
            Activity::log('home.schedule_cancelled', $s);
        });
    }

    /** Called every minute by the scheduler. */
    public function publishDue(): ?HomePageVersion
    {
        $v = HomePageVersion::query()->where('status', HomePageVersion::SCHEDULED)->where('publish_at', '<=', now())->latest('id')->first();
        if (! $v) {
            return null;
        }
        $problems = Sections::problems(Sections::normalize($v->sections), Sections::seo($v->seo));
        if ($problems['errors'] !== []) {
            // Something changed since it was planned (a picture was deleted…): wait for a person.
            $v->forceFill(['status' => HomePageVersion::DRAFT, 'publish_at' => null])->save();
            Activity::log('home.schedule_failed', $v, ['errors' => array_column($problems['errors'], 'message')], 'Planned publish stopped', $v->published_by);

            return null;
        }
        $v->forceFill(['status' => HomePageVersion::PUBLISHED, 'published_at' => now(), 'publish_at' => null])->save();
        $this->published($v, 'home.published', $v->published_by, ['scheduled' => true]);

        return $v;
    }

    /** Put an old version back as the live page (a new version, so history stays complete). */
    public function restore(HomePageVersion $old, User $user): HomePageVersion
    {
        abort_unless($old->status === HomePageVersion::PUBLISHED, 404);
        $sections = Sections::normalize($old->sections);
        $seo = Sections::seo($old->seo);
        $this->assertPublishable($sections, $seo);
        $v = HomePageVersion::query()->create([
            'status' => HomePageVersion::PUBLISHED, 'sections' => $sections, 'seo' => $seo, 'restored_from_id' => $old->id,
            'note' => "Restored version #{$old->id}", 'published_at' => now(), 'created_by' => $user->id, 'published_by' => $user->id,
        ]);
        $this->published($v, 'home.restored', null, ['from' => $old->id]);

        return $v;
    }

    /**
     * @param  list<array{id:string, type:string, on:bool, data:array<string, mixed>}>  $sections
     * @param  array{title:string, description:string, image:?int}  $seo
     */
    private function assertPublishable(array $sections, array $seo): void
    {
        $errors = Sections::problems($sections, $seo)['errors'];
        if ($errors !== []) {
            throw ValidationException::withMessages(['sections' => array_column($errors, 'message')]);
        }
    }

    /** @param array<string, mixed> $extra */
    private function published(HomePageVersion $v, string $action, ?int $actor = null, array $extra = []): void
    {
        HomePageVersion::forgetMemo();
        PageCache::clear();
        Activity::log($action, $v, ['version' => $v->id, 'sections' => count($v->sections), ...$extra], null, $actor);
    }
}
