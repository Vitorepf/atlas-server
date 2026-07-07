<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * K4 (Obra #18) — conformance gate for kit orders, an additive sibling of the
 * admission-gate-v2 logic/wiring checks. Runs at REPORT time (after the worker,
 * before the commit lands) and proves the kit contract was honoured:
 *
 *   - acceptance_test_untouched — the pre-written oracle still hashes to the
 *     frozen value; the implementer made it pass, never edited it.
 *   - no_artisan_command_collision — a new artisan command's signature name
 *     does not shadow an existing command.
 *   - diff_within_allowed — every changed file is inside allowed_files.
 *
 * All checks fail-safe to `passed: true` when their inputs are absent, so a
 * non-kit packet is byte-identical. Deterministic + side-effect-free.
 *
 * ponytail: running the frozen_callers' test SUITES belongs to the Verification
 * Court (it already re-runs impacted suites), not to this hot serving gate —
 * booting arbitrary suites here would be slow and could fail-open into false
 * green. This gate proves the ADDITIVE-ONLY contract structurally (untouched
 * oracle + diff scope); the court proves the suites.
 */
final class AtlasTaskKitConformanceGate
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? base_path();
    }

    /**
     * @param  array<string,mixed>  $packet  served packet (carries K1 kit fields)
     * @param  list<string>  $changedFiles
     * @return array{passed:bool,checks:array<string,array<string,mixed>>}
     */
    public function evaluate(array $packet, array $changedFiles): array
    {
        $changedFiles = array_values(array_filter(array_map(static fn ($f): string => trim((string) $f), $changedFiles), static fn (string $f): bool => $f !== ''));

        $checks = [
            'acceptance_test_untouched' => $this->acceptanceTestUntouched($packet, $changedFiles),
            'no_artisan_command_collision' => $this->noArtisanCommandCollision($changedFiles),
            'diff_within_allowed' => $this->diffWithinAllowed($packet, $changedFiles),
        ];

        $passed = true;
        foreach ($checks as $check) {
            if (($check['passed'] ?? true) !== true) {
                $passed = false;
            }
        }

        return ['passed' => $passed, 'checks' => $checks];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    private function acceptanceTestUntouched(array $packet, array $changedFiles): array
    {
        $path = trim((string) data_get($packet, 'acceptance_test_ref.path', ''));
        $hash = trim((string) data_get($packet, 'acceptance_test_ref.hash', ''));
        if ($path === '') {
            return ['passed' => true, 'reason' => 'no_pre_written_test'];
        }

        if (in_array($path, $changedFiles, true)) {
            return ['passed' => false, 'reason' => 'acceptance_test_in_diff', 'path' => $path];
        }

        $abs = $this->root.DIRECTORY_SEPARATOR.ltrim($path, '/');
        if (! is_file($abs)) {
            return ['passed' => false, 'reason' => 'acceptance_test_missing', 'path' => $path];
        }

        if ($hash === '') {
            return ['passed' => true, 'reason' => 'no_frozen_hash_to_compare'];
        }

        $actual = hash('sha256', (string) file_get_contents($abs));

        return $actual === $hash
            ? ['passed' => true]
            : ['passed' => false, 'reason' => 'acceptance_test_hash_mismatch', 'expected' => $hash, 'actual' => $actual];
    }

    /**
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    private function noArtisanCommandCollision(array $changedFiles): array
    {
        $newCommandFiles = array_values(array_filter($changedFiles, fn (string $f): bool => $this->isCommandFile($f)));
        if ($newCommandFiles === []) {
            return ['passed' => true, 'reason' => 'no_new_command'];
        }

        $newNames = [];
        foreach ($newCommandFiles as $file) {
            $name = $this->signatureNameOf($this->root.DIRECTORY_SEPARATOR.ltrim($file, '/'));
            if ($name !== '') {
                $newNames[$name] = $file;
            }
        }
        if ($newNames === []) {
            return ['passed' => true, 'reason' => 'no_signature_found'];
        }

        // Existing command names = signatures on disk EXCEPT the changed files.
        $changedAbs = array_map(fn (string $f): string => $this->root.DIRECTORY_SEPARATOR.ltrim($f, '/'), $newCommandFiles);
        $existing = [];
        foreach (glob($this->root.'/app/Console/Commands/*.php') ?: [] as $abs) {
            if (in_array($abs, $changedAbs, true)) {
                continue;
            }
            $name = $this->signatureNameOf($abs);
            if ($name !== '') {
                $existing[$name] = true;
            }
        }

        $collisions = array_values(array_intersect(array_keys($newNames), array_keys($existing)));

        return $collisions === []
            ? ['passed' => true]
            : ['passed' => false, 'reason' => 'artisan_command_name_collision', 'names' => $collisions];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    private function diffWithinAllowed(array $packet, array $changedFiles): array
    {
        $allowed = array_values(array_map(static fn ($f): string => trim((string) $f), (array) data_get($packet, 'allowed_files', [])));
        if ($allowed === [] || $changedFiles === []) {
            return ['passed' => true, 'reason' => 'nothing_to_bound'];
        }

        $outside = array_values(array_diff($changedFiles, $allowed));

        return $outside === []
            ? ['passed' => true]
            : ['passed' => false, 'reason' => 'changed_files_outside_allowed', 'outside' => $outside];
    }

    private function isCommandFile(string $path): bool
    {
        $norm = str_replace('\\', '/', $path);

        return str_starts_with(ltrim($norm, '/'), 'app/Console/Commands/') && str_ends_with($norm, '.php');
    }

    /** The artisan command name (first token of the `$signature`) declared in a file. */
    private function signatureNameOf(string $absPath): string
    {
        if (! is_file($absPath)) {
            return '';
        }
        $src = (string) file_get_contents($absPath);
        if (! preg_match('/\$signature\s*=\s*([\'"])(.*?)\1/s', $src, $m)) {
            return '';
        }

        return trim((string) preg_split('/\s+/', trim($m[2]))[0]);
    }
}
