<?php

namespace App\Membership;

use App\Jobs\SendCustomerMessage;
use App\Ledger\Ledger;
use App\Models\MembershipGift;
use App\Models\MembershipPurchase;
use App\Models\Payment;
use App\Models\User;
use App\Notify\Inbox;
use App\Notify\Message;
use App\Payments\PaymentService;
use App\Referral\Attribution;
use App\Referral\ReferralCodes;
use App\Support\Activity;
use App\Support\Currency;
use App\Support\Money;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Gift membership: someone pays one month for someone else. The receiver
 * gets a private link, signs in (or makes an account) and the month starts.
 * The giver's code counts: the receiver joins the giver's team if they had
 * nobody yet, and team earnings work as for any membership.
 */
class Gifts
{
    public const DAYS_TO_USE = 180;

    public function __construct(private readonly Ledger $ledger) {}

    /** @param array{giver_name:string, giver_phone:string, giver_email?:?string, receiver_name:string, receiver_contact:string, message?:?string} $data */
    public function create(array $data, ?User $giver, Request $request): MembershipGift
    {
        $phone = Phone::normalize($data['giver_phone']);
        if ($phone === null) {
            throw new InvalidArgumentException('Enter your phone number (09…).');
        }
        $contact = trim($data['receiver_contact']);
        if (! filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $contact = Phone::normalize($contact) ?? throw new InvalidArgumentException('Type their phone number (09…) or email.');
        }
        // Whose team the receiver joins: the giver (if they have a code), else the link they came from.
        $byPhone = $giver ?? User::query()->where('phone', $phone)->first();
        $referrer = $byPhone?->referral_code ? $byPhone : null;
        if ($referrer === null) {
            [$referrer] = app(Attribution::class)->validReferrer(app(Attribution::class)->codeFrom($request), null, $phone, $data['giver_email'] ?? null);
        }

        return MembershipGift::query()->create([
            'code' => Str::upper(Str::random(16)), 'amount' => Settings::int('membership.price'), 'status' => 'pending',
            'giver_user_id' => $giver?->id, 'giver_name' => strip_tags($data['giver_name']), 'giver_phone' => $phone,
            'giver_email' => $data['giver_email'] ?? null, 'receiver_name' => strip_tags($data['receiver_name']),
            'receiver_contact' => $contact, 'message' => isset($data['message']) ? strip_tags($data['message']) : null,
            'referrer_user_id' => $referrer?->id,
        ]);
    }

    /** Payment approved: make the link and tell both people. */
    public function paid(MembershipGift $gift): void
    {
        $gift->update(['status' => 'paid', 'paid_at' => now(), 'expires_at' => now()->addDays(self::DAYS_TO_USE)]);
        $link = url('/gift/'.$gift->code);
        $site = Settings::string('site.name');
        $isEmail = str_contains($gift->receiver_contact, '@');
        $body = "Hi {$gift->receiver_name}! {$gift->giver_name} gave you one month of {$site} membership 🎁"
            .($gift->message ? " “{$gift->message}”" : '').' Start it here (use it before '.$gift->expires_at->timezone(config('app.timezone'))->format('d M Y')."): $link";
        DB::afterCommit(function () use ($gift, $body, $isEmail, $site) {
            SendCustomerMessage::dispatch(new Message("A gift for you from {$gift->giver_name}", $body,
                $isEmail ? null : $gift->receiver_contact, $isEmail ? $gift->receiver_contact : null, type: 'gift'));
            SendCustomerMessage::dispatch(new Message('Your gift is on its way', "Thank you {$gift->giver_name}! We sent your gift to {$gift->receiver_name}. They have ".self::DAYS_TO_USE." days to start it. — $site",
                $gift->giver_phone, $gift->giver_email, type: 'gift'));
        });
    }

    /** The receiver starts their month. */
    public function redeem(string $code, User $user): MembershipPurchase
    {
        $purchase = DB::transaction(function () use ($code, $user) {
            $gift = MembershipGift::query()->where('code', $code)->lockForUpdate()->first();
            if (! $gift || $gift->status !== 'paid') {
                throw new InvalidArgumentException($gift?->status === 'redeemed' ? 'This gift was already used.' : 'This gift link is not valid.');
            }
            if ($gift->expires_at && $gift->expires_at->isPast()) {
                throw new InvalidArgumentException('This gift has expired. Please contact us.');
            }
            // Join the giver's team if nobody invited them yet (and never yourself).
            if ($user->referrer_id === null && $gift->referrer_user_id && $gift->referrer_user_id !== $user->id) {
                $ref = User::query()->find($gift->referrer_user_id);
                if ($ref && ! app(Attribution::class)->isSamePerson($ref, $user, $user->phone, $user->email)) {
                    $user->forceFill(['referrer_id' => $ref->id, 'referred_at' => now()])->save();
                    Inbox::add($ref, 'team', $user->first_name.' joined your team', 'They started a gift membership.', '/account/team', "team:{$user->id}");
                }
            }
            app(ReferralCodes::class)->assign($user);
            $purchase = MembershipPurchase::query()->create(['user_id' => $user->id, 'amount' => $gift->amount, 'status' => 'pending']);
            $payment = Payment::query()->create([
                'uuid' => (string) Str::uuid(), 'payable_type' => $purchase->getMorphClass(), 'payable_id' => $purchase->id,
                'user_id' => $user->id, 'provider' => Payment::GIFT, 'tx_ref' => 'KM-GIFT-'.$gift->id, 'amount' => $gift->amount,
                'currency' => Currency::code(), 'status' => Payment::PENDING, 'meta' => ['gift_id' => $gift->id],
            ]);
            // The money was paid when the gift was bought: now it becomes membership money.
            $this->ledger->post('gift.redeemed', [[MembershipGift::ACCOUNT, $gift->amount], ['income:memberships', -$gift->amount]],
                "gift:{$gift->id}:redeemed", $gift, description: 'Gift membership started');
            app(PaymentService::class)->markPaid($payment, note: 'Gift membership');
            $gift->update(['status' => 'redeemed', 'redeemed_by' => $user->id, 'redeemed_at' => now()]);
            Activity::log('gift.redeemed', $gift, ['user' => $user->id, 'amount' => Money::format($gift->amount)], null, $user->id);

            return $purchase;
        });

        return $purchase->fresh() ?? $purchase;
    }
}
