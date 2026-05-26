<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\DriverDeposit;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Services\Pricing\JokerPricing;
use App\Services\Pricing\ServiceFeeCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class DriverFinanceService
{
    public const UNPAID_SUSPEND_DAY = 11;
    public const UNPAID_SUSPEND_REASON = 'Setoran bulan sebelumnya masih unpaid per tanggal 11. Anda terkena suspend setoran, silakan bayar setoran agar akun bisa ON kembali.';
    public const BPJS_PREMI_AMOUNT = 20000;
    public const BPJS_PREMI_FREE_MIN_BASE_DEPOSIT = 30000;
    public const BPJS_JHT_AMOUNT = 20000;

    private const BANSOS_BY_AREA = [
        'situbondo' => 5000,
        'asembagus' => 10000,
        'banyuwangi' => 20000,
        'genteng' => 20000,
        'bondowoso' => 5000,
    ];

    public function __construct(private readonly ServiceFeeCalculator $serviceFees, private readonly RatingService $ratings)
    {
    }

    public function monthlyDeposit(Driver $driver, ?Carbon $month = null): DriverDeposit
    {
        $month ??= now();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $existing = DriverDeposit::query()
            ->where('driver_id', $driver->id)
            ->where('year', (int) $month->year)
            ->where('month', (int) $month->month)
            ->first();

        if ($this->periodIsBeforeDriverJoined($driver, $end) && ! (bool) data_get($existing?->breakdown, 'manual_override', false)) {
            $bansos = $this->bansos($driver);
            $bpjs = self::BPJS_PREMI_AMOUNT;
            $bpjsJht = $this->bpjsJhtForDriver($driver);
            $total = $bansos + $bpjs + $bpjsJht;
            $paidAmount = min((int) ($existing?->paid_amount ?? 0), $total);
            $paidAt = $paidAmount > 0 ? ($existing?->paid_at ?? now()) : null;
            $status = $total > 0 && $paidAmount < $total ? 'unpaid' : 'paid';

            return DriverDeposit::query()->updateOrCreate(
                ['driver_id' => $driver->id, 'year' => (int) $month->year, 'month' => (int) $month->month],
                [
                    'handle_day_15' => 0,
                    'handle_day_30' => 0,
                    'bansos' => $bansos,
                    'bpjs' => $bpjs,
                    'bpjs_jht' => $bpjsJht,
                    'total' => $total,
                    'due_date' => $this->unpaidSuspendDate($month)->toDateString(),
                    'paid_amount' => $paidAmount,
                    'paid_at' => $paidAt,
                    'status' => $status,
                    'breakdown' => [
                        'before_driver_joined' => true,
                        'driver_activation_charge' => true,
                        'handle_hari_15' => 0,
                        'handle_hari_30' => 0,
                        'setoran_hingga_hari_ini' => 0,
                        'tagihan_bulan_sebelumnya' => 0,
                        'cashback_bulan_sebelumnya' => 0,
                        'bansos' => $bansos,
                        'bpjs' => $bpjs,
                        'bpjs_jht' => $bpjsJht,
                        'note' => 'Tagihan aktivasi driver baru. Setoran order periode sebelum driver terdaftar tetap 0, tetapi BPJS/JHT/Bansos wajib dilunasi melalui tombol bayar/lunas.',
                    ],
                ],
            );
        }

        $previousPeriod = $month->copy()->subMonth();
        $handleDay15 = $this->handleTotal($driver, $start, $month->copy()->day(min(15, $end->day))->endOfDay());
        $handleDay30 = $this->handleTotal($driver, $month->copy()->day(min(16, $end->day))->startOfDay(), $end);
        $baseDeposit = $handleDay15 + $handleDay30;
        $previousDeposit = DriverDeposit::query()
            ->where('driver_id', $driver->id)
            ->where('year', $previousPeriod->year)
            ->where('month', $previousPeriod->month)
            ->first();

        if (! $previousDeposit) {
            $previousDeposit = $this->monthlyDeposit($driver, $previousPeriod);
        }

        $previousRemaining = max(0, (int) ($previousDeposit?->total ?? 0) - (int) ($previousDeposit?->paid_amount ?? 0));
        $previousBaseDeposit = (int) ($previousDeposit?->handle_day_15 ?? 0) + (int) ($previousDeposit?->handle_day_30 ?? 0);
        $cashback = $this->cashbackForPreviousDeposit($previousDeposit, $month, $previousBaseDeposit);
        $manualOverride = (bool) data_get($existing?->breakdown, 'manual_override', false);
        $manualBaseOverride = data_get($existing?->breakdown, 'manual_base_override');
        $manualBaseOverride = $manualBaseOverride === null ? $manualOverride : (bool) $manualBaseOverride;

        if ($manualBaseOverride) {
            $manualBase = (int) data_get(
                $existing?->breakdown,
                'manual_base_at_override',
                (int) ($existing?->handle_day_15 ?? 0) + (int) ($existing?->handle_day_30 ?? 0),
            );
            $manualAnchor = data_get($existing?->breakdown, 'manual_base_anchor_at');
            $newCompletedDeposit = filled($manualAnchor)
                ? $this->handleTotalAfter($driver, Carbon::parse((string) $manualAnchor), $end)
                : 0;

            $baseDeposit = max(0, $manualBase + $newCompletedDeposit);
            $handleDay15 = $baseDeposit;
            $handleDay30 = 0;
        }

        $bansos = $manualOverride ? (int) ($existing?->bansos ?? 0) : $this->bansos($driver);
        $bpjs = $this->bpjsPremiumForBaseDeposit($baseDeposit);
        $bpjsJht = $this->bpjsJhtForDriver($driver);
        $billBeforeBansos = data_get($existing?->breakdown, 'manual_bill_before_bansos');
        $billBeforeBansos = $billBeforeBansos !== null
            ? max(0, (int) $billBeforeBansos)
            : max(0, $baseDeposit + $previousRemaining - $cashback + $bpjs + $bpjsJht);
        $manualTotal = data_get($existing?->breakdown, 'manual_total_bill');
        $total = $manualTotal !== null
            ? max(0, (int) $manualTotal)
            : max(0, $billBeforeBansos + $bansos);
        $paidAmount = (int) ($existing?->paid_amount ?? 0);
        $paidAt = $existing?->paid_at;
        $dueDate = $this->unpaidSuspendDate($month);
        $status = $this->depositStatus($total, $paidAmount, $dueDate, $existing?->status);
        $breakdown = $existing?->breakdown ?? [];
        $breakdown = array_merge($breakdown, [
            'handle_hari_15' => $handleDay15,
            'handle_hari_30' => $handleDay30,
            'setoran_hingga_hari_ini' => $baseDeposit,
            'tagihan_bulan_sebelumnya' => $previousRemaining,
            'cashback_bulan_sebelumnya' => $cashback,
            'bill_before_bansos' => $billBeforeBansos,
            'bansos' => $bansos,
            'bpjs' => $bpjs,
            'bpjs_jht' => $bpjsJht,
            'manual_override' => $manualOverride,
            'manual_base_override' => $manualBaseOverride,
        ]);

        return DriverDeposit::query()->updateOrCreate(
            ['driver_id' => $driver->id, 'year' => (int) $month->year, 'month' => (int) $month->month],
            [
                'handle_day_15' => $handleDay15,
                'handle_day_30' => $handleDay30,
                'bansos' => $bansos,
                'bpjs' => $bpjs,
                'bpjs_jht' => $bpjsJht,
                'total' => $total,
                'due_date' => $dueDate->toDateString(),
                'paid_amount' => $paidAmount,
                'paid_at' => $paidAt,
                'status' => $status,
                'breakdown' => $breakdown,
            ],
        );
    }

    public function performance(Driver $driver): array
    {
        $rating = $driver->ratings()->avg('rating');
        $deposit = $this->monthlyDeposit($driver);
        $today = now();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();
        $completedAtColumn = Schema::hasColumn('orders', 'completed_at') ? 'completed_at' : 'updated_at';
        $completedThisMonth = $driver->orders()
            ->where('status', OrderStatus::Completed->value)
            ->whereBetween($completedAtColumn, [$monthStart, $monthEnd]);
        $cancelledThisMonth = $driver->orders()
            ->where('status', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$monthStart, $monthEnd]);
        $completedToday = $driver->orders()
            ->where('status', OrderStatus::Completed->value)
            ->whereDate($completedAtColumn, $today->toDateString());

        return [
            'rating' => round((float) $rating, 2),
            'rating_score' => $this->ratings->weightedScore((float) $rating, $driver->ratings()->count()),
            'rating_confidence' => $this->ratings->ratingConfidence($driver->ratings()->count()),
            'ratings_count' => $driver->ratings()->count(),
            'completed_orders_count' => (clone $completedThisMonth)->count(),
            'cancelled_orders_count' => (clone $cancelledThisMonth)->count(),
            'today_completed_orders_count' => (clone $completedToday)->count(),
            'month_revenue' => (int) (clone $completedThisMonth)->sum('total_price'),
            'today_revenue' => (int) (clone $completedToday)->sum('total_price'),
            'period_label' => $today->translatedFormat('F Y'),
            'setoran' => $deposit,
            'suspend_history' => $driver->suspensions()->latest()->limit(20)->get(),
            'oper_handle' => $driver->operHandleRequests()->latest()->limit(20)->get(),
        ];
    }

    public function bpjsPremiumForBaseDeposit(int $baseDeposit): int
    {
        return $baseDeposit >= self::BPJS_PREMI_FREE_MIN_BASE_DEPOSIT ? 0 : self::BPJS_PREMI_AMOUNT;
    }

    public function bpjsJhtForDriver(Driver $driver): int
    {
        return $driver->bpjs_jht_enabled ? self::BPJS_JHT_AMOUNT : 0;
    }

    public function enforceUnpaidSuspensions(SuspendService $suspensions): int
    {
        $count = 0;

        DriverDeposit::query()
            ->with('driver.user')
            ->where('status', 'unpaid')
            ->chunkById(100, function ($deposits) use (&$count, $suspensions): void {
                foreach ($deposits as $deposit) {
                    if (! $this->depositBlocksOrders($deposit)) {
                        continue;
                    }

                    if (
                        $deposit->driver
                        && ! in_array($deposit->driver->status, ['suspended_unpaid', 'permanent'], true)
                    ) {
                        $suspensions->suspendUnpaid($deposit->driver, self::UNPAID_SUSPEND_REASON);
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function depositBlocksOrders(?DriverDeposit $deposit, ?Carbon $now = null): bool
    {
        if (! $deposit || ($deposit->status ?? 'paid') === 'paid') {
            return false;
        }

        if ((bool) data_get($deposit->breakdown, 'manual_suspend_release', false)) {
            return false;
        }

        if ((bool) data_get($deposit->breakdown, 'before_driver_joined', false)) {
            return false;
        }

        $driver = $deposit->relationLoaded('driver') ? $deposit->driver : Driver::query()->find($deposit->driver_id);
        if ($driver instanceof Driver) {
            $periodEnd = Carbon::create((int) $deposit->year, (int) $deposit->month, 1)->endOfMonth();
            if ($this->periodIsBeforeDriverJoined($driver, $periodEnd)) {
                return false;
            }
        }

        $now ??= now();

        return $now->copy()->startOfDay()->greaterThanOrEqualTo($this->unpaidSuspendDateForDeposit($deposit));
    }

    public function unpaidSuspendDateForDeposit(DriverDeposit $deposit): Carbon
    {
        return Carbon::create((int) $deposit->year, (int) $deposit->month, 1)
            ->addMonthNoOverflow()
            ->day(self::UNPAID_SUSPEND_DAY)
            ->startOfDay();
    }

    private function unpaidSuspendDate(Carbon $month): Carbon
    {
        return $month->copy()
            ->addMonthNoOverflow()
            ->day(self::UNPAID_SUSPEND_DAY)
            ->startOfDay();
    }

    private function periodIsBeforeDriverJoined(Driver $driver, Carbon $periodEnd): bool
    {
        $joinedAt = $driver->created_at ?? $driver->user?->created_at;

        return $joinedAt instanceof Carbon
            && $joinedAt->copy()->startOfDay()->greaterThan($periodEnd->copy()->endOfDay());
    }

    private function depositStatus(int $total, int $paidAmount, Carbon $dueDate, ?string $currentStatus = null): string
    {
        if ($total <= 0) {
            return 'paid';
        }

        if ($paidAmount >= $total) {
            return 'paid';
        }

        return 'unpaid';
    }

    private function handleTotal(Driver $driver, Carbon $start, Carbon $end): int
    {
        $completedAtColumn = Schema::hasColumn('orders', 'completed_at') ? 'completed_at' : 'updated_at';

        return (int) Order::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'COMPLETED')
            ->whereBetween($completedAtColumn, [$start, $end])
            ->get(['source', 'price', 'service_charge', 'total_price', 'service_type', 'service_code', 'distance_km', 'stops', 'pricing_breakdown'])
            ->sum(fn (Order $order): int => $this->depositAmount($order));
    }

    private function handleTotalAfter(Driver $driver, Carbon $after, Carbon $end): int
    {
        $completedAtColumn = Schema::hasColumn('orders', 'completed_at') ? 'completed_at' : 'updated_at';

        return (int) Order::query()
            ->where('driver_id', $driver->id)
            ->where('status', OrderStatus::Completed->value)
            ->where($completedAtColumn, '>', $after)
            ->where($completedAtColumn, '<=', $end)
            ->get(['source', 'price', 'service_charge', 'total_price', 'service_type', 'service_code', 'distance_km', 'stops', 'pricing_breakdown'])
            ->sum(fn (Order $order): int => $this->depositAmount($order));
    }

    public function depositAmount(Order $order): int
    {
        if ($this->isJokerMobilOrder($order)) {
            return $this->jokerMobilDeposit($order);
        }

        if ($order->source === 'driver_request') {
            $jasa = (int) data_get(
                $order->pricing_breakdown,
                'request_deposit_jasa',
                data_get($order->pricing_breakdown, 'deposit_base', max(0, (int) $order->price + (int) $order->service_charge)),
            );

            return $this->depositFromJasa($jasa);
        }

        return $this->depositFromJasa(max(0, (int) $order->price) + max(0, (int) $order->service_charge));
    }

    private function depositFromJasa(int $jasa): int
    {
        if ($jasa < 12000) {
            return 1000;
        }

        if ($jasa < 18000) {
            return 2000;
        }

        return (int) floor(min($jasa, 60000) * 0.2);
    }

    private function isJokerMobilOrder(Order $order): bool
    {
        $service = strtolower(trim((string) ($order->service_code ?: $order->service_type)));

        return in_array($service, ['jm', 'joker_mobil', 'joker mobil'], true)
            || str_contains($service, 'joker mobil');
    }

    private function jokerMobilDeposit(Order $order): int
    {
        $distance = (float) ($order->distance_km ?: data_get($order->pricing_breakdown, 'distance', 0));
        $jasa = max(0, (int) ($order->total_price ?: $order->price));

        return app(JokerPricing::class)->depositAmount($jasa, $distance);
    }

    private function cashbackForPreviousDeposit(?DriverDeposit $previousDeposit, Carbon $period, int $previousBaseDeposit): int
    {
        if (! $previousDeposit || $previousBaseDeposit <= 0) {
            return 0;
        }

        if ($previousDeposit->status !== 'paid' || ! $previousDeposit->paid_at) {
            return 0;
        }

        $deadline = $period->copy()->day(7)->endOfDay();

        if ($previousDeposit->paid_at->greaterThan($deadline)) {
            return 0;
        }

        return (int) floor($previousBaseDeposit * 0.1);
    }

    private function bansos(Driver $driver): int
    {
        if ($driver->bansos_amount !== null) {
            return max(0, (int) $driver->bansos_amount);
        }

        $area = strtolower((string) ($driver->user?->branch?->area ?? $driver->user?->branch?->name ?? ''));

        foreach (self::BANSOS_BY_AREA as $key => $amount) {
            if (str_contains($area, $key)) {
                return $amount;
            }
        }

        return 0;
    }
}
