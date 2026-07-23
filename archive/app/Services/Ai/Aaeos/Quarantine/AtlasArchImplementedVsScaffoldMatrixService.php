<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Implemented vs Scaffold Matrix decider.
 *
 * Pure, deterministic runtime for the read-only handoff matrix doc. The matrix
 * exists to stop one specific failure: an agent (or the model itself) selling a
 * scaffold/future/partial block as a finished product, or duplicating a flow that
 * already exists. This service turns the doc's concrete contract into enforceable
 * functions and never lies.
 *
 * Four documented mechanisms are modelled:
 *
 *  1. Status Vocabulary (doc "Status Vocabulary" table). Six fixed status tokens,
 *     each with an operational meaning: implemented_ready, implemented_partial,
 *     scaffold, future, blocked, unknown. `classifyStatus` resolves a raw status
 *     to its canonical token + meaning; anything outside the table becomes
 *     `unknown` ("Nao afirmar pronto; exige auditoria focada antes de
 *     implementar") rather than a guess.
 *
 *  2. Product-readiness decision (doc frontmatter `decisions` + "Conflicts To
 *     Reconcile" + forbidden_changes). The doc's hard claim: "Itens scaffold ou
 *     future nao podem ser vendidos como produto pronto" and "Declarar runtime,
 *     maturidade ou prontidao sem evidencia verificavel e gates verdes" is
 *     forbidden. `decideProductReadiness` returns product_ready=true ONLY for
 *     implemented_ready WITH verifiable evidence; every other token (partial,
 *     scaffold, future, blocked, unknown) is product_ready=false. Crucially,
 *     implemented_ready WITHOUT evidence is also product_ready=false — silence
 *     never proves readiness.
 *
 *  3. Domain-ready vs product-final guard (doc "Conflicts To Reconcile" row 1):
 *     "`15 domains ready` significa contrato/orchestrator/flows/gates prontos;
 *     maturidade de produto vive nas linhas partial/scaffold". `domainReadyIsNot
 *     ProductFinal` makes "domain ready" never imply "product final".
 *
 *  4. Handoff Rule (doc "Handoff Rule"): before any new implementation, readiness
 *     must be checked; if `readiness.status=attention`, the blocker must be fixed
 *     or an explicit decision recorded BEFORE expanding, and "Nao criar fluxo
 *     paralelo para acelerar". `evaluateHandoff` gates a proposed new block: it
 *     blocks when readiness is in attention without an explicit decision, and it
 *     blocks any attempt to build a parallel flow for capability already covered.
 *
 * The Safe Next Blocks order (doc "Safe Next Blocks", ordem 1..5) is preserved so
 * a handoff can pick the documented next-safe block instead of inventing one.
 *
 * NEVER calls a provider. NEVER executes a command, mutates code, expands a block
 * or touches the database. It emits classification + a handoff verdict; callers
 * decide whether to proceed, fix a blocker, or stop.
 *
 * @see docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
 */
final class AtlasArchImplementedVsScaffoldMatrixService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.implemented_vs_scaffold_matrix.v1';

    /** Doc "Status Vocabulary" — the six canonical status tokens. */
    public const STATUS_IMPLEMENTED_READY = 'implemented_ready';

    public const STATUS_IMPLEMENTED_PARTIAL = 'implemented_partial';

    public const STATUS_SCAFFOLD = 'scaffold';

    public const STATUS_FUTURE = 'future';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Doc "Status Vocabulary" table — each status token mapped to its documented
     * operational meaning. Order preserved from the doc. `unknown` is the
     * sentinel for anything outside the table (the doc forbids asserting ready
     * for it).
     *
     * @var array<string,string>
     */
    public const STATUS_VOCABULARY = [
        self::STATUS_IMPLEMENTED_READY => 'Codigo/teste/comando/API existem e a validacao nao aponta blocker direto.',
        self::STATUS_IMPLEMENTED_PARTIAL => 'Base existe, mas falta integracao, runtime real, maturidade ou reconciliacao.',
        self::STATUS_SCAFFOLD => 'Contrato, doc, rota ou classe existem, mas ainda nao e produto final.',
        self::STATUS_FUTURE => 'Intencao aprovada, sem execucao atual suficiente.',
        self::STATUS_BLOCKED => 'Proximo passo deve corrigir validacao ou conflito antes de expandir.',
        self::STATUS_UNKNOWN => 'Nao afirmar pronto; exige auditoria focada antes de implementar.',
    ];

    /**
     * The only status the doc treats as a candidate for "product ready" — and
     * even then only with verifiable evidence and green gates.
     */
    public const PRODUCT_READY_STATUS = self::STATUS_IMPLEMENTED_READY;

    /**
     * Doc "Safe Next Blocks" table — ordem 1..5, the documented next-safe blocks
     * in priority order. Used so a handoff picks a documented block instead of
     * inventing a parallel flow.
     *
     * @var array<int,array{order:int,block:string}>
     */
    public const SAFE_NEXT_BLOCKS = [
        1 => ['order' => 1, 'block' => 'Voice Realtime product loop'],
        2 => ['order' => 2, 'block' => 'Programming harness durable execution'],
        3 => ['order' => 3, 'block' => 'Cognitive Plane UX hooks'],
        4 => ['order' => 4, 'block' => 'Provider Release ingestion runtime'],
        5 => ['order' => 5, 'block' => 'Runtime language boundary maintenance'],
    ];

    /**
     * Classify a raw status string against the doc "Status Vocabulary".
     *
     * Returns the canonical token and its documented operational meaning. Any
     * value outside the six-token vocabulary collapses to `unknown` (found=false)
     * — the matrix never guesses readiness for an unrecognized status.
     *
     * @return array{schema_version:string,input:string,status:string,found:bool,meaning:string,is_product_ready_candidate:bool}
     */
    public function classifyStatus(string $rawStatus): array
    {
        $key = $this->normalizeKey($rawStatus);
        $found = array_key_exists($key, self::STATUS_VOCABULARY) && $key !== self::STATUS_UNKNOWN;
        $status = $found ? $key : self::STATUS_UNKNOWN;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'input' => $rawStatus,
            'status' => $status,
            'found' => $found,
            'meaning' => self::STATUS_VOCABULARY[$status],
            'is_product_ready_candidate' => $status === self::PRODUCT_READY_STATUS,
        ];
    }

    /**
     * Apply the doc's product-readiness decision to a block.
     *
     * Doc decisions: "Itens scaffold ou future nao podem ser vendidos como
     * produto pronto." Doc forbidden_changes: "Declarar runtime, maturidade ou
     * prontidao sem evidencia verificavel e gates verdes."
     *
     * product_ready=true ONLY when BOTH hold:
     *   - status resolves to implemented_ready, AND
     *   - verifiable evidence is present (has_evidence=true).
     *
     * Every other token is product_ready=false with a documented reason, and
     * implemented_ready WITHOUT evidence is product_ready=false too (silence
     * never proves readiness). `sellable_as_product` mirrors product_ready — the
     * field name the doc's anti-overclaim rule speaks to.
     *
     * @param  array{status?:string,has_evidence?:bool}  $block
     * @return array<string,mixed>
     */
    public function decideProductReadiness(array $block): array
    {
        $classified = $this->classifyStatus((string) ($block['status'] ?? ''));
        $status = $classified['status'];
        $hasEvidence = ($block['has_evidence'] ?? false) === true;

        $reasons = [];
        $productReady = false;

        if ($status !== self::PRODUCT_READY_STATUS) {
            // partial / scaffold / future / blocked / unknown can never be sold.
            $reasons[] = match ($status) {
                self::STATUS_IMPLEMENTED_PARTIAL => 'partial_lacks_runtime_or_maturity',
                self::STATUS_SCAFFOLD => 'scaffold_is_not_final_product',
                self::STATUS_FUTURE => 'future_has_no_sufficient_execution',
                self::STATUS_BLOCKED => 'blocked_must_fix_validation_first',
                default => 'unknown_requires_focused_audit',
            };
        } elseif (! $hasEvidence) {
            // implemented_ready but no verifiable evidence -> forbidden claim.
            $reasons[] = 'readiness_claimed_without_verifiable_evidence';
        } else {
            $productReady = true;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'has_evidence' => $hasEvidence,
            'product_ready' => $productReady,
            'sellable_as_product' => $productReady,
            'reasons' => $reasons,
        ];
    }

    /**
     * Doc "Conflicts To Reconcile" row 1: "Domain readiness e produto final sao
     * coisas diferentes." `15 domains ready` means contract/orchestrator/flows/
     * gates are ready; product maturity lives in the partial/scaffold lines.
     *
     * Given a count of domains declared ready, this NEVER reports product_final;
     * it returns the documented safe interpretation so an agent cannot upsell
     * "domain ready" into "product final".
     *
     * @return array{schema_version:string,domains_ready:int,means_product_final:bool,means_contract_orchestrator_flows_gates_ready:bool,safe_action:string}
     */
    public function domainReadyIsNotProductFinal(int $domainsReady): array
    {
        $count = max(0, $domainsReady);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'domains_ready' => $count,
            'means_product_final' => false,
            'means_contract_orchestrator_flows_gates_ready' => $count > 0,
            'safe_action' => 'read_partial_and_scaffold_lines_for_product_maturity',
        ];
    }

    /**
     * Apply the doc "Handoff Rule" to a proposed new block.
     *
     * Doc: run readiness/place-feature/architecture-validate before any new
     * implementation. "Se `readiness.status=attention`, corrigir o blocker ou
     * registrar decisao explicita antes de expandir. Nao criar fluxo paralelo
     * para acelerar."
     *
     * The verdict is `block` when ANY of:
     *   - readiness_status=attention AND no explicit decision recorded (must fix
     *     the blocker or record a decision first), OR
     *   - the proposal builds a parallel flow for capability already covered
     *     (creates_parallel_flow=true) — accelerating by duplication is forbidden.
     * Otherwise the verdict is `proceed`.
     *
     * @param  array{readiness_status?:string,explicit_decision_recorded?:bool,creates_parallel_flow?:bool,capability_already_covered?:bool}  $proposal
     * @return array<string,mixed>
     */
    public function evaluateHandoff(array $proposal): array
    {
        $readiness = $this->normalizeKey((string) ($proposal['readiness_status'] ?? 'ready'));
        $explicitDecision = ($proposal['explicit_decision_recorded'] ?? false) === true;
        $createsParallel = ($proposal['creates_parallel_flow'] ?? false) === true;
        $alreadyCovered = ($proposal['capability_already_covered'] ?? false) === true;

        $blockers = [];

        if ($readiness === 'attention' && ! $explicitDecision) {
            $blockers[] = 'readiness_attention_without_explicit_decision';
        }

        // "Nao criar fluxo paralelo para acelerar." Building a parallel flow is
        // forbidden outright; if the capability is already covered it is doubly so.
        if ($createsParallel) {
            $blockers[] = $alreadyCovered
                ? 'parallel_flow_for_already_covered_capability'
                : 'parallel_flow_forbidden_reuse_existing';
        }

        $verdict = $blockers === [] ? 'proceed' : 'block';

        $requiredCommands = [
            'php artisan atlas:ai:architecture-readiness --json',
            'php artisan atlas:ai:place-feature "<feature>" --strict --json',
            'php artisan atlas:ai:architecture-validate --json',
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'blockers' => $blockers,
            'readiness_status' => $readiness,
            'parallel_flow_forbidden' => true,
            'required_preflight_commands' => $requiredCommands,
        ];
    }

    /**
     * Doc "Safe Next Blocks" — return the documented next-safe blocks in priority
     * order so a handoff picks a documented block, not an invented parallel flow.
     *
     * @return array{schema_version:string,blocks:array<int,array{order:int,block:string}>,first:string}
     */
    public function safeNextBlocks(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'blocks' => array_values(self::SAFE_NEXT_BLOCKS),
            'first' => self::SAFE_NEXT_BLOCKS[1]['block'],
        ];
    }

    /**
     * Composite snapshot for the matrix doc. Classifies a set of observed blocks,
     * applies the product-readiness decision to each, and reports how many are
     * genuinely product-ready vs how many must NOT be sold as final. Safe default
     * models the doc's own canonical trap: a scaffold block that an agent might
     * mistake for a finished product.
     *
     * @param  list<array{block?:string,status?:string,has_evidence?:bool}>  $blocks
     * @return array<string,mixed>
     */
    public function snapshot(array $blocks = []): array
    {
        if ($blocks === []) {
            $blocks = [
                ['block' => 'Voice Realtime', 'status' => self::STATUS_SCAFFOLD, 'has_evidence' => true],
            ];
        }

        $rows = [];
        $productReadyCount = 0;
        $notSellableCount = 0;

        foreach ($blocks as $raw) {
            $decision = $this->decideProductReadiness([
                'status' => (string) ($raw['status'] ?? ''),
                'has_evidence' => ($raw['has_evidence'] ?? false) === true,
            ]);

            if ($decision['product_ready']) {
                $productReadyCount++;
            } else {
                $notSellableCount++;
            }

            $rows[] = [
                'block' => (string) ($raw['block'] ?? 'unnamed_block'),
                'status' => $decision['status'],
                'product_ready' => $decision['product_ready'],
                'reasons' => $decision['reasons'],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'read_only' => true,
            'total' => count($rows),
            'product_ready' => $productReadyCount,
            'not_sellable_as_product' => $notSellableCount,
            'blocks' => $rows,
        ];
    }

    /**
     * Normalize a free-text status/key to snake_case ascii (lower, non-alnum
     * collapsed to a single underscore, trimmed).
     */
    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }
}
