<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\EvidenceLedgerHashChainIntegrityVerifier;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * MAXL-03 — Integridade do ledger como check vivo do WDG-01 + âncora diária.
 *
 * Roda o `EvidenceLedgerHashChainIntegrityVerifier` sobre as cadeias do dia,
 * emite alerta em `tampered`/`gap` e appenda uma linha diária provider-safe
 * `{date, chain_key, chain_length, tampered, gap, status}` no JSONL de
 * integridade. Zero payload cru — só ids/hashes/contagens.
 */
final class EvidenceLedgerIntegrityWatchdogCheck implements AtlasWatchdogCheck
{
    public const SCHEMA_VERSION = 'atlas.acos.watchdog.evidence_ledger_integrity.v1';

    public const DEFAULT_LEDGER_RELATIVE_PATH = 'storage/atlas/evidence-ledger-integrity/integrity.jsonl';

    /** @var callable(CarbonImmutable): list<array<string,mixed>> */
    private $verifyDay;

    /**
     * @param  callable(CarbonImmutable): list<array<string,mixed>>|null  $verifyDay
     *   Injectable seam so tests can drive the check without touching the
     *   final verifier or the live ledger table.
     */
    public function __construct(
        private readonly EvidenceLedgerHashChainIntegrityVerifier $verifier,
        private readonly ?string $ledgerPath = null,
        private readonly CarbonImmutable|string|null $day = null,
        ?callable $verifyDay = null,
    ) {
        $this->verifyDay = $verifyDay ?? function (CarbonImmutable $day): array {
            return $this->verifier->verifyStoredChainsForDay($day);
        };
    }

    public function id(): string
    {
        return 'wdg-01.evidence_ledger_integrity';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $day = $this->resolveDay();
        $date = $day->toDateString();

        try {
            $chains = ($this->verifyDay)($day);
        } catch (Throwable $e) {
            return AtlasWatchdogCheckResult::error([
                'schema_version' => self::SCHEMA_VERSION,
                'date' => $date,
                'reason' => 'verifier_threw',
            ], [
                'code' => 'evidence_ledger_verifier_error',
                'message' => 'Evidence ledger integrity verifier threw: '.$e->getMessage(),
            ]);
        }

        $safeChains = [];
        $tamperedTotal = 0;
        $gapTotal = 0;
        foreach ($chains as $chain) {
            $chainKey = (string) ($chain['chain_key'] ?? '');
            $status = (string) ($chain['status'] ?? 'ok');
            $length = (int) ($chain['chain_length'] ?? 0);
            $tampered = array_values(AiValueNormalizer::arrayOrEmpty($chain['tampered_event_ids'] ?? null));
            $gap = (int) ($chain['gap_count'] ?? 0);
            $legacy = (int) ($chain['legacy_unchained_count'] ?? 0);
            $tamperedTotal += count($tampered);
            $gapTotal += $gap;

            $safeChains[] = [
                'chain_key' => $chainKey,
                'chain_length' => $length,
                'status' => $status,
                'gap_count' => $gap,
                'tampered_count' => count($tampered),
                'tampered_event_ids' => $tampered,
                'legacy_unchained_count' => $legacy,
            ];
        }

        $line = [
            'schema_version' => self::SCHEMA_VERSION,
            'date' => $date,
            'chains' => count($safeChains),
            'chain_details' => $safeChains,
            'tampered_total' => $tamperedTotal,
            'gap_total' => $gapTotal,
        ];

        try {
            AppendOnlyJsonlStore::append($this->path(), $line);
        } catch (Throwable) {
            // JSONL persistence is best-effort; the alerting path below still
            // returns a truthful status even if the disk append fails.
        }

        $evidence = [
            'schema_version' => self::SCHEMA_VERSION,
            'date' => $date,
            'chains' => count($safeChains),
            'chain_length' => array_sum(array_column($safeChains, 'chain_length')),
            'tampered_event_ids' => array_values(array_merge(
                ...array_map(static fn (array $c): array => $c['tampered_event_ids'], $safeChains),
            )),
            'gap_count' => $gapTotal,
            'artifact' => $this->path(),
        ];

        if ($tamperedTotal > 0) {
            return AtlasWatchdogCheckResult::alert(
                $evidence + ['reason' => 'tampered'],
                [
                    'code' => 'evidence_ledger_tampered',
                    'message' => 'Evidence ledger chain integrity verifier detected tampered events.',
                    'tampered_event_ids' => $evidence['tampered_event_ids'],
                ],
            );
        }

        if ($gapTotal > 0) {
            return AtlasWatchdogCheckResult::alert(
                $evidence + ['reason' => 'gap'],
                [
                    'code' => 'evidence_ledger_gap',
                    'message' => 'Evidence ledger chain integrity verifier detected chain gaps.',
                    'gap_count' => $gapTotal,
                ],
            );
        }

        return AtlasWatchdogCheckResult::ok($evidence + ['reason' => 'chains_intact']);
    }

    public function path(): string
    {
        if ($this->ledgerPath !== null && $this->ledgerPath !== '') {
            return $this->ledgerPath;
        }

        if (function_exists('base_path')) {
            return base_path(self::DEFAULT_LEDGER_RELATIVE_PATH);
        }

        return self::DEFAULT_LEDGER_RELATIVE_PATH;
    }

    private function resolveDay(): CarbonImmutable
    {
        if ($this->day instanceof CarbonInterface) {
            return CarbonImmutable::instance($this->day);
        }

        return CarbonImmutable::parse($this->day ?? 'now');
    }
}
