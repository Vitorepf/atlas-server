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
            ];
        }

        return $cases;
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
