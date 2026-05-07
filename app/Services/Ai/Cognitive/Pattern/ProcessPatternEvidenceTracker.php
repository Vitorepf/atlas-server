<?php

namespace App\Services\Ai\Cognitive\Pattern;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProcessPatternEvidenceTracker
{
    public function __construct(private readonly AtlasEvidenceLedger $ledger) {}

    /**
     * @param  array<int,string>  $outcomeEvidence
     * @return array<string,mixed>
     */
    public function apply(int $patternId, string $domain, string $outcome = 'partial', array $outcomeEvidence = [], ?string $reflection = null): array
    {
        if (! Schema::hasTable('process_pattern_applications')) {
            return ['schema_version' => 'atlas.cognitive.process_pattern_application.v1', 'status' => 'table_missing'];
        }

        $outcome = in_array($outcome, ['success', 'partial', 'failure', 'abandoned'], true) ? $outcome : 'partial';
        $now = now();
        $envelopeId = (string) Str::uuid();
        $id = DB::table('process_pattern_applications')->insertGetId([
            'process_pattern_id' => $patternId,
            'envelope_id' => $envelopeId,
            'domain' => $domain,
            'outcome' => $outcome,
            'outcome_evidence' => json_encode(array_values($outcomeEvidence), JSON_THROW_ON_ERROR),
            'reflection' => $reflection,
            'applied_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('process_patterns')->where('id', $patternId)->update([
            'applied_count' => DB::raw('applied_count + 1'),
            'success_count' => $outcome === 'success' ? DB::raw('success_count + 1') : DB::raw('success_count'),
            'failure_count' => $outcome === 'failure' ? DB::raw('failure_count + 1') : DB::raw('failure_count'),
            'last_applied_at' => $now,
            'updated_at' => $now,
        ]);

        $this->ledger->record(LedgerEventType::ProcessPatternApplied, [
            'schema_version' => 'atlas.cognitive.process_pattern_applied.v1',
            'process_pattern_id' => $patternId,
            'application_id' => $id,
            'domain' => $domain,
            'outcome' => $outcome,
            'outcome_evidence_count' => count($outcomeEvidence),
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_pattern_cli',
            'envelope_id' => $envelopeId,
            'correlation_id' => $envelopeId,
            'emitter_stage' => 'atlas.pattern.apply',
            'emitter_version' => 'atlas.cognitive.process_pattern.v1',
        ]);

        return [
            'schema_version' => 'atlas.cognitive.process_pattern_application.v1',
            'status' => 'applied',
            'id' => $id,
            'process_pattern_id' => $patternId,
            'envelope_id' => $envelopeId,
            'domain' => $domain,
            'outcome' => $outcome,
        ];
    }
}
