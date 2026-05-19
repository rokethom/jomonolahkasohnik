<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('keyword_parsers')) {
            return;
        }

        $now = now();
        $rows = [
            [
                'keyword' => 'ojek, ojol, antar orang, jemput, ride, motor',
                'service_type' => 'OJ',
                'response_template' => 'Baik, silakan lengkapi form Ojek.',
                'form_schema' => $this->schema(['Alamat Jemput', 'Alamat Antar', 'Jumlah Penumpang', 'Preferensi Driver']),
                'parser_type' => 'advanced',
                'priority' => 100,
            ],
            [
                'keyword' => 'delivery, do, pesan makanan, beli makanan, belikan, order makanan',
                'service_type' => 'DO',
                'response_template' => 'Baik, silakan tulis alamat pembelian, pesanan, dan alamat antar.',
                'form_schema' => $this->schema(['Alamat Pembelian', 'Pembelian', 'Alamat Antar']),
                'parser_type' => 'advanced',
                'priority' => 95,
            ],
            [
                'keyword' => 'belanja, belikan barang, beli barang, pasar, toko',
                'service_type' => 'BL',
                'response_template' => 'Baik, silakan tulis daftar belanja, lokasi pembelian, dan alamat antar.',
                'form_schema' => $this->schema(['Lokasi Pembelian', 'Daftar Belanja', 'Alamat Antar']),
                'parser_type' => 'advanced',
                'priority' => 90,
            ],
            [
                'keyword' => 'kurir, kirim barang, antar barang, paket, dokumen',
                'service_type' => 'KR',
                'response_template' => 'Baik, silakan lengkapi alamat ambil, alamat antar, dan detail barang.',
                'form_schema' => $this->schema(['Alamat Ambil', 'Alamat Antar', 'Jenis Barang']),
                'parser_type' => 'advanced',
                'priority' => 85,
            ],
            [
                'keyword' => 'joker mobil, mobil, citycar, car ride, penumpang mobil',
                'service_type' => 'JM',
                'response_template' => 'Baik, silakan lengkapi alamat jemput, tujuan, dan jumlah penumpang Joker Mobil.',
                'form_schema' => $this->schema(['Alamat Jemput', 'Alamat Antar', 'Jumlah Penumpang']),
                'parser_type' => 'advanced',
                'priority' => 80,
            ],
            [
                'keyword' => 'gift, gift order, kado, hadiah',
                'service_type' => 'GO',
                'response_template' => 'Baik, silakan tulis detail hadiah, alamat pembelian, dan alamat antar.',
                'form_schema' => $this->schema(['Detail Hadiah', 'Alamat Pembelian', 'Alamat Antar']),
                'parser_type' => 'advanced',
                'priority' => 70,
            ],
        ];

        foreach ($rows as $row) {
            DB::table('keyword_parsers')->updateOrInsert(
                ['keyword' => $row['keyword']],
                [
                    ...$row,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('keyword_parsers')) {
            return;
        }

        DB::table('keyword_parsers')
            ->whereIn('keyword', [
                'ojek, ojol, antar orang, jemput, ride, motor',
                'delivery, do, pesan makanan, beli makanan, belikan, order makanan',
                'belanja, belikan barang, beli barang, pasar, toko',
                'kurir, kirim barang, antar barang, paket, dokumen',
                'joker mobil, mobil, citycar, car ride, penumpang mobil',
                'gift, gift order, kado, hadiah',
            ])
            ->delete();
    }

    private function schema(array $labels): string
    {
        return json_encode([
            'fields' => collect($labels)
                ->map(fn (string $label): array => [
                    'label' => $label,
                    'name' => str($label)->lower()->slug('_')->toString(),
                    'type' => 'text',
                    'required' => true,
                    'options' => [],
                ])
                ->all(),
        ]);
    }
};
