<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Self-Construction · Multi-Provider Agent Orchestration Contract — pure,
 * deterministic governance for coordinating Codex, Claude, Gemini, local agents
 * and future providers around ONE universal implementation packet.
 *
 * Scope of this service (distinct from the AI Implementation Packet Contract
 * service, which admits/judges a SINGLE packet artifact): this service governs
 * the ORCHESTRATION layer ACROSS providers — what a provider adapter may/may not
 * do to a packet, how a provider's final response is normalized before Atlas
 * accepts completion, which readiness level is permitted, and the hard stops
 * that halt multi-provider work. Atlas owns packet truth; providers are
 * replaceable executors.
 *
 * Documented rules this code enforces (from the doc):
 *
 *   Adapter Rule (## Adapter Rule):
 *     An adapter MAY: rephrase prompt, add provider tool instructions, compress
 *     context, choose a SAFER SUBSET of the packet.
 *     An adapter MUST NOT: widen allowed_files, remove forbidden_files, skip
 *     required gates, hide stop conditions, mark completion, approve/merge/
 *     dispatch, alter the packet hash. Any violation => adapter rejected.
 *
 *   Evidence Normalization (## Evidence Normalization):
 *     Every provider final response must normalize to the closed schema
 *     {packet_id, provider, files_changed, commands_run, gates, evidence_hash,
 *      scope_deviations, residual_risks, completion_claim}. "Atlas should reject
 *     completion if the response cannot be normalized." completion_claim ∈
 *     complete|partial|blocked; provider ∈ the five profiles.
 *
 *   Provider Profiles (## Provider Profiles):
 *     codex|claude|gemini|local_agent|generic, each with a routing signal.
 *     "Profiles guide assignment. They do not change the packet."
 *     Unknown capability => fall back to `generic`.
 *
 *   Readiness Levels (## Readiness Levels):
 *     L1..L5. "L5 is future and must remain blocked without explicit receipts."
 *     L5 auto-dispatch requires signed authority + reservations + gates.
 *
 *   Splitter Behavior (## Splitter Behavior) + Hard Stops (## Hard Stops):
 *     Two providers touching the same write set is a hard stop; the splitter
 *     prefers disjoint write sets. Free-form-only evidence, scope broadening,
 *     forbidden-file edits, missing gates, and self-claimed approval/merge/
 *     dispatch authority are all hard stops.
 *
 * Non-goals honoured (the service never widens authority): it does NOT dispatch
 * a provider, does NOT mark completion, does NOT approve/merge, does NOT flip L5
 * open, does NOT mutate the packet. It only judges adapter legality, normalizes
 * evidence, routes a profile, and reports stops — emitting evidence.
 *
 * @see docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
 */
final class AtlasMultiProviderAgentOrchestrationContractService
{
    /** Stable evidence schema id this service emits. */
    public const SCHEMA = 'atlas.self_construction.multi_provider_orchestration.v1';

    /** Provider profile closed set (## Provider Profiles / ## Evidence Normalization). */
    public const PROVIDER_PROFILES = ['codex', 'claude', 'gemini', 'local_agent', 'generic'];

    /** Fallback profile when capability is unknown ("generic" in the doc). */
    public const FALLBACK_PROFILE = 'generic';

    /** completion_claim closed set (## Evidence Normalization). */
    public const COMPLETION_CLAIMS = ['complete', 'partial', 'blocked'];

    /** Adapter verdicts (closed set). */
    public const ADAPTER_LEGAL = 'legal';
    public const ADAPTER_REJECTED = 'rejected';

    /** Readiness levels (## Readiness Levels). */
    public const READINESS_LEVELS = [
        'L1' => 'single_provider',
        'L2' => 'multi_session_same_provider',
        'L3' => 'multi_provider_manual',
        'L4' => 'multi_provider_governed',
        'L5' => 'auto_orchestrated',
    ];

    /** Highest readiness level allowed WITHOUT explicit signed receipts. */
    public const MAX_LEVEL_WITHOUT_RECEIPT = 'L4';

    /**
     * Routing signal per profile (## Provider Profiles). Used to suggest a
     * profile from a packet's nature; never mutates the packet.
     *
     * @var array<string,string>
     */
    private const ROUTING_SIGNAL = [
        'codex' => 'implementation-heavy packet',
        'claude' => 'review or documentation packet',
        'gemini' => 'broad context or visual/source packet',
        'local_agent' => 'mechanical validation packet',
        'generic' => 'fallback when capability is unknown',
    ];

    /**
     * Judge whether a provider adapter's transformation of a packet is LEGAL.
     *
     * Implements ## Adapter Rule exactly: the adapter may narrow but never widen.
     * "Choose a safer subset of the packet" is allowed, so REMOVING entries from
     * allowed_files (narrowing) is legal; ADDING any is a widen and illegal.
     * forbidden_files may only grow or stay; removing any is illegal. Required
     * gates and stop conditions may only be preserved (subset removal is illegal).
     * Marking completion / approve / merge / dispatch, or changing the packet
     * hash, is illegal.
     *
     * @param array<string,mixed> $base    the canonical packet (Atlas truth), with:
     *        allowed_files     : list<string>
     *        forbidden_files   : list<string>
     *        required_gates    : list<string>
     *        stop_conditions   : list<string>
     *        packet_hash       : string
     * @param array<string,mixed> $adapted the adapter's proposed packet, same keys, plus:
     *        marks_completion  : bool  did the adapter claim completion?
     *        claims_authority  : list<string>  e.g. ["approve","merge","dispatch"]
     *
     * @return array<string,mixed> adapter legality evidence document
     */
    public function evaluateAdapter(array $base, array $adapted): array
    {
        $baseAllowed = $this->paths($base['allowed_files'] ?? []);
        $baseForbidden = $this->paths($base['forbidden_files'] ?? []);
        $baseGates = $this->paths($base['required_gates'] ?? []);
        $baseStops = $this->paths($base['stop_conditions'] ?? []);
        $baseHash = $this->str($base['packet_hash'] ?? null);

        $adaptedAllowed = $this->paths($adapted['allowed_files'] ?? []);
        $adaptedForbidden = $this->paths($adapted['forbidden_files'] ?? []);
        $adaptedGates = $this->paths($adapted['required_gates'] ?? []);
        $adaptedStops = $this->paths($adapted['stop_conditions'] ?? []);
        $adaptedHash = $this->str($adapted['packet_hash'] ?? null);

        $violations = [];

        // MUST NOT widen allowed_files: any adapted entry not in base is a widen.
        $widened = $this->notIn($adaptedAllowed, $baseAllowed);
        foreach ($widened as $w) {
            $violations[] = $this->reason('widened_allowed_files',
                "Adapter added '{$w}' to allowed_files; adapters may narrow but never widen scope.");
        }

        // MUST NOT remove forbidden_files: any base forbidden missing from adapted.
        $removedForbidden = $this->notIn($baseForbidden, $adaptedForbidden);
        foreach ($removedForbidden as $rf) {
            $violations[] = $this->reason('removed_forbidden_files',
                "Adapter dropped forbidden file '{$rf}'; forbidden_files may never be removed.");
        }

        // MUST NOT skip required gates: any base gate missing from adapted.
        $skippedGates = $this->notIn($baseGates, $adaptedGates);
        foreach ($skippedGates as $sg) {
            $violations[] = $this->reason('skipped_required_gate',
                "Adapter omitted required gate '{$sg}'; required gates may never be skipped.");
        }

        // MUST NOT hide stop conditions: any base stop missing from adapted.
        $hiddenStops = $this->notIn($baseStops, $adaptedStops);
        foreach ($hiddenStops as $hs) {
            $violations[] = $this->reason('hidden_stop_condition',
                "Adapter hid stop condition '{$hs}'; stop conditions may never be hidden.");
        }

        // MUST NOT mark completion.
        if (($adapted['marks_completion'] ?? false) === true) {
            $violations[] = $this->reason('adapter_marked_completion',
                'Adapter marked completion; only Atlas marks completion after evidence normalization.');
        }

        // MUST NOT approve, merge or dispatch.
        $claimed = $this->paths($adapted['claims_authority'] ?? []);
        foreach (array_intersect(['approve', 'merge', 'dispatch'], $claimed) as $auth) {
            $violations[] = $this->reason('adapter_claimed_authority',
                "Adapter claimed '{$auth}' authority; adapters may not approve, merge or dispatch.");
        }

        // MUST NOT alter packet hash.
        if ($baseHash !== null && $adaptedHash !== null && $baseHash !== $adaptedHash) {
            $violations[] = $this->reason('altered_packet_hash',
                'Adapter altered the packet hash; the packet hash is immutable.');
        }

        // Informational: a strict narrowing of allowed_files is the legal "safer
        // subset" path the doc explicitly permits.
        $narrowed = $this->notIn($baseAllowed, $adaptedAllowed);

        $legal = $violations === [];

        return [
            'schema' => self::SCHEMA,
            'phase' => 'adapter',
            'verdict' => $legal ? self::ADAPTER_LEGAL : self::ADAPTER_REJECTED,
            'legal' => $legal,
            'violations' => $violations,
            'narrowed_allowed_files' => $narrowed, // permitted "safer subset"
            'summary' => [
                'violation_count' => count($violations),
                'widened_count' => count($widened),
                'narrowed_count' => count($narrowed),
            ],
        ];
    }

    /**
     * Normalize a provider's free-form-ish final response into the closed schema
     * from ## Evidence Normalization. "Atlas should reject completion if the
     * response cannot be normalized" — so a response missing the hard identity
     * fields (packet_id, provider) or carrying an out-of-set provider/
     * completion_claim is reported as un-normalizable and completion is rejected.
     *
     * @param array<string,mixed> $response provider final response (any shape), ideally with:
     *        packet_id        : string
     *        provider         : string  (one of the five profiles)
     *        files_changed    : list<string>
     *        commands_run     : list<string>
     *        gates            : list<string>
     *        evidence_hash    : string|null  ("sha256|null")
     *        scope_deviations : list<string>
     *        residual_risks   : list<string>
     *        completion_claim : string  (complete|partial|blocked)
     *
     * @return array<string,mixed> normalization evidence document
     */
    public function normalizeEvidence(array $response): array
    {
        $errors = [];

        // packet_id is a hard identity field; without it the response cannot be
        // tied back to Atlas's packet truth.
        $packetId = $this->str($response['packet_id'] ?? null);
        if ($packetId === null) {
            $errors[] = $this->reason('missing_packet_id',
                'Response has no packet_id; it cannot be tied to a packet.');
        }

        // provider must be one of the closed profile set.
        $provider = $this->str($response['provider'] ?? null);
        if ($provider === null) {
            $errors[] = $this->reason('missing_provider',
                'Response has no provider field.');
        } elseif (! in_array($provider, self::PROVIDER_PROFILES, true)) {
            $errors[] = $this->reason('invalid_provider',
                "provider '{$provider}' is not one of: " . implode('|', self::PROVIDER_PROFILES) . '.');
        }

        // completion_claim must be one of the closed set.
        $claim = $this->str($response['completion_claim'] ?? null);
        if ($claim === null) {
            $errors[] = $this->reason('missing_completion_claim',
                'Response has no completion_claim (complete|partial|blocked).');
        } elseif (! in_array($claim, self::COMPLETION_CLAIMS, true)) {
            $errors[] = $this->reason('invalid_completion_claim',
                "completion_claim '{$claim}' is not one of: " . implode('|', self::COMPLETION_CLAIMS) . '.');
        }

        $normalizable = $errors === [];

        // Build the canonical normalized record (closed schema, every key present).
        $evidenceHash = $this->str($response['evidence_hash'] ?? null); // may be null per "sha256|null"
        $normalized = [
            'packet_id' => $packetId,
            'provider' => $provider,
            'files_changed' => $this->paths($response['files_changed'] ?? []),
            'commands_run' => $this->paths($response['commands_run'] ?? []),
            'gates' => $this->paths($response['gates'] ?? []),
            'evidence_hash' => $evidenceHash,
            'scope_deviations' => $this->paths($response['scope_deviations'] ?? []),
            'residual_risks' => $this->paths($response['residual_risks'] ?? []),
            'completion_claim' => $normalizable ? $claim : null,
        ];

        // Atlas rejects completion when the response cannot be normalized; it also
        // never accepts a "complete" claim that carries scope deviations.
        $completionAccepted = $normalizable
            && $claim === 'complete'
            && $normalized['scope_deviations'] === [];

        return [
            'schema' => self::SCHEMA,
            'phase' => 'evidence_normalization',
            'normalizable' => $normalizable,
            'completion_accepted' => $completionAccepted,
            'normalized' => $normalizable ? $normalized : null,
            'errors' => $errors,
            'summary' => [
                'error_count' => count($errors),
                'scope_deviation_count' => count($normalized['scope_deviations']),
            ],
        ];
    }

    /**
     * Route a packet's nature to a provider profile (## Provider Profiles).
     * Returns the matched profile + its routing signal, or the documented
     * `generic` fallback when capability is unknown. Never mutates the packet
     * ("Profiles guide assignment. They do not change the packet.").
     *
     * @param string|null $nature one of: implementation|review|documentation|
     *        broad_context|visual|mechanical|unknown (free text tolerated)
     *
     * @return array{schema:string,phase:string,profile:string,routing_signal:string,is_fallback:bool}
     */
    public function routeProfile(?string $nature): array
    {
        $n = strtolower(trim((string) $nature));

        $profile = match (true) {
            $n === 'implementation', str_contains($n, 'implement'), str_contains($n, 'code edit') => 'codex',
            $n === 'review', str_contains($n, 'review'), str_contains($n, 'critique'), str_contains($n, 'policy') => 'claude',
            $n === 'documentation', str_contains($n, 'doc') => 'claude',
            $n === 'broad_context', str_contains($n, 'broad'), str_contains($n, 'long context') => 'gemini',
            $n === 'visual', str_contains($n, 'visual'), str_contains($n, 'multimodal'), str_contains($n, 'source') => 'gemini',
            $n === 'mechanical', str_contains($n, 'lint'), str_contains($n, 'format'), str_contains($n, 'static') => 'local_agent',
            default => self::FALLBACK_PROFILE,
        };

        return [
            'schema' => self::SCHEMA,
            'phase' => 'routing',
            'profile' => $profile,
            'routing_signal' => self::ROUTING_SIGNAL[$profile],
            'is_fallback' => $profile === self::FALLBACK_PROFILE,
        ];
    }

    /**
     * Gate a requested readiness level (## Readiness Levels). L1..L4 are allowed
     * for current documentation / read-only runtime surfaces. L5 (auto-
     * orchestrated dispatch) "must remain blocked without explicit receipts" —
     * it requires ALL of signed authority + reservations + gates green.
     *
     * @param string              $requestedLevel  e.g. "L5"
     * @param array<string,mixed> $authority with:
     *        signed_authority : bool
     *        reservations     : bool
     *        gates_green      : bool
     *
     * @return array<string,mixed> readiness gate evidence document
     */
    public function gateReadiness(string $requestedLevel, array $authority = []): array
    {
        $level = strtoupper(trim($requestedLevel));
        $known = array_key_exists($level, self::READINESS_LEVELS);

        $signed = ($authority['signed_authority'] ?? false) === true;
        $reservations = ($authority['reservations'] ?? false) === true;
        $gatesGreen = ($authority['gates_green'] ?? false) === true;

        $blockers = [];
        if (! $known) {
            $blockers[] = $this->reason('unknown_readiness_level',
                "Readiness level '{$level}' is not one of: " . implode('|', array_keys(self::READINESS_LEVELS)) . '.');
        }

        // L5 is the only level requiring receipts; everything up to L4 is open.
        $requiresReceipts = $level === 'L5';
        if ($requiresReceipts) {
            if (! $signed) {
                $blockers[] = $this->reason('missing_signed_authority',
                    'L5 auto-orchestration requires signed authority.');
            }
            if (! $reservations) {
                $blockers[] = $this->reason('missing_reservations',
                    'L5 auto-orchestration requires write-set reservations.');
            }
            if (! $gatesGreen) {
                $blockers[] = $this->reason('gates_not_green',
                    'L5 auto-orchestration requires all gates green.');
            }
        }

        $allowed = $known && $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'phase' => 'readiness_gate',
            'requested_level' => $level,
            'meaning' => $known ? self::READINESS_LEVELS[$level] : null,
            'requires_receipts' => $requiresReceipts,
            'allowed' => $allowed,
            'blocked' => ! $allowed,
            'blockers' => $blockers,
            'max_level_without_receipt' => self::MAX_LEVEL_WITHOUT_RECEIPT,
        ];
    }

    /**
     * Detect ## Hard Stops across an orchestration snapshot. Returns every
     * triggered stop; orchestration must halt if any is present. The write-set
     * collision check also encodes ## Splitter Behavior rule #1 (prefer disjoint
     * write sets): if two assignments overlap on any path, that is a hard stop.
     *
     * @param array<string,mixed> $snapshot with:
     *        assignments : list<array{provider:string,write_set:list<string>}>
     *        signals     : array{
     *            provider_requests_broader_scope?: bool,
     *            provider_edited_forbidden_file?: bool,
     *            provider_cannot_report_gates?: bool,
     *            evidence_free_form_only?: bool,
     *            provider_claims_merge_authority?: bool
     *        }
     *
     * @return array<string,mixed> hard-stop evidence document
     */
    public function detectHardStops(array $snapshot): array
    {
        $stops = [];
        $signals = is_array($snapshot['signals'] ?? null) ? $snapshot['signals'] : [];

        if (($signals['provider_requests_broader_scope'] ?? false) === true) {
            $stops[] = $this->reason('scope_broadening_requested',
                'A provider asked to broaden scope.');
        }
        if (($signals['provider_edited_forbidden_file'] ?? false) === true) {
            $stops[] = $this->reason('forbidden_file_edited',
                'A provider edited a forbidden file.');
        }
        if (($signals['provider_cannot_report_gates'] ?? false) === true) {
            $stops[] = $this->reason('gates_unreportable',
                'A provider cannot run or report required gates.');
        }
        if (($signals['evidence_free_form_only'] ?? false) === true) {
            $stops[] = $this->reason('evidence_free_form_only',
                'Evidence is free-form only and cannot be normalized.');
        }
        if (($signals['provider_claims_merge_authority'] ?? false) === true) {
            $stops[] = $this->reason('authority_claimed',
                'A provider claimed approval, merge or dispatch authority.');
        }

        // "two providers touch the same write set" — pairwise overlap across
        // assignments (also Splitter rule #1: prefer disjoint write sets).
        foreach ($this->collidingWriteSets($snapshot['assignments'] ?? []) as $collision) {
            $stops[] = $this->reason('write_set_collision',
                "Providers '{$collision['a']}' and '{$collision['b']}' both touch '{$collision['path']}'.");
        }

        $halt = $stops !== [];

        return [
            'schema' => self::SCHEMA,
            'phase' => 'hard_stops',
            'must_halt' => $halt,
            'clear' => ! $halt,
            'stops' => $stops,
            'summary' => ['stop_count' => count($stops)],
        ];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Pairwise write-set overlaps across assignments.
     *
     * @param mixed $assignments list<array{provider:string,write_set:list<string>}>
     * @return list<array{a:string,b:string,path:string}>
     */
    private function collidingWriteSets(mixed $assignments): array
    {
        if (! is_array($assignments)) {
            return [];
        }

        $clean = [];
        foreach ($assignments as $a) {
            if (! is_array($a)) {
                continue;
            }
            $provider = $this->str($a['provider'] ?? null) ?? 'unknown';
            $clean[] = ['provider' => $provider, 'write_set' => $this->paths($a['write_set'] ?? [])];
        }

        $collisions = [];
        $count = count($clean);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $shared = array_values(array_intersect(
                    array_map('strtolower', $clean[$i]['write_set']),
                    array_map('strtolower', $clean[$j]['write_set'])
                ));
                foreach ($shared as $path) {
                    $collisions[] = [
                        'a' => $clean[$i]['provider'],
                        'b' => $clean[$j]['provider'],
                        'path' => $path,
                    ];
                }
            }
        }

        return $collisions;
    }

    /**
     * Entries in $candidate that are NOT present in $reference (case-insensitive).
     *
     * @param list<string> $candidate
     * @param list<string> $reference
     * @return list<string>
     */
    private function notIn(array $candidate, array $reference): array
    {
        $lowerRef = array_map('strtolower', $reference);
        $out = [];
        foreach ($candidate as $entry) {
            if (! in_array(strtolower($entry), $lowerRef, true)) {
                $out[] = $entry;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array{code:string,message:string}
     */
    private function reason(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /**
     * @param mixed $paths
     * @return list<string>
     */
    private function paths(mixed $paths): array
    {
        if (! is_array($paths)) {
            return [];
        }

        $clean = [];
        foreach ($paths as $p) {
            if (is_string($p) && trim($p) !== '') {
                $clean[] = trim($p);
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
