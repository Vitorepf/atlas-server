<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Evidence Ledger admission + append-only + retention contract.
 *
 * Pure, deterministic implementation of the kernel `evidence-ledger` step.
 * The ledger is "o registro factual do que o Atlas decidiu, executou, testou,
 * reparou e aprendeu. Ele deve ser append-only e auditavel." This service is
 * the gate that decides, for one candidate event, whether it may enter the
 * ledger as factual evidence, must be reclassified as interpretation, or must
 * be rejected — and separately enforces the append-only and retention rules.
 *
 * It NEVER writes a row, calls a store, mutates history or touches the
 * database. It answers the contract question and returns a typed verdict;
 * callers either persist the event or stop.
 *
 * Contract (mapped to the doc sections):
 *
 *   "Contratos" — Entrada: eventos de decisao, execucao, gate, custo, latencia,
 *     erro e repair. So these are the canonical event kinds; an event with an
 *     unknown kind has no schema and is rejected (Risco: "Ledger virar log solto
 *     sem schema").
 *
 *   "Regras para IA" — "IA deve diferenciar evidencia factual de interpretacao.
 *     Comentario de agente nao substitui evento, teste, path, run ou receipt."
 *     An event whose payload carries no factual anchor (command / test / path /
 *     run / receipt / status / measurement) is NOT factual evidence: it is
 *     reclassified as `interpretation` and refused admission as evidence.
 *
 *   "Exemplos" — "Um gate de teste deve registrar comando, status, duracao,
 *     saida relevante e trace ligado ao receipt." A `gate` event must carry
 *     command, status, duration, output, trace AND receipt, or it is admitted-
 *     incomplete and rejected with the missing fields named.
 *
 *   "Escopo de Implementacao" — "Permitido: eventos append-only ... Proibido:
 *     reescrever historico sem politica." A write that updates or deletes an
 *     already-recorded event stops, UNLESS it is a new append OR an explicit
 *     retention/correction policy authorizes the rewrite.
 *
 *   "Riscos" — "Retention destruir informacao necessaria." A retention prune of
 *     a record under legal/audit hold must stop, regardless of age.
 *
 * @see docs/engineering-knowledge-base/system-graph/evidence-ledger.md
 */
final class AtlasEvidenceLedgerContractService
{
    /** Stable schema id for the admission decision this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.kernel.evidence_ledger_contract.v1';

    /** Canonical admission verdicts (closed set). */
    public const VERDICT_ADMIT = 'admit';
    public const VERDICT_INTERPRETATION = 'interpretation';
    public const VERDICT_REJECT = 'reject';

    /** Canonical write verdicts (closed set). */
    public const WRITE_APPEND = 'append';
    public const WRITE_STOP = 'stop';

    /**
     * Canonical event kinds from "Contratos". An event whose kind is not in this
     * set has no schema and cannot enter the ledger.
     *
     * @var list<string>
     */
    private const CANONICAL_KINDS = [
        'decision',
        'execution',
        'gate',
        'cost',
        'latency',
        'error',
        'repair',
    ];

    /**
     * Factual anchors named in "Regras para IA": an event is only factual
     * evidence if at least one of these keys is present with a non-empty value.
     * Agent commentary alone ("comentario de agente") never qualifies.
     *
     * @var list<string>
     */
    private const FACTUAL_ANCHORS = [
        'command',
        'test',
        'path',
        'run',
        'receipt',
        'status',
        'measurement',
    ];

    /**
     * Required fields per kind, from the doc.
     *
     * "Exemplos" pins the `gate` shape exactly: command, status, duration,
     * output, trace + receipt. The other kinds require, at minimum, the trace
     * and receipt that tie the factual event back to its decision contract
     * (the ledger "fecha o ciclo de confianca").
     *
     * @var array<string,list<string>>
     */
    private const REQUIRED_BY_KIND = [
        'gate' => ['command', 'status', 'duration', 'output', 'trace', 'receipt'],
        'execution' => ['status', 'trace', 'receipt'],
        'decision' => ['trace', 'receipt'],
        'repair' => ['status', 'trace', 'receipt'],
        'error' => ['status', 'trace'],
        'cost' => ['measurement', 'trace'],
        'latency' => ['measurement', 'trace'],
    ];

    /**
     * Decide whether one candidate event may enter the ledger as factual
     * evidence.
     *
     * @param  array<string,mixed>|null  $event
     *         null / [] means "no event" — rejected.
     *         kind  : one of CANONICAL_KINDS.
     *         Plus the factual fields the kind requires (see REQUIRED_BY_KIND).
     *
     * @return array<string,mixed> the verdict + audit receipt
     */
    public function admit(?array $event): array
    {
        // Rule 0 — nothing to record.
        if ($event === null || $event === []) {
            return $this->admitVerdict(self::VERDICT_REJECT, 'empty_event', null, []);
        }

        $kind = $this->stringOrNull($event['kind'] ?? null);

        // Rule 1 — schema/kind: an event without a canonical kind is "log solto
        // sem schema" and is rejected.
        if ($kind === null) {
            return $this->admitVerdict(self::VERDICT_REJECT, 'missing_kind', null, [
                'allowed_kinds' => self::CANONICAL_KINDS,
            ]);
        }
        if (! in_array($kind, self::CANONICAL_KINDS, true)) {
            return $this->admitVerdict(self::VERDICT_REJECT, 'unknown_kind', $kind, [
                'allowed_kinds' => self::CANONICAL_KINDS,
            ]);
        }

        // Rule 2 — factual vs interpretation: a payload with no factual anchor is
        // agent commentary, not evidence. Reclassify as interpretation; it does
        // NOT enter the ledger as factual evidence.
        if (! $this->hasFactualAnchor($event)) {
            return $this->admitVerdict(self::VERDICT_INTERPRETATION, 'no_factual_anchor', $kind, [
                'factual_anchors' => self::FACTUAL_ANCHORS,
            ]);
        }

        // Rule 3 — completeness: the kind's required fields must all be present
        // and non-empty, or the factual event is incomplete and rejected.
        $missing = $this->missingFields($kind, $event);
        if ($missing !== []) {
            return $this->admitVerdict(self::VERDICT_REJECT, 'incomplete_event', $kind, [
                'missing_fields' => $missing,
            ]);
        }

        // Factual, well-formed, complete: admit as evidence.
        return $this->admitVerdict(self::VERDICT_ADMIT, 'factual_evidence', $kind, [
            'required_fields' => self::REQUIRED_BY_KIND[$kind] ?? [],
        ]);
    }

    /**
     * Enforce the append-only rule for a write against the ledger.
     *
     * "Permitido: eventos append-only ... Proibido: reescrever historico sem
     * politica." A pure append is always allowed. An update or delete that
     * targets an already-recorded event stops, UNLESS an explicit retention /
     * correction policy authorizes the rewrite.
     *
     * @param  string  $mode  one of: append | update | delete.
     * @param  array<string,mixed>  $context
     *         targets_existing : bool — does this write mutate a recorded event?
     *         policy           : ?string — explicit authorizing policy id, if any.
     *
     * @return array<string,mixed> the write verdict + audit receipt
     */
    public function assertWritePolicy(string $mode, array $context = []): array
    {
        $normalizedMode = strtolower(trim($mode));

        // Append never rewrites history: always allowed.
        if ($normalizedMode === 'append') {
            return $this->writeVerdict(self::WRITE_APPEND, 'append_only', $normalizedMode, []);
        }

        if ($normalizedMode !== 'update' && $normalizedMode !== 'delete') {
            return $this->writeVerdict(self::WRITE_STOP, 'unknown_write_mode', $normalizedMode, [
                'allowed_modes' => ['append', 'update', 'delete'],
            ]);
        }

        $targetsExisting = (bool) ($context['targets_existing'] ?? true);
        $policy = $this->stringOrNull($context['policy'] ?? null);

        // A mutation that does not touch recorded history is effectively a new
        // write; treat it as an append.
        if (! $targetsExisting) {
            return $this->writeVerdict(self::WRITE_APPEND, 'no_existing_record', $normalizedMode, []);
        }

        // Rewriting recorded history requires an explicit policy. Without one it
        // is forbidden ("reescrever historico sem politica").
        if ($policy === null) {
            return $this->writeVerdict(self::WRITE_STOP, 'rewrite_without_policy', $normalizedMode, [
                'targets_existing' => true,
            ]);
        }

        return $this->writeVerdict(self::WRITE_APPEND, 'rewrite_authorized_by_policy', $normalizedMode, [
            'policy' => $policy,
        ]);
    }

    /**
     * Decide whether a retention prune of one record may proceed.
     *
     * Risco: "Retention destruir informacao necessaria." A record under an
     * active legal/audit hold must never be pruned, regardless of age. A record
     * not under hold may be pruned only once its age meets the retention floor.
     *
     * @param  array<string,mixed>  $record
     *         age_days  : int|float — how old the record is.
     *         legal_hold / audit_hold : bool — explicit retention holds.
     * @param  int  $retentionFloorDays  minimum age before a non-held record may be pruned.
     *
     * @return array<string,mixed> the prune verdict + audit receipt
     */
    public function retentionDecision(array $record, int $retentionFloorDays): array
    {
        $legalHold = (bool) ($record['legal_hold'] ?? false);
        $auditHold = (bool) ($record['audit_hold'] ?? false);

        if ($legalHold || $auditHold) {
            return $this->writeVerdict(self::WRITE_STOP, 'under_hold', 'prune', [
                'legal_hold' => $legalHold,
                'audit_hold' => $auditHold,
            ]);
        }

        $ageDays = $this->floatOrNull($record['age_days'] ?? null) ?? 0.0;
        if ($ageDays < (float) $retentionFloorDays) {
            return $this->writeVerdict(self::WRITE_STOP, 'within_retention_floor', 'prune', [
                'age_days' => $ageDays,
                'retention_floor_days' => $retentionFloorDays,
            ]);
        }

        return $this->writeVerdict(self::WRITE_APPEND, 'prune_authorized', 'prune', [
            'age_days' => $ageDays,
            'retention_floor_days' => $retentionFloorDays,
        ]);
    }

    /**
     * Bulk admission: of a batch of candidate events, which ones do NOT enter the
     * ledger as factual evidence? Returns the interpretation/reject decisions in
     * input order so a caller can quarantine non-evidence in one pass.
     *
     * @param  array<int,array<string,mixed>>  $events
     *
     * @return array<int,array{index:int,verdict:string,reason:string,kind:?string}>
     */
    public function rejected(array $events): array
    {
        $out = [];
        foreach (array_values($events) as $index => $event) {
            if (! is_array($event)) {
                $out[] = ['index' => $index, 'verdict' => self::VERDICT_REJECT, 'reason' => 'empty_event', 'kind' => null];

                continue;
            }
            $decision = $this->admit($event);
            if ($decision['verdict'] !== self::VERDICT_ADMIT) {
                $out[] = [
                    'index' => $index,
                    'verdict' => $decision['verdict'],
                    'reason' => $decision['reason'],
                    'kind' => $decision['kind'],
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function hasFactualAnchor(array $event): bool
    {
        foreach (self::FACTUAL_ANCHORS as $anchor) {
            if (array_key_exists($anchor, $event) && ! $this->isEmptyValue($event[$anchor])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $event
     *
     * @return list<string>
     */
    private function missingFields(string $kind, array $event): array
    {
        $required = self::REQUIRED_BY_KIND[$kind] ?? [];
        $missing = [];
        foreach ($required as $field) {
            if (! array_key_exists($field, $event) || $this->isEmptyValue($event[$field])) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $detail
     *
     * @return array<string,mixed>
     */
    private function admitVerdict(string $verdict, string $reason, ?string $kind, array $detail): array
    {
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'admitted' => $verdict === self::VERDICT_ADMIT,
            'reason' => $reason,
            'kind' => $kind,
            'detail' => $detail,
        ];
    }

    /**
     * @param  array<string,mixed>  $detail
     *
     * @return array<string,mixed>
     */
    private function writeVerdict(string $verdict, string $reason, string $mode, array $detail): array
    {
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'allowed' => $verdict === self::WRITE_APPEND,
            'reason' => $reason,
            'mode' => $mode,
            'detail' => $detail,
        ];
    }
}
