<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Operation Envelope admission decider.
 *
 * Pure, deterministic runtime for the invariant the Operation Envelope doc
 * declares: an operation is only traceable — and therefore only allowed to
 * advance — once it carries a complete envelope. The doc states the rules this
 * service enforces:
 *
 *   1. Contratos / Invariante: "sem envelope nao ha execucao". The envelope
 *      must carry trace, source/origin, operational identity, input,
 *      limits and attachments. `admit()` checks a candidate context against
 *      that required set and returns the missing/hard blockers.
 *
 *   2. Regras para IA: "IA deve exigir envelope antes de plano ou execucao.
 *      Falta de trace e bloqueio, nao detalhe opcional." So a missing trace is
 *      a HARD blocker (not a soft warning), and `guardPhase()` refuses to let an
 *      un-admitted operation reach `plan` or `execute`.
 *
 *   3. Fluxo: input -> envelope -> intent-routing. An admitted envelope unlocks
 *      exactly the next governed step (`intent-routing`); it never jumps straight
 *      to plan/execution.
 *
 *   4. Escopo de Implementacao: "Proibido: esconder dados fora do envelope."
 *      A candidate that declares data carried outside the envelope is blocked.
 *
 * The service NEVER executes an operation, routes, builds a plan, calls a
 * provider or touches a database. It only decides whether a candidate operation
 * is envelope-complete and what its single next step may be. Callers enforce.
 *
 * @see docs/engineering-knowledge-base/system-graph/operation-envelope.md
 */
final class AtlasOperationEnvelopeService
{
    /** Stable schema id for the verdict this decider emits. */
    public const SCHEMA = 'atlas.operation_envelope.admission.v1';

    /** The canonical Operation Envelope contract id (kernel/contracts anti-duplication note). */
    public const ENVELOPE_CONTRACT = 'atlas.envelope.v1';

    /** The single governed step an admitted envelope unlocks (doc: flows_to). */
    public const NEXT_STEP = 'intent-routing';

    /**
     * Envelope fields that MUST be present for an operation to be traceable.
     * Drawn from Contratos ("trace, source, limites, anexos e identidade
     * operacional") plus the canonical input the envelope wraps.
     *
     * @var list<string>
     */
    private const REQUIRED_FIELDS = [
        'trace',
        'origin',
        'identity',
        'input',
        'limits',
        'attachments',
    ];

    /**
     * Hard blockers: a missing one of these means the operation CANNOT execute,
     * not merely that the envelope is incomplete. Trace is hard per Regras para
     * IA; origin/identity/input are hard per Contratos + forbidden_changes
     * ("Executar operacao sem trace e origem").
     *
     * @var list<string>
     */
    private const HARD_FIELDS = [
        'trace',
        'origin',
        'identity',
        'input',
    ];

    /**
     * Phases a caller may request. Only `route` is reachable directly from a
     * fresh envelope; `plan` and `execute` require an admitted envelope first
     * (Regras para IA: envelope antes de plano ou execucao).
     *
     * @var list<string>
     */
    private const GUARDED_PHASES = ['route', 'plan', 'execute'];

    /**
     * Decide whether a candidate operation context carries a complete,
     * traceable envelope and may advance.
     *
     * @param  array<string,mixed>  $candidate  the candidate envelope/operation context
     * @return array{
     *     schema:string,
     *     envelope_contract:string,
     *     envelope_present:bool,
     *     admitted:bool,
     *     blocked:bool,
     *     present:list<string>,
     *     missing:list<string>,
     *     hard_blockers:list<string>,
     *     soft_missing:list<string>,
     *     reasons:list<string>,
     *     next_step:?string
     * }
     */
    public function admit(array $candidate): array
    {
        $reasons = [];

        // Invariante: sem envelope nao ha execucao. An empty/absent context is
        // the strongest form of "no envelope".
        $envelopePresent = $candidate !== [] && $this->anyRequiredPresent($candidate);
        if (! $envelopePresent) {
            $reasons[] = 'envelope_missing';
        }

        // Hard fields demand a real, non-empty value. Soft fields (limits,
        // attachments) only need to be DECLARED in the envelope: an explicit
        // empty list ("no attachments") is a complete, valid state — Escopo
        // forbids hiding data, not declaring an empty set.
        $present = [];
        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            $ok = in_array($field, self::HARD_FIELDS, true)
                ? $this->hasValue($candidate, $field)
                : $this->isDeclared($candidate, $field);
            if ($ok) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $hardBlockers = [];
        $softMissing = [];
        foreach ($missing as $field) {
            if (in_array($field, self::HARD_FIELDS, true)) {
                $hardBlockers[] = $field;
                $reasons[] = $field === 'trace' ? 'trace_missing' : 'missing_required_field:' . $field;
            } else {
                $softMissing[] = $field;
                $reasons[] = 'incomplete_envelope_field:' . $field;
            }
        }

        // Escopo proibido: esconder dados fora do envelope.
        if ($this->declaresExternalData($candidate)) {
            $hardBlockers[] = 'data_outside_envelope';
            $reasons[] = 'data_hidden_outside_envelope';
        }

        $admitted = $envelopePresent && $hardBlockers === [] && $missing === [];
        $blocked = $hardBlockers !== [] || ! $envelopePresent;

        return [
            'schema' => self::SCHEMA,
            'envelope_contract' => self::ENVELOPE_CONTRACT,
            'envelope_present' => $envelopePresent,
            'admitted' => $admitted,
            'blocked' => $blocked,
            'present' => $present,
            'missing' => $missing,
            'hard_blockers' => array_values(array_unique($hardBlockers)),
            'soft_missing' => $softMissing,
            'reasons' => array_values(array_unique($reasons)),
            'next_step' => $admitted ? self::NEXT_STEP : null,
        ];
    }

