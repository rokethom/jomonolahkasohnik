<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\OperHandleRequest;
use App\Services\DriverSuspendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OperHandleApprovalController extends Controller
{
    public function approve(Request $request, OperHandleRequest $operHandle, DriverSuspendService $suspensions): JsonResponse
    {
        $role = $request->user()->role instanceof UserRole
            ? $request->user()->role
            : UserRole::tryFrom((string) $request->user()->role);

        abort_unless(in_array($role, [UserRole::Operator, UserRole::SPV, UserRole::Admin, UserRole::GM], true), 403);

        $operHandle = DB::transaction(function () use ($request, $operHandle, $suspensions, $role): OperHandleRequest {
            $operHandle = OperHandleRequest::query()
                ->with(['order', 'driver.user'])
                ->lockForUpdate()
                ->findOrFail($operHandle->id);

            abort_if($operHandle->status === 'approved', 422, 'Oper handle sudah approved.');

            if (in_array($role, [UserRole::Operator, UserRole::Admin, UserRole::GM], true)) {
                $operHandle->forceFill([
                    'operator_approved_by' => $request->user()->id,
                    'operator_approved_at' => now(),
                ]);
            }

            if (in_array($role, [UserRole::SPV, UserRole::Admin, UserRole::GM], true)) {
                $operHandle->forceFill([
                    'spv_approved_by' => $request->user()->id,
                    'spv_approved_at' => now(),
                ]);
            }

            if ($operHandle->operator_approved_by && $operHandle->spv_approved_by) {
                $order = $operHandle->order;
                $oldStatus = $order?->status;

                $operHandle->status = 'approved';
                $order?->update([
                    'driver_id' => null,
                    'status' => OrderStatus::SearchingDriver,
                    'direction_bearing' => null,
                    'is_multi_order' => false,
                ]);

                if ($oldStatus && $order) {
                    try {
                        OrderStatusUpdated::dispatch($order->fresh(['user', 'driver.user']), $oldStatus, OrderStatus::SearchingDriver);
                    } catch (\Throwable $exception) {
                        Log::warning('broadcast.oper_handle_order_status_failed', [
                            'order_id' => $order->id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }

                $suspensions->suspendForOperHandle($operHandle->driver->load('user'), $request->user());
            }

            $operHandle->save();

            return $operHandle;
        });

        return response()->json([
            'message' => $operHandle->status === 'approved' ? 'Oper handle approved' : 'Approval tersimpan',
            'data' => $operHandle->fresh(['order', 'driver.user']),
        ]);
    }
}
