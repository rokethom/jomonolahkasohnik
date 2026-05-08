<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\DriverDeposit;
use App\Models\Order;
use App\Services\Pricing\ServiceFeeCalculator;
use Carbon\CarbonImmutable;
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

    public function monthlyDepositRows(?int $month = null, ?int $year = null): Collection
    {
        $period = Carbon::create($year ?: now()->year, $month ?: now()->month, 1)->startOfMonth();
        $previous = $period->copy()->subMonth();
        $start = $period->copy()->startOfMonth();
        $end = $period->copy()->endOfMonth();

        return Driver::query()
            ->with(['user.branch'])
            ->whereHas('user')
            ->get()
            ->map(function (Driver $driver) use ($period, $previous, $start, $end): array {
                $orders = Order::query()
                    ->where('driver_id', $driver->id)
                    ->where('status', OrderStatus::Completed->value)
                    ->whereBetween('created_at', [$start, $end])
                    ->get(['id', 'source', 'price', 'service_charge', 'total_price', 'service_type', 'service_code', 'distance_km', 'stops', 'pricing_breakdown']);

                app(DriverFinanceService::class)->monthlyDeposit($driver, $period->copy());

                $deposit = DriverDeposit::query()
                    ->where('driver_id', $driver->id)
                    ->where('year', $period->year)
                    ->where('month', $period->month)
                    ->first();

                $previousDeposit = DriverDeposit::query()
                    ->where('driver_id', $driver->id)
                    ->where('year', $previous->year)
                    ->where('month', $previous->month)
                    ->first();

                $setoranJasaDasar = (int) ($deposit?->handle_day_15 ?? 0) + (int) ($deposit?->handle_day_30 ?? 0);
                if ($setoranJasaDasar <= 0) {
                    $setoranJasaDasar = (int) $orders->sum(fn (Order $order): int => $this->depositAmount($order));
                }

                $tagihanBulanLalu = max(0, (int) ($previousDeposit?->total ?? 0) - (int) ($previousDeposit?->paid_amount ?? 0));
                $previousBaseDeposit = (int) ($previousDeposit?->handle_day_15 ?? 0) + (int) ($previousDeposit?->handle_day_30 ?? 0);
                $rewardCashbackBulanLalu = $this->cashbackForPreviousDeposit($previousDeposit, $period, $previousBaseDeposit);
                $totalTagihan = max(0, $setoranJasaDasar + $tagihanBulanLalu + (int) ($deposit?->bpjs_jht ?? 0) + (int) ($deposit?->bpjs ?? 0) + (int) ($deposit?->bansos ?? 0) - $rewardCashbackBulanLalu);
                $terbayar = (int) ($deposit?->paid_amount ?? 0);

                return [
                    'driver' => $driver->user?->name ?? 'Driver #'.$driver->id,
                    'area' => $driver->user?->branch?->area ?? $driver->user?->branch?->name ?? '-',
                    'orders_count' => $orders->count(),
                    'base_service_omset' => (int) $orders->sum('price'),
                    'base_service_deposit' => $setoranJasaDasar,
                    'previous_bill' => $tagihanBulanLalu,
                    'bpjs_jht' => (int) ($deposit?->bpjs_jht ?? 0),
                    'bpjs' => (int) ($deposit?->bpjs ?? 0),
                    'previous_cashback_reward' => $rewardCashbackBulanLalu,
                    'bill_before_bansos' => max(0, $setoranJasaDasar + $tagihanBulanLalu + (int) ($deposit?->bpjs_jht ?? 0) + (int) ($deposit?->bpjs ?? 0) - $rewardCashbackBulanLalu),
                    'bansos' => (int) ($deposit?->bansos ?? 0),
                    'total_bill' => $totalTagihan,
                    'paid_amount' => $terbayar,
                    'remaining_bill' => max(0, $totalTagihan - $terbayar),
                    'paid_at' => $deposit?->paid_at?->format('n/j/Y'),
                    'next_cashback' => (int) floor(($setoranJasaDasar * 10) / 100),
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
            'JML ORDER',
            'Omset Dari Jasa Dasar',
            'Setoran 20% dari Jasa Dasar',
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
}
