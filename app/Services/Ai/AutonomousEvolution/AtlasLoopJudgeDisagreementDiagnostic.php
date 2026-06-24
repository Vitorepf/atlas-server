<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * JUDGE-DISAGREEMENT DIAGNOSTIC — classifies WHY two or more judges disagreed on the SAME delivery, operating
 * ONLY on the receipts the {@see AtlasLoopJudgeConsensusGate} (+ the cross-model triangulator) already
 * emitted. Provider-free, pure fact, no scalar.
 *
 * It is an ANNOTATOR, never an actuator: it cannot rewrite the consensus verdict — it returns the verdict
 * UNCHANGED plus a typed category. Categories (frozen): bundle_drift (the judges scored DIFFERENT frozen
 * bundles — a hash mismatch, never silently swallowed), provider_capability_gap (a judge's provider lacked a
 * tool/length), prompt_lensing (asymmetric judge prompts), genuine_semantic_split (same bundle, same
 * capability, same prompt — a real split), or undetermined (no actual disagreement / too little evidence).
 */
final class AtlasLoopJudgeDisagreementDiagnostic
{
    public const SCHEMA = 'atlas.loop.judge_disagreement.v1';

    /** The frozen category enum (mirrors config atlas.loop.disagreement_categories). */
    public const CATEGORIES = [
        'bundle_drift',
        'provider_capability_gap',
        'prompt_lensing',
        'genuine_semantic_split',
        'undetermined',
    ];

    /**
     * @param  array<string,mixed>  $consensusVerdict  the AtlasLoopJudgeConsensusGate verdict (returned UNCHANGED)
     * @param  list<array<string,mixed>>  $judgeReceipts  one receipt per judge {provider, bundle_sha256, prompt_id, passed, capability_ok?, missing_capability?}
     * @return array{schema:string, category:string, evidence:array<string,mixed>, verdict:array<string,mixed>}
     */
    public function diagnose(array $consensusVerdict, array $judgeReceipts): array
    {
        $receipts = array_values(array_filter($judgeReceipts, 'is_array'));

        $verdicts = array_map(static fn (array $r): bool => ($r['passed'] ?? null) === true, $receipts);
        $distinctVerdicts = array_unique($verdicts);
        $hasDisagreement = count($receipts) >= 2 && count($distinctVerdicts) > 1;

        if (! $hasDisagreement) {
            return $this->result('undetermined', ['reason' => 'no_disagreement', 'judge_count' => count($receipts)], $consensusVerdict);
        }

        // 1. BUNDLE DRIFT — judges scored different frozen bundles (deterministic sha256 comparison; never swallowed).
        $bundles = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => trim((string) ($r['bundle_sha256'] ?? '')),
            $receipts,
        ), static fn (string $s): bool => $s !== '')));
        if (count($bundles) > 1) {
            return $this->result('bundle_drift', ['bundle_sha256s' => $bundles], $consensusVerdict);
        }

        // 2. PROVIDER CAPABILITY GAP — a judge's provider lacked a needed capability.
        $gaps = [];
        foreach ($receipts as $r) {
            $missing = trim((string) ($r['missing_capability'] ?? ''));
            if (($r['capability_ok'] ?? true) === false || $missing !== '') {
                $gaps[] = ['provider' => (string) ($r['provider'] ?? ''), 'missing_capability' => $missing];
            }
        }
        if ($gaps !== []) {
            return $this->result('provider_capability_gap', ['capability_gaps' => $gaps], $consensusVerdict);
        }

        // 3. PROMPT LENSING — asymmetric judge prompts (different prompt ids).
        $promptIds = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => trim((string) ($r['prompt_id'] ?? '')),
            $receipts,
        ), static fn (string $s): bool => $s !== '')));
        if (count($promptIds) > 1) {
            return $this->result('prompt_lensing', ['prompt_ids' => $promptIds], $consensusVerdict);
        }

        // 4. GENUINE SEMANTIC SPLIT — same bundle, same capability, same prompt, yet the verdicts differ.
        $providers = array_values(array_map(static fn (array $r): array => [
            'provider' => (string) ($r['provider'] ?? ''),
            'passed' => ($r['passed'] ?? null) === true,
        ], $receipts));

        return $this->result('genuine_semantic_split', ['providers' => $providers], $consensusVerdict);
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>  $verdict
     * @return array{schema:string, category:string, evidence:array<string,mixed>, verdict:array<string,mixed>}
     */
    private function result(string $category, array $evidence, array $verdict): array
    {
        return [
            'schema' => self::SCHEMA,
            'category' => $category,
            'evidence' => $evidence,
            'verdict' => $verdict, // returned UNCHANGED — the diagnostic annotates, never rewrites
        ];
    }
}
