<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('title', 160);
            $table->text('body');
            $table->string('category', 40)->default('operasional')->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->string('status', 24)->default('open')->index();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('internal_note_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('internal_note_id')->constrained('internal_notes')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_note_replies');
        Schema::dropIfExists('internal_notes');
    }
};
