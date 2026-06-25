<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\HardCaseBench;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The canonical, frozen registry of hard cases the Loop historically failed on (give-backs, cancellations,
 * judge_rejected, deadlock thrash). Each record is captured ONCE and never mutated — this is what makes the
 * registry a real adversarial benchmark instead of a moving target.
 *
 * Record shape: {case_id, slug, captured_at, source, scope_root, failure_signature,
 *               original_attempt_ledger_digest, minimal_repro_seed, expected_failure_mode}.
 *
 * INVARIANTS:
 *   - APPEND-ONLY: register() with an existing case_id whose payload differs throws InvalidArgumentException.
 *     Re-registering the exact same payload is a no-op (so callers can retry safely).
 *   - DETERMINISTIC digest(): SHA-256 over the canonical (ksort-recursive) frozen set; two consecutive calls
 *     on identical content return byte-identical hashes.
 *   - PERSISTED at storage/atlas/hardcase-bench/registry.json with case ids sorted ascending.
 */
final class AtlasLoopHardCaseDatasetRegistry
{
    public const ALLOWED_SOURCES = ['give_back', 'cancellation', 'judge_reject', 'timeout'];

    private const REQUIRED_FIELDS = [
        'case_id', 'slug', 'captured_at', 'source', 'scope_root', 'failure_signature',
        'original_attempt_ledger_digest', 'minimal_repro_seed', 'expected_failure_mode',
    ];

    /** @var array<string,array<string,mixed>> case_id => record */
    private array $cases = [];

    private ?string $storageRoot = null;

    private bool $loaded = false;

    public function __construct(?string $storageRoot = null)
    {
        $this->storageRoot = $storageRoot;
    }

    public function setStorageRootForTesting(?string $path): void
    {
        $this->storageRoot = $path === null ? null : rtrim($path, '/');
        $this->loaded = false;
        $this->cases = [];
    }

    /**
     * @param  array<string,mixed>  $case
     */
    public function register(array $case): void
    {
        $this->ensureLoaded();
        $this->assertShape($case);
        $caseId = (string) $case['case_id'];

        if (isset($this->cases[$caseId])) {
            $existing = $this->cases[$caseId];
            if ($this->canonical($existing) !== $this->canonical($case)) {
                throw new InvalidArgumentException('Hard-case registry is append-only: case_id='.$caseId.' already registered with a different payload');
            }

            return; // identical re-register ⇒ no-op
        }

        $this->cases[$caseId] = $case;
        $this->persist();
    }

    /**
     * @return list<array<string,mixed>>  every case, sorted by case_id ascending
     */
    public function all(): array
    {
        $this->ensureLoaded();
        $keys = array_keys($this->cases);
        sort($keys, SORT_STRING);
        $out = [];
        foreach ($keys as $k) {
            $out[] = $this->cases[$k];
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $caseId): ?array
    {
        $this->ensureLoaded();

        return $this->cases[$caseId] ?? null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function bySource(string $source): array
    {
        return array_values(array_filter($this->all(), static fn (array $c): bool => (string) ($c['source'] ?? '') === $source));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byScope(string $scopeRoot): array
    {
        return array_values(array_filter($this->all(), static fn (array $c): bool => (string) ($c['scope_root'] ?? '') === $scopeRoot));
    }

    public function digest(): string
    {
        return hash('sha256', (string) json_encode($this->canonical($this->all()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function registryPath(): string
    {
        if ($this->storageRoot !== null && $this->storageRoot !== '') {
            return $this->storageRoot.'/registry.json';
        }
        if (function_exists('storage_path')) {
            try {
                return (string) storage_path('atlas/hardcase-bench/registry.json');
            } catch (Throwable) {
            }
        }

        return sys_get_temp_dir().'/atlas-hardcase-bench-registry.json';
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function assertShape(array $case): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $case)) {
                throw new InvalidArgumentException('Hard-case missing required field: '.$field);
            }
        }
        if (trim((string) $case['case_id']) === '') {
            throw new InvalidArgumentException('Hard-case case_id must not be empty');
        }
        $source = (string) $case['source'];
        if (! in_array($source, self::ALLOWED_SOURCES, true)) {
            throw new InvalidArgumentException('Hard-case source must be one of: '.implode(', ', self::ALLOWED_SOURCES).'; got '.$source);
        }
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $path = $this->registryPath();
        if (is_file($path) && is_readable($path)) {
            $raw = @file_get_contents($path);
            $decoded = $raw === false ? null : json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $record) {
                    if (is_array($record) && isset($record['case_id'])) {
                        $this->cases[(string) $record['case_id']] = $record;
                    }
                }
            }
        }
        $this->loaded = true;
    }

    private function persist(): void
    {
        $path = $this->registryPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = (string) json_encode($this->canonical($this->all()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Hard-case registry: cannot persist to '.$path);
        }
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([$this, 'canonical'], $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->canonical($v);
        }

        return $out;
    }
}
