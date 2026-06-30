<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Closure;

/**
 * HARD-CASE AUTO-DISCOVERY — mines existing ledger rows (AtlasLoopAttemptLedger + the
 * AtlasLoopJudgeDisagreementDiagnostic outputs) to surface the deliveries worth re-grinding: where >=2
 * providers DISAGREED, a provider ROLLED BACK, or the FrozenJudge FLIPPED its verdict on re-prove.
 *
 * Hard cases are FACTS (ledger row ids + the real trigger), never synthesized examples. ANTI-FARM: the
 * discovery cannot MANUFACTURE cases — an injected anti-farm gate (wraps AtlasLoopAntiFarmFloor in production)
 * that returns false makes it emit NOTHING. Gated by ATLAS_LOOP_MASTER_ENABLED: OFF ⇒ empty list, no I/O
 * beyond reading. Every emitted reason maps to an explicit ledger fact — never a heuristic.
 */
final class AtlasLoopHardCaseAutoDiscovery
{
    public const SCHEMA = 'atlas.loop.hard_case.v1';

    public const TRIGGERS = ['provider_disagreement', 'provider_rollback', 'reprove_verdict_flip'];

    /**
     * @param  Closure():bool|null  $antiFarmGate  the anti-farm admission gate (false ⇒ emit nothing); production wraps AtlasLoopAntiFarmFloor
     */
    public function __construct(private readonly ?Closure $antiFarmGate = null) {}

    /**
     * @param  list<array<string,mixed>>  $ledgerRows  rows {id, providers:[{provider,passed}], bundle_sha256, rolled_back?, initial_verdict?, reprove_verdict?}
     * @return list<array{schema:string, ledger_row_id:string, trigger_reason:string, provider_set:list<string>, frozen_bundle_hash:string}>
     */
    public function discover(array $ledgerRows): array
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            return []; // master OFF ⇒ empty, read-only
        }
        if (! ($this->antiFarmGate ?? static fn (): bool => true)()) {
            return []; // anti-farm floor refused ⇒ cannot manufacture cases
        }

        $cases = [];
        foreach ($ledgerRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $reason = $this->triggerReason($row);
            if ($reason === null) {
                continue; // not a hard case — never fabricated
            }
            $cases[] = [
                'schema' => self::SCHEMA,
                'ledger_row_id' => (string) ($row['id'] ?? ''),
                'trigger_reason' => $reason,
                'provider_set' => $this->providerSet($row),
                'frozen_bundle_hash' => (string) ($row['bundle_sha256'] ?? ($row['frozen_bundle_hash'] ?? '')),
                'disagreement_category' => $this->disagreementCategory($reason, $row),
                'task_seed_readiness' => $this->buildTaskSeedReadiness($row),
            ];
        }

        return $cases;
    }

    /**
     * The typed disagreement category for an emitted case. Pure ANNOTATION: it never drops/adds/reorders a
     * case. For a 'provider_disagreement' trigger it composes {@see AtlasLoopJudgeDisagreementDiagnostic} over
     * per-judge receipts built from the row (each providers[] entry → {provider, passed} + its optional
     * bundle_sha256/prompt_id/capability_ok/missing_capability; the row-level bundle_sha256 is the fallback).
     * Every non-disagreement trigger is 'undetermined' — there is no judge split to classify.
     */
    private function disagreementCategory(string $reason, array $row): string
    {
        if ($reason !== 'provider_disagreement') {
            return 'undetermined';
        }

        $rowBundle = trim((string) ($row['bundle_sha256'] ?? ($row['frozen_bundle_hash'] ?? '')));
        $receipts = array_map(static function (array $p) use ($rowBundle): array {
            $receipt = [
                'provider' => (string) ($p['provider'] ?? ''),
                'passed' => ($p['passed'] ?? null) === true,
                'bundle_sha256' => trim((string) ($p['bundle_sha256'] ?? $rowBundle)),
            ];
            foreach (['prompt_id', 'capability_ok', 'missing_capability'] as $key) {
                if (array_key_exists($key, $p)) {
                    $receipt[$key] = $p[$key];
                }
            }

            return $receipt;
        }, $this->providers($row));

        return (string) (new AtlasLoopJudgeDisagreementDiagnostic)->diagnose([], $receipts)['category'];
    }

    /** The real ledger fact that makes this row a hard case, or null. Fact-driven, never a heuristic. */
    private function triggerReason(array $row): ?string
    {
        $providers = $this->providers($row);
        if (count($providers) >= 2) {
            $verdicts = array_unique(array_map(static fn (array $p): bool => ($p['passed'] ?? null) === true, $providers));
            if (count($verdicts) > 1) {
                return 'provider_disagreement';
            }
        }
        if (($row['rolled_back'] ?? false) === true) {
            return 'provider_rollback';
        }
        if (array_key_exists('initial_verdict', $row) && array_key_exists('reprove_verdict', $row)
            && ((bool) $row['initial_verdict']) !== ((bool) $row['reprove_verdict'])) {
            return 'reprove_verdict_flip';
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function providers(array $row): array
    {
        return array_values(array_filter((array) ($row['providers'] ?? []), 'is_array'));
    }

    /** @return array{ready:bool,route:string,blockers:list<string>,required_evidence_refs:list<string>} */
    private function buildTaskSeedReadiness(array $row): array
    {
        $frozenHash = trim((string) ($row['bundle_sha256'] ?? ($row['frozen_bundle_hash'] ?? '')));
        $bundleHashes = array_values(array_unique(array_filter(
            array_map(static fn (array $p): string => trim((string) ($p['bundle_sha256'] ?? '')), $this->providers($row)),
            static fn (string $h): bool => $h !== ''
        )));

        if (count($bundleHashes) > 1) {
            return ['ready' => false, 'route' => 'verification_repair', 'blockers' => ['bundle_drift'], 'required_evidence_refs' => ['bundle_sha256']];
        }
        if ($frozenHash === '') {
            return ['ready' => false, 'route' => 'blocked_missing_bundle', 'blockers' => ['missing_frozen_bundle_hash'], 'required_evidence_refs' => ['bundle_sha256']];
        }

        return ['ready' => true, 'route' => 'regrind_hard_case', 'blockers' => [], 'required_evidence_refs' => ['provider_verdicts', 'frozen_bundle_hash']];
    }

    /**
     * @return list<string>
     */
    private function providerSet(array $row): array
    {
        $set = array_values(array_unique(array_filter(array_map(
            static fn (array $p): string => trim((string) ($p['provider'] ?? '')),
            $this->providers($row),
        ), static fn (string $s): bool => $s !== '')));
        sort($set, SORT_STRING);

        return $set;
    }
}
