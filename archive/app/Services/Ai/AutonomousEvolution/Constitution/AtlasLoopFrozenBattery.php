<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Constitution;

use RuntimeException;

/**
 * LOOP-OS · FASE 3 · SLICE 3 — the FROZEN, hash-chained on-disk battery (pétreo / FORBIDDEN under Constitution/).
 *
 * The Constitution's whole guarantee rests on a battery of known-BAD (any judge must REFUTE) + known-GOOD
 * (any judge must CERTIFY, as MINIMUM obligations) + robustness cases. For that battery to be trustworthy it
 * must be IMPOSSIBLE to silently weaken: this stores each case as an immutable file in a Merkle-style chain —
 *
 *   case_hash = sha256( prev_hash . "\n" . canonicalJson(case_without_hashes) )
 *   battery_root_hash = the last case_hash in the manifest order.
 *
 * Removing, reordering, or editing ANY case breaks the recomputed chain ⇒ {@see verifyAndRoot} throws ⇒ the
 * gate REJECTS (a broken battery is never silently trusted). The hash is byte-reproducible (keys sorted
 * recursively — PHP has no JSON_SORT_KEYS) so a genesis root can be attested once and re-checked forever.
 *
 * GROWTH is APPEND-ONLY and asymmetric: a known-BAD append strictly STRENGTHENS (more must-catch cases) so it
 * is autonomous; a known-GOOD append can DILUTE the floor (it grants a certify-license) so it is two-key — the
 * caller must pass `$twoKeyApproved` AND the radius-1 neighbourhood re-proof (enforced upstream by the
 * BatteryRunner, Slice 4.5) before a good may enter.
 */
final class AtlasLoopFrozenBattery
{
    /** The battery directory, relative to the repo root (under the already-frozen Constitution/ subtree). */
    public const REL_DIR = 'app/Services/Ai/AutonomousEvolution/Constitution/battery';

    public const KINDS = ['bad', 'good', 'robust'];

    /** The genesis predecessor — a fixed sentinel so an empty battery has a well-defined, attestable root. */
    public const GENESIS_PREV = 'atlas-loop-constitution-genesis';

    private readonly string $dir;

    public function __construct(?string $batteryDir = null)
    {
        $this->dir = rtrim($batteryDir ?? base_path().'/'.self::REL_DIR, '/');
    }

    /** Manifest = the ORDERED list of case filenames (the chain order is load-bearing). */
    public function manifest(): array
    {
        $path = $this->dir.'/manifest.json';
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data['cases'] ?? null) ? array_values(array_filter($data['cases'], 'is_string')) : [];
    }

    /**
     * Walk + VERIFY the whole chain. Returns the battery_root_hash. THROWS on any break — a missing case
     * file, a hash mismatch (tampered case), or a prev-link mismatch (reorder). A broken chain is a REJECT.
     */
    public function verifyAndRoot(): string
    {
        $prev = self::GENESIS_PREV;
        foreach ($this->manifest() as $file) {
            $case = $this->readCaseFile($file);
            $expected = $this->computeHash($prev, $case);
            if (($case['prev_hash'] ?? null) !== $prev) {
                throw new RuntimeException('battery_chain_broken: prev_hash mismatch at '.$file);
            }
            if (($case['case_hash'] ?? null) !== $expected) {
                throw new RuntimeException('battery_chain_broken: case_hash mismatch at '.$file.' (case tampered)');
            }
            $prev = $expected;
        }

        return $prev;
    }

    /** The current battery_root_hash (verifies the chain as a side effect — never returns a root of a broken chain). */
    public function rootHash(): string
    {
        return $this->verifyAndRoot();
    }

    /**
     * The decoded cases in chain order (after verification).
     *
     * @return list<array<string,mixed>>
     */
    public function cases(): array
    {
        $this->verifyAndRoot();

        return array_map(fn (string $f): array => $this->readCaseFile($f), $this->manifest());
    }

    /**
     * Canonical case hash — byte-reproducible: sha256(prev . "\n" . canonicalJson(body without hash fields)).
     *
     * @param  array<string,mixed>  $case
     */
    public function computeHash(string $prev, array $case): string
    {
        $body = $case;
        unset($body['case_hash'], $body['prev_hash']);

        return hash('sha256', $prev."\n".$this->canonicalJson($body));
    }

    /**
     * Append a case to the chain (governed). bad/robust strengthen ⇒ autonomous; good dilutes ⇒ requires
     * `$twoKeyApproved` (the radius-1 re-proof is enforced by the caller). Returns the new battery_root_hash.
     *
     * @param  array<string,mixed>  $case  must carry {id, kind, contract, expected_verdict, diff_bytes|mutation_ref}
     */
    public function append(array $case, bool $twoKeyApproved = false): string
    {
        $kind = (string) ($case['kind'] ?? '');
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('battery_append_rejected: unknown kind "'.$kind.'"');
        }
        if ($kind === 'good' && ! $twoKeyApproved) {
            throw new RuntimeException('battery_append_rejected: a known-GOOD case dilutes the floor — two-key approval required');
        }
        $id = trim((string) ($case['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('battery_append_rejected: case id required');
        }

        $prev = $this->verifyAndRoot();              // never append onto a broken chain
        $body = $case;
        unset($body['case_hash'], $body['prev_hash']);
        $caseHash = $this->computeHash($prev, $body);
        $record = $body + ['prev_hash' => $prev, 'case_hash' => $caseHash];

        $manifest = $this->manifest();
        $file = sprintf('case-%04d-%s.json', count($manifest) + 1, $kind);
        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0o755, true) && ! is_dir($this->dir)) {
            throw new RuntimeException('battery_append_failed: cannot create '.$this->dir);
        }
        file_put_contents($this->dir.'/'.$file, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $manifest[] = $file;
        file_put_contents($this->dir.'/manifest.json', json_encode(['cases' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $caseHash;
    }

    /**
     * @return array<string,mixed>
     */
    private function readCaseFile(string $file): array
    {
        $path = $this->dir.'/'.$file;
        if (! is_file($path)) {
            throw new RuntimeException('battery_chain_broken: missing case file '.$file);
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (! is_array($data)) {
            throw new RuntimeException('battery_chain_broken: unreadable case file '.$file);
        }

        return $data;
    }

    /**
     * Canonical JSON — keys sorted recursively (PHP has no JSON_SORT_KEYS) so the hash is byte-reproducible
     * regardless of insertion order.
     *
     * @param  array<string,mixed>  $data
     */
    private function canonicalJson(array $data): string
    {
        $this->ksortRecursive($data);

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function ksortRecursive(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
