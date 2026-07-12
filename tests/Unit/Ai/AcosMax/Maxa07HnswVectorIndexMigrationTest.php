<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use Tests\TestCase;

final class Maxa07HnswVectorIndexMigrationTest extends TestCase
{
    public function test_vector_embedding_indexes_are_created_as_hnsw_not_ivfflat(): void
    {
        $migrationPaths = [
            database_path('migrations/2026_04_28_050000_create_semantic_memory_tables.php'),
            database_path('migrations/2026_05_01_170000_create_ai_attachment_index_entries_table.php'),
            database_path('migrations/2026_06_08_200000_align_semantic_embedding_dimension_to_real_model.php'),
            database_path('migrations/2026_06_09_120000_add_embedding_to_atlas_memory_tables.php'),
            database_path('migrations/2026_07_09_154000_repair_missing_atlas_memory_embedding_columns.php'),
            database_path('migrations/2026_07_12_031000_convert_vector_indexes_to_hnsw.php'),
        ];

        foreach ($migrationPaths as $path) {
            $source = (string) file_get_contents($path);
            $upSource = explode('public function down', $source, 2)[0];

            self::assertStringContainsString(
                'USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64)',
                $upSource,
                $path.' must build vector indexes with HNSW parameters.',
            );
            self::assertStringNotContainsString(
                'USING ivfflat (embedding vector_cosine_ops)',
                $upSource,
                $path.' must not create ivfflat vector indexes in the forward path.',
            );
        }
    }
}
