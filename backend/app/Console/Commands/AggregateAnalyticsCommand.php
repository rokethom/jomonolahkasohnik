<?php

namespace App\Console\Commands;

use App\Jobs\AggregateAnalyticsJob;
use App\Services\AnalyticsAggregationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AggregateAnalyticsCommand extends Command
{
    protected $signature = 'analytics:aggregate
        {--date= : Tanggal tunggal (Y-m-d)}
        {--from= : Tanggal awal (Y-m-d)}
        {--to= : Tanggal akhir (Y-m-d)}
        {--days=1 : Jumlah hari ke belakang bila tanggal tidak diberikan}
        {--queue : Jalankan melalui queue worker}';

    protected $description = 'Mengagregasi analytics harian JojoApp untuk widget Filament.';

    public function handle(AnalyticsAggregationService $analytics): int
    {
        [$from, $to] = $this->resolveRange();

        if ($this->option('queue')) {
            AggregateAnalyticsJob::dispatch($from->toDateString(), $to->toDateString());
            $this->info("Aggregation diantrikan: {$from->toDateString()} s/d {$to->toDateString()}.");

            return self::SUCCESS;
        }

        $count = $analytics->aggregateRange($from, $to);
        $this->info("Aggregation selesai untuk {$count} hari: {$from->toDateString()} s/d {$to->toDateString()}.");

        return self::SUCCESS;
    }

    private function resolveRange(): array
    {
        if (filled($this->option('date'))) {
            $date = Carbon::parse((string) $this->option('date'))->startOfDay();

            return [$date, $date->copy()];
        }

        if (filled($this->option('from')) || filled($this->option('to'))) {
            $from = Carbon::parse((string) ($this->option('from') ?: $this->option('to')))->startOfDay();
            $to = Carbon::parse((string) ($this->option('to') ?: $this->option('from')))->startOfDay();

            return $from->lte($to) ? [$from, $to] : [$to, $from];
        }

        $to = now()->startOfDay();
        $days = max(1, (int) $this->option('days'));

        return [$to->copy()->subDays($days - 1), $to];
    }
}
