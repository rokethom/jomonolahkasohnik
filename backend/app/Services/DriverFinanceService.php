<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\DriverDeposit;
use App\Models\Order;
use App\Services\Pricing\ServiceFeeCalculator;
use Illuminate\Support\Carbon;

class DriverFinanceService
{
    private const BANSOS_BY_AREA = [
        'situbondo' => 5000,
        'asembagus' => 10000,
        'banyuwangi' => 20000,
        'genteng' => 20000,
        'bondowoso' => 5000,
    ];

    public function __construct(private readonly ServiceFeeCalculator $serviceFees)
    {
    }

    public function monthlyDeposit(Driver $driver, ?Carbon $month = null): DriverDeposit
    {
        $month ??= now();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $previousPeriod = $month->copy()->subMonth();
        $handleDay15 = $this->handleTotal($driver, $start, $month->copy()->day(min(15, $end->day))->endOfDay());
        $handleDay30 = $this->handleTotal($driver, $month->copy()->day(min(16, $end->day))->startOfDay(), $end);
        $baseDeposit = $handleDay15 + $handleDay30;
        $previousDeposit = DriverDeposit::query()
            ->where('driver_id', $driver->id)
            ->where('year', $previousPeriod->year)
            ->where('month', $previousPeriod->month)
            ->first();
        $previousRemaining = max(0, (int) ($previousDeposit?->total ?? 0) - (int) ($previousDeposit?->paid_amount ?? 0));
        $previousBaseDeposit = (int) ($previousDeposit?->handle_day_15 ?? 0) + (int) ($previousDeposit?->handle_day_30 ?? 0);
        $cashback = $this->cashbackForPreviousDeposit($previousDeposit, $month, $previousBaseDeposit);
        $bansos = $this->bansos($driver);
        $bpjs = $baseDeposit < 30000 ? 20000 : 0;
        $bpjsJht = $driver->bpjs_jht_enabled ? 20000 : 0;
        $total = max(0, $baseDeposit + $previousRemaining - $cashback + $bansos + $bpjs + $bpjsJht);

        $existing = DriverDeposit::query()
            ->where('driver_id', $driver->id)
            ->where('year', (int) $month->year)
            ->where('month', (int) $month->month)
            ->first();
        $paidAmount = (int) ($existing?->paid_amount ?? 0);
        $paidAt = $existing?->paid_at;
        $status = $paidAmount >= $total && $total > 0 ? 'paid' : ($total <= 0 ? 'paid' : 'unpaid');

        return DriverDeposit::query()->updateOrCreate(
            ['driver_id' => $driver->id, 'year' => (int) $month->year, 'month' => (int) $month->month],
            [
                'handle_day_15' => $handleDay15,
                'handle_day_30' => $handleDay30,
                'bansos' => $bansos,
                'bpjs' => $bpjs,
                'bpjs_jht' => $bpjsJht,
                'total' => $total,
                'due_date' => $month->copy()->day(min(20, $end->day))->toDateString(),
                'paid_amount' => $paidAmount,
                'paid_at' => $paidAt,
                'status' => $status,
                'breakdown' => [
                    'handle_hari_15' => $handleDay15,
                    'handle_hari_30' => $handleDay30,
                    'setoran_hingga_hari_ini' => $baseDeposit,
                    'tagihan_bulan_sebelumnya' => $previousRemaining,
                    'cashback_bulan_sebelumnya' => $cashback,
                    'bansos' => $bansos,
                    'bpjs' => $bpjs,
                    'bpjs_jht' => $bpjsJht,
                ],
            ],
        );
    }

    public function performance(Driver $driver): array
    {
        $rating = $driver->ratings()->avg('rating');
        $deposit = $this->monthlyDeposit($driver);

        return [
            'rating' => round((float) $rating, 2),
            'ratings_count' => $driver->ratings()->count(),
            'setoran' => $deposit,
            'suspend_history' => $driver->suspensions()->latest()->limit(20)->get(),
            'oper_handle' => $driver->operHandleRequests()->latest()->limit(20)->get(),
        ];
    }

    public function enforceUnpaidSuspensions(SuspendService $suspensions): int
    {
        $count = 0;

        DriverDeposit::query()
            ->with('driver.user')
            ->where('status', 'unpaid')
            ->whereDate('due_date', '<', now()->toDateString())
            ->chunkById(100, function ($deposits) use (&$count, $suspensions): void {
                foreach ($deposits as $deposit) {
                    if ($deposit->driver) {
                        $suspensions->suspendUnpaid($deposit->driver, 'Belum bayar setoran lewat tanggal 20');
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function handleTotal(Driver $driver, Carbon $start, Carbon $end): int
    {
        return (int) Order::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'COMPLETED')
            ->whereBetween('created_at', [$start, $end])
            ->get(['source', 'price', 'service_charge'])
            ->sum(fn (Order $order): int => $this->depositAmount($order));
    }

    private function depositAmount(Order $order): int
    {
        $serviceCash = $order->source === 'driver_request'
            ? (int) data_get($order->pricing_breakdown, 'service_fee', $this->serviceFees->calculate(max(1, (int) $order->stops)))
            : (int) $order->service_charge;
        $jasa = max(0, (int) $order->price) + max(0, $serviceCash);

        if ($jasa < 12000) {
            return 1000;
        }

        if ($jasa < 18000) {
            return 2000;
        }

        return (int) floor(min($jasa, 60000) * 0.2);
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
