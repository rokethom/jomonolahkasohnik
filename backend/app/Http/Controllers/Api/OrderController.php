<?php

namespace App\Http\Controllers\Api;

use App\Actions\Order\CancelOrder;
use App\Actions\Order\CompleteOrder;
use App\Actions\Order\CreateOrder;
use App\Actions\Order\FindDriver;
use App\Actions\Order\UpdateOrderStatus;
use App\Enums\OrderStatus;
use App\Exceptions\OrderLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\OrderOperationService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderController extends Controller
{
    public function index(Request $request, OrderService $orders): JsonResponse
    {
        $orders->cancelExpiredCreatedOrders();

        $user = $request->user();
        $query = Order::query()->with(['user', 'driver.user', 'items', 'payments', 'rating'])->latest();

        if (($user->role->value ?? $user->role) === 'customer') {
            $query->where('user_id', $user->id);
        }

        if (($user->role->value ?? $user->role) === 'driver') {
            $query->where(function ($query) use ($user) {
                $query->whereHas('driver', fn ($query) => $query->where('user_id', $user->id))
                    ->orWhereIn('status', [OrderStatus::Created->value, OrderStatus::SearchingDriver->value]);
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

    public function quote(StoreOrderRequest $request, PricingService $pricingService, OrderOperationService $operations): JsonResponse
    {
        if ($message = $operations->closedMessage()) {
            return response()->json([
                'success' => false,
                'order_closed' => true,
                'message' => $message,
            ], 423);
        }

        return response()->json([
            'message' => 'Pricing calculated',
            'data' => $pricingService->calculate([
                ...$request->validated(),
                'branch_id' => $request->validated('branch_id') ?? $request->user()?->branch_id,
            ]),
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
}
