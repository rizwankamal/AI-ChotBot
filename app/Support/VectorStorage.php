<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Detects whether products.embedding is a native VECTOR column and whether
 * SQL VECTOR_DISTANCE / DISTANCE is available (HeatWave / Percona, not Community).
 */
final class VectorStorage
{
    private static ?bool $usesNativeVector = null;

    private static ?bool $supportsDistanceFunction = null;

    public static function usesNativeVector(): bool
    {
        if (self::$usesNativeVector !== null) {
            return self::$usesNativeVector;
        }

        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'mysql') {
            return self::$usesNativeVector = false;
        }

        if (! Schema::hasColumn('products', 'embedding')) {
            return self::$usesNativeVector = false;
        }

        $database = $connection->getDatabaseName();

        $dataType = $connection->table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'products')
            ->where('COLUMN_NAME', 'embedding')
            ->value('DATA_TYPE');

        return self::$usesNativeVector = strtolower((string) $dataType) === 'vector';
    }

    /**
     * True when VECTOR_DISTANCE()/DISTANCE() exists (not in MySQL Community).
     */
    public static function supportsDistanceFunction(): bool
    {
        if (self::$supportsDistanceFunction !== null) {
            return self::$supportsDistanceFunction;
        }

        if (! self::usesNativeVector()) {
            return self::$supportsDistanceFunction = false;
        }

        try {
            DB::select(
                "SELECT VECTOR_DISTANCE(STRING_TO_VECTOR('[1]'), STRING_TO_VECTOR('[1]'), 'COSINE') AS d"
            );

            return self::$supportsDistanceFunction = true;
        } catch (Throwable) {
            try {
                DB::select(
                    "SELECT DISTANCE(STRING_TO_VECTOR('[1]'), STRING_TO_VECTOR('[1]'), 'COSINE') AS d"
                );

                return self::$supportsDistanceFunction = true;
            } catch (Throwable) {
                return self::$supportsDistanceFunction = false;
            }
        }
    }

    public static function flush(): void
    {
        self::$usesNativeVector = null;
        self::$supportsDistanceFunction = null;
    }

    /**
     * @param  array<int, float>  $vector
     */
    public static function toVectorString(array $vector): string
    {
        return '['.implode(',', array_map(
            static fn (float $value): string => rtrim(rtrim(sprintf('%.8F', $value), '0'), '.'),
            $vector,
        )).']';
    }

    /**
     * @param  array<int, float>  $vector
     */
    public static function saveProductEmbedding(int $productId, array $vector): void
    {
        $literal = self::toVectorString($vector);

        if (self::usesNativeVector()) {
            DB::update(
                'UPDATE products SET embedding = STRING_TO_VECTOR(?), updated_at = ? WHERE id = ?',
                [$literal, now(), $productId],
            );

            return;
        }

        DB::table('products')->where('id', $productId)->update([
            'embedding' => json_encode($vector, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<int, float>  $vector
     */
    public static function saveContentChunkEmbedding(int $chunkId, array $vector): void
    {
        $literal = self::toVectorString($vector);

        if (self::usesNativeVector() && Schema::hasColumn('content_chunks', 'embedding')) {
            $dataType = Schema::getConnection()
                ->table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
                ->where('TABLE_NAME', 'content_chunks')
                ->where('COLUMN_NAME', 'embedding')
                ->value('DATA_TYPE');

            if (strtolower((string) $dataType) === 'vector') {
                DB::update(
                    'UPDATE content_chunks SET embedding = STRING_TO_VECTOR(?), updated_at = ? WHERE id = ?',
                    [$literal, now(), $chunkId],
                );

                return;
            }
        }

        DB::table('content_chunks')->where('id', $chunkId)->update([
            'embedding' => json_encode($vector, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
}
