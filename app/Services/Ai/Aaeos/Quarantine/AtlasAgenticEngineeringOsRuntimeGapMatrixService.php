<?php

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Atlas Agentic Engineering OS Runtime Gap Matrix doc.
 *
 * The doc is a SHORT canonical matrix that crosses AAEOS docs against real code
 * and classifies each area's operational state. Its load-bearing contract is the
 * closed set of four runtime states (the "Contratos" table) plus the "Regras
 * para IA" / "Fluxo" sections:
 *
 *   - `solid_runtime`   : code + test + command/route OR receipt prove use.
 *   - `partial_runtime` : code exists, but the productive path / evidence /
 *                          integration still fails.
 *   - `spec_runtime_gap`: strong doc, runtime absent or not connected.
 *   - `drift_risk`      : code exists, but name/status/doc misleads the AI.
 *
 * Enforced documented rules (pure, deterministic, no DB):
 *   1. A row may ONLY be claimed "ready" when its state is `solid_runtime` AND
 *      evidence is cited. A gap can never be promoted to ready without evidence
 *      ("Promover gap a pronto sem evidence" is a forbidden_change; "require
 *      evidence before claim" is the last step of the Fluxo).
 *   2. DOC L4 alone never makes an area ready ("Nao declare area como pronta so
 *      porque aparece como DOC L4").
 *   3. The high-impact backlog the loop should pick from is ONLY rows whose
 *      state is `partial_runtime` or `spec_runtime_gap` AND that are in scope.
 *      Out-of-scope buckets (`measurement_only`, `north_star`) are never backlog, and
 *      decorative micro-tasks are excluded.
 *   4. Using a provider directly without a Dev/Forge owner runtime must be
 *      declared as a bypass, not as complete Forge/Dev execution.
 *
 * Evidence accepted (the "Evidencias" section): service path, command path,
 * route path, focused test, AP receipt, ledger, AP-790 merged cycle, or a
 * machine-readable blocker.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md
 */
final class AtlasAgenticEngineeringOsRuntimeGapMatrixService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.runtime_gap_matrix.v1';

    public const MODE = 'canonical_runtime_gap_classifier';

    /** The four runtime states from the "Contratos" table. */
    public const STATE_SOLID = 'solid_runtime';

    public const STATE_PARTIAL = 'partial_runtime';

    public const STATE_SPEC_GAP = 'spec_runtime_gap';

    public const STATE_DRIFT = 'drift_risk';

    /**
     * Out-of-scope buckets the doc lists in the snapshot that are explicitly NOT
     * runtime and must never be treated as backlog or as "Forge complete" proof.
     */
    public const STATE_OUT_OF_SCOPE_MEASUREMENT = 'measurement_only';

    public const STATE_OUT_OF_SCOPE_NORTH_STAR = 'north_star';

    /** The only state that can be claimed ready (and only WITH evidence). */
    public const READY_STATE = self::STATE_SOLID;

    /** States the Fluxo says the loop should pick high-impact gaps from. */
    public const BACKLOG_STATES = [self::STATE_PARTIAL, self::STATE_SPEC_GAP];

    /** Closed set of every state the matrix recognises. */
    public const KNOWN_STATES = [
        self::STATE_SOLID,
        self::STATE_PARTIAL,
        self::STATE_SPEC_GAP,
        self::STATE_DRIFT,
        self::STATE_OUT_OF_SCOPE_MEASUREMENT,
        self::STATE_OUT_OF_SCOPE_NORTH_STAR,
    ];

    /**
     * The evidence kinds the "Evidencias" section accepts as proof of runtime.
     * Anything outside this set does not satisfy an evidence requirement.
     */
    public const ACCEPTED_EVIDENCE_KINDS = [
        'service_path',
        'command_path',
        'route_path',
        'focused_test',
        'ap_receipt',
        'ledger',
        'ap790_merged_cycle',
        'machine_readable_blocker',
    ];

    /**
     * The canonical snapshot rows, copied from the doc's "Snapshot Runtime"
     * table. `area` is the human label; `state` is the OBSERVED classification;
     * `caveat` is the evidence/caveat note. `in_scope` is false for the two
     * out-of-scope buckets the doc parks at the bottom of the matrix.
     *
     * @var array<int, array{area:string, state:string, in_scope:bool, caveat:string}>
     */
    private const SNAPSHOT = [
        ['area' => 'AAEOS skeleton', 'state' => self::STATE_SOLID, 'in_scope' => true, 'caveat' => 'Services em app/Services/Ai/AgenticEngineeringOs; CLI e tests existem.'],
        ['area' => 'Runbook 17 fases', 'state' => self::STATE_PARTIAL, 'in_scope' => true, 'caveat' => 'Envelope existe; algumas fases ainda dependem de integracoes reais.'],
        ['area' => 'HTTP path AAEOS', 'state' => self::STATE_PARTIAL, 'in_scope' => true, 'caveat' => 'Facade/fases existem; path produtivo ainda deve provar wiring completo.'],
        ['area' => 'Atlas Dev', 'state' => self::STATE_PARTIAL, 'in_scope' => true, 'caveat' => 'Fluxo rico e DTOs reais; matriz ainda marca Dev baixo por A2/HTTP/parity.'],
        ['area' => 'Atlas Forge', 'state' => self::STATE_PARTIAL, 'in_scope' => true, 'caveat' => 'Runtime/provider/governance fortes; precisa usar owner runtime real, nao prompt simples.'],
        ['area' => 'Dual-Core Dev/Forge', 'state' => self::STATE_PARTIAL, 'in_scope' => true, 'caveat' => 'Route decision existe; ha mecanismos paralelos de promocao a consolidar.'],
        ['area' => 'Universal gates', 'state' => self::STATE_PARTIAL, 'in_scope' => true, 'caveat' => 'Evaluator existe, mas gates continuam parcialmente dispersos.'],
        ['area' => 'Mission Control', 'state' => self::STATE_PARTIAL, 'in_scope' => true, 'caveat' => 'Service/surface parcial; review humano nao pode ser claim completo.'],
        ['area' => 'Evidence Dev/Forge', 'state' => self::STATE_PARTIAL, 'in_scope' => true, 'caveat' => 'Receipts existem; cross-reference ainda precisa caminho unico robusto.'],
        ['area' => 'Stewardship 24h', 'state' => self::STATE_PARTIAL, 'in_scope' => true, 'caveat' => 'AP-790 prova merges reais; precisa backlog forte, failover e recovery.'],
        ['area' => 'Measurement/Comparison (out of scope)', 'state' => self::STATE_OUT_OF_SCOPE_MEASUREMENT, 'in_scope' => false, 'caveat' => 'Nao e arquitetura nem prova de Forge completo.'],
        ['area' => 'TEOS/extreme tiers', 'state' => self::STATE_OUT_OF_SCOPE_NORTH_STAR, 'in_scope' => false, 'caveat' => 'Nao entra como runtime atual.'],
    ];

    /**
     * Classify a single area's runtime state and decide whether it may be
     * claimed "ready". The documented rule: ready REQUIRES `solid_runtime` AND
     * cited evidence; a gap can never be promoted to ready without evidence, and
     * DOC L4 alone is never enough.
     *
     * @param  array<int,array<string,mixed>>  $evidence  list of {kind: <string>, ref: <string>} items
     * @return array{
     *   area:string,
     *   state:string,
     *   in_scope:bool,
     *   ready_claim_allowed:bool,
     *   is_backlog_candidate:bool,
     *   accepted_evidence:array<int,string>,
     *   rejected_evidence:array<int,string>,
     *   blocked_reason:string
     * }
     */
    public function classifyArea(string $area, string $state, array $evidence = []): array
    {
        $state = $this->normalizeState($state);
        $inScope = ! in_array($state, [self::STATE_OUT_OF_SCOPE_MEASUREMENT, self::STATE_OUT_OF_SCOPE_NORTH_STAR], true);

        $accepted = [];
        $rejected = [];
        foreach ($evidence as $item) {
            $kind = is_array($item) ? (string) ($item['kind'] ?? '') : (string) $item;
            if ($kind !== '' && in_array($kind, self::ACCEPTED_EVIDENCE_KINDS, true)) {
                $accepted[] = $kind;
            } elseif ($kind !== '') {
                $rejected[] = $kind;
            }
        }
        $accepted = array_values(array_unique($accepted));
        $rejected = array_values(array_unique($rejected));

        $hasEvidence = $accepted !== [];

        // Ready is allowed ONLY for solid_runtime WITH accepted evidence.
        $readyAllowed = $state === self::READY_STATE && $hasEvidence;

        $blocked = '';
        if (! $readyAllowed) {
            if ($state !== self::READY_STATE) {
                $blocked = "state '{$state}' is not solid_runtime; gap cannot be claimed ready without runtime evidence";
            } else {
                $blocked = 'solid_runtime requires at least one accepted evidence kind before a ready claim';
            }
        }

        $isBacklog = $inScope && in_array($state, self::BACKLOG_STATES, true);

        return [
            'area' => $area,
            'state' => $state,
            'in_scope' => $inScope,
            'ready_claim_allowed' => $readyAllowed,
            'is_backlog_candidate' => $isBacklog,
            'accepted_evidence' => $accepted,
            'rejected_evidence' => $rejected,
            'blocked_reason' => $blocked,
        ];
    }

    /**
     * Decide whether a "ready" claim is allowed for an area whose only support
     * is its documentation maturity level. The doc forbids declaring an area
     * ready just because it shows as DOC L4: maturity is never runtime evidence.
     *
     * @return array{area:string, doc_level:string, ready_claim_allowed:bool, reason:string}
     */
    public function evaluateDocLevelClaim(string $area, string $docLevel): array
    {
        return [
            'area' => $area,
            'doc_level' => $docLevel,
            'ready_claim_allowed' => false,
            'reason' => 'documentation maturity ('.$docLevel.') is not runtime evidence; ready requires solid_runtime proven by code/test/command/route/receipt/blocker',
        ];
    }

    /**
     * Build the high-impact backlog the Fluxo feeds into Stewardship/Area Focus.
     * Only IN-SCOPE rows in `partial_runtime` or `spec_runtime_gap` qualify;
     * solid rows are done, out-of-scope buckets and decorative tasks are never
     * backlog ("escolha gaps partial_runtime ou spec_runtime_gap com alto
     * impacto na fabrica, nao tarefas decorativas").
     *
     * @param  array<int,array{area:string, state:string, in_scope?:bool, caveat?:string}>|null  $rows
     * @return array{
     *   schema_version:string,
     *   backlog:array<int,array{area:string, state:string, caveat:string}>,
     *   backlog_count:int,
     *   excluded:array<int,array{area:string, state:string, reason:string}>
     * }
     */
    public function highImpactBacklog(?array $rows = null): array
    {
        $rows ??= self::SNAPSHOT;

        $backlog = [];
        $excluded = [];

        foreach ($rows as $row) {
            $area = (string) ($row['area'] ?? '');
            $state = $this->normalizeState((string) ($row['state'] ?? ''));
            $inScope = (bool) ($row['in_scope'] ?? true);
            $caveat = (string) ($row['caveat'] ?? '');

            if (! $inScope || in_array($state, [self::STATE_OUT_OF_SCOPE_MEASUREMENT, self::STATE_OUT_OF_SCOPE_NORTH_STAR], true)) {
                $excluded[] = ['area' => $area, 'state' => $state, 'reason' => 'out_of_scope_not_backlog'];

                continue;
            }
            if ($state === self::STATE_SOLID) {
                $excluded[] = ['area' => $area, 'state' => $state, 'reason' => 'solid_runtime_already_done'];

                continue;
            }
            if (! in_array($state, self::BACKLOG_STATES, true)) {
                $excluded[] = ['area' => $area, 'state' => $state, 'reason' => 'unknown_state_not_backlog'];

                continue;
            }

            $backlog[] = ['area' => $area, 'state' => $state, 'caveat' => $caveat];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'backlog' => $backlog,
            'backlog_count' => count($backlog),
            'excluded' => $excluded,
        ];
    }

    /**
     * Classify a provider invocation. The doc: using a provider directly without
     * a Dev/Forge owner runtime must be declared as a BYPASS, not as complete
     * Forge/Dev execution.
     *
     * @return array{
     *   used_owner_runtime:bool,
     *   classification:string,
     *   may_claim_full_execution:bool,
     *   declaration:string
     * }
     */
    public function classifyProviderInvocation(bool $usedOwnerRuntime): array
    {
        if ($usedOwnerRuntime) {
            return [
                'used_owner_runtime' => true,
                'classification' => 'governed_owner_runtime',
                'may_claim_full_execution' => true,
                'declaration' => 'executed through Dev/Forge owner runtime; full execution claim allowed',
            ];
        }

        return [
            'used_owner_runtime' => false,
            'classification' => 'provider_bypass',
            'may_claim_full_execution' => false,
            'declaration' => 'direct provider call without Dev/Forge owner runtime; must be declared as bypass, not complete Forge/Dev execution',
        ];
    }

    /**
     * Render the full canonical matrix with derived counters. Safe default for
     * the command: no evidence supplied, so no in-scope row is ready and the
     * loop sees the real high-impact backlog.
     *
     * @return array{
     *   schema_version:string,
     *   mode:string,
     *   states:array<int,string>,
     *   snapshot:array<int,array{area:string, state:string, in_scope:bool, caveat:string, is_backlog_candidate:bool, ready_claim_allowed:bool}>,
     *   row_count:int,
     *   solid_count:int,
     *   partial_count:int,
     *   spec_gap_count:int,
     *   drift_count:int,
     *   out_of_scope_count:int,
     *   backlog_count:int,
     *   ready_count:int
     * }
     */
    public function matrix(): array
    {
        $snapshot = [];
        $solid = 0;
        $partial = 0;
        $specGap = 0;
        $drift = 0;
        $outOfScope = 0;
        $ready = 0;

        foreach (self::SNAPSHOT as $row) {
            $classified = $this->classifyArea($row['area'], $row['state']);

            switch ($row['state']) {
                case self::STATE_SOLID:
                    $solid++;
                    break;
                case self::STATE_PARTIAL:
                    $partial++;
                    break;
                case self::STATE_SPEC_GAP:
                    $specGap++;
                    break;
                case self::STATE_DRIFT:
                    $drift++;
                    break;
                default:
                    $outOfScope++;
                    break;
            }

            if ($classified['ready_claim_allowed']) {
                $ready++;
            }

            $snapshot[] = [
                'area' => $row['area'],
                'state' => $row['state'],
                'in_scope' => $row['in_scope'],
                'caveat' => $row['caveat'],
                'is_backlog_candidate' => $classified['is_backlog_candidate'],
                'ready_claim_allowed' => $classified['ready_claim_allowed'],
            ];
        }

        $backlog = $this->highImpactBacklog();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'states' => self::KNOWN_STATES,
            'snapshot' => $snapshot,
            'row_count' => count($snapshot),
            'solid_count' => $solid,
            'partial_count' => $partial,
            'spec_gap_count' => $specGap,
            'drift_count' => $drift,
            'out_of_scope_count' => $outOfScope,
            'backlog_count' => $backlog['backlog_count'],
            'ready_count' => $ready,
        ];
    }

    private function normalizeState(string $state): string
    {
        $state = strtolower(trim($state));

        return in_array($state, self::KNOWN_STATES, true) ? $state : 'unknown';
    }
}
