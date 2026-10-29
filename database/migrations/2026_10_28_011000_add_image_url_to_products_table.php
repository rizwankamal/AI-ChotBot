<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'image_url')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('image_url', 2048)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'image_url')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('image_url');
        });
    }
};
