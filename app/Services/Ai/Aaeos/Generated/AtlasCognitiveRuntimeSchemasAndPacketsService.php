<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Cognitive Runtime Schemas And Packets — pure, deterministic schema
 * guard for the four cognitive-runtime packet types.
 *
 * The doc defines the *shapes* the cognitive runtime exchanges (it is the schema
 * authority, not the operating procedure — that is the runbook). This service
 * turns those documented schema contracts into runtime. It is read-only: it
 * validates a packet's declared shape, enforces the closed "Packet Rules", and
 * answers the documented "Promotion Rule" over the three packet statuses. It
 * never touches the database, a provider, the shell or the filesystem, never
 * promotes memory, never alters policy and never applies a patch.
 *
 * Implemented decision surfaces (one per documented section):
 *
 *   1. Packet Rules ("Packet Rules"). Four invariants enforced verbatim, each
 *      fail-closed: packets are provider-safe by default; carry refs/hashes/
 *      summaries, never raw chat; a read-only packet may feed Self-Improvement
 *      proposal-only; a packet may NOT promote memory, alter policy or apply a
 *      patch on its own. evaluatePacketRules() returns, per requested capability,
 *      whether the packet is permitted to do it (always denying the three
 *      mutate capabilities, and denying provider-unsafe / raw-chat packets).
 *
 *   2. Long Session Snapshot ("Long Session Snapshot" schema). validateSnapshot()
 *      pins `schema_version`, the closed `status` set
 *      (active|paused|compacted|handoff|closed|blocked), the closed
 *      `current_phase` set, and the presence of the nine documented
 *      `quality_metrics` keys.
 *
 *   3. Compaction Packet ("Compaction Packet" table + status set).
 *      validateCompactionPacket() pins the seven required fields, the closed
 *      status set, and the doc's cross-field rule: `blocked_reasons` MUST be
 *      non-empty when status is any `blocked_*`, and `next_action` MUST be
 *      present when status is `ready`.
 *
 *   4. Cognitive Audit Packet ("Cognitive Audit Packet" schema). Pins the closed
 *      status set (ready|watch|critical), the gain/harm sub-blocks, and the
 *      documented relation net_value = gain - harm (sum of sub-fields).
 *
 *   5. Retrieval Evaluation Packet (the doc's fourth packet, the retrieval
 *      measures section). Pins that all eight required measures are present;
 *      reports the missing ones.
 *
 *   6. Promotion Rule ("Promotion Rule"). A 72h session may become maturity
 *      evidence ONLY when snapshot, compaction packet and audit packet are each
 *      `ready` — or `watch` with risks explicitly accepted by a human operator.
 *      Any other status (or an unaccepted `watch`, or `critical`/`blocked_*`)
 *      fails the gate, and the conversation itself is never blocked by this gate.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/schemas-and-packets.md
 */
