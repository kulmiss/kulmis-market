<section class="mt-12" aria-labelledby="contact-h">
    <h2 id="contact-h" class="text-2xl">Still need help?</h2>
    <div class="mt-4 flex flex-wrap gap-2">
        @if ($whatsapp)<a class="btn-ghost" href="{{ $whatsapp }}" rel="noopener" target="_blank">WhatsApp</a>@endif
        @if ($telegram)<a class="btn-ghost" href="{{ $telegram }}" rel="noopener" target="_blank">Telegram</a>@endif
        @if ($email)<a class="btn-ghost" href="mailto:{{ $email }}">Email {{ $email }}</a>@endif
    </div>
    <form class="card mt-5 grid gap-4" data-contact-form novalidate>
        <p class="muted -mb-1 text-sm">Or write to us here. We answer within one working day.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <label><span class="label">Your name</span><input name="name" class="input" required maxlength="80" autocomplete="given-name"></label>
            <label><span class="label">Phone or email</span><input name="contact" class="input" required maxlength="160" autocomplete="email" placeholder="09… or you@example.com"></label>
        </div>
        <label><span class="label">About</span>
            <select name="topic" class="input">@foreach ($topics as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach</select>
        </label>
        <label><span class="label">Your message</span><textarea name="message" class="input min-h-32" required minlength="10" maxlength="2000"></textarea></label>
        <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true" aria-label="Leave this empty">
        <button class="btn-primary">Send</button>
        <p class="text-sm" data-form-message role="status" aria-live="polite"></p>
    </form>
</section>
