<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Events\OrderPriceUpdated;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ChatConversation;
use App\Models\Driver;
use App\Models\GeofenceArea;
use App\Models\LocationLog;
use App\Models\Order;
use App\Models\PriceSetting;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminDashboardMetricsService;
use App\Services\DriverReportService;
use App\Services\DriverSuspendService;
use App\Services\MultiOrderService;
use App\Services\OrderFeedbackService;
use App\Services\OrderOperationService;
use App\Services\OrderService;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    public function bootstrap(Request $request, SettingService $settings, OrderService $orders): JsonResponse
    {
        $orders->cancelExpiredCreatedOrders();

        $user = $request->user()->load('branch');

        return response()->json([
            'me' => $this->userPayload($user),
            'permissions' => $this->permissionsFor($user),
            'system_settings' => $this->systemSettingsPayload($settings),
            'stats' => $this->stats($user),
            'users' => $this->usersQuery($user)->limit(100)->get()->map(fn (User $item) => $this->userPayload($item)),
            'drivers' => $this->driverRows($user),
            'orders' => $this->ordersQuery($user)->latest()->limit(100)->get()->map(fn (Order $order) => $this->orderPayload($order)),
            'branches' => Branch::query()->withCount('geofenceAreas')->orderBy('name')->get(),
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'price_settings' => PriceSetting::query()->with('branch')->latest()->get(),
            'geofences' => GeofenceArea::query()->with('branch')->latest()->get(),
            'location_logs' => $this->locationLogsQuery($user)->limit(100)->get()->map(fn (LocationLog $log) => $this->locationLogPayload($log)),
            'chats' => $this->chatsQuery($user)->limit(100)->get()->map(fn (ChatConversation $chat) => $this->chatPayload($chat)),
            'audit_logs' => $this->auditLogsQuery($user)->limit(12)->get()->map(fn (AuditLog $log) => $this->auditLogPayload($log)),
        ]);
    }

    public function monitoring(AdminDashboardMetricsService $metrics): JsonResponse
    {
        return response()->json([
            'success' => true,
            'stats' => $metrics->productionStats(),
            'server_metrics' => $metrics->serverMetrics(),
            'endpoints' => $metrics->endpointHealth(),
            'timeline' => $metrics->timeline(),
            'charts' => $metrics->chartSeries(),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->usersQuery($request->user())
                ->latest()
                ->paginate($request->integer('per_page', 25))
                ->through(fn (User $user) => $this->userPayload($user)),
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $actor = $request->user();
        $payload = $request->validate([
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', new Enum(UserRole::class)],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'is_active' => ['sometimes', 'boolean'],
            'is_suspended' => ['sometimes', 'boolean'],
            'suspension_reason' => ['nullable', 'string'],
            'driver_bansos_amount' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'driver_bpjs_jht_enabled' => ['sometimes', 'boolean'],
        ]);

        $role = UserRole::from($payload['role']);
        abort_unless($this->canAssignRole($actor, $role), 403);

        $driverPayload = [
            'bpjs_jht_enabled' => $payload['driver_bpjs_jht_enabled'] ?? true,
        ];
        if (array_key_exists('driver_bansos_amount', $payload)) {
            $driverPayload['bansos_amount'] = $payload['driver_bansos_amount'];
        }
        unset($payload['driver_bansos_amount'], $payload['driver_bpjs_jht_enabled']);

        $password = $this->generatePassword();
        $user = User::create([
            ...$payload,
            'password' => $password,
        ]);

        if ($role === UserRole::Driver) {
            $user->driver()->firstOrCreate([], [
                'is_available' => true,
                'status' => 'active',
                ...$driverPayload,
            ]);
        }

        $this->recordAudit($actor, 'created_user', $user, ['role' => $user->role->value]);

        return response()->json([
            'message' => 'User created',
            'temporary_password' => $password,
            'data' => $this->userPayload($user->fresh('branch', 'driver')),
        ], 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->canManageUser($actor, $user), 403);

        $payload = $request->validate([
            'username' => ['sometimes', 'string', 'max:255', 'unique:users,username,'.$user->id],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['sometimes', new Enum(UserRole::class)],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'is_active' => ['sometimes', 'boolean'],
            'is_suspended' => ['sometimes', 'boolean'],
            'suspension_reason' => ['nullable', 'string'],
            'suspended_until' => ['nullable', 'date'],
            'driver_bansos_amount' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'driver_bpjs_jht_enabled' => ['sometimes', 'boolean'],
        ]);

        if (isset($payload['role'])) {
            abort_unless($this->canAssignRole($actor, UserRole::from($payload['role'])), 403);
        }

        $before = $user->only(array_keys($payload));
        $driverPayload = [];
        if (array_key_exists('driver_bansos_amount', $payload)) {
            $driverPayload['bansos_amount'] = $payload['driver_bansos_amount'];
        }
        if (array_key_exists('driver_bpjs_jht_enabled', $payload)) {
            $driverPayload['bpjs_jht_enabled'] = $payload['driver_bpjs_jht_enabled'];
        }
        unset($payload['driver_bansos_amount'], $payload['driver_bpjs_jht_enabled']);

        $user->update($payload);
        $nextRole = $payload['role'] ?? ($user->role instanceof UserRole ? $user->role->value : (string) $user->role);
        if ($nextRole === UserRole::Driver->value) {
            $driver = $user->driver()->firstOrCreate([], [
                'is_available' => true,
                'status' => 'active',
                ...$driverPayload,
            ]);
            if ($driverPayload !== []) {
                $driver->update($driverPayload);
            }
        }
        $this->recordAudit($actor, 'updated_user', $user, ['before' => $before, 'after' => $payload]);

        return response()->json([
            'message' => 'User updated',
            'data' => $this->userPayload($user->fresh('branch', 'driver')),
        ]);
    }

    public function destroyUser(Request $request, User $user): JsonResponse
    {
        abort_unless($this->canManageUser($request->user(), $user), 403);
        abort_if($request->user()->is($user), 422, 'Cannot delete yourself.');

        $this->recordAudit($request->user(), 'deleted_user', $user, ['email' => $user->email, 'role' => $user->role->value]);
        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        abort_unless($this->canManageUser($request->user(), $user), 403);

        $password = $this->generatePassword();
        $user->update(['password' => Hash::make($password)]);
        $user->tokens()->delete();
        $user->deviceTokens()->update(['is_active' => false]);
        $this->recordAudit($request->user(), 'reset_user_password', $user);

        return response()->json([
            'message' => 'Password reset',
            'temporary_password' => $password,
        ]);
    }

    public function resetUserToken(Request $request, User $user): JsonResponse
    {
        abort_unless($this->canManageUser($request->user(), $user), 403);
        abort_if($request->user()->is($user), 422, 'Tidak bisa reset token akun sendiri dari menu ini.');

        $user->tokens()->delete();
        $user->deviceTokens()->update(['is_active' => false]);
        $this->recordAudit($request->user(), 'reset_user_token', $user);

        return response()->json(['message' => 'Token user berhasil direset. User perlu login ulang.']);
    }

    public function orders(Request $request, OrderService $orders): JsonResponse
    {
        $orders->cancelExpiredCreatedOrders();

        return response()->json([
            'data' => $this->ordersQuery($request->user())
                ->latest()
                ->paginate($request->integer('per_page', 25))
                ->through(fn (Order $order) => $this->orderPayload($order)),
        ]);
    }

    public function updateOrderPrice(Request $request, Order $order): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV, UserRole::Operator], true), 403);

        $payload = $request->validate([
            'price' => ['required', 'integer', 'min:0'],
            'service_charge' => ['sometimes', 'integer', 'min:0'],
        ]);

        $serviceCharge = $payload['service_charge'] ?? $order->service_charge;
        $order->update([
            'price' => $payload['price'],
            'service_charge' => $serviceCharge,
            'total_price' => $payload['price'] + $serviceCharge + $order->extra_charge,
        ]);

        $freshOrder = $order->fresh(['user.branch', 'driver.user.branch']);
        $this->recordAudit($request->user(), 'updated_order_price', $freshOrder, [
            'price' => $payload['price'],
            'service_charge' => $serviceCharge,
        ]);

        try {
            OrderPriceUpdated::dispatch($freshOrder);
        } catch (\Throwable $exception) {
            Log::warning('broadcast.order_price_failed', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Order price updated',
            'data' => $this->orderPayload($freshOrder),
        ]);
    }

    public function suspendDriver(Request $request, Driver $driver, DriverSuspendService $suspensions): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager, UserRole::SPV], true), 403);

        $payload = $request->validate([
            'duration' => ['required', 'integer', 'in:1,3,12,24,72,168'],
            'reason' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:suspended,suspended_unpaid'],
        ]);

        $suspension = $suspensions->suspend(
            $driver->load('user'),
            $payload['duration'],
            $payload['reason'],
            $request->user(),
            $payload['status'] ?? 'suspended',
        );

        return response()->json([
            'message' => 'Driver suspended',
            'data' => $suspension,
        ], 201);
    }

    public function releaseDriverSuspend(Request $request, Driver $driver, DriverSuspendService $suspensions): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager, UserRole::SPV], true), 403);

        $suspensions->release($driver->load('user'), $request->user());

        return response()->json(['message' => 'Driver suspension released']);
    }

    public function resetDriverToken(Request $request, Driver $driver): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager, UserRole::SPV], true), 403);
        abort_unless($driver->user !== null, 404);

        $driver->user->tokens()->delete();
        $this->recordAudit($request->user(), 'reset_driver_token', $driver->user, ['driver_id' => $driver->id]);

        return response()->json(['message' => 'Token driver berhasil direset. Driver perlu login ulang.']);
    }

    public function updateDriverGoogleAuth(Request $request, Driver $driver): JsonResponse
    {
        $this->authorizeDriverAuthCms($request);
        abort_unless($driver->user !== null, 404);

        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($driver->user_id)],
        ]);

        $oldEmail = Schema::hasColumn('drivers', 'email') && filled($driver->email) ? $driver->email : $driver->user->email;
        $driver->user->forceFill(['email' => strtolower($payload['email'])])->save();
        if (Schema::hasColumn('drivers', 'email')) {
            $driver->forceFill(['email' => strtolower($payload['email'])])->save();
        }

        $this->recordAudit($request->user(), 'updated_driver_google_email', $driver->user, [
            'driver_id' => $driver->id,
            'old_email' => $oldEmail,
            'new_email' => strtolower($payload['email']),
        ]);

        return response()->json(['message' => 'Email Google driver berhasil diperbarui.']);
    }

    public function resetDriverGoogleBind(Request $request, Driver $driver): JsonResponse
    {
        $this->authorizeDriverAuthCms($request);
        abort_unless($driver->user !== null, 404);

        $driver->forceFill([
            'google_id' => null,
            'auth_failed_attempts' => 0,
            'auth_locked_until' => null,
        ])->save();
        $driver->user->tokens()->delete();

        $this->recordAudit($request->user(), 'reset_driver_google_bind', $driver->user, ['driver_id' => $driver->id]);

        return response()->json(['message' => 'Google bind driver berhasil direset. Driver dapat login ulang dengan akun Google baru.']);
    }

    public function suspendDriverGoogleAuth(Request $request, Driver $driver): JsonResponse
    {
        $this->authorizeDriverAuthCms($request);
        abort_unless($driver->user !== null, 404);

        $driver->forceFill(['auth_suspended_at' => now()])->save();
        $driver->user->tokens()->delete();

        $this->recordAudit($request->user(), 'suspended_driver_google_auth', $driver->user, ['driver_id' => $driver->id]);

        return response()->json(['message' => 'Auth Google driver disuspend dan token aktif dicabut.']);
    }

    public function unlockDriverGoogleAuth(Request $request, Driver $driver): JsonResponse
    {
        $this->authorizeDriverAuthCms($request);
        abort_unless($driver->user !== null, 404);

        $driver->forceFill([
            'auth_suspended_at' => null,
            'auth_locked_until' => null,
            'auth_failed_attempts' => 0,
        ])->save();

        $this->recordAudit($request->user(), 'unlocked_driver_google_auth', $driver->user, ['driver_id' => $driver->id]);

        return response()->json(['message' => 'Auth Google driver berhasil di-unlock.']);
    }

    public function updateDriverConfig(Request $request, Driver $driver): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager, UserRole::SPV], true), 403);
        abort_unless($driver->user !== null, 404);

        $payload = $request->validate([
            'vehicle_type' => ['required', 'string', 'in:motor,mobil'],
            'allowed_service_types' => ['array'],
            'allowed_service_types.*' => ['string', 'max:50'],
        ]);

        $driver->update([
            'vehicle_type' => $payload['vehicle_type'],
            'allowed_service_types' => array_values(array_unique(array_map(
                fn ($service): string => $this->normalizeServiceType((string) $service),
                $payload['allowed_service_types'] ?? [],
            ))),
        ]);

        $this->recordAudit($request->user(), 'updated_driver_config', $driver->user, [
            'driver_id' => $driver->id,
            'vehicle_type' => $driver->vehicle_type,
            'allowed_service_types' => $driver->allowed_service_types,
        ]);

        return response()->json(['message' => 'Driver config updated']);
    }

    public function manualOrder(Request $request, MultiOrderService $multiOrder): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::Operator], true), 403);

        $payload = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'service_type' => ['required', 'string', 'max:50'],
            'pickup_address' => ['required', 'string', 'max:255'],
            'pickup_lat' => ['required', 'numeric'],
            'pickup_lng' => ['required', 'numeric'],
            'destination_address' => ['required', 'string', 'max:255'],
            'destination_lat' => ['required', 'numeric'],
            'destination_lng' => ['required', 'numeric'],
            'price' => ['required', 'integer', 'min:0'],
            'service_charge' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $serviceCharge = $payload['service_charge'] ?? 0;
        $order = Order::create([
            ...$payload,
            'service_charge' => $serviceCharge,
            'direction_bearing' => $multiOrder->calculateBearing(
                (float) $payload['pickup_lat'],
                (float) $payload['pickup_lng'],
                (float) $payload['destination_lat'],
                (float) $payload['destination_lng'],
            ),
            'total_price' => $payload['price'] + $serviceCharge,
            'status' => OrderStatus::Created,
        ]);

        $this->recordAudit($request->user(), 'created_manual_order', $order, ['order_code' => $order->order_code]);

        return response()->json([
            'message' => 'Manual order created',
            'data' => $this->orderPayload($order->fresh(['user.branch', 'driver.user.branch'])),
        ], 201);
    }

    public function priceSettings(): JsonResponse
    {
        return response()->json([
            'data' => PriceSetting::query()->with('branch')->latest()->get(),
        ]);
    }

    public function storePriceSetting(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager, UserRole::SPV], true), 403);

        $setting = PriceSetting::create($this->validatePriceSetting($request));
        $this->recordAudit($request->user(), 'created_price_policy', $setting);

        return response()->json([
            'message' => 'Price setting created',
            'data' => $setting->load('branch'),
        ], 201);
    }

    public function updatePriceSetting(Request $request, PriceSetting $priceSetting): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager, UserRole::SPV], true), 403);
        $payload = $this->validatePriceSetting($request);
        $before = $priceSetting->only(array_keys($payload));
        $priceSetting->update($payload);
        $this->recordAudit($request->user(), 'updated_price_policy', $priceSetting, ['before' => $before, 'after' => $payload]);

        return response()->json([
            'message' => 'Price setting updated',
            'data' => $priceSetting->fresh('branch'),
        ]);
    }

    public function destroyPriceSetting(Request $request, PriceSetting $priceSetting): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::HRD], true), 403);
        $this->recordAudit($request->user(), 'deleted_price_policy', $priceSetting);
        $priceSetting->delete();

        return response()->json(['message' => 'Price setting deleted']);
    }

    public function branches(): JsonResponse
    {
        return response()->json([
            'data' => Branch::query()->withCount('geofenceAreas')->orderBy('name')->get(),
        ]);
    }

    public function storeBranch(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM], true), 403);

        $payload = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('branches', 'name')->where(fn (Builder $query) => $query->where('area', $request->input('area'))),
            ],
            'area' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
        ]);

        $payload['radius_km'] ??= 5;

        $branch = Branch::query()->create($payload);
        GeofenceArea::query()->create([
            'branch_id' => $branch->id,
            'name' => trim($branch->name.' '.($branch->area ? '- '.$branch->area : 'Area')),
            'description' => 'Default geofence dari titik cabang.',
            'center_latitude' => $branch->latitude,
            'center_longitude' => $branch->longitude,
            'radius_meters' => (int) round(((float) $branch->radius_km) * 1000),
            'is_active' => true,
            'priority' => 10,
        ]);
        $this->recordAudit($request->user(), 'created_branch', $branch, ['area' => $branch->area]);

        return response()->json([
            'message' => 'Branch created',
            'data' => $branch->loadCount('geofenceAreas'),
        ], 201);
    }

    public function geofences(): JsonResponse
    {
        return response()->json([
            'data' => GeofenceArea::query()->with('branch')->latest()->get(),
        ]);
    }

    public function locationLogs(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->locationLogsQuery($request->user())
                ->paginate($request->integer('per_page', 25))
                ->through(fn (LocationLog $log) => $this->locationLogPayload($log)),
        ]);
    }

    public function chats(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->chatsQuery($request->user())
                ->paginate($request->integer('per_page', 25))
                ->through(fn (ChatConversation $chat) => $this->chatPayload($chat)),
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        $orders = $this->ordersQuery($request->user());
        $users = $this->usersQuery($request->user());

        return response()->json([
            'data' => [
                'transaction_total' => (clone $orders)->sum('total_price'),
                'orders_count' => (clone $orders)->count(),
                'drivers_count' => (clone $users)->where('role', UserRole::Driver->value)->count(),
                'suspicious_logs_count' => $this->locationLogsQuery($request->user())->where('is_suspicious', true)->count(),
            ],
        ]);
    }

    public function driverDepositReport(Request $request, DriverReportService $reports): JsonResponse
    {
        [$month, $year] = $this->reportPeriod($request);

        return response()->json([
            'data' => [
                'month' => $month,
                'year' => $year,
                'rows' => $reports->monthlyDepositRows($month, $year)->values(),
            ],
        ]);
    }

    public function exportDriverDepositReport(Request $request, DriverReportService $reports): StreamedResponse
    {
        [$month, $year] = $this->reportPeriod($request);
        $period = now()->setDate($year, $month, 1)->startOfMonth();
        $rows = $reports->monthlyDepositRows($month, $year);
        $headers = $reports->monthlyDepositHeaders($period);
        $filename = 'rekap-setoran-driver-'.$period->format('Y-m').'.xls';

        return response()->streamDownload(function () use ($rows, $headers): void {
            echo '<html><head><meta charset="UTF-8"><style>';
            echo 'table{border-collapse:collapse;font-family:Arial,sans-serif;font-size:12px}';
            echo 'th,td{border:1px solid #000;padding:6px 8px;white-space:nowrap}';
            echo 'th{background:#c6d9f1;font-weight:700;text-align:center}';
            echo '.yellow{background:#ffff00}.orange{background:#ffc000}.right{text-align:right}.center{text-align:center}';
            echo '</style></head><body><table><thead><tr>';
            foreach ($headers as $index => $header) {
                $class = in_array($index, [8], true) ? 'orange' : (in_array($index, [11, 13], true) ? 'yellow' : '');
                echo '<th class="'.$class.'">'.e($header).'</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $cells = [
                    $row['driver'],
                    $row['area'],
                    $row['orders_count'],
                    $row['base_service_omset'],
                    $row['base_service_deposit'],
                    $row['previous_bill'],
                    $row['bpjs_jht'],
                    $row['bpjs'],
                    $row['previous_cashback_reward'],
                    $row['bill_before_bansos'],
                    $row['bansos'],
                    $row['total_bill'],
                    $row['paid_amount'],
                    $row['remaining_bill'],
                    $row['paid_at'] ?? '',
                    $row['next_cashback'],
                ];

                echo '<tr>';
                foreach ($cells as $index => $cell) {
                    $class = is_numeric($cell) ? 'right' : '';
                    $class .= in_array($index, [11, 13], true) ? ' yellow' : '';
                    echo '<td class="'.$class.'">'.e(is_numeric($cell) ? number_format((int) $cell, 0, '.', ',') : (string) $cell).'</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></body></html>';
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    public function updateSystemSettings(Request $request, SettingService $settings): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV], true), 403);

        $payload = $request->validate([
            'multi_order_enabled' => ['required', 'boolean'],
            'max_multi_order' => ['required', 'integer', 'min:1', 'max:3'],
            'feedback_templates' => ['sometimes', 'array'],
            'feedback_templates.driver_accepted' => ['nullable', 'string', 'max:500'],
            'feedback_templates.order_auto_cancelled' => ['nullable', 'string', 'max:500'],
            'feedback_templates.order_cancelled' => ['nullable', 'string', 'max:500'],
            'order_close_enabled' => ['sometimes', 'boolean'],
            'order_close_start' => ['nullable', 'date_format:H:i'],
            'order_close_end' => ['nullable', 'date_format:H:i'],
            'order_close_message' => ['nullable', 'string', 'max:500'],
            'night_tariff_enabled' => ['sometimes', 'boolean'],
            'night_tariff_rules' => ['sometimes', 'array'],
            'night_tariff_rules.*.area' => ['nullable', 'string', 'max:50'],
            'night_tariff_rules.*.start' => ['required_with:night_tariff_rules', 'date_format:H:i'],
            'night_tariff_rules.*.end' => ['required_with:night_tariff_rules', 'date_format:H:i'],
            'night_tariff_rules.*.percent' => ['required_with:night_tariff_rules', 'integer', 'min:0', 'max:300'],
        ]);

        $settings->set('multi_order_enabled', $payload['multi_order_enabled']);
        $settings->set('max_multi_order', $payload['max_multi_order']);
        $templates = $payload['feedback_templates'] ?? [];
        if (array_key_exists('driver_accepted', $templates)) {
            $settings->set(OrderFeedbackService::DRIVER_ACCEPTED_KEY, $templates['driver_accepted']);
        }
        if (array_key_exists('order_auto_cancelled', $templates)) {
            $settings->set(OrderFeedbackService::AUTO_CANCELLED_KEY, $templates['order_auto_cancelled']);
        }
        if (array_key_exists('order_cancelled', $templates)) {
            $settings->set(OrderFeedbackService::CANCELLED_KEY, $templates['order_cancelled']);
        }
        foreach (['order_close_enabled', 'order_close_start', 'order_close_end', 'order_close_message', 'night_tariff_enabled'] as $key) {
            if (array_key_exists($key, $payload)) {
                $settings->set($key, $payload[$key]);
            }
        }
        if (array_key_exists('night_tariff_rules', $payload)) {
            $settings->set('night_tariff_rules', json_encode(array_values($payload['night_tariff_rules'])));
        }

        return response()->json([
            'message' => 'System settings updated',
            'data' => $this->systemSettingsPayload($settings),
        ]);
    }

    private function validatePriceSetting(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'min_km' => ['required', 'numeric', 'min:0'],
            'max_km' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'integer', 'min:0'],
            'is_formula' => ['required', 'boolean'],
            'per_km_rate' => ['nullable', 'integer', 'min:0'],
            'subtract_value' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function stats(User $user): array
    {
        return [
            'total_users' => $this->usersQuery($user)->count(),
            'total_drivers' => $this->usersQuery($user)->where('role', UserRole::Driver->value)->count(),
            'active_orders' => $this->ordersQuery($user)->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])->count(),
            'suspended_drivers' => $this->usersQuery($user)->where('role', UserRole::Driver->value)->where('is_suspended', true)->count(),
        ];
    }

    private function reportPeriod(Request $request): array
    {
        $month = max(1, min(12, $request->integer('month', now()->month)));
        $year = max(2020, min(2100, $request->integer('year', now()->year)));

        return [$month, $year];
    }

    private function usersQuery(User $actor): Builder
    {
        $query = User::query()->with(['branch', 'driver']);

        return match ($actor->role) {
            UserRole::Admin, UserRole::GM => $query,
            UserRole::HRD => $query->whereIn('role', [UserRole::Manager->value, UserRole::SPV->value, UserRole::Operator->value, UserRole::Driver->value]),
            UserRole::Manager, UserRole::SPV, UserRole::Operator => $query->where('branch_id', $actor->branch_id)->whereNotIn('role', [UserRole::Admin->value, UserRole::GM->value]),
            default => $query->whereKey($actor->id),
        };
    }

    private function ordersQuery(User $actor): Builder
    {
        $query = Order::query()
            ->with(['user.branch', 'driver.user.branch']);

        if ($actor->role === UserRole::Manager || $actor->role === UserRole::SPV || $actor->role === UserRole::Operator) {
            $query->where(function (Builder $query) use ($actor): void {
                $query->whereHas('user', fn (Builder $query) => $query->where('branch_id', $actor->branch_id))
                    ->orWhereHas('driver.user', fn (Builder $query) => $query->where('branch_id', $actor->branch_id));
            });
        }

        return $query;
    }

    private function locationLogsQuery(User $actor): Builder
    {
        $query = LocationLog::query()->with(['user', 'branch', 'geofenceArea'])->latest();

        if (in_array($actor->role, [UserRole::Manager, UserRole::SPV, UserRole::Operator], true)) {
            $query->where('branch_id', $actor->branch_id);
        }

        return $query;
    }

    private function chatsQuery(User $actor): Builder
    {
        $query = ChatConversation::query()->with(['customer', 'driver', 'operator', 'branch', 'order', 'latestMessage'])->latest();

        if (in_array($actor->role, [UserRole::Manager, UserRole::SPV, UserRole::Operator], true)) {
            $query->where('branch_id', $actor->branch_id);
        }

        return $query;
    }

    private function auditLogsQuery(User $actor): Builder
    {
        $query = AuditLog::query()->with('user')->latest();

        if (in_array($actor->role, [UserRole::Manager, UserRole::SPV, UserRole::Operator], true)) {
            $query->whereHas('user', fn (Builder $query) => $query->where('branch_id', $actor->branch_id));
        }

        return $query;
    }

    private function permissionsFor(User $user): array
    {
        $permissionNames = $user->permissions();

        return [
            'backend_access' => in_array($user->role, [UserRole::Admin, UserRole::GM], true),
            'names' => $permissionNames,
            'assignable_roles' => collect($user->role->assignableRoles())->map->value->all(),
            'can_manage_policy' => $user->hasPermission('edit_tarif'),
            'can_manage_users' => $user->hasPermission('create_user'),
            'can_suspend_drivers' => $user->hasPermission('suspend_driver'),
            'can_unsuspend_drivers' => $user->hasPermission('unsuspend_driver'),
            'can_manage_driver_auth' => in_array($user->role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager], true)
                && $user->hasPermission('suspend_driver'),
            'can_manage_system_settings' => in_array($user->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV], true),
            'can_edit_order_price' => $user->hasPermission('edit_tarif'),
            'can_create_manual_order' => in_array($user->role, [UserRole::Admin, UserRole::GM, UserRole::Operator], true),
            'can_view_report' => $user->hasPermission('view_report'),
            'can_export_report' => $user->hasPermission('export_report'),
            'can_monitor_live_order' => $user->hasPermission('monitor_live_order'),
            'can_monitor_live_chat' => $user->hasPermission('monitor_live_chat'),
            'can_approve_cancel_order' => $user->hasPermission('approve_cancel_order'),
            'can_reject_cancel_order' => $user->hasPermission('reject_cancel_order'),
        ];
    }

    private function canAssignRole(User $actor, UserRole $role): bool
    {
        return $actor->role->canManageRole($role);
    }

    private function canManageUser(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        return $actor->role->canManageRole($target->role);
    }

    private function authorizeDriverAuthCms(Request $request): void
    {
        abort_unless(in_array($request->user()->role, [
            UserRole::Admin,
            UserRole::GM,
            UserRole::HRD,
            UserRole::Manager,
        ], true), 403);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role->value,
            'branch_id' => $user->branch_id,
            'branch' => $user->branch?->name,
            'branch_area' => $user->branch?->area,
            'is_active' => $user->is_active,
            'is_suspended' => $user->is_suspended,
            'driver_state' => $user->driver?->is_available ? 'online' : 'offline',
            'driver_bansos_amount' => $user->driver?->bansos_amount,
            'driver_bpjs_jht_enabled' => $user->driver?->bpjs_jht_enabled ?? false,
        ];
    }

    private function orderPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'code' => $order->order_code,
            'customer' => $order->user?->name,
            'driver' => $order->driver?->user?->name,
            'service' => $order->service_type,
            'source' => $order->source,
            'status' => $order->status->value,
            'cancel_reason' => $this->cancelReasonFor($order),
            'branch' => $order->user?->branch?->name ?? $order->driver?->user?->branch?->name,
            'price' => $order->price,
            'service_charge' => $order->service_charge,
            'extra_charge' => $order->extra_charge,
            'total' => $order->total_price,
            'direction_bearing' => $order->direction_bearing !== null ? (float) $order->direction_bearing : null,
            'is_multi_order' => $order->is_multi_order,
            'created_at' => $order->created_at?->toDateTimeString(),
        ];
    }

    private function cancelReasonFor(Order $order): ?string
    {
        if ($order->status !== OrderStatus::Cancelled) {
            return null;
        }

        $notes = trim((string) $order->notes);

        if (str_contains(strtolower($notes), 'driver timeout')) {
            return 'Auto-cancel: batas waktu cari driver habis (10 menit).';
        }

        if ($notes === '') {
            return 'Dibatalkan tanpa alasan tersimpan.';
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $notes) ?: [])));

        return $lines !== [] ? end($lines) : 'Dibatalkan tanpa alasan tersimpan.';
    }

    private function driverRows(User $actor): array
    {
        return $this->usersQuery($actor)
            ->where('role', UserRole::Driver->value)
            ->with(['driver.suspensions' => fn ($query) => $query->latest()->limit(5)])
            ->limit(100)
            ->get()
            ->map(fn (User $user): array => [
                ...$this->userPayload($user),
                'driver_id' => $user->driver?->id,
                'driver_status' => $user->driver?->status ?? ($user->is_suspended ? 'suspended' : 'active'),
                'google_bound' => filled($user->driver?->google_id),
                'google_email' => Schema::hasColumn('drivers', 'email') ? ($user->driver?->email ?? $user->email) : $user->email,
                'last_login_at' => $user->driver?->last_login_at?->toDateTimeString(),
                'last_login_ip' => $user->driver?->last_login_ip,
                'last_login_device' => $user->driver?->last_login_device,
                'auth_failed_attempts' => $user->driver?->auth_failed_attempts ?? 0,
                'auth_locked_until' => $user->driver?->auth_locked_until?->toDateTimeString(),
                'auth_suspended_at' => $user->driver?->auth_suspended_at?->toDateTimeString(),
                'suspended_until' => $user->driver?->suspended_until?->toDateTimeString() ?? $user->suspended_until?->toDateTimeString(),
                'suspension_reason' => $user->suspension_reason,
                'oper_handle_count' => $user->driver?->oper_handle_count ?? 0,
                'vehicle_type' => $user->driver?->vehicle_type ?? 'motor',
                'allowed_service_types' => $user->driver?->allowed_service_types ?? [],
                'suspensions' => $user->driver?->suspensions->map(fn ($suspension): array => [
                    'id' => $suspension->id,
                    'reason' => $suspension->reason,
                    'duration' => $suspension->duration,
                    'start_at' => $suspension->start_at?->toDateTimeString(),
                    'end_at' => $suspension->end_at?->toDateTimeString(),
                    'status' => $suspension->status,
                ])->all() ?? [],
            ])
            ->all();
    }

    private function normalizeServiceType(string $service): string
    {
        return match (strtolower(trim($service))) {
            'do' => 'delivery',
            'gift', 'gift order' => 'gift_order',
            'joker mobil', 'joker-mobile', 'joker' => 'joker_mobil',
            default => strtolower(trim($service)),
        };
    }

    private function systemSettingsPayload(SettingService $settings): array
    {
        return [
            'multi_order_enabled' => $settings->bool('multi_order_enabled', false),
            'max_multi_order' => max(1, min(3, $settings->int('max_multi_order', 3))),
            'feedback_templates' => app(OrderFeedbackService::class)->defaultTemplates(),
            'order_close_enabled' => $settings->bool('order_close_enabled', true),
            'order_close_start' => $settings->get('order_close_start', '01:00') ?: '01:00',
            'order_close_end' => $settings->get('order_close_end', '05:00') ?: '05:00',
            'order_close_message' => $settings->get('order_close_message', 'Maaf, sistem order sedang tutup. Order dibuka kembali pukul {end}.') ?: 'Maaf, sistem order sedang tutup. Order dibuka kembali pukul {end}.',
            'night_tariff_enabled' => $settings->bool('night_tariff_enabled', true),
            'night_tariff_rules' => app(OrderOperationService::class)->nightRules(),
        ];
    }

    private function locationLogPayload(LocationLog $log): array
    {
        return [
            'id' => $log->id,
            'user' => $log->user?->name,
            'branch' => $log->branch?->name,
            'geofence_area' => $log->geofenceArea?->name,
            'latitude' => (float) $log->latitude,
            'longitude' => (float) $log->longitude,
            'is_valid' => $log->is_valid,
            'is_suspicious' => $log->is_suspicious,
            'reason' => $log->suspicion_reason,
            'created_at' => $log->created_at?->toDateTimeString(),
        ];
    }

    private function auditLogPayload(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'user' => $log->user?->name ?? 'System',
            'role' => $log->user?->role?->value,
            'action' => $log->action,
            'subject_type' => class_basename($log->subject_type),
            'subject_id' => $log->subject_id,
            'subject_label' => $log->subject_label,
            'created_at' => $log->created_at?->toDateTimeString(),
        ];
    }

    private function chatPayload(ChatConversation $chat): array
    {
        return [
            'id' => $chat->id,
            'order_code' => $chat->order?->order_code,
            'customer' => $chat->customer?->name,
            'driver' => $chat->driver?->name,
            'operator' => $chat->operator?->name,
            'branch' => $chat->branch?->name,
            'status' => $chat->status,
            'latest_message' => $chat->latestMessage?->message,
            'updated_at' => $chat->updated_at?->toDateTimeString(),
        ];
    }

    private function generatePassword(): string
    {
        return 'JOJO-'.Str::upper(Str::random(6)).'-'.random_int(10, 99);
    }

    private function recordAudit(User $actor, string $action, object $subject, array $metadata = []): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->id ?? null,
            'subject_label' => $subject->name ?? $subject->order_code ?? $subject->email ?? null,
            'metadata' => $metadata,
        ]);
    }
}
