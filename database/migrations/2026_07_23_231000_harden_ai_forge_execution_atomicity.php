<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2c: unique cycle position per work packet (CAS-friendly atomicity).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_forge_work_packet_execution_cycles')) {
            return;
        }

        if ($this->hasIndex('ai_forge_work_packet_execution_cycles', 'ai_forge_cycles_packet_position_unique')) {
            return;
        }

        Schema::table('ai_forge_work_packet_execution_cycles', function (Blueprint $table): void {
            $table->unique(
                ['work_packet_id', 'cycle_position'],
                'ai_forge_cycles_packet_position_unique',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_forge_work_packet_execution_cycles')) {
            return;
        }
        if (! $this->hasIndex('ai_forge_work_packet_execution_cycles', 'ai_forge_cycles_packet_position_unique')) {
            return;
        }

        Schema::table('ai_forge_work_packet_execution_cycles', function (Blueprint $table): void {
            $table->dropUnique('ai_forge_cycles_packet_position_unique');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                $name = is_object($row) ? (string) ($row->name ?? '') : (string) ($row['name'] ?? '');
                if ($name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();
        $count = (int) $connection->table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->count();

        return $count > 0;
    }
};
