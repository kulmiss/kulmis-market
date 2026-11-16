<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Order;
use App\Orders\OrderService;
use App\Support\Activity;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Order::TRANSITIONS))], 'q' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'member' => ['nullable', 'boolean'],
            'zone_id' => ['nullable', 'integer'], 'courier_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'max:200'],
        ]);
        $page = Order::query()->withCount('items')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('number', 'like', "%$v%")
                ->orWhere('phone', 'like', "%$v%")->orWhere('first_name', 'like', "%$v%")->orWhere('last_name', 'like', "%$v%")))
            ->when($request->input('from'), fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->when($request->has('member'), fn ($q) => $q->where('is_member_order', $request->boolean('member')))
            ->when($request->input('zone_id'), fn ($q, $v) => $q->where('shipping_zone_id', $v))
            ->when($request->input('courier_id'), fn ($q, $v) => $q->where('courier_id', $v))
            ->tap(fn ($q) => TableQuery::sort($q, $request, ['number' => 'id', 'total' => 'total', 'status' => 'status', 'items' => 'items_count', 'placed' => 'created_at', 'name' => 'first_name']))->paginate((int) $request->input('per_page', 25));

        return response()->json($page);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(['data' => $order->load(['items', 'history', 'user:id,first_name,last_name,phone'])
            ->append([])->toArray() + ['allowed_next' => Order::TRANSITIONS[$order->status] ?? []]]);
    }

    /** Move along paid → making → shipped → delivered (or cancel). */
    public function status(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Order::TRANSITIONS))],
            'note' => ['nullable', 'string', 'max:500'],
            'courier_id' => ['nullable', 'integer', 'exists:couriers,id'],
            'tracking_number' => ['nullable', 'string', 'max:80'],
        ]);
        if ($data['status'] === Order::SHIPPED) {
            $courier = $data['courier_id'] ?? $order->courier_id;
            if (! $courier) {
                throw ValidationException::withMessages(['courier_id' => 'Choose the courier before marking as shipped.']);
            }
            $order->update(['courier_id' => $courier, 'tracking_number' => $data['tracking_number'] ?? $order->tracking_number]);
        }
        if ($data['status'] === Order::PAID) {
            throw ValidationException::withMessages(['status' => 'Orders are marked paid by a confirmed payment (Payments).']);
        }
        if ($data['status'] === Order::REFUNDED) {
            throw ValidationException::withMessages(['status' => 'Use the refund action (Payments) to refund an order.']);
        }
        try {
            $this->orders->transition($order, $data['status'], $data['note'] ?? null, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return $this->show($order->fresh());
    }

    public function shipping(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'courier_id' => ['nullable', 'integer', 'exists:couriers,id'],
            'tracking_number' => ['nullable', 'string', 'max:80'],
        ]);
        $order->update($data);
        Activity::log('order.shipping_updated', $order, $data);

        return $this->show($order->fresh());
    }

    /** Bulk move, e.g. many paid orders → making. Reports per-order failures. */
    public function bulkStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer'],
            'status' => ['required', Rule::in([Order::MAKING, Order::DELIVERED, Order::CANCELLED])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $done = [];
        $failed = [];
        foreach (Order::query()->whereKey($data['ids'])->get() as $order) {
            try {
                $this->orders->transition($order, $data['status'], $data['note'] ?? null, $request->user()?->id);
                $done[] = $order->number;
            } catch (InvalidArgumentException $e) {
                $failed[$order->number] = $e->getMessage();
            }
        }

        return response()->json(['updated' => $done, 'failed' => (object) $failed]);
    }

    public function couriers(): JsonResponse
    {
        return response()->json(['data' => Courier::query()->orderBy('name')->get()]);
    }
}
