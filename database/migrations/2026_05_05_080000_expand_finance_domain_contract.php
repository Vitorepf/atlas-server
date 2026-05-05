<?php

use App\Services\Ai\Finance\AtlasFinanceDomainContract;
use App\Services\Ai\Finance\AtlasFinanceProfileFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_domain_profiles') || ! Schema::hasTable('ai_flow_profiles')) {
            return;
        }

        $now = now();
        $factory = new AtlasFinanceProfileFactory;

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => AtlasFinanceDomainContract::DOMAIN_ID],
            $this->withDomainJson($factory->domainProfile($now))
        );

        DB::table('ai_flow_profiles')
            ->where('id', 'finance.research')
            ->delete();

        foreach ($factory->flowProfiles() as $flow) {
            $exists = DB::table('ai_flow_profiles')->where('id', $flow['id'])->exists();
            $values = $this->withFlowJson([...$flow, 'updated_at' => $now]);

            if (! $exists) {
                $values['created_at'] = $now;
            }

            DB::table('ai_flow_profiles')->updateOrInsert(['id' => $flow['id']], $values);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_flow_profiles')) {
            return;
        }

        DB::table('ai_flow_profiles')
            ->whereIn('id', array_column((new AtlasFinanceProfileFactory)->flowProfiles(), 'id'))
            ->delete();
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function withDomainJson(array $values): array
    {
        foreach (['model_policy', 'context_policy', 'skill_policy', 'tool_policy', 'memory_policy', 'gate_policy', 'metadata'] as $column) {
            $values[$column] = json_encode($values[$column] ?? [], JSON_THROW_ON_ERROR);
        }

        return $values;
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function withFlowJson(array $values): array
    {
        foreach (['model_policy', 'context_policy', 'skill_policy', 'tool_policy', 'memory_policy', 'gate_policy', 'execution_policy', 'metadata'] as $column) {
            $values[$column] = json_encode($values[$column] ?? [], JSON_THROW_ON_ERROR);
        }

        return $values;
    }
};
