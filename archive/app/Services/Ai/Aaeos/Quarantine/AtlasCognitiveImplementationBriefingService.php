<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Cognitive Plane — Implementation Briefing runtime.
 *
 * Turns the briefing doc into deterministic, pure decision logic instead of
 * restating prose. The briefing is the operational gate any IA reads before
 * implementing a cognitive AP, and it carries several machine-checkable
 * contracts that this service enforces directly:
 *
 *  - Status taxonomy ("Taxonomia de status", 5 rows): each canonical status maps
 *    to a "can be called ready?" verdict (no / partially / yes-for-runtime /
 *    yes-for-daily-use / yes-high-maturity). The bare `implemented` status is
 *    FORBIDDEN in new APs because it hides the gap — classifyStatus() flags it as
 *    a violation rather than guessing readiness.
 *  - Command policy ("Politica de comandos"): `php artisan atlas:*` is the
 *    implementation contract; a bare `atlas <cap>` product wrapper is an alias,
 *    never the contract; a doc for an IMPLEMENTED AP must show
 *    `php artisan atlas:*`. classifyCommand() / gateApDocCommand() encode that.
 *  - AP roster ("Estado dos APs", 8 rows): AP -> capability -> canonical status,
 *    with the briefing's exact observations. apRoster() / readiness() are the
 *    read model; promotability flows from the taxonomy, not from a status string
 *    that merely contains the word "implemented".
 *  - Reading order ("Ordem obrigatoria de leitura", 13 steps) and the merge
 *    blockers ("Principios que bloqueiam merge") are pinned so a caller can check
 *    that the mandatory context was honoured before code changes.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/cognitive/implementation-briefing.md
 */
