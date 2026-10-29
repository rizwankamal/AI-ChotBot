<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_id')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('inventory_count')->default(0);
            $table->string('category')->nullable();
            $table->timestamps();
        });

        $this->addEmbeddingColumn();
    }

    /**
     * Prefer a native VECTOR column when the server supports it.
     * Fall back to JSON so MariaDB 10.x / older MySQL can store embeddings.
     */
    private function addEmbeddingColumn(): void
    {
        if ($this->supportsNativeVector()) {
            DB::statement('ALTER TABLE products ADD COLUMN embedding VECTOR(768) NULL');

            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->json('embedding')->nullable();
        });
    }

    /**
     * MySQL 9.0+ and MariaDB 11.7+ can store a native VECTOR column.
     */
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
