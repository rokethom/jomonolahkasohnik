<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (! Schema::hasColumn('services', 'whatsapp_redirect_enabled')) {
                $table->boolean('whatsapp_redirect_enabled')->default(false)->after('form_schema');
            }

            if (! Schema::hasColumn('services', 'whatsapp_number')) {
                $table->string('whatsapp_number', 32)->nullable()->after('whatsapp_redirect_enabled');
            }

            if (! Schema::hasColumn('services', 'whatsapp_message_template')) {
                $table->text('whatsapp_message_template')->nullable()->after('whatsapp_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            foreach (['whatsapp_message_template', 'whatsapp_number', 'whatsapp_redirect_enabled'] as $column) {
                if (Schema::hasColumn('services', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
