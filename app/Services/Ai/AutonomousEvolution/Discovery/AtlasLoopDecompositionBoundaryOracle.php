<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ACDE Leap 2 — the HUMAN-FROZEN DECOMPOSITION BOUNDARY-ORACLE reader.
 *
 * The single-target moat works because the acceptance bar is FROZEN BY A HUMAN — the model cannot author
 * the bar it must clear, so it cannot Goodhart its way past by emitting a plausible-but-wrong result. This
 * class imports that exact trick into the DECOMPOSITION layer: for a given objective, a human freezes a
 * fixture naming the REQUIRED node boundaries (which seams MUST exist as separate nodes / which create-class
 * files are mandatory). The {@see AtlasLoopObraPlanValidator} then proves the generated DAG SUPERSETS that
 * required set — a deterministic check anchored on a frozen artifact, never a model-emitted spec.
 *
 * The fixture lives at <oracle_dir>/<goal-hash>.json, where goal-hash is a deterministic reduction of the
 * normalized objective. Shape:
 *   {
 *     "required_boundaries":   ["app/Foo.php", "app/Support/FooHelper.php", ...],  // seams that MUST be nodes
 *     "required_create_files": ["app/Support/FooHelper.php", ...]                  // files the plan MUST create
 *   }
 *
 * No oracle for a goal => null => the gate degrades to the structural-only check (NO false-reject). This is
 * the honest edge: the loop's decomposition-correctness reach scales one frozen boundary-fixture at a time.
 *
 * Pure aside from the single fixture read: no provider, no DB, no mutation.
 */
final class AtlasLoopDecompositionBoundaryOracle
{
    public function __construct(private readonly ?string $oracleDir = null) {}

    /**
     * Deterministic hash of the normalized objective: trim + lowercase + sha256, first 16 hex chars.
     * Stable across runs and machines, so the same objective always maps to the same fixture file.
     */
    public function goalHash(string $goal): string
    {
        return substr(hash('sha256', trim(mb_strtolower($goal))), 0, 16);
    }

    /** Absolute path to the (possibly non-existent) fixture for this goal. */
    public function fixturePath(string $goal): string
    {
        return rtrim($this->dir(), '/').'/'.$this->goalHash($goal).'.json';
    }

    /** True iff a frozen boundary-oracle fixture exists for this objective. */
    public function hasOracle(string $goal): bool
    {
        $path = $this->fixturePath($goal);

        return is_file($path) && is_readable($path);
    }

    /**
     * Load the frozen required-boundary set for the objective, or null when no fixture exists / the
     * fixture is unreadable or malformed (=> the gate degrades to structural-only, never false-rejects).
     *
     * @return array{required_boundaries:list<string>, required_create_files:list<string>}|null
     */
    public function load(string $goal): ?array
    {
        if (! $this->hasOracle($goal)) {
            return null;
        }

        $raw = @file_get_contents($this->fixturePath($goal));
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return [
            'required_boundaries' => $this->normalizeSeams($decoded['required_boundaries'] ?? []),
            'required_create_files' => $this->normalizeSeams($decoded['required_create_files'] ?? []),
        ];
    }

    /** The configured oracle directory (overridable for tests), defaulting to frozen/obra-decompositions. */
    private function dir(): string
    {
        if ($this->oracleDir !== null && trim($this->oracleDir) !== '') {
            return $this->oracleDir;
        }

        // Read defensively so a container-less caller (pure unit) never fatals on the config/base_path
        // helpers; an explicit oracleDir (the test path) short-circuits above before we ever get here.
        try {
            $app = function_exists('app') ? app() : null;
            if ($app !== null && $app->bound('config')) {
                $configured = config('atlas.loop.decomposition_oracle_dir');
                if (is_string($configured) && trim($configured) !== '') {
                    return $configured;
                }

                return base_path('frozen/obra-decompositions');
            }
        } catch (\Throwable) {
            // fall through to the relative default below
        }

        return 'frozen/obra-decompositions';
    }

    /**
     * Normalize a list of seam paths: drop non-strings/blanks, strip a leading slash, de-duplicate.
     *
     * @return list<string>
     */
    private function normalizeSeams(mixed $list): array
    {
        $out = [];
        foreach ((array) $list as $seam) {
            if (is_string($seam) && trim($seam) !== '') {
                $out[ltrim(trim($seam), '/')] = true;
            }
        }

        return array_keys($out);
    }
}
