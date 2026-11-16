<?php

namespace App\Support;

use App\Models\Banner;
use App\Models\Faq;
use App\Models\Page;
use App\Models\ShareKitItem;
use App\Notify\Message;

/**
 * For the admin: texts you edit that still talk about a switched-off feature
 * (outside {if feature}…{/if} parts), and what a customer sees of them now.
 */
final class OffFeatureTexts
{
    /**
     * @return list<array{where:string, title:string, features:list<string>, what_happens:string, link:string}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (Faq::query()->where('is_published', true)->get() as $f) {
            self::add($out, 'FAQ', $f->question, $f->question.' '.$f->answer, 'Hidden from the help page while it is off.', '/help');
        }
        foreach (Page::query()->where('is_published', true)->get() as $p) {
            $title = FeatureText::mentionsOff($p->title.' '.str_replace('-', ' ', $p->slug));
            $body = FeatureText::mentionsOff(FeatureText::markdown($p->body));
            if ($title !== [] || $body !== []) {
                $out[] = ['where' => 'Page', 'title' => $p->title, 'features' => array_values(array_unique([...$title, ...$body])),
                    'what_happens' => $title !== [] ? 'The whole page is hidden while it is off.' : 'Still shown: change the sentence or put it in {if …}…{/if}.', 'link' => '/content'];
            }
        }
        foreach (Banner::query()->get() as $b) {
            self::add($out, 'Banner', $b->title, $b->title.' '.$b->text.' '.$b->link_text, 'Hidden while it is off.', '/content');
        }
        foreach (ShareKitItem::query()->where('is_active', true)->get() as $s) {
            self::add($out, 'Share kit', $s->title, $s->title.' '.$s->text, 'Hidden from the share kit while it is off.', '/share-kit');
        }
        foreach (config('settings') as $key => $def) {
            if (($def[0] ?? '') !== 'text' || ! str_starts_with($key, 'notify.')) {
                continue;
            }
            $needs = Message::featureOf(substr($key, 7));
            if ($needs && Features::disabled($needs)) {
                continue; // this message is not sent at all while its feature is off
            }
            self::add($out, 'Message', (string) ($def[2] ?? $key), Settings::string($key), 'Still sent: change the text or put that part in {if …}…{/if}.', '/settings');
        }

        return $out;
    }

    /** @param list<array{where:string, title:string, features:list<string>, what_happens:string, link:string}> $out */
    private static function add(array &$out, string $where, string $title, string $text, string $what, string $link): void
    {
        $off = FeatureText::mentionsOff($text);
        if ($off !== []) {
            $out[] = ['where' => $where, 'title' => $title, 'features' => $off, 'what_happens' => $what, 'link' => $link];
        }
    }
}
