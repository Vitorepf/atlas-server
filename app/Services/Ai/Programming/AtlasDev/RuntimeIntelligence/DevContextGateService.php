<?php

namespace App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Models\AtlasDevContextGate;
use App\Models\AtlasDevTaskPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

class DevContextGateService
{
    public const SCHEMA_VERSION = 'atlas.dev.context_gate.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    public function evaluate(array $packet): array
    {
        $risk = (string) ($packet['risk_band'] ?? 'medium');
        $taskClass = (string) ($packet['task_class'] ?? 'feature');
        $missing = [];
        $remediation = [];

        $objective = trim((string) ($packet['objective'] ?? ''));
        $contextRefs = $this->list($packet['context_refs'] ?? []);
        $realContextRefs = array_values(array_filter(
            $contextRefs,
            static fn (string $ref): bool => ! str_starts_with($ref, 'aedpds_context:'),
        ));
        $expectedFiles = $this->list($packet['expected_files'] ?? []);
        $allowedFiles = $this->list($packet['allowed_files'] ?? []);
        $suggestedTests = $this->list($packet['suggested_tests'] ?? []);
        $requiredEvidence = $this->list($packet['required_evidence'] ?? []);
        $acceptance = $this->list($packet['acceptance_criteria'] ?? []);

        if ($objective === '' || $objective === 'Atlas Dev task') {
            $missing[] = 'objective';
            $remediation[] = 'declare a concrete engineering objective before sending to provider';
        }

        if (! in_array($taskClass, ['trivial', 'read_only'], true)) {
            if ($realContextRefs === [] && $expectedFiles === [] && $allowedFiles === []) {
                $missing[] = 'context_or_scope';
                $remediation[] = 'attach owner docs, expected files or allowed file scope';
            }

            if ($suggestedTests === [] && $requiredEvidence === []) {
                $missing[] = 'verification_plan';
                $remediation[] = 'declare suggested tests or required evidence';
            }
        }

        if (in_array($risk, ['high', 'critical'], true)) {
            if ($realContextRefs === []) {
                $missing[] = 'owner_docs_or_context_refs';
                $remediation[] = 'high risk Dev work requires canonical docs/context refs';
            }

            if ($acceptance === []) {
                $missing[] = 'acceptance_criteria';
                $remediation[] = 'high risk Dev work requires acceptance criteria';
            }
        }

        $missing = array_values(array_unique($missing));
        $remediation = array_values(array_unique($remediation));
        $status = $missing === [] ? self::STATUS_PASSED : (in_array($risk, ['high', 'critical'], true) ? self::STATUS_BLOCKED : self::STATUS_NEEDS_REVIEW);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => (string) ($packet['run_id'] ?? 'unknown'),
            'task_id' => (string) ($packet['task_id'] ?? 'unknown'),
            'status' => $status,
            'provider_safe' => $status === self::STATUS_PASSED,
            'missing' => $missing,
            'remediation' => $remediation,
        ];
        $payload['context_gate_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    public function persist(AtlasDevTaskPacket $taskPacket): AtlasDevContextGate
    {
        $payload = $this->evaluate($taskPacket->toArray());

        return AtlasDevContextGate::query()->updateOrCreate(
            ['context_gate_hash' => $payload['context_gate_hash']],
            [
                'schema_version' => $payload['schema_version'],
                'uuid' => $payload['context_gate_hash'] ?: Str::uuid()->toString(),
                'run_id' => $payload['run_id'],
                'task_id' => $payload['task_id'],
                'task_packet_id' => $taskPacket->id,
                'status' => $payload['status'],
                'missing' => $payload['missing'],
                'remediation' => $payload['remediation'],
                'provider_safe' => $payload['provider_safe'],
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
