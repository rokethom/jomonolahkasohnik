<?php

namespace App\Http\Controllers\Api;

use App\Actions\Order\AcceptOrder;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Services\DriverSuspendService;
use App\Services\DriverFinanceService;
use App\Services\OrderAdjustmentService;
use App\Services\DriverRequestOrderService;
use App\Services\MultiOrderService;
use App\Services\OrderService;
use App\Services\PricingParser;
use App\Services\SettingService;
use App\Services\SuspendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;

class DriverController extends Controller
{
    public function bootstrap(Request $request, MultiOrderService $multiOrder, SettingService $settings, DriverFinanceService $finance, OrderService $orders): JsonResponse
    {
        $orders->cancelExpiredCreatedOrders();

        $driver = $this->ensureDriver($request)->load('user');

        $orders = Order::query()
            ->with(['user', 'driver.user', 'adjustments'])
            ->where(function ($query) use ($driver): void {
                $query->where('driver_id', $driver->id)
                    ->orWhere(function ($query) use ($driver): void {
                        $query->whereIn('status', [OrderStatus::Created->value, OrderStatus::SearchingDriver->value])
                            ->where('branch_id', $driver->user?->branch_id);
                    });
            })
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'driver' => $this->driverPayload($request),
            'settings' => [
                'multi_order_enabled' => $settings->bool('multi_order_enabled', true),
                'max_multi_order' => max(1, min(3, $settings->int('max_multi_order', 3))),
            ],
            'finance' => $finance->monthlyDeposit($driver->load('user.branch')),
            'performance' => $finance->performance($driver),
            'orders' => $orders->map(fn (Order $order): array => [
                ...$this->orderPayload($order),
                'eligibility' => $multiOrder->canAcceptOrder($driver, $order),
            ]),
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $this->ensureDriver($request);

        return response()->json([
            'data' => $this->driverPayload($request),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $this->ensureDriver($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username,'.$request->user()->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        if (! filled($payload['password'] ?? null)) {
            unset($payload['password']);
        } else {
            $payload['password'] = Hash::make($payload['password']);
        }

        $request->user()->update($payload);

        return $this->profile($request);
    }

    public function accept(Request $request, Order $order, AcceptOrder $acceptOrder): JsonResponse
    {
        $driver = $this->ensureDriver($request);

        try {
            $order = $acceptOrder->handle($order, $driver);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Order accepted',
            'data' => $order,
        ]);
    }

    public function canAccept(Request $request, Order $order, MultiOrderService $multiOrder): JsonResponse
    {
        $driver = $this->ensureDriver($request);

        return response()->json($multiOrder->canAcceptOrder($driver, $order));
    }

    public function previewRequest(Request $request, PricingParser $pricingParser): JsonResponse
    {
        $this->ensureDriver($request);

        $data = $request->validate([
            'raw_text' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => [
                'price' => $pricingParser->parse($data['raw_text']),
            ],
        ]);
    }

    public function requestOrder(Request $request, DriverRequestOrderService $driverRequestOrder, SuspendService $suspensions): JsonResponse
    {
        $driver = $this->ensureDriver($request);
        abort_unless($suspensions->canRequestOrder($driver), 422, 'Driver tidak bisa request order karena suspend setoran/permanent.');

        $data = $request->validate([
            'raw_text' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $order = $driverRequestOrder->create($driver, $data['raw_text']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Driver request order created',
            'data' => $order,
        ], 201);
    }

    public function operHandle(Request $request, Order $order): JsonResponse
    {
        $driver = $this->ensureDriver($request);
        abort_unless((int) $order->driver_id === (int) $driver->id, 403);
        abort_unless(in_array($order->status, [
            OrderStatus::DriverAccepted,
            OrderStatus::DriverOnTheWay,
            OrderStatus::ArrivedPickup,
            OrderStatus::OnGoing,
            OrderStatus::PendingCancel,
        ], true), 422, 'Oper handle hanya bisa untuk order aktif.');

        $hasPendingRequest = \App\Models\OperHandleRequest::query()
            ->where('order_id', $order->id)
            ->where('driver_id', $driver->id)
            ->where('status', 'pending')
            ->exists();

        abort_if($hasPendingRequest, 422, 'Oper handle order ini masih menunggu approval.');

        $payload = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'proof' => ['nullable', 'image', 'max:4096'],
        ]);

        $proofPath = isset($payload['proof']) ? $request->file('proof')?->store('oper-proofs', 'public') : null;
        $requestRow = \App\Models\OperHandleRequest::query()->create([
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'requested_by' => $request->user()->id,
            'reason' => $payload['reason'] ?? null,
            'proof_path' => $proofPath,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Oper handle menunggu approval Operator dan SPV',
            'data' => $requestRow,
        ]);
    }

