<?php

namespace App\Http\Controllers\Api;

use App\Actions\Order\CancelOrder;
use App\Actions\Order\CompleteOrder;
use App\Actions\Order\CreateOrder;
use App\Actions\Order\FindDriver;
use App\Actions\Order\UpdateOrderStatus;
use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Exceptions\OrderLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\OrderOperationService;
use App\Services\PricingService;
use App\Services\OperationalAreaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderController extends Controller
{
    public function index(Request $request, OrderService $orders, OperationalAreaService $areas): JsonResponse
    {
        $orders->cancelExpiredCreatedOrders();

        $user = $request->user();
        $query = Order::query()->with(['user', 'driver.user', 'items', 'payments', 'rating', 'adjustments.driver.user', 'crews.driver.user'])->latest();

        if (($user->role->value ?? $user->role) === 'customer') {
            $query->where('user_id', $user->id);
        }

        if (($user->role->value ?? $user->role) === 'driver') {
            $user->loadMissing('driver');

            $query->where(function ($query) use ($user) {
                $query->whereHas('driver', fn ($query) => $query->where('user_id', $user->id))
                    ->orWhere(function ($query) use ($user): void {
                        $query->whereIn('status', [OrderStatus::Created->value, OrderStatus::SearchingDriver->value])
                            ->where(function ($query) use ($user, $areas): void {
                                $areaId = $areas->userAreaId($user);
                                if ($areaId !== null) {
                                    $query->where('area_id', $areaId)
                                        ->orWhere(function ($query) use ($user): void {
                                            $query->whereNull('area_id')->where('branch_id', $user->branch_id);
                                        });

                                    return;
                                }

                                $user->branch_id
                                    ? $query->where('branch_id', $user->branch_id)
                                    : $query->whereRaw('1 = 0');
                            });
                    });
            })->where('status', '!=', OrderStatus::Cancelled->value);
        }

        if ($request->filled('month') && preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) === 1) {
            [$year, $month] = array_map('intval', explode('-', (string) $request->query('month')));
            $query->whereYear('created_at', $year)->whereMonth('created_at', $month);
        }

        return response()->json([
            'data' => $query->paginate($request->integer('per_page', 15)),
        ]);
    }

    public function store(StoreOrderRequest $request, CreateOrder $createOrder): JsonResponse
    {
        try {
            $order = $createOrder->handle($request->user(), $request->validated());
        } catch (ValidationException $exception) {
            $message = $exception->errors()['order_closed'][0] ?? null;
            if ($message) {
                return response()->json([
                    'success' => false,
                    'order_closed' => true,
                    'message' => $message,
                    'jojobot_message' => $message,
                ], 423);
            }

            throw $exception;
        } catch (OrderLimitExceededException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'jojobot_message' => $exception->getMessage(),
                'active_orders' => $exception->activeOrders,
                'max_orders' => $exception->maxOrders,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order created',
            'data' => $order,
        ], 201);
    }

    public function quote(StoreOrderRequest $request, PricingService $pricingService, OrderOperationService $operations, OrderService $orders): JsonResponse
    {
        if ($message = $operations->closedMessage()) {
            return response()->json([
                'success' => false,
                'order_closed' => true,
                'message' => $message,
            ], 423);
        }

        $payload = $request->validated();
        $payload['branch_id'] = $orders->resolveTargetBranchId($payload, $request->user()?->branch_id);

        return response()->json([
            'message' => 'Pricing calculated',
            'data' => $pricingService->calculate($payload),
        ]);
    }

    public function findDriver(Order $order, FindDriver $findDriver): JsonResponse
    {
        $this->authorizeCustomerOrAdmin($order, request());

        try {
            $result = $findDriver->handle($order);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Searching driver',
            'data' => $result['order'],
            'driver' => $result['driver'],
        ]);
    }

    public function updateStatus(
        UpdateOrderStatusRequest $request,
        Order $order,
        UpdateOrderStatus $updateOrderStatus,
    ): JsonResponse {
        $this->authorizeAssignedDriverOrAdmin($order, $request);

        try {
            $order = $updateOrderStatus->handle($order, OrderStatus::from($request->validated('status')));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Order status updated',
            'data' => $order,
        ]);
    }

    public function complete(Request $request, Order $order, CompleteOrder $completeOrder): JsonResponse
    {
        $this->authorizeAssignedDriverOrAdmin($order, $request);

        try {
            $order = $completeOrder->handle($order);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Order completed',
            'data' => $order,
        ]);
    }

    public function cancel(Request $request, Order $order, CancelOrder $cancelOrder): JsonResponse
    {
        $this->authorizeCustomerDriverOrAdmin($order, $request);

        try {
            $order = $cancelOrder->handle($order);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Order cancelled',
            'data' => $order,
        ]);
    }

    public function extendWait(Request $request, Order $order): JsonResponse
    {
        $this->authorizeCustomerOrAdmin($order, $request);

        try {
            $order = DB::transaction(function () use ($order): Order {
                $lockedOrder = Order::query()
                    ->with(['user', 'driver.user', 'items', 'payments', 'rating', 'adjustments.driver.user', 'crews.driver.user'])
                    ->lockForUpdate()
                    ->findOrFail($order->id);

                if ($lockedOrder->driver_id !== null) {
                    throw new RuntimeException('Order sudah diterima driver.');
                }

                if ($lockedOrder->status === OrderStatus::Completed) {
                    throw new RuntimeException('Order sudah selesai.');
                }

                if (! $this->isDriverTimeoutOrder($lockedOrder)) {
                    throw new RuntimeException('Order ini tidak bisa diperpanjang. Silakan buat order baru atau hubungi CS.');
                }

                $oldStatus = $lockedOrder->status;
                $breakdown = $lockedOrder->pricing_breakdown ?? [];
                $currentExtensions = (int) data_get($breakdown, 'wait_extension.count', 0);
                if ($currentExtensions >= 3) {
                    throw new RuntimeException('Order ini sudah 3x dicari ulang dan tetap belum mendapat driver. Silakan hubungi Operator.');
                }

                $extensions = $currentExtensions + 1;
                data_set($breakdown, 'wait_extension.count', $extensions);
                data_set($breakdown, 'wait_extension.last_extended_at', now()->toIso8601String());
                data_set($breakdown, 'wait_extension.minutes', 10);

                $lockedOrder->update([
                    'status' => OrderStatus::SearchingDriver,
                    'cancelled_at' => null,
                    'expired_at' => now()->addMinutes(10),
                    'pricing_breakdown' => $breakdown,
                    'notes' => trim(((string) $lockedOrder->notes)."\nCustomer memilih order kembali #{$extensions}."),
                ]);

                $freshOrder = $lockedOrder->fresh(['user', 'driver.user', 'items', 'payments', 'rating', 'adjustments.driver.user', 'crews.driver.user']);

                DB::afterCommit(function () use ($freshOrder, $oldStatus): void {
                    try {
                        OrderStatusUpdated::dispatch($freshOrder, $oldStatus, OrderStatus::SearchingDriver);
                    } catch (\Throwable $exception) {
                        Log::warning('broadcast.order_wait_extended_failed', [
                            'order_id' => $freshOrder->id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                });

                return $freshOrder;
            });
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Order kembali dibuka untuk mencari driver.',
            'data' => $order,
        ]);
    }

    private function authorizeCustomerOrAdmin(Order $order, Request $request): void
    {
        $role = $request->user()->role->value ?? $request->user()->role;

        abort_unless($role === 'admin' || (int) $order->user_id === (int) $request->user()->id, 403);
    }

    private function authorizeAssignedDriverOrAdmin(Order $order, Request $request): void
    {
        $role = $request->user()->role->value ?? $request->user()->role;

        if ($role === 'admin') {
            return;
        }

        abort_unless($role === 'driver', 403);

        $order->loadMissing('driver');
        abort_unless((int) optional($order->driver)->user_id === (int) $request->user()->id, 403);
    }

    private function authorizeCustomerDriverOrAdmin(Order $order, Request $request): void
    {
        $role = $request->user()->role->value ?? $request->user()->role;

        if ($role === 'admin' || (int) $order->user_id === (int) $request->user()->id) {
            return;
        }

        $order->loadMissing('driver');
        abort_unless($role === 'driver' && (int) optional($order->driver)->user_id === (int) $request->user()->id, 403);
    }

    private function isDriverTimeoutOrder(Order $order): bool
    {
        $notes = strtolower((string) $order->notes);

        if (str_contains($notes, 'multi-crew timeout')) {
            return false;
        }

        if ($order->status === OrderStatus::Cancelled && (str_contains($notes, 'driver timeout') || str_contains($notes, 'batas waktu mencari driver'))) {
            return true;
        }

        return in_array($order->status, [OrderStatus::Created, OrderStatus::SearchingDriver], true)
            && ($order->expired_at?->lte(now()) ?? false);
    }
}
