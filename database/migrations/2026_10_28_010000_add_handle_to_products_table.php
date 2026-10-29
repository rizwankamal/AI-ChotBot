<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'handle')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('handle')->nullable()->after('shopify_id')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'handle')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('handle');
        });
    }
};
