<?php

namespace App\Http\Controllers\Admin;

use App\Badges\ProfitGuard;
use App\Catalog\ImageProcessor;
use App\Http\Controllers\Controller;
use App\Mail\PlainMessage;
use App\Models\User;
use App\Notify\Sms\SmsGateway;
use App\Setup\SetupWizard;
use App\Support\Activity;
use App\Support\Settings;
use App\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** First-time setup (Super Admin only). Pages 2, 3 and 5 save through the normal settings, switches and demo-data endpoints. */
class SetupController extends Controller
{
    private function superAdmin(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSuperAdmin(), 403);

        return $user;
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => SetupWizard::state($this->superAdmin($request))]);
    }

    /** {"step": 3} saves where you are; {"finished": true} ends the wizard; {"restart": true} opens it again. */
    public function update(Request $request): JsonResponse
    {
        $user = $this->superAdmin($request);
        $data = $request->validate([
            'step' => ['sometimes', 'integer', 'min:1', 'max:'.SetupWizard::PAGES],
            'finished' => ['sometimes', 'accepted'],
            'restart' => ['sometimes', 'accepted'],
        ]);
        if (isset($data['step'])) {
            Settings::set('setup.step', (int) $data['step'], $user->id);
        }
        if (isset($data['finished'])) {
            Settings::set('setup.finished', true, $user->id);
            Activity::log('setup.finished', null, [], null, $user->id);
        }
        if (isset($data['restart'])) {
            Settings::set('setup.finished', false, $user->id);
            Settings::set('setup.step', 1, $user->id);
        }

        return $this->show($request);
    }

    /** "You keep at least X of every membership" for numbers not saved yet (nothing is saved). */
    public function preview(Request $request): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate(['settings' => ['required', 'array']]);
        foreach (array_keys($data['settings']) as $key) {
            if (! in_array($key, SetupWizard::PRICES, true)) {
                throw ValidationException::withMessages(["settings.$key" => 'Not a price on this page.']);
            }
        }
        $result = Settings::preview($data['settings'], fn () => ProfitGuard::worstCase());

        return response()->json(['data' => ['keep' => $result['keep'], 'total_bps' => $result['total'], 'max_bps' => $result['max'],
            'safe' => $result['total'] <= $result['max']]]);
    }

    public function logo(Request $request, ImageProcessor $images): JsonResponse
    {
        $user = $this->superAdmin($request);
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);
        try {
            $path = $images->logo($request->file('logo'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['logo' => $e->getMessage()]);
        }
        $old = Settings::string('appearance.logo');
        Settings::set('appearance.logo', $path, $user->id);
        if ($old !== '' && str_starts_with($old, 'branding/')) {
            Storage::disk('public')->delete($old);
        }

        return $this->show($request);
    }

    public function removeLogo(Request $request): JsonResponse
    {
        $user = $this->superAdmin($request);
        $old = Settings::string('appearance.logo');
        Settings::set('appearance.logo', '', $user->id);
        if (str_starts_with($old, 'branding/')) {
            Storage::disk('public')->delete($old);
        }

        return $this->show($request);
    }

    /** Sends a test message to the Super Admin (Telegram: checks the bot). Never shows a secret. */
    public function test(Request $request, string $service): JsonResponse
    {
        $user = $this->superAdmin($request);
        $site = Settings::string('site.name');
        try {
            $message = match ($service) {
                'telegram' => 'The bot @'.(app(TelegramClient::class)->call('getMe')['username'] ?? '?').' answers.',
                'email' => $this->testEmail($user, $site),
                'sms' => $this->testSms($user, $site),
                default => abort(404),
            };
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return response()->json(['data' => ['ok' => false, 'message' => 'The test did not work. Check the settings in .env, then try again.']]);
        }
        Activity::log('setup.tested', null, ['service' => $service], null, $user->id);

        return response()->json(['data' => ['ok' => true, 'message' => $message]]);
    }

    private function testEmail(User $user, string $site): string
    {
        if (! $user->email) {
            throw ValidationException::withMessages(['service' => 'Add an email to your account first (My account).']);
        }
        Mail::to($user->email)->send(new PlainMessage("Test email from $site", "It works: $site can send emails."));

        return 'We sent a test email to your address. Check your inbox (and spam).';
    }

    private function testSms(User $user, string $site): string
    {
        if (! $user->phone) {
            throw ValidationException::withMessages(['service' => 'Add a phone number to your account first.']);
        }
        app(SmsGateway::class)->send($user->phone, "It works: $site can send SMS.");

        return 'We sent a test SMS to your phone.';
    }
}
