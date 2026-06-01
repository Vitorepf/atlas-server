<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Self-Construction Assignment And Claim — pure, deterministic packet selector
 * that emits a READ-ONLY claim preview for one AI session.
 *
 * Answers (Purpose): which packet this AI should take, why it is safe now,
 * whether it is already claimed, what scope the AI owns, and when the AI must
 * stop instead of implementing. It selects at most ONE packet and never grants
 * write authority or persists a claim.
 *
 * Contract (from the doc Claim Preview Schema + Selection Policy + Collision Rules):
 *   Entrada: candidates[] (each: packet_id, status, collision_risk, dependencies,
 *            dependencies_complete, allowed_files, forbidden_files,
 *            required_validator/has_required_validator, authorizes_execution,
 *            required_first_commands, stop_conditions, packet_hash, split_hash),
 *            plus optional hot_external_scopes and already_claimed_files.
 *   Saida:   the atlas.self_construction_assignment_preview.v1 document:
 *            status (claim_preview_ready|blocked), selected_packet_id,
 *            claim_state, execution_allowed=false, allowed/forbidden files,
 *            required_first_commands, stop_conditions, and (on no qualifier)
 *            a blocked envelope with per-candidate rejection reasons.
 *
 * Documented invariants this code ENFORCES (not just echoes):
 *   - "An AI session may work on only one claimed packet at a time." (decision)
 *       => exactly one selected_packet_id; selection stops at the first qualifier.
 *   - "Claim preview is read-only until a durable reservation ledger exists."
 *       => claim_state is always preview_only_not_persisted and never persisted.
 *   - "Assignment must prefer the safest unblocked packet over maximum throughput."
 *       => candidates are ordered safest-first (collision_risk none<low, fewer
 *          allowed_files) before the first-qualifier scan — NOT input order.
 *   - Selection Policy (1..6): status=available; collision_risk none|low;
 *     dependencies empty or complete; allowed_files disjoint from other selected
 *     and from already-claimed files; forbidden_files include hot external scopes;
 *     required validator exists.
 *   - Collision Rules: block when the packet writes a file already changed by a
 *     hot scope, overlaps another claimed packet, depends on incomplete work,
 *     lacks required gates, omits hot forbidden scopes, or tries to authorize
 *     runtime execution without a receipt.
 *   - Read-Only Phase: emitted preview always carries execution_allowed=false.
 *   - "If no packet qualifies, Atlas must return `blocked` with reasons."
 *
 * Non-goals honoured (Non Goals): does NOT persist claims, does NOT grant write
 * authority, does NOT override Scope Validator, does NOT assign hot Voice /
 * Kernel / provider / route / migration / daemon work (those are forbidden hot
 * scopes), and does NOT let one session own multiple packets.
 *
 * @see docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md
 */
final class AtlasAssignmentAndClaimContractService
{
    /** Stable preview schema id (from Claim Preview Schema). */
    public const SCHEMA = 'atlas.self_construction_assignment_preview.v1';

    /** Output statuses (closed set, from Claim Preview Schema). */
    public const STATUS_READY = 'claim_preview_ready';
    public const STATUS_BLOCKED = 'blocked';

    /** Constant read-only markers (Read-Only Phase). */
    public const SESSION_OWNER = 'read_only_preview';
    public const CLAIM_STATE = 'preview_only_not_persisted';

    /** Packet status that is eligible for selection (Selection Policy 1). */
    public const PACKET_AVAILABLE = 'available';

    /** collision_risk levels accepted by Selection Policy 2 (none|low only). */
    private const ACCEPTED_COLLISION_RISK = ['none', 'low'];

    /**
     * Hot external scope needles that MUST appear in a packet's forbidden_files
     * (Selection Policy 5 + Non Goals: "Do not assign hot Voice, Kernel,
     * provider, route, migration or daemon work."). A packet that touches none of
     * these still must declare them forbidden so the hot scopes stay fenced off.
     *
     * @var list<string>
     */
    private const HOT_SCOPE_NEEDLES = [
        'voice',
        'kernel',
        'provider',
        'route',
        'migration',
        'daemon',
    ];

