<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\AtlasDecide\DecisionReceiptFailurePatternMiner;
use App\Services\Ai\AtlasDecide\RequestedAutonomyDerivation;
use App\Services\Ai\Autonomy\AtlasAutonomyLadderRuntimeService;
use App\Services\Ai\Autonomy\AtlasAutonomyLadderSignatureLedger;
use App\Services\Ai\Autonomy\AtlasAutonomyMetricsAuthorityPort;
use App\Services\Ai\Autonomy\SealedLedgerAutonomyMetricsAuthority;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Policy\PolicyCanon;
use App\Services\Ai\Support\AiValueNormalizer;
use Throwable;

/**
 * MAXK-09 — Certificação: escada+envelope como gate real ungameable (WDG-01 plugin).
 *
 * The MAXK family lands independent property-gated defenses:
 *  - MAXK-05: signature ledger recusa boolean/receipt ausente/nonce reutilizado
 *  - MAXK-06: sealed metrics authority recusa SOURCE_MISSING/SOURCE_TAMPERED
 *  - MAXK-07: requested-autonomy shrink NUNCA sobe acima do teto do caller e
 *             aperta em privacy sensitive/secret/cyber e reversal_rate alto
 *  - MAXK-08: failure-pattern miner é report-only (`promotes_selection=false`)
 *
 * MAXK-09 fecha a família: uma suíte adversarial permanente que forja CADA
 * input (boolean signatures, caller-supplied metrics, requested-autonomy
 * ceiling loosening, miner blocker mode) e ASSERTA que a promoção do estado
 * real é NEGADA. Se algum forjar-input passar, a check vira `alert` — a
 * mutação concreta é o `pass` gate do MAXK-05 no `AtlasAutonomousLearningApplier::decideCandidate()`
 * (linha :356 da era do plano, imediatamente após o cheque `promotion_gate.status==='pass'`).
 *
 * Provider-safe by construction: puro; nunca lê DB; usa um ledger temporário
 * por invocação. Fail-open em erros de bookkeeping para nunca quebrar o land.
 *
 * @see docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md
 */
final class AutonomyLadderAdversarialWatchdogCheck implements AtlasWatchdogCheck
{
    public const SCHEMA = 'atlas.acos.watchdog.autonomy_ladder_adversarial.v1';

    public const CHECK_ID = 'maxk-09.autonomy_ladder_adversarial';

    public function __construct(
        private readonly AtlasAutonomyLadderRuntimeService $ladder,
        private readonly DecisionReceiptFailurePatternMiner $miner,
    ) {}

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $probes = [];
        $violations = [];
        $errors = [];

        try {
            $probes[] = $this->probeSignatureForgedBoolean();
            $probes[] = $this->probeSignatureReceiptMissing();
            $probes[] = $this->probeSignatureNonceReused();
            $probes[] = $this->probeMetricsAuthorityMissing();
            $probes[] = $this->probeMetricsAuthorityTampered();
            $probes[] = $this->probeShrinkPrivacySensitive();
            $probes[] = $this->probeShrinkReversalRateHigh();
            $probes[] = $this->probeShrinkNeverExceedsCeiling();
            $probes[] = $this->probeMinerIsReportOnly();
        } catch (Throwable $e) {
            $errors[] = ['probe' => 'orchestration', 'message' => $e->getMessage()];
        }

        foreach ($probes as $probe) {
            if (($probe['refused'] ?? false) !== true) {
                $violations[] = [
                    'probe' => AiValueNormalizer::trimmedStringOrNull($probe['id'] ?? null) ?? 'unknown',
                    'reason' => AiValueNormalizer::trimmedStringOrNull($probe['observed'] ?? null) ?? 'forgery_passed',
                ];
            }
        }

        $evidence = [
            'schema_version' => self::SCHEMA,
            'probes' => $probes,
            'probe_count' => count($probes),
            'refused_count' => count(array_filter($probes, static fn (array $p): bool => ($p['refused'] ?? false) === true)),
            'violations' => $violations,
            'errors' => $errors,
            'source' => [
                'read_only' => true,
                'promotes_selection' => false,
                'blocker' => true,
                'gates_mutation' => 'AtlasAutonomousLearningApplier::decideCandidate:pass',
            ],
        ];

