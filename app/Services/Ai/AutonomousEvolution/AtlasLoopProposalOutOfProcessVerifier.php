<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasDeadCodeAnalyzer;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasEngineeringHonestyGate;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Symfony\Component\Process\Process;

/**
 * Independent verifier for persisted unified-loop proposals.
 *
 * It replays each proposal from JSONL in a clean git worktree, runs the frozen
 * acceptance in a child process, proves the diff is earned by reverting to RED,
 * and reruns the engineering gate over the clean checkout contents. It never
 * writes to the source checkout and never promotes or merges a proposal.
 */
final class AtlasLoopProposalOutOfProcessVerifier
{
    public const SCHEMA = 'atlas.loop.proposal_independent_verdict.v1';

    public function __construct(
        private readonly AtlasLoopProposalDiffReconstructor $diffReconstructor,
        private readonly AtlasEngineeringHonestyGate $gate,
        private readonly AtlasDeadCodeAnalyzer $deadCode,
        private readonly ?AtlasLoopAdversarialVerifierPool $adversarialVerifierPool = null,
    ) {}

    /**
     * @param  array{limit?:int, refuters?:int, refuter_provider?:?string, force?:bool}  $options
     * @return array<string,mixed>
     */
    public function verifyFile(string $repoRoot, string $runDir, string $proposalsPath, array $options = []): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $runDir = rtrim($runDir, '/');
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $force = (bool) ($options['force'] ?? false);

        if (! is_file($proposalsPath)) {
            return [
                'schema_version' => self::SCHEMA.'.summary',
                'status' => 'failed',
                'reason' => 'proposals_file_missing',
                'proposals_path' => $proposalsPath,
            ];
        }

        @mkdir($runDir, 0o755, true);
        $verifiedPath = $runDir.'/independently_verified.jsonl';
        $refutedPath = $runDir.'/refuted.jsonl';
        if ($force) {
            @unlink($verifiedPath);
            @unlink($refutedPath);
        }
        @touch($verifiedPath);
        @touch($refutedPath);

        $seen = $this->seenProposalHashes([$verifiedPath, $refutedPath]);
        $summary = [
            'schema_version' => self::SCHEMA.'.summary',
            'status' => 'ok',
            'repo_root' => $repoRoot,
            'run_dir' => $runDir,
            'proposals_path' => $proposalsPath,
            'processed' => 0,
            'skipped_existing' => 0,
            'independently_verified' => 0,
            'refuted' => 0,
            'limit' => $limit,
            'refuters_requested' => max(0, (int) ($options['refuters'] ?? 0)),
            'refuter_provider' => $options['refuter_provider'] ?? null,
            'provider_refuters_executed' => 0,
            'provider_refuters_note' => 'not_wired_in_this_deterministic_replay',
            'outputs' => [
                'independently_verified' => $verifiedPath,
                'refuted' => $refutedPath,
            ],
        ];

