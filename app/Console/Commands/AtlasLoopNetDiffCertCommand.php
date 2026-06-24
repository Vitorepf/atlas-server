<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopNetDiffCertCollector;
use App\Services\Ai\AutonomousEvolution\AtlasLoopNetDiffCertJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopNetDiffCertReceiptLedger;
use Closure;
use Illuminate\Console\Command;

/**
 * Operator front door for the net-diff cert pipeline. Two subcommands:
 *
 *   verify --cert-id=X --attempt-id=Y
 *     Resolve baseline + candidate measurements via the
 *     {@see AtlasLoopNetDiffCertCommand::MEASUREMENT_RESOLVER_KEY} container binding, run
 *     {@see AtlasLoopNetDiffCertCollector::collect()} → {@see AtlasLoopNetDiffCertJudge::judge()},
 *     append the verdict to {@see AtlasLoopNetDiffCertReceiptLedger}, and print the public receipt
 *     fields + verdict. Provider-free; only the public ledger fields are ever printed.
 *
 *   history --cert-id=X [--limit=N]
 *     Read the ledger's per-cert append-order timeline with chain hashes for audit and print it.
 *
 * Exit codes (the contract cron / operator scripts branch on):
 *   0  PASS or history-shown-ok
 *   1  REGRESSED
 *   2  FLAT
 *   3  ABSTAIN (unarmed)
 *   10 usage error
 */
final class AtlasLoopNetDiffCertCommand extends Command
{
    /**
     * Container binding key for the per-(cert_id, attempt_id) measurement resolver. The binding (when
     * present) must return a Closure: `function (string $certId, string $attemptId): array { return
     * ['baseline_side'=>[...], 'candidate_side'=>[...]]; }`. When absent the Collector receives empty
     * sides and returns unarmed ⇒ the command exits 3 (ABSTAIN).
     */
    public const MEASUREMENT_RESOLVER_KEY = 'atlas.loop.netdiff_cert.measurement_resolver';

    public const EXIT_OK = 0;

    public const EXIT_REGRESSED = 1;

    public const EXIT_FLAT = 2;

    public const EXIT_ABSTAIN = 3;

    public const EXIT_USAGE = 10;

    protected $signature = 'atlas:loop:netdiff-cert {action : verify|history} {--cert-id=} {--attempt-id=} {--limit=20}';

    protected $description = 'Operator CLI for net-diff cert verdicts: verify a (cert,attempt) end-to-end or print the audit timeline for a cert.';

    public function handle(
        AtlasLoopNetDiffCertCollector $collector,
        AtlasLoopNetDiffCertJudge $judge,
        AtlasLoopNetDiffCertReceiptLedger $ledger,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'verify' => $this->verify($collector, $judge, $ledger),
            'history' => $this->history($ledger),
            default => $this->usage('unknown action: '.$action),
        };
    }

    private function verify(AtlasLoopNetDiffCertCollector $collector, AtlasLoopNetDiffCertJudge $judge, AtlasLoopNetDiffCertReceiptLedger $ledger): int
    {
        $certId = trim((string) $this->option('cert-id'));
        $attemptId = trim((string) $this->option('attempt-id'));
        if ($certId === '' || $attemptId === '') {
            return $this->usage('--cert-id and --attempt-id are required for verify');
        }

        [$baseline, $candidate] = $this->resolveSides($certId, $attemptId);
        $collected = $collector->collect($certId, $baseline, $candidate);
        $verdict = $judge->judge($collected);

        $receipt = $ledger->append([
            'cert_id' => $certId,
            'attempt_id' => $attemptId,
            'verdict' => (string) ($verdict['verdict'] ?? AtlasLoopNetDiffCertJudge::VERDICT_ABSTAIN),
            'metric_kind' => $verdict['metric_kind'] ?? null,
            'baseline' => $verdict['baseline'] ?? null,
            'candidate' => $verdict['candidate'] ?? null,
            'signed_delta' => $verdict['signed_delta'] ?? null,
            'min_delta_used' => (float) ($verdict['min_delta_used'] ?? 0.0),
            'tolerance_used' => (float) ($verdict['tolerance_used'] ?? 0.0),
            'baseline_sha' => (string) (($collected['baseline_side'] ?? [])['source_sha'] ?? ''),
            'candidate_sha' => (string) (($collected['candidate_side'] ?? [])['source_sha'] ?? ''),
            'collector_reason' => $collected['reason'] ?? null,
            'judge_reason' => $verdict['reason'] ?? null,
        ]);

        $this->printReceipt($receipt, $verdict);

        return $this->exitForVerdict((string) ($verdict['verdict'] ?? ''));
    }

    private function history(AtlasLoopNetDiffCertReceiptLedger $ledger): int
    {
        $certId = trim((string) $this->option('cert-id'));
        if ($certId === '') {
            return $this->usage('--cert-id is required for history');
        }
        $limit = max(1, (int) $this->option('limit'));

        $rows = $ledger->history($certId);
        $tail = array_slice($rows, -$limit);
        foreach ($tail as $row) {
            $this->line(sprintf(
                '%s  %s  attempt=%s  chain=%s  prev=%s',
                (string) ($row['recorded_at'] ?? ''),
                str_pad((string) ($row['verdict'] ?? ''), 9),
                (string) ($row['attempt_id'] ?? ''),
                substr(sha1((string) json_encode($row)), 0, 12),
                substr((string) ($row['prior_receipt_chain_sha'] ?? ''), 0, 12) ?: 'genesis',
            ));
        }

        return self::EXIT_OK;
    }

    /**
     * @return array{0:array<string,mixed>, 1:array<string,mixed>}
     */
    private function resolveSides(string $certId, string $attemptId): array
    {
        if (! app()->bound(self::MEASUREMENT_RESOLVER_KEY)) {
            return [[], []]; // ⇒ Collector returns unarmed ⇒ ABSTAIN.
        }
        $resolver = app(self::MEASUREMENT_RESOLVER_KEY);
        if (! $resolver instanceof Closure) {
            return [[], []];
        }
        $result = $resolver($certId, $attemptId);
        $baseline = is_array($result['baseline_side'] ?? null) ? (array) $result['baseline_side'] : [];
        $candidate = is_array($result['candidate_side'] ?? null) ? (array) $result['candidate_side'] : [];

        return [$baseline, $candidate];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $verdict
     */
    private function printReceipt(array $receipt, array $verdict): void
    {
        // ONLY public receipt fields — no provider internals.
        $this->line('verdict='.((string) ($verdict['verdict'] ?? ''))
            .' cert_id='.((string) ($receipt['cert_id'] ?? ''))
            .' attempt_id='.((string) ($receipt['attempt_id'] ?? ''))
            .' receipt_id='.((string) ($receipt['id'] ?? '')));
    }

    private function exitForVerdict(string $verdict): int
    {
        return match ($verdict) {
            AtlasLoopNetDiffCertJudge::VERDICT_PASS => self::EXIT_OK,
            AtlasLoopNetDiffCertJudge::VERDICT_REGRESSED => self::EXIT_REGRESSED,
            AtlasLoopNetDiffCertJudge::VERDICT_FLAT => self::EXIT_FLAT,
            default => self::EXIT_ABSTAIN,
        };
    }

    private function usage(string $message): int
    {
        $this->error($message);

        return self::EXIT_USAGE;
    }
}