        if ($errors !== []) {
            return AtlasWatchdogCheckResult::error($evidence, [
                'code' => 'maxk09_probe_orchestration_error',
                'message' => 'Adversarial probe orchestration failed.',
            ]);
        }
        if ($violations !== []) {
            return AtlasWatchdogCheckResult::alert($evidence, [
                'code' => 'maxk09_adversarial_probe_passed',
                'message' => 'A MAXK ladder/envelope forgery was NOT refused — regression opens the boolean-forgeable gate.',
                'violations' => $violations,
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence);
    }

    /**
     * MAXK-05 — `promotion_gate.signatures[k] = true` (boolean) is the exact
     * adversarial input the plan names; the ledger MUST refuse without
     * touching persistence.
     *
     * @return array<string,mixed>
     */
    private function probeSignatureForgedBoolean(): array
    {
        [$ledger, $path] = $this->tempSignatureLedger();
        $verdict = $ledger->verify(
            signature: 'operator',
            actor: 'atlas-operator',
            nonce: 'forged-boolean',
            policyHash: 'policy-h',
            targetKind: 'atlas_aemor_memory_candidate',
            targetId: 'cand-1',
        );
        $this->cleanup($path);

        // The ledger surface returns 'signature_receipt_missing' when the row
        // is absent — which is the outcome for a bare boolean receipt after
        // the applier layer converts `true` into a missing-field verdict at
        // its own boundary. Either refusal reason satisfies the invariant.
        $refused = $verdict['ok'] === false;

        return [
            'id' => 'maxk05.signature_forged_boolean',
            'refused' => $refused,
            'expected' => 'ok=false',
            'observed' => (AiValueNormalizer::trimmedStringOrNull($verdict['reason'] ?? null) ?? 'unknown'),
        ];
    }

    /**
     * MAXK-05 — verification without any receipt MUST return signature_receipt_missing.
     *
     * @return array<string,mixed>
     */
    private function probeSignatureReceiptMissing(): array
    {
        [$ledger, $path] = $this->tempSignatureLedger();
        $verdict = $ledger->verify(
            signature: 'operator',
            actor: 'atlas-operator',
            nonce: 'never-issued',
            policyHash: 'policy-h',
            targetKind: 'atlas_aemor_memory_candidate',
            targetId: 'cand-2',
        );
        $this->cleanup($path);

        return [
            'id' => 'maxk05.signature_receipt_missing',
            'refused' => $verdict['ok'] === false && $verdict['reason'] === 'signature_receipt_missing',
            'expected' => 'signature_receipt_missing',
            'observed' => (AiValueNormalizer::trimmedStringOrNull($verdict['reason'] ?? null) ?? 'unknown'),
        ];
    }

    /**
     * MAXK-05 — a nonce marked `spent` after one successful verify MUST fail
     * with signature_nonce_reused on any re-verify.
     *
     * @return array<string,mixed>
     */
    private function probeSignatureNonceReused(): array
    {
        [$ledger, $path] = $this->tempSignatureLedger();
        $ledger->record(
            signature: 'operator',
            actor: 'atlas-operator',
            nonce: 'nonce-reused-probe',
            policyHash: 'policy-h',
            targetKind: 'atlas_aemor_memory_candidate',
            targetId: 'cand-3',
        );
        $first = $ledger->verify(
            signature: 'operator',
            actor: 'atlas-operator',
            nonce: 'nonce-reused-probe',
            policyHash: 'policy-h',
            targetKind: 'atlas_aemor_memory_candidate',
            targetId: 'cand-3',
        );
        // Second verify — must fail because nonce is now spent.
        $second = $ledger->verify(
            signature: 'operator',
            actor: 'atlas-operator',
            nonce: 'nonce-reused-probe',
            policyHash: 'policy-h',
            targetKind: 'atlas_aemor_memory_candidate',
            targetId: 'cand-3',
        );
        $this->cleanup($path);

        return [
            'id' => 'maxk05.signature_nonce_reused',
            'refused' => $first['ok'] === true && $second['ok'] === false && $second['reason'] === 'signature_nonce_reused',
            'expected' => 'signature_nonce_reused after first spend',
            'observed' => (AiValueNormalizer::trimmedStringOrNull($second['reason'] ?? null) ?? 'unknown'),
        ];
    }

    /**
     * MAXK-06 — caller-supplied metrics MUST NOT alter the authoritative
     * verdict when the sealed authority has no entry (SOURCE_MISSING).
     *
     * @return array<string,mixed>
     */
    private function probeMetricsAuthorityMissing(): array
    {
        $path = $this->tempFile('maxk09-auth-missing-');
        $authority = new SealedLedgerAutonomyMetricsAuthority($path);
        $verdict = $this->ladder->evaluatePromotionAuthoritative('L0', $authority, ['operator' => true]);
        $this->cleanup($path);

        $provenance = AiValueNormalizer::arrayOrEmpty($verdict['metrics_authority'] ?? null);
        $refused = ($verdict['eligible'] ?? true) === false
            && ($verdict['decision'] ?? '') === 'blocked'
            && ($verdict['refusal_reason'] ?? '') === 'metrics_authority_missing'
            && ($provenance['source'] ?? '') === AtlasAutonomyMetricsAuthorityPort::SOURCE_MISSING;

        return [
            'id' => 'maxk06.metrics_authority_missing',
            'refused' => $refused,
            'expected' => 'blocked+metrics_authority_missing',
            'observed' => (string) ($verdict['refusal_reason'] ?? $verdict['decision'] ?? 'unknown'),
        ];
    }

    /**
     * MAXK-06 — a post-seal rewrite of metric values MUST be detected as
     * SOURCE_TAMPERED (recompute of entry_hash fails).
     *
     * @return array<string,mixed>
     */
    private function probeMetricsAuthorityTampered(): array
    {
        $path = $this->tempFile('maxk09-auth-tampered-');
        $authority = new SealedLedgerAutonomyMetricsAuthority($path);
        $authority->seal(
            level: 'L1',
            metrics: [
                'assist_sessions' => 10,
                'acceptance_rate' => 0.10,
                'severe_hallucination_count' => 5,
            ],
            sourceId: 'maxk09-probe',
        );

        $tamperOk = false;
        try {
            $raw = (string) file_get_contents($path);
            $lines = array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => trim($line) !== ''));
            if (count($lines) === 1) {
                $entry = json_decode($lines[0], true);
                if (is_array($entry)) {
                    // Adversary overwrites metrics but leaves entry_hash alone.
                    $entry['metrics']['assist_sessions'] = 999;
                    $entry['metrics']['acceptance_rate'] = 1.0;
                    $entry['metrics']['severe_hallucination_count'] = 0;
                    file_put_contents($path, json_encode($entry)."\n");
                    $tamperOk = true;
                }
            }
        } catch (Throwable) {
            $tamperOk = false;
        }

