<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Evidence;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\EvidenceLedgerHashChainIntegrityVerifier;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class EvidenceLedgerDailyChainHeadAnchor
{
    public const SCHEMA_VERSION = 'atlas.evidence.ledger_chain_head_anchor.v1';

    public const DEFAULT_ANCHOR_RELATIVE_PATH = 'docs/engineering-knowledge-base/evidence-ledger-chain-head-anchors.jsonl';

    /**
     * @param  callable():string|null  $gitHeadResolver
     */
    public function __construct(
        private readonly EvidenceLedgerHashChainIntegrityVerifier $verifier,
        private readonly ?string $anchorPath = null,
        private readonly mixed $gitHeadResolver = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function anchorDay(CarbonInterface|string|null $day = null): array
    {
        $anchorDay = $day instanceof CarbonInterface
            ? CarbonImmutable::instance($day)
            : CarbonImmutable::parse($day ?? 'now');
        $chains = $this->verifier->chainHeadsForDay($anchorDay);

        $row = [
            'schema_version' => self::SCHEMA_VERSION,
            'anchor_date' => $anchorDay->toDateString(),
            'anchored_at' => CarbonImmutable::now('UTC')->toISOString(),
            'external_anchor' => 'git_tracked_jsonl',
            'git_head' => $this->gitHead(),
            'storage_basis' => 'postgres_hash_chain_plus_git_committed_jsonl',
            'threat_model' => 'covers_accidental_or_partial_db_deletion_reordering_payload_tamper; does_not_cover_process_with_db_and_worktree_access_that_reseals_and_recommits',
            'chain_count' => count($chains),
            'chains' => $chains,
        ];
        $row['anchor_hash'] = self::anchorHash($row);

        AppendOnlyJsonlStore::append($this->path(), $row);

        return $row;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    public static function anchorHash(array $row): string
    {
        unset($row['anchor_hash']);
        ksort($row);

        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function path(): string
    {
        return $this->anchorPath ?? base_path(self::DEFAULT_ANCHOR_RELATIVE_PATH);
    }

    private function gitHead(): string
    {
        if (is_callable($this->gitHeadResolver)) {
            $resolved = (string) ($this->gitHeadResolver)();

            return $resolved !== '' ? $resolved : 'unavailable';
        }

        $output = [];
        $code = 1;
        @exec('git -C '.escapeshellarg(base_path()).' rev-parse HEAD 2>/dev/null', $output, $code);
        $head = trim((string) ($output[0] ?? ''));

        return $code === 0 && preg_match('/^[a-f0-9]{40}$/', $head) === 1 ? $head : 'unavailable';
    }
}
