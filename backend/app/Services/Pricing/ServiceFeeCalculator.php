<?php

namespace App\Services\Pricing;

class ServiceFeeCalculator
{
    public function calculate(int $stops): int
    {
        return array_sum(array_column($this->breakdown($stops), 'fee'));
    }

    public function breakdown(int $stops): array
    {
        $breakdown = [];

        for ($point = 1; $point <= max(1, $stops); $point++) {
            $fee = match (true) {
                $point <= 2 => 1000,
                $point === 3 => 2000,
                default => 3000,
            };

            $breakdown[] = [
                'point' => $point,
                'label' => 'Titik '.$point,
                'fee' => $fee,
            ];
        }

        return $breakdown;
    }
}
