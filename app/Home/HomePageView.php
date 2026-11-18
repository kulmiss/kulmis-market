<?php

namespace App\Home;

use App\Models\HomeMedia;
use Illuminate\View\View;

/** The builder's home page as a view: the live page, a draft preview or an old version. */
final class HomePageView
{
    /**
     * @param  list<array{id:string, type:string, on:bool, data:array<string, mixed>}>  $sections
     * @param  array<string, mixed>|null  $seo
     */
    public static function make(array $sections, ?array $seo, bool $member, bool $preview = false, bool $dark = false): View
    {
        $seo = Sections::seo($seo);
        $renderer = new HomeRenderer($member, $preview);
        $image = $seo['image'] ? HomeMedia::query()->where('kind', 'image')->find($seo['image']) : null;

        return view('store.home.page', array_filter([
            'blocks' => $renderer->prepare(Sections::normalize($sections)),
            'preview' => $preview,
            'homePreview' => $preview ? ['dark' => $dark] : null,
            'fullTitle' => $seo['title'] !== '' ? $renderer->text($seo['title']) : null,
            'description' => $seo['description'] !== '' ? $renderer->text($seo['description']) : null,
            'ogImage' => $image?->shareUrl(),
            'isMember' => $member,
        ], fn ($v) => $v !== null));
    }
}
