<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

use RuntimeException;

/**
 * Pure builder that converts a normalized task packet into an Atlas-native execution envelope. NEVER
 * produces an external provider prompt — runtime_owner is always atlas_native and provider_prompt is
 * always null.
 *
 * INVARIANTS:
 *   - FAIL-CLOSED on: missing allowed_files, empty acceptance_criteria, missing required_evidence, or a
 *     non-Atlas-native simplicity_contract.
 *   - Envelope contains runtime_owner='atlas_native', execution_topology='shared_local_main_with_scope_lock',
 *     provider_prompt=null.
 *   - Identical packet ⇒ byte-identical envelope_hash (SHA-256 over canonical encoded envelope).
 */
final class AtlasNativeWorkerExecutionEnvelopeBuilder
{
    public const SCHEMA = 'atlas.native_worker.execution_envelope.v1';

    public const RUNTIME_OWNER = 'atlas_native';

    public const EXECUTION_TOPOLOGY = 'shared_local_main_with_scope_lock';

    public const SIMPLICITY_CONTRACT_NATIVE = 'atlas_native';

    /**
     * @param  array<string,mixed>  $packet
     * @return array{schema:string, runtime_owner:string, execution_topology:string, provider_prompt:null, objective:string, allowed_files:list<string>, scope_in:list<string>, acceptance_criteria:list<string>, required_evidence:list<string>, gates:list<string>, rollback_plan:array<string,mixed>, evidence_template:array<string,mixed>, envelope_hash:string}
     */
    public function build(array $packet): array
    {
        $allowedFiles = $this->normalizeStringList($packet['allowed_files'] ?? null);
        if ($allowedFiles === []) {
            throw new RuntimeException('execution envelope fail-closed: missing allowed_files');
        }
        $acceptance = $this->normalizeStringList($packet['acceptance_criteria'] ?? null);
        if ($acceptance === []) {
            throw new RuntimeException('execution envelope fail-closed: empty acceptance_criteria');
        }
        $requiredEvidence = $this->normalizeStringList($packet['required_evidence'] ?? null);
        if ($requiredEvidence === []) {
            throw new RuntimeException('execution envelope fail-closed: missing required_evidence');
        }
        $simplicity = (string) ($packet['simplicity_contract'] ?? self::SIMPLICITY_CONTRACT_NATIVE);
        if ($simplicity !== self::SIMPLICITY_CONTRACT_NATIVE) {
            throw new RuntimeException('execution envelope fail-closed: non Atlas-native simplicity_contract: '.$simplicity);
        }

        $envelope = [
            'schema' => self::SCHEMA,
            'runtime_owner' => self::RUNTIME_OWNER,
            'execution_topology' => self::EXECUTION_TOPOLOGY,
            'provider_prompt' => null,
            'objective' => trim((string) ($packet['objective'] ?? '')),
            'allowed_files' => $allowedFiles,
            'scope_in' => $this->normalizeStringList($packet['scope_in'] ?? null) ?: $allowedFiles,
            'acceptance_criteria' => $acceptance,
            'required_evidence' => $requiredEvidence,
            'gates' => $this->normalizeStringList($packet['gates'] ?? null),
            'rollback_plan' => is_array($packet['rollback_plan'] ?? null) ? $packet['rollback_plan'] : ['mode' => 'revert_commit'],
            'evidence_template' => is_array($packet['evidence_template'] ?? null) ? $packet['evidence_template'] : $this->defaultEvidenceTemplate($requiredEvidence),
        ];

        $envelope['envelope_hash'] = $this->hash($envelope);

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function hash(array $envelope): string
    {
        $canonical = $envelope;
        unset($canonical['envelope_hash']);
        ksort($canonical);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            $s = trim((string) $v);
            if ($s !== '' && ! in_array($s, $out, true)) {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $requiredEvidence
     * @return array<string,mixed>
     */
    private function defaultEvidenceTemplate(array $requiredEvidence): array
    {
        $tpl = [];
        foreach ($requiredEvidence as $key) {
            $tpl[$key] = null;
        }

        return $tpl;
    }
}