final class AtlasCognitiveImplementationBriefingService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const SCHEMA_VERSION = 'atlas.cognitive.implementation_briefing.v1';

    // ---- Status taxonomy ("Taxonomia de status") ----------------------------

    public const STATUS_SCAFFOLD = 'scaffold';
    public const STATUS_READ_MODEL = 'implemented-operational-read-model';
    public const STATUS_RUNTIME = 'implemented-runtime';
    public const STATUS_SURFACE = 'implemented-surface-integrated';
    public const STATUS_SELF_IMPROVING = 'implemented-self-improving';

    /** The bare status the briefing explicitly forbids for new APs. */
    public const STATUS_FORBIDDEN_GENERIC = 'implemented';

    /**
     * Canonical readiness verdicts mirroring the doc's third column
     * ("Pode ser chamado pronto?").
     */
    public const READY_NO = 'no';
    public const READY_PARTIAL = 'partial';
    public const READY_RUNTIME = 'yes-for-runtime';
    public const READY_DAILY = 'yes-for-daily-use';
    public const READY_HIGH_MATURITY = 'yes-high-maturity';

    /**
     * Each canonical status, in maturity order, with its documented meaning and
     * the exact "can be called ready?" verdict from the taxonomy table.
     *
     * @var array<string,array{rank:int,ready:string,meaning:string}>
     */
    private const STATUS_TAXONOMY = [
        self::STATUS_SCAFFOLD => [
            'rank' => 0,
            'ready' => self::READY_NO,
            'meaning' => 'contract drawn; no reliable runtime',
        ],
        self::STATUS_READ_MODEL => [
            'rank' => 1,
            'ready' => self::READY_PARTIAL,
            'meaning' => 'persists, queries, tests and emits evidence; consumers may still be missing',
        ],
        self::STATUS_RUNTIME => [
            'rank' => 2,
            'ready' => self::READY_RUNTIME,
            'meaning' => 'runs the main flow end-to-end under receipt/gates',
        ],
        self::STATUS_SURFACE => [
            'rank' => 3,
            'ready' => self::READY_DAILY,
            'meaning' => 'CLI/App/Mobile/Voice expose the canonical UX',
        ],
        self::STATUS_SELF_IMPROVING => [
            'rank' => 4,
            'ready' => self::READY_HIGH_MATURITY,
            'meaning' => 'Self-Improvement consumes metrics and emits a governed proposal',
        ],
    ];

    // ---- AP roster ("Estado dos APs") ---------------------------------------

    /**
     * The eight cognitive APs exactly as the briefing's "Estado dos APs" table
     * declares them: capability, canonical status, observation.
     *
     * Note: AP-168/169/170 carry the legacy `implemented_partial` token in the
     * table; the briefing treats that as a partial (read-model-grade) status,
     * NOT as the forbidden bare `implemented`.
     *
     * @var array<string,array{capability:string,status:string,note:string}>
     */
    private const AP_ROSTER = [
        'AP-163' => [
            'capability' => 'Dreyfus Dynamic Pedagogy',
            'status' => self::STATUS_READ_MODEL,
            'note' => 'CLI atlas:dreyfus and atlas:study; Dreyfus gate/SLO/events active',
        ],
        'AP-164' => [
            'capability' => 'Worked Example Engine',
            'status' => self::STATUS_READ_MODEL,
            'note' => 'catalog, selector, fading and CLI atlas:worked-example',
        ],
        'AP-165' => [
            'capability' => 'Process Pattern Catalog',
            'status' => self::STATUS_READ_MODEL,
            'note' => 'catalog and matcher; personal detector left for a future AP',
        ],
        'AP-166' => [
            'capability' => 'Failure Signature Tracker',
            'status' => self::STATUS_READ_MODEL,
            'note' => 'classifies failure, alerts repetition, records learning.failure_review; proposal emission future',
        ],
        'AP-167' => [
            'capability' => 'SRL Orchestrator',
            'status' => self::STATUS_READ_MODEL,
            'note' => 'opt-in forethought/performance/reflection; surface hooks left for consumers',
        ],
        'AP-168' => [
            'capability' => 'Productive Failure Flow',
            'status' => 'implemented_partial',
            'note' => 'minimal runtime, CLI, migration, gates, transfer-tests read-model, Self-Improvement review proposal; App/Mobile/Voice UX future',
        ],
        'AP-169' => [
            'capability' => 'Personal Worked Examples Generator',
            'status' => 'implemented_partial',
            'note' => 'integrated into AP-164 via atlas:worked-example extract/personal; scheduler/review UI and source maturity future',
        ],
        'AP-170' => [
            'capability' => 'Predictive Failure Insertion',
            'status' => 'implemented_partial',
            'note' => 'migration, CLI atlas:predict, gates, outcome, Brier/calibration metrics; daily-plan/UX/KG maturity future',
        ],
    ];

    /**
     * Mandatory reading order ("Ordem obrigatoria de leitura"), in sequence.
     *
     * @var list<string>
     */
    private const READING_ORDER = [
        'atlas-ai-thesis-multiplier-channel.md',
        'atlas-ai-canonical-architecture-index.md',
        'atlas-ai-pipeline.md',
        'atlas-ai-core-vs-domain.md',
        'cognitive/README.md',
        'cognitive/overview.md',
        'cognitive/principles.md',
        'cognitive/capabilities-core.md',
        'cognitive/multiplier-edge.md',
        'cognitive/pipeline-overlay.md',
        'cognitive/roadmap.md',
        'domains/learning.md',
        'docs/ap/AP-###-cognitive-*.md',
    ];

    /**
     * Merge-blocking principles ("Principios que bloqueiam merge").
     *
     * @var list<string>
     */
    private const MERGE_BLOCKERS = [
        'C1-C22 in cognitive/principles.md',
        'surface does not decide',
        'provider does not decide',
        'tool does not decide',
        'domain does not bypass policy',
        'runtime does not execute without Decision Receipt',
        'everything repeated becomes Core',
        'learning does not change critical behaviour without proposal/review',
    ];

    // ---- Status taxonomy enforcement ----------------------------------------

    /**
     * Classify a declared status against the briefing's taxonomy and return the
     * exact "can be called ready?" verdict. The bare `implemented` token is a
     * hard violation; an unknown token is reported, not guessed.
     *
     * @return array{
     *   schema_version:string,
     *   status:string,
     *   known:bool,
     *   forbidden:bool,
     *   rank:int|null,
     *   ready:string,
     *   callable_ready:bool,
     *   meaning:string,
     *   violation:string|null
     * }
     */
    public function classifyStatus(string $status): array
    {
        $status = trim($status);

        if ($status === self::STATUS_FORBIDDEN_GENERIC) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => $status,
                'known' => false,
                'forbidden' => true,
                'rank' => null,
                'ready' => self::READY_NO,
                'callable_ready' => false,
                'meaning' => 'generic implemented is forbidden in new APs because it hides the gap',
                'violation' => 'generic_implemented_status_forbidden',
            ];
        }

        $row = self::STATUS_TAXONOMY[$status] ?? null;
        if ($row === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => $status,
                'known' => false,
                'forbidden' => false,
                'rank' => null,
                'ready' => self::READY_NO,
                'callable_ready' => false,
                'meaning' => 'status not in the cognitive taxonomy',
                'violation' => 'status_not_in_cognitive_taxonomy',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'known' => true,
            'forbidden' => false,
            'rank' => $row['rank'],
            'ready' => $row['ready'],
            'callable_ready' => $row['ready'] !== self::READY_NO,
            'meaning' => $row['meaning'],
            'violation' => null,
        ];
    }

    /**
     * True only when the declared status is the runtime grade or higher — i.e.
     * the AP may actually be invoked as runtime. Read-model is NOT runtime-ready.
     */
    public function isRuntimeReady(string $status): bool
    {
        $row = self::STATUS_TAXONOMY[trim($status)] ?? null;

        return $row !== null && $row['rank'] >= self::STATUS_TAXONOMY[self::STATUS_RUNTIME]['rank'];
    }

    // ---- Command policy ("Politica de comandos") ----------------------------

    /**
     * Classify a command string against the command policy. The canonical local
     * Laravel form `php artisan atlas:<cap>` is the implementation contract; a
     * bare product wrapper `atlas <cap>` is an alias, never the contract.
     *
     * @return array{
     *   schema_version:string,
     *   command:string,
     *   layer:string,
     *   is_contract:bool,
     *   is_alias:bool,
     *   note:string
     * }
     */
    public function classifyCommand(string $command): array
    {
        $command = trim($command);
        $lower = strtolower($command);

        $isContract = str_starts_with($lower, 'php artisan atlas:');
        $isAlias = ! $isContract && str_starts_with($lower, 'atlas ');

        if ($isContract) {
            $layer = 'laravel_local_canonical';
            $note = 'implementation contract';
        } elseif ($isAlias) {
            $layer = 'product_wrapper';
            $note = 'permitted alias, not the implementation contract';
        } else {
            $layer = 'unknown';
            $note = 'not a recognised atlas command form';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'command' => $command,
            'layer' => $layer,
            'is_contract' => $isContract,
            'is_alias' => $isAlias,
            'note' => $note,
        ];
    }

    /**
     * Gate the command shown by an AP doc. The briefing requires a doc for an
     * IMPLEMENTED AP to show `php artisan atlas:*`; a future AP doc may show the
     * wrapper only when it is explicitly flagged as a future product alias.
     *
     * @return array{
     *   schema_version:string,
     *   ap_implemented:bool,
     *   command:string,
     *   layer:string,
     *   marked_future_alias:bool,
     *   allowed:bool,
     *   violation:string|null
     * }
     */
    public function gateApDocCommand(string $command, bool $apImplemented, bool $markedFutureAlias = false): array
    {
        $classified = $this->classifyCommand($command);
        $violation = null;
        $allowed = true;

        if ($apImplemented) {
            // Implemented AP docs MUST show the canonical contract form.
            if (! $classified['is_contract']) {
                $allowed = false;
                $violation = 'implemented_ap_doc_must_show_php_artisan_atlas';
            }
        } else {
            // Future AP docs: a wrapper is only allowed when flagged.
            if ($classified['is_alias'] && ! $markedFutureAlias) {
                $allowed = false;
                $violation = 'future_alias_must_be_marked_future_product_alias';
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ap_implemented' => $apImplemented,
            'command' => $classified['command'],
            'layer' => $classified['layer'],
            'marked_future_alias' => $markedFutureAlias,
            'allowed' => $allowed,
            'violation' => $violation,
        ];
    }

    // ---- AP roster read model ("Estado dos APs") ----------------------------

    /**
     * The full AP roster with each AP's canonical status, its taxonomy-derived
     * readiness verdict and the briefing observation.
     *
     * @return array{
     *   schema_version:string,
     *   ap_count:int,
     *   entries:list<array{
     *     ap:string,
     *     capability:string,
     *     status:string,
     *     ready:string,
     *     runtime_ready:bool,
     *     note:string
     *   }>
     * }
     */
    public function apRoster(): array
    {
        $entries = [];
        foreach (self::AP_ROSTER as $ap => $row) {
            $classified = $this->classifyStatus($row['status']);
            $entries[] = [
                'ap' => $ap,
                'capability' => $row['capability'],
                'status' => $row['status'],
                'ready' => $classified['ready'],
                'runtime_ready' => $this->isRuntimeReady($row['status']),
                'note' => $row['note'],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ap_count' => count($entries),
            'entries' => $entries,
        ];
    }

    /**
     * Resolve a single AP to its canonical state, or null if it is not in the
     * briefing roster.
     *
     * @return array{ap:string,capability:string,status:string,ready:string,runtime_ready:bool,note:string}|null
     */
    public function resolveAp(string $ap): ?array
    {
        $ap = strtoupper(trim($ap));
        $row = self::AP_ROSTER[$ap] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'ap' => $ap,
            'capability' => $row['capability'],
            'status' => $row['status'],
            'ready' => $this->classifyStatus($row['status'])['ready'],
            'runtime_ready' => $this->isRuntimeReady($row['status']),
            'note' => $row['note'],
        ];
    }

    /**
     * Readiness rollup across the roster: how many APs are runtime-ready vs
     * read-model/partial only. Per the briefing, NONE of the eight is yet
     * runtime/surface grade, so runtime_ready_count must be 0.
     *
     * @return array{
     *   schema_version:string,
     *   ap_count:int,
     *   runtime_ready_count:int,
     *   not_yet_runtime_count:int,
     *   any_runtime_ready:bool
     * }
     */
    public function readiness(): array
    {
        $roster = $this->apRoster()['entries'];
        $runtimeReady = 0;
        foreach ($roster as $entry) {
            if ($entry['runtime_ready']) {
                $runtimeReady++;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ap_count' => count($roster),
            'runtime_ready_count' => $runtimeReady,
            'not_yet_runtime_count' => count($roster) - $runtimeReady,
            'any_runtime_ready' => $runtimeReady > 0,
        ];
    }

    // ---- Pinned context ------------------------------------------------------

    /**
     * The whole briefing as a single deterministic envelope: taxonomy, command
     * policy layers, AP roster, reading order and merge blockers.
     *
     * @return array<string,mixed>
     */
    public function briefing(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status_taxonomy' => array_map(
                static fn (string $s): array => [
                    'status' => $s,
                    'rank' => self::STATUS_TAXONOMY[$s]['rank'],
                    'ready' => self::STATUS_TAXONOMY[$s]['ready'],
                    'meaning' => self::STATUS_TAXONOMY[$s]['meaning'],
                ],
                array_keys(self::STATUS_TAXONOMY),
            ),
            'forbidden_generic_status' => self::STATUS_FORBIDDEN_GENERIC,
            'reading_order' => self::READING_ORDER,
            'merge_blockers' => self::MERGE_BLOCKERS,
            'ap_roster' => $this->apRoster(),
            'readiness' => $this->readiness(),
        ];
    }

    /**
     * The mandatory reading order, in sequence.
     *
     * @return list<string>
     */
    public function readingOrder(): array
    {
        return self::READING_ORDER;
    }

    /**
     * The merge-blocking principles.
     *
     * @return list<string>
     */
    public function mergeBlockers(): array
    {
        return self::MERGE_BLOCKERS;
    }
}
