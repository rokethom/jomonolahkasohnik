<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Service;

class OrderCodeGenerator
{
    private const MAX_ATTEMPTS = 10;

    public function generate(Service|string|null $service = null, Branch|string|null $branch = null): string
    {
        $serviceCode = $this->normalizeCode($service instanceof Service ? $service->code : ($service ?: 'JO'), 'JO');
        $areaCode = $branch instanceof Branch ? $this->branchCode($branch) : $this->normalizeCode($branch ?: 'APP', 'APP');
        $datetime = now()->format('ymdH');

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $code = "{$serviceCode}-{$areaCode}-{$datetime}-".$this->uniqueSuffix();

            if (! Order::query()->where('order_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Gagal generate kode order unik. Silakan coba lagi.');
    }

    public function generateRequest(Service|string|null $service = null): string
    {
        $serviceCode = $this->normalizeCode($service instanceof Service ? $service->code : ($service ?: 'JO'), 'JO');
        $datetime = now()->format('ymdH');

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $code = "{$serviceCode}-{$datetime}-REQ".$this->uniqueRequestSuffix();

            if (! Order::query()->where('order_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Gagal generate kode request order unik. Silakan coba lagi.');
    }

    private function branchCode(Branch $branch): string
    {
        if (filled($branch->branch_code)) {
            return $this->normalizeCode($branch->branch_code, 'APP', 6);
        }

        return $this->normalizeCode($branch->area ?: $branch->name, 'APP');
    }

    private function normalizeCode(string $value, string $fallback, int $length = 3): string
    {
        $letters = preg_replace('/[^a-z0-9]/i', '', $value) ?: $fallback;

        return strtoupper(substr($letters, 0, $length));
    }

    private function uniqueSuffix(): string
    {
        $micro = (int) floor(microtime(true) * 10000);
        $entropy = random_int(0, 9999);
        $unique = (string) ($micro + $entropy);

        return str_pad(substr($unique, -4), 4, '0', STR_PAD_LEFT);
    }

    private function uniqueRequestSuffix(): string
    {
        return str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }
}