    /**
     * Guard a requested phase against the envelope invariant.
     *
     * Regras para IA: IA deve exigir envelope antes de plano ou execucao. So:
     *   - `route`  is allowed once the envelope is admitted (the only governed
     *              step the envelope unlocks).
     *   - `plan` / `execute` are HARD-blocked whenever the envelope is not
     *              admitted; a missing trace alone is enough to block.
     *
     * @param  array<string,mixed>  $candidate  the candidate envelope/operation context
     * @param  string  $phase  one of route|plan|execute
     * @return array{
     *     schema:string,
     *     phase:string,
     *     known_phase:bool,
     *     admitted:bool,
     *     allowed:bool,
     *     blocked:bool,
     *     reasons:list<string>,
     *     admission:array<string,mixed>
     * }
     */
    public function guardPhase(array $candidate, string $phase): array
    {
        $phase = strtolower(trim($phase));
        $knownPhase = in_array($phase, self::GUARDED_PHASES, true);
        $admission = $this->admit($candidate);
        $admitted = $admission['admitted'];

        $reasons = [];
        if (! $knownPhase) {
            $reasons[] = 'unknown_phase';
        }

        // No phase may proceed without an admitted envelope. plan/execute are the
        // phases the doc names explicitly; route also needs the envelope first.
        if (! $admitted) {
            $reasons[] = 'envelope_not_admitted';
            if (in_array($phase, ['plan', 'execute'], true)) {
                $reasons[] = 'phase_requires_envelope_before_' . $phase;
            }
            foreach ($admission['reasons'] as $r) {
                $reasons[] = $r;
            }
        }

        $allowed = $knownPhase && $admitted;

        return [
            'schema' => self::SCHEMA,
            'phase' => $phase,
            'known_phase' => $knownPhase,
            'admitted' => $admitted,
            'allowed' => $allowed,
            'blocked' => ! $allowed,
            'reasons' => array_values(array_unique($reasons)),
            'admission' => $admission,
        ];
    }

    /**
     * The required envelope contract, classified by hardness.
     *
     * @return array{
     *     schema:string,
     *     envelope_contract:string,
     *     next_step:string,
     *     required_fields:list<string>,
     *     hard_fields:list<string>,
     *     soft_fields:list<string>,
     *     guarded_phases:list<string>
     * }
     */
    public function manifest(): array
    {
        $soft = array_values(array_diff(self::REQUIRED_FIELDS, self::HARD_FIELDS));

        return [
            'schema' => self::SCHEMA,
            'envelope_contract' => self::ENVELOPE_CONTRACT,
            'next_step' => self::NEXT_STEP,
            'required_fields' => self::REQUIRED_FIELDS,
            'hard_fields' => self::HARD_FIELDS,
            'soft_fields' => $soft,
            'guarded_phases' => self::GUARDED_PHASES,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function anyRequiredPresent(array $candidate): bool
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if ($this->hasValue($candidate, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Escopo proibido detector: a candidate that flags data carried outside the
     * envelope (e.g. external_data / data_outside_envelope / hidden_context).
     *
     * @param  array<string,mixed>  $candidate
     */
    private function declaresExternalData(array $candidate): bool
    {
        foreach (['data_outside_envelope', 'external_data', 'hidden_context'] as $flag) {
            $value = $candidate[$flag] ?? null;
            if ($value === true) {
                return true;
            }
            if (is_array($value) && $value !== []) {
                return true;
            }
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Soft-field presence: the key is declared and not null. An explicit empty
     * array (e.g. "no attachments", "no extra limits") counts as declared.
     *
     * @param  array<string,mixed>  $payload
     */
    private function isDeclared(array $payload, string $field): bool
    {
        if (! array_key_exists($field, $payload)) {
            return false;
        }
        $value = $payload[$field];
        if ($value === null) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasValue(array $payload, string $field): bool
    {
        if (! array_key_exists($field, $payload)) {
            return false;
        }
        $value = $payload[$field];
        if ($value === null) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }
        if (is_array($value) && $value === []) {
            return false;
        }

        return true;
    }
}
