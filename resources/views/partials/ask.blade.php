{{-- One "Are you sure?" box for every form with data-confirm (filled in by app.js). Bottom sheet on phones. --}}
<dialog data-ask aria-labelledby="ask-text"
        class="m-auto mb-0 w-full max-w-none rounded-t-2xl bg-white p-0 text-ink-900 shadow-xl backdrop:bg-ink-900/50 sm:mb-auto sm:max-w-sm sm:rounded-2xl dark:bg-ink-800 dark:text-mist-50">
    <form method="dialog" class="p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]">
        <h2 id="ask-text" data-ask-text class="text-lg font-semibold">Are you sure?</h2>
        <p data-ask-more class="muted mt-1 text-sm" hidden></p>
        <div class="mt-5 grid grid-cols-2 gap-3">
            <button value="cancel" class="btn-ghost min-h-12 justify-center" autofocus>Cancel</button>
            <button value="ok" class="btn-primary min-h-12 justify-center" data-ask-ok>Yes</button>
        </div>
    </form>
</dialog>
