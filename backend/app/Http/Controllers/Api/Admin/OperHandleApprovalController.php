<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Events\OperHandleDecisionUpdated;
use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\OperHandleRequest;
use App\Services\DriverSuspendService;
use App\Services\NotificationService;
use App\Services\OrderFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OperHandleApprovalController extends Controller
{
    public function approve(Request $request, OperHandleRequest $operHandle, DriverSuspendService $suspensions, NotificationService $notifications): JsonResponse
    {
        $operHandle = DB::transaction(function () use ($request, $operHandle, $suspensions, $notifications): OperHandleRequest {
            $operHandle = OperHandleRequest::query()
                ->with(['order.user', 'driver.user'])
                ->lockForUpdate()
                ->findOrFail($operHandle->id);

            abort_unless($operHandle->status === 'pending', 422, 'Oper handle sudah diputuskan.');

            $order = $operHandle->order;
            $oldStatus = $order?->status;

            $operHandle->forceFill([
                'status' => 'approved',
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
                'decision_note' => 'Approved oleh '.$request->user()->name,
                'operator_approved_by' => $request->user()->id,
                'operator_approved_at' => now(),
            ])->save();

            $order?->update([
                'driver_id' => null,
                'status' => OrderStatus::SearchingDriver,
                'direction_bearing' => null,
                'is_multi_order' => false,
                'expired_at' => now()->addMinutes(10),
                'notes' => trim(((string) $order->notes)."\nOper handle approved: {$operHandle->reason}"),
            ]);

            $suspensions->suspendForOperHandle($operHandle->driver->load('user'), $request->user());

            $freshOperHandle = $operHandle->fresh(['order.user', 'driver.user']);
            DB::afterCommit(function () use ($freshOperHandle, $oldStatus, $notifications): void {
                try {
                    OperHandleDecisionUpdated::dispatch($freshOperHandle);
                    $freshOrder = $freshOperHandle->order?->fresh(['user', 'driver.user']);
                    if ($freshOrder && $oldStatus) {
                        OrderStatusUpdated::dispatch($freshOrder, $oldStatus, OrderStatus::SearchingDriver);
                        $feedback = app(OrderFeedbackService::class)->statusUpdated($freshOrder, $oldStatus, OrderStatus::SearchingDriver);
                        $notifications->sendToUser(
                            $freshOrder->user,
                            $feedback['title'] ?? 'Order dialihkan ke driver lain',
                            $feedback['message'] ?? 'Order sedang dicari ulang karena driver sebelumnya melakukan oper handle.',
                            [
                                'type' => 'order_oper_handle_approved',
                                'order_id' => $freshOrder->id,
                                'order_code' => $freshOrder->order_code,
                            ],
                        );
                    }
                    $notifications->sendToUser(
                        $freshOperHandle->driver?->user,
                        'Oper handle disetujui',
                        'Permintaan oper handle Anda disetujui. Order dialihkan dan suspend berlaku sesuai ketentuan.',
                        ['type' => 'oper_handle_approved', 'order_id' => $freshOperHandle->order_id],
                    );
                } catch (\Throwable $exception) {
                    Log::warning('broadcast.oper_handle_approved_failed', [
                        'oper_handle_id' => $freshOperHandle->id,
                        'message' => $exception->getMessage(),
                    ]);
                }
            });

            return $freshOperHandle;
        });

        return response()->json([
            'message' => 'Oper handle disetujui. Driver tersuspend dan order dibuka kembali.',
            'data' => $operHandle,
        ]);
    }

    public function reject(Request $request, OperHandleRequest $operHandle, NotificationService $notifications): JsonResponse
    {
        $payload = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $operHandle = DB::transaction(function () use ($request, $operHandle, $notifications, $payload): OperHandleRequest {
            $operHandle = OperHandleRequest::query()
                ->with(['order', 'driver.user'])
                ->lockForUpdate()
                ->findOrFail($operHandle->id);

            abort_unless($operHandle->status === 'pending', 422, 'Oper handle sudah diputuskan.');

            $operHandle->forceFill([
                'status' => 'rejected',
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
                'decision_note' => trim((string) ($payload['reason'] ?? '')) ?: 'Rejected oleh '.$request->user()->name,
            ])->save();

            $freshOperHandle = $operHandle->fresh(['order', 'driver.user']);
            DB::afterCommit(function () use ($freshOperHandle, $notifications): void {
                try {
                    OperHandleDecisionUpdated::dispatch($freshOperHandle);
                    $notifications->sendToUser(
                        $freshOperHandle->driver?->user,
                        'Oper handle ditolak',
                        'Permintaan oper handle ditolak. Silakan lanjutkan order yang sedang berjalan.',
                        ['type' => 'oper_handle_rejected', 'order_id' => $freshOperHandle->order_id],
                    );
                } catch (\Throwable $exception) {
                    Log::warning('broadcast.oper_handle_rejected_failed', [
                        'oper_handle_id' => $freshOperHandle->id,
                        'message' => $exception->getMessage(),
                    ]);
                }
            });

            return $freshOperHandle;
        });

        return response()->json([
            'message' => 'Oper handle ditolak. Order tetap dilanjutkan oleh driver.',
            'data' => $operHandle,
        ]);
    }
}