    /**
     * Select at most one safe packet and build its claim preview.
     *
     * @param array<string,mixed> $input
     *        assignment_id          : string (optional; defaults to a local id)
     *        candidates             : list<array<string,mixed>>
     *        hot_external_scopes    : list<string>  paths owned by another active front
     *        already_claimed_files  : list<string>  paths owned by other claimed packets
     *
     * @return array<string,mixed> the Claim Preview Schema document
     */
    public function select(array $input): array
    {
        $assignmentId = $this->str($input['assignment_id'] ?? null) ?? 'ASSIGN-LOCAL-0001';
        $candidates = $this->candidates($input['candidates'] ?? []);
        $hotScopes = $this->normalizePaths($input['hot_external_scopes'] ?? []);
        $claimedFiles = $this->normalizePaths($input['already_claimed_files'] ?? []);

        // "Prefer the safest unblocked packet over maximum throughput": order
        // safest-first BEFORE the first-qualifier scan so selection is not at the
        // mercy of input order. Stable within equal safety.
        $ordered = $this->orderSafestFirst($candidates);

        $rejections = [];

        foreach ($ordered as $candidate) {
            $check = $this->qualify($candidate, $hotScopes, $claimedFiles);
            if ($check['qualifies'] === true) {
                // First qualifier wins — one session, one packet.
                return $this->readyPreview($assignmentId, $candidate, $hotScopes, $claimedFiles);
            }

            $rejections[] = [
                'packet_id' => $check['packet_id'],
                'reasons' => $check['reasons'],
            ];
        }

        // "If no packet qualifies, Atlas must return `blocked` with reasons."
        return $this->blockedPreview($assignmentId, $rejections);
    }

    /**
     * Convenience predicate: is there a safe packet to preview at all?
     */
    public function hasSelectablePacket(array $input): bool
    {
        return $this->select($input)['status'] === self::STATUS_READY;
    }

    /**
     * Qualify ONE candidate against Selection Policy (1..6) + Collision Rules.
     * Collects every failed rule so the blocked envelope can explain itself.
     *
     * @param array<string,mixed> $c
     * @param list<string> $hotScopes
     * @param list<string> $claimedFiles
     * @return array{qualifies:bool,packet_id:string,reasons:list<string>}
     */
    public function qualify(array $c, array $hotScopes, array $claimedFiles): array
    {
        $packetId = $this->str($c['packet_id'] ?? null) ?? 'AIP-UNKNOWN';
        $reasons = [];

        $allowed = $this->normalizePaths($c['allowed_files'] ?? []);
        $forbidden = $this->normalizePaths($c['forbidden_files'] ?? []);

        // Selection Policy 1: status=available.
        if ($this->str($c['status'] ?? null) !== self::PACKET_AVAILABLE) {
            $reasons[] = 'status_not_available';
        }

        // Selection Policy 2: collision_risk is none or low.
        $risk = strtolower($this->str($c['collision_risk'] ?? null) ?? 'unknown');
        if (! in_array($risk, self::ACCEPTED_COLLISION_RISK, true)) {
            $reasons[] = 'collision_risk_too_high';
        }

        // Selection Policy 3 / Collision Rule "depends on incomplete work":
        // dependencies empty OR explicitly complete.
        $dependencies = $this->normalizePaths($c['dependencies'] ?? []);
        $dependenciesComplete = (bool) ($c['dependencies_complete'] ?? false);
        if ($dependencies !== [] && ! $dependenciesComplete) {
            $reasons[] = 'dependencies_incomplete';
        }

        // Selection Policy 4 / Collision Rule "overlaps another claimed packet":
        // allowed_files must be disjoint from files already claimed elsewhere.
        $claimedOverlap = $this->intersection($allowed, $claimedFiles);
        if ($claimedOverlap !== []) {
            $reasons[] = 'allowed_files_overlap_claimed_packet';
        }

        // Collision Rule "writes a file already changed by another hot scope":
        // allowed_files must not intersect the hot external scopes either.
        $hotOverlap = $this->intersection($allowed, $hotScopes);
        if ($hotOverlap !== []) {
            $reasons[] = 'allowed_files_touch_hot_external_scope';
        }

        // Selection Policy 5 / Collision Rule "does not list forbidden hot scopes":
        // every hot-scope category must be fenced in forbidden_files.
        if (! $this->declaresHotScopesForbidden($forbidden)) {
            $reasons[] = 'forbidden_files_missing_hot_scopes';
        }

        // Selection Policy 6 / Collision Rule "lacks required gates":
        // a required validator must exist.
        if (! $this->hasRequiredValidator($c)) {
            $reasons[] = 'required_validator_missing';
        }

        // Collision Rule "tries to authorize runtime execution without receipt":
        // read-only phase forbids any execution authorization, with or without a
        // claimed receipt (durable persistence needs a future reservation ledger).
        if ((bool) ($c['authorizes_execution'] ?? false) === true) {
            $reasons[] = 'execution_authorization_not_allowed_in_preview';
        }

        return [
            'qualifies' => $reasons === [],
            'packet_id' => $packetId,
            'reasons' => $reasons,
        ];
    }

