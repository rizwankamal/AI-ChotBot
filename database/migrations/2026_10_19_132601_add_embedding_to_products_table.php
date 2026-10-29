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
        if (Schema::hasColumn('products', 'embedding')) {
            return;
        }

        if ($this->supportsNativeVector()) {
            DB::statement('ALTER TABLE products ADD COLUMN embedding VECTOR(768) NULL');

            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->json('embedding')->nullable()->after('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('products', 'embedding')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('embedding');
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
};
