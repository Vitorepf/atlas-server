<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ACDE Leap 6 (design-judgement ceiling) — the HUMAN-FROZEN node-interface contract reader.
 *
 * Sibling of {@see AtlasLoopDecompositionBoundaryOracle}: where the boundary-oracle freezes which FILES
 * must be separate nodes, this freezes the required ABSTRACTION inside those files — for each file, the
 * exported type's required public methods, the interfaces it must implement / parents it must extend, and
 * the imports it must NOT carry (dependency-direction / anti-inversion). The frozen artifact is authored by
 * a HUMAN; {@see AtlasLoopNodeInterfaceVerifier} proves the file's REAL AST surface honors it. The model
 * can neither author the bar nor Goodhart a deterministic AST census.
 *
 * Fixture: <interface_contract_dir>/<goal-hash>.json (goal-hash shared with the boundary-oracle), shape:
 *   {
 *     "files": {
 *       "app/Support/HubHelper.php": {
 *         "fqn": "App\\Support\\HubHelper",        // optional — the type the file must declare
 *         "required_public_methods": ["stepA"],     // methods the exported type MUST expose
 *         "implements": ["App\\Contracts\\Helper"],  // interfaces it MUST implement
 *         "extends": ["App\\Support\\Base"],          // parents it MUST extend
 *         "forbidden_imports": ["App\\Services\\Hub"]  // deps it MUST NOT import (direction/anti-inversion)
 *       }
 *     }
 *   }
 *
 * No contract for a goal => null => the gate degrades to no-interface-check (NO false-reject). Pure aside
 * from one fixture read.
 */
final class AtlasLoopNodeInterfaceContract
{
    public function __construct(private readonly ?string $dir = null) {}

    /** Deterministic hash of the normalized objective (shared with the boundary-oracle). */
    public function goalHash(string $goal): string
    {
        return substr(hash('sha256', trim(mb_strtolower($goal))), 0, 16);
    }

    public function fixturePath(string $goal): string
    {
        return rtrim($this->dir(), '/').'/'.$this->goalHash($goal).'.json';
    }

    public function hasContract(string $goal): bool
    {
        $path = $this->fixturePath($goal);

        return is_file($path) && is_readable($path);
    }

    /**
     * Load the frozen per-file interface requirements for the objective, or null when absent / unreadable /
     * malformed / empty (=> no interface check, never a false-reject).
     *
     * @return array<string, array{fqn:?string, required_public_methods:list<string>, implements:list<string>, extends:list<string>, forbidden_imports:list<string>}>|null
     */
    public function load(string $goal): ?array
    {
        if (! $this->hasContract($goal)) {
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
        $files = is_array($decoded['files'] ?? null) ? (array) $decoded['files'] : [];

        $out = [];
        foreach ($files as $file => $req) {
            if (! is_string($file) || trim($file) === '' || ! is_array($req)) {
                continue;
            }
            $out[ltrim(trim($file), '/')] = [
                'fqn' => is_string($req['fqn'] ?? null) && trim((string) $req['fqn']) !== '' ? ltrim(trim((string) $req['fqn']), '\\') : null,
                'required_public_methods' => $this->strList($req['required_public_methods'] ?? []),
                'implements' => $this->fqnList($req['implements'] ?? []),
                'extends' => $this->fqnList($req['extends'] ?? []),
                'forbidden_imports' => $this->fqnList($req['forbidden_imports'] ?? []),
                // ACDE Leap 6 plan-time — seam-to-seam DAG edge rules (files this node's seam MUST / must NOT
                // depend_on). The plan-time gate checks these against the ONLY structural design fact a node
                // emits (depends_on); the AST cert above checks the delivered code. Both optional.
                'must_depend_on' => $this->pathList($req['must_depend_on'] ?? []),
                'forbidden_depend_on' => $this->pathList($req['forbidden_depend_on'] ?? []),
            ];
        }

        return $out === [] ? null : $out;
    }

    private function dir(): string
    {
        if ($this->dir !== null && trim($this->dir) !== '') {
            return $this->dir;
        }
        try {
            $app = function_exists('app') ? app() : null;
            if ($app !== null && $app->bound('config')) {
                $configured = config('atlas.loop.interface_contract_dir');
                if (is_string($configured) && trim($configured) !== '') {
                    return $configured;
                }

                return base_path('frozen/obra-interfaces');
            }
        } catch (\Throwable) {
            // fall through
        }

        return 'frozen/obra-interfaces';
    }

    /** @return list<string> */
    private function strList(mixed $list): array
    {
        $out = [];
        foreach ((array) $list as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[trim($v)] = true;
            }
        }

        return array_keys($out);
    }

    /** Normalize a list of FQNs: drop blanks, strip a leading backslash, de-dup. @return list<string> */
    private function fqnList(mixed $list): array
    {
        $out = [];
        foreach ((array) $list as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[ltrim(trim($v), '\\')] = true;
            }
        }

        return array_keys($out);
    }

    /** Normalize a list of file paths: drop blanks, strip a leading slash, de-dup. @return list<string> */
    private function pathList(mixed $list): array
    {
        $out = [];
        foreach ((array) $list as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[ltrim(trim($v), '/')] = true;
            }
        }

        return array_keys($out);
    }
}
