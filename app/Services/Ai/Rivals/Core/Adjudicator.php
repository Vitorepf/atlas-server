<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;

/**
 * SÓ hard gates, fail-closed. claim_allowed=false é o default e qualquer gate
 * reprovado mantém false com blocker nomeado. NÃO existe score de qualidade
 * agregado nem "vencedor geral" — isso morreu com o Rivals 1.0.
 */
class Adjudicator
{
    public function __construct(private readonly ReplayVerifier $verifier = new ReplayVerifier)
    {
    }

    public function adjudicate(string $runId): array
    {
        $blockers = [];

        try {
            $plan = RunPlan::load($runId);
        } catch (\Throwable $e) {
            return $this->persist($runId, 'invalid', ["plan_invalid:{$e->getMessage()}"]);
        }

        $receipts = [];
        try {
            $receipts = RunReceipt::loadAll($runId);
        } catch (\Throwable $e) {
            $blockers[] = 'receipts_unparseable:'.$e->getMessage();
        }

        // gate: receipt para cada case×arm×rep planejado
        $seen = array_map(fn (RunReceipt $r) => $r->key(), $receipts);
        foreach ($plan->expectedReceiptKeys() as $expected) {
            if (! in_array($expected, $seen, true)) {
                $blockers[] = "missing_receipt:{$expected}";
            }
        }

        // gate: repetições >= mínimo de claim
        $minReps = (int) config('atlas_rivals.claim.min_repetitions', 3);
        if ($plan->data['repetitions'] < $minReps) {
            $blockers[] = "repetitions_below_min:{$plan->data['repetitions']}<{$minReps}";
        }

        // gate: custo/tempo presentes e numéricos em todo receipt
        foreach ($receipts as $receipt) {
            if (! is_numeric($receipt->data['cost_usd']) || ! is_numeric($receipt->data['wall_ms'])) {
                $blockers[] = 'cost_or_time_missing:'.$receipt->key();
            }
        }

        // gate: judge pinning — se o plan declara judge, todo receipt carrega o MESMO judge_config
        $planJudge = $plan->data['judge_config'] ?? null;
        if ($planJudge !== null) {
            $pinned = json_encode($planJudge);
            foreach ($receipts as $receipt) {
                if (json_encode($receipt->data['judge_config'] ?? null) !== $pinned) {
                    $blockers[] = 'judge_config_mismatch:'.$receipt->key();
                }
            }
        }

        // gate: evidence pack completo (nenhum present:false)
        $packPath = RunPaths::evidencePath($runId);
        if (! is_file($packPath)) {
            $blockers[] = 'evidence_pack_missing';
        } else {
            $pack = json_decode(file_get_contents($packPath), true) ?? [];
            foreach (['plan_hash', 'receipts_hash'] as $field) {
                if ((($pack[$field] ?? [])['present'] ?? false) !== true) {
                    $blockers[] = "evidence_incomplete:{$field}";
                }
            }
            foreach ($pack['artifacts'] ?? [] as $rel => $meta) {
                if (($meta['present'] ?? false) !== true) {
                    $blockers[] = "evidence_incomplete:artifact:{$rel}";
                }
            }
        }

        // gate: replay verificado agora (não confiar em verify antigo)
        $replay = $this->verifier->verify($runId);
        if (! $replay['verified']) {
            foreach ($replay['failures'] as $failure) {
                $blockers[] = "replay_failed:{$failure}";
            }
        }

        return $this->persist($runId, $blockers === [] ? 'valid' : 'invalid', $blockers, $plan->data);
    }

    private function persist(string $runId, string $verdict, array $blockers, array $planData = []): array
    {
        $adjudication = [
            'schema_version' => 'atlas.rivals2.adjudication.v1',
            'run_id' => $runId,
            'verdict' => $verdict,
            'claim_allowed' => $verdict === 'valid',
            'claim_blockers' => array_values($blockers),
            // claim é SEMPRE escopado; sem escopo completo não há claim
            'claim_scope' => $planData === [] ? null : [
                'suite' => $planData['suite_id'],
                'cases' => $planData['case_ids'],
                'arms' => array_column($planData['arms'], 'arm_id'),
                'repetitions' => $planData['repetitions'],
                'budget' => $planData['budget'],
                'environment' => $planData['environment'],
                'judge_config' => $planData['judge_config'] ?? null,
            ],
            'adjudicated_at' => now()->toIso8601String(),
        ];
        RunPaths::ensureDir(RunPaths::runDir($runId));
        file_put_contents(
            RunPaths::adjudicationPath($runId),
            json_encode($adjudication, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $adjudication;
    }
}
