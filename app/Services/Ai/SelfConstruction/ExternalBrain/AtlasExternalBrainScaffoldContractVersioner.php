<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure versioner. Classifies scaffold contract changes between versions and
 * computes the next semantic version.
 *
 * Input facts:
 *   current_version     — SemVer string of the existing contract (e.g. "1.2.3").
 *   current_checks      — list of {name, is_safety_check, required} in the current contract.
 *   proposed_checks     — list of {name, is_safety_check, required} in the proposed contract.
 *   explicit_retirements — list of check names the author explicitly declares as retired.
 *
 * AC2 — Compatibility classification:
 *   breaking           — any silent safety-check removal (AC3), OR required goes false→true.
 *   migration_required — non-safety check removed, OR non-safety required goes false→true
 *                        (only when no breaking change present).
 *   compatible         — only additions or required-relaxation (true→false).
 *
 * AC3 — Silent safety removal guard:
 *   A safety check (is_safety_check=true) removed from the proposed set AND NOT present in
 *   explicit_retirements is a hard violation → compatibility forced to 'breaking' and a
 *   'silent_safety_removal' note is emitted.
 *
 * Versioning (SemVer):
 *   compatible         → patch bump (x.y.z → x.y.z+1).
 *   migration_required → minor bump (x.y.z → x.y+1.0).
 *   breaking           → major bump (x.y.z → x+1.0.0).
 *
 * AC4 outputs: next_version, compatibility, migration_notes, retired_checks,
 *   rollout_guidance.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainScaffoldContractVersioner
{
    public const SCHEMA = 'atlas.external_brain.scaffold_contract_versioner.v1';

    private const COMPAT_COMPATIBLE = 'compatible';
    private const COMPAT_MIGRATION  = 'migration_required';
    private const COMPAT_BREAKING   = 'breaking';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function version(array $facts): array
    {
        $currentVersion     = (string) ($facts['current_version'] ?? '1.0.0');
        $currentChecks      = $this->indexChecks(is_array($facts['current_checks']       ?? null) ? $facts['current_checks']       : []);
        $proposedChecks     = $this->indexChecks(is_array($facts['proposed_checks']      ?? null) ? $facts['proposed_checks']      : []);
        $explicitRetirements = array_flip(is_array($facts['explicit_retirements'] ?? null) ? $facts['explicit_retirements'] : []);

        $migrationNotes  = [];
        $retiredChecks   = [];
        $compatLevel     = self::COMPAT_COMPATIBLE;

        // Evaluate removals (in current, not in proposed).
        foreach ($currentChecks as $name => $check) {
            if (array_key_exists($name, $proposedChecks)) {
                continue; // handled in modifications below
            }

            if ($check['is_safety_check']) {
                if (array_key_exists($name, $explicitRetirements)) {
                    // Explicit retirement of a safety check → migration_required.
                    $retiredChecks[]  = $name;
                    $migrationNotes[] = "safety check '$name' explicitly retired; update consumers";
                    $compatLevel      = $this->escalate($compatLevel, self::COMPAT_MIGRATION);
                } else {
                    // AC3: silent safety removal → hard breaking.
                    $migrationNotes[] = "VIOLATION: safety check '$name' silently removed; must be explicitly retired or replaced";
                    $compatLevel      = self::COMPAT_BREAKING;
                }
            } else {
                // Non-safety removal.
                if (array_key_exists($name, $explicitRetirements)) {
                    $retiredChecks[]  = $name;
                    $migrationNotes[] = "check '$name' explicitly retired";
                } else {
                    $migrationNotes[] = "check '$name' removed; update consumers";
                }
                $compatLevel = $this->escalate($compatLevel, self::COMPAT_MIGRATION);
            }
        }

        // Evaluate additions (in proposed, not in current).
        foreach ($proposedChecks as $name => $check) {
            if (! array_key_exists($name, $currentChecks)) {
                $migrationNotes[] = "check '$name' added" . ($check['required'] ? ' (required)' : ' (optional)');
                // New required check = consumers must implement it → migration_required.
                if ($check['required']) {
                    $compatLevel = $this->escalate($compatLevel, self::COMPAT_MIGRATION);
                }
                // Optional addition = compatible; no escalation.
            }
        }

        // Evaluate modifications (in both).
        foreach ($currentChecks as $name => $current) {
            if (! array_key_exists($name, $proposedChecks)) {
                continue; // handled above
            }
            $proposed = $proposedChecks[$name];

            if ($current['required'] && ! $proposed['required']) {
                // Relaxation: required → optional = compatible.
                $migrationNotes[] = "check '$name' relaxed from required to optional";
            } elseif (! $current['required'] && $proposed['required']) {
                // Tightening: optional → required = breaking.
                $migrationNotes[] = "check '$name' tightened from optional to required (breaking)";
                $compatLevel      = self::COMPAT_BREAKING;
            }
        }

        $nextVersion = $this->bumpVersion($currentVersion, $compatLevel);

        return [
            'schema_version'   => self::SCHEMA,
            'next_version'     => $nextVersion,
            'compatibility'    => $compatLevel,
            'migration_notes'  => $migrationNotes,
            'retired_checks'   => $retiredChecks,
            'rollout_guidance' => $this->rolloutGuidance($compatLevel, $nextVersion),
        ];
    }

    private function indexChecks(array $checks): array
    {
        $indexed = [];
        foreach ($checks as $check) {
            if (! is_array($check) || ! isset($check['name'])) {
                continue;
            }
            $indexed[(string) $check['name']] = [
                'is_safety_check' => (bool) ($check['is_safety_check'] ?? false),
                'required'        => (bool) ($check['required']        ?? true),
            ];
        }

        return $indexed;
    }

    private function escalate(string $current, string $candidate): string
    {
        $order = [self::COMPAT_COMPATIBLE => 0, self::COMPAT_MIGRATION => 1, self::COMPAT_BREAKING => 2];

        return ($order[$candidate] ?? 0) > ($order[$current] ?? 0) ? $candidate : $current;
    }

    private function bumpVersion(string $version, string $compat): string
    {
        $parts = array_map('intval', explode('.', $version . '.0.0'));
        [$major, $minor, $patch] = [$parts[0] ?? 1, $parts[1] ?? 0, $parts[2] ?? 0];

        return match ($compat) {
            self::COMPAT_BREAKING   => ($major + 1) . '.0.0',
            self::COMPAT_MIGRATION  => $major . '.' . ($minor + 1) . '.0',
            default                 => $major . '.' . $minor . '.' . ($patch + 1),
        };
    }

    private function rolloutGuidance(string $compat, string $nextVersion): string
    {
        return match ($compat) {
            self::COMPAT_BREAKING  => "major version $nextVersion: requires coordinated rollout; update all consumers before deploying",
            self::COMPAT_MIGRATION => "minor version $nextVersion: consumers must handle removed or new required checks before deploying",
            default                => "patch version $nextVersion: backward-compatible; deploy freely",
        };
    }
}
