<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('trace_id')->index();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 64)->index();
            $table->string('provider', 32)->nullable();
            $table->string('model', 128)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('estimated_cost_usd', 12, 8)->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('status', 32)->default('ok');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_audit_logs');
    }
};
