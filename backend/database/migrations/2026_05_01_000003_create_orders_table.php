<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_code')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_type');
            $table->string('pickup_address');
            $table->decimal('pickup_lat', 11, 8);
            $table->decimal('pickup_lng', 11, 8);
            $table->string('destination_address');
            $table->decimal('destination_lat', 11, 8);
            $table->decimal('destination_lng', 11, 8);
            $table->unsignedInteger('price');
            $table->unsignedInteger('service_charge')->default(0);
            $table->unsignedInteger('total_price');
            $table->string('status')->default(OrderStatus::Created->value)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