    public function finance(Request $request, DriverFinanceService $finance): JsonResponse
    {
        $driver = $this->ensureDriver($request)->load('user.branch');

        return response()->json(['data' => $finance->monthlyDeposit($driver)]);
    }

    public function performance(Request $request, DriverFinanceService $finance): JsonResponse
    {
        $driver = $this->ensureDriver($request)->load('user.branch');

        return response()->json(['data' => $finance->performance($driver)]);
    }

    public function adjustOrder(Request $request, Order $order, OrderAdjustmentService $adjustments): JsonResponse
    {
        $driver = $this->ensureDriver($request);

        $payload = $request->validate([
            'amount' => ['required', 'integer', 'min:1000', 'max:500000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $adjustment = $adjustments->create($order, $driver, $payload['amount'], $payload['reason']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Service charge tambahan terkirim ke customer',
            'data' => $adjustment,
            'order' => $order->fresh(['user', 'driver.user', 'adjustments']),
        ], 201);
    }

    private function ensureDriver(Request $request): Driver
    {
        $user = $request->user()->loadMissing('driver');
        $role = $user->role instanceof UserRole
            ? $user->role->value
            : strtolower((string) $user->role);

        abort_unless($role === UserRole::Driver->value || $user->driver !== null, 403, 'Akun ini bukan driver.');

        if ($user->driver === null) {
            $user->driver()->create([
                'is_available' => true,
                'status' => 'active',
                'bpjs_jht_enabled' => true,
            ]);

            $user->load('driver');
        }

        return $user->driver;
    }

    private function driverPayload(Request $request): array
    {
        $user = $request->user()->load('driver.suspensions');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => 'Driver',
            'status' => $user->driver?->status ?? ($user->is_suspended ? 'suspended' : 'active'),
            'suspended_until' => $user->driver?->suspended_until?->toIso8601String() ?? $user->suspended_until?->toIso8601String(),
            'suspension_reason' => $user->suspension_reason,
            'oper_handle_count' => $user->driver?->oper_handle_count ?? 0,
        ];
    }

    private function orderPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'code' => $order->order_code,
            'status' => $order->status->value,
            'customer' => $order->user?->name ?? 'Customer',
            'customer_phone' => $order->user?->phone,
            'service' => $order->service_type,
            'distance_km' => (float) $order->distance_km,
            'pickup' => $order->pickup_address,
            'pickup_lat' => (float) $order->pickup_lat,
            'pickup_lng' => (float) $order->pickup_lng,
            'destination' => $order->destination_address,
            'destination_lat' => (float) $order->destination_lat,
            'destination_lng' => (float) $order->destination_lng,
            'direction_bearing' => $order->direction_bearing !== null ? (float) $order->direction_bearing : null,
            'is_multi_order' => $order->is_multi_order,
            'price' => $order->price,
            'service_fee' => $order->service_charge,
            'extra_charge' => $order->extra_charge,
            'total' => $order->total_price,
            'notes' => $order->notes,
            'detail' => $order->raw_text,
            'accepted_at' => in_array($order->status->value, ['DRIVER_ACCEPTED', 'DRIVER_ON_THE_WAY', 'ARRIVED_PICKUP', 'ON_GOING'], true)
                ? $order->updated_at?->toIso8601String()
                : null,
            'expired_at' => $order->expired_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'adjustments' => $order->adjustments,
        ];
    }
}
