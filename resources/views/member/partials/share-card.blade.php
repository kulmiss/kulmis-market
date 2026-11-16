{{-- One "share" block: text, buttons, picture card made on the phone (canvas). --}}
@php use App\Badges\BadgeIcons as I; @endphp
<div class="grid gap-2" data-share-block>
    <textarea class="m-input min-h-24 text-sm [field-sizing:content]" rows="5" readonly data-copy-source aria-label="Text to share">{{ $text }}</textarea>
    <div class="flex flex-wrap gap-2">
        <button type="button" class="m-btn" data-copy>{!! I::svg('copy', 16) !!}Copy</button>
        @feature('referral.share_buttons')
        <a class="m-btn-ghost" href="{{ $buttons['whatsapp'] }}" target="_blank" rel="noopener">{!! I::svg('message-circle', 16) !!}WhatsApp</a>
        <a class="m-btn-ghost" href="{{ $buttons['telegram'] }}" target="_blank" rel="noopener">{!! I::svg('send', 16) !!}Telegram</a>
        <a class="m-btn-ghost" href="{{ $buttons['facebook'] }}" target="_blank" rel="noopener">Facebook</a>
        @endfeature
        <button type="button" class="m-btn-ghost" data-card data-card-image="{{ $image }}" data-card-title="{{ $title }}" data-card-line="{{ $line }}"
            data-card-code="{{ $code }}" data-card-qr="{{ $qr }}" data-card-link="{{ $link }}" data-card-text="{{ $text }}">{!! I::svg('download', 16) !!}Picture card</button>
    </div>
    <p class="text-xs m-muted" data-card-msg role="status" aria-live="polite"></p>
</div>
