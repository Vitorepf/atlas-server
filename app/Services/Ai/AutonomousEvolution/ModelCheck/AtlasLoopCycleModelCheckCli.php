<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ModelCheck;

/**
 * Pure orchestration service for the Loop model-check CLI surface.
 *
 *   extract  — invokes AtlasLoopCycleStateMachineExtractor and persists the FSM artifact
 *   deadlock — invokes AtlasLoopCycleDeadlockChecker over the FSM and persists the verdict
 *   history  — tails the append-only ndjson with timestamp + sha256 of each artifact
 *
 * Fail-closed under the master switch: when atlas.loop.master_enabled is false, every write
 * subcommand becomes a byte-identical no-op and reports refused=true.
 */
final class AtlasLoopCycleModelCheckCli
{
    public const FSM_FILE = 'fsm.json';

    public const VERDICT_FILE = 'verdict.json';

    public const HISTORY_FILE = 'history.ndjson';

    /** @var callable():bool */
    private $masterEnabledReader;

    /** @var callable():string */
    private $clock;

    public function __construct(
        private readonly AtlasLoopCycleStateMachineExtractor $extractor,
        private readonly AtlasLoopCycleDeadlockChecker $checker,
        private readonly string $storageRoot,
        ?callable $masterEnabledReader = null,
        ?callable $clock = null,
    ) {
        $this->masterEnabledReader = $masterEnabledReader ?? static function (): bool {
            if (! function_exists('config')) {
                return false;
            }

            return (bool) config('atlas.loop.master_enabled', false);
        };
        $this->clock = $clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array<string,mixed>
     */
    public function extract(): array
    {
        if (! ($this->masterEnabledReader)()) {
            return ['refused' => true, 'reason' => 'master_switch_off'];
        }
        $fsm = $this->extractor->extract();
        $path = $this->ensureDir().'/'.self::FSM_FILE;
        $bytes = $this->canonicalEncode($fsm);
        $this->atomicWrite($path, $bytes);
        $sha = hash('sha256', $bytes);
        $this->appendHistory('extract', $path, $sha);

        return [
            'refused' => false,
            'action' => 'extract',
            'path' => $path,
            'sha256' => $sha,
            'fsm' => $fsm,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function deadlock(): array
    {
        if (! ($this->masterEnabledReader)()) {
            return ['refused' => true, 'reason' => 'master_switch_off'];
        }
        $fsmPath = $this->ensureDir().'/'.self::FSM_FILE;
        $fsm = is_file($fsmPath)
            ? (array) json_decode((string) file_get_contents($fsmPath), true)
            : $this->extractor->extract();
        $verdict = $this->checker->check($fsm);
        $path = $this->ensureDir().'/'.self::VERDICT_FILE;
        $bytes = $this->canonicalEncode($verdict);
        $this->atomicWrite($path, $bytes);
        $sha = hash('sha256', $bytes);
        $this->appendHistory('deadlock', $path, $sha);

        return [
            'refused' => false,
            'action' => 'deadlock',
            'path' => $path,
            'sha256' => $sha,
            'verdict' => $verdict,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function history(int $limit = 20): array
    {
        $path = $this->storageRoot.'/'.self::HISTORY_FILE;
        if (! is_file($path)) {
            return ['refused' => false, 'rows' => []];
        }
        $rows = [];
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        if ($limit > 0) {
            $rows = array_values(array_slice($rows, -$limit));
        }

        return ['refused' => false, 'rows' => $rows];
    }

    public function storageRoot(): string
    {
        return $this->storageRoot;
    }

    private function ensureDir(): string
    {
        if (! is_dir($this->storageRoot) && ! @mkdir($this->storageRoot, 0o755, true) && ! is_dir($this->storageRoot)) {
            throw new \RuntimeException('atlas_model_check_mkdir_failed:'.$this->storageRoot);
        }

        return $this->storageRoot;
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function canonicalEncode(array $payload): string
    {
        $sorted = $this->sortRecursive($payload);

        return (string) json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => $this->sortRecursive($v), $value);
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->sortRecursive($v);
        }

        return $out;
    }

    private function atomicWrite(string $path, string $bytes): void
    {
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new \RuntimeException('atlas_model_check_write_failed');
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('atlas_model_check_rename_failed');
        }
    }

    private function appendHistory(string $action, string $artifactPath, string $sha): void
    {
        $line = (string) json_encode([
            'action' => $action,
            'artifact_path' => $artifactPath,
            'sha256' => $sha,
            'ts' => ($this->clock)(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $historyPath = $this->storageRoot.'/'.self::HISTORY_FILE;
        $fh = @fopen($historyPath, 'ab');
        if ($fh === false) {
            throw new \RuntimeException('atlas_model_check_history_open_failed');
        }
        try {
            if (! @flock($fh, LOCK_EX)) {
                throw new \RuntimeException('atlas_model_check_history_lock_failed');
            }
            fwrite($fh, $line."\n");
            fflush($fh);
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
