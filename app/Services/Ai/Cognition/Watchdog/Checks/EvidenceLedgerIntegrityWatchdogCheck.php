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
    public const REASON_CHAINS_INTACT = 'chains_intact';

    public const STATUS_OK = 'ok';

    public const REASON_GAP = 'gap';

    public const REASON_TAMPERED = 'tampered';

    public const REASON_VERIFIER_THREW = 'verifier_threw';

    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_STATUS = 'status';
    public const FIELD_REASON = 'reason';
    public const FIELD_DATE = 'date';
    public const FIELD_CODE = 'code';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_CHAIN_KEY = 'chain_key';
    public const FIELD_CHAIN_LENGTH = 'chain_length';
    public const FIELD_GAP_COUNT = 'gap_count';
    public const FIELD_TAMPERED_EVENT_IDS = 'tampered_event_ids';
    public const FIELD_CHAINS = 'chains';


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
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_DATE => $date,
                self::FIELD_REASON => self::REASON_VERIFIER_THREW,
            ], [
                self::FIELD_CODE => 'evidence_ledger_verifier_error',
                self::FIELD_MESSAGE => 'Evidence ledger integrity verifier threw: '.$e->getMessage(),
            ]);
        }

        $safeChains = [];
        $tamperedTotal = 0;
        $gapTotal = 0;
        foreach ($chains as $chain) {
            $chainKey = (AiValueNormalizer::trimmedStringOrNull($chain[self::FIELD_CHAIN_KEY] ?? null) ?? '');
            $status = (AiValueNormalizer::trimmedStringOrNull($chain[self::FIELD_STATUS] ?? null) ?? self::STATUS_OK);
            $length = (int) (AiValueNormalizer::finiteFloatOrNull($chain[self::FIELD_CHAIN_LENGTH] ?? null) ?? 0);
            $tampered = array_values(AiValueNormalizer::arrayOrEmpty($chain[self::FIELD_TAMPERED_EVENT_IDS] ?? null));
            $gap = (int) (AiValueNormalizer::finiteFloatOrNull($chain[self::FIELD_GAP_COUNT] ?? null) ?? 0);
            $legacy = (int) (AiValueNormalizer::finiteFloatOrNull($chain['legacy_unchained_count'] ?? null) ?? 0);
            $tamperedTotal += count($tampered);
            $gapTotal += $gap;

            $safeChains[] = [
                self::FIELD_CHAIN_KEY => $chainKey,
                self::FIELD_CHAIN_LENGTH => $length,
                self::FIELD_STATUS => $status,
                self::FIELD_GAP_COUNT => $gap,
                'tampered_count' => count($tampered),
                self::FIELD_TAMPERED_EVENT_IDS => $tampered,
                'legacy_unchained_count' => $legacy,
            ];
        }

        $line = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_DATE => $date,
            self::FIELD_CHAINS => count($safeChains),
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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_DATE => $date,
            self::FIELD_CHAINS => count($safeChains),
            self::FIELD_CHAIN_LENGTH => array_sum(array_column($safeChains, 'chain_length')),
            self::FIELD_TAMPERED_EVENT_IDS => array_values(array_merge(
                ...array_map(static fn (array $c): array => $c[self::FIELD_TAMPERED_EVENT_IDS], $safeChains),
            )),
            self::FIELD_GAP_COUNT => $gapTotal,
            'artifact' => $this->path(),
        ];

        if ($tamperedTotal > 0) {
            return AtlasWatchdogCheckResult::alert(
                $evidence + [self::FIELD_REASON => self::REASON_TAMPERED],
                [
                    self::FIELD_CODE => 'evidence_ledger_tampered',
                    self::FIELD_MESSAGE => 'Evidence ledger chain integrity verifier detected tampered events.',
                    self::FIELD_TAMPERED_EVENT_IDS => $evidence[self::FIELD_TAMPERED_EVENT_IDS],
                ],
            );
        }

        if ($gapTotal > 0) {
            return AtlasWatchdogCheckResult::alert(
                $evidence + [self::FIELD_REASON => self::REASON_GAP],
                [
                    self::FIELD_CODE => 'evidence_ledger_gap',
                    self::FIELD_MESSAGE => 'Evidence ledger chain integrity verifier detected chain gaps.',
                    self::FIELD_GAP_COUNT => $gapTotal,
                ],
            );
        }

        return AtlasWatchdogCheckResult::ok($evidence + [self::FIELD_REASON => self::REASON_CHAINS_INTACT]);
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
