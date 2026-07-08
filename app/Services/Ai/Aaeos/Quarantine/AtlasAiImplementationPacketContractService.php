<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas Self-Construction · AI Implementation Packet Contract — pure,
 * deterministic admission validator for the universal work packet that lets
 * ANOTHER AI continue Atlas construction from one short instruction
 * ("continue a implementação").
 *
 * Scope of this service (distinct from the Scope Validator): the Scope
 * Validator classifies REALIZED file changes AFTER implementation. This service
 * validates the PACKET ARTIFACT ITSELF — its schema, hard invariants and
 * acceptance rules — BEFORE it is claimed/executed, and judges completion AFTER
 * evidence is returned. It is the admission + completion gate for the packet.
 *
 * Documented contract this code enforces (from the doc):
 *
 *   Packet Schema (closed sets):
 *     - status     ∈ available|claimed|blocked|completed|stale
 *     - lane       ∈ self_construction|docs|tests|runtime_read_only|runtime_scoped
 *     - risk_level ∈ low|medium|high|critical
 *     - provider_profile ∈ codex|claude|gemini|local_agent|generic
 *
 *   Hard Invariants:
 *     - "execution_allowed=false until a governed receipt permits execution."
 *     - "allowed_files is the maximum write set."  (must be non-empty)
 *     - "forbidden_files always wins over allowed_files."  (overlap => reject)
 *     - "Every acceptance criterion must map to at least one evidence item."
 *     - "One AI claims one packet at a time."  (claimed => session must own it)
 *     - "A provider adapter may narrow execution instructions but may not widen
 *       scope."  (normalized_final_response_required must stay true)
 *
 *   Rejected Packet rules (any => reject/block):
 *     - has no allowed_files;
 *     - permits broad directories such as app/** without sub-scope;
 *     - omits gates (required_gates empty);
 *     - asks for implementation before its structural contract exists;
 *     - overlaps hot files owned by another session;
 *     - changes security, payment, provider, daemon, memory or runtime policy
 *       without explicit critical AP + receipt.
 *
 *   Completion Criteria (all required for status=complete):
 *     - all acceptance criteria satisfied;
 *     - required gates passed OR failures explicitly external;
 *     - Scope Validator reports no blocking violation;
 *     - evidence attached;
 *     - residual risk documented;
 *     - no unrelated file changed by the packet owner;
 *     - normalized final response returned (id, files, commands, gates, risks).
 *
 * Non-goals honoured (the service never widens authority):
 *   - It does NOT authorize merge, does NOT sign receipts, does NOT flip
 *     execution_allowed to true, does NOT let an AI swap to a "more useful" task.
 *     It only admits/rejects packets and judges completion, emitting evidence.
 *
 * @see docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
 */
final class AtlasAiImplementationPacketContractService
{
    /** Stable evidence schema id this validator emits. */
    public const SCHEMA = 'atlas.self_construction.ai_implementation_packet.v1';

    /** Admission verdicts (closed set). */
    public const VERDICT_ADMITTED = 'admitted';
    public const VERDICT_REJECTED = 'rejected';
    public const VERDICT_BLOCKED = 'blocked';

    /** Packet status closed set (Packet Schema). */
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_STALE = 'stale';

    /** Lane closed set (Packet Schema). */
    public const LANES = [
        'self_construction',
        'docs',
        'tests',
        'runtime_read_only',
        'runtime_scoped',
    ];

    /** Risk-level closed set (Packet Schema). */
    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    /** Provider profile closed set (Packet Schema). */
    public const PROVIDER_PROFILES = ['codex', 'claude', 'gemini', 'local_agent', 'generic'];

    /** Completion verdicts (closed set). */
    public const COMPLETION_COMPLETE = 'complete';
    public const COMPLETION_INCOMPLETE = 'incomplete';

    /**
     * "Rejected Packet" — broad directory roots that may not be granted without a
     * narrower sub-scope. An allowed_files entry equal to or a prefix of one of
     * these (e.g. "app/", "app/**", "app/*") is too broad.
     *
     * @var list<string>
     */
    private const BROAD_ROOTS = ['app/', 'app/**', 'app/*', 'routes/', 'database/', 'runtimes/', 'config/', 'src/'];

