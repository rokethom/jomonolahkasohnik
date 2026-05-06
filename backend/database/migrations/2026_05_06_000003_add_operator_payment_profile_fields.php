<?php

use App\Models\AppSetting;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'profile_photo_path')) {
                $table->string('profile_photo_path')->nullable()->after('address');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method', 40)->nullable()->after('notes');
            }

            if (! Schema::hasColumn('orders', 'payment_label')) {
                $table->string('payment_label')->nullable()->after('payment_method');
            }

            if (! Schema::hasColumn('orders', 'payment_meta')) {
                $table->json('payment_meta')->nullable()->after('payment_label');
            }
        });

        Schema::table('chat_conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('chat_conversations', 'operator_rating')) {
                $table->unsignedTinyInteger('operator_rating')->nullable()->after('sla_status');
            }

            if (! Schema::hasColumn('chat_conversations', 'operator_rating_comment')) {
                $table->text('operator_rating_comment')->nullable()->after('operator_rating');
            }

            if (! Schema::hasColumn('chat_conversations', 'operator_rated_at')) {
                $table->timestamp('operator_rated_at')->nullable()->after('operator_rating_comment');
            }

            if (! Schema::hasColumn('chat_conversations', 'rating_requested_at')) {
                $table->timestamp('rating_requested_at')->nullable()->after('operator_rated_at');
            }
        });

        AppSetting::query()->firstOrCreate([
            'key' => 'payment_methods',
        ], [
            'value' => json_encode([
                ['key' => 'cash', 'label' => 'Pembayaran Cash', 'description' => 'Customer membayar manual kepada driver.'],
                ['key' => 'transfer', 'label' => 'Pembayaran Transfer', 'description' => 'Customer transfer ke rekening aplikasi.'],
            ]),
            'is_active' => true,
        ]);

        AppSetting::query()->firstOrCreate([
            'key' => 'payment_transfer_account',
        ], [
            'value' => json_encode([]),
            'is_active' => true,
        ]);

        AppSetting::query()->firstOrCreate([
            'key' => 'payment_qris_image',
        ], [
            'value' => null,
            'is_active' => true,
        ]);

        AppSetting::query()->firstOrCreate([
            'key' => 'complaint_whatsapp_number',
        ], [
            'value' => '6281299232918',
            'is_active' => true,
        ]);

        if (Schema::hasTable('roles') && Schema::hasTable('permissions') && Schema::hasTable('role_permissions')) {
            $role = Role::query()->firstOrCreate(['name' => 'eksekutor']);
            $permissions = collect([
                'monitor_live_order',
                'approve_cancel_order',
                'reject_cancel_order',
                'monitor_live_chat',
            ])->map(fn (string $name) => Permission::query()->firstOrCreate(['name' => $name]));

            $role->permissions()->syncWithoutDetaching($permissions->pluck('id')->all());
        }
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table): void {
            foreach (['rating_requested_at', 'operator_rated_at', 'operator_rating_comment', 'operator_rating'] as $column) {
                if (Schema::hasColumn('chat_conversations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            foreach (['payment_meta', 'payment_label', 'payment_method'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'profile_photo_path')) {
                $table->dropColumn('profile_photo_path');
            }
        });
    }
};
