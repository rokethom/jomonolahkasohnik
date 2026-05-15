<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\DriverDeposit;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Pricing\ServiceFeeCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DriverReportService
{
    public function __construct(private readonly ServiceFeeCalculator $serviceFees)
    {
    }

    public function summary(int $days = 5): Collection
    {
        $from = CarbonImmutable::now()->subDays(max(1, $days) - 1)->startOfDay();

        return Order::query()
            ->with('driver.user')
            ->whereNotNull('driver_id')
            ->where('created_at', '>=', $from)
            ->where('status', OrderStatus::Completed->value)
            ->get()
            ->groupBy('driver_id')
            ->map(function (Collection $orders): array {
                $driver = $orders->first()->driver;
                $income = (int) $orders->sum('price');
                $potongan = (int) $orders->sum(fn (Order $order): int => $this->deduction($order));

                return [
                    'driver_id' => $driver?->id,
                    'driver' => $driver?->user?->name ?? 'Driver #'.$orders->first()->driver_id,
                    'total_order' => $orders->count(),
                    'total_income' => $income,
                    'total_potongan' => $potongan,
                    'total_setoran' => $income - $potongan,
                ];
            })
            ->values();
    }

    public function deduction(Order $order): int
    {
        $distance = (float) ($order->distance_km ?? data_get($order->pricing_breakdown, 'distance', 0));
        $tarif = (int) $order->price;

        if ($distance > 0 && $distance <= 5) {
            return 1000;
        }

        if ($distance > 0 && $distance <= 10) {
            return 2000;
        }

        return min(10000, (int) ceil($tarif * 0.2));
    }

    public function monthlyDepositRows(?int $month = null, ?int $year = null, ?User $actor = null): Collection
    {
        $paymentPeriod = Carbon::create($year ?: now()->year, $month ?: now()->month, 1)->startOfMonth();
        $earningPeriod = $paymentPeriod->copy()->subMonth();
        $previous = $earningPeriod->copy()->subMonth();
        $start = $earningPeriod->copy()->startOfMonth();
        $end = $earningPeriod->copy()->endOfMonth();

        return Driver::query()
            ->with(['user.branch'])
            ->whereHas('user')
            ->when($actor !== null && $this->scopedBranchIds($actor) !== null, function (Builder $query) use ($actor): Builder {
                return $query->whereHas('user', fn (Builder $query) => $query->whereIn('branch_id', $this->scopedBranchIds($actor) ?? []));
            })
            ->get()
            ->map(function (Driver $driver) use ($paymentPeriod, $earningPeriod, $previous, $start, $end): array {
                $orders = Order::query()
                    ->where('driver_id', $driver->id)
                    ->where('status', OrderStatus::Completed->value)
                    ->whereBetween('created_at', [$start, $end])
                    ->get(['id', 'source', 'price', 'service_charge', 'total_price', 'service_type', 'service_code', 'distance_km', 'stops', 'pricing_breakdown']);

                $finance = app(DriverFinanceService::class);
                $deposit = $finance->monthlyDeposit($driver, $earningPeriod->copy());

                $previousDeposit = DriverDeposit::query()
                    ->where('driver_id', $driver->id)
                    ->where('year', $previous->year)
                    ->where('month', $previous->month)
                    ->first();

                $setoranJasaDasar = (int) ($deposit?->handle_day_15 ?? 0) + (int) ($deposit?->handle_day_30 ?? 0);
                if ($setoranJasaDasar <= 0) {
                    $setoranJasaDasar = (int) $orders->sum(fn (Order $order): int => $this->depositAmount($order));
                }

                $breakdown = $deposit?->breakdown ?? [];
                $tagihanBulanLalu = data_get($breakdown, 'manual_previous_bill');
                $tagihanBulanLalu = $tagihanBulanLalu !== null
                    ? max(0, (int) $tagihanBulanLalu)
                    : max(0, (int) ($previousDeposit?->total ?? 0) - (int) ($previousDeposit?->paid_amount ?? 0));
                $previousBaseDeposit = (int) ($previousDeposit?->handle_day_15 ?? 0) + (int) ($previousDeposit?->handle_day_30 ?? 0);
                $rewardCashbackBulanLalu = data_get($breakdown, 'manual_previous_cashback_reward');
                $rewardCashbackBulanLalu = $rewardCashbackBulanLalu !== null
                    ? max(0, (int) $rewardCashbackBulanLalu)
                    : $this->cashbackForPreviousDeposit($previousDeposit, $previousBaseDeposit);
                $baseServiceOmset = (int) (data_get($breakdown, 'manual_base_service_omset') ?? $orders->sum('price'));
                $billBeforeBansos = (int) (data_get($breakdown, 'manual_bill_before_bansos') ?? max(0, $setoranJasaDasar + $tagihanBulanLalu + (int) ($deposit?->bpjs_jht ?? 0) + (int) ($deposit?->bpjs ?? 0) - $rewardCashbackBulanLalu));
                $totalTagihan = (int) (data_get($breakdown, 'manual_total_bill') ?? max(0, $billBeforeBansos + (int) ($deposit?->bansos ?? 0)));
                $terbayar = (int) ($deposit?->paid_amount ?? 0);
                $remainingBill = (int) (data_get($breakdown, 'manual_remaining_bill') ?? max(0, $totalTagihan - $terbayar));

                return [
                    'driver_id' => $driver->id,
                    'deposit_id' => $deposit?->id,
                    'driver' => $driver->user?->name ?? 'Driver #'.$driver->id,
                    'area' => $driver->user?->branch?->area ?? $driver->user?->branch?->name ?? '-',
                    'orders_count' => (int) (data_get($breakdown, 'manual_orders_count') ?? $orders->count()),
                    'base_service_omset' => $baseServiceOmset,
                    'base_service_deposit' => $setoranJasaDasar,
                    'previous_bill' => $tagihanBulanLalu,
                    'bpjs_jht' => (int) ($deposit?->bpjs_jht ?? 0),
                    'bpjs' => (int) ($deposit?->bpjs ?? 0),
                    'previous_cashback_reward' => $rewardCashbackBulanLalu,
                    'bill_before_bansos' => $billBeforeBansos,
                    'bansos' => (int) ($deposit?->bansos ?? 0),
                    'total_bill' => $totalTagihan,
                    'paid_amount' => $terbayar,
                    'remaining_bill' => $remainingBill,
                    'paid_at' => $deposit?->paid_at?->format('n/j/Y'),
                    'status' => $deposit?->status ?? 'unpaid',
                    'manual_override' => (bool) data_get($deposit?->breakdown, 'manual_override', false),
                    'next_cashback' => (int) (data_get($breakdown, 'manual_next_cashback') ?? $this->cashbackForPreviousDeposit($deposit, $setoranJasaDasar)),
                    'payment_period' => $paymentPeriod->format('Y-m'),
                    'earning_period' => $earningPeriod->format('Y-m'),
                ];
            })
            ->sortBy('driver')
            ->values();
    }

    public function monthlyDepositHeaders(Carbon $period): array
    {
        return [
            'DRIVER',
            'AREA',
            'JML ORDER BULAN LALU',
            'Omset Jasa Dasar Bulan Lalu',
            'Setoran dari Jasa Dasar Bulan Lalu',
            'Tagihan Bln Lalu',
            'JHT BPJSTK',
            'Premi BPJSTK',
            'Reward Cashback Bulan Lalu',
            'Total Tagihan',
            'Bansos Area',
            'Total Tagihan '.strtoupper($period->translatedFormat('F')),
            'Terbayar',
            'Sisa Tagihan',
            'Tgl Bayar',
            'cashback 10% utk Bulan Depan',
        ];
    }

    private function depositAmount(Order $order): int
    {
        return app(DriverFinanceService::class)->depositAmount($order);
    }

    private function cashbackForPreviousDeposit(?DriverDeposit $previousDeposit, int $previousBaseDeposit): int
    {
        $manualNextCashback = data_get($previousDeposit?->breakdown, 'manual_next_cashback');
        if ($manualNextCashback !== null) {
            return max(0, (int) $manualNextCashback);
        }

        if (! $previousDeposit || $previousBaseDeposit <= 0) {
            return 0;
        }

        if ($previousDeposit->status !== 'paid' || ! $previousDeposit->paid_at) {
            return 0;
        }

        if ((int) $previousDeposit->paid_at->day >= 7) {
            return 0;
        }

        return (int) floor($previousBaseDeposit * 0.1);
    }

    /**
     * @return array<int, int>|null Null means global scope.
     */
    private function scopedBranchIds(User $actor): ?array
    {
        $role = $actor->role?->value ?? (string) $actor->role;

        if (in_array($role, ['admin', 'gm', 'operator'], true)) {
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

        return Branch::expandToOperationalAreaIds($branchIds);
    }
}