    /**
     * "Rejected Packet" sensitive-policy needles: a packet that writes any of
     * these scopes requires an explicit critical AP + signed receipt. Each maps
     * to its documented reason category.
     *
     * @var array<string,string>
     */
    private const SENSITIVE_NEEDLES = [
        'security' => 'security_policy',
        'auth' => 'security_policy',
        'payment' => 'payment_policy',
        'billing' => 'payment_policy',
        'provider' => 'provider_policy',
        'daemon' => 'daemon_policy',
        'memory' => 'memory_policy',
        'runtime_policy' => 'runtime_policy',
    ];

    /**
     * Validate a packet for ADMISSION (before it is claimed/executed).
     *
     * @param array<string,mixed> $packet a packet matching the doc Packet Schema, plus:
     *        session_id            : string  the AI session asking to consume it
     *        structural_contract_exists : bool  whether the subsystem contract doc exists
     *        has_critical_ap       : bool  a signed critical AP authorizes sensitive scope
     *        hot_files             : list<string>  files owned by another active session
     *
     * @return array<string,mixed> admission evidence document
     */
    public function validateAdmission(array $packet): array
    {
        $packetId = $this->str($packet['packet_id'] ?? null) ?? 'AIP-UNKNOWN';
        $status = $this->normalizeStatus($packet['status'] ?? null);

        $allowed = AtlasAaeosValueNormalizer::uniqueTrimmedStringList($packet['allowed_files'] ?? []);
        $forbidden = AtlasAaeosValueNormalizer::uniqueTrimmedStringList($packet['forbidden_files'] ?? []);
        $requiredGates = AtlasAaeosValueNormalizer::uniqueTrimmedStringList($packet['required_gates'] ?? []);
        $hotFiles = AtlasAaeosValueNormalizer::uniqueTrimmedStringList($packet['hot_files'] ?? []);
        $acceptance = AtlasAaeosValueNormalizer::uniqueTrimmedStringList($packet['acceptance_criteria'] ?? []);
        $evidence = AtlasAaeosValueNormalizer::uniqueTrimmedStringList($packet['required_evidence'] ?? []);

        $touchesSensitive = $this->touchesSensitive($allowed);
        $hasCriticalAp = (bool) ($packet['has_critical_ap'] ?? false);
        $structuralContractExists = (bool) ($packet['structural_contract_exists'] ?? false);
        $sessionId = $this->str($packet['session_id'] ?? null);
        $claimedBy = $this->str($packet['claimed_by'] ?? null);

        $rejections = [];   // hard rejects (malformed / unsafe packet contract)
        $blocks = [];       // blocks (collisions / claim conflict; packet may retry)
        $invariantFailures = [];

        // ---- Packet Schema closed-set validation -----------------------------
        if (! $this->inSet($this->str($packet['lane'] ?? null), self::LANES)) {
            $rejections[] = $this->reason('invalid_lane',
                'lane must be one of: ' . implode('|', self::LANES) . '.');
        }
        if (! $this->inSet($this->str($packet['risk_level'] ?? null), self::RISK_LEVELS)) {
            $rejections[] = $this->reason('invalid_risk_level',
                'risk_level must be one of: ' . implode('|', self::RISK_LEVELS) . '.');
        }
        $providerProfile = $this->providerProfile($packet);
        if (! $this->inSet($providerProfile, self::PROVIDER_PROFILES)) {
            $rejections[] = $this->reason('invalid_provider_profile',
                'provider_contract.provider_profile must be one of: ' . implode('|', self::PROVIDER_PROFILES) . '.');
        }

        // ---- Hard Invariants -------------------------------------------------
        // "execution_allowed=false until a governed receipt permits execution."
        if (($packet['execution_allowed'] ?? false) === true) {
            $invariantFailures[] = $this->reason('execution_allowed_true_without_receipt',
                'execution_allowed must be false at admission; only a governed receipt may permit execution.');
        }
        // normalized_final_response_required must remain true (adapter may not widen scope).
        if ($this->normalizedFinalResponseRequired($packet) !== true) {
            $invariantFailures[] = $this->reason('normalized_response_not_required',
                'provider_contract.normalized_final_response_required must be true.');
        }

        // ---- Rejected Packet rules ------------------------------------------
        // 1. no allowed_files (also: "allowed_files is the maximum write set").
        if ($allowed === []) {
            $rejections[] = $this->reason('missing_allowed_files',
                'Packet has no allowed_files; the maximum write set must be declared.');
        }
        // 2. broad directory without sub-scope (e.g. app/** without a narrower path).
        foreach ($this->broadGrants($allowed) as $broad) {
            $rejections[] = $this->reason('broad_scope_without_subscope',
                "allowed_files entry '{$broad}' is too broad; declare a narrower sub-scope.");
        }
        // 3. omits gates.
        if ($requiredGates === []) {
            $rejections[] = $this->reason('missing_gates',
                'Packet omits required_gates; gates prove the work.');
        }
        // 4. forbidden overlaps allowed ("forbidden_files always wins over allowed_files").
        foreach ($this->overlap($allowed, $forbidden) as $clash) {
            $rejections[] = $this->reason('forbidden_overlaps_allowed',
                "Path '{$clash}' is in BOTH allowed_files and forbidden_files; forbidden wins, packet is contradictory.");
        }
        // 5. implementation before its structural contract exists (only for code lanes).
        if ($this->isCodeLane($this->str($packet['lane'] ?? null)) && ! $structuralContractExists) {
            $rejections[] = $this->reason('structural_contract_missing',
                'Packet asks for implementation before its structural contract exists.');
        }
        // 6. sensitive scope without explicit critical AP + receipt.
        if ($touchesSensitive !== [] && ! $hasCriticalAp) {
            foreach ($touchesSensitive as $cat) {
                $rejections[] = $this->reason('sensitive_scope_without_critical_ap',
                    "Packet writes '{$cat}' scope without an explicit critical AP + signed receipt.");
            }
        }
        // 7. acceptance criterion without an evidence item to map to.
        //    "Every acceptance criterion must map to at least one evidence item."
        if ($acceptance !== [] && $evidence === []) {
            $invariantFailures[] = $this->reason('acceptance_without_evidence',
                'Acceptance criteria exist but required_evidence is empty; every criterion must map to evidence.');
        }

        // ---- Blocks (recoverable, not malformed) ----------------------------
        // Overlaps hot files owned by another session.
        $hotClashes = $this->overlap($allowed, $hotFiles);
        if ($hotClashes !== []) {
            foreach ($hotClashes as $hc) {
                $blocks[] = $this->reason('hot_file_collision',
                    "allowed_files entry '{$hc}' overlaps files owned by another active session.");
            }
        }
        // "One AI claims one packet at a time": a claimed packet must be owned by
        // THIS requesting session.
        if ($status === self::STATUS_CLAIMED) {
            if ($claimedBy === null) {
                $rejections[] = $this->reason('claimed_without_owner',
                    'Packet status is claimed but claimed_by is empty.');
            } elseif ($sessionId !== null && $claimedBy !== $sessionId) {
                $blocks[] = $this->reason('claimed_by_other_session',
                    "Packet is claimed by another session ('{$claimedBy}'); one AI claims one packet at a time.");
            }
        }
        // A stale/blocked packet is never admissible for consumption.
        if ($status === self::STATUS_STALE) {
            $blocks[] = $this->reason('packet_stale', 'Packet status is stale; refresh before consuming.');
        }
        if ($status === self::STATUS_BLOCKED) {
            $blocks[] = $this->reason('packet_blocked', 'Packet status is blocked.');
        }

        $allRejections = array_merge($rejections, $invariantFailures);
        $verdict = $this->resolveAdmissionVerdict($allRejections, $blocks);

        return [
            'schema' => self::SCHEMA,
            'phase' => 'admission',
            'packet_id' => $packetId,
            'verdict' => $verdict,
            'admissible' => $verdict === self::VERDICT_ADMITTED,
            'execution_allowed' => false, // never flipped by this service.
            'provider_profile' => $providerProfile,
            'rejections' => $allRejections,
            'blocks' => $blocks,
            'invariant_failures' => $invariantFailures,
            'summary' => [
                'rejection_count' => count($allRejections),
                'block_count' => count($blocks),
                'invariant_failure_count' => count($invariantFailures),
            ],
        ];
    }