    /**
     * Build the claim_preview_ready document for a qualifying packet.
     *
     * @param array<string,mixed> $c
     * @param list<string> $hotScopes
     * @param list<string> $claimedFiles
     * @return array<string,mixed>
     */
    private function readyPreview(string $assignmentId, array $c, array $hotScopes, array $claimedFiles): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'assignment_id' => $assignmentId,
            'status' => self::STATUS_READY,
            'session_owner' => self::SESSION_OWNER,
            'selected_packet_id' => $this->str($c['packet_id'] ?? null) ?? 'AIP-UNKNOWN',
            'claim_state' => self::CLAIM_STATE,
            // Read-Only Phase invariant — preview never authorizes execution.
            'execution_allowed' => false,
            'packet_hash' => $this->str($c['packet_hash'] ?? null) ?? 'sha256:unknown',
            'split_hash' => $this->str($c['split_hash'] ?? null) ?? 'sha256:unknown',
            'allowed_files' => $this->normalizePaths($c['allowed_files'] ?? []),
            'forbidden_files' => $this->normalizePaths($c['forbidden_files'] ?? []),
            'required_first_commands' => $this->normalizeStrings($c['required_first_commands'] ?? []),
            'stop_conditions' => $this->stopConditions($c),
            'hot_external_scopes' => $hotScopes,
            'already_claimed_files' => $claimedFiles,
            'blocked_reasons' => [],
        ];
    }

    /**
     * Build the blocked document when no candidate qualifies.
     *
     * @param list<array{packet_id:string,reasons:list<string>}> $rejections
     * @return array<string,mixed>
     */
    private function blockedPreview(string $assignmentId, array $rejections): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'assignment_id' => $assignmentId,
            'status' => self::STATUS_BLOCKED,
            'session_owner' => self::SESSION_OWNER,
            'selected_packet_id' => null,
            'claim_state' => self::CLAIM_STATE,
            'execution_allowed' => false,
            'packet_hash' => null,
            'split_hash' => null,
            'allowed_files' => [],
            'forbidden_files' => [],
            'required_first_commands' => [],
            'stop_conditions' => [],
            'hot_external_scopes' => [],
            'already_claimed_files' => [],
            'blocked_reasons' => $rejections,
        ];
    }

    /**
     * Stop conditions for the AI protocol. Always merges the documented baseline
     * (stale packet hash, hot file, unknown file, failed gate) with any
     * packet-declared conditions, de-duplicated.
     *
     * @param array<string,mixed> $c
     * @return list<string>
     */
    private function stopConditions(array $c): array
    {
        $baseline = [
            'stale_packet_hash',
            'hot_file_detected',
            'unknown_file_detected',
            'failed_gate',
        ];
        $declared = $this->normalizeStrings($c['stop_conditions'] ?? []);

        return array_values(array_unique(array_merge($baseline, $declared)));
    }

    /**
     * "Prefer the safest unblocked packet": ascending by (collision-risk rank,
     * allowed-file count). none(0) < low(1) < anything-else(2); ties keep input
     * order (stable) so the result is deterministic.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private function orderSafestFirst(array $candidates): array
    {
        $decorated = [];
        foreach ($candidates as $i => $c) {
            $decorated[] = [
                'index' => $i,
                'rank' => $this->riskRank($c),
                'fanout' => count($this->normalizePaths($c['allowed_files'] ?? [])),
                'candidate' => $c,
            ];
        }

        usort($decorated, static function (array $a, array $b): int {
            return [$a['rank'], $a['fanout'], $a['index']]
                <=> [$b['rank'], $b['fanout'], $b['index']];
        });

        return array_map(static fn (array $d): array => $d['candidate'], $decorated);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function riskRank(array $c): int
    {
        $risk = strtolower($this->str($c['collision_risk'] ?? null) ?? 'unknown');

        return match ($risk) {
            'none' => 0,
            'low' => 1,
            default => 2,
        };
    }

    /**
     * Selection Policy 6: a required validator exists. Accepts either an explicit
     * boolean flag or a non-empty validator id/name.
     *
     * @param array<string,mixed> $c
     */
    private function hasRequiredValidator(array $c): bool
    {
        if (array_key_exists('has_required_validator', $c)) {
            return (bool) $c['has_required_validator'];
        }

        return $this->str($c['required_validator'] ?? null) !== null;
    }

    /**
     * Selection Policy 5: forbidden_files must fence EVERY hot-scope category
     * (voice, kernel, provider, route, migration, daemon). Returns true only when
     * each needle is covered by at least one forbidden entry.
     *
     * @param list<string> $forbidden
     */
    private function declaresHotScopesForbidden(array $forbidden): bool
    {
        $haystack = strtolower(implode("\n", $forbidden));
        foreach (self::HOT_SCOPE_NEEDLES as $needle) {
            if (! str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Case-insensitive path/prefix intersection. An entry matches when it equals
     * the other path or is a directory/glob prefix of it (so a hot scope dir
     * "voice/" catches "voice/realtime.py").
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private function intersection(array $a, array $b): array
    {
        $hits = [];
        foreach ($a as $left) {
            foreach ($b as $right) {
                if ($this->pathTouches($left, $right) || $this->pathTouches($right, $left)) {
                    $hits[] = $left;
                    break;
                }
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * Does $rule cover $path? Equality, directory prefix ("dir/"), or glob prefix
     * ("dir/*"). Case-insensitive.
     */
    private function pathTouches(string $rule, string $path): bool
    {
        $r = strtolower($rule);
        $p = strtolower($path);
        if ($r === '' || $p === '') {
            return false;
        }
        if ($r === $p) {
            return true;
        }
        if (str_ends_with($r, '/') && str_starts_with($p, $r)) {
            return true;
        }
        if (str_ends_with($r, '*') && str_starts_with($p, rtrim($r, '*'))) {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $candidates
     * @return list<array<string,mixed>>
     */
    private function candidates(mixed $candidates): array
    {
        if (! is_array($candidates)) {
            return [];
        }

        $clean = [];
        foreach ($candidates as $c) {
            if (is_array($c)) {
                $clean[] = $c;
            }
        }

        return array_values($clean);
    }

    /**
     * @param mixed $paths
     * @return list<string>
     */
    private function normalizePaths(mixed $paths): array
    {
        return $this->normalizeStrings($paths);
    }

    /**
     * @param mixed $values
     * @return list<string>
     */
    private function normalizeStrings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $clean = [];
        foreach ($values as $v) {
            if (is_string($v) && trim($v) !== '') {
                $clean[] = trim($v);
            }
        }

        return array_values(array_unique($clean));
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return null;
    }
}