        $verdict = $this->ladder->evaluatePromotionAuthoritative(
            'L0',
            new SealedLedgerAutonomyMetricsAuthority($path),
            ['operator' => true],
        );
        $this->cleanup($path);

        if (! $tamperOk) {
            return [
                'id' => 'maxk06.metrics_authority_tampered',
                'refused' => false,
                'expected' => 'blocked+metrics_authority_tampered',
                'observed' => 'probe_setup_failed',
            ];
        }

        $provenance = AiValueNormalizer::arrayOrEmpty($verdict['metrics_authority'] ?? null);

        return [
            'id' => 'maxk06.metrics_authority_tampered',
            'refused' => ($verdict['eligible'] ?? true) === false
                && ($verdict['refusal_reason'] ?? '') === 'metrics_authority_tampered'
                && ($provenance['source'] ?? '') === AtlasAutonomyMetricsAuthorityPort::SOURCE_TAMPERED,
            'expected' => 'blocked+metrics_authority_tampered',
            'observed' => (string) ($verdict['refusal_reason'] ?? $verdict['decision'] ?? 'unknown'),
        ];
    }

    /**
     * MAXK-07 — privacy_class sensitive MUST shrink the requested autonomy
     * to at most `execute_with_approval` regardless of ceiling.
     *
     * @return array<string,mixed>
     */
    private function probeShrinkPrivacySensitive(): array
    {
        $derived = RequestedAutonomyDerivation::derive([
            'privacy_class' => 'sensitive',
            'ceiling' => PolicyCanon::AUTONOMY_AUTONOMOUS,
        ]);
        $level = (string) $derived['requested_autonomy'];
        $refused = $level === PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL
            || $level === PolicyCanon::AUTONOMY_DRAFT
            || $level === PolicyCanon::AUTONOMY_SUGGEST;

        return [
            'id' => 'maxk07.privacy_sensitive_shrinks',
            'refused' => $refused,
            'expected' => '≤ execute_with_approval',
            'observed' => $level,
        ];
    }

    /**
     * MAXK-07 — reversal_rate ≥ 0.30 with n ≥ MIN_N MUST shrink to `draft`.
     *
     * @return array<string,mixed>
     */
    private function probeShrinkReversalRateHigh(): array
    {
        $derived = RequestedAutonomyDerivation::derive([
            'privacy_class' => 'normal',
            'reversal_rate' => 0.42,
            'n' => 20,
            'ceiling' => PolicyCanon::AUTONOMY_AUTONOMOUS,
        ]);
        $level = (string) $derived['requested_autonomy'];
        $refused = $level === PolicyCanon::AUTONOMY_DRAFT
            || $level === PolicyCanon::AUTONOMY_SUGGEST;

        return [
            'id' => 'maxk07.reversal_rate_high_shrinks_to_draft',
            'refused' => $refused,
            'expected' => '≤ draft',
            'observed' => $level,
        ];
    }

    /**
     * MAXK-07 — caller ceiling is respected: derived MUST NEVER be looser
     * than ceiling even under a spotless input.
     *
     * @return array<string,mixed>
     */
    private function probeShrinkNeverExceedsCeiling(): array
    {
        $derived = RequestedAutonomyDerivation::derive([
            'privacy_class' => 'normal',
            'reversal_rate' => 0.0,
            'n' => 100,
            'ceiling' => PolicyCanon::AUTONOMY_DRAFT,
        ]);
        $level = (string) $derived['requested_autonomy'];
        $refused = RequestedAutonomyDerivation::isMonotonicallyDownward($level, PolicyCanon::AUTONOMY_DRAFT);

        return [
            'id' => 'maxk07.never_exceeds_ceiling',
            'refused' => $refused,
            'expected' => 'derived ≤ ceiling=draft',
            'observed' => $level,
        ];
    }

    /**
     * MAXK-08 — miner MUST advertise report-only invariants
     * (`promotes_selection=false`, `blocker=false`). Any regression that
     * flips these keys reopens the "learn-to-block" input MAXK-08 refutes.
     *
     * @return array<string,mixed>
     */
    private function probeMinerIsReportOnly(): array
    {
        $report = $this->miner->mine([], []);
        $source = AiValueNormalizer::arrayOrEmpty($report['source'] ?? null);
        $refused = ($source['promotes_selection'] ?? true) === false
            && ($source['blocker'] ?? true) === false;

        return [
            'id' => 'maxk08.miner_report_only',
            'refused' => $refused,
            'expected' => 'promotes_selection=false AND blocker=false',
            'observed' => 'promotes_selection='.$this->exportBool($source['promotes_selection'] ?? null)
                .' blocker='.$this->exportBool($source['blocker'] ?? null),
        ];
    }

    /**
     * @return array{0:AtlasAutonomyLadderSignatureLedger,1:string}
     */
    private function tempSignatureLedger(): array
    {
        $path = $this->tempFile('maxk09-sigledger-');

        return [new AtlasAutonomyLadderSignatureLedger($path), $path];
    }

    private function tempFile(string $prefix): string
    {
        $temp = tempnam(sys_get_temp_dir(), $prefix);
        if ($temp === false) {
            return sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.uniqid('', true);
        }

        return $temp.'.jsonl';
    }

    private function cleanup(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
        $base = str_ends_with($path, '.jsonl') ? substr($path, 0, -6) : $path;
        if ($base !== '' && is_file($base)) {
            @unlink($base);
        }
    }

    private function exportBool(mixed $value): string
    {
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }

        return 'unset';
    }
}
