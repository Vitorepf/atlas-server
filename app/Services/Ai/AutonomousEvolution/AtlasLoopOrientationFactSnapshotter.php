<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * ORIENTAR (phase 1 of the 8-phase canonical live cycle — docs/loop-canonical-definition.md +
 * loop-final-state-vision.md) materialized as a deterministic JSON FACT snapshot anchored on the running
 * scope. It consumes the grounded {@see AtlasLoopScopeComprehensionModel} + the discovery roots + the master
 * switch state and emits pure facts the downstream LeverageDecisionFactReporter / ArchitectureDraftService
 * ground against — NO scores, NO rankings, NO synthesized 'orientation_score' (anti-Goodhart spine).
 *
 * Byte-stable: same input ⇒ byte-identical JSON (keys sorted; generated_at_iso is the scope's latest source
 * mtime, a deterministic "as-of" — never time()/now()). Fail-closed: empty comprehension / no scope roots ⇒ a
 * refusal envelope {fact_snapshot:false, reason}, never an exception. Flag atlas.loop.orientation_snapshotter_enabled
 * default OFF ⇒ snapshot() returns null (byte-identical no-op). Pure read: no DB writes, no provider.
 */
final class AtlasLoopOrientationFactSnapshotter
{
    public const SCHEMA = 'atlas.loop.orientation.v1';

    public function __construct(private readonly ?string $repoRoot = null) {}

    /**
     * @return array<string,mixed>|null  the fact envelope, a refusal envelope, or null (flag OFF)
     */
    public function snapshot(AtlasLoopScopeComprehensionModel $model): ?array
    {
        if (! (bool) config('atlas.loop.orientation_snapshotter_enabled', false)) {
            return null; // flag OFF ⇒ byte-identical no-op
        }

        $roots = array_values(array_filter(
            array_map(static fn ($r): string => trim((string) $r), (array) config('atlas.loop.campaign.discovery_roots', ['app/Services/Ai/AutonomousEvolution'])),
            static fn (string $r): bool => $r !== '',
        ));
        sort($roots, SORT_STRING);

        if ($roots === []) {
            return $this->refuse('missing_scope_roots');
        }
        if ($model->inventory === []) {
            return $this->refuse('empty_comprehension_model');
        }

        $repoRoot = rtrim($this->repoRoot ?? base_path(), '/');

        $envelope = [
            'schema_version' => self::SCHEMA,
            'fact_snapshot' => true,
            'scope_roots' => $roots,
            'inventory_size' => count($model->inventory),
            'orphan_count' => count($model->orphans),
            'last_commit_sha' => $this->headSha($repoRoot),
            'master_switch_state' => AtlasLoopMasterSwitch::state(),
            'generated_at_iso' => $this->latestSourceMtimeIso($model, $repoRoot),
        ];

        ksort($envelope);

        return $envelope;
    }

    /**
     * @return array{schema_version:string, fact_snapshot:false, reason:string}
     */
    private function refuse(string $reason): array
    {
        return ['schema_version' => self::SCHEMA, 'fact_snapshot' => false, 'reason' => $reason];
    }

    /** HEAD commit sha via plain .git file reads (deterministic, no Process). Unresolvable ⇒ 'unknown'. */
    private function headSha(string $repoRoot): string
    {
        $head = @file_get_contents($repoRoot.'/.git/HEAD');
        if ($head === false) {
            return 'unknown';
        }
        $head = trim($head);
        if (str_starts_with($head, 'ref:')) {
            $ref = trim(substr($head, 4));
            $sha = @file_get_contents($repoRoot.'/.git/'.$ref);

            return $sha === false ? 'unknown' : (substr(trim($sha), 0, 40) ?: 'unknown');
        }

        return substr($head, 0, 40) ?: 'unknown';
    }

    /** The latest source mtime in the inventory as a UTC ISO-8601 "as-of" — deterministic for an unchanged scope. */
    private function latestSourceMtimeIso(AtlasLoopScopeComprehensionModel $model, string $repoRoot): string
    {
        $max = 0;
        foreach ($model->inventory as $item) {
            $rel = is_array($item) ? ltrim((string) ($item['rel_path'] ?? ''), '/') : '';
            if ($rel === '') {
                continue;
            }
            $abs = $repoRoot.'/'.$rel;
            if (is_file($abs)) {
                $mtime = @filemtime($abs);
                if ($mtime !== false && $mtime > $max) {
                    $max = $mtime;
                }
            }
        }

        return $max > 0 ? gmdate('Y-m-d\TH:i:s\Z', $max) : '1970-01-01T00:00:00Z';
    }
}
