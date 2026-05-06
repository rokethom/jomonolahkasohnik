<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 10)->unique();
            $table->boolean('is_active')->default(true);
            $table->json('form_schema')->nullable();
            $table->timestamps();
        });

        $now = now();
        foreach ($this->defaults() as $service) {
            DB::table('services')->updateOrInsert(
                ['code' => $service['code']],
                [...$service, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }

    private function defaults(): array
    {
        $routeFields = [
            ['type' => 'text', 'name' => 'pickup', 'label' => 'Pickup'],
            ['type' => 'text', 'name' => 'destination', 'label' => 'Tujuan'],
            ['type' => 'textarea', 'name' => 'notes', 'label' => 'Catatan'],
        ];

        return [
            ['name' => 'Ojek', 'code' => 'OJ', 'is_active' => true, 'form_schema' => json_encode(['fields' => $routeFields])],
            ['name' => 'Kurir', 'code' => 'KR', 'is_active' => true, 'form_schema' => json_encode(['fields' => $routeFields])],
            ['name' => 'Delivery', 'code' => 'DO', 'is_active' => true, 'form_schema' => json_encode(['fields' => $routeFields])],
            ['name' => 'Belanja', 'code' => 'BL', 'is_active' => true, 'form_schema' => json_encode(['fields' => [
                ['type' => 'text', 'name' => 'store', 'label' => 'Lokasi toko'],
                ['type' => 'textarea', 'name' => 'items', 'label' => 'List barang'],
                ['type' => 'textarea', 'name' => 'notes', 'label' => 'Catatan'],
            ]])],
            ['name' => 'Gift Order', 'code' => 'GO', 'is_active' => true, 'form_schema' => json_encode(['fields' => [
                ['type' => 'text', 'name' => 'product', 'label' => 'Produk'],
                ['type' => 'text', 'name' => 'destination', 'label' => 'Alamat tujuan'],
                ['type' => 'textarea', 'name' => 'gift_message', 'label' => 'Pesan gift'],
            ]])],
            ['name' => 'Travel', 'code' => 'TV', 'is_active' => true, 'form_schema' => json_encode(['fields' => [
                ['type' => 'select', 'name' => 'route', 'label' => 'Rute', 'options' => ['ASB-STB', 'ASB-BWS', 'ASB-JBR', 'STB-BWS', 'STB-JBR', 'BWS-JBR']],
                ['type' => 'date', 'name' => 'date', 'label' => 'Tanggal'],
                ['type' => 'text', 'name' => 'seat', 'label' => 'Kursi'],
            ]])],
            ['name' => 'Joker Mobil', 'code' => 'JM', 'is_active' => true, 'form_schema' => json_encode(['fields' => $routeFields])],
        ];
    }
};
