<?php

namespace App\Services;

class TextFormatter
{
    public function smartParserReply(array $parsed, array $quote): string
    {
        $money = fn (int|float|null $value): string => 'Rp '.number_format((int) $value, 0, ',', '.');
        $helperFee = (int) ($quote['crew_helper_fee'] ?? $quote['helper_service_charge'] ?? data_get($quote, 'crew_decision.helper_fee', data_get($quote, 'crew_decision.helper_service_charge', 0)));
        $helperLabel = (string) data_get($quote, 'crew_decision.helper_label', 'Jasa helper');
        $crossRing = filled($quote['cross_ring'] ?? null)
            ? strtoupper(str_replace('_', ' ', (string) $quote['cross_ring']))
            : null;
        $breakdown = [
            'Breakdown Harga:',
            '- Tarif: '.$money($quote['tarif'] ?? $quote['price'] ?? 0),
            '- Service fee: '.$money($quote['service_fee'] ?? $quote['service_charge'] ?? 0),
            '- Tambahan: '.$money($quote['extra_charge'] ?? 0),
        ];

        if ($crossRing !== null) {
            array_splice($breakdown, 1, 0, ['- Cross ring: '.$crossRing]);
        }

        if ($helperFee > 0) {
            $breakdown[] = '- '.$helperLabel.': '.$money($helperFee);
        }

        return implode("\n", [
            'Pesanan Anda:',
            '',
            ...$this->detailLines($parsed),
            $this->locationLabel($parsed['service_type']),
            $this->locationText($parsed),
            '',
            $this->destinationLabel($parsed['service_type']),
            $this->destinationText($parsed),
            '',
            ...$breakdown,
            '',
            'Total: '.$money($quote['total_price'] ?? $quote['final_price'] ?? 0),
            '',
            'Apakah pesanan sudah benar? (YA / TIDAK)',
        ]);
    }

    private function detailLines(array $parsed): array
    {
        if ($parsed['service_type'] === 'ojek') {
            return [
                'Layanan: Ojek',
                'Jumlah penumpang: '.($parsed['passengers'] ?? 1),
                '',
            ];
        }

        if ($parsed['service_type'] === 'gift_order') {
            return [
                'Gift Order:',
                ...collect($parsed['items'])->map(fn (array $item): string => '- '.$item['name'].' x'.($item['quantity'] ?? 1))->all(),
                '',
                'Penerima: '.($parsed['receiver']['name'] ?? '-'),
                'Hp penerima: '.($parsed['receiver']['phone'] ?? '-'),
                'Alamat penerima: '.($parsed['receiver']['address'] ?? '-'),
                '',
            ];
        }

        if ($parsed['service_type'] === 'kurir') {
            return [
                'Kurir:',
                ...collect($parsed['items'])->map(fn (array $item): string => '- '.$item['name'].' x'.($item['quantity'] ?? 1))->all(),
                '',
                'Penerima: '.($parsed['receiver']['name'] ?? '-'),
                'Hp penerima: '.($parsed['receiver']['phone'] ?? '-'),
                '',
            ];
        }

        return [
            'Item:',
            ...collect($parsed['items'])->map(fn (array $item): string => '- '.$item['name'].(($item['quantity'] ?? 1) > 1 ? ' x'.$item['quantity'] : ''))->all(),
            '',
        ];
    }

    private function locationLabel(string $serviceType): string
    {
        return match ($serviceType) {
            'ojek' => 'Alamat jemput:',
            'kurir' => 'Tujuan pengiriman:',
            default => 'Lokasi pembelian:',
        };
    }

    private function locationText(array $parsed): string
    {
        if (($parsed['service_type'] ?? null) === 'ojek') {
            return $parsed['address'] ?? $parsed['pickup_address'] ?? $parsed['store_location'] ?? '-';
        }

        return $parsed['store_location'] ?? $parsed['pickup_address'] ?? '-';
    }

    private function destinationText(array $parsed): string
    {
        if (($parsed['service_type'] ?? null) === 'ojek') {
            return $parsed['destination'] ?? $parsed['store_location'] ?? $parsed['destination_address'] ?? '-';
        }

        return $parsed['destination'] ?? $parsed['address'] ?? $parsed['destination_address'] ?? '-';
    }

    private function destinationLabel(string $serviceType): string
    {
        return match ($serviceType) {
            'ojek' => 'Alamat antar:',
            'kurir' => 'Alamat tujuan:',
            default => 'Alamat antar:',
        };
    }

    public function unrecognizedFormat(): string
    {
        return "Format belum dikenali, silakan gunakan format berikut:\n\nAda pesanan Delivery Order untuk Aplikasi Joker\n\nBelikan:\na : Nama item 1\nb : Nama item 2\n\nAlamat pembelian:\nArea: Nama toko / patokan";
    }
}
