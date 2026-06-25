<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModEditClassifier;
use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModInvariantExtractor;
use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModProofReceiptLedger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-facing surface for the entire self-mod safety stack:
 *   classify   — print the edit classifier verdict for a file (--file) vs --against ref.
 *   invariants — list extracted @invariant tags for a class (--class) or all Loop classes.
 *   verify     — run the full invariant-survival check against the working tree; fail-closed exit 1 on
 *                UNCHECKABLE or REJECTED.
 *   history    — read the proof-receipt ledger, optionally filtered by --since / --status / --receipt.
 */
final class AtlasLoopSelfModCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:selfmod {action : classify|invariants|verify|history} {--file=} {--class=} {--receipt=} {--since=} {--status=} {--against=HEAD} {--json}';

    /** @var string */
    protected $description = 'Self-mod safety surface: classify / invariants / verify / history.';

    public function handle(): int
    {
        $action = trim((string) $this->argument('action'));

        return match ($action) {
            'classify' => $this->classify(),
            'invariants' => $this->invariants(),
            'verify' => $this->verify(),
            'history' => $this->history(),
            default => $this->usage($action),
        };
    }

    private function classify(): int
    {
        $file = trim((string) $this->option('file'));
        if ($file === '') {
            return $this->emit(['status' => 'usage_error', 'reason' => '--file is required for classify'], self::INVALID);
        }
        $after = is_file($file) ? (string) @file_get_contents($file) : '';
        $before = $this->fileContentsAtRef($file, trim((string) $this->option('against')));

        try {
            $classifier = $this->app()->make(AtlasLoopSelfModEditClassifier::class);
            $verdict = $classifier->classify($file, $before, $after);
        } catch (Throwable $e) {
            return $this->emit(['status' => 'error', 'message' => $e->getMessage(), 'target_file' => $file], self::INVALID);
        }

        $payload = is_array($verdict) ? $verdict : ['raw' => $verdict];
        $payload['target_file'] ??= $file;
        $payload['kind'] ??= (string) ($payload['classification'] ?? 'unknown');
        $payload['evidence'] ??= ($payload['evidence'] ?? []);

        return $this->emit($payload, self::SUCCESS);
    }

    private function invariants(): int
    {
        $class = trim((string) $this->option('class'));
        $classes = $class === '' ? [] : [$class];

        try {
            $extractor = $this->app()->make(AtlasLoopSelfModInvariantExtractor::class);
            $result = $extractor->extract($classes);
        } catch (Throwable $e) {
            return $this->emit(['status' => 'error', 'message' => $e->getMessage()], self::INVALID);
        }

        return $this->emit(['invariants' => $result], self::SUCCESS);
    }

    private function verify(): int
    {
        try {
            $checker = $this->resolveChecker();
            if ($checker === null) {
                return $this->emit(['status' => 'UNCHECKABLE', 'reason' => 'survival_checker_unwired', 'violations' => []], self::FAILURE);
            }
            $result = $this->invokeChecker($checker);
        } catch (Throwable $e) {
            return $this->emit(['status' => 'UNCHECKABLE', 'reason' => 'checker_threw:'.$e->getMessage(), 'violations' => []], self::FAILURE);
        }

        $status = (string) ($result['proof_status'] ?? ($result['status'] ?? 'UNCHECKABLE'));
        $violations = (array) ($result['violations'] ?? []);
        $payload = ['status' => $status, 'violations' => array_values($violations)] + (is_array($result) ? $result : []);

        $exit = $status === AtlasLoopSelfModProofReceiptLedger::PROOF_APPROVED ? self::SUCCESS : self::FAILURE;

        return $this->emit($payload, $exit);
    }

    private function history(): int
    {
        try {
            $ledger = $this->app()->make(AtlasLoopSelfModProofReceiptLedger::class);
        } catch (Throwable $e) {
            return $this->emit(['status' => 'error', 'message' => $e->getMessage()], self::INVALID);
        }

        $receipt = trim((string) $this->option('receipt'));
        if ($receipt !== '') {
            $hit = $ledger->byReceiptId($receipt);

            return $this->emit($hit ?? ['status' => 'not_found', 'receipt_id' => $receipt], self::SUCCESS);
        }

        $rows = $ledger->all();
        $status = trim((string) $this->option('status'));
        if ($status !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['proof_status'] ?? '') === $status));
        }
        $since = trim((string) $this->option('since'));
        if ($since !== '') {
            $sinceTs = (int) strtotime($since);
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) strtotime((string) ($r['ts_iso8601'] ?? '')) >= $sinceTs));
        }

        return $this->emit($rows, self::SUCCESS);
    }

    /**
     * @return mixed  duck-typed checker (an AtlasLoopSelfModInvariantSurvivalChecker or compatible)
     */
    private function resolveChecker(): mixed
    {
        try {
            return $this->app()->make(\App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModInvariantSurvivalChecker::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function invokeChecker(object $checker): array
    {
        // The checker's signature is heavy and varies by binding; the tests bind a stub whose check(...args)
        // returns an array directly. Production wires the real one via the container.
        try {
            $result = $checker->check();
        } catch (Throwable $e) {
            return ['proof_status' => 'UNCHECKABLE', 'violations' => [], 'message' => 'checker_check_threw:'.$e->getMessage()];
        }

        return is_array($result) ? $result : ['proof_status' => 'UNCHECKABLE', 'violations' => []];
    }

    /**
     * Read $file as it stood at $ref using `git show`. Empty string when the ref or file is unavailable.
     */
    private function fileContentsAtRef(string $file, string $ref): string
    {
        if ($ref === '') {
            return '';
        }
        $repoRoot = function_exists('base_path') ? (string) base_path() : (string) getcwd();
        $rel = $this->relativeToRepo($repoRoot, $file);
        if ($rel === null) {
            return '';
        }
        $cmd = sprintf('cd %s && git show %s:%s 2>/dev/null', escapeshellarg($repoRoot), escapeshellarg($ref), escapeshellarg($rel));
        $out = @shell_exec($cmd);

        return is_string($out) ? $out : '';
    }

    private function relativeToRepo(string $repoRoot, string $absOrRel): ?string
    {
        $repoRoot = rtrim($repoRoot, '/').'/';
        if (str_starts_with($absOrRel, '/')) {
            return str_starts_with($absOrRel, $repoRoot) ? substr($absOrRel, strlen($repoRoot)) : null;
        }

        return $absOrRel;
    }

    private function usage(string $action): int
    {
        return $this->emit([
            'status' => 'usage_error',
            'reason' => 'unknown action: '.$action,
            'allowed' => ['classify', 'invariants', 'verify', 'history'],
        ], self::INVALID);
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        return $exit;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
