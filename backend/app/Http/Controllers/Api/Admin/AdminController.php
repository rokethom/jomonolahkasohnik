<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Actions\Order\AcceptOrder;
use App\Events\OrderPriceUpdated;
use App\Actions\Order\CreateOrder;
use App\Exceptions\OrderLimitExceededException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ChatConversation;
use App\Models\Driver;
use App\Models\GeofenceArea;
use App\Models\KeywordParser;
use App\Models\LocationLog;
use App\Models\OperHandleRequest;
use App\Models\Order;
use App\Models\PriceSetting;
use App\Models\PricingKeywordRule;
use App\Models\RingPricingRule;
use App\Models\RingPricingSuggestion;
use App\Models\Service;
use App\Models\User;
use App\Models\ZonePricingRule;
use App\Services\AdminDashboardMetricsService;
use App\Services\AdminRoleMenuOverrideService;
use App\Services\AiParserRuleService;
use App\Services\BranchDetectionService;
use App\Services\BranchAccessSettingService;
use App\Services\DriverDailyPriorityService;
use App\Services\DriverFinanceService;
use App\Services\DriverManagementCsvService;
use App\Services\DriverReportService;
use App\Services\DriverSuspendService;
use App\Services\JojoBotService;
use App\Services\KeywordParserService;
use App\Services\MultiOrderService;
use App\Services\NotificationService;
use App\Services\OrderFeedbackService;
use App\Services\OrderOperationService;
use App\Services\OrderService;
use App\Services\PricingService;
use App\Services\PricingKeywordRuleService;
use App\Services\Pricing\DistanceCalculator;
use App\Services\RatingService;
use App\Services\RingPricingService;
use App\Services\RolePermissionSettingService;
use App\Services\SettingService;
use App\Services\SLAService;
use App\Services\ZonePricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    private const DEFAULT_ASSIGN_DRIVER_ROLES = ['operator', 'eksekutor'];

    public function bootstrap(Request $request, SettingService $settings, OrderService $orders, SLAService $slaService, DriverSuspendService $driverSuspensions): JsonResponse
    {
        $orders->cancelExpiredCreatedOrders();
        $driverSuspensions->releaseExpiredSuspensions();

        $user = $request->user()->load(['branch', 'branchScopes']);
        $slaService->enforceUnansweredOperatorChats((clone $this->chatsQuery($user)));

        return response()->json([
            'me' => $this->userPayload($user),
            'permissions' => $this->permissionsFor($user),
            'system_settings' => $this->systemSettingsPayload($settings),
            'stats' => $this->stats($user),
            'users' => $this->usersQuery($user)->limit(100)->get()->map(fn (User $item) => $this->userPayload($item)),
            'drivers' => $this->driverRows($user),
            'operator_performance' => $this->operatorPerformanceRows($user),
            'orders' => $this->ordersQuery($user)->latest()->limit(100)->get()->map(fn (Order $order) => $this->orderPayload($order, $user)),
            'oper_handles' => $this->operHandlesQuery($user)->latest('updated_at')->limit(50)->get()->map(fn (OperHandleRequest $operHandle) => $this->operHandlePayload($operHandle)),
            'branches' => Branch::query()
                ->with('geofenceAreas:id,branch_id,name,center_latitude,center_longitude,radius_meters,is_active')
                ->withCount('geofenceAreas')
                ->orderBy('name')
                ->get(),
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'whatsapp_redirect_enabled', 'outside_area_only', 'whatsapp_number']),
            'price_settings' => PriceSetting::query()->with('branch')->latest()->get(),
            'keyword_parsers' => $this->keywordParsersQuery()->get()->map(fn (KeywordParser $parser) => $this->keywordParserPayload($parser)),
            'pricing_keyword_rules' => $this->pricingKeywordRulesQuery()->get()->map(fn (PricingKeywordRule $rule) => $this->pricingKeywordRulePayload($rule)),
            'ring_pricing_rules' => $this->ringPricingRulesQuery($user)->get()->map(fn (RingPricingRule $rule) => $this->ringPricingRulePayload($rule)),
            'ring_pricing_suggestions' => $this->ringPricingSuggestionsQuery($user)->limit(30)->get()->map(fn (RingPricingSuggestion $suggestion) => $this->ringPricingSuggestionPayload($suggestion)),
            'zone_pricing_rules' => $this->zonePricingRulesQuery($user)->get()->map(fn (ZonePricingRule $rule) => $this->zonePricingRulePayload($rule)),
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
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['required', new Enum(UserRole::class)],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'branch_scope_ids' => ['nullable', 'array'],
            'branch_scope_ids.*' => ['integer', 'exists:branches,id'],
            'is_active' => ['sometimes', 'boolean'],
            'is_suspended' => ['sometimes', 'boolean'],
            'suspension_reason' => ['nullable', 'string'],
            'driver_bansos_amount' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'driver_bpjs_jht_enabled' => ['sometimes', 'boolean'],
            'vehicle_type' => ['nullable', 'string', 'in:motor,mobil'],
            'vehicle_types' => ['nullable', 'array'],
            'vehicle_types.*' => ['string', 'in:motor,mobil'],
            'vehicle_seat_rows' => ['nullable', 'integer', 'in:2,3'],
            'is_ladies_driver' => ['sometimes', 'boolean'],
            'can_accept_all_areas' => ['sometimes', 'boolean'],
            'allowed_service_types' => ['nullable', 'array'],
            'allowed_service_types.*' => ['string', 'max:80'],
        ]);

        $role = UserRole::from($payload['role']);
        abort_unless($this->canAssignRole($actor, $role), 403);
        $payload['branch_id'] = $this->branchIdForUserWrite($actor, $payload['branch_id'] ?? null, $role);
        $branchScopeIds = $this->branchScopeIdsForUserWrite($actor, $role, $payload['branch_scope_ids'] ?? [], $payload['branch_id'] ?? null);
        if ($role === UserRole::Driver) {
            $payload['is_suspended'] = false;
            $payload['suspension_reason'] = null;
            $payload['suspended_until'] = null;
        }

        $vehicleTypes = $this->normalizeVehicleTypes($payload['vehicle_types'] ?? [$payload['vehicle_type'] ?? 'motor']);
        $driverPayload = [
            'bpjs_jht_enabled' => $payload['driver_bpjs_jht_enabled'] ?? true,
            'vehicle_type' => $vehicleTypes[0] ?? 'motor',
            'vehicle_types' => $vehicleTypes,
            'vehicle_seat_rows' => in_array('mobil', $vehicleTypes, true) ? ($payload['vehicle_seat_rows'] ?? 2) : null,
            'is_ladies_driver' => $payload['is_ladies_driver'] ?? false,
            'can_accept_all_areas' => $payload['can_accept_all_areas'] ?? false,
        ];
        if (array_key_exists('allowed_service_types', $payload)) {
            $driverPayload['allowed_service_types'] = array_values(array_unique(array_filter(array_map('strval', $payload['allowed_service_types'] ?? []))));
        }
        if (array_key_exists('driver_bansos_amount', $payload)) {
            $driverPayload['bansos_amount'] = $payload['driver_bansos_amount'];
        }
        unset($payload['branch_scope_ids'], $payload['driver_bansos_amount'], $payload['driver_bpjs_jht_enabled'], $payload['vehicle_type'], $payload['vehicle_types'], $payload['vehicle_seat_rows'], $payload['is_ladies_driver'], $payload['can_accept_all_areas'], $payload['allowed_service_types']);

        $password = filled($payload['password'] ?? null) ? (string) $payload['password'] : $this->generatePassword();
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

        $user->branchScopes()->sync($branchScopeIds);

        $this->recordAudit($actor, 'created_user', $user, ['role' => $user->role->value]);

        return response()->json([
            'message' => 'User created',
            'temporary_password' => $password,
            'data' => $this->userPayload($user->fresh('branch', 'branchScopes', 'driver')),
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
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['sometimes', new Enum(UserRole::class)],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'branch_scope_ids' => ['nullable', 'array'],
            'branch_scope_ids.*' => ['integer', 'exists:branches,id'],
            'is_active' => ['sometimes', 'boolean'],
            'is_suspended' => ['sometimes', 'boolean'],
            'suspension_reason' => ['nullable', 'string'],
            'suspended_until' => ['nullable', 'date'],
            'driver_bansos_amount' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'driver_bpjs_jht_enabled' => ['sometimes', 'boolean'],
            'vehicle_types' => ['nullable', 'array'],
            'vehicle_types.*' => ['string', 'in:motor,mobil'],
            'vehicle_seat_rows' => ['nullable', 'integer', 'in:2,3'],
            'is_ladies_driver' => ['sometimes', 'boolean'],
            'can_accept_all_areas' => ['sometimes', 'boolean'],
            'allowed_service_types' => ['nullable', 'array'],
            'allowed_service_types.*' => ['string', 'max:80'],
        ]);

        if (isset($payload['role'])) {
            abort_unless($this->canAssignRole($actor, UserRole::from($payload['role'])), 403);
        }

        if (array_key_exists('branch_id', $payload)) {
            $payload['branch_id'] = $this->branchIdForUserWrite($actor, $payload['branch_id'] ?? null, isset($payload['role']) ? UserRole::from($payload['role']) : $user->role);
        }

        $targetRole = isset($payload['role']) ? UserRole::from($payload['role']) : $user->role;
        $branchScopeIds = array_key_exists('branch_scope_ids', $payload)
            ? $this->branchScopeIdsForUserWrite($actor, $targetRole, $payload['branch_scope_ids'] ?? [], $payload['branch_id'] ?? $user->branch_id)
            : ((array_key_exists('branch_id', $payload) || array_key_exists('role', $payload))
                ? $this->branchScopeIdsForUserWrite($actor, $targetRole, [], $payload['branch_id'] ?? $user->branch_id)
                : null);

        $newPassword = filled($payload['password'] ?? null) ? (string) $payload['password'] : null;
        unset($payload['password'], $payload['branch_scope_ids']);

        $before = $user->only(array_keys($payload));
        $driverPayload = [];
        if (array_key_exists('driver_bansos_amount', $payload)) {
            $driverPayload['bansos_amount'] = $payload['driver_bansos_amount'];
        }
        if (array_key_exists('driver_bpjs_jht_enabled', $payload)) {
            $driverPayload['bpjs_jht_enabled'] = $payload['driver_bpjs_jht_enabled'];
        }
        if (array_key_exists('vehicle_types', $payload)) {
            $vehicleTypes = $this->normalizeVehicleTypes($payload['vehicle_types'] ?? []);
            $driverPayload['vehicle_types'] = $vehicleTypes;
            $driverPayload['vehicle_type'] = $vehicleTypes[0] ?? 'motor';
            $driverPayload['vehicle_seat_rows'] = in_array('mobil', $vehicleTypes, true) ? ($payload['vehicle_seat_rows'] ?? $user->driver?->vehicle_seat_rows ?? 2) : null;
        }
        if (array_key_exists('vehicle_seat_rows', $payload)) {
            $driverPayload['vehicle_seat_rows'] = $payload['vehicle_seat_rows'];
        }
        if (array_key_exists('is_ladies_driver', $payload)) {
            $driverPayload['is_ladies_driver'] = $payload['is_ladies_driver'];
        }
        if (array_key_exists('can_accept_all_areas', $payload)) {
            $driverPayload['can_accept_all_areas'] = $payload['can_accept_all_areas'];
        }
        if (array_key_exists('allowed_service_types', $payload)) {
            $driverPayload['allowed_service_types'] = array_values(array_unique(array_filter(array_map('strval', $payload['allowed_service_types'] ?? []))));
        }
        unset($payload['driver_bansos_amount'], $payload['driver_bpjs_jht_enabled'], $payload['vehicle_types'], $payload['vehicle_seat_rows'], $payload['is_ladies_driver'], $payload['can_accept_all_areas'], $payload['allowed_service_types']);

        $user->update($newPassword ? [...$payload, 'password' => $newPassword] : $payload);
        if ($newPassword) {
            $user->tokens()->delete();
            $user->deviceTokens()->update(['is_active' => false]);
        }
        $nextRole = $payload['role'] ?? ($user->role instanceof UserRole ? $user->role->value : (string) $user->role);
        if ($nextRole === UserRole::Driver->value) {
            $driver = $user->driver()->firstOrCreate([], [
                'is_available' => true,
                'status' => 'active',
                ...$driverPayload,
            ]);
            if ($driver->wasRecentlyCreated) {
                $user->forceFill([
                    'is_suspended' => false,
                    'suspension_reason' => null,
                    'suspended_until' => null,
                ])->save();
            }
            if ($driverPayload !== []) {
                $driver->update($driverPayload);
            }
        }
        if ($branchScopeIds !== null) {
            $user->branchScopes()->sync($branchScopeIds);
        }
        $this->recordAudit($actor, 'updated_user', $user, ['before' => $before, 'after' => $payload]);

        return response()->json([
            'message' => 'User updated',
            'data' => $this->userPayload($user->fresh('branch', 'branchScopes', 'driver')),
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

    public function exportDriverManagementCsv(Request $request, DriverManagementCsvService $csv): StreamedResponse
    {
        abort_unless($request->user()->hasPermission('create_user'), 403);

        return $csv->downloadCsv();
    }

    public function importDriverManagementCsv(Request $request, DriverManagementCsvService $csv): JsonResponse
    {
        abort_unless($request->user()->hasPermission('create_user'), 403);

        $payload = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'allow_create' => ['sometimes', 'boolean'],
        ]);

        $result = $csv->importCsv(
            $payload['file']->getRealPath(),
            (bool) ($payload['allow_create'] ?? true),
        );

        return response()->json([
            'message' => "Import selesai: {$result['created']} driver baru, {$result['updated']} driver update, {$result['skipped']} baris dilewati.",
            ...$result,
        ]);
    }

    public function orders(Request $request, OrderService $orders): JsonResponse
    {
        $orders->cancelExpiredCreatedOrders();

        return response()->json([
            'data' => $this->ordersQuery($request->user())
                ->latest()
                ->paginate($request->integer('per_page', 25))
                ->through(fn (Order $order) => $this->orderPayload($order, $request->user())),
        ]);
    }

    public function assignDriver(Request $request, Order $order, AcceptOrder $acceptOrder, NotificationService $notifications): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->canAssignDriver($actor), 403, 'Role Anda tidak diizinkan assign driver.');

        $payload = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $order->loadMissing(['user.branch', 'driver.user.branch']);
        $this->assertOrderAreaScope($actor, $order);

        $driver = Driver::query()->with(['user.branch'])->findOrFail($payload['driver_id']);
        $driverMatchesOrderArea = (int) $driver->user?->branch_id === (int) ($order->branch_id ?? $order->user?->branch_id);
        abort_unless($driverMatchesOrderArea || (bool) $driver->can_accept_all_areas || in_array($actor->role, [UserRole::Admin, UserRole::GM], true), 403, 'Driver di luar area dan belum diberi akses all area.');
        abort_unless($driver->status === 'active' && ! $driver->is_suspend, 422, 'Driver tidak aktif.');
        abort_unless($driver->is_available, 422, 'Driver sedang tidak idle/online.');
        abort_unless(! $this->driverHasActiveOrder($driver), 422, 'Driver masih memiliki order aktif.');

        try {
            $assigned = $acceptOrder->handle($order, $driver, ignoreDailyPriority: true);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $assignReason = trim((string) ($payload['reason'] ?? '')) ?: 'Manual assign dispatcher';
        $breakdown = $assigned->pricing_breakdown ?? [];
        $breakdown['admin_assign'] = [
            'reason' => $assignReason,
            'assigned_by' => $actor->name,
            'assigned_by_role' => $actor->role instanceof UserRole ? $actor->role->value : (string) $actor->role,
            'assigned_at' => now()->toIso8601String(),
        ];
        $assigned->update(['pricing_breakdown' => $breakdown]);
        $assigned = $assigned->fresh(['user.branch', 'driver.user.branch']);

        $notifications->sendToUser(
            $driver->user,
            'Order ditugaskan dispatcher',
            "Order {$assigned->order_code} ditugaskan oleh {$actor->name}. Alasan: {$assignReason}.",
            [
                'type' => 'dispatcher_assigned_order',
                'order_id' => $assigned->id,
                'order_code' => $assigned->order_code,
                'assigned_by' => $actor->id,
                'assign_reason' => $assignReason,
            ],
        );

        $this->recordAudit($actor, 'assigned_driver_to_order', $assigned, [
            'driver_id' => $driver->id,
            'reason' => $payload['reason'] ?? null,
        ]);

        return response()->json([
            'message' => 'Driver berhasil di-assign ke order.',
            'data' => $this->orderPayload($assigned, $actor),
        ]);
    }

    public function broadcastDrivers(Request $request, Order $order, NotificationService $notifications): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->canAssignDriver($actor), 403, 'Role Anda tidak diizinkan broadcast driver.');

        $order->loadMissing(['branch', 'user.branch', 'driver.user.branch']);
        $this->assertOrderAreaScope($actor, $order);
        abort_unless(in_array($order->status, [OrderStatus::Created, OrderStatus::SearchingDriver], true), 422, 'Broadcast hanya untuk order pending.');

        $driverIds = collect($this->suggestedDriversForOrder($order, $actor))->pluck('id')->all();
        $drivers = Driver::query()->with('user')->whereIn('id', $driverIds)->get();

        foreach ($drivers as $driver) {
            $notifications->sendToUser(
                $driver->user,
                'Pending order area',
                "Order {$order->order_code} masih menunggu driver di area Anda.",
                [
                    'type' => 'dispatcher_broadcast_order',
                    'order_id' => $order->id,
                    'order_code' => $order->order_code,
                    'broadcast_by' => $actor->id,
                ],
            );
        }

        $this->recordAudit($actor, 'broadcast_pending_order_to_drivers', $order, [
            'driver_count' => $drivers->count(),
        ]);

        return response()->json([
            'message' => "Broadcast terkirim ke {$drivers->count()} driver idle area.",
            'driver_count' => $drivers->count(),
        ]);
    }

    public function updateOrderPrice(Request $request, Order $order): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV, UserRole::Operator, UserRole::Eksekutor], true), 403);
        $order->loadMissing(['branch', 'user.branch', 'driver.user.branch']);
        $this->assertOrderAreaScope($request->user(), $order);
        abort_if($order->status->isTerminal(), 422, 'Harga hanya bisa diedit saat order masih berjalan.');

        $payload = $request->validate([
            'price' => ['required', 'integer', 'min:0'],
            'service_charge' => ['sometimes', 'integer', 'min:0'],
        ]);

        $serviceCharge = $payload['service_charge'] ?? $order->service_charge;
        $before = [
            'price' => $order->price,
            'service_charge' => $order->service_charge,
            'total' => $order->total_price,
        ];
        $order->update([
            'price' => $payload['price'],
            'service_charge' => $serviceCharge,
            'total_price' => $payload['price'] + $serviceCharge + $order->extra_charge,
        ]);

        $freshOrder = $order->fresh(['user.branch', 'driver.user.branch']);
        $after = [
            'price' => $freshOrder->price,
            'service_charge' => $freshOrder->service_charge,
            'total' => $freshOrder->total_price,
        ];
        $this->recordAudit($request->user(), 'updated_order_price', $freshOrder, [
            'actor_name' => $request->user()->name,
            'before' => $before,
            'after' => $after,
        ]);
        app(RingPricingService::class)->recordPriceEdit($freshOrder, $request->user(), (int) $before['price'], (int) $after['price']);

        try {
            OrderPriceUpdated::dispatch($freshOrder, $request->user(), [
                'before' => $before,
                'after' => $after,
            ]);
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

    public function markDriverDepositPaid(Request $request, Driver $driver, DriverFinanceService $finance, DriverSuspendService $suspensions): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager, UserRole::SPV], true), 403);

        $payload = $request->validate([
            'full' => ['nullable', 'boolean'],
            'amount' => ['nullable', 'integer', 'min:1', 'required_if:full,false'],
        ]);

        $deposit = $finance->monthlyDeposit($driver->load('user.branch'), now()->subMonth());
        $targetTotal = (int) $deposit->total;
        $currentPaid = (int) $deposit->paid_amount;
        $paymentAmount = $request->boolean('full', false) || ! isset($payload['amount'])
            ? max(0, $targetTotal - $currentPaid)
            : min((int) $payload['amount'], max(0, $targetTotal - $currentPaid));
        $paidAmount = min($targetTotal, $currentPaid + $paymentAmount);
        $isPaid = $targetTotal <= 0 || $paidAmount >= $targetTotal;

        $deposit->forceFill([
            'paid_amount' => $paidAmount,
            'paid_at' => $paidAmount > 0 ? now() : null,
            'status' => $isPaid ? 'paid' : 'unpaid',
        ])->save();

        if ($isPaid && $driver->status === 'suspended_unpaid') {
            $suspensions->release($driver->fresh('user'), $request->user());
        }

        $driver->fresh()->update(['is_available' => false]);

        $this->recordAudit($request->user(), 'marked_driver_deposit_paid', $driver, [
            'deposit_id' => $deposit->id,
            'paid_amount' => $deposit->paid_amount,
            'payment_amount' => $paymentAmount,
            'remaining_amount' => max(0, $targetTotal - $paidAmount),
            'status' => $deposit->status,
            'period' => $deposit->year.'-'.str_pad((string) $deposit->month, 2, '0', STR_PAD_LEFT),
        ]);

        return response()->json([
            'message' => $isPaid
                ? 'Setoran driver lunas. Driver bisa ON dari aplikasi driver.'
                : 'Pembayaran parsial tersimpan. Sisa tagihan tetap tercatat dan akan dicek pada tanggal 11.',
            'deposit' => $deposit->fresh(),
            'driver' => $driver->fresh(['user.branch']),
        ]);
    }

    public function markDriverDepositUnpaid(Request $request, Driver $driver, DriverFinanceService $finance): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager, UserRole::SPV], true), 403);

        $payload = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $deposit = $finance->monthlyDeposit($driver->load('user.branch'), now()->subMonth());
        $deposit->forceFill([
            'paid_amount' => 0,
            'paid_at' => null,
            'status' => 'unpaid',
        ])->save();

        $driver->update(['is_available' => false]);

        $this->recordAudit($request->user(), 'marked_driver_deposit_unpaid', $driver, [
            'deposit_id' => $deposit->id,
            'period' => $deposit->year.'-'.str_pad((string) $deposit->month, 2, '0', STR_PAD_LEFT),
            'reason' => $payload['reason'] ?? null,
        ]);

        return response()->json([
            'message' => 'Setoran driver ditandai unpaid. Driver otomatis OFF dan tidak bisa menerima/request order.',
            'deposit' => $deposit->fresh(),
            'driver' => $driver->fresh(['user.branch']),
        ]);
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
            'vehicle_type' => ['nullable', 'string', 'in:motor,mobil'],
            'vehicle_types' => ['nullable', 'array'],
            'vehicle_types.*' => ['string', 'in:motor,mobil'],
            'vehicle_seat_rows' => ['nullable', 'integer', 'in:2,3'],
            'is_ladies_driver' => ['sometimes', 'boolean'],
            'can_accept_all_areas' => ['sometimes', 'boolean'],
            'allowed_service_types' => ['array'],
            'allowed_service_types.*' => ['string', 'max:50'],
        ]);

        $vehicleTypes = $this->normalizeVehicleTypes($payload['vehicle_types'] ?? [$payload['vehicle_type'] ?? $driver->vehicle_type ?? 'motor']);

        $driver->update([
            'vehicle_type' => $vehicleTypes[0] ?? 'motor',
            'vehicle_types' => $vehicleTypes,
            'vehicle_seat_rows' => in_array('mobil', $vehicleTypes, true) ? ($payload['vehicle_seat_rows'] ?? 2) : null,
            'is_ladies_driver' => $payload['is_ladies_driver'] ?? false,
            'can_accept_all_areas' => $payload['can_accept_all_areas'] ?? false,
            'allowed_service_types' => array_values(array_unique(array_map(
                fn ($service): string => $this->normalizeServiceType((string) $service),
                $payload['allowed_service_types'] ?? [],
            ))),
        ]);

        $this->recordAudit($request->user(), 'updated_driver_config', $driver->user, [
            'driver_id' => $driver->id,
            'vehicle_type' => $driver->vehicle_type,
            'vehicle_types' => $driver->vehicle_types,
            'vehicle_seat_rows' => $driver->vehicle_seat_rows,
            'is_ladies_driver' => $driver->is_ladies_driver,
            'can_accept_all_areas' => $driver->can_accept_all_areas,
            'allowed_service_types' => $driver->allowed_service_types,
        ]);

        return response()->json(['message' => 'Driver config updated']);
    }

    public function previewManualOrder(Request $request, JojoBotService $jojoBot): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV, UserRole::Operator, UserRole::Eksekutor], true), 403);

        $payload = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'raw_text' => ['required', 'string', 'max:4000'],
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);

        $customer = $this->manualPreviewCustomer($request, $payload);
        $this->assertManualOrderCustomerScope($request->user(), $customer);

        try {
            $preview = $this->withManualOrderContact(
                $jojoBot->preview($customer, $payload['raw_text']),
                $this->manualOrderContactFromText($payload['raw_text']),
            );
        } catch (\Throwable $exception) {
            Log::warning('admin.manual_order_preview_failed', [
                'admin_id' => $request->user()?->id,
                'customer_id' => $customer->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'JOJOBOT belum berhasil menghitung pesanan. Coba ulangi sebentar lagi atau cek alamat pickup dan tujuan.',
                'data' => [
                    'intent' => 'pricing_unavailable',
                    'reply' => 'JOJOBOT belum berhasil menghitung pesanan. Coba ulangi sebentar lagi atau cek alamat pickup dan tujuan.',
                ],
            ]);
        }

        return response()->json([
            'message' => $preview['message'] ?? $preview['reply'] ?? null,
            'data' => $preview,
        ]);
    }

    public function manualOrder(Request $request, MultiOrderService $multiOrder, CreateOrder $createOrder, AiParserRuleService $parserRules): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV, UserRole::Operator, UserRole::Eksekutor], true), 403);

        if ($request->has('order_payload')) {
            $payload = $request->validate([
                'user_id' => ['nullable', 'exists:users,id'],
                'customer_name' => ['nullable', 'string', 'max:255'],
                'customer_phone' => ['nullable', 'string', 'max:30'],
                'customer_address' => ['nullable', 'string', 'max:500'],
                'parsed_customer' => ['nullable', 'array'],
                'parsed_customer.name' => ['nullable', 'string', 'max:255'],
                'parsed_customer.phone' => ['nullable', 'string', 'max:30'],
                'parsed_customer.address' => ['nullable', 'string', 'max:500'],
                'branch_id' => ['nullable', 'exists:branches,id'],
                'raw_text' => ['nullable', 'string', 'max:4000'],
                'order_payload' => ['required', 'array'],
                'order_payload.branch_id' => ['nullable', 'exists:branches,id'],
                'order_payload.service_type' => ['required', 'string', 'max:50'],
                'order_payload.pickup_address' => ['required', 'string', 'max:255'],
                'order_payload.pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
                'order_payload.pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
                'order_payload.destination_address' => ['required', 'string', 'max:255'],
                'order_payload.destination_lat' => ['nullable', 'numeric', 'between:-90,90'],
                'order_payload.destination_lng' => ['nullable', 'numeric', 'between:-180,180'],
                'order_payload.stops' => ['sometimes', 'integer', 'min:1'],
                'order_payload.notes' => ['nullable', 'string'],
                'order_payload.items' => ['sometimes', 'array'],
                'order_payload.points' => ['sometimes', 'array', 'max:5'],
                'order_payload.payment_method' => ['nullable', 'string', 'in:cash,transfer,qris'],
                'order_payload.preferred_vehicle_type' => ['nullable', 'string', 'in:motor,mobil'],
                'order_payload.vehicle_seat_rows' => ['nullable', 'integer', 'in:2,3'],
                'order_payload.service_payload' => ['nullable', 'array'],
                'order_payload.service_payload.preferred_vehicle_type' => ['nullable', 'string', 'in:motor,mobil'],
                'order_payload.service_payload.vehicle_seat_rows' => ['nullable', 'integer', 'in:2,3'],
                'price_override' => ['nullable', 'integer', 'min:0'],
                'service_charge_override' => ['nullable', 'integer', 'min:0'],
            ]);

            $customer = $this->manualOrderCustomer($request, $payload);
            $this->assertManualOrderCustomerScope($request->user(), $customer);

            $orderPayload = $payload['order_payload'];
            $orderPayload['notes'] = trim((string) ($orderPayload['notes'] ?? '')."\nDibuat dari dashboard oleh ".$request->user()->name);
            $orderPayloads = $this->manualOrderPayloads($orderPayload);

            try {
                $orders = DB::transaction(function () use ($createOrder, $customer, $orderPayloads, $payload): array {
                    $created = [];

                    foreach ($orderPayloads as $manualPayload) {
                        $order = $createOrder->handle($customer, $manualPayload);
                        $order->forceFill(['source' => 'dashboard_manual'])->save();

                        if (array_key_exists('price_override', $payload) || array_key_exists('service_charge_override', $payload)) {
                            $price = $payload['price_override'] ?? $order->price;
                            $serviceCharge = $payload['service_charge_override'] ?? $order->service_charge;
                            $order->forceFill([
                                'price' => $price,
                                'service_charge' => $serviceCharge,
                                'total_price' => $price + $serviceCharge + $order->extra_charge,
                            ])->save();
                        }

                        $created[] = $order->fresh(['user.branch', 'driver.user.branch']);
                    }

                    return $created;
                });
            } catch (OrderLimitExceededException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'active_orders' => $exception->activeOrders,
                    'max_orders' => $exception->maxOrders,
                ], 422);
            } catch (ValidationException $exception) {
                return response()->json([
                    'message' => collect($exception->errors())->flatten()->first() ?? 'Order belum bisa dibuat.',
                    'errors' => $exception->errors(),
                ], 422);
            }
            $order = $orders[0];

            $this->rememberManualOrderParserRule($parserRules, $payload, $order);

            foreach ($orders as $createdOrder) {
                $this->recordAudit($request->user(), 'created_dashboard_text_order', $createdOrder, [
                    'order_code' => $createdOrder->order_code,
                    'branch_id' => $createdOrder->branch_id,
                ]);
            }

            return response()->json([
                'message' => $this->manualOrderContainsTart($orderPayload)
                    ? 'Manual order crew dibuat. Rider utama akan menerima order, lalu sistem membuka slot helper.'
                    : 'Manual order created from dashboard parser',
                'data' => $this->orderPayload($order),
                'orders' => collect($orders)->map(fn (Order $createdOrder): array => $this->orderPayload($createdOrder))->values(),
            ], 201);
        }

        $payload = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'customer_name' => ['required_without:user_id', 'nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'exists:branches,id'],
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

        $customer = $this->manualOrderCustomer($request, $payload);
        $this->assertManualOrderCustomerScope($request->user(), $customer);

        $serviceCharge = $payload['service_charge'] ?? 0;
        $order = Order::create([
            'user_id' => $customer->id,
            'branch_id' => $customer->branch_id,
            'service_type' => $payload['service_type'],
            'pickup_address' => $payload['pickup_address'],
            'pickup_lat' => $payload['pickup_lat'],
            'pickup_lng' => $payload['pickup_lng'],
            'destination_address' => $payload['destination_address'],
            'destination_lat' => $payload['destination_lat'],
            'destination_lng' => $payload['destination_lng'],
            'price' => $payload['price'],
            'notes' => $payload['notes'] ?? null,
            'source' => 'dashboard_manual',
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

    private function assertManualOrderCustomerScope(User $actor, User $customer): void
    {
        $branchIds = $this->operationalBranchScopeIds($actor);
        if ($branchIds === null) {
            return;
        }

        abort_unless($customer->branch_id !== null && in_array((int) $customer->branch_id, $branchIds, true), 403, 'Customer di luar area akun ini.');
    }

    private function manualOrderContactFromText(string $text): array
    {
        return [
            'name' => $this->manualOrderTextField($text, '(?:nama|customer|pemesan)'),
            'phone' => $this->manualOrderTextField($text, '(?:hp|no\\s*hp|wa|whatsapp|telepon|phone)'),
            'address' => $this->manualOrderTextField($text, '(?:alamat\\s*(?:customer|pemesan)?|alamat\\s*antar)'),
        ];
    }

    private function manualOrderTextField(string $text, string $label): ?string
    {
        if (preg_match('/(?:^|\\R)\\s*'.$label.'\\s*(?:\\/\\s*(?:hp|wa|whatsapp|telepon|phone))?\\s*[:=\\-]\\s*(.+)$/imu', $text, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    private function withManualOrderContact(array $preview, array $contact): array
    {
        $preview['parsed'] = is_array($preview['parsed'] ?? null) ? $preview['parsed'] : [];
        $preview['parsed']['name'] = $contact['name'] ?? data_get($preview, 'parsed.name');
        $preview['parsed']['phone'] = $contact['phone'] ?? data_get($preview, 'parsed.phone');
        $preview['parsed']['address'] = $contact['address'] ?? data_get($preview, 'parsed.address');
        $preview['parsed']['customer'] = [
            'name' => $preview['parsed']['name'],
            'phone' => $preview['parsed']['phone'],
            'address' => $preview['parsed']['address'],
        ];

        return $preview;
    }

    private function rememberManualOrderParserRule(AiParserRuleService $parserRules, array $payload, Order $order): void
    {
        $rawText = trim((string) ($payload['raw_text'] ?? ''));
        if ($rawText === '') {
            return;
        }

        $orderPayload = is_array($payload['order_payload'] ?? null) ? $payload['order_payload'] : [];
        $parsedCustomer = is_array($payload['parsed_customer'] ?? null) ? $payload['parsed_customer'] : [];
        $items = is_array($orderPayload['items'] ?? null) ? $orderPayload['items'] : [];

        $parserRules->remember($rawText, [
            'service_type' => $orderPayload['service_type'] ?? $order->service_type,
            'pickup_address' => $orderPayload['pickup_address'] ?? $order->pickup_address,
            'destination_address' => $orderPayload['destination_address'] ?? $order->destination_address,
            'store_location' => data_get($orderPayload, 'service_payload.store_location'),
            'customer_name' => $parsedCustomer['name'] ?? null,
            'customer_phone' => $parsedCustomer['phone'] ?? null,
            'customer_address' => $parsedCustomer['address'] ?? null,
            'items' => $items,
            'notes' => $orderPayload['notes'] ?? null,
        ], 'manual_order', 'dashboard');
    }

    private function manualOrderPayloads(array $orderPayload): array
    {
        if (! $this->manualOrderContainsTart($orderPayload)) {
            return [$orderPayload];
        }

        $servicePayload = is_array($orderPayload['service_payload'] ?? null) ? $orderPayload['service_payload'] : [];

        return [[
            ...$orderPayload,
            'service_type' => 'delivery',
            'notes' => trim(implode("\n", array_filter([
                $orderPayload['notes'] ?? null,
                'Crew rule: kue tart membutuhkan helper. Customer tetap melihat 1 order.',
            ]))),
            'service_payload' => [
                ...$servicePayload,
                'crew_decision_hint' => 'kue_tart_helper',
            ],
        ]];
    }

    private function manualOrderContainsTart(array $orderPayload): bool
    {
        $items = collect($orderPayload['items'] ?? [])
            ->map(fn (mixed $item): string => is_array($item)
                ? implode(' ', array_filter([$item['name'] ?? null, $item['notes'] ?? null]))
                : (string) $item)
            ->implode(' ');
        $haystack = implode(' ', array_filter([
            $orderPayload['notes'] ?? null,
            $orderPayload['pickup_address'] ?? null,
            $orderPayload['destination_address'] ?? null,
            $items,
            json_encode($orderPayload['service_payload'] ?? []),
        ]));

        return preg_match('/\b(?:kue\s*)?tart\b/iu', $haystack) === 1;
    }

    private function manualPreviewCustomer(Request $request, array $payload): User
    {
        if (filled($payload['user_id'] ?? null)) {
            $customer = User::query()->with('branch')->findOrFail($payload['user_id']);
            abort_unless($customer->role === UserRole::Customer, 422, 'User yang dipilih bukan customer.');

            return $customer;
        }

        $contact = $this->manualOrderContactFromText((string) ($payload['raw_text'] ?? ''));
        $branchId = $this->manualOrderBranchId($request, $payload);
        $customer = new User([
            'name' => $contact['name'] ?: 'Customer Manual',
            'phone' => $contact['phone'],
            'address' => $contact['address'],
            'branch_id' => $branchId,
            'role' => UserRole::Customer,
            'is_active' => true,
        ]);

        if ($branchId) {
            $customer->setRelation('branch', Branch::query()->find($branchId));
        }

        return $customer;
    }

    private function manualOrderCustomer(Request $request, array $payload): User
    {
        if (filled($payload['user_id'] ?? null)) {
            $customer = User::query()->with('branch')->findOrFail($payload['user_id']);
            abort_unless($customer->role === UserRole::Customer, 422, 'User yang dipilih bukan customer.');

            return $customer;
        }

        $parsedCustomer = is_array($payload['parsed_customer'] ?? null) ? $payload['parsed_customer'] : [];
        $name = trim((string) ($payload['customer_name'] ?? $parsedCustomer['name'] ?? 'Customer Manual'));
        $phone = trim((string) ($payload['customer_phone'] ?? $parsedCustomer['phone'] ?? ''));
        $address = trim((string) ($payload['customer_address'] ?? $parsedCustomer['address'] ?? ''));
        $customer = $phone !== ''
            ? User::query()->where('role', UserRole::Customer->value)->where('phone', $phone)->first()
            : null;

        if ($customer) {
            $customer->forceFill([
                'name' => $name ?: $customer->name,
                'address' => $address ?: $customer->address,
                'branch_id' => $this->manualOrderBranchId($request, $payload) ?? $customer->branch_id,
            ])->save();

            return $customer->load('branch');
        }

        $branchId = $this->manualOrderBranchId($request, $payload);
        $emailSeed = $phone !== '' ? preg_replace('/\D+/', '', $phone) : Str::lower(Str::random(12));

        return User::query()->create([
            'username' => 'manual_'.Str::lower(Str::random(10)),
            'name' => $name ?: 'Customer Manual',
            'email' => 'manual_'.$emailSeed.'_'.Str::lower(Str::random(6)).'@manual.jojo.local',
            'phone' => $phone ?: null,
            'address' => $address ?: null,
            'branch_id' => $branchId,
            'role' => UserRole::Customer,
            'password' => Hash::make(Str::random(40)),
            'is_active' => true,
        ])->load('branch');
    }

    private function manualOrderBranchId(Request $request, array $payload): ?int
    {
        $explicit = data_get($payload, 'order_payload.branch_id') ?? ($payload['branch_id'] ?? null);
        if ($explicit) {
            return (int) $explicit;
        }

        $fromText = $this->manualOrderBranchIdFromText((string) ($payload['raw_text'] ?? data_get($payload, 'order_payload.notes', '')));
        if ($fromText) {
            return $fromText;
        }

        $branchIds = $this->operationalBranchScopeIds($request->user());

        return $branchIds !== null ? ($branchIds[0] ?? null) : null;
    }

    private function manualOrderBranchIdFromText(string $text): ?int
    {
        $hint = $this->manualOrderTextField($text, '(?:area|branch|cabang|kode\\s*pelanggan)');
        if (! $hint) {
            return null;
        }

        $needle = Str::lower($hint);

        return Branch::query()
            ->get()
            ->first(function (Branch $branch) use ($needle): bool {
                $values = array_filter([
                    $branch->name,
                    $branch->area,
                    trim(($branch->name ?? '').' '.($branch->area ?? '')),
                ]);

                foreach ($values as $value) {
                    $value = Str::lower((string) $value);
                    if ($value !== '' && (str_contains($needle, $value) || str_contains($value, $needle))) {
                        return true;
                    }
                }

                return false;
            })?->id;
    }

    public function priceSettings(): JsonResponse
    {
        return response()->json([
            'data' => PriceSetting::query()->with('branch')->latest()->get(),
        ]);
    }

    public function adminKeywordParsers(Request $request): JsonResponse
    {
        $this->authorizeZonePricing($request);

        return response()->json([
            'data' => $this->keywordParsersQuery()->get()->map(fn (KeywordParser $parser): array => $this->keywordParserPayload($parser)),
        ]);
    }

    public function storeKeywordParser(Request $request): JsonResponse
    {
        $this->authorizeZonePricing($request);

        $parser = KeywordParser::query()->create($this->validateKeywordParser($request));
        $this->recordAudit($request->user(), 'created_keyword_parser', $parser);

        return response()->json([
            'message' => 'Keyword parser created',
            'data' => $this->keywordParserPayload($parser),
        ], 201);
    }

    public function destroyKeywordParser(Request $request, KeywordParser $keywordParser): JsonResponse
    {
        $this->authorizeZonePricing($request);

        $this->recordAudit($request->user(), 'deleted_keyword_parser', $keywordParser);
        $keywordParser->delete();

        return response()->json(['message' => 'Keyword parser deleted']);
    }

    public function pricingKeywordRules(Request $request): JsonResponse
    {
        $this->authorizeZonePricing($request);

        return response()->json([
            'data' => $this->pricingKeywordRulesQuery()->get()->map(fn (PricingKeywordRule $rule): array => $this->pricingKeywordRulePayload($rule)),
        ]);
    }

    public function storePricingKeywordRule(Request $request): JsonResponse
    {
        $this->authorizeZonePricing($request);

        $rule = PricingKeywordRule::query()->create($this->validatePricingKeywordRule($request));
        $this->recordAudit($request->user(), 'created_pricing_keyword_rule', $rule);

        return response()->json([
            'message' => 'Pricing keyword rule created',
            'data' => $this->pricingKeywordRulePayload($rule),
        ], 201);
    }

    public function destroyPricingKeywordRule(Request $request, PricingKeywordRule $pricingKeywordRule): JsonResponse
    {
        $this->authorizeZonePricing($request);

        $this->recordAudit($request->user(), 'deleted_pricing_keyword_rule', $pricingKeywordRule);
        $pricingKeywordRule->delete();

        return response()->json(['message' => 'Pricing keyword rule deleted']);
    }

    public function ringPricingRules(Request $request): JsonResponse
    {
        $this->authorizeRingPricing($request);

        return response()->json([
            'data' => $this->ringPricingRulesQuery($request->user())
                ->get()
                ->map(fn (RingPricingRule $rule): array => $this->ringPricingRulePayload($rule)),
            'suggestions' => $this->ringPricingSuggestionsQuery($request->user())
                ->get()
                ->map(fn (RingPricingSuggestion $suggestion): array => $this->ringPricingSuggestionPayload($suggestion)),
        ]);
    }

    public function storeRingPricingRule(Request $request): JsonResponse
    {
        $this->authorizeRingPricing($request);
        $payload = $this->validateRingPricingRule($request);
        $this->assertPricingBranchScope($request->user(), $payload['branch_id'] ?? null);

        $rule = RingPricingRule::create([
            ...$payload,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        $this->recordAudit($request->user(), 'created_ring_pricing_rule', $rule);

        return response()->json([
            'message' => 'Ring pricing rule created',
            'data' => $this->ringPricingRulePayload($rule->fresh('branch')),
        ], 201);
    }

    public function importRingPricingGeojson(Request $request): JsonResponse
    {
        $this->authorizeRingPricing($request);

        $payload = $request->validate([
            'geojson_file' => ['required', 'file', 'max:15360'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'service_type' => ['nullable', 'string', 'max:80'],
            'polygon_match_point' => ['nullable', Rule::in(['destination_then_pickup', 'destination', 'pickup', 'either', 'both'])],
            'is_active' => ['sometimes', 'boolean'],
            'replace_existing' => ['sometimes', 'boolean'],
        ]);

        if (($payload['branch_id'] ?? null) !== null) {
            $this->assertPricingBranchScope($request->user(), (int) $payload['branch_id']);
        }

        /** @var UploadedFile $file */
        $file = $payload['geojson_file'];
        $decoded = json_decode((string) file_get_contents($file->getRealPath()), true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'geojson_file' => 'File GeoJSON tidak valid atau bukan JSON.',
            ]);
        }

        $serviceType = isset($payload['service_type']) && $payload['service_type'] !== ''
            ? app(RingPricingService::class)->normalizeServiceType((string) $payload['service_type'])
            : null;
        $defaultBranchId = isset($payload['branch_id']) ? (int) $payload['branch_id'] : null;
        $polygonMatchPoint = (string) ($payload['polygon_match_point'] ?? 'destination_then_pickup');
        $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;
        $actor = $request->user();

        $branches = Branch::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'branch_code', 'name', 'area', 'latitude', 'longitude']);

        $features = $this->geojsonFeatures($decoded);
        if ($features === []) {
            throw ValidationException::withMessages([
                'geojson_file' => 'GeoJSON harus berisi Feature polygon atau multipolygon.',
            ]);
        }

        if (($payload['replace_existing'] ?? false) && $defaultBranchId !== null) {
            RingPricingRule::query()
                ->where('source', 'geojson')
                ->where('branch_id', $defaultBranchId)
                ->when($serviceType !== null, fn (Builder $query) => $query->where('service_type', $serviceType), fn (Builder $query) => $query->whereNull('service_type'))
                ->delete();
        }

        $created = 0;
        $updated = 0;
        $skipped = [];
        $featureIndex = 0;

        foreach ($features as $feature) {
            $featureIndex++;
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $polygons = $this->geojsonFeaturePolygons($feature);

            if ($polygons === []) {
                $skipped[] = $this->geojsonSkipLabel($properties, $featureIndex, 'bukan polygon');
                continue;
            }

            $ring = $this->normalizeGeojsonRing($properties['ring'] ?? $properties['Ring'] ?? $properties['RING'] ?? null);
            if ($ring === null) {
                $skipped[] = $this->geojsonSkipLabel($properties, $featureIndex, 'ring tidak ditemukan');
                continue;
            }

            foreach ($polygons as $polygonIndex => $points) {
                if (count($points) < 3) {
                    $skipped[] = $this->geojsonSkipLabel($properties, $featureIndex, 'polygon kurang dari 3 titik');
                    continue;
                }

                $centroid = $this->geojsonCentroid($points);
                $branchId = $defaultBranchId ?? $this->nearestBranchIdForGeojson($branches, $centroid);

                if ($branchId === null) {
                    $skipped[] = $this->geojsonSkipLabel($properties, $featureIndex, 'cabang tidak ditemukan');
                    continue;
                }

                try {
                    $this->assertPricingBranchScope($actor, $branchId);
                } catch (\Throwable) {
                    $skipped[] = $this->geojsonSkipLabel($properties, $featureIndex, 'di luar scope cabang user');
                    continue;
                }

                $name = $this->geojsonRuleName($properties, $ring, $featureIndex, $polygonIndex, count($polygons));
                $rule = RingPricingRule::query()
                    ->where('source', 'geojson')
                    ->where('branch_id', $branchId)
                    ->where('service_type', $serviceType)
                    ->where('name', $name)
                    ->first();

                $data = [
                    'branch_id' => $branchId,
                    'service_type' => $serviceType,
                    'name' => $name,
                    'area_mode' => 'polygon',
                    'pickup_area' => (string) ($properties['pickup_area'] ?? $properties['name'] ?? $name),
                    'destination_area' => (string) ($properties['destination_area'] ?? $properties['name'] ?? $name),
                    'pickup_aliases' => [],
                    'destination_aliases' => [],
                    'polygon_coordinates' => $points,
                    'polygon_match_point' => $polygonMatchPoint,
                    'match_type' => (string) ($properties['match_type'] ?? 'point'),
                    'pickup_ring' => $this->normalizeGeojsonRing($properties['pickup_ring'] ?? null),
                    'destination_ring' => $this->normalizeGeojsonRing($properties['destination_ring'] ?? null),
                    'ring' => $ring,
                    ...$this->geojsonPricingData($properties, $ring),
                    'is_bidirectional' => true,
                    'source' => 'geojson',
                    'is_active' => $isActive,
                    'updated_by' => $actor->id,
                ];

                if ($rule) {
                    $rule->update($data);
                    $updated++;
                } else {
                    RingPricingRule::query()->create([...$data, 'created_by' => $actor->id]);
                    $created++;
                }
            }
        }

        $this->recordAudit($actor, 'imported_ring_pricing_geojson', $actor, [
            'file' => $file->getClientOriginalName(),
            'created' => $created,
            'updated' => $updated,
            'skipped' => count($skipped),
            'branch_id' => $defaultBranchId,
        ]);

        return response()->json([
            'message' => sprintf('Import GeoJSON selesai: %d baru, %d update, %d skip.', $created, $updated, count($skipped)),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'data' => $this->ringPricingRulesQuery($actor)
                ->get()
                ->map(fn (RingPricingRule $rule): array => $this->ringPricingRulePayload($rule)),
        ]);
    }

    public function updateRingPricingRule(Request $request, RingPricingRule $ringPricingRule): JsonResponse
    {
        $this->authorizeRingPricing($request);

        $payload = $this->validateRingPricingRule($request);
        $this->assertPricingBranchScope($request->user(), $ringPricingRule->branch_id);
        $this->assertPricingBranchScope($request->user(), $payload['branch_id'] ?? null);
        $before = $ringPricingRule->only(array_keys($payload));
        $ringPricingRule->update([...$payload, 'updated_by' => $request->user()->id]);
        $this->recordAudit($request->user(), 'updated_ring_pricing_rule', $ringPricingRule, ['before' => $before, 'after' => $payload]);

        return response()->json([
            'message' => 'Ring pricing rule updated',
            'data' => $this->ringPricingRulePayload($ringPricingRule->fresh('branch')),
        ]);
    }

    public function toggleRingPricingRule(Request $request, RingPricingRule $ringPricingRule): JsonResponse
    {
        $this->authorizeRingPricing($request);
        $this->assertPricingBranchScope($request->user(), $ringPricingRule->branch_id);

        $payload = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $before = (bool) $ringPricingRule->is_active;
        $ringPricingRule->update([
            'is_active' => (bool) $payload['is_active'],
            'updated_by' => $request->user()->id,
        ]);
        $this->recordAudit($request->user(), 'toggled_ring_pricing_rule', $ringPricingRule, [
            'before' => $before,
            'after' => (bool) $ringPricingRule->is_active,
        ]);

        return response()->json([
            'message' => $ringPricingRule->is_active ? 'Ring pricing rule activated' : 'Ring pricing rule deactivated',
            'data' => $this->ringPricingRulePayload($ringPricingRule->fresh('branch')),
        ]);
    }

    public function destroyRingPricingRule(Request $request, RingPricingRule $ringPricingRule): JsonResponse
    {
        $this->authorizeRingPricing($request);
        $this->assertPricingBranchScope($request->user(), $ringPricingRule->branch_id);

        $this->recordAudit($request->user(), 'deleted_ring_pricing_rule', $ringPricingRule);
        $ringPricingRule->delete();

        return response()->json(['message' => 'Ring pricing rule deleted']);
    }

    public function approveRingPricingSuggestion(Request $request, RingPricingSuggestion $suggestion): JsonResponse
    {
        $this->authorizeRingPricing($request);
        $this->assertPricingBranchScope($request->user(), $suggestion->branch_id);

        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'ring' => ['nullable', 'string', 'max:40'],
            'price' => ['nullable', 'integer', 'min:0'],
            'is_bidirectional' => ['sometimes', 'boolean'],
        ]);

        $rule = RingPricingRule::create([
            'branch_id' => $suggestion->branch_id,
            'service_type' => $suggestion->service_type,
            'name' => $payload['name'] ?? sprintf('%s ke %s', $suggestion->pickup_area, $suggestion->destination_area),
            'pickup_area' => $suggestion->pickup_area,
            'destination_area' => $suggestion->destination_area,
            'ring' => $payload['ring'] ?? $suggestion->ring ?? 'ring_1',
            'price' => $payload['price'] ?? $suggestion->suggested_price,
            'is_bidirectional' => $payload['is_bidirectional'] ?? true,
            'source' => 'learned',
            'is_active' => true,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $suggestion->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);
        $this->recordAudit($request->user(), 'approved_ring_pricing_suggestion', $rule, ['suggestion_id' => $suggestion->id]);

        return response()->json([
            'message' => 'Suggestion approved as ring pricing rule',
            'data' => $this->ringPricingRulePayload($rule->fresh('branch')),
        ]);
    }

    public function rejectRingPricingSuggestion(Request $request, RingPricingSuggestion $suggestion): JsonResponse
    {
        $this->authorizeRingPricing($request);
        $this->assertPricingBranchScope($request->user(), $suggestion->branch_id);
        $suggestion->update(['status' => 'rejected']);
        $this->recordAudit($request->user(), 'rejected_ring_pricing_suggestion', $suggestion);

        return response()->json(['message' => 'Suggestion rejected']);
    }

    public function zonePricingRules(Request $request): JsonResponse
    {
        $this->authorizeZonePricing($request);

        return response()->json([
            'data' => $this->zonePricingRulesQuery($request->user())
                ->get()
                ->map(fn (ZonePricingRule $rule): array => $this->zonePricingRulePayload($rule)),
        ]);
    }

    public function storeZonePricingRule(Request $request): JsonResponse
    {
        $this->authorizeZonePricing($request);
        $payload = $this->validateZonePricingRule($request);
        $this->assertPricingBranchScope($request->user(), $payload['branch_id'] ?? null, $payload['geofence_area_id'] ?? null);

        $rule = ZonePricingRule::query()->create($payload);
        $this->recordAudit($request->user(), 'created_zone_pricing_rule', $rule);

        return response()->json([
            'message' => 'Zone pricing rule created',
            'data' => $this->zonePricingRulePayload($rule->fresh(['branch', 'geofenceArea.branch'])),
        ], 201);
    }

    public function updateZonePricingRule(Request $request, ZonePricingRule $zonePricingRule): JsonResponse
    {
        $this->authorizeZonePricing($request);

        $payload = $this->validateZonePricingRule($request);
        $this->assertPricingBranchScope($request->user(), $zonePricingRule->branch_id, $zonePricingRule->geofence_area_id);
        $this->assertPricingBranchScope($request->user(), $payload['branch_id'] ?? null, $payload['geofence_area_id'] ?? null);
        $before = $zonePricingRule->only(array_keys($payload));
        $zonePricingRule->update($payload);
        $this->recordAudit($request->user(), 'updated_zone_pricing_rule', $zonePricingRule, ['before' => $before, 'after' => $payload]);

        return response()->json([
            'message' => 'Zone pricing rule updated',
            'data' => $this->zonePricingRulePayload($zonePricingRule->fresh(['branch', 'geofenceArea.branch'])),
        ]);
    }

    public function destroyZonePricingRule(Request $request, ZonePricingRule $zonePricingRule): JsonResponse
    {
        $this->authorizeZonePricing($request);
        $this->assertPricingBranchScope($request->user(), $zonePricingRule->branch_id, $zonePricingRule->geofence_area_id);

        $this->recordAudit($request->user(), 'deleted_zone_pricing_rule', $zonePricingRule);
        $zonePricingRule->delete();

        return response()->json(['message' => 'Zone pricing rule deleted']);
    }

    public function testZonePricing(
        Request $request,
        BranchDetectionService $branches,
        PricingService $pricing,
        ZonePricingService $zones,
    ): JsonResponse {
        $this->authorizeZonePricing($request);

        $payload = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'service_type' => ['required', 'string', 'max:80'],
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            'stops' => ['nullable', 'integer', 'min:1', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $serviceType = app(RingPricingService::class)->normalizeServiceType((string) $payload['service_type']);
        if (! $this->canManageGlobalPricing($request->user())) {
            $branchIds = $this->staffBranchScopeIds($request->user()) ?? [];
            $fallbackBranchId = isset($payload['branch_id']) && $payload['branch_id'] ? (int) $payload['branch_id'] : ($branchIds[0] ?? null);
            $this->assertPricingBranchScope($request->user(), $fallbackBranchId);
            $payload['branch_id'] = $fallbackBranchId;
        }
        $pickup = $zones->testPoint((float) $payload['pickup_lat'], (float) $payload['pickup_lng']);
        $destination = $zones->testPoint((float) $payload['destination_lat'], (float) $payload['destination_lng']);
        $detectedBranchId = $branches->detect((float) $payload['destination_lat'], (float) $payload['destination_lng'])['branch']?->id
            ?? $branches->detect((float) $payload['pickup_lat'], (float) $payload['pickup_lng'])['branch']?->id
            ?? (($payload['branch_id'] ?? null) ? (int) $payload['branch_id'] : null);
        if (! $this->canManageGlobalPricing($request->user())) {
            $branchIds = $this->staffBranchScopeIds($request->user()) ?? [];
            $detectedBranchId = in_array((int) $detectedBranchId, $branchIds, true)
                ? $detectedBranchId
                : ($branchIds[0] ?? null);
        }

        $quote = $pricing->calculate([
            ...$payload,
            'service_type' => $serviceType,
            'branch_id' => $detectedBranchId,
            'pickup_address' => 'Tester pickup',
            'destination_address' => 'Tester tujuan',
            'destination_text' => $payload['notes'] ?? 'Tester tujuan',
        ]);

        return response()->json([
            'data' => [
                'pickup' => $this->zonePointPayload($pickup),
                'destination' => $this->zonePointPayload($destination),
                'branch_id' => $detectedBranchId,
                'branch' => $detectedBranchId ? Branch::query()->find($detectedBranchId)?->only(['id', 'name', 'area']) : null,
                'quote' => $quote,
            ],
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
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager], true), 403);
        $this->recordAudit($request->user(), 'deleted_price_policy', $priceSetting);
        $priceSetting->delete();

        return response()->json(['message' => 'Price setting deleted']);
    }

    public function branches(): JsonResponse
    {
        return response()->json([
            'data' => Branch::query()
                ->with('geofenceAreas:id,branch_id,name,center_latitude,center_longitude,radius_meters,is_active')
                ->withCount('geofenceAreas')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeBranch(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM], true), 403);

        $payload = $request->validate([
            'branch_code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                Rule::unique('branches', 'branch_code'),
            ],
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

        $payload['branch_code'] = strtoupper(trim((string) $payload['branch_code']));
        $payload['radius_km'] ??= 5;

        $branch = Branch::query()->create($payload);
        $this->recordAudit($request->user(), 'created_branch', $branch, ['area' => $branch->area]);

        return response()->json([
            'message' => 'Branch created',
            'data' => $branch->load(['geofenceAreas'])->loadCount('geofenceAreas'),
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

    public function chats(Request $request, SLAService $slaService): JsonResponse
    {
        $slaService->enforceUnansweredOperatorChats((clone $this->chatsQuery($request->user())));

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
                'rows' => $reports->monthlyDepositRows($month, $year, $request->user())->values(),
            ],
        ]);
    }

    public function exportDriverDepositReport(Request $request, DriverReportService $reports): StreamedResponse
    {
        [$month, $year] = $this->reportPeriod($request);
        $period = now()->setDate($year, $month, 1)->startOfMonth();
        $rows = $reports->monthlyDepositRows($month, $year, $request->user());
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
            'multi_crew_auto_cancel_enabled' => ['sometimes', 'boolean'],
            'multi_crew_auto_cancel_minutes' => ['sometimes', 'integer', 'min:1', 'max:180'],
            'multi_crew_auto_cancel_message' => ['nullable', 'string', 'max:500'],
            'driver_daily_priority_enabled' => ['sometimes', 'boolean'],
            'driver_daily_priority_hold_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'driver_daily_priority_windows' => ['sometimes', 'array'],
            'driver_daily_priority_windows.*.start' => ['required_with:driver_daily_priority_windows', 'date_format:H:i'],
            'driver_daily_priority_windows.*.end' => ['required_with:driver_daily_priority_windows', 'date_format:H:i'],
            'night_tariff_enabled' => ['sometimes', 'boolean'],
            'night_tariff_rules' => ['sometimes', 'array'],
            'night_tariff_rules.*.area' => ['nullable', 'string', 'max:50'],
            'night_tariff_rules.*.start' => ['required_with:night_tariff_rules', 'date_format:H:i'],
            'night_tariff_rules.*.end' => ['required_with:night_tariff_rules', 'date_format:H:i'],
            'night_tariff_rules.*.percent' => ['required_with:night_tariff_rules', 'integer', 'min:0', 'max:300'],
            'zone_pricing_enabled' => ['sometimes', 'boolean'],
            'assign_driver_allowed_roles' => ['sometimes', 'array'],
            'assign_driver_allowed_roles.*' => ['string', Rule::in(['manager', 'spv', 'operator', 'eksekutor'])],
            'edit_tarif_allowed_roles' => ['sometimes', 'array'],
            'edit_tarif_allowed_roles.*' => ['string', Rule::in(RolePermissionSettingService::CONFIGURABLE_EDIT_TARIF_ROLES)],
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
        foreach (['order_close_enabled', 'order_close_start', 'order_close_end', 'order_close_message', 'multi_crew_auto_cancel_enabled', 'multi_crew_auto_cancel_minutes', 'multi_crew_auto_cancel_message', 'driver_daily_priority_enabled', 'driver_daily_priority_hold_minutes', 'night_tariff_enabled', 'zone_pricing_enabled'] as $key) {
            if (array_key_exists($key, $payload)) {
                $settings->set($key, $payload[$key]);
            }
        }
        if (array_key_exists('driver_daily_priority_windows', $payload)) {
            $settings->set('driver_daily_priority_windows', json_encode($this->normalizeDailyPriorityWindows($payload['driver_daily_priority_windows'])));
        }
        if (array_key_exists('night_tariff_rules', $payload)) {
            $settings->set('night_tariff_rules', json_encode(array_values($payload['night_tariff_rules'])));
        }
        if (array_key_exists('assign_driver_allowed_roles', $payload)) {
            $settings->set('assign_driver_allowed_roles', json_encode($this->normalizeAssignDriverRoles($payload['assign_driver_allowed_roles'])));
        }
        if (array_key_exists('edit_tarif_allowed_roles', $payload)) {
            $settings->set(
                RolePermissionSettingService::EDIT_TARIF_ALLOWED_ROLES_KEY,
                json_encode(app(RolePermissionSettingService::class)->normalizeEditTarifRoles($payload['edit_tarif_allowed_roles'])),
                true,
                ['type' => 'json'],
            );
        }

        return response()->json([
            'message' => 'System settings updated',
            'data' => $this->systemSettingsPayload($settings),
        ]);
    }

    private function validatePriceSetting(Request $request): array
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'min_km' => ['required', 'numeric', 'min:0'],
            'max_km' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'integer', 'min:0'],
            'is_formula' => ['required', 'boolean'],
            'per_km_rate' => ['nullable', 'integer', 'min:0'],
            'subtract_value' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $payload['is_active'] = (bool) ($payload['is_active'] ?? true);
        $payload['is_formula'] = (bool) ($payload['is_formula'] ?? false);
        $payload['branch_id'] = $payload['branch_id'] ?? null;
        $payload['max_km'] = $payload['max_km'] ?? null;

        if ($payload['is_formula']) {
            $payload['price'] = null;
            $payload['per_km_rate'] = (int) ($payload['per_km_rate'] ?? 0);
            $payload['subtract_value'] = (int) ($payload['subtract_value'] ?? 0);
        } else {
            $payload['price'] = (int) ($payload['price'] ?? 0);
            $payload['per_km_rate'] = null;
            $payload['subtract_value'] = 0;
        }

        return $payload;
    }

    private function validateKeywordParser(Request $request): array
    {
        $payload = $request->validate([
            'keyword' => ['required', 'string', 'max:255'],
            'service_type' => ['required', 'string', 'max:40'],
            'response_template' => ['required', 'string', 'max:4000'],
            'form_schema' => ['nullable', 'array'],
            'parser_type' => ['required', Rule::in(['simple', 'advanced'])],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
        ]);

        $payload['keyword'] = app(KeywordParserService::class)->normalizeKeywordList($payload['keyword']);
        $payload['service_type'] = strtoupper((string) $payload['service_type']);
        $payload['is_active'] = $payload['is_active'] ?? true;
        $payload['priority'] = (int) ($payload['priority'] ?? 0);
        $payload['form_schema'] = $payload['form_schema'] ?? ['fields' => []];

        return $payload;
    }

    private function validatePricingKeywordRule(Request $request): array
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'keywords' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:0'],
            'service_scopes' => ['nullable', 'array'],
            'service_scopes.*' => ['string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $payload['keywords'] = app(PricingKeywordRuleService::class)->normalizeKeywordList($payload['keywords']);
        $payload['service_scopes'] = app(PricingKeywordRuleService::class)->normalizeScopes($payload['service_scopes'] ?? ['all']);
        $payload['is_active'] = $payload['is_active'] ?? true;
        $payload['priority'] = (int) ($payload['priority'] ?? 0);

        return $payload;
    }

    private function validateRingPricingRule(Request $request): array
    {
        $payload = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'service_type' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'area_mode' => ['nullable', Rule::in(['text', 'polygon'])],
            'pickup_area' => ['nullable', 'string', 'max:255'],
            'destination_area' => ['nullable', 'string', 'max:255'],
            'pickup_aliases' => ['nullable', 'array'],
            'pickup_aliases.*' => ['string', 'max:255'],
            'destination_aliases' => ['nullable', 'array'],
            'destination_aliases.*' => ['string', 'max:255'],
            'polygon_coordinates' => ['nullable', 'array'],
            'polygon_match_point' => ['nullable', Rule::in(['destination_then_pickup', 'destination', 'pickup', 'either', 'both'])],
            'match_type' => ['nullable', Rule::in(['point', 'cross'])],
            'pickup_ring' => ['nullable', 'string', 'max:40'],
            'destination_ring' => ['nullable', 'string', 'max:40'],
            'ring' => ['required', 'string', 'max:40'],
            'min_km' => ['nullable', 'numeric', 'min:0'],
            'max_km' => ['nullable', 'numeric', 'min:0'],
            'pricing_mode' => ['nullable', Rule::in(['flat', 'formula'])],
            'price' => ['required', 'integer', 'min:0'],
            'per_km_rate' => ['nullable', 'integer', 'min:0'],
            'subtract_value' => ['nullable', 'integer', 'min:0'],
            'service_fee' => ['nullable', 'integer', 'min:0'],
            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'is_bidirectional' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $payload['area_mode'] = $payload['area_mode'] ?? 'text';
        if ($payload['area_mode'] === 'polygon') {
            if (blank($payload['branch_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Master Ring Polygon wajib memilih cabang agar tidak bocor antar cabang.',
                ]);
            }

            $payload['pickup_area'] = filled($payload['pickup_area'] ?? null) ? $payload['pickup_area'] : $payload['name'];
            $payload['destination_area'] = filled($payload['destination_area'] ?? null) ? $payload['destination_area'] : $payload['name'];
            $payload['polygon_coordinates'] = $this->normalizeRingPolygonCoordinates($payload['polygon_coordinates'] ?? []);

            if (count($payload['polygon_coordinates']) < 3) {
                throw ValidationException::withMessages([
                    'polygon_coordinates' => 'Polygon Master Ring minimal memiliki 3 titik.',
                ]);
            }
        } else {
            if (blank($payload['pickup_area'] ?? null) || blank($payload['destination_area'] ?? null)) {
                throw ValidationException::withMessages([
                    'pickup_area' => 'Asal area dan tujuan area wajib diisi untuk mode alias teks.',
                ]);
            }

            $payload['polygon_coordinates'] = null;
        }

        $payload['service_type'] = isset($payload['service_type']) && $payload['service_type'] !== ''
            ? app(RingPricingService::class)->normalizeServiceType((string) $payload['service_type'])
            : null;
        $payload['pickup_aliases'] = array_values(array_filter($payload['pickup_aliases'] ?? []));
        $payload['destination_aliases'] = array_values(array_filter($payload['destination_aliases'] ?? []));
        $payload['polygon_match_point'] = $payload['polygon_match_point'] ?? 'destination_then_pickup';
        $payload['match_type'] = $payload['match_type'] ?? 'point';
        $payload['pickup_ring'] = filled($payload['pickup_ring'] ?? null) ? $payload['pickup_ring'] : null;
        $payload['destination_ring'] = filled($payload['destination_ring'] ?? null) ? $payload['destination_ring'] : null;
        $payload['min_km'] = (float) ($payload['min_km'] ?? $this->defaultRingMinKm((string) $payload['ring']));
        $payload['max_km'] = array_key_exists('max_km', $payload) && $payload['max_km'] !== null && $payload['max_km'] !== ''
            ? (float) $payload['max_km']
            : $this->defaultRingMaxKm((string) $payload['ring']);
        $payload['pricing_mode'] = $payload['pricing_mode'] ?? (((int) ($payload['per_km_rate'] ?? 0) > 0) ? 'formula' : 'flat');
        if ($payload['pricing_mode'] === 'formula') {
            $payload['per_km_rate'] = (int) ($payload['per_km_rate'] ?? 0);
            $payload['subtract_value'] = (int) ($payload['subtract_value'] ?? 0);
            $payload['price'] = (int) ($payload['price'] ?? 0);
        } else {
            $payload['per_km_rate'] = null;
            $payload['subtract_value'] = 0;
            $payload['price'] = (int) ($payload['price'] ?? 0);
        }
        $payload['service_fee'] = (int) ($payload['service_fee'] ?? $this->defaultRingServiceFee((string) $payload['ring']));
        $payload['priority'] = (int) ($payload['priority'] ?? $this->defaultRingPriority((string) $payload['ring']));
        $payload['is_bidirectional'] = $payload['is_bidirectional'] ?? true;
        $payload['is_active'] = $payload['is_active'] ?? true;

        return $payload;
    }

    private function defaultRingMinKm(string $ring): float
    {
        return match ($ring) {
            'ring_2' => 4.1,
            'ring_3' => 9.1,
            default => 0.0,
        };
    }

    private function defaultRingMaxKm(string $ring): ?float
    {
        return match ($ring) {
            'ring_1' => 4.0,
            'ring_2' => 9.0,
            default => null,
        };
    }

    private function defaultRingServiceFee(string $ring): int
    {
        return in_array($ring, ['ring_1', 'ring_2'], true) ? 1000 : 0;
    }

    private function defaultRingPriority(string $ring): int
    {
        return match ($ring) {
            'ring_1' => 300,
            'ring_2' => 200,
            'ring_3' => 100,
            default => 0,
        };
    }

    private function normalizeRingPolygonCoordinates(mixed $value): array
    {
        $points = is_array($value) ? $value : [];
        $extracted = $this->extractRingPolygonPoints($points);

        $normalized = collect($extracted['points'])
            ->map(function (mixed $point): ?array {
                if (! is_array($point)) {
                    return null;
                }

                $lat = $point['lat'] ?? $point['latitude'] ?? null;
                $lng = $point['lng'] ?? $point['longitude'] ?? null;

                if ((! is_numeric($lat) || ! is_numeric($lng)) && isset($point[0], $point[1])) {
                    $lng = $point[0];
                    $lat = $point[1];
                }

                if (! is_numeric($lat) || ! is_numeric($lng)) {
                    return null;
                }

                return [
                    'lat' => round((float) $lat, 8),
                    'lng' => round((float) $lng, 8),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return ($extracted['geometry'] ?? null) === 'line'
            ? $this->ringPolygonConvexHull($normalized)
            : $normalized;
    }

    private function extractRingPolygonPoints(array $value): array
    {
        if (($value['type'] ?? null) === 'FeatureCollection') {
            $linePoints = [];

            foreach ($value['features'] ?? [] as $feature) {
                if (is_array($feature)) {
                    $extracted = $this->extractRingPolygonPoints($feature);

                    if (($extracted['geometry'] ?? null) === 'polygon' && $extracted['points'] !== []) {
                        return $extracted;
                    }

                    if (($extracted['geometry'] ?? null) === 'line') {
                        $linePoints = [...$linePoints, ...$extracted['points']];
                    }
                }
            }

            return ['geometry' => $linePoints !== [] ? 'line' : null, 'points' => $linePoints];
        }

        if (($value['type'] ?? null) === 'Feature') {
            return is_array($value['geometry'] ?? null) ? $this->extractRingPolygonPoints($value['geometry']) : ['geometry' => null, 'points' => []];
        }

        if (($value['type'] ?? null) === 'GeometryCollection') {
            $linePoints = [];

            foreach ($value['geometries'] ?? [] as $geometry) {
                if (is_array($geometry)) {
                    $extracted = $this->extractRingPolygonPoints($geometry);

                    if (($extracted['geometry'] ?? null) === 'polygon' && $extracted['points'] !== []) {
                        return $extracted;
                    }

                    if (($extracted['geometry'] ?? null) === 'line') {
                        $linePoints = [...$linePoints, ...$extracted['points']];
                    }
                }
            }

            return ['geometry' => $linePoints !== [] ? 'line' : null, 'points' => $linePoints];
        }

        if (($value['type'] ?? null) === 'Polygon') {
            return ['geometry' => 'polygon', 'points' => is_array($value['coordinates'][0] ?? null) ? $value['coordinates'][0] : []];
        }

        if (($value['type'] ?? null) === 'MultiPolygon') {
            return ['geometry' => 'polygon', 'points' => is_array($value['coordinates'][0][0] ?? null) ? $value['coordinates'][0][0] : []];
        }

        if (($value['type'] ?? null) === 'LineString') {
            return ['geometry' => 'line', 'points' => is_array($value['coordinates'] ?? null) ? $value['coordinates'] : []];
        }

        if (($value['type'] ?? null) === 'MultiLineString') {
            return ['geometry' => 'line', 'points' => collect($value['coordinates'] ?? [])->filter(fn (mixed $line): bool => is_array($line))->flatten(1)->all()];
        }

        return ['geometry' => 'polygon', 'points' => $value];
    }

    private function ringPolygonConvexHull(array $points): array
    {
        $points = collect($points)
            ->unique(fn (array $point): string => $point['lng'].','.$point['lat'])
            ->sortBy([['lng', 'asc'], ['lat', 'asc']])
            ->values()
            ->all();

        if (count($points) <= 3) {
            return $points;
        }

        $cross = fn (array $origin, array $a, array $b): float => (($a['lng'] - $origin['lng']) * ($b['lat'] - $origin['lat']))
            - (($a['lat'] - $origin['lat']) * ($b['lng'] - $origin['lng']));

        $lower = [];
        foreach ($points as $point) {
            while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $point) <= 0) {
                array_pop($lower);
            }
            $lower[] = $point;
        }

        $upper = [];
        foreach (array_reverse($points) as $point) {
            while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $point) <= 0) {
                array_pop($upper);
            }
            $upper[] = $point;
        }

        array_pop($lower);
        array_pop($upper);

        return array_values([...$lower, ...$upper]);
    }

    private function authorizeRingPricing(Request $request): void
    {
        abort_unless($request->user()->hasPermission('edit_tarif'), 403);
    }

    private function validateZonePricingRule(Request $request): array
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'geofence_area_id' => ['required', 'exists:geofence_areas,id'],
            'service_type' => ['nullable', 'string', 'max:80'],
            'match_point' => ['required', Rule::in(['destination', 'pickup', 'either', 'both'])],
            'price_mode' => ['required', Rule::in(['fixed', 'extra', 'percent'])],
            'amount' => ['nullable', 'integer', 'min:0'],
            'percent' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'min_km' => ['nullable', 'numeric', 'min:0'],
            'max_km' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $payload['service_type'] = isset($payload['service_type']) && $payload['service_type'] !== ''
            ? app(RingPricingService::class)->normalizeServiceType((string) $payload['service_type'])
            : null;
        $payload['amount'] = in_array($payload['price_mode'], ['fixed', 'extra'], true) ? (int) ($payload['amount'] ?? 0) : null;
        $payload['percent'] = $payload['price_mode'] === 'percent' ? (float) ($payload['percent'] ?? 0) : null;
        $payload['is_active'] = $payload['is_active'] ?? true;
        $payload['priority'] = (int) ($payload['priority'] ?? 0);

        $geofence = GeofenceArea::query()->find($payload['geofence_area_id']);
        if (($payload['branch_id'] ?? null) && $geofence && (int) $geofence->branch_id !== (int) $payload['branch_id']) {
            throw ValidationException::withMessages([
                'geofence_area_id' => 'Zona/geofence harus berada di cabang yang sama dengan rule pricing.',
            ]);
        }

        if (($payload['min_km'] ?? null) !== null && ($payload['max_km'] ?? null) !== null && (float) $payload['max_km'] < (float) $payload['min_km']) {
            throw ValidationException::withMessages([
                'max_km' => 'Max KM tidak boleh lebih kecil dari Min KM.',
            ]);
        }

        return $payload;
    }

    private function authorizeZonePricing(Request $request): void
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV], true)
            || $request->user()->hasPermission('edit_tarif'), 403);
    }

    private function ringPricingRulesQuery(User $actor): Builder
    {
        $query = RingPricingRule::query()
            ->with('branch')
            ->latest();

        if (! $this->canManageGlobalPricing($actor)) {
            $query->whereIn('branch_id', $this->staffBranchScopeIds($actor) ?? []);
        }

        return $query;
    }

    private function zonePricingRulesQuery(?User $actor = null): Builder
    {
        if (! Schema::hasTable('zone_pricing_rules')) {
            return ZonePricingRule::query()->whereRaw('1 = 0');
        }

        $query = ZonePricingRule::query()
            ->with(['branch', 'geofenceArea.branch'])
            ->orderByDesc('priority')
            ->latest();

        if ($actor !== null && ! $this->canManageGlobalPricing($actor)) {
            $query->whereIn('branch_id', $this->staffBranchScopeIds($actor) ?? []);
        }

        return $query;
    }

    private function keywordParsersQuery(): Builder
    {
        if (! Schema::hasTable('keyword_parsers')) {
            return KeywordParser::query()->whereRaw('1 = 0');
        }

        return KeywordParser::query()->orderByDesc('priority')->latest();
    }

    private function pricingKeywordRulesQuery(): Builder
    {
        if (! Schema::hasTable('pricing_keyword_rules')) {
            return PricingKeywordRule::query()->whereRaw('1 = 0');
        }

        return PricingKeywordRule::query()->orderByDesc('priority')->latest();
    }

    private function ringPricingSuggestionsQuery(User $actor): Builder
    {
        $query = RingPricingSuggestion::query()
            ->with(['branch', 'lastOrder', 'editor'])
            ->where('status', 'pending')
            ->orderByDesc('occurrence_count')
            ->latest();

        if (! $actor->hasPermission('edit_tarif')) {
            $query->whereRaw('1 = 0');
        }

        if (! $this->canManageGlobalPricing($actor)) {
            $query->whereIn('branch_id', $this->staffBranchScopeIds($actor) ?? []);
        }

        return $query;
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
        $query = User::query()->with(['branch', 'branchScopes', 'driver', 'currentLocation.branch', 'latestLocationLog.branch']);
        $branchIds = $this->staffBranchScopeIds($actor);

        return match ($actor->role) {
            UserRole::Admin, UserRole::GM => $query,
            UserRole::HRD, UserRole::Manager => $this->whereInStaffBranchScope($query->whereIn('role', [UserRole::Manager->value, UserRole::SPV->value, UserRole::Operator->value, UserRole::Eksekutor->value, UserRole::Driver->value]), $branchIds, true),
            UserRole::SPV, UserRole::Eksekutor => $this->whereInStaffBranchScope($query->whereNotIn('role', [UserRole::Admin->value, UserRole::GM->value, UserRole::HRD->value, UserRole::Manager->value]), $branchIds, true),
            UserRole::Operator => $query->whereNotIn('role', [UserRole::Admin->value, UserRole::GM->value, UserRole::HRD->value, UserRole::Manager->value]),
            default => $query->whereKey($actor->id),
        };
    }

    private function ordersQuery(User $actor): Builder
    {
        $query = Order::query()
            ->with(['branch', 'user.branch', 'driver.user.branch', 'operHandleRequests.driver.user']);

        if (in_array($actor->role, [UserRole::WebAdmin, UserRole::CmsEditor], true)) {
            return $query->whereRaw('1 = 0');
        }

        $branchIds = $this->operationalBranchScopeIds($actor);
        if ($branchIds !== null) {
            $query->where(function (Builder $query) use ($branchIds): void {
                $query->whereIn('branch_id', $branchIds)
                    ->orWhereHas('user', fn (Builder $query) => $query->whereIn('branch_id', $branchIds))
                    ->orWhereHas('driver.user', fn (Builder $query) => $query->whereIn('branch_id', $branchIds));
            });
        }

        return $query;
    }

    private function operHandlesQuery(User $actor): Builder
    {
        $query = OperHandleRequest::query()
            ->with(['order.branch', 'order.user.branch', 'order.driver.user.branch', 'driver.user.branch', 'requester']);

        if (in_array($actor->role, [UserRole::WebAdmin, UserRole::CmsEditor], true)) {
            return $query->whereRaw('1 = 0');
        }

        $branchIds = $this->operationalBranchScopeIds($actor);
        if ($branchIds !== null) {
            $query->whereHas('order', function (Builder $query) use ($branchIds): void {
                $query->whereIn('branch_id', $branchIds)
                    ->orWhereHas('user', fn (Builder $query) => $query->whereIn('branch_id', $branchIds))
                    ->orWhereHas('driver.user', fn (Builder $query) => $query->whereIn('branch_id', $branchIds));
            });
        }

        return $query;
    }

    private function locationLogsQuery(User $actor): Builder
    {
        $query = LocationLog::query()->with(['user', 'branch', 'geofenceArea'])->latest();

        if (in_array($actor->role, [UserRole::WebAdmin, UserRole::CmsEditor], true)) {
            return $query->whereRaw('1 = 0');
        }

        $branchIds = $this->operationalBranchScopeIds($actor);
        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }

        return $query;
    }

    private function chatsQuery(User $actor): Builder
    {
        $query = ChatConversation::query()->with(['customer', 'driver', 'operator', 'branch', 'order', 'latestMessage'])->latest();

        if (in_array($actor->role, [UserRole::WebAdmin, UserRole::CmsEditor], true)) {
            return $query->whereRaw('1 = 0');
        }

        $branchIds = $this->operationalBranchScopeIds($actor);
        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }

        return $query;
    }

    private function auditLogsQuery(User $actor): Builder
    {
        $query = AuditLog::query()->with('user')->latest();

        if (in_array($actor->role, [UserRole::WebAdmin, UserRole::CmsEditor], true)) {
            return $query->whereRaw('1 = 0');
        }

        $branchIds = $this->operationalBranchScopeIds($actor);
        if ($branchIds !== null) {
            $query->whereHas('user', fn (Builder $query) => $query->whereIn('branch_id', $branchIds));
        }

        return $query;
    }

    private function permissionsFor(User $user): array
    {
        $permissionNames = $user->permissions();

        $permissions = [
            'backend_access' => in_array($user->role, [UserRole::Admin, UserRole::GM], true),
            'names' => $permissionNames,
            'assignable_roles' => collect($user->role->assignableRoles())->map->value->all(),
            'can_manage_policy' => $user->hasPermission('edit_tarif'),
            'can_manage_ring_pricing' => $user->hasPermission('edit_tarif'),
            'can_manage_users' => $user->hasPermission('create_user'),
            'can_suspend_drivers' => $user->hasPermission('suspend_driver'),
            'can_unsuspend_drivers' => $user->hasPermission('unsuspend_driver'),
            'can_manage_driver_auth' => in_array($user->role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager], true)
                && $user->hasPermission('suspend_driver'),
            'can_manage_all_branches' => app(BranchAccessSettingService::class)->roleHasGlobalBranchAccess($user->role),
            'can_manage_system_settings' => $user->hasPermission('manage_system_settings'),
            'can_manage_cms' => in_array($user->role, [UserRole::Admin, UserRole::GM, UserRole::WebAdmin, UserRole::CmsEditor], true),
            'can_edit_order_price' => $user->hasPermission('edit_tarif'),
            'can_create_manual_order' => $user->hasPermission('manual_order'),
            'can_assign_driver' => $this->canAssignDriver($user),
            'can_view_report' => $user->hasPermission('view_report'),
            'can_export_report' => $user->hasPermission('export_report'),
            'can_monitor_live_order' => $user->hasPermission('monitor_live_order'),
            'can_monitor_live_chat' => $user->hasPermission('monitor_live_chat'),
            'can_use_internal_chat' => $user->hasPermission('internal_chat'),
            'can_use_internal_notes' => $user->hasPermission('internal_chat'),
            'can_approve_cancel_order' => $user->hasPermission('approve_cancel_order'),
            'can_reject_cancel_order' => $user->hasPermission('reject_cancel_order'),
            'can_approve_oper_handle' => in_array($user->role, [UserRole::Admin, UserRole::GM, UserRole::SPV, UserRole::Operator, UserRole::Eksekutor], true),
        ];

        return app(AdminRoleMenuOverrideService::class)->applyToPermissions($user, $permissions);
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

        if (! $actor->role->canManageRole($target->role)) {
            return false;
        }

        if ($this->canManageGlobalUsers($actor)) {
            return true;
        }

        $branchIds = $this->staffBranchScopeIds($actor) ?? [];

        return $target->branch_id !== null && in_array((int) $target->branch_id, $branchIds, true);
    }

    private function branchIdForUserWrite(User $actor, mixed $branchId, ?UserRole $targetRole = null): ?int
    {
        if ($this->canManageGlobalUsers($actor)) {
            return filled($branchId) ? (int) $branchId : null;
        }

        if ($targetRole === UserRole::Operator && blank($branchId)) {
            return null;
        }

        $branchIds = $this->staffBranchScopeIds($actor) ?? [];
        abort_unless($branchIds !== [], 403, 'Akun ini belum memiliki area/cabang.');

        if (filled($branchId)) {
            abort_unless(in_array((int) $branchId, $branchIds, true), 403, 'User di luar area akun ini.');

            return (int) $branchId;
        }

        return $branchIds[0];
    }

    private function canManageGlobalUsers(User $actor): bool
    {
        return app(BranchAccessSettingService::class)->roleHasGlobalBranchAccess($actor->role);
    }

    private function branchScopeIdsForUserWrite(User $actor, UserRole $targetRole, mixed $branchIds, mixed $fallbackBranchId = null): array
    {
        $branchIds = collect(is_array($branchIds) ? $branchIds : [])
            ->filter(fn (mixed $id): bool => filled($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (! in_array($targetRole, [UserRole::HRD, UserRole::Manager, UserRole::SPV, UserRole::Eksekutor], true)) {
            return [];
        }

        if ($branchIds === [] && filled($fallbackBranchId)) {
            $branchIds = [(int) $fallbackBranchId];
        }

        if ($this->canManageGlobalUsers($actor)) {
            return $branchIds;
        }

        $allowed = $this->staffBranchScopeIds($actor) ?? [];
        abort_unless($allowed !== [], 403, 'Akun ini belum memiliki area/cabang.');

        if ($branchIds === []) {
            return $allowed;
        }

        foreach ($branchIds as $branchId) {
            abort_unless(in_array($branchId, $allowed, true), 403, 'Scope cabang di luar area akun ini.');
        }

        return $branchIds;
    }

    /**
     * @return array<int, int>|null Null means global scope.
     */
    private function operationalBranchScopeIds(User $actor): ?array
    {
        if (app(BranchAccessSettingService::class)->roleHasGlobalBranchAccess($actor->role) || $actor->role === UserRole::Operator) {
            return null;
        }

        if (in_array($actor->role, [UserRole::SPV, UserRole::Eksekutor], true)) {
            return $this->staffBranchScopeIds($actor) ?? [];
        }

        return [];
    }

    /**
     * @return array<int, int>|null Null means global scope.
     */
    private function staffBranchScopeIds(User $actor): ?array
    {
        if (app(BranchAccessSettingService::class)->roleHasGlobalBranchAccess($actor->role)) {
            return null;
        }

        $actor->loadMissing('branchScopes:id');

        $branchIds = $actor->branchScopes
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($branchIds === [] && $actor->branch_id !== null) {
            $branchIds[] = (int) $actor->branch_id;
        }

        return array_values(array_unique($branchIds));
    }

    private function whereInStaffBranchScope(Builder $query, ?array $branchIds, bool $includeUnassignedOperators = false): Builder
    {
        if ($branchIds === null) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($branchIds, $includeUnassignedOperators): void {
            $query->whereIn('branch_id', $branchIds);

            if ($includeUnassignedOperators) {
                $query->orWhere(fn (Builder $query) => $query
                    ->where('role', UserRole::Operator->value)
                    ->whereNull('branch_id'));
            }
        });
    }

    private function canAssignDriver(User $user): bool
    {
        if (! $user->role instanceof UserRole) {
            return false;
        }

        if (in_array($user->role, [UserRole::Admin, UserRole::GM], true)) {
            return true;
        }

        if (! $user->hasPermission('monitor_live_order')) {
            return false;
        }

        return in_array($user->role->value, $this->assignDriverAllowedRoles(), true);
    }

    private function assignDriverAllowedRoles(?SettingService $settings = null): array
    {
        $settings ??= app(SettingService::class);
        $raw = $settings->get('assign_driver_allowed_roles', json_encode(self::DEFAULT_ASSIGN_DRIVER_ROLES));
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return $this->normalizeAssignDriverRoles(is_array($decoded) ? $decoded : self::DEFAULT_ASSIGN_DRIVER_ROLES);
    }

    private function normalizeAssignDriverRoles(array $roles): array
    {
        $allowed = ['manager', 'spv', 'operator', 'eksekutor'];

        return collect($roles)
            ->map(fn (mixed $role): string => strtolower(trim((string) $role)))
            ->filter(fn (string $role): bool => in_array($role, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeDailyPriorityWindows(array $windows): array
    {
        $normalized = collect($windows)
            ->filter(fn (mixed $window): bool => is_array($window))
            ->map(fn (array $window): array => [
                'start' => (string) ($window['start'] ?? '05:00'),
                'end' => (string) ($window['end'] ?? '11:00'),
            ])
            ->filter(fn (array $window): bool => $window['start'] !== $window['end'])
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : [
            ['start' => '05:00', 'end' => '11:00'],
            ['start' => '13:00', 'end' => '17:00'],
        ];
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
        $user->loadMissing(['branchScopes', 'currentLocation.branch', 'latestLocationLog.branch']);
        $registrationLocation = $user->lat !== null && $user->lng !== null
            ? [
                'lat' => (float) $user->lat,
                'lng' => (float) $user->lng,
                'address' => $user->address,
                'maps_url' => $this->mapsUrl((float) $user->lat, (float) $user->lng),
            ]
            : null;
        $currentLocation = $user->currentLocation
            ? [
                'lat' => (float) $user->currentLocation->lat,
                'lng' => (float) $user->currentLocation->lng,
                'accuracy' => $user->currentLocation->accuracy !== null ? (float) $user->currentLocation->accuracy : null,
                'branch' => $user->currentLocation->branch?->name,
                'branch_code' => $user->currentLocation->branch?->branch_code,
                'branch_display_name' => $user->currentLocation->branch?->display_name,
                'status' => $user->currentLocation->status,
                'updated_at' => $user->currentLocation->updated_at?->toDateTimeString(),
                'maps_url' => $this->mapsUrl((float) $user->currentLocation->lat, (float) $user->currentLocation->lng),
            ]
            : null;
        $latestLog = $user->latestLocationLog
            ? [
                'lat' => (float) $user->latestLocationLog->latitude,
                'lng' => (float) $user->latestLocationLog->longitude,
                'accuracy' => $user->latestLocationLog->accuracy !== null ? (float) $user->latestLocationLog->accuracy : null,
                'branch' => $user->latestLocationLog->branch?->name,
                'branch_code' => $user->latestLocationLog->branch?->branch_code,
                'branch_display_name' => $user->latestLocationLog->branch?->display_name,
                'is_suspicious' => (bool) $user->latestLocationLog->is_suspicious,
                'is_mock_location' => (bool) $user->latestLocationLog->is_mock_location,
                'reason' => $user->latestLocationLog->suspicion_reason,
                'created_at' => $user->latestLocationLog->created_at?->toDateTimeString(),
                'maps_url' => $this->mapsUrl((float) $user->latestLocationLog->latitude, (float) $user->latestLocationLog->longitude),
            ]
            : null;
        $latestPoint = $latestLog ?? $currentLocation;
        $movementMeters = $registrationLocation && $latestPoint
            ? $this->distanceMeters($registrationLocation['lat'], $registrationLocation['lng'], $latestPoint['lat'], $latestPoint['lng'])
            : null;
        $locationChanged = $movementMeters !== null && $movementMeters >= 250;
        $locationRisk = match (true) {
            (bool) data_get($latestLog, 'is_mock_location') => 'mock_location',
            (bool) data_get($latestLog, 'is_suspicious') => 'suspicious',
            $movementMeters !== null && $movementMeters >= 5000 => 'moved_far',
            $locationChanged => 'changed',
            default => 'normal',
        };

        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'address' => $user->address,
            'profile_photo_url' => $user->profile_photo_path ? '/api/media/'.ltrim($user->profile_photo_path, '/') : null,
            'role' => $user->role->value,
            'branch_id' => $user->branch_id,
            'branch' => $user->branch?->name,
            'branch_code' => $user->branch?->branch_code,
            'branch_area' => $user->branch?->area,
            'branch_display_name' => $user->branch?->display_name,
            'branch_scope_ids' => $user->branchScopes->pluck('id')->values()->all(),
            'branch_scopes' => $user->branchScopes
                ->map(fn (Branch $branch): array => [
                    'id' => $branch->id,
                    'branch_code' => $branch->branch_code,
                    'name' => $branch->name,
                    'area' => $branch->area,
                    'display_name' => $branch->display_name,
                ])
                ->values()
                ->all(),
            'is_active' => $user->is_active,
            'is_suspended' => $user->is_suspended,
            'driver_state' => $user->driver?->is_available ? 'online' : 'offline',
            'driver_bansos_amount' => $user->driver?->bansos_amount,
            'driver_bpjs_jht_enabled' => $user->driver?->bpjs_jht_enabled ?? false,
            'registration_location' => $registrationLocation,
            'current_location' => $currentLocation,
            'latest_gps' => $latestLog,
            'location_changed' => $locationChanged,
            'location_distance_meters' => $movementMeters,
            'location_risk' => $locationRisk,
        ];
    }

    private function orderPayload(Order $order, ?User $actor = null): array
    {
        $order->loadMissing(['user.branch', 'driver.user.branch', 'operHandleRequests.driver.user', 'crews.driver.user']);
        $operHandle = $order->operHandleRequests->sortByDesc('updated_at')->first();
        $branch = $order->branch ?? $order->user?->branch ?? $order->driver?->user?->branch;

        return [
            'id' => $order->id,
            'code' => $order->order_code,
            'customer' => $order->user?->name,
            'driver_user_id' => $order->driver?->user?->id,
            'driver' => $order->driver?->user?->name,
            'service' => $order->service_type,
            'service_code' => $order->service_code,
            'source' => $order->source,
            'status' => $order->status->value,
            'cancel_reason' => $this->cancelReasonFor($order),
            'branch' => $branch?->name,
            'branch_code' => $branch?->branch_code,
            'branch_area' => $branch?->area,
            'branch_display_name' => $branch?->display_name,
            'pickup_address' => $order->pickup_address,
            'destination_address' => $order->destination_address,
            'distance_km' => $order->distance_km !== null ? (float) $order->distance_km : null,
            'price' => $order->price,
            'service_charge' => $order->service_charge,
            'extra_charge' => $order->extra_charge,
            'total' => $order->total_price,
            'payment_method' => $order->payment_method,
            'payment_label' => $order->payment_label,
            'payment_meta' => $order->payment_meta,
            'preferred_vehicle_type' => data_get($order->pricing_breakdown, 'preferred_vehicle_type'),
            'required_vehicle_seat_rows' => data_get($order->pricing_breakdown, 'required_vehicle_seat_rows'),
            'driver_preference' => data_get($order->pricing_breakdown, 'driver_preference', 'general'),
            'oper_handle' => $operHandle ? $this->operHandlePayload($operHandle) : null,
            'notes' => $order->notes,
            'raw_text' => $order->raw_text,
            'pricing_breakdown' => $order->pricing_breakdown,
            'crew_decision' => data_get($order->pricing_breakdown, 'crew_decision'),
            'crew_status' => data_get($order->pricing_breakdown, 'crew_status', data_get($order->pricing_breakdown, 'crew_decision.requires_helper') ? 'waiting_helper' : null),
            'crews' => $order->crews->map(fn ($crew): array => [
                'id' => $crew->id,
                'role' => $crew->role,
                'label' => $crew->label,
                'status' => $crew->status,
                'driver' => $crew->driver?->user?->name,
                'service_charge' => $crew->role === 'rider' ? (int) $order->price : $crew->service_charge,
                'accepted_at' => $crew->accepted_at?->toDateTimeString(),
            ])->values(),
            'direction_bearing' => $order->direction_bearing !== null ? (float) $order->direction_bearing : null,
            'is_multi_order' => $order->is_multi_order,
            'created_at' => $order->created_at?->toDateTimeString(),
            'updated_at' => $order->updated_at?->toDateTimeString(),
            'waiting_seconds' => $this->waitingSeconds($order),
            'sla_status' => $this->dispatchSlaStatus($order),
            'suggested_drivers' => $actor ? $this->suggestedDriversForOrder($order, $actor) : [],
            'customer_preferences' => $this->customerPreferencePayload($order),
        ];
    }

    private function operHandlePayload(OperHandleRequest $operHandle): array
    {
        $operHandle->loadMissing(['order.branch', 'order.user.branch', 'order.driver.user.branch', 'driver.user.branch', 'requester']);
        $order = $operHandle->order;
        $branch = $order?->branch ?? $order?->user?->branch ?? $operHandle->driver?->user?->branch;

        return [
            'id' => $operHandle->id,
            'order_id' => $operHandle->order_id,
            'order_code' => $order?->order_code,
            'order_status' => $order?->status?->value,
            'customer' => $order?->user?->name,
            'driver' => $operHandle->driver?->user?->name,
            'driver_phone' => $operHandle->driver?->user?->phone,
            'branch' => $branch?->name,
            'branch_code' => $branch?->branch_code,
            'branch_area' => $branch?->area,
            'branch_display_name' => $branch?->display_name,
            'service' => $order?->service_type,
            'total' => $order?->total_price,
            'reason' => $operHandle->reason,
            'status' => $operHandle->status,
            'requested_by' => $operHandle->requester?->name,
            'operator_approved_at' => $operHandle->operator_approved_at?->toDateTimeString(),
            'spv_approved_at' => $operHandle->spv_approved_at?->toDateTimeString(),
            'created_at' => $operHandle->created_at?->toDateTimeString(),
            'updated_at' => $operHandle->updated_at?->toDateTimeString(),
        ];
    }

    private function assertOrderAreaScope(User $actor, Order $order): void
    {
        $branchIds = $this->operationalBranchScopeIds($actor);
        if ($branchIds === null) {
            return;
        }

        abort_unless($branchIds !== [], 403, 'Akun ini belum memiliki area/cabang.');

        $orderBranchId = $order->branch_id ?? $order->user?->branch_id ?? $order->driver?->user?->branch_id;
        abort_unless(in_array((int) $orderBranchId, $branchIds, true), 403, 'Order di luar area akun ini.');
    }

    private function canManageGlobalPricing(User $actor): bool
    {
        return app(BranchAccessSettingService::class)->roleHasGlobalBranchAccess($actor->role);
    }

    private function assertPricingBranchScope(User $actor, ?int $branchId, ?int $geofenceAreaId = null): void
    {
        if ($this->canManageGlobalPricing($actor)) {
            return;
        }

        $branchIds = $this->staffBranchScopeIds($actor) ?? [];
        abort_unless($branchIds !== [], 403, 'Akun ini belum memiliki area/cabang.');
        abort_unless($branchId !== null, 403, 'Role ini hanya boleh mengatur pricing cabang sendiri.');
        abort_unless(in_array((int) $branchId, $branchIds, true), 403, 'Pricing di luar area akun ini.');

        if ($geofenceAreaId === null) {
            return;
        }

        $geofenceBranchId = GeofenceArea::query()->whereKey($geofenceAreaId)->value('branch_id');
        abort_unless(in_array((int) $geofenceBranchId, $branchIds, true), 403, 'Zona pricing di luar area akun ini.');
    }

    private function suggestedDriversForOrder(Order $order, User $actor): array
    {
        if (! $this->canAssignDriver($actor)) {
            return [];
        }

        $branchId = $order->branch_id ?? $order->user?->branch_id ?? $actor->branch_id;
        if (! $branchId) {
            return [];
        }

        $favoriteDriverId = $this->favoriteDriverForCustomer($order)?->id;
        $blockedDriverIds = $this->blockedDriverIdsForCustomer($order);

        return Driver::query()
            ->with(['user.branch'])
            ->withAvg('ratings as rating_average', 'rating')
            ->where('status', 'active')
            ->where(fn (Builder $query) => $query->where('is_suspend', false)->orWhereNull('is_suspend'))
            ->where('is_available', true)
            ->when(data_get($order->pricing_breakdown, 'preferred_vehicle_type') === 'motor', fn (Builder $query) => $query->where(function (Builder $query): void {
                $query->where('vehicle_type', 'motor')
                    ->orWhereJsonContains('vehicle_types', 'motor');
            }))
            ->when(data_get($order->pricing_breakdown, 'preferred_vehicle_type') === 'mobil', function (Builder $query) use ($order): Builder {
                $requiredRows = (int) data_get($order->pricing_breakdown, 'required_vehicle_seat_rows', 2);

                return $query->where(function (Builder $query): void {
                    $query->where('vehicle_type', 'mobil')
                        ->orWhereJsonContains('vehicle_types', 'mobil');
                })->where(function (Builder $query) use ($requiredRows): void {
                    $query->where('vehicle_seat_rows', '>=', $requiredRows);

                    if ($requiredRows <= 2) {
                        $query->orWhereNull('vehicle_seat_rows');
                    }
                });
            })
            ->when(data_get($order->pricing_breakdown, 'driver_preference') === 'ladies', fn (Builder $query) => $query->where('is_ladies_driver', true))
            ->where(function (Builder $query) use ($branchId): void {
                $query->where('can_accept_all_areas', true)
                    ->orWhereHas('user', fn (Builder $query) => $query->where('branch_id', $branchId));
            })
            ->whereHas('user', fn (Builder $query) => $query
                ->where('is_active', true)
                ->where('is_suspended', false))
            ->limit(12)
            ->get()
            ->reject(function (Driver $driver) use ($blockedDriverIds): bool {
                $deposit = app(\App\Services\DriverFinanceService::class)->monthlyDeposit($driver, now()->subMonth());
                if (($deposit->status ?? 'unpaid') !== 'paid' && $deposit->due_date?->endOfDay()->isPast()) {
                    if ($driver->is_available) {
                        $driver->forceFill(['is_available' => false])->save();
                    }

                    return true;
                }

                return $this->driverHasActiveOrder($driver) || in_array((int) $driver->id, $blockedDriverIds, true);
            })
            ->sortByDesc(fn (Driver $driver): int => (int) $driver->id === (int) $favoriteDriverId ? 1 : 0)
            ->values()
            ->map(fn (Driver $driver): array => [
                'id' => $driver->id,
                'name' => $driver->user?->name ?? 'Driver #'.$driver->id,
                'phone' => $driver->user?->phone,
                'vehicle_type' => $driver->vehicle_type ?? 'motor',
                'vehicle_types' => $driver->vehicleTypes(),
                'vehicle_seat_rows' => $driver->vehicle_seat_rows,
                'is_ladies_driver' => (bool) $driver->is_ladies_driver,
                'can_accept_all_areas' => (bool) $driver->can_accept_all_areas,
                'branch' => $driver->user?->branch?->name,
                'branch_area' => $driver->user?->branch?->area,
                'rating_average' => round((float) ($driver->rating_average ?? 0), 2),
                'is_favorite' => (int) $driver->id === (int) $favoriteDriverId,
            ])
            ->all();
    }

    private function customerPreferencePayload(Order $order): array
    {
        $favorite = $this->favoriteDriverForCustomer($order);
        $notes = trim((string) $order->notes);
        $blockedDrivers = $this->blockedDriversForCustomer($order);

        return [
            'favorite_driver' => $favorite ? [
                'id' => $favorite->id,
                'name' => $favorite->user?->name ?? 'Driver #'.$favorite->id,
            ] : null,
            'blocked_drivers' => $blockedDrivers !== [] ? $blockedDrivers : $this->blockedDriverHints($notes),
            'notes' => $notes !== '' ? $notes : ($order->user?->address ?? null),
        ];
    }

    private function favoriteDriverForCustomer(Order $order): ?Driver
    {
        if (! $order->user_id) {
            return null;
        }

        if (Schema::hasTable('customer_driver_preferences')) {
            $driverId = DB::table('customer_driver_preferences')
                ->where('user_id', $order->user_id)
                ->where('type', 'favorite')
                ->latest('updated_at')
                ->value('driver_id');

            if ($driverId) {
                return Driver::query()->with('user')->find($driverId);
            }
        }

        $driverId = Order::query()
            ->where('user_id', $order->user_id)
            ->whereNotNull('driver_id')
            ->where('status', OrderStatus::Completed->value)
            ->selectRaw('driver_id, count(*) as total')
            ->groupBy('driver_id')
            ->orderByDesc('total')
            ->value('driver_id');

        return $driverId ? Driver::query()->with('user')->find($driverId) : null;
    }

    private function blockedDriverIdsForCustomer(Order $order): array
    {
        if (! $order->user_id || ! Schema::hasTable('customer_driver_preferences')) {
            return [];
        }

        return DB::table('customer_driver_preferences')
            ->where('user_id', $order->user_id)
            ->where('type', 'blocked')
            ->pluck('driver_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function blockedDriversForCustomer(Order $order): array
    {
        $ids = $this->blockedDriverIdsForCustomer($order);
        if ($ids === []) {
            return [];
        }

        return Driver::query()
            ->with('user')
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (Driver $driver): string => $driver->user?->name ?? 'Driver #'.$driver->id)
            ->all();
    }

    private function blockedDriverHints(string $notes): array
    {
        if ($notes === '') {
            return [];
        }

        preg_match_all('/(?:jangan|tidak\s+mau|blocked?|blokir)\s+(?:driver\s+)?([a-z0-9 ._-]{2,40})/i', $notes, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $name): string => trim($name, " .,-\t\n\r\0\x0B"))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function waitingSeconds(Order $order): int
    {
        if (! in_array($order->status, [OrderStatus::Created, OrderStatus::SearchingDriver], true)) {
            return 0;
        }

        return max(0, $order->created_at?->diffInSeconds(now()) ?? 0);
    }

    private function dispatchSlaStatus(Order $order): string
    {
        $seconds = $this->waitingSeconds($order);

        return match (true) {
            $seconds >= 600 => 'critical',
            $seconds >= 300 => 'warning',
            $seconds > 0 => 'normal',
            default => 'assigned',
        };
    }

    private function driverHasActiveOrder(Driver $driver): bool
    {
        return Order::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', [
                OrderStatus::DriverAccepted->value,
                OrderStatus::DriverOnTheWay->value,
                OrderStatus::ArrivedPickup->value,
                OrderStatus::OnGoing->value,
                OrderStatus::PendingCancel->value,
            ])
            ->exists();
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

        if (str_contains(strtolower($notes), 'multi-crew timeout')) {
            return 'Auto-cancel: batas waktu cari helper multi-crew habis.';
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
            ->with([
                'driver' => fn ($query) => $query
                    ->withAvg('ratings as rating_average', 'rating')
                    ->withCount([
                        'ratings as ratings_count',
                        'orders as completed_orders_count' => fn ($query) => $query->where('status', OrderStatus::Completed->value),
                        'orders as today_completed_orders_count' => fn ($query) => $query
                            ->where('status', OrderStatus::Completed->value)
                            ->whereDate('created_at', now()->toDateString()),
                        'orders as month_completed_orders_count' => fn ($query) => $query
                            ->where('status', OrderStatus::Completed->value)
                            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]),
                        'orders as cancelled_orders_count' => fn ($query) => $query->where('status', OrderStatus::Cancelled->value),
                        'orders as today_cancelled_orders_count' => fn ($query) => $query
                            ->where('status', OrderStatus::Cancelled->value)
                            ->whereDate('created_at', now()->toDateString()),
                        'orders as month_cancelled_orders_count' => fn ($query) => $query
                            ->where('status', OrderStatus::Cancelled->value)
                            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]),
                        'suspensions as suspensions_count',
                        'operHandleRequests as oper_handle_requests_count',
                        'deposits as unpaid_deposits_count' => fn ($query) => $query->where('status', 'unpaid'),
                    ])
                    ->withSum([
                        'orders as completed_revenue' => fn ($query) => $query->where('status', OrderStatus::Completed->value),
                        'orders as today_revenue' => fn ($query) => $query
                            ->where('status', OrderStatus::Completed->value)
                            ->whereDate('created_at', now()->toDateString()),
                        'orders as month_revenue' => fn ($query) => $query
                            ->where('status', OrderStatus::Completed->value)
                            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]),
                    ], 'total_price'),
                'driver.orders' => fn ($query) => $query
                    ->where('status', OrderStatus::Completed->value)
                    ->latest()
                    ->limit(1),
                'driver.suspensions' => fn ($query) => $query->latest()->limit(5),
            ])
            ->limit(100)
            ->get()
            ->map(function (User $user): array {
                $deposit = $user->driver ? app(DriverFinanceService::class)->monthlyDeposit($user->driver, now()->subMonth()) : null;

                return [
                ...$this->userPayload($user),
                'driver_id' => $user->driver?->id,
                'driver_status' => $user->driver?->status ?? ($user->is_suspended ? 'suspended' : 'active'),
                'deposit_status' => $deposit?->status,
                'deposit_total' => (int) ($deposit?->total ?? 0),
                'deposit_paid_amount' => (int) ($deposit?->paid_amount ?? 0),
                'deposit_remaining' => max(0, (int) ($deposit?->total ?? 0) - (int) ($deposit?->paid_amount ?? 0)),
                'deposit_paid_at' => $deposit?->paid_at?->toDateTimeString(),
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
                'vehicle_types' => $user->driver?->vehicleTypes() ?? ['motor'],
                'vehicle_seat_rows' => $user->driver?->vehicle_seat_rows,
                'is_ladies_driver' => (bool) ($user->driver?->is_ladies_driver ?? false),
                'can_accept_all_areas' => (bool) ($user->driver?->can_accept_all_areas ?? false),
                'allowed_service_types' => $user->driver?->allowed_service_types ?? [],
                'performance' => [
                    'rating_average' => round((float) ($user->driver?->rating_average ?? 0), 2),
                    'ratings_count' => (int) ($user->driver?->ratings_count ?? 0),
                    'rating_score' => app(RatingService::class)->weightedScore((float) ($user->driver?->rating_average ?? 0), (int) ($user->driver?->ratings_count ?? 0)),
                    'rating_confidence' => app(RatingService::class)->ratingConfidence((int) ($user->driver?->ratings_count ?? 0)),
                    'completed_orders_count' => (int) ($user->driver?->completed_orders_count ?? 0),
                    'today_completed_orders_count' => (int) ($user->driver?->today_completed_orders_count ?? 0),
                    'month_completed_orders_count' => (int) ($user->driver?->month_completed_orders_count ?? 0),
                    'cancelled_orders_count' => (int) ($user->driver?->cancelled_orders_count ?? 0),
                    'today_cancelled_orders_count' => (int) ($user->driver?->today_cancelled_orders_count ?? 0),
                    'month_cancelled_orders_count' => (int) ($user->driver?->month_cancelled_orders_count ?? 0),
                    'suspensions_count' => (int) ($user->driver?->suspensions_count ?? 0),
                    'oper_handle_requests_count' => (int) ($user->driver?->oper_handle_requests_count ?? 0),
                    'unpaid_deposits_count' => (int) ($user->driver?->unpaid_deposits_count ?? 0),
                    'completed_revenue' => (int) ($user->driver?->completed_revenue ?? 0),
                    'today_revenue' => (int) ($user->driver?->today_revenue ?? 0),
                    'month_revenue' => (int) ($user->driver?->month_revenue ?? 0),
                    'last_completed_at' => $user->driver?->orders?->first()?->created_at?->toDateTimeString(),
                    'online_score' => $user->driver?->is_available ? 1 : 0,
                ],
                'suspensions' => $user->driver?->suspensions->map(fn ($suspension): array => [
                    'id' => $suspension->id,
                    'reason' => $suspension->reason,
                    'duration' => $suspension->duration,
                    'start_at' => $suspension->start_at?->toDateTimeString(),
                    'end_at' => $suspension->end_at?->toDateTimeString(),
                    'status' => $suspension->status,
                ])->all() ?? [],
            ];
            })
            ->all();
    }

    private function operatorPerformanceRows(User $actor): array
    {
        return $this->usersQuery($actor)
            ->whereIn('role', [UserRole::Operator->value, UserRole::Eksekutor->value])
            ->limit(100)
            ->get()
            ->map(function (User $user): array {
                $query = ChatConversation::query()->where('operator_id', $user->id);
                $ratedQuery = (clone $query)->whereNotNull('operator_rating');

                $ratingAverage = (float) $ratedQuery->avg('operator_rating');
                $ratingCount = (clone $ratedQuery)->count();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role->value,
                    'branch' => $user->branch?->name,
                    'branch_area' => $user->branch?->area,
                    'handled_chats_count' => (clone $query)->count(),
                    'active_chats_count' => (clone $query)->whereIn('status', ['open', 'active', 'waiting'])->count(),
                    'rating_average' => round($ratingAverage, 2),
                    'rating_score' => app(RatingService::class)->weightedScore($ratingAverage, $ratingCount, 4.2, 8),
                    'rating_confidence' => app(RatingService::class)->ratingConfidence($ratingCount, 8),
                    'ratings_count' => $ratingCount,
                    'late_response_count' => (clone $query)->whereIn('sla_status', ['late', 'breached'])->count(),
                ];
            })
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

    private function normalizeVehicleTypes(array $types): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($type): string => strtolower(trim((string) $type)),
            $types,
        ), fn (string $type): bool => in_array($type, ['motor', 'mobil'], true))));

        return $normalized === [] ? ['motor'] : $normalized;
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
            'multi_crew_auto_cancel_enabled' => $settings->bool('multi_crew_auto_cancel_enabled', true),
            'multi_crew_auto_cancel_minutes' => max(1, min(180, $settings->int('multi_crew_auto_cancel_minutes', 7))),
            'multi_crew_auto_cancel_message' => $settings->get('multi_crew_auto_cancel_message', 'Maaf, order {order_code} dibatalkan otomatis karena helper belum menerima dalam {minutes} menit.') ?: 'Maaf, order {order_code} dibatalkan otomatis karena helper belum menerima dalam {minutes} menit.',
            'driver_daily_priority_enabled' => $settings->bool('driver_daily_priority_enabled', true),
            'driver_daily_priority_hold_minutes' => max(1, min(60, $settings->int('driver_daily_priority_hold_minutes', 3))),
            'driver_daily_priority_windows' => app(DriverDailyPriorityService::class)->windows(),
            'night_tariff_enabled' => $settings->bool('night_tariff_enabled', true),
            'night_tariff_rules' => app(OrderOperationService::class)->nightRules(),
            'zone_pricing_enabled' => $settings->bool('zone_pricing_enabled', true),
            'assign_driver_allowed_roles' => $this->assignDriverAllowedRoles($settings),
            'edit_tarif_allowed_roles' => app(RolePermissionSettingService::class)->editTarifAllowedRoles(),
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
            'accuracy' => $log->accuracy !== null ? (float) $log->accuracy : null,
            'provider' => $log->provider,
            'is_mock_location' => (bool) $log->is_mock_location,
            'is_valid' => $log->is_valid,
            'is_suspicious' => $log->is_suspicious,
            'reason' => $log->suspicion_reason,
            'maps_url' => $this->mapsUrl((float) $log->latitude, (float) $log->longitude),
            'created_at' => $log->created_at?->toDateTimeString(),
        ];
    }

    private function mapsUrl(float $lat, float $lng): string
    {
        return "https://www.google.com/maps?q={$lat},{$lng}";
    }

    private function geojsonFeatures(array $geojson): array
    {
        if (($geojson['type'] ?? null) === 'FeatureCollection') {
            return array_values(array_filter($geojson['features'] ?? [], 'is_array'));
        }

        if (($geojson['type'] ?? null) === 'Feature') {
            return [$geojson];
        }

        if (in_array($geojson['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
            return [['type' => 'Feature', 'properties' => [], 'geometry' => $geojson]];
        }

        return [];
    }

    private function geojsonFeaturePolygons(array $feature): array
    {
        $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : $feature;
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        if ($type === 'Polygon' && is_array($coordinates)) {
            return $this->geojsonPolygonRings($coordinates);
        }

        if ($type === 'MultiPolygon' && is_array($coordinates)) {
            $polygons = [];
            foreach ($coordinates as $polygon) {
                if (is_array($polygon)) {
                    $polygons = [...$polygons, ...$this->geojsonPolygonRings($polygon)];
                }
            }

            return $polygons;
        }

        return [];
    }

    private function geojsonPolygonRings(array $coordinates): array
    {
        $outerRing = $coordinates[0] ?? [];
        if (! is_array($outerRing)) {
            return [];
        }

        $points = collect($outerRing)
            ->map(function (mixed $point): ?array {
                if (! is_array($point) || ! isset($point[0], $point[1]) || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                    return null;
                }

                return [
                    'lat' => round((float) $point[1], 8),
                    'lng' => round((float) $point[0], 8),
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (count($points) > 1 && $points[0] === $points[count($points) - 1]) {
            array_pop($points);
        }

        return $points !== [] ? [$points] : [];
    }

    private function normalizeGeojsonRing(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^(?:ring[_\s-]?)?([123])$/', $normalized, $matches) === 1) {
            return 'ring_'.$matches[1];
        }

        return str_starts_with($normalized, 'ring_') ? $normalized : null;
    }

    private function geojsonPricingData(array $properties, string $ring): array
    {
        $mode = strtolower((string) ($properties['pricing_mode'] ?? ''));
        $defaultMode = $ring === 'ring_3' ? 'formula' : 'flat';
        $pricingMode = in_array($mode, ['flat', 'formula'], true) ? $mode : $defaultMode;

        $price = $this->geojsonInt($properties['price'] ?? $properties['base_price'] ?? null);
        $perKmRate = $this->geojsonInt($properties['per_km_rate'] ?? null);
        $subtractValue = $this->geojsonInt($properties['subtract_value'] ?? null);

        if ($pricingMode === 'formula') {
            $price = $price ?? 0;
            $perKmRate = $perKmRate ?? 0;
            $subtractValue = $subtractValue ?? 0;
        } else {
            $price = $price ?? 0;
            $perKmRate = null;
            $subtractValue = 0;
        }

        return [
            'min_km' => $this->geojsonFloat($properties['min_km'] ?? null) ?? $this->defaultRingMinKm($ring),
            'max_km' => $this->geojsonFloat($properties['max_km'] ?? null) ?? $this->defaultRingMaxKm($ring),
            'pricing_mode' => $pricingMode,
            'price' => $price,
            'per_km_rate' => $perKmRate,
            'subtract_value' => $subtractValue,
            'service_fee' => $this->geojsonInt($properties['service_fee'] ?? null) ?? $this->defaultRingServiceFee($ring),
            'priority' => $this->geojsonInt($properties['priority'] ?? null) ?? $this->defaultRingPriority($ring),
        ];
    }

    private function geojsonRuleName(array $properties, string $ring, int $featureIndex, int $polygonIndex, int $polygonCount): string
    {
        $base = trim((string) ($properties['name'] ?? $properties['Name'] ?? $properties['NAME'] ?? 'GeoJSON'));
        $suffix = $polygonCount > 1 ? sprintf(' #%03d-%02d', $featureIndex, $polygonIndex + 1) : sprintf(' #%03d', $featureIndex);

        return Str::limit(sprintf('%s %s%s', $base, str_replace('_', ' ', $ring), $suffix), 255, '');
    }

    private function geojsonCentroid(array $points): array
    {
        $total = max(1, count($points));
        $lat = array_sum(array_map(fn (array $point): float => (float) $point['lat'], $points)) / $total;
        $lng = array_sum(array_map(fn (array $point): float => (float) $point['lng'], $points)) / $total;

        return ['lat' => $lat, 'lng' => $lng];
    }

    private function nearestBranchIdForGeojson($branches, array $point): ?int
    {
        $calculator = app(DistanceCalculator::class);
        $nearest = null;
        $nearestDistance = null;

        foreach ($branches as $branch) {
            if (! is_numeric($branch->latitude) || ! is_numeric($branch->longitude)) {
                continue;
            }

            $distance = $calculator->haversine((float) $branch->latitude, (float) $branch->longitude, (float) $point['lat'], (float) $point['lng']);
            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearest = (int) $branch->id;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    private function geojsonSkipLabel(array $properties, int $featureIndex, string $reason): string
    {
        $name = trim((string) ($properties['name'] ?? $properties['Name'] ?? $properties['NAME'] ?? 'Feature'));

        return sprintf('%s #%03d: %s', $name, $featureIndex, $reason);
    }

    private function geojsonInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function geojsonFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function distanceMeters(float $latA, float $lngA, float $latB, float $lngB): int
    {
        $earthRadius = 6371000;
        $latDelta = deg2rad($latB - $latA);
        $lngDelta = deg2rad($lngB - $lngA);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($lngDelta / 2) ** 2;

        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
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
            'metadata' => $log->metadata ?? [],
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
            'type' => $chat->type,
            'status' => $chat->status,
            'sla_status' => $chat->sla_status,
            'latest_message' => $chat->latestMessage?->message,
            'last_customer_message_at' => $chat->last_customer_message_at?->toDateTimeString(),
            'first_operator_response_at' => $chat->first_operator_response_at?->toDateTimeString(),
            'rating_requested_at' => $chat->rating_requested_at?->toDateTimeString(),
            'closed_at' => $chat->closed_at?->toDateTimeString(),
            'unread_count' => $chat->messages()
                ->where('sender_id', '!=', request()->user()?->id)
                ->whereNull('read_at')
                ->count(),
            'updated_at' => $chat->updated_at?->toDateTimeString(),
        ];
    }

    private function ringPricingRulePayload(RingPricingRule $rule): array
    {
        return [
            'id' => $rule->id,
            'branch_id' => $rule->branch_id,
            'branch' => $rule->branch ? [
                'id' => $rule->branch->id,
                'branch_code' => $rule->branch->branch_code,
                'name' => $rule->branch->name,
                'area' => $rule->branch->area,
                'display_name' => $rule->branch->display_name,
            ] : null,
            'service_type' => $rule->service_type,
            'name' => $rule->name,
            'area_mode' => $rule->area_mode ?? 'text',
            'pickup_area' => $rule->pickup_area,
            'destination_area' => $rule->destination_area,
            'pickup_aliases' => $rule->pickup_aliases ?? [],
            'destination_aliases' => $rule->destination_aliases ?? [],
            'polygon_coordinates' => $rule->polygon_coordinates ?? [],
            'polygon_match_point' => $rule->polygon_match_point ?? 'destination_then_pickup',
            'match_type' => $rule->match_type ?? 'point',
            'pickup_ring' => $rule->pickup_ring,
            'destination_ring' => $rule->destination_ring,
            'ring' => $rule->ring,
            'min_km' => $rule->min_km,
            'max_km' => $rule->max_km,
            'pricing_mode' => $rule->pricing_mode ?? 'flat',
            'price' => $rule->price,
            'per_km_rate' => $rule->per_km_rate,
            'subtract_value' => $rule->subtract_value ?? 0,
            'service_fee' => $rule->service_fee ?? 1000,
            'priority' => $rule->priority ?: $this->defaultRingPriority((string) $rule->ring),
            'is_bidirectional' => (bool) $rule->is_bidirectional,
            'source' => $rule->source,
            'is_active' => (bool) $rule->is_active,
            'created_at' => $rule->created_at?->toDateTimeString(),
            'updated_at' => $rule->updated_at?->toDateTimeString(),
        ];
    }

    private function ringPricingSuggestionPayload(RingPricingSuggestion $suggestion): array
    {
        return [
            'id' => $suggestion->id,
            'branch_id' => $suggestion->branch_id,
            'branch' => $suggestion->branch ? [
                'id' => $suggestion->branch->id,
                'name' => $suggestion->branch->name,
                'area' => $suggestion->branch->area,
            ] : null,
            'service_type' => $suggestion->service_type,
            'pickup_area' => $suggestion->pickup_area,
            'destination_area' => $suggestion->destination_area,
            'ring' => $suggestion->ring,
            'suggested_price' => $suggestion->suggested_price,
            'previous_price' => $suggestion->previous_price,
            'occurrence_count' => $suggestion->occurrence_count,
            'sample_order_ids' => $suggestion->sample_order_ids ?? [],
            'last_order_code' => $suggestion->lastOrder?->order_code,
            'last_edited_by' => $suggestion->editor?->name,
            'status' => $suggestion->status,
            'created_at' => $suggestion->created_at?->toDateTimeString(),
            'updated_at' => $suggestion->updated_at?->toDateTimeString(),
        ];
    }

    private function zonePricingRulePayload(ZonePricingRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'branch_id' => $rule->branch_id,
            'branch' => $rule->branch ? [
                'id' => $rule->branch->id,
                'name' => $rule->branch->name,
                'area' => $rule->branch->area,
            ] : null,
            'geofence_area_id' => $rule->geofence_area_id,
            'geofence_area' => $rule->geofenceArea ? [
                'id' => $rule->geofenceArea->id,
                'name' => $rule->geofenceArea->name,
                'shape_type' => $rule->geofenceArea->shape_type ?? 'circle',
                'radius_meters' => $rule->geofenceArea->radius_meters,
                'branch' => $rule->geofenceArea->branch ? [
                    'id' => $rule->geofenceArea->branch->id,
                    'name' => $rule->geofenceArea->branch->name,
                    'area' => $rule->geofenceArea->branch->area,
                ] : null,
            ] : null,
            'service_type' => $rule->service_type,
            'match_point' => $rule->match_point,
            'price_mode' => $rule->price_mode,
            'amount' => (int) ($rule->amount ?? 0),
            'percent' => $rule->percent !== null ? (float) $rule->percent : null,
            'min_km' => $rule->min_km !== null ? (float) $rule->min_km : null,
            'max_km' => $rule->max_km !== null ? (float) $rule->max_km : null,
            'is_active' => (bool) $rule->is_active,
            'priority' => (int) $rule->priority,
            'notes' => $rule->notes,
            'created_at' => $rule->created_at?->toDateTimeString(),
            'updated_at' => $rule->updated_at?->toDateTimeString(),
        ];
    }

    private function zonePointPayload(?array $point): ?array
    {
        if (! $point) {
            return null;
        }

        $area = $point['area'] ?? null;
        $branch = $point['branch'] ?? null;

        return [
            'area' => $area ? [
                'id' => $area->id,
                'name' => $area->name,
                'shape_type' => $area->shape_type ?? 'circle',
                'radius_meters' => $area->radius_meters,
            ] : null,
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'area' => $branch->area,
            ] : null,
            'distance_meters' => $point['distance_meters'] ?? null,
        ];
    }

    private function keywordParserPayload(KeywordParser $parser): array
    {
        return [
            'id' => $parser->id,
            'keyword' => $parser->keyword,
            'service_type' => $parser->service_type,
            'response_template' => $parser->response_template,
            'form_schema' => $parser->form_schema ?? ['fields' => []],
            'parser_type' => $parser->parser_type,
            'is_active' => (bool) $parser->is_active,
            'priority' => (int) $parser->priority,
            'created_at' => $parser->created_at?->toDateTimeString(),
            'updated_at' => $parser->updated_at?->toDateTimeString(),
        ];
    }

    private function pricingKeywordRulePayload(PricingKeywordRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'keywords' => $rule->keywords,
            'amount' => (int) $rule->amount,
            'service_scopes' => $rule->service_scopes ?? ['all'],
            'is_active' => (bool) $rule->is_active,
            'priority' => (int) $rule->priority,
            'description' => $rule->description,
            'created_at' => $rule->created_at?->toDateTimeString(),
            'updated_at' => $rule->updated_at?->toDateTimeString(),
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