    /**
     * Judge a returned packet for COMPLETION (after evidence is produced).
     * Implements the doc's "Completion Criteria" exactly: ALL must hold.
     *
     * @param array<string,mixed> $report normalized final response, with:
     *        packet_id                 : string
     *        acceptance_criteria       : list<string>
     *        satisfied_criteria        : list<string>
     *        gates                     : list<array{name:string,passed:bool,external?:bool}>
     *        scope_validator_status    : string  pass|fail|blocked (from Scope Validator)
     *        evidence                  : list<string>  attached evidence items
     *        residual_risk_documented  : bool
     *        unrelated_files_changed   : list<string>  files outside packet scope
     *        normalized_final_response : bool  was the normalized response returned
     *
     * @return array<string,mixed> completion evidence document
     */
    public function judgeCompletion(array $report): array
    {
        $packetId = $this->str($report['packet_id'] ?? null) ?? 'AIP-UNKNOWN';

        $acceptance = AtlasAaeosValueNormalizer::uniqueTrimmedStringList($report['acceptance_criteria'] ?? []);
        $satisfied = AtlasAaeosValueNormalizer::uniqueTrimmedStringList($report['satisfied_criteria'] ?? []);
        $missingCriteria = array_values(array_diff($acceptance, $satisfied));

        $gates = is_array($report['gates'] ?? null) ? $report['gates'] : [];
        $failedGates = [];
        foreach ($gates as $gate) {
            if (! is_array($gate)) {
                continue;
            }
            $passed = ($gate['passed'] ?? false) === true;
            $external = ($gate['external'] ?? false) === true;
            // Completion allows a failed gate ONLY when the failure is explicitly external.
            if (! $passed && ! $external) {
                $failedGates[] = $this->str($gate['name'] ?? null) ?? 'unnamed_gate';
            }
        }

        $scopeStatus = strtolower((string) ($report['scope_validator_status'] ?? 'fail'));
        $scopeClean = $scopeStatus === 'pass';

        $evidence = AtlasAaeosValueNormalizer::uniqueTrimmedStringList($report['evidence'] ?? []);
        $evidenceAttached = $evidence !== [];

        $residualDocumented = ($report['residual_risk_documented'] ?? false) === true;
        $unrelated = AtlasAaeosValueNormalizer::uniqueTrimmedStringList($report['unrelated_files_changed'] ?? []);
        $normalizedResponse = ($report['normalized_final_response'] ?? false) === true;

        $blockers = [];
        if ($missingCriteria !== []) {
            $blockers[] = $this->reason('acceptance_criteria_unmet',
                'Unsatisfied acceptance criteria: ' . implode('; ', $missingCriteria) . '.');
        }
        if ($failedGates !== []) {
            $blockers[] = $this->reason('gate_failed_not_external',
                'Required gates failed without being explicitly external: ' . implode(', ', $failedGates) . '.');
        }
        if (! $scopeClean) {
            $blockers[] = $this->reason('scope_validator_not_clean',
                "Scope Validator status is '{$scopeStatus}', not pass.");
        }
        if (! $evidenceAttached) {
            $blockers[] = $this->reason('evidence_missing', 'No evidence attached.');
        }
        if (! $residualDocumented) {
            $blockers[] = $this->reason('residual_risk_undocumented', 'Residual risk is not documented.');
        }
        if ($unrelated !== []) {
            $blockers[] = $this->reason('unrelated_files_changed',
                'Packet owner changed unrelated files: ' . implode(', ', $unrelated) . '.');
        }
        if (! $normalizedResponse) {
            $blockers[] = $this->reason('normalized_response_missing',
                'Normalized final response (id, files, commands, gates, risks) was not returned.');
        }

        $complete = $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'phase' => 'completion',
            'packet_id' => $packetId,
            'verdict' => $complete ? self::COMPLETION_COMPLETE : self::COMPLETION_INCOMPLETE,
            'complete' => $complete,
            'final_status' => $complete ? self::STATUS_COMPLETED : self::STATUS_CLAIMED,
            'blockers' => $blockers,
            'criteria_total' => count($acceptance),
            'criteria_satisfied' => count(array_intersect($acceptance, $satisfied)),
            'criteria_missing' => $missingCriteria,
            'summary' => [
                'blocker_count' => count($blockers),
                'failed_gate_count' => count($failedGates),
                'unrelated_file_count' => count($unrelated),
            ],
        ];
    }

    /** Convenience predicate: is this packet admissible for consumption? */
    public function isAdmissible(array $packet): bool
    {
        return $this->validateAdmission($packet)['verdict'] === self::VERDICT_ADMITTED;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @param list<array<string,string>> $rejections
     * @param list<array<string,string>> $blocks
     */
    private function resolveAdmissionVerdict(array $rejections, array $blocks): string
    {
        if ($rejections !== []) {
            return self::VERDICT_REJECTED;
        }
        if ($blocks !== []) {
            return self::VERDICT_BLOCKED;
        }

        return self::VERDICT_ADMITTED;
    }

    /**
     * Broad grants: allowed entries that equal/contain a broad root with no
     * narrower sub-scope. "app/" or "app/**" is broad; "app/Services/Foo/" is not.
     *
     * @param list<string> $allowed
     * @return list<string>
     */
    private function broadGrants(array $allowed): array
    {
        $broad = [];
        foreach ($allowed as $entry) {
            $norm = strtolower(rtrim($entry, '/* '));
            foreach (self::BROAD_ROOTS as $root) {
                $rootNorm = strtolower(rtrim($root, '/* '));
                if ($norm === $rootNorm) {
                    $broad[] = $entry;
                    break;
                }
            }
        }

        return array_values(array_unique($broad));
    }

    /**
     * Sensitive categories the allowed_files touch.
     *
     * @param list<string> $allowed
     * @return list<string>
     */
    private function touchesSensitive(array $allowed): array
    {
        $hit = [];
        foreach ($allowed as $entry) {
            $needle = strtolower($entry);
            foreach (self::SENSITIVE_NEEDLES as $sub => $category) {
                if (str_contains($needle, $sub)) {
                    $hit[$category] = $category;
                }
            }
        }

        return array_values($hit);
    }

    /**
     * Case-insensitive intersection of two path lists (exact path overlap).
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private function overlap(array $a, array $b): array
    {
        $lowerB = array_map('strtolower', $b);
        $out = [];
        foreach ($a as $entry) {
            if (in_array(strtolower($entry), $lowerB, true)) {
                $out[] = $entry;
            }
        }

        return array_values(array_unique($out));
    }

    private function isCodeLane(?string $lane): bool
    {
        return $lane === 'runtime_scoped' || $lane === 'self_construction';
    }

    private function normalizeStatus(mixed $v): string
    {
        return AtlasAaeosValueNormalizer::trimmedAllowed($v, [
            self::STATUS_AVAILABLE, self::STATUS_CLAIMED, self::STATUS_BLOCKED,
            self::STATUS_COMPLETED, self::STATUS_STALE,
        ], self::STATUS_AVAILABLE);
    }

    private function providerProfile(array $packet): ?string
    {
        $pc = $packet['provider_contract'] ?? null;
        if (is_array($pc)) {
            return $this->str($pc['provider_profile'] ?? null);
        }

        return null;
    }

    private function normalizedFinalResponseRequired(array $packet): bool
    {
        $pc = $packet['provider_contract'] ?? null;
        if (is_array($pc) && array_key_exists('normalized_final_response_required', $pc)) {
            return $pc['normalized_final_response_required'] === true;
        }

        // Absent => treat as required (the doc mandates it); only an explicit
        // false is an invariant failure.
        return true;
    }

    /**
     * @param list<string> $set
     */
    private function inSet(?string $value, array $set): bool
    {
        return $value !== null && in_array($value, $set, true);
    }

    /**
     * @return array{code:string,message:string}
     */
    private function reason(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return null;
    }
}