        $lineNo = 0;
        $handle = fopen($proposalsPath, 'rb');
        if ($handle === false) {
            $summary['status'] = 'failed';
            $summary['reason'] = 'proposals_file_unreadable';

            return $summary;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $lineNo++;
                if (trim($line) === '') {
                    continue;
                }
                if ($limit > 0 && (int) $summary['processed'] >= $limit) {
                    break;
                }

                $record = json_decode($line, true);
                if (! is_array($record)) {
                    $verdict = $this->verdict('refuted', ['invalid_jsonl_record'], [], [
                        'source_line' => $lineNo,
                    ]);
                    AppendOnlyJsonlStore::appendUsingFilePutContents(
                        $refutedPath,
                        $verdict,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                        FILE_APPEND,
                        0o755,
                    );
                    $summary['processed']++;
                    $summary['refuted']++;
                    continue;
                }

                $hash = $this->proposalHash($record);
                if ($hash !== '' && isset($seen[$hash])) {
                    $summary['skipped_existing']++;
                    continue;
                }

                $verdict = $this->verifyRecord($repoRoot, $record, $lineNo, $options);
                $outcome = (string) $verdict['outcome'];
                AppendOnlyJsonlStore::appendUsingFilePutContents(
                    $outcome === 'independently_verified' ? $verifiedPath : $refutedPath,
                    $verdict,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    FILE_APPEND,
                    0o755,
                );
                if ($hash !== '') {
                    $seen[$hash] = true;
                }

                $summary['processed']++;
                if ($outcome === 'independently_verified') {
                    $summary['independently_verified']++;
                } else {
                    $summary['refuted']++;
                }
            }
        } finally {
            fclose($handle);
        }

        $summary['total_independently_verified'] = $this->jsonlCount($verifiedPath);
        $summary['total_refuted'] = $this->jsonlCount($refutedPath);
        $summary['total_recorded'] = (int) $summary['total_independently_verified'] + (int) $summary['total_refuted'];

        $this->writeJson($runDir.'/independent_verification_summary.json', $summary + ['generated_at' => time()]);

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function verifyRecord(string $repoRoot, array $record, int $sourceLine = 0, array $options = []): array
    {
        $mode = (string) ($record['mode'] ?? '');
        $originRel = ltrim((string) ($record['path'] ?? ''), '/');
        $proposal = is_array($record['proposal'] ?? null) ? $record['proposal'] : [];
        $proposalHash = $this->proposalHash($record);
        $diff = (string) ($proposal['diff_text'] ?? '');
        $filename = $this->targetFilename($mode);

        $base = [
            'source_line' => $sourceLine,
            'mode' => $mode,
            'path' => $originRel,
            'proposal_hash' => $proposalHash,
            'provider' => $proposal['provider'] ?? null,
            'original_outcome' => $record['outcome'] ?? null,
            'refuters_requested' => max(0, (int) ($options['refuters'] ?? 0)),
            'refuter_provider' => $options['refuter_provider'] ?? null,
            'provider_refuters_executed' => 0,
        ];

        if (! in_array($mode, ['deadcode', 'docs_structure'], true)) {
            return $this->verdict('refuted', ['unsupported_mode('.$mode.')'], [], $base);
        }
        if ($originRel === '' || $diff === '') {
            return $this->verdict('refuted', ['missing_path_or_diff'], [], $base);
        }

        $worktree = $this->createWorktree($repoRoot);
        if (! $worktree['ok']) {
            return $this->verdict('refuted', [$worktree['reason']], ['clean_checkout' => false], $base);
        }

        $worktreePath = (string) $worktree['path'];
        try {
            $originAbs = $worktreePath.'/'.$originRel;
            if (! is_file($originAbs)) {
                return $this->verdict('refuted', ['target_missing_in_clean_checkout'], ['clean_checkout' => true], $base);
            }

            $original = (string) file_get_contents($originAbs);
            $baseline = $this->runAcceptance($mode, $originAbs);
            if ($baseline['metric'] === null || (int) $baseline['metric'] >= 999) {
                return $this->verdict('refuted', ['baseline_inconclusive'], [
                    'clean_checkout' => true,
                    'baseline_red' => false,
                    'baseline_acceptance' => $baseline,
                ], $base);
            }
            if ($baseline['ok']) {
                return $this->verdict('refuted', ['change_is_inert'], [
                    'clean_checkout' => true,
                    'baseline_red' => false,
                    'baseline_acceptance' => $baseline,
                ], $base);
            }

            $deadMembers = [];
            if ($mode === 'deadcode') {
                $analysis = $this->deadCode->analyzeFile($originAbs);
                if (! $analysis['parseable']) {
                    return $this->verdict('refuted', ['baseline_deadcode_unparseable'], [
                        'clean_checkout' => true,
                        'baseline_red' => true,
                        'baseline_acceptance' => $baseline,
                    ], $base);
                }
                $deadMembers = $analysis['dead'];
            }

            $reconstructed = $this->diffReconstructor->reconstruct($original, $diff, $filename);
            if (! $reconstructed['ok'] || ! is_string($reconstructed['content'])) {
                return $this->verdict('refuted', [$reconstructed['reason'] ?? 'does_not_apply_clean'], [
                    'clean_checkout' => true,
                    'baseline_red' => true,
                    'diff_applies_clean' => false,
                    'baseline_acceptance' => $baseline,
                ], $base);
            }

            file_put_contents($originAbs, $reconstructed['content']);
            $proposed = $this->runAcceptance($mode, $originAbs);
            if (! $proposed['ok']) {
                return $this->verdict('refuted', ['acceptance_not_green'], [
                    'clean_checkout' => true,
                    'baseline_red' => true,
                    'diff_applies_clean' => true,
                    'acceptance_green' => false,
                    'baseline_acceptance' => $baseline,
                    'proposed_acceptance' => $proposed,
                ], $base);
            }

            $gateVerdict = $mode === 'deadcode'
                ? $this->gate->evaluateDeadCodeRemoval($worktreePath, $originRel, $original, $reconstructed['content'], $deadMembers)
                : $this->gate->evaluateDocEdit($originRel, $original, $reconstructed['content']);
            if (! $gateVerdict['certified']) {
                return $this->verdict('refuted', ['honesty_gate_rejected'], [
                    'clean_checkout' => true,
                    'baseline_red' => true,
                    'diff_applies_clean' => true,
                    'acceptance_green' => true,
                    'honesty_gate' => false,
                    'baseline_acceptance' => $baseline,
                    'proposed_acceptance' => $proposed,
                    'gate_reasons' => $gateVerdict['reasons'],
                    'gate_report' => $gateVerdict['report'],
                ], $base);
            }

            file_put_contents($originAbs, $original);
            $revert = $this->runAcceptance($mode, $originAbs);
            if ($revert['ok']) {
                return $this->verdict('refuted', ['change_is_inert'], [
                    'clean_checkout' => true,
                    'baseline_red' => true,
                    'diff_applies_clean' => true,
                    'acceptance_green' => true,
                    'honesty_gate' => true,
                    'revert_red' => false,
                    'baseline_acceptance' => $baseline,
                    'proposed_acceptance' => $proposed,
                    'revert_acceptance' => $revert,
                    'gate_report' => $gateVerdict['report'],
                ], $base);
            }

            return $this->adversarialReview($record, $this->verdict('independently_verified', ['independently_verified'], [
                'clean_checkout' => true,
                'baseline_red' => true,
                'diff_applies_clean' => true,
                'acceptance_green' => true,
                'honesty_gate' => true,
                'revert_red' => true,
                'baseline_acceptance' => $baseline,
                'proposed_acceptance' => $proposed,
                'revert_acceptance' => $revert,
                'gate_report' => $gateVerdict['report'],
            ], $base), $options);
        } finally {
            $this->removeWorktree($repoRoot, $worktreePath);
        }
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $primaryVerdict
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function adversarialReview(array $record, array $primaryVerdict, array $options): array
    {
        return ($this->adversarialVerifierPool ?? new AtlasLoopAdversarialVerifierPool)
            ->reviewVerdict($record, $primaryVerdict, $options);
    }

    /**
     * @return array{ok:bool,path?:string,reason:string}
     */
    private function createWorktree(string $repoRoot): array
    {
        $path = sys_get_temp_dir().'/atlas-loop-clean-'.bin2hex(random_bytes(5));
        $process = new Process(['git', 'worktree', 'add', '--detach', $path, 'HEAD'], $repoRoot, null, null, 120.0);
        $process->run();
        if (! $process->isSuccessful()) {
            return ['ok' => false, 'reason' => 'clean_checkout_failed', 'path' => $path];
        }

        return ['ok' => true, 'path' => $path, 'reason' => 'ok'];
    }

    private function removeWorktree(string $repoRoot, string $path): void
    {
        if ($path === '' || ! str_contains($path, 'atlas-loop-clean-')) {
            return;
        }
        (new Process(['git', 'worktree', 'remove', '--force', $path], $repoRoot, null, null, 120.0))->run();
        if (is_dir($path)) {
            (new Process(['rm', '-rf', $path]))->run();
        }
    }

    /**
     * @return array{command:list<string>, ok:bool, exit_code:int, metric:?int, output:string}
     */
    private function runAcceptance(string $mode, string $targetAbs): array
    {
        $command = match ($mode) {
            'deadcode' => [PHP_BINARY, base_path('artisan'), 'atlas:code:deadcode-check', '--path='.$targetAbs, '--json'],
            'docs_structure' => [PHP_BINARY, base_path('artisan'), 'atlas:docs:lint-file', '--path='.$targetAbs, '--json'],
            default => [PHP_BINARY, '-r', 'exit(1);'],
        };
        $process = new Process($command, base_path(), null, null, 120.0);
        $process->run();
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        return [
            'command' => $command,
            'ok' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode() ?? 1,
            'metric' => $this->metricFromOutput($mode, $output),
            'output' => mb_substr($output, 0, 4000),
        ];
    }

    private function metricFromOutput(string $mode, string $output): ?int
    {
        $key = $mode === 'deadcode' ? 'ATLAS_DEADCODE' : 'ATLAS_DOC_VIOLATIONS';
        if (preg_match('/'.$key.'=(\d+)/', $output, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    private function targetFilename(string $mode): string
    {
        return $mode === 'docs_structure' ? 'target.md' : 'target.php';
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function proposalHash(array $record): string
    {
        $proposal = is_array($record['proposal'] ?? null) ? $record['proposal'] : [];

        return (string) ($proposal['proposal_hash'] ?? $record['proposal_hash'] ?? '');
    }

    /**
     * @param  list<string>  $paths
     * @return array<string,true>
     */
    private function seenProposalHashes(array $paths): array
    {
        $seen = [];
        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded) && (string) ($decoded['proposal_hash'] ?? '') !== '') {
                    $seen[(string) $decoded['proposal_hash']] = true;
                }
            }
        }

        return $seen;
    }

    private function jsonlCount(string $path): int
    {
        if (! is_file($path)) {
            return 0;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? count($lines) : 0;
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $checks
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function verdict(string $outcome, array $reasons, array $checks, array $base): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'at' => time(),
            'outcome' => $outcome,
            'reasons' => $reasons,
            'merged_to_main' => false,
            'checks' => $checks,
        ] + $base;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function writeJson(string $path, array $data): void
    {
        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
