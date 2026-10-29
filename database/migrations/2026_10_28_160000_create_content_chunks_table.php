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
        Schema::create('content_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 32)->index(); // policy|page|article
            $table->string('source_key', 191)->index();
            $table->string('title');
            $table->string('url', 2048)->nullable();
            $table->unsignedInteger('chunk_index')->default(0);
            $table->longText('body');
            $table->timestamps();

            $table->unique(['shop_id', 'source_type', 'source_key', 'chunk_index'], 'content_chunks_unique');
        });

        if ($this->supportsNativeVector()) {
            DB::statement('ALTER TABLE content_chunks ADD COLUMN embedding VECTOR(768) NULL');
        } else {
            Schema::table('content_chunks', function (Blueprint $table): void {
                $table->json('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_chunks');
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
