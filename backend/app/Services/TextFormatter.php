<?php

namespace App\Services;

class TextFormatter
{
    public function smartParserReply(array $parsed, array $quote): string
    {
        $money = fn (int|float|null $value): string => 'Rp '.number_format((int) $value, 0, ',', '.');

        return implode("\n", [
            'Pesanan Anda:',
            '',
            ...$this->detailLines($parsed),
            $this->locationLabel($parsed['service_type']),
            $parsed['store_location'] ?: '-',
            '',
            $this->destinationLabel($parsed['service_type']),
            $parsed['destination'] ?? $parsed['address'] ?? '-',
            '',
            'Breakdown Harga:',
            '- Tarif: '.$money($quote['tarif'] ?? $quote['price'] ?? 0),
            '- Service fee: '.$money($quote['service_fee'] ?? $quote['service_charge'] ?? 0),
            '- Tambahan: '.$money($quote['extra_charge'] ?? 0),
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

    private function destinationLabel(string $serviceType): string
    {
        return match ($serviceType) {
            'ojek' => 'Alamat antar:',
            'kurir' => 'Alamat tujuan:',
            default => 'Alamat antar/customer:',
        };
    }

    public function unrecognizedFormat(): string
    {
        return "Format belum dikenali, silakan gunakan format berikut:\n\nAda pesanan Delivery Order untuk Aplikasi Joker\n\nBelikan:\na : Nama item 1\nb : Nama item 2\n\nAlamat pembelian:\nArea: Nama toko / patokan";
    }
}
