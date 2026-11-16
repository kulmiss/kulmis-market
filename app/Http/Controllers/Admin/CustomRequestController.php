<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendCustomerMessage;
use App\Models\CustomRequest;
use App\Models\Order;
use App\Notify\Message;
use App\Shop\Deposits;
use App\Support\Activity;
use App\Support\Settings;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CustomRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = CustomRequest::query()->with('order:id,number,status,total,deposit_amount,deposit_paid')
            ->when($request->input('status', 'new'), fn ($q, $v) => $v === 'all' ? $q : $q->where('status', $v))
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', "%$v%")->orWhere('phone', 'like', "%$v%")->orWhere('description', 'like', "%$v%")))
            ->tap(fn ($q) => TableQuery::sort($q, $request, ['created' => 'created_at'], '-created_at'))->paginate(min(200, (int) $request->input('per_page', 50)));
        $page->getCollection()->transform(fn (CustomRequest $r) => $r->toArray() + [
            'photo_urls' => array_map(fn ($p) => Storage::disk('public')->url($p), $r->photos ?? []),
            'deposit_bps' => Settings::int('custom.deposit_bps'),
        ]);

        return response()->json($page);
    }

    public function quote(Request $request, Deposits $deposits, CustomRequest $custom): JsonResponse
    {
        $data = $request->validate(['price' => ['required', 'integer', 'min:100'], 'cost' => ['nullable', 'integer', 'min:0'],
            'making_days' => ['required', 'integer', 'min:1', 'max:365'], 'deposit_bps' => ['nullable', 'integer', 'min:1000', 'max:10000'],
            'note' => ['nullable', 'string', 'max:300']]);
        try {
            $order = $deposits->quote($custom, $data['price'], (int) ($data['cost'] ?? 0), $data['making_days'],
                (int) ($data['deposit_bps'] ?? Settings::int('custom.deposit_bps')), $data['note'] ?? null, (int) $request->user()->id);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['price' => $e->getMessage()]);
        }

        return response()->json(['data' => ['order' => $order->number, 'deposit' => $order->deposit_amount]]);
    }

    public function decline(Request $request, CustomRequest $custom): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $custom->update(['status' => 'declined', 'decline_reason' => $data['reason']]);
        SendCustomerMessage::dispatch(new Message('About your custom order request', "Hi {$custom->name}, thank you for asking. Sorry, we can't make this one: {$data['reason']}",
            $custom->phone, $custom->email, type: 'order_cancelled'));
        Activity::log('custom.declined', $custom, [], $data['reason']);

        return response()->json(['data' => ['status' => 'declined']]);
    }

    /** "It's ready, please pay the rest" (pre-orders and custom orders). */
    public function askRest(Deposits $deposits, Order $order): JsonResponse
    {
        try {
            $deposits->askForTheRest($order);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return response()->json(['data' => ['ok' => true]]);
    }
}
