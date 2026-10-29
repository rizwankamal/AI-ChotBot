<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_semantic_cache', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->string('query_hash', 64)->index();
            $table->text('query_text');
            $table->longText('response_text');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        if ($this->supportsNativeVector()) {
            DB::statement('ALTER TABLE ai_semantic_cache ADD COLUMN query_embedding VECTOR(768) NULL');
        } else {
            Schema::table('ai_semantic_cache', function (Blueprint $table): void {
                $table->json('query_embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_semantic_cache');
    }

    private function supportsNativeVector(): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        $version = (string) $connection->getServerVersion();

        if ($connection->isMaria()) {
            return version_compare($version, '11.7.0', '>=');
        }

        return version_compare($version, '9.0.0', '>=');
    }
};
