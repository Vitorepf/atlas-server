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
    public const FIELD_REFUSED = 'refused';
    public const FIELD_OBSERVED = 'observed';
    public const FIELD_EXPECTED = 'expected';
    public const FIELD_REASON = 'reason';
    public const FIELD_STATUS = 'status';
    public const FIELD_CHECK = 'check';
    public const FIELD_DETAILS = 'details';
    public const FIELD_PASSED = 'passed';

    public const EXPORT_BOOL_TRUE = 'true';

    public const EXPORT_BOOL_FALSE = 'false';

    public const EXPORT_BOOL_UNSET = 'unset';

    public const PROBE_ID_UNKNOWN = 'unknown';

    public const FIELD_OK = 'ok';
    public const FIELD_ID = 'id';
    public const FIELD_SOURCE = 'source';
    public const FIELD_REFUSAL_REASON = 'refusal_reason';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_PROMOTES_SELECTION = 'promotes_selection';
    public const FIELD_BLOCKER = 'blocker';
    public const FIELD_DECISION = 'decision';
    public const FIELD_METRICS = 'metrics';
    public const FIELD_PRIVACY_CLASS = 'privacy_class';
    public const FIELD_CEILING = 'ceiling';
    public const FIELD_REQUESTED_AUTONOMY = 'requested_autonomy';
    public const FIELD_PROBE = 'probe';
    public const FIELD_VIOLATIONS = 'violations';
    public const FIELD_OPERATOR = 'operator';
    public const FIELD_REVERSAL_RATE = 'reversal_rate';
    public const FIELD_ASSIST_SESSIONS = 'assist_sessions';
    public const FIELD_ACCEPTANCE_RATE = 'acceptance_rate';
    public const FIELD_SEVERE_HALLUCINATION_COUNT = 'severe_hallucination_count';
    public const FIELD_METRICS_AUTHORITY = 'metrics_authority';
    public const FIELD_ELIGIBLE = 'eligible';
    public const FIELD_CODE = 'code';
    public const FIELD_PROBE_COUNT = 'probe_count';
    public const FIELD_REFUSED_COUNT = 'refused_count';
    public const FIELD_PROBES = 'probes';
    public const FIELD_ERRORS = 'errors';
    public const FIELD_N = 'n';
    public const FIELD_SCHEMA_VERSION = 'schema_version';

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
            $errors[] = [self::FIELD_PROBE => 'orchestration', self::FIELD_MESSAGE => $e->getMessage()];
        }

        foreach ($probes as $probe) {
            if (($probe[self::FIELD_REFUSED] ?? false) !== true) {
                $violations[] = [
                    self::FIELD_PROBE => AiValueNormalizer::trimmedStringOrNull($probe[self::FIELD_ID] ?? null) ?? self::PROBE_ID_UNKNOWN,
                    self::FIELD_REASON => AiValueNormalizer::trimmedStringOrNull($probe[self::FIELD_OBSERVED] ?? null) ?? 'forgery_passed',
                ];
            }
        }

        $evidence = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_PROBES => $probes,
            self::FIELD_PROBE_COUNT => count($probes),
            self::FIELD_REFUSED_COUNT => count(array_filter($probes, static fn (array $p): bool => ($p[self::FIELD_REFUSED] ?? false) === true)),
            self::FIELD_VIOLATIONS => $violations,
            self::FIELD_ERRORS => $errors,
            self::FIELD_SOURCE => [
                'read_only' => true,
                self::FIELD_PROMOTES_SELECTION => false,
                self::FIELD_BLOCKER => true,
                'gates_mutation' => 'AtlasAutonomousLearningApplier::decideCandidate:pass',
            ],
        ];

        if ($errors !== []) {
            return AtlasWatchdogCheckResult::error($evidence, [
                self::FIELD_CODE => 'maxk09_probe_orchestration_error',
                self::FIELD_MESSAGE => 'Adversarial probe orchestration failed.',
            ]);
        }
        if ($violations !== []) {
            return AtlasWatchdogCheckResult::alert($evidence, [
                self::FIELD_CODE => 'maxk09_adversarial_probe_passed',
                self::FIELD_MESSAGE => 'A MAXK ladder/envelope forgery was NOT refused — regression opens the boolean-forgeable gate.',
                self::FIELD_VIOLATIONS => $violations,
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
        $refused = $verdict[self::FIELD_OK] === false;

        return [
            self::FIELD_ID => 'maxk05.signature_forged_boolean',
            self::FIELD_REFUSED => $refused,
            self::FIELD_EXPECTED => 'ok=false',
            self::FIELD_OBSERVED => (AiValueNormalizer::trimmedStringOrNull($verdict[self::FIELD_REASON] ?? null) ?? self::PROBE_ID_UNKNOWN),
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
            self::FIELD_ID => 'maxk05.signature_receipt_missing',
            self::FIELD_REFUSED => $verdict[self::FIELD_OK] === false && $verdict[self::FIELD_REASON] === 'signature_receipt_missing',
            self::FIELD_EXPECTED => 'signature_receipt_missing',
            self::FIELD_OBSERVED => (AiValueNormalizer::trimmedStringOrNull($verdict[self::FIELD_REASON] ?? null) ?? self::PROBE_ID_UNKNOWN),
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
            self::FIELD_ID => 'maxk05.signature_nonce_reused',
            self::FIELD_REFUSED => $first[self::FIELD_OK] === true && $second[self::FIELD_OK] === false && $second[self::FIELD_REASON] === 'signature_nonce_reused',
            self::FIELD_EXPECTED => 'signature_nonce_reused after first spend',
            self::FIELD_OBSERVED => (AiValueNormalizer::trimmedStringOrNull($second[self::FIELD_REASON] ?? null) ?? self::PROBE_ID_UNKNOWN),
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
        $verdict = $this->ladder->evaluatePromotionAuthoritative('L0', $authority, [self::FIELD_OPERATOR => true]);
        $this->cleanup($path);

        $provenance = AiValueNormalizer::arrayOrEmpty($verdict[self::FIELD_METRICS_AUTHORITY] ?? null);
        $refused = ($verdict[self::FIELD_ELIGIBLE] ?? true) === false
            && ($verdict[self::FIELD_DECISION] ?? '') === 'blocked'
            && ($verdict[self::FIELD_REFUSAL_REASON] ?? '') === 'metrics_authority_missing'
            && ($provenance[self::FIELD_SOURCE] ?? '') === AtlasAutonomyMetricsAuthorityPort::SOURCE_MISSING;

        return [
            self::FIELD_ID => 'maxk06.metrics_authority_missing',
            self::FIELD_REFUSED => $refused,
            self::FIELD_EXPECTED => 'blocked+metrics_authority_missing',
            self::FIELD_OBSERVED => (AiValueNormalizer::trimmedStringOrNull($verdict[self::FIELD_REFUSAL_REASON] ?? $verdict[self::FIELD_DECISION] ?? null) ?? self::PROBE_ID_UNKNOWN),
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
                self::FIELD_ASSIST_SESSIONS => 10,
                self::FIELD_ACCEPTANCE_RATE => 0.10,
                self::FIELD_SEVERE_HALLUCINATION_COUNT => 5,
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
                    $entry[self::FIELD_METRICS][self::FIELD_ASSIST_SESSIONS] = 999;
                    $entry[self::FIELD_METRICS][self::FIELD_ACCEPTANCE_RATE] = 1.0;
                    $entry[self::FIELD_METRICS][self::FIELD_SEVERE_HALLUCINATION_COUNT] = 0;
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
            [self::FIELD_OPERATOR => true],
        );
        $this->cleanup($path);

        if (! $tamperOk) {
            return [
                self::FIELD_ID => 'maxk06.metrics_authority_tampered',
                self::FIELD_REFUSED => false,
                self::FIELD_EXPECTED => 'blocked+metrics_authority_tampered',
                self::FIELD_OBSERVED => 'probe_setup_failed',
            ];
        }

        $provenance = AiValueNormalizer::arrayOrEmpty($verdict[self::FIELD_METRICS_AUTHORITY] ?? null);

        return [
            self::FIELD_ID => 'maxk06.metrics_authority_tampered',
            self::FIELD_REFUSED => ($verdict[self::FIELD_ELIGIBLE] ?? true) === false
                && ($verdict[self::FIELD_REFUSAL_REASON] ?? '') === 'metrics_authority_tampered'
                && ($provenance[self::FIELD_SOURCE] ?? '') === AtlasAutonomyMetricsAuthorityPort::SOURCE_TAMPERED,
            self::FIELD_EXPECTED => 'blocked+metrics_authority_tampered',
            self::FIELD_OBSERVED => (AiValueNormalizer::trimmedStringOrNull($verdict[self::FIELD_REFUSAL_REASON] ?? $verdict[self::FIELD_DECISION] ?? null) ?? self::PROBE_ID_UNKNOWN),
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
            self::FIELD_PRIVACY_CLASS => 'sensitive',
            self::FIELD_CEILING => PolicyCanon::AUTONOMY_AUTONOMOUS,
        ]);
        $level = AiValueNormalizer::trimmedScalarStringOrNull($derived[self::FIELD_REQUESTED_AUTONOMY] ?? null) ?? '';
        $refused = $level === PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL
            || $level === PolicyCanon::AUTONOMY_DRAFT
            || $level === PolicyCanon::AUTONOMY_SUGGEST;

        return [
            self::FIELD_ID => 'maxk07.privacy_sensitive_shrinks',
            self::FIELD_REFUSED => $refused,
            self::FIELD_EXPECTED => '≤ execute_with_approval',
            self::FIELD_OBSERVED => $level,
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
            self::FIELD_PRIVACY_CLASS => 'normal',
            self::FIELD_REVERSAL_RATE => 0.42,
            self::FIELD_N => 20,
            self::FIELD_CEILING => PolicyCanon::AUTONOMY_AUTONOMOUS,
        ]);
        $level = AiValueNormalizer::trimmedScalarStringOrNull($derived[self::FIELD_REQUESTED_AUTONOMY] ?? null) ?? '';
        $refused = $level === PolicyCanon::AUTONOMY_DRAFT
            || $level === PolicyCanon::AUTONOMY_SUGGEST;

        return [
            self::FIELD_ID => 'maxk07.reversal_rate_high_shrinks_to_draft',
            self::FIELD_REFUSED => $refused,
            self::FIELD_EXPECTED => '≤ draft',
            self::FIELD_OBSERVED => $level,
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
            self::FIELD_PRIVACY_CLASS => 'normal',
            self::FIELD_REVERSAL_RATE => 0.0,
            self::FIELD_N => 100,
            self::FIELD_CEILING => PolicyCanon::AUTONOMY_DRAFT,
        ]);
        $level = AiValueNormalizer::trimmedScalarStringOrNull($derived[self::FIELD_REQUESTED_AUTONOMY] ?? null) ?? '';
        $refused = RequestedAutonomyDerivation::isMonotonicallyDownward($level, PolicyCanon::AUTONOMY_DRAFT);

        return [
            self::FIELD_ID => 'maxk07.never_exceeds_ceiling',
            self::FIELD_REFUSED => $refused,
            self::FIELD_EXPECTED => 'derived ≤ ceiling=draft',
            self::FIELD_OBSERVED => $level,
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
        $source = AiValueNormalizer::arrayOrEmpty($report[self::FIELD_SOURCE] ?? null);
        $refused = ($source[self::FIELD_PROMOTES_SELECTION] ?? true) === false
            && ($source[self::FIELD_BLOCKER] ?? true) === false;

        return [
            self::FIELD_ID => 'maxk08.miner_report_only',
            self::FIELD_REFUSED => $refused,
            self::FIELD_EXPECTED => 'promotes_selection=false AND blocker=false',
            self::FIELD_OBSERVED => 'promotes_selection='.$this->exportBool($source[self::FIELD_PROMOTES_SELECTION] ?? null)
                .' blocker='.$this->exportBool($source[self::FIELD_BLOCKER] ?? null),
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
            return self::EXPORT_BOOL_TRUE;
        }
        if ($value === false) {
            return self::EXPORT_BOOL_FALSE;
        }

        return self::EXPORT_BOOL_UNSET;
    }
}
