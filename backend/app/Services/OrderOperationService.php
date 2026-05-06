<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Carbon;

class OrderOperationService
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    public function closedStatus(?Carbon $time = null): array
    {
        $time ??= now();

        if (! $this->settings->bool('order_close_enabled', true)) {
            return ['closed' => false];
        }

        $start = $this->settings->get('order_close_start', '01:00') ?: '01:00';
        $end = $this->settings->get('order_close_end', '05:00') ?: '05:00';
        $closed = $this->timeInRange($this->minutes($time->format('H:i')), $this->timeToMinutes($start), $this->timeToMinutes($end));

        return [
            'closed' => $closed,
            'start' => $this->normalizeTime($start),
            'end' => $this->normalizeTime($end),
            'message' => $this->settings->get('order_close_message', 'Maaf, sistem order sedang tutup. Order dibuka kembali pukul {end}.') ?: 'Maaf, sistem order sedang tutup. Order dibuka kembali pukul {end}.',
        ];
    }

    public function closedMessage(?Carbon $time = null): ?string
    {
        $status = $this->closedStatus($time);
        if (! ($status['closed'] ?? false)) {
            return null;
        }

        return strtr((string) $status['message'], [
            '{start}' => (string) $status['start'],
            '{end}' => (string) $status['end'],
        ]);
    }

    public function nightTariff(int $baseTarif, ?int $branchId = null, ?Carbon $time = null): array
    {
        $time ??= now();

        if ($baseTarif <= 0 || ! $this->settings->bool('night_tariff_enabled', true)) {
            return ['amount' => 0, 'percent' => 0, 'rule' => null];
        }

        $branch = $branchId ? Branch::query()->find($branchId) : null;
        $area = strtolower((string) ($branch?->area ?: $branch?->name ?: ''));
        $minute = $this->minutes($time->format('H:i'));

        foreach ($this->nightRules() as $rule) {
            $areaKey = strtolower(trim((string) ($rule['area'] ?? '')));
            if ($areaKey !== '' && ! str_contains($area, $areaKey)) {
                continue;
            }

            if (! $this->timeInRange($minute, $this->timeToMinutes((string) $rule['start']), $this->timeToMinutes((string) $rule['end']))) {
                continue;
            }

            $percent = max(0, (int) ($rule['percent'] ?? 0));
            $rawAmount = (int) ceil($baseTarif * ($percent / 100));

            return [
                'amount' => $this->roundUpThousand($rawAmount),
                'percent' => $percent,
                'rule' => $rule,
            ];
        }

        return ['amount' => 0, 'percent' => 0, 'rule' => null];
    }

    public function nightRules(): array
    {
        $raw = $this->settings->get('night_tariff_rules');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (is_array($decoded) && $decoded !== []) {
            return array_values(array_filter($decoded, fn ($rule): bool => is_array($rule) && isset($rule['start'], $rule['end'], $rule['percent'])));
        }

        return [
            ['area' => 'bws', 'start' => '21:30', 'end' => '00:00', 'percent' => 30],
            ['area' => 'bondowoso', 'start' => '21:30', 'end' => '00:00', 'percent' => 30],
            ['area' => '', 'start' => '22:00', 'end' => '00:00', 'percent' => 30],
            ['area' => '', 'start' => '00:01', 'end' => '04:00', 'percent' => 50],
            ['area' => '', 'start' => '04:01', 'end' => '06:00', 'percent' => 30],
        ];
    }

    private function timeInRange(int $minute, int $start, int $end): bool
    {
        if ($start <= $end) {
            return $minute >= $start && $minute <= $end;
        }

        return $minute >= $start || $minute <= $end;
    }

    private function minutes(string $time): int
    {
        return $this->timeToMinutes($time);
    }

    private function timeToMinutes(string $time): int
    {
        [$hour, $minute] = array_pad(explode(':', $this->normalizeTime($time)), 2, 0);

        return ((int) $hour * 60) + (int) $minute;
    }

    private function normalizeTime(string $time): string
    {
        if (preg_match('/^(\d{1,2}):(\d{1,2})$/', trim($time), $match) !== 1) {
            return '00:00';
        }

        return sprintf('%02d:%02d', min(23, max(0, (int) $match[1])), min(59, max(0, (int) $match[2])));
    }

    private function roundUpThousand(int $amount): int
    {
        return $amount <= 0 ? 0 : (int) ceil($amount / 1000) * 1000;
    }
}