final class AtlasCognitiveRuntimeSchemasAndPacketsService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.cognitive_runtime.schemas_and_packets.v1';

    /** Frozen schema_version ids for the four documented packets. */
    public const SCHEMA_SNAPSHOT = 'atlas.cognitive_runtime.long_session_snapshot.v1';
    public const SCHEMA_COMPACTION = 'atlas.cognitive_runtime.compaction_packet.v1';
    public const SCHEMA_AUDIT = 'atlas.cognitive_runtime.audit_packet.v1';

    /** Long Session Snapshot — closed `status` set. */
    public const SNAPSHOT_STATUSES = [
        'active', 'paused', 'compacted', 'handoff', 'closed', 'blocked',
    ];

    /** Long Session Snapshot — closed `current_phase` set. */
    public const SNAPSHOT_PHASES = [
        'planning', 'implementation', 'validation', 'review', 'handoff',
    ];

    /** Long Session Snapshot — the nine documented quality-metric keys. */
    public const SNAPSHOT_QUALITY_METRIC_KEYS = [
        'decision_quality',
        'drift_rate',
        'repeated_work_rate',
        'missed_invariant_count',
        'context_precision_at_k',
        'missed_critical_context_count',
        'context_contamination_count',
        'stale_context_use_count',
        'cost_per_useful_hour',
    ];

    /** Compaction Packet — seven required fields (the doc's table). */
    public const COMPACTION_REQUIRED_FIELDS = [
        'schema_version',
        'source_session_id',
        'summary_hash',
        'preserved_decisions',
        'preserved_invariants',
        'evidence_refs',
        'blocked_reasons',
        'next_action',
    ];

    /** Compaction Packet — closed status set. */
    public const COMPACTION_READY = 'ready';
    public const COMPACTION_STATUSES = [
        'ready',
        'blocked_missing_evidence',
        'blocked_hot_files_ambiguous',
        'blocked_policy_gap',
        'blocked_privacy_gap',
        'blocked_canonical_conflict',
    ];

    /** Cognitive Audit Packet — closed status set. */
    public const AUDIT_READY = 'ready';
    public const AUDIT_WATCH = 'watch';
    public const AUDIT_CRITICAL = 'critical';
    public const AUDIT_STATUSES = ['ready', 'watch', 'critical'];

    /** Cognitive Audit Packet — gain / harm sub-field keys. */
    public const AUDIT_GAIN_KEYS = ['useful_context', 'repeated_work_avoided', 'decision_reuse'];
    public const AUDIT_HARM_KEYS = [
        'wrong_context',
        'stale_context',
        'context_contamination',
        'lost_decision',
        'policy_violation',
    ];

    /** Retrieval Evaluation Packet — the eight required measures. */
    public const RETRIEVAL_REQUIRED_MEASURES = [
        'precision_at_3',
        'precision_at_5',
        'missed_critical_context_count',
        'stale_context_use_count',
        'context_contamination_count',
        'reason_coverage',
        'budget_truncation_count',
        'provider_safe_violation_count',
    ];

    /**
     * Capabilities a packet may *request*. The three mutate capabilities are
     * permanently denied to every packet ("Packets nao podem promover memoria,
     * alterar policy ou aplicar patch sozinhos"). `feed_proposal_only` is the one
     * write-adjacent capability the doc allows, and only to a read-only packet.
     */
    public const CAP_FEED_PROPOSAL_ONLY = 'feed_proposal_only';
    public const CAP_PROMOTE_MEMORY = 'promote_memory';
    public const CAP_ALTER_POLICY = 'alter_policy';
    public const CAP_APPLY_PATCH = 'apply_patch';

    /** Mutate capabilities that no packet may ever exercise. */
    public const FORBIDDEN_CAPABILITIES = [
        self::CAP_PROMOTE_MEMORY,
        self::CAP_ALTER_POLICY,
        self::CAP_APPLY_PATCH,
    ];

    /**
     * "Packet Rules" — enforce the four invariants for one packet.
     *
     * A packet is `provider_safe` by default (absent flag => safe), carries refs/
     * hashes/summaries and NOT raw chat (`carries_raw_chat` must be falsey), and
     * may only feed Self-Improvement proposal-only when it is read-only. The three
     * mutate capabilities (promote memory / alter policy / apply patch) are denied
     * unconditionally.
     *
     * @param array<string,mixed> $packet
     *
     * @return array<string,mixed>
     */
    public function evaluatePacketRules(array $packet): array
    {
        // Default-safe: provider safety is assumed unless explicitly set false.
        $providerSafe = ($packet['provider_safe'] ?? true) !== false;
        $carriesRawChat = ($packet['carries_raw_chat'] ?? false) === true;
        $readOnly = ($packet['read_only'] ?? true) !== false;

        $baselineOk = $providerSafe && ! $carriesRawChat;

        $capabilities = [
            // The one allowed write-adjacent capability: read-only + baseline-ok.
            self::CAP_FEED_PROPOSAL_ONLY => $baselineOk && $readOnly,
            // Permanently forbidden, regardless of any flag on the packet.
            self::CAP_PROMOTE_MEMORY => false,
            self::CAP_ALTER_POLICY => false,
            self::CAP_APPLY_PATCH => false,
        ];

        $violations = [];
        if (! $providerSafe) {
            $violations[] = 'packet_not_provider_safe';
        }
        if ($carriesRawChat) {
            $violations[] = 'packet_carries_raw_chat';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'surface' => 'packet_rules',
            'provider_safe' => $providerSafe,
            'carries_raw_chat' => $carriesRawChat,
            'read_only' => $readOnly,
            'capabilities' => $capabilities,
            'forbidden_capabilities' => self::FORBIDDEN_CAPABILITIES,
            'violations' => $violations,
            'compliant' => $violations === [],
        ];
    }

    /**
     * Predicate: may this packet exercise the named capability?
     */
    public function packetMayPerform(array $packet, string $capability): bool
    {
        $caps = $this->evaluatePacketRules($packet)['capabilities'];

        return ($caps[$capability] ?? false) === true;
    }

    /**
     * Validate a Long Session Snapshot's documented shape.
     *
     * @param array<string,mixed> $snapshot
     *
     * @return array<string,mixed>
     */
    public function validateSnapshot(array $snapshot): array
    {
        $errors = [];

        if (($snapshot['schema_version'] ?? null) !== self::SCHEMA_SNAPSHOT) {
            $errors[] = 'schema_version_mismatch';
        }

        $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : '';
        if (! in_array($status, self::SNAPSHOT_STATUSES, true)) {
            $errors[] = 'status_out_of_enum';
        }

        $phase = is_string($snapshot['current_phase'] ?? null) ? $snapshot['current_phase'] : '';
        if (! in_array($phase, self::SNAPSHOT_PHASES, true)) {
            $errors[] = 'current_phase_out_of_enum';
        }

        $metrics = $snapshot['quality_metrics'] ?? null;
        $missingMetrics = [];
        if (! is_array($metrics)) {
            $errors[] = 'quality_metrics_missing';
            $missingMetrics = self::SNAPSHOT_QUALITY_METRIC_KEYS;
        } else {
            foreach (self::SNAPSHOT_QUALITY_METRIC_KEYS as $key) {
                if (! array_key_exists($key, $metrics)) {
                    $missingMetrics[] = $key;
                }
            }
            if ($missingMetrics !== []) {
                $errors[] = 'quality_metrics_incomplete';
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'surface' => 'long_session_snapshot',
            'valid' => $errors === [],
            'errors' => $errors,
            'missing_quality_metrics' => $missingMetrics,
        ];
    }

    /**
     * Validate a Compaction Packet's documented shape and cross-field rules.
     *
     * Beyond the seven required fields and the closed status set, the doc states:
     *   - `blocked_reasons` is "Non-empty when continuity is unsafe" -> for any
     *     `blocked_*` status, blocked_reasons MUST be non-empty;
     *   - `next_action` is the "Concrete next step if status is ready" -> for the
     *     `ready` status, next_action MUST be present.
     *
     * @param array<string,mixed> $packet
     *
     * @return array<string,mixed>
     */
    public function validateCompactionPacket(array $packet): array
    {
        $errors = [];

        if (($packet['schema_version'] ?? null) !== self::SCHEMA_COMPACTION) {
            $errors[] = 'schema_version_mismatch';
        }

        $missing = [];
        foreach (self::COMPACTION_REQUIRED_FIELDS as $field) {
            if ($field === 'blocked_reasons' || $field === 'next_action') {
                // Conditional fields validated by the cross-field rules below;
                // they only need their key, not a value, to be structurally present.
                if (! array_key_exists($field, $packet)) {
                    $missing[] = $field;
                }

                continue;
            }
            if (! $this->present($packet[$field] ?? null)) {
                $missing[] = $field;
            }
        }
        if ($missing !== []) {
            $errors[] = 'required_fields_missing';
        }

        $status = is_string($packet['status'] ?? null) ? $packet['status'] : '';
        $statusKnown = in_array($status, self::COMPACTION_STATUSES, true);
        if (! $statusKnown) {
            $errors[] = 'status_out_of_enum';
        }

        $isBlocked = str_starts_with($status, 'blocked_');
        $blockedReasons = $this->stringList($packet['blocked_reasons'] ?? []);
        if ($statusKnown && $isBlocked && $blockedReasons === []) {
            $errors[] = 'blocked_status_requires_blocked_reasons';
        }

        if ($statusKnown && $status === self::COMPACTION_READY && ! $this->present($packet['next_action'] ?? null)) {
            $errors[] = 'ready_status_requires_next_action';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'surface' => 'compaction_packet',
            'status' => $statusKnown ? $status : null,
            'is_blocked' => $statusKnown ? $isBlocked : null,
            'valid' => $errors === [],
            'errors' => $errors,
            'missing_fields' => $missing,
        ];
    }

    /**
     * Validate a Cognitive Audit Packet's documented shape, including the
     * net_value = sum(gain) - sum(harm) relation.
     *
     * @param array<string,mixed> $packet
     *
     * @return array<string,mixed>
     */
    public function validateAuditPacket(array $packet): array
    {
        $errors = [];

        if (($packet['schema_version'] ?? null) !== self::SCHEMA_AUDIT) {
            $errors[] = 'schema_version_mismatch';
        }

        $status = is_string($packet['status'] ?? null) ? $packet['status'] : '';
        if (! in_array($status, self::AUDIT_STATUSES, true)) {
            $errors[] = 'status_out_of_enum';
        }

        $gainSum = $this->blockSum($packet['gain'] ?? null, self::AUDIT_GAIN_KEYS, $gainMissing);
        $harmSum = $this->blockSum($packet['harm'] ?? null, self::AUDIT_HARM_KEYS, $harmMissing);

        if ($gainMissing !== []) {
            $errors[] = 'gain_block_incomplete';
        }
        if ($harmMissing !== []) {
            $errors[] = 'harm_block_incomplete';
        }

        $expectedNet = $gainSum - $harmSum;
        $declaredNet = $this->number($packet['net_value'] ?? null);
        $netConsistent = $gainMissing === [] && $harmMissing === []
            && abs($declaredNet - (float) $expectedNet) < 0.0001;
        if (! $netConsistent && $gainMissing === [] && $harmMissing === []) {
            $errors[] = 'net_value_inconsistent';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'surface' => 'audit_packet',
            'valid' => $errors === [],
            'errors' => $errors,
            'gain_sum' => $gainSum,
            'harm_sum' => $harmSum,
            'expected_net_value' => $expectedNet,
            'declared_net_value' => $declaredNet,
            'net_value_consistent' => $netConsistent,
        ];
    }

    /**
     * Validate a Retrieval Evaluation Packet: all eight measures must be present.
     *
     * @param array<string,mixed> $packet
     *
     * @return array<string,mixed>
     */
    public function validateRetrievalEvaluation(array $packet): array
    {
        $missing = [];
        foreach (self::RETRIEVAL_REQUIRED_MEASURES as $measure) {
            if (! array_key_exists($measure, $packet)) {
                $missing[] = $measure;
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'surface' => 'retrieval_evaluation_packet',
            'valid' => $missing === [],
            'missing_measures' => $missing,
            'measures_present' => count(self::RETRIEVAL_REQUIRED_MEASURES) - count($missing),
            'measures_total' => count(self::RETRIEVAL_REQUIRED_MEASURES),
        ];
    }

    /**
     * "Promotion Rule" — a 72h session may become maturity evidence ONLY when
     * snapshot, compaction packet and audit packet are each `ready`, or `watch`
     * with risks explicitly accepted by a human operator.
     *
     * This gate never blocks the human conversation (the doc: "Qualquer packet
     * incompleto bloqueia promocao de maturidade, nao necessariamente a conversa
     * humana") — it only reports whether maturity promotion is permitted.
     *
     * @param array<string,mixed> $session  Expected keys: snapshot_status,
     *     compaction_status, audit_status (strings), and risks_accepted_by_operator
     *     (bool) covering the watch case.
     *
     * @return array<string,mixed>
     */
    public function evaluatePromotion(array $session): array
    {
        $risksAccepted = ($session['risks_accepted_by_operator'] ?? false) === true;

        $gates = [
            'snapshot' => $this->promotionGate($session['snapshot_status'] ?? null, $risksAccepted),
            'compaction' => $this->promotionGate($session['compaction_status'] ?? null, $risksAccepted),
            'audit' => $this->promotionGate($session['audit_status'] ?? null, $risksAccepted),
        ];

        $unmet = [];
        foreach ($gates as $name => $gate) {
            if (! $gate['ok']) {
                $unmet[] = $name;
            }
        }

        $mayPromote = $unmet === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'surface' => 'promotion',
            'risks_accepted_by_operator' => $risksAccepted,
            'may_promote_to_maturity_evidence' => $mayPromote,
            // The doc is explicit: promotion gating never blocks the conversation.
            'blocks_human_conversation' => false,
            'gates' => $gates,
            'unmet_gates' => $unmet,
            'reason' => $mayPromote
                ? 'all_packets_ready_or_watch_with_accepted_risks'
                : 'one_or_more_packets_not_promotable',
        ];
    }

    /** Convenience predicate over evaluatePromotion(). */
    public function mayPromoteToMaturity(array $session): bool
    {
        return $this->evaluatePromotion($session)['may_promote_to_maturity_evidence'] === true;
    }

    /**
     * One packet's promotion-readiness: `ready` always passes; `watch` passes
     * only when the operator accepted the risks; anything else fails.
     *
     * @return array{ok:bool,status:string,detail:string}
     */
    private function promotionGate(mixed $rawStatus, bool $risksAccepted): array
    {
        $status = is_string($rawStatus) ? strtolower(trim($rawStatus)) : '';

        if ($status === 'ready') {
            return ['ok' => true, 'status' => $status, 'detail' => 'ready'];
        }
        if ($status === 'watch') {
            return [
                'ok' => $risksAccepted,
                'status' => $status,
                'detail' => $risksAccepted ? 'watch_risks_accepted' : 'watch_risks_not_accepted',
            ];
        }

        return [
            'ok' => false,
            'status' => $status === '' ? 'unknown' : $status,
            'detail' => 'not_ready_or_watch',
        ];
    }

    /**
     * Sum a fixed-key numeric block; records missing keys via the out-param.
     *
     * @param mixed $raw
     * @param list<string> $keys
     * @param list<string>|null $missing
     */
    private function blockSum(mixed $raw, array $keys, ?array &$missing): float
    {
        $missing = [];
        if (! is_array($raw)) {
            $missing = $keys;

            return 0.0;
        }

        $sum = 0.0;
        foreach ($keys as $key) {
            if (! array_key_exists($key, $raw)) {
                $missing[] = $key;

                continue;
            }
            $sum += $this->number($raw[$key]);
        }

        return $sum;
    }

    /** Coerce a value to a float; non-numerics become 0.0. */
    private function number(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /** A value is "present" when it is not null, not an empty string, not []. */
    private function present(mixed $value): bool
    {
        if ($value === null || $value === []) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }

        return true;
    }

    /**
     * Normalize a list to non-empty strings.
     *
     * @param mixed $value
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
