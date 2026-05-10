<?php

namespace App\Http\Controllers\Api;

use App\Actions\Order\AcceptOrder;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverDeposit;
use App\Models\OrderCrew;
use App\Models\DriverSuspension;
use App\Models\OperHandleRequest;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

class DriverController extends Controller
{
    public function bootstrap(Request $request, MultiOrderService $multiOrder, SettingService $settings, DriverFinanceService $finance, OrderService $orders): JsonResponse
    {
        $orders->cancelExpiredCreatedOrders();

        $driver = $this->ensureDriver($request)->load('user.branch');
        $deposit = $finance->monthlyDeposit($driver);
        $billingDeposit = $finance->monthlyDeposit($driver, now()->subMonth());
        $driver = $this->syncAvailabilityForFinance($driver, $billingDeposit);
        $canReceiveOrders = $this->canReceiveOrders($driver, $billingDeposit);

        $orders = Order::query()
            ->with(['user', 'driver.user', 'adjustments', 'operHandleRequests.driver.user', 'crews.driver.user'])
            ->where(function ($query) use ($driver, $canReceiveOrders): void {
                $query->where('driver_id', $driver->id);
                $query->orWhereHas('operHandleRequests', fn ($query) => $query->where('driver_id', $driver->id));
                $query->orWhereHas('crews', fn ($query) => $query->where('driver_id', $driver->id));

                if ($canReceiveOrders) {
                    $query->orWhere(function ($query) use ($driver): void {
                        $query->whereIn('status', [OrderStatus::Created->value, OrderStatus::SearchingDriver->value])
                            ->when(! $driver->can_accept_all_areas, fn ($query) => $query->where('branch_id', $driver->user?->branch_id))
                            ->where(function ($query) use ($driver): void {
                                $query->where('pricing_breakdown->driver_preference', '!=', 'ladies')
                                    ->orWhereNull('pricing_breakdown->driver_preference')
                                    ->when((bool) $driver->is_ladies_driver, fn ($query) => $query->orWhere('pricing_breakdown->driver_preference', 'ladies'));
                            })
                            ->where(function ($query) use ($driver): void {
                                $vehicleTypes = $driver->vehicleTypes();
                                $seatRows = (int) ($driver->vehicle_seat_rows ?: 2);

                                $query->whereNull('pricing_breakdown->preferred_vehicle_type')
                                    ->when(in_array('motor', $vehicleTypes, true), fn ($query) => $query->orWhere('pricing_breakdown->preferred_vehicle_type', 'motor'))
                                    ->when(in_array('mobil', $vehicleTypes, true), function ($query) use ($seatRows): void {
                                        $query->orWhere(function ($query) use ($seatRows): void {
                                            $query->where('pricing_breakdown->preferred_vehicle_type', 'mobil')
                                                ->where(function ($query) use ($seatRows): void {
                                                    $query->whereNull('pricing_breakdown->required_vehicle_seat_rows')
                                                        ->orWhere('pricing_breakdown->required_vehicle_seat_rows', '<=', $seatRows);
                                                });
                                        });
                                    });
                            });
                    });
                }
            })
            ->latest()
            ->limit(50)
            ->get();
        $helperOrders = $canReceiveOrders ? $this->helperOpportunityOrders($driver) : collect();
        $orders = $orders->merge($helperOrders)->unique('id')->values();
        $branchId = $driver->user?->branch_id;
        $branchAcceptedOrders = $branchId
            ? Order::query()
                ->with(['user', 'driver.user', 'operHandleRequests.driver.user', 'crews.driver.user'])
                ->where('branch_id', $branchId)
                ->whereNotNull('driver_id')
                ->latest('updated_at')
                ->limit(20)
                ->get()
            : collect();
        $branchRequestOrders = $branchId
            ? Order::query()
                ->with(['user', 'driver.user', 'operHandleRequests.driver.user', 'crews.driver.user'])
                ->where('branch_id', $branchId)
                ->where('source', 'driver_request')
                ->latest('updated_at')
                ->limit(30)
                ->get()
            : collect();
        $branchOperHandleOrders = $branchId
            ? OperHandleRequest::query()
                ->with(['order.user', 'order.driver.user', 'driver.user'])
                ->whereHas('order', fn ($query) => $query->where('branch_id', $branchId))
                ->latest('updated_at')
                ->limit(12)
                ->get()
            : collect();
        $branchSuspendHistory = $branchId
            ? DriverSuspension::query()
                ->with('driver.user')
                ->whereHas('driver.user', fn ($query) => $query->where('branch_id', $branchId))
                ->latest('updated_at')
                ->limit(20)
                ->get()
            : collect();
        $branchPerformance = $branchId
            ? Driver::query()
                ->with('user.branch')
                ->whereHas('user', fn ($query) => $query->where('branch_id', $branchId))
                ->orderBy('id')
                ->limit(50)
                ->get()
                ->map(fn (Driver $branchDriver): array => $this->branchPerformancePayload($branchDriver))
                ->sortByDesc('month_revenue')
                ->values()
            : collect();

        return response()->json([
            'driver' => $this->driverPayload($request, $billingDeposit),
            'settings' => [
                'multi_order_enabled' => $settings->bool('multi_order_enabled', true),
                'max_multi_order' => max(1, min(3, $settings->int('max_multi_order', 3))),
            ],
            'finance' => $this->financePayload($deposit),
            'performance' => $finance->performance($driver),
            'branch_performance' => $branchPerformance,
            'orders' => $orders->map(fn (Order $order): array => [
                ...$this->orderPayload($order, $driver),
                'eligibility' => $multiOrder->canAcceptOrder($driver, $order),
            ]),
            'branch_accepted_orders' => $branchAcceptedOrders->map(fn (Order $order): array => $this->orderPayload($order)),
            'branch_request_orders' => $branchRequestOrders->map(fn (Order $order): array => $this->orderPayload($order)),
            'branch_oper_handle_orders' => $branchOperHandleOrders->map(fn (OperHandleRequest $request): array => [
                ...$this->orderPayload($request->order),
                'oper_handle_status' => $request->status,
                'oper_handle_driver' => $request->driver?->user?->name,
                'oper_handle_reason' => $request->reason,
                'oper_handle_updated_at' => $request->updated_at?->toIso8601String(),
            ]),
            'branch_suspend_history' => $branchSuspendHistory->map(fn (DriverSuspension $suspension): array => [
                'id' => $suspension->id,
                'driver' => $suspension->driver?->user?->name ?? 'Driver',
                'type' => $suspension->type,
                'reason' => $suspension->reason,
                'status' => $suspension->status,
                'start_at' => $suspension->start_at?->toIso8601String(),
                'end_at' => $suspension->end_at?->toIso8601String(),
                'updated_at' => $suspension->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $driver = $this->ensureDriver($request);
        app(DriverSuspendService::class)->releaseIfExpired($driver);

        return response()->json([
            'data' => $this->driverPayload($request),
        ]);
    }

    public function ordersFeed(Request $request, MultiOrderService $multiOrder, DriverFinanceService $finance, OrderService $ordersService): JsonResponse
    {
        $ordersService->cancelExpiredCreatedOrders();

        $driver = $this->ensureDriver($request)->load('user.branch');
        $billingDeposit = $finance->monthlyDeposit($driver, now()->subMonth());
        $driver = $this->syncAvailabilityForFinance($driver, $billingDeposit);
        $canReceiveOrders = $this->canReceiveOrders($driver, $billingDeposit);
        $branchId = $driver->user?->branch_id;

        $orders = Order::query()
            ->with(['user', 'driver.user', 'adjustments', 'operHandleRequests.driver.user', 'crews.driver.user'])
            ->where(function ($query) use ($driver, $canReceiveOrders): void {
                $query->where('driver_id', $driver->id);
                $query->orWhereHas('operHandleRequests', fn ($query) => $query->where('driver_id', $driver->id));
                $query->orWhereHas('crews', fn ($query) => $query->where('driver_id', $driver->id));

                if ($canReceiveOrders) {
                    $query->orWhere(function ($query) use ($driver): void {
                        $query->whereIn('status', [OrderStatus::Created->value, OrderStatus::SearchingDriver->value])
                            ->when(! $driver->can_accept_all_areas, fn ($query) => $query->where('branch_id', $driver->user?->branch_id))
                            ->where(function ($query) use ($driver): void {
                                $query->where('pricing_breakdown->driver_preference', '!=', 'ladies')
                                    ->orWhereNull('pricing_breakdown->driver_preference')
                                    ->when((bool) $driver->is_ladies_driver, fn ($query) => $query->orWhere('pricing_breakdown->driver_preference', 'ladies'));
                            })
                            ->where(function ($query) use ($driver): void {
                                $vehicleTypes = $driver->vehicleTypes();
                                $seatRows = (int) ($driver->vehicle_seat_rows ?: 2);

                                $query->whereNull('pricing_breakdown->preferred_vehicle_type')
                                    ->when(in_array('motor', $vehicleTypes, true), fn ($query) => $query->orWhere('pricing_breakdown->preferred_vehicle_type', 'motor'))
                                    ->when(in_array('mobil', $vehicleTypes, true), function ($query) use ($seatRows): void {
                                        $query->orWhere(function ($query) use ($seatRows): void {
                                            $query->where('pricing_breakdown->preferred_vehicle_type', 'mobil')
                                                ->where(function ($query) use ($seatRows): void {
                                                    $query->whereNull('pricing_breakdown->required_vehicle_seat_rows')
                                                        ->orWhere('pricing_breakdown->required_vehicle_seat_rows', '<=', $seatRows);
                                                });
                                        });
                                    });
                            });
                    });
                }
            })
            ->latest()
            ->limit(50)
            ->get();
        $helperOrders = $canReceiveOrders ? $this->helperOpportunityOrders($driver) : collect();
        $orders = $orders->merge($helperOrders)->unique('id')->values();

        $branchAcceptedOrders = $branchId
            ? Order::query()
                ->with(['user', 'driver.user', 'operHandleRequests.driver.user', 'crews.driver.user'])
                ->where('branch_id', $branchId)
                ->whereNotNull('driver_id')
                ->latest('updated_at')
                ->limit(20)
                ->get()
            : collect();
        $branchRequestOrders = $branchId
            ? Order::query()
                ->with(['user', 'driver.user', 'operHandleRequests.driver.user', 'crews.driver.user'])
                ->where('branch_id', $branchId)
                ->where('source', 'driver_request')
                ->latest('updated_at')
                ->limit(30)
                ->get()
            : collect();
        $branchOperHandleOrders = $branchId
            ? OperHandleRequest::query()
                ->with(['order.user', 'order.driver.user', 'driver.user'])
                ->whereHas('order', fn ($query) => $query->where('branch_id', $branchId))
                ->latest('updated_at')
                ->limit(12)
                ->get()
            : collect();
        $branchSuspendHistory = $branchId
            ? DriverSuspension::query()
                ->with('driver.user')
                ->whereHas('driver.user', fn ($query) => $query->where('branch_id', $branchId))
                ->latest('updated_at')
                ->limit(20)
                ->get()
            : collect();

        return response()->json([
            'driver' => $this->driverPayload($request, $billingDeposit),
            'orders' => $orders->map(fn (Order $order): array => [
                ...$this->orderPayload($order, $driver),
                'eligibility' => $multiOrder->canAcceptOrder($driver, $order),
            ]),
            'branch_accepted_orders' => $branchAcceptedOrders->map(fn (Order $order): array => $this->orderPayload($order)),
            'branch_request_orders' => $branchRequestOrders->map(fn (Order $order): array => $this->orderPayload($order)),
            'branch_oper_handle_orders' => $branchOperHandleOrders->map(fn (OperHandleRequest $request): array => [
                ...$this->orderPayload($request->order),
                'oper_handle_status' => $request->status,
                'oper_handle_driver' => $request->driver?->user?->name,
                'oper_handle_reason' => $request->reason,
                'oper_handle_updated_at' => $request->updated_at?->toIso8601String(),
            ]),
            'branch_suspend_history' => $branchSuspendHistory->map(fn (DriverSuspension $suspension): array => [
                'id' => $suspension->id,
                'driver' => $suspension->driver?->user?->name ?? 'Driver',
                'type' => $suspension->type,
                'reason' => $suspension->reason,
                'status' => $suspension->status,
                'start_at' => $suspension->start_at?->toIso8601String(),
                'end_at' => $suspension->end_at?->toIso8601String(),
                'updated_at' => $suspension->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function updateAvailability(Request $request, DriverFinanceService $finance): JsonResponse
    {
        $driver = $this->ensureDriver($request)->load('user.branch');
        app(DriverSuspendService::class)->releaseIfExpired($driver);
        $driver->refresh();
        $payload = $request->validate([
            'online' => ['required', 'boolean'],
        ]);

        $deposit = $finance->monthlyDeposit($driver, now()->subMonth());

        if ($this->depositBlocksOrders($deposit)) {
            $driver->update(['is_available' => false]);

            return response()->json([
                'message' => 'Tagihan bulan sebelumnya unpaid dan sudah lewat jatuh tempo. Driver otomatis OFF dan tidak bisa menerima/request order.',
                'driver' => $this->driverPayload($request, $deposit),
                'finance' => $this->financePayload($finance->monthlyDeposit($driver)),
            ], $request->boolean('online') ? 422 : 200);
        }

        if ($driver->status !== 'active' && $request->boolean('online')) {
            $driver->update(['is_available' => false]);

            return response()->json([
                'message' => 'Driver tidak aktif/suspend sehingga tidak bisa ON.',
                'driver' => $this->driverPayload($request, $deposit),
                'finance' => $this->financePayload($finance->monthlyDeposit($driver)),
            ], 422);
        }

        $driver->update(['is_available' => $request->boolean('online')]);

        return response()->json([
            'message' => $request->boolean('online') ? 'Driver ON dan bisa menerima/request order.' : 'Driver OFF. Order baru dan request order nonaktif.',
            'driver' => $this->driverPayload($request, $deposit),
            'finance' => $this->financePayload($finance->monthlyDeposit($driver)),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $this->ensureDriver($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username,'.$request->user()->id],
            'password' => ['nullable', 'string', 'min:8'],
            'profile_photo' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('profile_photo')) {
            if ($request->user()->profile_photo_path) {
                Storage::disk('public')->delete($request->user()->profile_photo_path);
            }

            $payload['profile_photo_path'] = $request->file('profile_photo')?->store('profiles/drivers', 'public');
        }

        if (! filled($payload['password'] ?? null)) {
            unset($payload['password']);
        } else {
            $payload['password'] = Hash::make($payload['password']);
        }

        unset($payload['profile_photo']);
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

    public function acceptCrew(Request $request, Order $order, string $role): JsonResponse
    {
        $driver = $this->ensureDriver($request)->load('user.branch');
        $role = strtolower($role ?: 'helper');

        try {
            $crew = DB::transaction(function () use ($order, $driver, $role): OrderCrew {
                $order = Order::query()->with(['user', 'driver.user'])->lockForUpdate()->findOrFail($order->id);
                $crew = OrderCrew::query()
                    ->where('order_id', $order->id)
                    ->where('role', $role)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($crew->status !== 'pending') {
                    throw new RuntimeException('Slot crew sudah tidak tersedia.');
                }

                if ((int) $order->driver_id === (int) $driver->id) {
                    throw new RuntimeException('Rider utama tidak bisa menerima slot helper order yang sama.');
                }

                if ($driver->status !== 'active' || ! $driver->is_available) {
                    throw new RuntimeException('Status driver OFF atau tidak aktif.');
                }

                if (! $driver->can_accept_all_areas && (int) $driver->user?->branch_id !== (int) $order->branch_id) {
                    throw new RuntimeException('Order helper berada di luar cabang driver.');
                }

                $crew->update([
                    'driver_id' => $driver->id,
                    'status' => 'accepted',
                    'accepted_at' => now(),
                ]);
                $driver->update(['is_available' => false]);

                $breakdown = $order->pricing_breakdown ?? [];
                $breakdown['crew_status'] = 'ready';
                $breakdown['helper_driver_id'] = $driver->id;
                $breakdown['helper_name'] = $driver->user?->name;
                $order->update(['pricing_breakdown' => $breakdown]);

                return $crew->fresh(['driver.user', 'order.user', 'order.driver.user']);
            });
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $crew->label.' diterima',
            'data' => $this->orderPayload($crew->order->fresh(['user', 'driver.user', 'items', 'crews.driver.user']), $driver),
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
        abort_if(! $driver->is_available, 422, 'Status driver OFF. Aktifkan ON terlebih dahulu untuk membuat request order.');
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

        return response()->json(['data' => $this->financePayload($finance->monthlyDeposit($driver))]);
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

    private function driverPayload(Request $request, ?DriverDeposit $deposit = null): array
    {
        $user = $request->user()->load('driver.suspensions');
        $driver = $user->driver;
        if ($driver && app(DriverSuspendService::class)->releaseIfExpired($driver)) {
            $user->load('driver.suspensions');
            $driver = $user->driver;
        }
        $canReceiveOrders = $driver ? $this->canReceiveOrders($driver, $deposit) : false;
        $activeSuspension = $driver?->suspensions
            ?->filter(fn ($suspension) => in_array($suspension->status, ['active', 'suspended', 'suspended_unpaid'], true))
            ->sortByDesc('start_at')
            ->first();
        $suspensionReason = $user->suspension_reason ?: $activeSuspension?->reason;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'phone' => $user->phone,
            'email' => $user->email,
            'profile_photo_url' => $user->profile_photo_path ? $request->getSchemeAndHttpHost().'/api/media/'.ltrim($user->profile_photo_path, '/') : null,
            'role' => 'Driver',
            'vehicle_type' => $driver?->vehicle_type ?? 'motor',
            'vehicle_types' => $driver?->vehicleTypes() ?? ['motor'],
            'vehicle_seat_rows' => $driver?->vehicle_seat_rows,
            'is_ladies_driver' => (bool) ($driver?->is_ladies_driver ?? false),
            'can_accept_all_areas' => (bool) ($driver?->can_accept_all_areas ?? false),
            'is_available' => (bool) ($driver?->is_available ?? false),
            'can_receive_orders' => $canReceiveOrders,
            'deposit_status' => $deposit?->status,
            'availability_block_reason' => $this->availabilityBlockReason($driver, $deposit),
            'status' => $driver?->status ?? ($user->is_suspended ? 'suspended' : 'active'),
            'suspended_until' => $driver?->suspended_until?->toIso8601String() ?? $user->suspended_until?->toIso8601String(),
            'suspension_reason' => $suspensionReason,
            'suspension_type' => $activeSuspension?->type,
            'oper_handle_count' => $driver?->oper_handle_count ?? 0,
        ];
    }

    private function branchPerformancePayload(Driver $driver): array
    {
        $today = now();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();
        $completedThisMonth = $driver->orders()
            ->where('status', OrderStatus::Completed->value)
            ->whereBetween('created_at', [$monthStart, $monthEnd]);
        $completedToday = $driver->orders()
            ->where('status', OrderStatus::Completed->value)
            ->whereDate('created_at', $today->toDateString());
        $cancelledThisMonth = $driver->orders()
            ->where('status', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$monthStart, $monthEnd]);
        $activeOrders = $driver->orders()
            ->whereIn('status', [
                OrderStatus::DriverAccepted->value,
                OrderStatus::DriverOnTheWay->value,
                OrderStatus::ArrivedPickup->value,
                OrderStatus::OnGoing->value,
            ]);
        $rating = $driver->ratings()->avg('rating');
        $ratingsCount = $driver->ratings()->count();

        return [
            'driver_id' => $driver->id,
            'user_id' => $driver->user_id,
            'name' => $driver->user?->name ?? 'Driver',
            'branch' => $driver->user?->branch?->name,
            'branch_area' => $driver->user?->branch?->area,
            'status' => $driver->status,
            'is_available' => (bool) $driver->is_available,
            'rating' => round((float) $rating, 2),
            'ratings_count' => $ratingsCount,
            'completed_orders_count' => (clone $completedThisMonth)->count(),
            'today_completed_orders_count' => (clone $completedToday)->count(),
            'cancelled_orders_count' => (clone $cancelledThisMonth)->count(),
            'active_orders_count' => (clone $activeOrders)->count(),
            'month_revenue' => (int) (clone $completedThisMonth)->sum('total_price'),
            'today_revenue' => (int) (clone $completedToday)->sum('total_price'),
        ];
    }

    private function syncAvailabilityForFinance(Driver $driver, DriverDeposit $deposit): Driver
    {
        if ($this->depositBlocksOrders($deposit) && $driver->is_available) {
            $driver->forceFill(['is_available' => false])->save();
            $driver->refresh();
        }

        return $driver;
    }

    private function depositBlocksOrders(?DriverDeposit $deposit): bool
    {
        if (! $deposit || ($deposit->status ?? 'paid') === 'paid') {
            return false;
        }

        if (! $deposit->due_date) {
            return true;
        }

        return Carbon::parse($deposit->due_date)->endOfDay()->isPast();
    }

    private function financePayload(DriverDeposit $deposit): array
    {
        $period = Carbon::create((int) $deposit->year, (int) $deposit->month, 1);
        $previousPeriod = $period->copy()->subMonth();
        $driver = Driver::query()->find($deposit->driver_id);
        $previous = $driver
            ? app(DriverFinanceService::class)->monthlyDeposit($driver, $previousPeriod)
            : DriverDeposit::query()
                ->where('driver_id', $deposit->driver_id)
                ->where('year', $previousPeriod->year)
                ->where('month', $previousPeriod->month)
                ->first();
        $previousTotal = (int) ($previous?->total ?? 0);
        $previousPaid = (int) ($previous?->paid_amount ?? 0);
        $previousRemaining = max(0, $previousTotal - $previousPaid);

        return [
            ...$deposit->toArray(),
            'period_label' => $period->translatedFormat('F Y'),
            'current_period_deposit' => (int) data_get($deposit->breakdown, 'setoran_hingga_hari_ini', ((int) $deposit->handle_day_15 + (int) $deposit->handle_day_30)),
            'remaining' => max(0, (int) $deposit->total - (int) $deposit->paid_amount),
            'previous_deposit' => [
                'month' => $previousPeriod->month,
                'year' => $previousPeriod->year,
                'period_label' => $previousPeriod->translatedFormat('F Y'),
                'total' => $previousTotal,
                'paid_amount' => $previousPaid,
                'paid_at' => $previous?->paid_at?->toISOString(),
                'remaining' => $previousRemaining,
                'status' => $previousRemaining > 0 ? 'unpaid' : 'paid',
                'due_date' => $previous?->due_date?->toDateString(),
                'current_period_deposit' => (int) data_get($previous?->breakdown, 'setoran_hingga_hari_ini', ((int) ($previous?->handle_day_15 ?? 0) + (int) ($previous?->handle_day_30 ?? 0))),
                'breakdown' => $previous?->breakdown ?? [],
            ],
        ];
    }

    private function canReceiveOrders(?Driver $driver, ?DriverDeposit $deposit = null): bool
    {
        if (! $driver) {
            return false;
        }

        return $driver->status === 'active'
            && (bool) $driver->is_available
            && ! $this->depositBlocksOrders($deposit);
    }

    private function availabilityBlockReason(?Driver $driver, ?DriverDeposit $deposit = null): ?string
    {
        if (! $driver) {
            return 'Akun driver tidak ditemukan.';
        }

        if ($this->depositBlocksOrders($deposit)) {
            return 'Tagihan bulan sebelumnya unpaid dan sudah lewat jatuh tempo. Driver otomatis OFF dan tidak bisa menerima/request order.';
        }

        if ($driver->status !== 'active') {
            return 'Driver tidak aktif/suspend.';
        }

        if (! $driver->is_available) {
            return 'Driver sedang OFF.';
        }

        return null;
    }

    private function helperOpportunityOrders(Driver $driver)
    {
        return Order::query()
            ->with(['user', 'driver.user', 'adjustments', 'operHandleRequests.driver.user', 'crews.driver.user'])
            ->whereNotNull('driver_id')
            ->where('driver_id', '!=', $driver->id)
            ->whereIn('status', [
                OrderStatus::DriverAccepted->value,
                OrderStatus::DriverOnTheWay->value,
                OrderStatus::ArrivedPickup->value,
                OrderStatus::OnGoing->value,
            ])
            ->whereHas('crews', fn ($query) => $query
                ->where('status', 'pending')
                ->where('role', '!=', 'rider'))
            ->when(! (bool) $driver->can_accept_all_areas, fn ($query) => $query->where('branch_id', $driver->user?->branch_id))
            ->latest('updated_at')
            ->limit(20)
            ->get();
    }

    private function orderPayload(Order $order, ?Driver $forDriver = null): array
    {
        if ($order->relationLoaded('operHandleRequests')) {
            $operHandles = $order->operHandleRequests;
            if ($forDriver) {
                $operHandles = $operHandles->where('driver_id', $forDriver->id);
            }
            $operHandle = $operHandles->sortByDesc('updated_at')->first();
        } else {
            $operHandleQuery = $order->operHandleRequests()->latest('updated_at');
            if ($forDriver) {
                $operHandleQuery->where('driver_id', $forDriver->id);
            }
            $operHandle = $operHandleQuery->first();
        }

        return [
            'id' => $order->id,
            'code' => $order->order_code,
            'status' => $order->status->value,
            'customer' => $order->user?->name ?? 'Customer',
            'customer_phone' => $order->user?->phone,
            'driver' => $order->driver?->user?->name,
            'source' => $order->source,
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
            'payment_method' => $order->payment_method,
            'payment_label' => $order->payment_label,
            'payment_meta' => $order->payment_meta,
            'preferred_vehicle_type' => data_get($order->pricing_breakdown, 'preferred_vehicle_type'),
            'required_vehicle_seat_rows' => data_get($order->pricing_breakdown, 'required_vehicle_seat_rows'),
            'driver_preference' => data_get($order->pricing_breakdown, 'driver_preference', 'general'),
            'oper_handle_status' => $operHandle?->status,
            'oper_handle_driver' => $operHandle?->driver?->user?->name,
            'oper_handle_reason' => $operHandle?->reason,
            'oper_handle_updated_at' => $operHandle?->updated_at?->toIso8601String(),
            'detail' => $order->raw_text,
            'accepted_at' => in_array($order->status->value, ['DRIVER_ACCEPTED', 'DRIVER_ON_THE_WAY', 'ARRIVED_PICKUP', 'ON_GOING'], true)
                ? $order->updated_at?->toIso8601String()
                : null,
            'expired_at' => $order->expired_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'adjustments' => $order->adjustments,
            'crew_decision' => data_get($order->pricing_breakdown, 'crew_decision'),
            'crew_status' => data_get($order->pricing_breakdown, 'crew_status', data_get($order->pricing_breakdown, 'crew_decision.requires_helper') ? 'waiting_helper' : null),
            'crews' => $order->relationLoaded('crews')
                ? $order->crews->map(fn (OrderCrew $crew): array => [
                    'id' => $crew->id,
                    'role' => $crew->role,
                    'label' => $crew->label,
                    'status' => $crew->status,
                    'driver' => $crew->driver?->user?->name,
                    'service_charge' => $crew->service_charge,
                    'accepted_at' => $crew->accepted_at?->toIso8601String(),
                ])->values()
                : [],
            'crew_role' => $forDriver && $order->relationLoaded('crews')
                ? $this->crewRoleForDriver($order, $forDriver)
                : null,
        ];
    }

    private function crewRoleForDriver(Order $order, Driver $driver): ?string
    {
        $assigned = $order->crews->first(fn (OrderCrew $crew): bool => (int) $crew->driver_id === (int) $driver->id);
        if ($assigned) {
            return $assigned->role;
        }

        if ((int) $order->driver_id === (int) $driver->id) {
            return 'rider';
        }

        return $order->crews->first(fn (OrderCrew $crew): bool => $crew->driver_id === null && $crew->status === 'pending' && $crew->role !== 'rider')?->role;
    }
}
