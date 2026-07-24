<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use App\Models\HermesProcedureCandidate;
use App\Services\Ai\Hermes\Support\HermesStringListNormalizer;
use App\Services\Ai\Skills\Governance\SkillPackPromotionGate;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class HermesProcedureAdapter
{
    use HermesAdapterReceipt;
    use HermesWorkspaceHelper;

    /**
     * @param  array<string,mixed>  $resultPacket
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @return array<string,mixed>
     */
    public function persistCandidates(AiJob $job, array $resultPacket, array $mission, array $invocation, string $procedurePolicy): array
    {
        $candidates = data_get($resultPacket, 'procedure_gate.candidates', []);
        $candidates = is_array($candidates) ? array_values($candidates) : [];
        $receipt = [
            'schema_version' => 'atlas.hermes.procedure_adapter_receipt.v1',
            'adapter' => 'hermes_procedure_adapter',
            'procedure_policy' => $procedurePolicy,
            'canonical_skill_authority' => 'atlas',
            'atlas_skill_gate' => 'hermes_procedure_candidates',
            'promotion_gate' => 'SkillPackPromotionGate',
            'promotion_allowed_now' => false,
            'candidate_count' => count($candidates),
            'eligible_candidate_count' => 0,
            'persisted_count' => 0,
            'duplicate_count' => 0,
            'skipped_count' => 0,
            'persisted_candidate_ids' => [],
            'duplicate_candidate_ids' => [],
            'skipped_candidates' => [],
            'status' => 'no_candidates',
        ];

        if ($candidates === []) {
            return $this->withReceiptHash($receipt);
        }

        if ($procedurePolicy !== 'atlas_adapter') {
            $receipt['status'] = 'skipped_by_policy';
            $receipt['skipped_count'] = count($candidates);
            $receipt['skipped_candidates'] = $this->skippedCandidates($candidates, 'procedure_policy_not_atlas_adapter');

            return $this->withReceiptHash($receipt);
        }

        if (! DatabaseTableAvailability::has('hermes_procedure_candidates')) {
            $receipt['status'] = 'procedure_gate_unavailable';
            $receipt['skipped_count'] = count($candidates);
            $receipt['skipped_candidates'] = $this->skippedCandidates($candidates, 'hermes_procedure_candidates_table_missing');

            return $this->withReceiptHash($receipt);
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $candidateId = $this->string($candidate['candidate_id'] ?? null, 120) ?: 'hermes_procedure_candidate_unknown';
            $name = $this->string($candidate['name'] ?? null, 160);
            $purpose = $this->string($candidate['purpose'] ?? null, 700);
            if ($name === null || $purpose === null) {
                $receipt['skipped_count']++;
                $receipt['skipped_candidates'][] = [
                    'candidate_id' => $candidateId,
                    'reason' => 'missing_name_or_purpose',
                ];

                continue;
            }

            $receipt['eligible_candidate_count']++;
            $workspace = $this->workspace($job);
            $candidateHash = $this->candidateHash($candidate, $workspace);
            $existing = $this->duplicateCandidate($candidateHash);
            if ($existing instanceof HermesProcedureCandidate) {
                $receipt['duplicate_count']++;
                $receipt['duplicate_candidate_ids'][] = $existing->id;

                continue;
            }

            $riskLevel = $this->riskLevel($candidate);
            $row = HermesProcedureCandidate::query()->create([
                'candidate_hash' => $candidateHash,
                'status' => 'persisted_for_review',
                'source_status' => 'hermes_session',
                'risk_level' => $riskLevel,
                'promotion_allowed' => false,
                'review_required' => true,
                'payload_json' => $this->payload($candidate),
                'evidence_refs_json' => $this->evidence($candidate, $resultPacket, $mission, $invocation),
                'promotion_gate_json' => $this->promotionGate($candidate),
                'reviewed_at' => null,
                'expires_at' => now()->addDays(90),
            ]);

            $receipt['persisted_count']++;
            $receipt['persisted_candidate_ids'][] = $row->id;
        }

        $receipt['status'] = $receipt['persisted_count'] > 0
            ? 'persisted_for_review'
            : ($receipt['duplicate_count'] > 0 ? 'deduplicated' : 'skipped_by_gate');

        return $this->withReceiptHash($receipt);
    }

    /**
     * @param  array<int,mixed>  $candidates
     * @return array<int,array<string,string>>
     */
    private function skippedCandidates(array $candidates, string $reason): array
    {
        return collect($candidates)
            ->filter(fn (mixed $candidate): bool => is_array($candidate))
            ->map(fn (array $candidate): array => [
                'candidate_id' => $this->string($candidate['candidate_id'] ?? null, 120) ?: 'hermes_procedure_candidate_unknown',
                'reason' => $reason,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function candidateHash(array $candidate, ?string $workspace): string
    {
        $name = $this->string($candidate['name'] ?? null, 160) ?? '';
        $purpose = $this->string($candidate['purpose'] ?? null, 700) ?? '';
        $riskLevel = $this->riskLevel($candidate);
        $candidateId = $this->string($candidate['candidate_id'] ?? null, 120) ?? '';

        return hash('sha256', $name.'|'.$purpose.'|'.$riskLevel.'|'.((string) $workspace).'|'.$candidateId);
    }

    private function duplicateCandidate(string $candidateHash): ?HermesProcedureCandidate
    {
        return HermesProcedureCandidate::query()
            ->where('candidate_hash', $candidateHash)
            ->first();
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function payload(array $candidate): array
    {
        return [
            'schema_version' => 'atlas.hermes.procedure_candidate.v1',
            'name' => $this->string($candidate['name'] ?? null, 160),
            'purpose' => $this->string($candidate['purpose'] ?? null, 700),
            'steps' => HermesStringListNormalizer::bounded($candidate['steps'] ?? null, 12, 700),
            'required_tools' => HermesStringListNormalizer::bounded($candidate['required_tools'] ?? null, 12, 120),
            'risk_level' => $this->riskLevel($candidate),
            'source' => $this->string($candidate['source'] ?? null, 80) ?: 'hermes_session',
            'gate_status' => 'persisted_for_atlas_skill_review',
            'review_status' => 'pending',
            'duplicate_check_required' => true,
            'promotion_allowed_now' => false,
            'promotion_requires_atlas_skill_gate' => true,
            'promotion_gate' => 'SkillPackPromotionGate',
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function promotionGate(array $candidate): array
    {
        $riskLevel = $this->riskLevel($candidate);

        return [
            'gate' => 'SkillPackPromotionGate',
            'schema_version' => SkillPackPromotionGate::SCHEMA_VERSION,
            'promotion_allowed_now' => false,
            'requires' => [
                'canon_docs/owner_doc',
                '>=3 quality_gates',
                'evidence_contracts',
                'semver version',
                'per-skill policy_class',
                'baseline_or_test',
            ],
            'risk_level' => $riskLevel,
            'danger_requires_operator_authority' => $riskLevel === 'high',
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $resultPacket
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @return array<int,array<string,mixed>>
     */
    private function evidence(array $candidate, array $resultPacket, array $mission, array $invocation): array
    {
        $evidence = is_array($candidate['evidence'] ?? null) ? array_values($candidate['evidence']) : [];
        $evidence[] = [
            'kind' => 'hermes_result_packet',
            'candidate_id' => $candidate['candidate_id'] ?? null,
            'result_id' => $resultPacket['result_id'] ?? null,
            'result_hash' => $resultPacket['result_hash'] ?? null,
            'mission_id' => $mission['mission_id'] ?? null,
            'mission_hash' => $mission['mission_hash'] ?? null,
            'cli_invocation_hash' => $this->hashValue($invocation),
            'promotion_allowed_now' => false,
        ];

        return array_values(array_filter($evidence, fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function riskLevel(array $candidate): string
    {
        $riskLevel = $this->string($candidate['risk_level'] ?? null, 16) ?: 'medium';

        return in_array($riskLevel, ['low', 'medium', 'high'], true) ? $riskLevel : 'medium';
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
