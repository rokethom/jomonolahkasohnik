<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class AnalyticsPeriod
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $preset,
    ) {}

    public static function fromFilters(?array $filters): self
    {
        $preset = (string) ($filters['period'] ?? 'today');
        $today = now()->startOfDay();

        return match ($preset) {
            '7_days' => new self($today->copy()->subDays(6), $today->copy(), $preset),
            '30_days' => new self($today->copy()->subDays(29), $today->copy(), $preset),
            'custom' => self::custom($filters ?? [], $today),
            default => new self($today->copy(), $today->copy(), 'today'),
        };
    }

    public function previous(): self
    {
        $days = $this->days();

        return new self(
            $this->from->copy()->subDays($days),
            $this->from->copy()->subDay(),
            'previous',
        );
    }

    public function days(): int
    {
        return $this->from->diffInDays($this->to) + 1;
    }

    public function cacheKey(): string
    {
        return $this->from->toDateString().':'.$this->to->toDateString();
    }

    private static function custom(array $filters, Carbon $today): self
    {
        $from = filled($filters['from'] ?? null) ? Carbon::parse($filters['from'])->startOfDay() : $today->copy();
        $to = filled($filters['to'] ?? null) ? Carbon::parse($filters['to'])->startOfDay() : $today->copy();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return new self($from, $to, 'custom');
    }
}
