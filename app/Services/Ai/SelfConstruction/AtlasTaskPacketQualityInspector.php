<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

/**
 * PART 2 · axis 8 — the task-packet SELF-SUFFICIENCY inspector (the "packet-quality scorer" the operator's
 * loop asks for). A served TaskEnvelope must be implementable by a COLD client — an AI with a meta/loop and
 * ZERO context from the conversation that produced it. This inspector emits the concrete, deterministic FACTS
 * that decide whether a packet meets that bar; it NEVER emits a scalar/rank (the pétreo "facts not score").
 *
 * The serving builder/validator already block an empty objective, empty allowed_files, and a bare-dir-only
 * scope — but NOT a packet that is missing its acceptance criteria or required evidence. Such a packet is
 * served today (verified) and strands the client: no acceptance ⇒ it can't know when it's done; no required
 * evidence ⇒ the `report` completion gate can't validate the work. These are BLOCKING deficiencies.
 *
 * BLOCKING (a cold client literally cannot implement or prove the task):
 *   - missing_objective · empty_allowed_files · missing_acceptance_criteria · missing_required_evidence
 *   - bare_directory_in_allowed_files: a directory in the WRITE set (not a concrete file). The completion
 *     enforcement matches changed files exactly against allowed_files (MF-12), so a bare dir guarantees a
 *     `files_changed_outside_allowed_scope` failure — the task is doomed before it starts.
 * ADVISORY (worth surfacing, not disqualifying):
 *   - scope_incoherent: an allowed_file not covered by scope_in.
 */
final class AtlasTaskPacketQualityInspector
{
    public const SCHEMA = 'atlas.task_serving.packet_quality.v1';

    public const BLOCKING_DEFICIENCIES = [
        'missing_objective',
        'empty_allowed_files',
        'missing_acceptance_criteria',
        'missing_required_evidence',
        'bare_directory_in_allowed_files',
    ];

    /**
     * Inspect a task packet (or its served projection — both expose the same fields). Returns the FACTS and a
     * boolean `self_sufficient` (no BLOCKING deficiency). Pure + deterministic.
     *
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    public function inspect(array $packet): array
    {
        $objective = trim((string) data_get($packet, 'objective', ''));
        $allowed = $this->stringList((array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', [])));
        $scopeIn = $this->stringList((array) data_get($packet, 'normalized_scope.scope_in', data_get($packet, 'scope_in', [])));
        $acceptance = $this->stringList((array) data_get($packet, 'acceptance_criteria', []));
        // The raw packet stores the evidence list under `evidence_requirements.required`; the served projection
        // exposes it as `required_evidence`. Read both shapes so the inspector judges either.
        $evidence = $this->stringList((array) data_get($packet, 'required_evidence', data_get($packet, 'evidence_requirements.required', [])));

        $bareDirs = array_values(array_filter($allowed, fn (string $p): bool => $this->isBareDirectory($p)));
        $uncovered = $this->uncoveredByScopeIn($allowed, $scopeIn);

        $deficiencies = [];
        if ($objective === '') {
            $deficiencies[] = 'missing_objective';
        }
        if ($allowed === []) {
            $deficiencies[] = 'empty_allowed_files';
        }
        if ($acceptance === []) {
            $deficiencies[] = 'missing_acceptance_criteria';
        }
        if ($evidence === []) {
            $deficiencies[] = 'missing_required_evidence';
        }
        if ($bareDirs !== []) {
            $deficiencies[] = 'bare_directory_in_allowed_files';
        }
        if ($uncovered !== []) {
            $deficiencies[] = 'scope_incoherent'; // advisory
        }

        $blocking = array_values(array_intersect($deficiencies, self::BLOCKING_DEFICIENCIES));

        return [
            'schema' => self::SCHEMA,
            'self_sufficient' => $blocking === [],
            'deficiencies' => $deficiencies,
            'blocking_deficiencies' => $blocking,
            'facts' => [
                'has_objective' => $objective !== '',
                'allowed_files_count' => count($allowed),
                'acceptance_criteria_count' => count($acceptance),
                'required_evidence_count' => count($evidence),
                'bare_directories' => $bareDirs,
                'scope_uncovered_allowed_files' => $uncovered,
            ],
        ];
    }

    /** Convenience: just the boolean. */
    public function isSelfSufficient(array $packet): bool
    {
        return (bool) $this->inspect($packet)['self_sufficient'];
    }

    /** A path is a bare directory when it has no filename extension in its last segment (or ends with '/'). */
    private function isBareDirectory(string $path): bool
    {
        $raw = trim($path);
        if ($raw === '') {
            return false;
        }
        if (str_ends_with($raw, '/')) {
            return true;
        }
        $normalized = rtrim(str_replace('\\', '/', $raw), '/');
        $base = basename($normalized);

        // A concrete file has an extension in its final segment (e.g. Foo.php); a directory does not.
        return ! str_contains($base, '.');
    }

    /**
     * Allowed (write) paths not covered by any scope_in (read) path — a read/write scope incoherence.
     *
     * @param  list<string>  $allowed
     * @param  list<string>  $scopeIn
     * @return list<string>
     */
    private function uncoveredByScopeIn(array $allowed, array $scopeIn): array
    {
        if ($scopeIn === []) {
            return $allowed;
        }
        $uncovered = [];
        foreach ($allowed as $a) {
            if (WriteSetOverlap::collidingPaths([$a], $scopeIn) === []) {
                $uncovered[] = $a;
            }
        }

        return $uncovered;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            $values,
        ), static fn (string $v): bool => $v !== ''));
    }
}
