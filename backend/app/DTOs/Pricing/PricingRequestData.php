<?php

declare(strict_types=1);

namespace App\DTOs\Pricing;

final readonly class PricingRequestData
{
    public function __construct(
        public float $pickupLatitude,
        public float $pickupLongitude,
        public float $destinationLatitude,
        public float $destinationLongitude,
        public ?string $serviceType = null,
        public ?int $customerId = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            pickupLatitude: (float) $data['pickup_latitude'],
            pickupLongitude: (float) $data['pickup_longitude'],
            destinationLatitude: (float) $data['destination_latitude'],
            destinationLongitude: (float) $data['destination_longitude'],
            serviceType: isset($data['service_type']) ? (string) $data['service_type'] : null,
            customerId: isset($data['customer_id']) ? (int) $data['customer_id'] : null,
        );
    }
}
