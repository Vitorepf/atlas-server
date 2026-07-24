<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Atlas Loop LEGACY ACDE config (extracted full-pass multi-file split)
|--------------------------------------------------------------------------
| Loaded as config('atlas.loop') via require from config/atlas.php.
| Daily operate path is atlas:brain:* / atlas:task:* (Autônomos live).
| Keep-list AtlasLoop* classes may still read values here.
| Do NOT re-enable atlas:loop:* as the live CLI.
*/

return [
    // '' (empty) => let the senior-loop / Atlas Decide pick. Set a key to pin.
    'default_provider' => (string) env('ATLAS_LOOP_DEFAULT_PROVIDER', env('ATLAS_AI_DEFAULT_PROVIDER', '')),
    // Baseline / minimum candidate scenarios the loop explores per task before
    // the frozen judge picks the best (the "explore 20, keep the 1 that works").
    'scenarios_per_task' => max(1, (int) env('ATLAS_LOOP_SCENARIOS_PER_TASK', 3)),
    // DEEP SEARCH: the loop keeps exploring NEW scenarios (up to this hard cap)
    // as long as it keeps finding strictly-better candidates — it spends real
    // time hunting the best evolution scenario, like a junior exploring 20
    // options until the right one. It stops early once it converges (below).
    'max_scenarios_per_task' => max(1, (int) env('ATLAS_LOOP_MAX_SCENARIOS_PER_TASK', 12)),
    // Convergence: once a winner exists, stop after this many consecutive
    // scenarios that fail to improve it (patience). Higher = searches harder.
    'search_patience' => max(1, (int) env('ATLAS_LOOP_SEARCH_PATIENCE', 3)),
    // Lever 4 — CROSS-PROVIDER best-of-N. Comma-separated provider keys the explorer rotates the N
    // attempts across, so candidates are DECORRELATED by engine (codex and MiniMax fail differently),
    // a real one-shot success-rate lift. Empty => single-provider (byte-identical to before). The
    // explorer reads this ONLY when a task pins no provider, so pure unit tests never touch config.
    'scenario_provider_portfolio' => array_values(array_filter(array_map(
        static fn (string $p): string => trim($p),
        explode(',', (string) env('ATLAS_LOOP_SCENARIO_PROVIDER_PORTFOLIO', '')),
    ), static fn (string $p): bool => $p !== '')),
    // ACDE direction-(a) · DEEPER best-of-N on a SINGLE weak engine. The cross-provider portfolio
    // above decorrelates by ENGINE; this is its single-engine sibling. On Hermes/MiniMax there is no
    // per-call temperature/seed, so the ONLY decorrelation lever is STRATEGY diversity. The explorer's
    // built-in pool is 5 mandates (baseline + A..D), but max_scenarios_per_task widens to 12 — so
    // scenarios 5..11 cycle back to the same 5 prompts and, lacking temp/seed, collapse into near-
    // duplicate diffs (best-of-12 buys only best-of-5-distinct). When ON, the explorer's default pool
    // extends to 9 STRUCTURALLY-DISTINCT mandates (adds guard-first / extract-helper / type-driven /
    // invert-flatten) so widening actually buys genuinely-different candidates the frozen judge can
    // choose between. Injected via the task by the grinder (the explorer hot path stays config-free);
    // indices 0..4 are byte-identical so OFF == today and persisted strategy keys are stable.
    'deep_strategy_portfolio' => (bool) env('ATLAS_LOOP_DEEP_STRATEGY_PORTFOLIO', false),
    // ACDE lever #1 — the SAFETY belt for arming the multi-engine best-of-N portfolio. The cross-
    // provider rotation (scenario_provider_portfolio above) is fully built + live on Path B, but agentic
    // CLI engines (codex_cli/gemini_cli) edit the workspace IN PLACE — so the FrozenJudge SCOPE guard must
    // be real. Without allowed_globs the judge defaults to ['**'] (allow-all) and an out-of-scope edit by
    // a rotated engine is NOT caught. When this is ON, the materializers derive the writable globs from
    // allowed_files (the tightest correct scope) for any task whose acceptance carries none — so the
    // portfolio can be armed soundly. ARMING BUNDLE (operator, .env): set this true AND set
    // ATLAS_LOOP_SCENARIO_PROVIDER_PORTFOLIO=hermes_cli,codex_cli,gemini_cli (keys MUST be the *_cli form
    // — bare 'codex'/'gemini' hit provider_not_configured and silently no-op). Default OFF => byte-
    // identical (tightening to allowed_files never false-rejects a candidate that edits only what it may).
    'cross_provider_best_of_n' => (bool) env('ATLAS_LOOP_CROSS_PROVIDER_BEST_OF_N', false),
    // ACDE lever #5 — the strong-engine ESCALATION rung (the missing N×M multiplier). The conductor's
    // ladder walks best_of_n -> repair -> decompose -> escalate_provider; on a single weak engine that
    // last tier just re-ran the SAME engine wider, so a no-winner dead-ended (a DQS refusal=defect). Set
    // this to a genuinely STRONGER configured engine key (e.g. codex_cli / a gpt-5.5 provider) and the
    // FINAL rung hands that engine the now-refuted task under the SAME pétreo frozen judge — converting a
    // refusal into a certified delivery on the same run. NEVER Claude (Anthropic 3rd-party block + no-burn
    // rule) and never equal to the weak default (anti-theatre); both fall back to byte-identical. Default
    // '' => OFF => byte-identical (the rung stays the same-engine $deep tier).
    'escalation_strong_provider' => trim((string) env('ATLAS_LOOP_ESCALATION_STRONG_PROVIDER', '')),
    // Hard caps per task (the autoresearch fixed-budget discipline).
    'max_seconds_per_scenario' => max(30, (int) env('ATLAS_LOOP_MAX_SECONDS_PER_SCENARIO', 600)),
    // The loop NEVER merges to main: it accumulates certified-for-review proposals.
    'propose_only' => (bool) env('ATLAS_LOOP_PROPOSE_ONLY', true),

    // L2-1: "sucesso" de provider com ZERO mudanças no workspace re-tenta uma vez
    // (assinatura da regressão acp diff-0); carimbo zero_diff_retry auditável.
    'zero_diff_retry' => (bool) env('ATLAS_LOOP_ZERO_DIFF_RETRY', true),

    // ACDE Tier-0 #4: probe determinístico de overfit no cert pipeline — recusa `return <literal>`
    // curto-circuitado por func_num_args() ou igualdade-no-input (o gaming do modelo fraco). Alta
    // precisão (só as formas inequívocas), gateia TODA cert. Default ON: pegar isso É o moat.
    'overfit_probe_enabled' => (bool) env('ATLAS_LOOP_OVERFIT_PROBE_ENABLED', true),

    // S215 Discovery→Brain coupling: ORIGINATION REFUSAL MEMORY. When ON, leverage-first origination
    // demotes a target the brain has already refused >= _min times (target-intrinsic refusals only)
    // below fresh targets, so it ORIGINATES a new target instead of re-proposing a failed one — the
    // brain's per-target memory becomes load-bearing on WHAT is originated. Default OFF/empty-map =>
    // byte-identical first-valid pick. Stacks under leverage_first_origination_enabled (the whole
    // leverage-first path) + ATLAS_LOOP_MASTER_ENABLED + AtlasBrainMasterSwitch.
    'origination_refusal_memory_enabled' => (bool) env('ATLAS_LOOP_ORIGINATION_REFUSAL_MEMORY_ENABLED', false),
    'origination_refusal_memory_min' => max(1, (int) env('ATLAS_LOOP_ORIGINATION_REFUSAL_MEMORY_MIN', 2)),

    // QUEUE-AWARE ORIGINATION: the brain considers CODE *and* the live TASK QUEUE — leverage-first
    // origination DEMOTES a candidate whose target already has a LIVE task packet (seeded by ANY
    // brain/session), so it stops re-proposing work that already exists in the queue ("onipresente no
    // escopo"). Binary signal (target queued? y/n), never a scalar (anti-Goodhart). Fail-OPEN on a queue
    // read error. Default OFF => the live-queue is never read => byte-identical first-valid pick. Stacks
    // under leverage_first_origination_enabled + ATLAS_LOOP_MASTER_ENABLED + AtlasBrainMasterSwitch.
    'origination_queue_dedup_enabled' => (bool) env('ATLAS_LOOP_ORIGINATION_QUEUE_DEDUP_ENABLED', false),

    // MAXN-04 — proven-yield origination. When armed under the leverage-first path, candidates are ordered
    // by PathYieldEwma samples anchored in proven_real outcomes only; unknown-yield paths keep exploration
    // priority over known low-yield paths. Default OFF keeps the existing leverage-ranked pick unchanged.
    'origination_yield_enabled' => (bool) env('ATLAS_LOOP_ORIGINATION_YIELD_ENABLED', false),

    // MULTN17-03 — evidence-backed multi-source opportunity leads. When armed under leverage-first
    // origination, local open gaps and ponytail debt that resolve to real repo files are added as
    // ordinary orphan_wiring candidates; closed/dead evidence is dropped. Default OFF is byte-identical.
    'multi_source_opportunity_scanner_enabled' => (bool) env('ATLAS_LOOP_MULTI_SOURCE_OPPORTUNITY_SCANNER_ENABLED', false),

    // MULTN17-02 — composed obra arc origination. When armed under leverage-first origination,
    // neighbor candidates (organ dependency graph) may serialize into one arc with thesis,
    // completion criterion, and kill-gate; each task still passes architect + seed gates individually.
    // Default OFF ⇒ zero arcs and byte-identical produce().
    'composed_obra_arc_enabled' => (bool) env('ATLAS_LOOP_COMPOSED_OBRA_ARC_ENABLED', false),

    // MULTN17-06 — evidence-derived persistent vision theses. When armed under leverage-first
    // origination, ≤3 active theses derived from series/leads/calibration reorder candidates
    // as WEIGHT only (never veto/fabrication). Death criterion + TTL archive stale theses.
    // Default OFF ⇒ byte-identical produce().
    'vision_theses_enabled' => (bool) env('ATLAS_LOOP_VISION_THESES_ENABLED', false),

    // CONTRACT-GAP ORIGINATION: when ON, the automated origination writer prompt
    // (AtlasLoopComprehensionOriginator::buildPrompt) surfaces interfaces declared in scope with ZERO
    // implementer (declared-but-unfulfilled contracts) as a high-leverage axis — architecture-completion,
    // not orphan-wiring. Binary/grounded signal via the shared AtlasLoopContractGapScanner. Default OFF =>
    // the line is empty => the writer prompt is byte-identical. Same surface as atlas:brain:contract-gaps.
    'contract_gap_origination_enabled' => (bool) env('ATLAS_LOOP_CONTRACT_GAP_ORIGINATION_ENABLED', false),

    // AUTHOR≠JUDGE runtime cert predicate: se o diff de uma proposta tocar QUALQUER arquivo
    // dono de juízo/gate (HarnessGuard::FORBIDDEN_SELF_TARGETS), a cert é RECUSADA — não só
    // edit-bloqueada. Converte author≠judge de perímetro (blocklist de edição) em predicado
    // de cert load-bearing. Default ON (igual overfit_probe acima): self-judging é o caso de
    // alta severidade que a autópsia de 12/06 pegou rodando LIVE; default-OFF reabriria o buraco.
    'author_judge_overlap_gate_enabled' => (bool) env('ATLAS_LOOP_AUTHOR_JUDGE_OVERLAP_GATE_ENABLED', true),

    // ACDE engine-independence: providers de TEXTO/HTTP (default: MiniMax M3 API) não editam
    // arquivos — devolvem a mudança no corpo da resposta. Com este flag ON, o driver parseia
    // e APLICA esse texto no workspace via DiffParser+PatchApplier/full-file blocks. CLI
    // providers NÃO recebem esse protocolo no prompt, porque têm filesystem e devem editar
    // em place.
    'text_provider_edit_apply' => (bool) env('ATLAS_LOOP_TEXT_PROVIDER_EDIT_APPLY', true),
    'text_provider_edit_apply_providers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ATLAS_LOOP_TEXT_PROVIDER_EDIT_APPLY_PROVIDERS', 'minimax_m27')),
    ))),
    // Hermes/Codex/Claude provider bootstraps may refresh provider projections inside the
    // disposable scenario workspace. Those files are Atlas memory projections, not the
    // candidate implementation, so reset them before the frozen judge unless the task
    // explicitly allowed editing them. This keeps out-of-scope gates strict for real code.
    'provider_projection_noise_reset' => (bool) env('ATLAS_LOOP_PROVIDER_PROJECTION_NOISE_RESET', true),
    'provider_projection_noise_files' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ATLAS_LOOP_PROVIDER_PROJECTION_NOISE_FILES', 'AGENTS.md,CLAUDE.md')),
    ))),

    // ACDE Tier-1 #7: DEPENDENCY-BODY grounding. The code-graph seam injects only callee SIGNATURES;
    // a weak engine then hallucinates the callee CONTRACT (confident wrong calls). With this ON, the
    // driver appends the EXACT body (line_start..line_end range read, capped) of the top-K cross-file
    // dependencies. Default OFF => byte-identical prompt.
    'inject_dependency_bodies' => (bool) env('ATLAS_LOOP_INJECT_DEPENDENCY_BODIES', false),
    // ACDE lever B1-fast — extend the brain-context seams (code-graph signatures + dependency bodies)
    // to the ITERATE-TO-GREEN retry prompt (buildFixPrompt), not just the first attempt. Today the
    // retry — exactly where the weak engine is failing — goes in with ZERO brain. When ON (AND the
    // per-source flags above are armed), the fix prompt gets the same grounding as the initial prompt.
    // Default OFF => byte-identical; injects nothing the operator has not already armed for buildPrompt.
    'brain_context_on_fix_prompt' => (bool) env('ATLAS_LOOP_BRAIN_CONTEXT_ON_FIX_PROMPT', false),
    // ACDE B3 — provider-safe DELIVERY RECALL into the loop prompt window: the recent CERTIFIED merged
    // deliveries in the edited file's module (paths only, never raw code), so the weak engine matches the
    // conventions of what just landed nearby. The read-back half of the brain flywheel whose write half is
    // the merge itself (reads atlas_loop_proposals — no new table, no merge-path write). A SELF signal,
    // never engine-vs-engine. Default OFF => no lines => byte-identical.
    'brain_delivery_recall_enabled' => (bool) env('ATLAS_LOOP_BRAIN_DELIVERY_RECALL_ENABLED', false),
    // ACDE B4a — inject the BLAST RADIUS of the edited file (who depends on it, via the code-graph reverse-
    // dependency walk) so the weak engine edits a hub knowingly. De-orphans AtlasLoopBlastRadiusAnalyzer
    // over the live world-model edges; surfaces consumer PATHS + a risk band only (provider-safe, no raw
    // code). Reads an index that already exists (no new table, no write). Default OFF => no lines =>
    // byte-identical. Arm only when the workspace code-graph index is fresh (else no path match => empty).
    'blast_radius_brain_enabled' => (bool) env('ATLAS_LOOP_BLAST_RADIUS_BRAIN_ENABLED', false),
    // ACDE B2 — AGGREGATE cap (total chars across ALL in-scope files) for the CURRENT FILE CONTENTS dump
    // in the loop prompt. Each file is capped individually, but with no total cap a many-file obra swamps
    // the weak engine's small window before the ranked brain context lands. 0 (default) => unlimited =>
    // byte-identical; set e.g. 40000 to protect the MiniMax window (overflow files are omitted with the
    // [TRUNCATED_BY_ATLAS_OPEN_BRAIN_BUDGET] marker). Pair with code_graph.auto_context_budget (now read).
    'prompt_file_contents_budget_chars' => max(0, (int) env('ATLAS_LOOP_PROMPT_FILE_CONTENTS_BUDGET_CHARS', 0)),

    // L2-2: descoberta admite targets framework-reach (serviços REAIS) — eles seguem
    // o caminho framework-materializer + intent-verifier + certificação adversarial.
    // Default OFF de fábrica; o operador ligou em 12/06 (.env). Reversível.
    'discovery_framework_targets' => (bool) env('ATLAS_LOOP_DISCOVERY_FRAMEWORK_TARGETS', false),

    // L3-2: a descoberta injeta intents guiados pelo BACKLOG REAL (manifesto curado +
    // corpus de falhas), com OBJETIVO ESPECÍFICO promovido no ranking — o que ataca os
    // 94% de waste medidos no Marco Zero (o Loop deixa de inventar tarefa genérica).
    // Default OFF de fábrica; o operador liga via .env. Fail-open (fonte vazia ⇒ no-op).
    'discovery_backlog_intents' => (bool) env('ATLAS_LOOP_DISCOVERY_BACKLOG_INTENTS', false),

    // LOOP-OS Grupos B/C — wiring das 11 capacidades no feed vivo. Default ON ("deixa tudo
    // ligado", diretiva do operador). Cada wire é additive + fail-open/self-gating, então
    // default-ON é byte-identical no caminho default quando o alimentador correspondente ainda
    // não está presente (ex.: bug-repro só dispara com failure-signature; perf-proof só com
    // contrato perf; research só com search tool armado). Flags planas em atlas.loop.* .
    'bug_reproduction_lane_enabled' => (bool) env('ATLAS_LOOP_BUG_REPRODUCTION_LANE_ENABLED', true),
    'discovery_coverage_deficit_enabled' => (bool) env('ATLAS_LOOP_DISCOVERY_COVERAGE_DEFICIT_ENABLED', true),
    'discovery_clone_dedup_enabled' => (bool) env('ATLAS_LOOP_DISCOVERY_CLONE_DEDUP_ENABLED', true),
    'clone_similarity_threshold' => (float) env('ATLAS_LOOP_CLONE_SIMILARITY_THRESHOLD', 0.9),

    // P27 — the research-to-RED origination BACKEND gate (AtlasLoopResearchOriginator). Default-OFF =>
    // fail-closed (no objective minted from research). Arm it only when the 24/7 regime is wanted: the
    // loop's grind engine (Hermes) has native browser/web tools, so an armed backend lets the grind
    // research a topic live and prove the improvement with its own RED change (source stays advisory).
    'research_backend_enabled' => (bool) env('ATLAS_LOOP_RESEARCH_BACKEND_ENABLED', false),

    // Net-new MATERIAL supply via decomposition (AtlasLoopComplexTargetDecomposer): how many independent
    // sub-refactors a single multi-method complex file may flood into one refill. Each sub-target is
    // material-by-construction (cyclomatic >= material_refactor_min_cyclomatic); this only bounds the count.
    'decompose_max_subtargets' => max(1, (int) env('ATLAS_LOOP_DECOMPOSE_MAX_SUBTARGETS', 8)),
    // NET-NEW MATERIAL SUPPLY LANE (AtlasLoopQueueRefiller decompose lane). The loop surfaces ONE refactor
    // per complex file (its worst method); after refactoring ~the first wave of files it runs out of
    // single-objective substantive targets and fills every refill with coverage. A per-method census shows
    // many more material refactor methods (cyclomatic >= material_refactor_min_cyclomatic) still untapped in
    // those same multi-method files. When this flag is ON, the refiller drains that untapped material supply:
    // for each complex file in the discovery scope with NO in-flight task it re-mints a governed refactor
    // task (the SAME framework-refactor synthesizer + complexity-proof cert as a normal refactor — never a
    // proxy, never coverage), serialized at most ONE in-flight task per file so two workers never grind the
    // same file. Default-OFF: with it off refill() is byte-identical to today (the lane early-returns before
    // any work). The per-file count is bounded by decompose_supply_max_files_per_refill.
    'decompose_supply_enabled' => (bool) env('ATLAS_LOOP_DECOMPOSE_SUPPLY_ENABLED', false),
    'decompose_supply_max_files_per_refill' => max(1, (int) env('ATLAS_LOOP_DECOMPOSE_SUPPLY_MAX_FILES_PER_REFILL', 8)),
    // §5.6 DEDUP-SUPPLY — the comprehension brain DRIVING selection: build the scope-comprehension model and
    // mint CERTIFIABLE clone-unification tasks from its clone clusters (net-new work the proxy scan cannot
    // produce). Default-OFF => the refiller builds no model and mints nothing (byte-identical). Needs the
    // judge's Guard 4d armed (refactor_dedup_proof) for the minted task to certify. Pair both to go live.
    'dedup_supply_enabled' => (bool) env('ATLAS_LOOP_DEDUP_SUPPLY_ENABLED', false),
    'dedup_supply_max_per_refill' => max(1, (int) env('ATLAS_LOOP_DEDUP_SUPPLY_MAX_PER_REFILL', 4)),
    'obra_earned_red_enabled' => (bool) env('ATLAS_LOOP_OBRA_EARNED_RED_ENABLED', true),
    'comprehension_grounding_gate_enabled' => (bool) env('ATLAS_LOOP_COMPREHENSION_GROUNDING_GATE_ENABLED', true),
    'external_research_enabled' => (bool) env('ATLAS_LOOP_EXTERNAL_RESEARCH_ENABLED', true),
    'external_research_tool_available' => (bool) env('ATLAS_LOOP_EXTERNAL_RESEARCH_TOOL_AVAILABLE', false),
    // §5.5 — the research TOPIC producer (AtlasLoopResearchTopicDeriver). When ON, the per-target authoring
    // lane derives an egress-safe public-concept research topic from the target's work-shape and feeds the
    // generator's advisory EXTERNAL-RESEARCH slot, so research guides authoring a STRONGER real improvement
    // (the obligation stays a genuinely RED-verified test on the real target — research never gates a cert).
    // Default-OFF => no topic is ever derived => the generator options are byte-identical to today. For a
    // note to actually reach the prompt the whole chain must be armed: this flag + external_research_enabled
    // + external_research_tool_available + a wired research backend (otherwise the service fail-closes).
    'research_authoring_enabled' => (bool) env('ATLAS_LOOP_RESEARCH_AUTHORING_ENABLED', false),
    'refactor_performance_proof' => (bool) env('ATLAS_LOOP_REFACTOR_PERFORMANCE_PROOF', true),
    // §5.6 DEDUP — the frozen-judge Guard 4d clone-unification proof. When ON, a `dedup_proof` acceptance
    // certifies ONLY when behavior is preserved (Guard 3 frozen per-member siblings green) AND the targeted
    // clone duplication is REMOVED (the judge's OWN count-drop, never a provider number). Default-OFF =>
    // Guard 4d is never entered => the judge is byte-identical. The clone-unification SUPPLY lane (the brain
    // minting these tasks from the comprehension model's clone clusters) is separately gated below.
    'refactor_dedup_proof' => (bool) env('ATLAS_LOOP_REFACTOR_DEDUP_PROOF', false),
    // §5.6 ORPHAN-WIRING — the frozen-judge Guard 4e. When ON, a `wired_proof` acceptance certifies ONLY
    // when a former orphan went 0->>=1 production callers AND is MEANINGFULLY load-bearing (neutralizing the
    // orphan's methods turns a frozen command RED — a cosmetic new Orphan() or a hardcoded value cannot
    // pass). Default-OFF => Guard 4e never entered => the judge is byte-identical.
    'refactor_wired_proof' => (bool) env('ATLAS_LOOP_REFACTOR_WIRED_PROOF', false),
    // §5.6 ORPHAN-WIRING supply lane — when ON, the refiller mints wiring directives from the comprehension
    // model's tested orphans, and the grinder routes them to the orphan-wiring executor. Default-OFF =>
    // no model build, no mint, no route (byte-identical). Co-gated with refactor_wired_proof (the cert) +
    // orphan_wiring_execution_enabled (the live engine route).
    'orphan_wiring_supply_enabled' => (bool) env('ATLAS_LOOP_ORPHAN_WIRING_SUPPLY_ENABLED', false),
    'orphan_wiring_supply_max_per_refill' => (int) env('ATLAS_LOOP_ORPHAN_WIRING_SUPPLY_MAX_PER_REFILL', 2),
    'orphan_wiring_execution_enabled' => (bool) env('ATLAS_LOOP_ORPHAN_WIRING_EXECUTION_ENABLED', false),
    // §2 DOC-GAP supply: the brain originates a capability the canonical docs DEMAND but no symbol
    // provides (red→green feature; FrozenJudge Guard 4 diff_earned certifies; authoring is model-bound,
    // §9-fenced, like orphan-wiring). Default-OFF ⇒ no model build, byte-identical refill.
    'doc_gap_supply_enabled' => (bool) env('ATLAS_LOOP_DOC_GAP_SUPPLY_ENABLED', false),
    'doc_gap_supply_max_per_refill' => (int) env('ATLAS_LOOP_DOC_GAP_SUPPLY_MAX_PER_REFILL', 1),
    'doc_gap_supply_docs_roots' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_LOOP_DOC_GAP_SUPPLY_DOCS_ROOTS', 'docs/engineering-knowledge-base'))))),
    'park_escalation_enabled' => (bool) env('ATLAS_LOOP_PARK_ESCALATION_ENABLED', true),
    'territory_ladder_enabled' => (bool) env('ATLAS_LOOP_TERRITORY_LADDER_ENABLED', true),
    'drift_restart_debounce_enabled' => (bool) env('ATLAS_LOOP_DRIFT_RESTART_DEBOUNCE_ENABLED', true),
    'drift_restart_debounce_window_seconds' => (int) env('ATLAS_LOOP_DRIFT_RESTART_DEBOUNCE_WINDOW_SECONDS', 600),
    'drift_restart_debounce_min_self_merges' => (int) env('ATLAS_LOOP_DRIFT_RESTART_DEBOUNCE_MIN_SELF_MERGES', 1),
    'observability_digest_enabled' => (bool) env('ATLAS_LOOP_OBSERVABILITY_DIGEST_ENABLED', true),

    // C0 · Real-Work Campaign Scorecard — read-only honesty ruler embedded in campaign status. It
    // classifies a campaign's tasks (real bug_fix/feature/verification vs proxy refactor vs cosmetic
    // vs unknown) and emits a conservative claim policy, so a campaign can never call proxy/cosmetic
    // work "real evolution" without evidence. Default ON (zero provider spend, no side effects).
    'real_work_scorecard_enabled' => (bool) env('ATLAS_LOOP_REAL_WORK_SCORECARD_ENABLED', true),

    // L4-1: ranking anti-Goodhart. O score estrutural continua sendo a base, mas ganha
    // boost limitado por impacto real: surface no code graph, evidência de falha e backlog.
    'impact_ranking_enabled' => (bool) env('ATLAS_LOOP_IMPACT_RANKING_ENABLED', true),
    // Discovery lints every candidate before it can be safely ground. Keep the per-file lint bounded
    // and heartbeat-pulsed so cold-start discovery stays observable during a 24h soak.
    'discovery_php_lint_timeout_seconds' => (float) env('ATLAS_LOOP_DISCOVERY_PHP_LINT_TIMEOUT_SECONDS', 20.0),
    // Caller resolution is intentionally fail-open. Keep the per-grep timeout bounded so a cold-start
    // discovery cannot hold the 24h supervisor in "lock alive, no heartbeat, no tasks" limbo.
    'wired_caller_grep_timeout_seconds' => (float) env('ATLAS_LOOP_WIRED_CALLER_GREP_TIMEOUT_SECONDS', 30.0),

    // WIRED targeting (impact upgrade): demote/exclude orphan scaffolding (0 real
    // production callers + 0 failure evidence + 0 backlog reach) so the provider
    // budget hardens code that RUNS. Default ON (deprioritise); hard_exclude OFF.
    'orphan_gate_enabled' => (bool) env('ATLAS_LOOP_ORPHAN_GATE_ENABLED', true),
    'orphan_score_penalty' => (float) env('ATLAS_LOOP_ORPHAN_SCORE_PENALTY', 0.15),
    'orphan_gate_hard_exclude' => (bool) env('ATLAS_LOOP_ORPHAN_GATE_HARD_EXCLUDE', false),

    // SUBSTANTIVE-GRIND (lift NON_TRIVIAL): route the grind to WIRED files that already
    // carry a convention sibling test, frame the objective as the sibling coverage gap,
    // and make a canary-green substantive change the credit. ALL default OFF/fail-open
    // so the running 24/7 loop is byte-identical until the operator flips them.
    'test_gap_targets' => (bool) env('ATLAS_LOOP_TEST_GAP_TARGETS', false),
    'prefer_test_backed_targets' => (bool) env('ATLAS_LOOP_PREFER_TEST_BACKED_TARGETS', false),
    'substantive_tiebreak' => (bool) env('ATLAS_LOOP_SUBSTANTIVE_TIEBREAK', false),
    'test_gap_min_callers' => max(0, (int) env('ATLAS_LOOP_TEST_GAP_MIN_CALLERS', 1)),
    'test_gap_hub_callers' => max(2, (int) env('ATLAS_LOOP_TEST_GAP_HUB_CALLERS', 3)),

    // GOVERNED REFACTOR (Phase 1 within-file). The loop can synthesize a
    // `refactor_reduce_complexity` objective for a high-complexity self-contained file
    // that HAS a real sibling test, and the frozen judge certifies ONLY when the frozen
    // sibling test stays GREEN (behavior preserved — the loop cannot edit it) AND a real
    // AST cyclomatic measure DROPS. ALL FOUR default OFF; with them OFF the judge,
    // discovery and refiller are byte-identical to today (proven by a default-inert
    // frozen test). Each is a deliberate operator flip via .env; a separate soak step
    // turns them on. NEVER weakens never-merge / petreo HarnessGuard / canary / value-gate.
    //
    // Gates the refactor objective synthesizer at AtlasLoopQueueRefiller::generateAndEnqueue.
    'refactor_objectives_enabled' => (bool) env('ATLAS_LOOP_REFACTOR_OBJECTIVES_ENABLED', false),
    // Gates the small (<=0.12) refactor_leverage rank boost in applyImpactRanking;
    // the leverage signal is computed but UNUSED in scoring when OFF (byte-identical order).
    'refactoring_targets_enabled' => (bool) env('ATLAS_LOOP_REFACTORING_TARGETS_ENABLED', false),
    // Gates the judge's complexityEarned proof; when OFF the judge ignores complexity_proof
    // entirely and behaves exactly as today (the petreo TAMPER/SCOPE/RE-PROOF/DIFF-EARNED path).
    'refactor_complexity_proof' => (bool) env('ATLAS_LOOP_REFACTOR_COMPLEXITY_PROOF', false),
    // Gates Phase-2 routing of >=2-file refactor tasks through AtlasLoopObraBridgeService
    // (operator-reviewed, never-merge). Phase 1 is single-file only; this stays OFF until Phase 2.
    'refactor_multi_file_via_obra' => (bool) env('ATLAS_LOOP_REFACTOR_MULTI_FILE_VIA_OBRA', false),

    // FRAMEWORK REFACTOR (heavy, behavior-preserving, framework-reach targets). The loop can
    // emit a `refactor_reduce_complexity` objective for a HIGH-COMPLEXITY framework service that
    // is WIRED (>=1 real production caller) AND has real PHPUnit tests; the framework path runs
    // those REAL tests (behavior preserved) AND the semantic certifier proves an AST max-per-method
    // cyclomatic DROP (file total not increasing) by the judge's OWN measure in the gate workspace
    // — ungameable: behavior by the real tests, complexity by AST, never provider-claimed. ALL
    // default OFF: with the flag OFF the framework branch is byte-identical to today (edge-gap
    // objective only). NEVER weakens never-merge / petreo HarnessGuard / governed door /
    // broader-regression gate. Heavy multi-statement diffs are NOT rejected by any small-diff cap
    // for refactor objectives — the certification measure is complexity drop, not diff size.
    'framework_refactor_enabled' => (bool) env('ATLAS_LOOP_FRAMEWORK_REFACTOR_ENABLED', false),
    // Legacy fallback for framework-reach targets that are NOT eligible for the heavy refactor
    // synthesizer. When ON (default) the refiller emits the old provider-authored edge-gap task.
    // When OFF, it falls through to the RED-generating path instead of queuing a framework task
    // with no executable verification atom. This is the soak/real-work supply gate: no unverifiable
    // framework edge-gap tasks should enter a campaign that is measuring real work.
    'framework_edge_gap_fallback_enabled' => (bool) env('ATLAS_LOOP_FRAMEWORK_EDGE_GAP_FALLBACK_ENABLED', true),
    // Generic provider fallback inside refill(). This legacy path asks a provider to invent a
    // self-contained RED test BEFORE the campaign has a durable task, so a slow executor can freeze
    // the supervisor with targets claimed, refills=0 and no task/heartbeat progress. Keep it ON for
    // byte-identical legacy behavior; turn it OFF for controlled soaks that must measure only
    // deterministic real-supply lanes and keep provider work in the grind phase.
    'generic_provider_fallback_enabled' => (bool) env('ATLAS_LOOP_GENERIC_PROVIDER_FALLBACK_ENABLED', true),
    // Supply-side gate for behavior-preserving refactor lanes. Keep ON for legacy/evolution
    // campaigns; turn OFF for real-work smoke so proxy refactors cannot dominate the campaign
    // scorecard while coverage, bug-fix and feature/verification lanes remain armed.
    'proxy_refactor_supply_enabled' => (bool) env('ATLAS_LOOP_PROXY_REFACTOR_SUPPLY_ENABLED', true),
    // Cert-integrity lock for a future structural (extract-to-new-file) lane: a net-new candidate
    // file has no baseline worst-method, so its complexity win is UNPROVABLE. Default ON fails the
    // complexity verdict closed for any net-new file (blocks "god method relocated intact + cosmetic
    // drop elsewhere = certify"). Byte-identical for all existing single-file/sibling paths (they
    // never emit a net-new file in the census). Set false only to restore the legacy treat-as-no-change.
    'complexity_new_file_fail_closed' => (bool) env('ATLAS_LOOP_COMPLEXITY_NEW_FILE_FAIL_CLOSED', true),
    // Structural-depth (extract-class) lane gate (default OFF). When ON, a structural_proof
    // contract routes the complexity verdict to the per-method-identity gate
    // (structuralComplexityReduced), which supersedes the per-file-max + new-file-lock so a
    // legitimate new class file is provable — under the anti-relocation invariant (a method moved
    // intact earns nothing). OFF -> a structural_proof task falls back to complexityReduced (the
    // new-file-lock rejects the extract-class), so judge + certifier are byte-identical.
    'complexity_method_identity_gate' => (bool) env('ATLAS_LOOP_COMPLEXITY_METHOD_IDENTITY_GATE', false),
    // Characterization flywheel: max grind attempts per coverage gap before backing off. The
    // provider's test output is variable (a gap can certify on retry), so a re-detected gap gets
    // bounded retries (salted dedupe); past this it is provider-hard and the feeder stops hammering.
    'characterization_max_attempts_per_gap' => max(1, (int) env('ATLAS_LOOP_CHARACTERIZATION_MAX_ATTEMPTS_PER_GAP', 3)),
    // Min AST max-per-method cyclomatic for a framework target to be worth a heavy refactor.
    'framework_refactor_min_cyclomatic' => max(1, (int) env('ATLAS_LOOP_FRAMEWORK_REFACTOR_MIN_CYCLOMATIC', 10)),
    // WORK-SUPPLY keystone: discovery resolves real callers only for the top-N by cheap
    // structural score (caller_resolve_cap, cost guard). That starved the heavy-refactor lane —
    // a complex/wired file below that cut never got measured, never earned the promotion that
    // reaches the claim window, so refactor supply drained to ~0 and vanilla flooded. This ALSO
    // measures the top-N most-COMPLEX candidates (cyclomatic >= framework_refactor_min_cyclomatic),
    // breaking the chicken-and-egg without lowering any value gate. 0 => byte-identical legacy.
    'discovery_caller_resolve_cap' => max(1, (int) env('ATLAS_LOOP_DISCOVERY_CALLER_RESOLVE_CAP', 60)),
    'discovery_refactor_resolve_cap' => max(0, (int) env('ATLAS_LOOP_DISCOVERY_REFACTOR_RESOLVE_CAP', 40)),
    // ACDE T2 (supply-rate coupling) — multiplies BOTH discovery resolve caps so candidate SUPPLY scales
    // with scenario fan-out WIDTH (else width starves on too few candidates; supply, not width, is the
    // real bottleneck). Default 1 => byte-identical (60/40). Arm alongside scenario_fanout (e.g. 3 for
    // a width-4 fan-out). A one-time starvation-unblock, not a dynamic controller (see T3, deferred).
    'discovery_supply_widen_factor' => max(1, (int) env('ATLAS_LOOP_DISCOVERY_SUPPLY_WIDEN_FACTOR', 1)),
    // Min real production callers (wired requirement): a refactor only pays back on code that runs.
    'framework_refactor_min_callers' => max(1, (int) env('ATLAS_LOOP_FRAMEWORK_REFACTOR_MIN_CALLERS', 1)),
    // Gates the obra-auto-merge crossing (AtlasLoopObraAutoMergeService): a GENUINELY
    // certified obra branch auto-merges to main WITHOUT operator review, but ONLY after the
    // broader-regression gate passes. DEFAULT FALSE; OFF => obra always stays operator-review.
    'obra_auto_merge_enabled' => (bool) env('ATLAS_LOOP_OBRA_AUTO_MERGE_ENABLED', false),
    // PARK-FIRST MATURITY INTERLOCK (day-2 hardening). Even with the crossing flag ON, an obra
    // may auto-merge ONLY if its derived change CLASS has EARNED autonomy from REAL merge
    // history on the single-source AtlasChangeClassTrustLadder (operator-allowlisted class +
    // proven clean streak). DEFAULT TRUE => a never-proven class (e.g. any `code` obra) PARKS
    // for the operator however green its gates — autonomy is earned, never granted on a first
    // run. The operator may set FALSE for a controlled experiment, but the safe default stands.
    'obra_auto_merge_require_trust' => (bool) env('ATLAS_LOOP_OBRA_AUTO_MERGE_REQUIRE_TRUST', true),

    // OBRA CANDIDATE PRODUCER (AtlasLoopObraClusterDetectorService): connects the live grind
    // loop to the operator-gated big-obra surface. After discovery+enqueue it scans the
    // claimed targets for a WIRED high-leverage HUB (measured callers + complexity + leverage),
    // resolves the hub's REAL production caller FILE PATHS, and parks a >=2-file obra CANDIDATE
    // in the SAME operator-review backlog the architecture proposer uses. PROPOSAL-ONLY: never
    // enqueues a loop task, never calls a provider, never mutates code, never merges, never
    // weakens never-merge / HarnessGuard / the L4-10 gate. DEFAULT-OFF => fully inert. Fail-open.
    'obra_cluster_detection_enabled' => (bool) env('ATLAS_LOOP_OBRA_CLUSTER_DETECTION_ENABLED', false),
    // Min MEASURED production callers for a target to qualify as a refactor-worthy hub.
    'obra_cluster_min_callers' => max(2, (int) env('ATLAS_LOOP_OBRA_CLUSTER_MIN_CALLERS', 3)),
    // Min AST max-per-method cyclomatic for the hub (only complex hubs are worth an obra).
    'obra_cluster_min_cyclomatic' => max(1, (int) env('ATLAS_LOOP_OBRA_CLUSTER_MIN_CYCLOMATIC', 10)),
    // Min refactor_leverage (0.6*callerLeverage + 0.4*complexityLeverage) for the hub.
    'obra_cluster_leverage_floor' => max(0.0, (float) env('ATLAS_LOOP_OBRA_CLUSTER_LEVERAGE_FLOOR', 0.5)),
    // Hard cap on cluster size (hub + callers) so an obra candidate stays reviewable.
    'obra_cluster_max_files' => max(2, (int) env('ATLAS_LOOP_OBRA_CLUSTER_MAX_FILES', 8)),
    // Re-proposal cooldown for the SAME cluster hash (anti-spam; dedup lives in the detector's
    // own durable index, NOT the backlog which mints a fresh id per call).
    'obra_cluster_cooldown_hours' => max(1, (int) env('ATLAS_LOOP_OBRA_CLUSTER_COOLDOWN_HOURS', 168)),
    // Max obra candidates parked per refill cycle (bounds operator-queue growth).
    'obra_cluster_max_candidates_per_cycle' => max(1, (int) env('ATLAS_LOOP_OBRA_CLUSTER_MAX_CANDIDATES_PER_CYCLE', 2)),

    // DECISION work-shape router (AtlasLoopWorkShapeRouter): reasons the highest-leverage work
    // SHAPE per target from the already-stamped discovery signals, replacing the static flag
    // cascade. Load-bearing decision = work_skip (defer a CONFIRMED orphan instead of spending
    // a provider call on dead code). Default ON; fail-open to the cascade. The shape only
    // routes; the synthesizer/generator RED-gates remain the authority.
    'decision_router_enabled' => (bool) env('ATLAS_LOOP_DECISION_ROUTER_ENABLED', true),
    'decision_min_refactor_cyclomatic' => max(1, (int) env('ATLAS_LOOP_DECISION_MIN_REFACTOR_CYCLOMATIC', 10)),
    'decision_leverage_floor' => max(0.0, (float) env('ATLAS_LOOP_DECISION_LEVERAGE_FLOOR', 0.6)),

    // SHAPE VOCABULARY BROADENING — let the deterministic work-shape router (AtlasLoopWorkShapeRouter)
    // NAME the two HEAVIER refactor shapes the refiller already knows how to synthesize, instead of
    // only ever naming the smallest single-file shape:
    //   - extract_class : a WIRED, test-backed hub whose worst-method cyclomatic is well above the
    //     floor (>= extract_class_min_cyclomatic) — routes to synthesizeFrameworkRefactor(extractClass:true),
    //     the 2-file god-method split (target + <Target>Support.php).
    //   - multi_file    : a detected COUPLED CLUSTER (hub + >=1 covered caller) carried on the signals
    //     packet — routes to synthesizeMultiFileRefactor() (the >=2-file cluster refactor).
    // BOTH DEFAULT-OFF: with both flags off the router output is BYTE-IDENTICAL to today (the heavier
    // shapes are simply never named), so the rédea ships inert until the operator arms it. Every
    // downstream RED/structural-cert gate is unchanged — the router only broadens the shape decision.
    'decision_extract_class_shape_enabled' => (bool) env('ATLAS_LOOP_DECISION_EXTRACT_CLASS_SHAPE_ENABLED', false),
    'decision_multi_file_shape_enabled' => (bool) env('ATLAS_LOOP_DECISION_MULTI_FILE_SHAPE_ENABLED', false),
    // PRODUCER (the autonomous high-leverage rédea): AtlasLoopLeverageScorer + objective
    // producer originate the BIGGEST leap per least time from brain signals. Default OFF,
    // fail-open (byte-identical when off). The ambition floor is what keeps it off trivia:
    // a candidate must clear leverage AND a real unblock AND be verifiable, or it is rejected.
    'objective_producer_enabled' => (bool) env('ATLAS_LOOP_OBJECTIVE_PRODUCER_ENABLED', false),
    // Trivia rejection is carried mostly by min_unblock (real breadth) + verifiable; the leverage
    // floor is the secondary filter, calibrated against real files so genuine hubs pass.
    'producer_leverage_floor' => max(0.0, (float) env('ATLAS_LOOP_PRODUCER_LEVERAGE_FLOOR', 0.2)),
    'producer_min_unblock' => max(0.0, (float) env('ATLAS_LOOP_PRODUCER_MIN_UNBLOCK', 0.25)),
    // The expensive brain read (~3s/file) runs only on this many structural finalists per tick.
    'producer_brain_finalists' => max(1, (int) env('ATLAS_LOOP_PRODUCER_BRAIN_FINALISTS', 3)),
    // FEATURE ORIGINATION (the ceiling lift): when ON, the producer may originate a NEW-capability
    // objective (RED-verified) instead of only refactors. Default OFF — refactor-only until armed.
    'producer_feature_origination_enabled' => (bool) env('ATLAS_LOOP_PRODUCER_FEATURE_ORIGINATION_ENABLED', false),
    // MULTI-FILE ORIGINATION: when ON, the producer may originate a multi_file_refactor objective
    // when the winner has production callers, bypassing single-file origination. The objective
    // carries allowed_files spanning the hub path + its callers so the execution lane knows exactly
    // which files to touch. Default OFF — single-file only until armed.
    'multi_file_origination_enabled' => (bool) env('ATLAS_LOOP_MULTI_FILE_ORIGINATION_ENABLED', false),
    // Adversarial critic: if the leverage(ratio)-winner's leap-magnitude (impact×breadth×compounding)
    // is below this fraction of the biggest floor-passer's, the critic promotes the bigger leap.
    'critic_numerator_threshold' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_CRITIC_NUMERATOR_THRESHOLD', 0.6))),
    // PRODUCER-EXCLUSIVE: when the rédea produces a leap, skip the slow per-target generation so
    // refill returns fast and the supervisor reaches the grind phase (one biggest leap per cycle).
    // Default OFF => the per-target lanes are untouched.
    'producer_exclusive' => (bool) env('ATLAS_LOOP_PRODUCER_EXCLUSIVE', false),

    // ════════ VALUE-WIRING (ligar a máquina de valor no caminho vivo) ════════
    // S1 — EV brain na decisão viva. O reorder/EV-rank já existe mas estava atrás de um flag
    // não-registrado (producer_ev_pick_enabled, literal-false). ev_live_decision_enabled (default ON)
    // dirige o EV-rank + o PARK honesto: quando TODO floor-passer é proxy-only (refactor
    // behavior-preserving sem relief de eixo de valor), o producer retorna null (não emite proxy).
    'ev_live_decision_enabled' => (bool) env('ATLAS_LOOP_EV_LIVE_DECISION_ENABLED', true),
    'producer_ev_pick_enabled' => (bool) env('ATLAS_LOOP_PRODUCER_EV_PICK_ENABLED', false),
    'producer_ev_leverage_halfsat' => max(0.1, (float) env('ATLAS_LOOP_PRODUCER_EV_LEVERAGE_HALFSAT', 8.0)),
    // S2 — projeção async live. Em vez de enfileirar task one-shot, o refiller despacha uma projeção
    // (AtlasLoopDeliveryPipeline); um worker roda o ProjectionEngine (frozen) até content-fixpoint,
    // PARKa se não converge, e só enfileira a task COM obrigações tipadas quando converge.
    'projection_stage_enabled' => (bool) env('ATLAS_LOOP_PROJECTION_STAGE_ENABLED', true),
    'projection_drain_per_tick' => max(1, (int) env('ATLAS_LOOP_PROJECTION_DRAIN_PER_TICK', 2)),
    // §3 ARCHITECT PHASE — when ON, the projection worker runs the GROUNDED design↔critique critic
    // (AtlasLoopGroundedProjectionRoles) instead of the scripted raise-then-resolve: the critic raises a
    // consumer_intact obligation for every REAL caller of the target (from the comprehension model's
    // who-calls-who edges), so the projected contract provably protects every real caller and a
    // high-fan-out target PARKS. Default-OFF ⇒ the worker is byte-identical to the scripted roles.
    // §3 ANTI-FARM FLOOR — when ON, a certified proposal must be LOAD-BEARING (revert→red bite-proof) to
    // auto-merge; a cosmetic flip that bites nothing is blocked at the merge boundary. Default-OFF =
    // byte-identical (the 18 auto-merge tests stay green). AtlasLoopAntiFarmFloor, wired in valueGateVerdict.
    'anti_farm_floor_enabled' => (bool) env('ATLAS_LOOP_ANTI_FARM_FLOOR_ENABLED', false),
    // §4 PROVIDER CIRCUIT-BREAKER — when ON, after N consecutive provider-down grinds (no winner, 0
    // scenarios explored) the supervisor pauses the campaign + alerts instead of burning CPU for hours on a
    // dead provider. Default-OFF ⇒ record-only, never pauses (byte-identical). A soak-safety guard.
    'provider_circuit_breaker_enabled' => (bool) env('ATLAS_LOOP_PROVIDER_CIRCUIT_BREAKER_ENABLED', false),
    'provider_circuit_breaker_threshold' => (int) env('ATLAS_LOOP_PROVIDER_CIRCUIT_BREAKER_THRESHOLD', 5),
    // §4 FLEET GOVERNOR — global cap on in-flight grinds across ALL campaigns (soft per-campaign cap by
    // fleet headroom), so a respawn storm / many campaigns never swamp the Mac. <=0 ⇒ unlimited (byte-identical).
    'fleet_global_worker_cap' => (int) env('ATLAS_LOOP_FLEET_GLOBAL_WORKER_CAP', 0),
    'grounded_projection_enabled' => (bool) env('ATLAS_LOOP_GROUNDED_PROJECTION_ENABLED', false),
    // §3 CROSS-MODEL CRITIQUE — when ON (and grounded_projection_enabled), a frontier model proposes
    // ADDITIONAL grounded obligations on top of the deterministic floor (AtlasLoopModelProjectionCritic).
    // The engine's grounding gate rejects any ungrounded one, so it can only DEEPEN the contract, never
    // weaken it; fail-closed (no provider ⇒ the deterministic floor alone). Default-OFF.
    'grounded_projection_model_critic_enabled' => (bool) env('ATLAS_LOOP_GROUNDED_PROJECTION_MODEL_CRITIC_ENABLED', false),
    // §3 LEVERAGE SELECTION — when ON, the brain asks the frontier model to pick the highest-leverage
    // candidate FIRST among the real grounded directives (AtlasLoopLeverageSelector), so a capped refill
    // lands the biggest step. The model can only REORDER the real set (validated in-range), never fabricate
    // work; fail-closed (no provider ⇒ the producer's deterministic order). Default-OFF.
    'leverage_selection_enabled' => (bool) env('ATLAS_LOOP_LEVERAGE_SELECTION_ENABLED', false),
    // §1 ARCHITECT-PHASE GATE — when ON, a supply lane DESIGNS each directive through the architect phase
    // (AtlasLoopArchitectPhaseGate) before minting: it attaches the converged design contract (typed
    // obligations + enforceable consumer_contracts protecting the target's real callers) or SUPPRESSES a
    // directive it cannot converge (pétreo / blast-radius). Default-OFF ⇒ the lane is byte-identical (the
    // work type's own cert still guards behaviour). The path to "design EVERY evolution before it grinds".
    'architect_gate_enabled' => (bool) env('ATLAS_LOOP_ARCHITECT_GATE_ENABLED', false),
    // §4 RECURSIVE SELF-IMPROVEMENT — the brain may PROPOSE improvements to its own NON-pétreo code, but
    // auto-APPLYING a self-edit needs explicit operator policy. Default-OFF ⇒ every self-improvement
    // proposal is PARKED (propose-only); the loop never edits itself unattended. The constitution
    // (AtlasLoopHarnessGuard pétreo) refuses cert-organ edits REGARDLESS of this flag — the gate is the
    // policy lever, never a bypass of the constitution. Max-Goodhart surface: keep OFF until policy is set.
    'recursive_self_improvement_auto_apply' => (bool) env('ATLAS_LOOP_RECURSIVE_SELF_IMPROVEMENT_AUTO_APPLY', false),
    // SEV-1 08/07 — CONSTITUTION GATE no committer escopado: um commit AUTÔNOMO tocando a zona
    // property_gated (AutonomousEvolution/) exige PASS-token constitucional re-verificado contra a
    // árvore pós-apply (verdict de MÁQUINA, fail-closed — a denylist pétrea sozinha falha-ABERTO para
    // arquivo novo). Kill-switch do operador: desligar remove só este verdict extra, nunca a denylist.
    'constitution_gate_enabled' => (bool) env('ATLAS_CONSTITUTION_GATE_ENABLED', true),
    // §5 LEARNING — the architect phase records each projection outcome (converged/parked+reason) per
    // campaign; the ONE safe feedback is skipping the ~8s model rebuild for a target already parked as a
    // pétreo cert organ (permanently off-limits). Blast-radius/non-converged parks are audit-only, never
    // auto-suppressed (that is the #4 Goodhart surface). Default-OFF ⇒ the worker is byte-identical.
    'projection_outcome_learning_enabled' => (bool) env('ATLAS_LOOP_PROJECTION_OUTCOME_LEARNING_ENABLED', false),
    // S3 — supply de bug REAL. O harvester colhe reds DETERMINÍSTICOS (filtra flaky/ambiental via
    // SuiteRedTriageHelper) pra atlas_loop_failure_handles; a discovery estampa o handle no signal do
    // alvo → a bug-fix lane (já ligada) enfileira objective_kind=bug_fix, revert_recheck=true.
    'discovery_failure_handle_stamp_enabled' => (bool) env('ATLAS_LOOP_DISCOVERY_FAILURE_HANDLE_STAMP_ENABLED', false),
    'failure_handle_harvest' => [
        'enabled' => (bool) env('ATLAS_LOOP_FAILURE_HANDLE_HARVEST_ENABLED', false),
        'report_path' => (string) env('ATLAS_LOOP_FAILURE_HANDLE_HARVEST_REPORT_PATH', ''),
    ],
    // PATTERN-REGISTRY (Slice 1) — advisory pattern selection + ExecutionContract on the produced
    // objective. ADVISORY/read-only: the AtlasLoopPatternSelector picks the best SELECTABLE pattern
    // for the originated objective and the AtlasLoopPatternCompiler compiles it into a typed contract,
    // attached to produce()'s return as `pattern` + `execution_contract`. It NEVER gates or reorders
    // origination (fail-open: any hiccup leaves the objective untouched). Default ON because it only
    // ADDS read-only context; flip OFF for byte-identical legacy output.
    'pattern_advisory_enabled' => (bool) env('ATLAS_LOOP_PATTERN_ADVISORY_ENABLED', true),
    // PATTERN-REGISTRY DECISION DRIVER (A0) — the step UP from advisory. When OFF (default) the
    // PatternRegistry/Selector stay read-only: they may ATTACH a pattern + contract AFTER the EV
    // brain/critic already chose the work (the advisory above), preserving today's behaviour exactly.
    // When ON, the AtlasLoopPatternDecisionDriver runs over the floor-passers BEFORE origination and is
    // the DECIDER: it filters/reorders/gates the candidates, REJECTS a cosmetic / negligible-impact /
    // non-finite-impact top candidate and selects the next acceptable one, or emits NO objective when
    // none is acceptable (governed null). It is FAIL-CLOSED (a selector/compile failure stops the cycle
    // rather than producing ungoverned work) — distinct from the fail-OPEN advisory above and NOT a
    // reuse of pattern_advisory_enabled. Default OFF: arm only when supply is ready to feed it.
    'pattern_driver_enabled' => (bool) env('ATLAS_LOOP_PATTERN_DRIVER_ENABLED', false),

    // HONEST CANDIDATE VALUE SIGNAL (A) — gather() stamps proxy / cosmetic / work_value_class / shape /
    // proxy_reasons on every candidate from REAL signals so the DecisionDriver's veto is no longer
    // starved (the driver reads candidate['proxy']/['cosmetic']). A refactor candidate is MATERIAL
    // (real value) only when it has a behaviour anchor (verifiable), real complexity to reduce
    // (cyclomatic >= material_refactor_min_cyclomatic) AND is wired (>=1 caller). Otherwise it is
    // proxy/cosmetic with auditable reasons. This is inert metadata unless pattern_driver_enabled is ON
    // (the advisory path forces cosmetic=false; the scorecard reads payload.objective_kind, not the packet).
    'material_refactor_min_cyclomatic' => (int) env('ATLAS_LOOP_MATERIAL_REFACTOR_MIN_CYCLOMATIC', 12),

    // COVERAGE PORTFOLIO CAP (C) — characterization/coverage work is useful verification but must NEVER
    // monopolize a campaign whose objective is loop evolution (the r24 soak minted 146 characterization
    // proposals). When the gate is ON, a single refill may mint at most coverage_characterization_max_per_refill
    // characterization tasks; further coverage-deficit targets are DEFERRED that cycle so bug / feature /
    // self-improvement / material-refactor lanes keep their slots. Default ON with a small cap.
    'coverage_portfolio_gate_enabled' => (bool) env('ATLAS_LOOP_COVERAGE_PORTFOLIO_GATE_ENABLED', true),
    'coverage_characterization_max_per_refill' => max(0, (int) env('ATLAS_LOOP_COVERAGE_CHARACTERIZATION_MAX_PER_REFILL', 2)),

    // MATERIAL-SUPPLY GATE (D2) — the driver only governs the rédea; the PER-TARGET refactor lanes
    // (extract_class / multi_file / framework / single_file) can still mint behaviour-preserving PROXY
    // refactors (a synthesized refactor that carries NO material proof: no revert_recheck, no
    // red_required, no complexity_proof, no governed self-improvement triple). When ON, such a proxy
    // refactor task is DROPPED before it enters the queue (and the target deferred) — the same honest
    // rule the scorecard uses to classify proxy, applied at supply time so the loop never GRINDS proxy.
    // Coverage/bug/feature tasks are untouched. Default ON; OFF => byte-identical legacy supply.
    'material_supply_gate_enabled' => (bool) env('ATLAS_LOOP_MATERIAL_SUPPLY_GATE_ENABLED', true),
    // Coverage may not exceed the substantive (bug/feature/self-improvement/material-refactor) work
    // minted in the SAME refill — the "rebaixado quando há trabalho de valor" rule (floor of 1 so a
    // pure-coverage cycle is not fully starved). The absolute cap above is the additional hard ceiling.
    'coverage_relative_to_substantive' => (bool) env('ATLAS_LOOP_COVERAGE_RELATIVE_TO_SUBSTANTIVE', true),

    // DECISION ("o quê a seguir") — the UNGAMEABLE next-work priority. When ON, the task
    // priority becomes BAND(shape) + OFFSET(leverage re-resolved FRESH from git/graph) instead
    // of the stored `score*100` scalar, so SHAPE dominates (a confirmed orphan can never out-rank
    // a wired hub) and no forged stored score can buy the next-work slot. Re-resolution runs at
    // ENQUEUE only (never the hot claim path); fail-open to the legacy value WITHIN the band.
    // Default OFF: with it off the enqueue priority is byte-identical to today (score*100).
    'decision_priority_enabled' => (bool) env('ATLAS_LOOP_DECISION_PRIORITY_ENABLED', false),
    // When BOTH fresh leverage reads (grep callers + AST cyclomatic) are unavailable, the offset
    // falls back to the stored score but is CAPPED to this ceiling (far below the band width)
    // so any genuinely measured target sorts strictly above any fail-open-degraded one — a
    // forged/stale stored score can never buy the next-work slot. Anti-gaming floor.
    'decision_unmeasured_offset_ceiling' => max(0, min(999, (int) env('ATLAS_LOOP_DECISION_UNMEASURED_OFFSET_CEILING', 199))),

    // OPTION 3 — operator-gated autonomous MULTI-FILE refactor. When ON (AND
    // refactor_multi_file_via_obra ON, AND obra_cluster_detection_enabled ON), the refiller
    // synthesizes a >=2-file refactor_reduce_complexity task from a detected cluster; the
    // grinder hard-routes it to the obra bridge -> operator-review. NEVER auto-merges (operator
    // approval required for every multi-file merge). DEFAULT-OFF. The CO-GATE is load-bearing:
    // with refactor_multi_file_via_obra OFF the lane is inert (a multi-file task with no obra
    // route is never admissible), so a multi-file diff can never reach main via a single-file canary.
    'multi_file_refactor_objectives_enabled' => (bool) env('ATLAS_LOOP_MULTI_FILE_REFACTOR_OBJECTIVES_ENABLED', false),

    // OPTION 3 · #7 EXECUTION — when ON, the grinder's multi-file refactor lane (already gated on
    // refactor_multi_file_via_obra) EXECUTES the obra itself when no operator L4-10 is supplied:
    // AtlasLoopObraExecutionAdapter runs the real provider on an ISOLATED worktree, certifies, and
    // produces the real L4-10, then routes the certified result to the obra bridge -> PARK for
    // operator review. A provider failure / non-real / non-certified result loops back honestly
    // (never parks). DEFAULT-OFF: with it off the lane only accepts an operator-supplied L4-10
    // (today's behaviour, byte-identical). Auto-merge of the parked obra stays a SEPARATE, default-
    // OFF decision (obra_auto_merge_enabled) — this flag only makes the loop PRODUCE the obra.
    'multi_file_execution_enabled' => (bool) env('ATLAS_LOOP_MULTI_FILE_EXECUTION_ENABLED', false),
    // PATH B (the operator-chosen unblock for BIG refactors): instead of the heavy Obra/L4-10
    // machine, let multi-file refactors run through the PROVEN normal grind. When ON: (1) the
    // refiller escalates a target with cyclomatic >= extract_class_min_cyclomatic to a 2-file
    // extract-class objective (target + a new <Target>Support.php), and (2) the grinder routes
    // multi-file refactors to the normal materializer/explorer/structural-cert (NOT the Obra
    // bridge). Safety: the structural_proof cert (cross-file census + anti-relocation), the frozen
    // sibling test, and allowed_globs (diff bounded to exactly the 2 declared files) gate the merge
    // — no Obra ceremony. Default OFF = byte-identical (single-file in-place reduction only).
    'multi_file_refactor_via_normal_lane' => (bool) env('ATLAS_LOOP_MULTI_FILE_REFACTOR_VIA_NORMAL_LANE', false),
    'extract_class_min_cyclomatic' => max(1, (int) env('ATLAS_LOOP_EXTRACT_CLASS_MIN_CYCLOMATIC', 15)),
    // Live soak backoff: if extract-class tasks are timing out repeatedly in THIS campaign, stop
    // escalating the next refactor candidates to the two-file lane and fall back to the smaller
    // single-file complexity reduction. This preserves real work while avoiding a 24h loop that
    // burns every parallel slot on known-overlarge tasks. Fail-open: DB/read errors disable backoff.
    'extract_class_timeout_backoff_enabled' => (bool) env('ATLAS_LOOP_EXTRACT_CLASS_TIMEOUT_BACKOFF_ENABLED', true),
    'extract_class_timeout_backoff_window_hours' => max(1, (int) env('ATLAS_LOOP_EXTRACT_CLASS_TIMEOUT_BACKOFF_WINDOW_HOURS', 6)),
    'extract_class_timeout_backoff_min_timeouts' => max(1, (int) env('ATLAS_LOOP_EXTRACT_CLASS_TIMEOUT_BACKOFF_MIN_TIMEOUTS', 3)),
    // ADEP keystone — ITERATE-TO-GREEN: after the provider attempt, the LOOP runs the acceptance
    // test and, on red, re-invokes the provider WITH the exact failure until green or budget (the
    // test-fix-retest loop codex/Claude use; the loop's single-shot lane never had it). Default
    // OFF => byte-identical (one invocation + zero-diff retry only). Token cost is no concern;
    // quality is — set the budget high. iterate_to_green_max = max re-invocations per attempt.
    'iterate_to_green_enabled' => (bool) env('ATLAS_LOOP_ITERATE_TO_GREEN_ENABLED', false),
    'iterate_to_green_max' => max(1, (int) env('ATLAS_LOOP_ITERATE_TO_GREEN_MAX', 3)),

    // ACDE Tier-0 #2: when ON, iterate-to-green's green check IS the frozen judge (diff-earned /
    // scope / frozen / complexity), not a raw exit-0 a gamed candidate can satisfy — so the loop
    // re-prompts toward a CERTIFIABLE result and feeds the real rejection reason back. Default OFF:
    // the judge re-proof per iteration roughly doubles per-iteration test cost (revert-recheck runs
    // the suite twice); arm after measuring throughput on the live engine.
    'iterate_against_judge' => (bool) env('ATLAS_LOOP_ITERATE_AGAINST_JUDGE', false),
    // ≥9 QUALITY BAR (AtlasLoopQualityGrader). The certifier always RECORDS the 0-10 grade in the
    // receipt (observability); quality_bar_gate_enabled makes it a GATE (a verified refactor below
    // the bar is refuted). Default OFF => byte-identical (grade computed, never gates). quality_bar
    // is the threshold (operator directive: 9). Raise once the loop reliably clears it.
    'quality_bar_gate_enabled' => (bool) env('ATLAS_LOOP_QUALITY_BAR_GATE_ENABLED', false),
    'quality_bar' => (float) env('ATLAS_LOOP_QUALITY_BAR', 9.0),

    // ITEM10 — FEATURE-LANE ≥9 quality bar + CONFIDENCE CALIBRATION flywheel. The certifier always
    // RECORDS gradeFeature()'s 0-10 score on a non-refactor cert; delivery_bar.armed turns it into a
    // GATE (a feature below the bar is refuted). The auto-merge feeder appends {predicted,correct}
    // samples post-merge; atlas:loop:confidence-calibrate fits the honest arm-threshold. All default
    // OFF => byte-identical. Note: mutation SAMPLING runs regardless of the mutation-gate flag, so a
    // genuinely good feature (behavior preserved + diff-earned + frozen suite kills its sampled
    // mutants) scores ~9.5 and PASSES the 9.0 bar; only an undiff-earned change or a weak frozen suite
    // (mutants survive) scores below 9 and is refused — arming this does NOT universally refuse features.
    'delivery_bar' => [
        'armed' => (bool) env('ATLAS_LOOP_DELIVERY_BAR_ARMED', false),
    ],
    'confidence_calibration' => [
        'enabled' => (bool) env('ATLAS_LOOP_CONFIDENCE_CALIBRATION_ENABLED', false),
        'target_precision' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_CONFIDENCE_CALIBRATION_TARGET', 0.93))),
        'min_samples' => max(1, (int) env('ATLAS_LOOP_CONFIDENCE_CALIBRATION_MIN_SAMPLES', 20)),
    ],
    // LEVER 2 — CALIBRATED delivery confidence. The certifier RECORDS a principled P(correct) over the
    // cert's measurable signals on every proposal (replacing the old hardcoded confidence theatre). The
    // GATE (confidence_gate_enabled) refuses a cert below the threshold — but arming it at a TRUSTWORTHY
    // 0.93 is honest only AFTER the self-calibration loop fits the weights to real outcomes, so it ships
    // OFF (record-only). weights/threshold are config so calibration can refit them without code changes.
    'confidence_gate_enabled' => (bool) env('ATLAS_LOOP_CONFIDENCE_GATE_ENABLED', false),
    'confidence_model' => [
        'threshold' => (float) env('ATLAS_LOOP_CONFIDENCE_THRESHOLD', 0.93),
        'weights' => [], // empty => the model's conservative defaults; the calibration loop overwrites this
    ],
    // NEXT-LEVER 2 — ESCALATION LADDER. When a round fails to certify, escalate to a stronger tier
    // (best_of_n -> repair_from_refutation -> decompose -> escalate_provider) instead of giving up —
    // bounded by certification (the quality bar) and this round budget, NOT a fixed N. The ladder is the
    // pure policy; the grind orchestrator walks it across the existing tiers.
    'escalation_max_rounds' => max(1, (int) env('ATLAS_LOOP_ESCALATION_MAX_ROUNDS', 6)),
    'escalation_thrash_threshold' => max(2, (int) env('ATLAS_LOOP_ESCALATION_THRASH_THRESHOLD', 3)),

    // ACDE Tier-1 #5: arms the autonomous conductor in the grinder — a no-winner best-of-N round
    // escalates STRUCTURALLY (repair->decompose->escalate) via AtlasLoopAutonomousConductor instead
    // of dead-ending, feeding the attempt-ledger forward + thrash-jumping. Default OFF: real provider
    // spend (up to escalation_max_rounds deeper re-runs); arm after measuring conversion + cost.
    'conductor_escalation_enabled' => (bool) env('ATLAS_LOOP_CONDUCTOR_ESCALATION_ENABLED', false),
    // NEXT-LEVER 1 — COMPLETENESS. A goal's checklist of acceptance criteria must be covered (every
    // required criterion satisfied + coverage >= floor) — proves the change did the WHOLE job, not just
    // enough to pass one test. RECORDED always; GATE only when completeness_gate_enabled — default OFF /
    // empty checklist => byte-identical. The criteria-from-goal resolver feeds the checklist + satisfaction.
    'completeness_gate_enabled' => (bool) env('ATLAS_LOOP_COMPLETENESS_GATE_ENABLED', false),
    'completeness_min_coverage' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_COMPLETENESS_MIN_COVERAGE', 1.0))),
    // NEXT-LEVER 3 — INDEPENDENT MULTI-JUDGE CONSENSUS. The certifier RECORDS a consensus assessment over
    // independent verdicts (lens-diverse provider judges when supplied; else the adversarial panel +
    // refuters as correctness judges). GATE only when judge_consensus_gate_enabled — default OFF until the
    // lens-judge panel (dedicated provider judges per lens) feeds diverse verdicts. quorum: policy
    // (unanimous|n_of_m), min_pass, required_lenses, min_distinct_providers (independence).
    'judge_consensus_gate_enabled' => (bool) env('ATLAS_LOOP_JUDGE_CONSENSUS_GATE_ENABLED', false),
    // S216 — arms the S214 source-class independence floor with a REAL second source. A shell command
    // for an INDEPENDENT-engine judge (a DIFFERENT engine than the author); when set, the grinder
    // appends it as a semantic refuter so its verdict enters judge_verdicts stamped source_class=
    // 'external'. Empty (default) => no extra judge spawned => byte-identical, zero provider spend.
    // Arm together with judge_consensus_gate_enabled + min_distinct_source_classes>=2 for real
    // cross-source consensus (the author can no longer BE the only judge).
    'independent_judge_cmd' => (string) env('ATLAS_LOOP_INDEPENDENT_JUDGE_CMD', ''),
    // PHASE 1 (Bloco 2.1 + 3.1) — Arbor-as-engine under governance. Both default-OFF + armed-only:
    // - iterate_to_metric: the grind runs edit->measure->keep-if-better->repeat (Arbor algo) per
    //   scenario when the task carries a held_out block; OFF => the loop's one-shot best-of-N is byte-identical.
    // - held_out_delta_cert: certify() requires the candidate to MOVE the metric on the FROZEN held-out
    //   (test) split; OFF or unarmed => byte-identical (no task carries a held_out block today).
    'iterate_to_metric_enabled' => (bool) env('ATLAS_LOOP_ITERATE_TO_METRIC_ENABLED', false),
    'iterate_to_metric_max_edits' => max(1, (int) env('ATLAS_LOOP_ITERATE_TO_METRIC_MAX_EDITS', 6)),
    'iterate_to_metric_patience' => max(1, (int) env('ATLAS_LOOP_ITERATE_TO_METRIC_PATIENCE', 2)),
    'held_out_delta_cert_enabled' => (bool) env('ATLAS_LOOP_HELD_OUT_DELTA_CERT_ENABLED', false),
    'judge_consensus' => [
        'quorum' => [
            'policy' => (string) env('ATLAS_LOOP_JUDGE_CONSENSUS_POLICY', 'unanimous'),
            'min_distinct_providers' => max(1, (int) env('ATLAS_LOOP_JUDGE_CONSENSUS_MIN_PROVIDERS', 2)),
            // SOURCE-CLASS independence floor: `provider` is free-text (two cert-internal engines look
            // like 2 providers while being self-refereed). source_class is a curated enum
            // (in_process|external); requiring >=2 means a genuine cross-source verdict. Default 1 =>
            // inert/byte-identical until armed (needs a real external judge fed in — see grinder wire).
            'min_distinct_source_classes' => max(0, (int) env('ATLAS_LOOP_JUDGE_CONSENSUS_MIN_SOURCE_CLASSES', 0)),
            'required_lenses' => array_values(array_filter(array_map(
                static fn (string $l): string => trim($l),
                explode(',', (string) env('ATLAS_LOOP_JUDGE_CONSENSUS_REQUIRED_LENSES', 'correctness,completeness')),
            ), static fn (string $l): bool => $l !== '')),
        ],
    ],
    // LEVER 5 — SPEC AMPLIFICATION floor (feature lane). A feature's frozen acceptance test IS its spec;
    // a thin test under-specifies a complex feature (spec-gaming). The gate refuses a feature whose
    // acceptance asserts fewer than this many cases — forcing the spec to be amplified (boundary/error/
    // idempotency) before provider budget is spent. 0 => OFF (byte-identical). Fail-open if no test file.
    'spec_amplification' => [
        'min_assertions' => max(0, (int) env('ATLAS_LOOP_SPEC_MIN_ASSERTIONS', 0)),
        'min_methods' => max(0, (int) env('ATLAS_LOOP_SPEC_MIN_METHODS', 0)),
    ],
    // LEVER 3 — behavioral-equivalence STRENGTH floor. For a refactor, "the sibling test is green" is
    // necessary but weak; this requires the frozen suite to be strong enough to have CAUGHT a behaviour
    // change — a minimum mutation KILL RATIO (killed/sampled) over the exhaustively sampled decision
    // mutants. 0.0 => OFF (byte-identical). Raise toward the >93%-confidence bar once the loop clears it.
    'mutation_kill_ratio_floor' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_MUTATION_KILL_RATIO_FLOOR', 0.0))),
    // LEVER 5 — provider routing ("MiniMax when you should, codex as you should"). Cheap classes
    // go to the cheap tier (MiniMax-M3), load-bearing classes stay on the strong default. Default
    // OFF => byte-identical. Fail-safe: a cheap provider that is not configured falls back to the
    // default. Turn ON once the cheap provider key (minimax_m27) is wired in the operator's setup.
    'provider_routing' => [
        'enabled' => (bool) env('ATLAS_LOOP_PROVIDER_ROUTING_ENABLED', false),
        'cheap_provider' => (string) env('ATLAS_LOOP_PROVIDER_ROUTING_CHEAP_PROVIDER', 'minimax_m27'),
        'cheap_model' => (string) env('ATLAS_LOOP_PROVIDER_ROUTING_CHEAP_MODEL', 'MiniMax-M3'),
        'cheap_classes' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_LOOP_PROVIDER_ROUTING_CHEAP_CLASSES', 'characterization_test,edge_fix'))))),
    ],

    // OBRA REPAIR (eixo-3, the autonomy multiplier) — when ON, a node whose DELIVERY fails
    // certification is RETRIED with label-only failure feedback (bounded), instead of halting on
    // the first failure. Raises per-node success, which compounds for a many-node obra. Repair
    // changes only the COUNT of attempts, never the bar (every retry faces the same gate stack).
    // DEFAULT-OFF: with it off the delivery runs exactly once (byte-identical to today). Two caps
    // bound spend: per-node (3) and per-obra (8); a byte-identical re-edit stops early (no-progress).
    'obra_repair_enabled' => (bool) env('ATLAS_LOOP_OBRA_REPAIR_ENABLED', false),
    'obra_repair_max_attempts_per_node' => max(1, (int) env('ATLAS_LOOP_OBRA_REPAIR_MAX_ATTEMPTS_PER_NODE', 3)),
    'obra_repair_max_attempts_per_obra' => max(1, (int) env('ATLAS_LOOP_OBRA_REPAIR_MAX_ATTEMPTS_PER_OBRA', 8)),
    'multi_file_refactor_timeout_seconds' => max(60, (int) env('ATLAS_LOOP_MULTI_FILE_REFACTOR_TIMEOUT_SECONDS', 600)),

    // ITEM8 — PLANNING PHASE ahead of best-of-N. A qualifying task (>=2 allowed_files OR objective_kind
    // refactor_/feature_) runs IntentSpecCompiler (NL goal -> falsifiable acceptance) then
    // ObraDecompositionPlanner (DAG validated by PlanReadinessGate, create-class node at seq 0)
    // INSTEAD of the one-node-per-existing-file buildPlan. Default-OFF => byte-identical (buildPlan
    // runs exactly as today). The production provider seam is fail-open: until a hermes_cli spec-only
    // call is wired it returns no spec/plan and the adapter falls back to buildPlan (so even ON is safe).
    'planning_enabled' => (bool) env('ATLAS_LOOP_PLANNING_ENABLED', false),
    'planning_spec_max_attempts' => max(1, (int) env('ATLAS_LOOP_PLANNING_SPEC_MAX_ATTEMPTS', 3)),
    'planning_plan_max_attempts' => max(1, (int) env('ATLAS_LOOP_PLANNING_PLAN_MAX_ATTEMPTS', 3)),

    // ARBOR-GRAFT — the idea-tree compounding substrate (ADVISORY: walled off from every cert/merge/
    // trust class by AtlasLoopAdvisoryFirewallTest). These were read by the code with a hard-coded
    // `false` default but had NO config entry, so the .env vars could never arm them. Declared here so
    // the operator can flip them via .env. Each is default-OFF => byte-identical until armed.
    'idea_tree_enabled' => (bool) env('ATLAS_LOOP_IDEA_TREE_ENABLED', false),
    'constraints_block_enabled' => (bool) env('ATLAS_LOOP_CONSTRAINTS_BLOCK_ENABLED', false),
    'insight_backprop_enabled' => (bool) env('ATLAS_LOOP_INSIGHT_BACKPROP_ENABLED', false),
    'select_adjuster_enabled' => (bool) env('ATLAS_LOOP_SELECT_ADJUSTER_ENABLED', false),
    'select_adjuster_max_penalty_fraction' => (float) env('ATLAS_LOOP_SELECT_ADJUSTER_MAX_PENALTY_FRACTION', 0.5),
    // ARBOR-GRAFT #2 — failure-driven supply: a metric-miss fans out N orthogonal alternative-direction
    // siblings (advisory tree nodes that re-enter the SAME gates). Two-flag AND with idea_tree_enabled.
    'failure_supply_enabled' => (bool) env('ATLAS_LOOP_FAILURE_SUPPLY_ENABLED', false),
    'failure_supply_frames' => max(1, (int) env('ATLAS_LOOP_FAILURE_SUPPLY_FRAMES', 3)),
    'failure_supply_max_depth' => max(0, (int) env('ATLAS_LOOP_FAILURE_SUPPLY_MAX_DEPTH', 1)),
    // ACDE #7 — cross-node consumer-cert by DEFAULT for a multi-file (>=2-node) obra. The bigger the obra,
    // the more cross-node edges a node can silently break, so derive one criterion per cross-node consumer
    // contract for EVERY >=2-file net diff (not only when the global completeness flag is on), and fall the
    // Code-Intelligence workspace back to the obra repo when the envelope omits it (so contracts POPULATE
    // instead of fail-open-empty). Default-OFF (byte-identical; measure merge-rate before flipping);
    // fail-OPEN (an unresolvable contract narrows coverage, never false-rejects).
    'obra_cross_node_cert_default' => (bool) env('ATLAS_LOOP_OBRA_CROSS_NODE_CERT_DEFAULT', false),
    // GAP-2 (24h endurance) — reap LEAKED atlas-loop-scn-* code-symbol rows at supervisor boot. A SIGKILL'd
    // materialization leaves indexed symbol rows behind forever (the ~11.9M-row session-bootstrap OOM).
    // Default-OFF (byte-identical); arm for a long soak. older_than = safety window so a live scenario's
    // freshly-indexed symbols are never touched (no grind runs longer than ~50min).
    'symbol_gc_on_boot' => (bool) env('ATLAS_LOOP_SYMBOL_GC_ON_BOOT', false),
    'symbol_gc_older_than_seconds' => max(0, (int) env('ATLAS_LOOP_SYMBOL_GC_OLDER_THAN_SECONDS', 7200)),
    // CYCLE CONTRACT (operator mandate) — refuse to launch a campaign whose base_workspace is more than N
    // commits behind main (the 579-atras incident). Default-OFF (byte-identical); base=main is 0 behind so
    // the soak always passes. AtlasLoopCycleGitContract::commitsBehindMain powers the check.
    'base_staleness_guard_enabled' => (bool) env('ATLAS_LOOP_BASE_STALENESS_GUARD_ENABLED', false),
    'base_staleness_main_ref' => (string) env('ATLAS_LOOP_BASE_STALENESS_MAIN_REF', 'main'),
    'base_staleness_max_commits_behind' => max(0, (int) env('ATLAS_LOOP_BASE_STALENESS_MAX_COMMITS_BEHIND', 50)),
    // ACDE #8 — merge-boundary CONTRACT-SWAP guard: the reprove asserts the persisted acceptance contract
    // still hashes to the FROZEN fingerprint stamped at grind time. Default-OFF (byte-identical); arm only
    // after confirming real certified proposals hash-match (a false mismatch would fail-close every merge).
    'reprove_hash_assert_enabled' => (bool) env('ATLAS_LOOP_REPROVE_HASH_ASSERT_ENABLED', false),

    // ACDE Leap 2 — HUMAN-FROZEN DECOMPOSITION BOUNDARY-ORACLE. Imports the proven single-target
    // moat (a human-frozen bar the model cannot author) into the DECOMPOSITION layer. With the flag
    // ON AND a per-objective fixture frozen/obra-decompositions/<goal-hash>.json present, the readiness
    // gate adds a DETERMINISTIC SUPERSET check: the generated DAG's node target_areas + create-class
    // set must SUPERSET the human-named required boundaries, else REPLAN with the missing-seam reasons.
    // OFF, or no oracle for the goal => degrades to the structural-only gate (byte-identical to today).
    // The oracle dir is overridable so tests can point it at a temp dir; default ships EMPTY (no
    // false-reject — operators add boundary fixtures per objective). No LLM plan-judge: correlated
    // weak-model self-grading is the Goodhart this moat forbids — the bar is named by a human only.
    'decomposition_oracle_enabled' => (bool) env('ATLAS_LOOP_DECOMPOSITION_ORACLE_ENABLED', false),
    'decomposition_oracle_dir' => env('ATLAS_LOOP_DECOMPOSITION_ORACLE_DIR', base_path('frozen/obra-decompositions')),

    // ACDE Leap 3 — OBRA NET-DIFF FULL CERTIFICATION. The single-target anti-gaming stack
    // (behavioral-equivalence floor, overfit-constant-return probe, diff-earned, mutation-kill-ratio,
    // completeness, cross-file consumer contracts) runs ONLY inside certify() on a single dirty tree —
    // the obra path never calls it, so a multi-file obra is anti-gaming WEAKER than a one-file change.
    // With this flag ON, the ASSEMBLED obra net diff (base_head..branch, replayed into a base_head
    // worktree) is routed through the FULL certify() against the obra's HUMAN-FROZEN payload.acceptance
    // (never the model spec). Catches "independently-green steps that conflict once assembled" — a node
    // that silently breaks a sibling's frozen command turns the whole obra RED. OFF (default) => the obra
    // path is byte-identical to today (certifyAggregateDrop / structural lane only — never called here).
    'obra_full_cert_enabled' => (bool) env('ATLAS_LOOP_OBRA_FULL_CERT_ENABLED', false),
    // Sub-gate: force completeness_gate on the obra acceptance so certify()'s resolver derives one
    // criterion per command + per cross-node consumer contract and RE-RUNS each on the net diff. Inert
    // unless obra_full_cert_enabled is also ON; empty-derivable checklist => byte-identical (fail-open).
    'obra_completeness_gate_enabled' => (bool) env('ATLAS_LOOP_OBRA_COMPLETENESS_GATE_ENABLED', false),

    // ACDE Leap 5 — DECOMPOSITION OUTCOME LEDGER + shape-prior REPLAN band. The loop compounds
    // decomposition competence: a durable corpus records one row per EXECUTED obra (structural plan
    // fingerprint -> real terminal outcome from the Leaps 2-3 certifier envelope / post-merge canary),
    // and the readiness gate consults a Wilson lower-bound certified-rate per shape. corpus_enabled arms
    // the RECORDER (append-only telemetry; no gate). shape_prior_gate_enabled arms the ADVISORY band: a
    // shape whose certified-rate lower-bound is below target with >= min_samples appends
    // 'shape_historically_thrashes' => REPLAN (cheap). Both default OFF => recorder no-op + prior never
    // consulted => byte-identical. Cold/thin corpus (n<min_samples => UNKNOWN) never blocks a novel shape.
    'decomposition_corpus_enabled' => (bool) env('ATLAS_LOOP_DECOMPOSITION_CORPUS_ENABLED', false),
    'shape_prior_gate_enabled' => (bool) env('ATLAS_LOOP_SHAPE_PRIOR_GATE_ENABLED', false),
    'shape_prior_min_samples' => max(1, (int) env('ATLAS_LOOP_SHAPE_PRIOR_MIN_SAMPLES', 8)),
    'shape_prior_target_rate' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_SHAPE_PRIOR_TARGET_RATE', 0.5))),

    // ACDE Leap 6 (design-judgement ceiling) — HUMAN-FROZEN NODE-INTERFACE CONTRACT. The boundary-oracle
    // anchors WHICH files are separate nodes; this anchors the required ABSTRACTION inside them. With the
    // flag ON AND a per-objective fixture frozen/obra-interfaces/<goal-hash>.json present, the assembled
    // obra net diff is replayed and each contracted file's REAL AST surface (nikic/php-parser — decorrelated
    // from the provider LLM) must honour the frozen contract: required public methods present, required
    // implements/extends satisfied, forbidden imports absent (dependency-direction / anti-inversion). A
    // violation refuses the obra. OFF, or no contract for the goal => no interface check (byte-identical).
    // No LLM design-judge: the bar is human-authored + the check is a deterministic AST census (ungameable).
    'interface_contract_enabled' => (bool) env('ATLAS_LOOP_INTERFACE_CONTRACT_ENABLED', false),
    'interface_contract_dir' => env('ATLAS_LOOP_INTERFACE_CONTRACT_DIR', base_path('frozen/obra-interfaces')),
    // ACDE Leap 6 plan-time half — the readiness gate consults the SAME interface contract's seam-to-seam
    // edge rules (must_depend_on / forbidden_depend_on) and REPLANS a DAG with a missing or inverted edge,
    // feeding the planner its first machine design-steering gap. OFF / no contract => byte-identical. This
    // is STEERING (the model can declare a clean edge then write a contaminated file — caught only by the
    // as-built AST cert above); arm both for the full design-judgement moat.
    'node_interface_plan_gate_enabled' => (bool) env('ATLAS_LOOP_NODE_INTERFACE_PLAN_GATE_ENABLED', false),

    // ACDE Leap 7 (spec/index-completeness ceiling) — CHANGED-PUBLIC-SYMBOL COVERAGE CENSUS. Closes the
    // fail-open hole where a changed PUBLIC symbol with no frozen command + no wired consumer emits ZERO
    // criteria and certifies silently. With the flag ON, the assembled obra net diff is replayed and every
    // public method DECLARED in the diff's added lines must be NAMED (whole-word) in the coverage corpus —
    // the source of the test files the obra's frozen acceptance commands run; an uncovered new public
    // symbol refuses the obra (refuse-until-named, anchored on AST + the literal test corpus). OFF =>
    // byte-identical. HONEST: name-reference is necessary not sufficient (a thin test naming the symbol
    // passes — true sufficiency needs a mutation/coverage anchor); container-string/reflection sites are
    // RECORDED as an audit signal, never gated.
    'changed_symbol_census_enabled' => (bool) env('ATLAS_LOOP_CHANGED_SYMBOL_CENSUS_ENABLED', false),
    // ACDE lever #3 — the SAME census on the LIVE Path B cert (not just the planning-OFF obra adapter
    // above, which has zero attempt-#1 reach). When ON, AtlasLoopSemanticImplementationCertifier::certify()
    // refuses any new PUBLIC method in the diff's added lines that is NOT named in the frozen acceptance's
    // coverage corpus — the deterministic clamp on the weak engine's WRONG-BUT-GREEN-with-un-exercised-
    // surface gaming. Necessary-not-sufficient (a thin naming test passes) so it COMPOSES with the
    // mutation kill-ratio floor, never replaces it. OFF => byte-identical; a refute only appends a reason.
    'changed_symbol_census_path_b_enabled' => (bool) env('ATLAS_LOOP_CHANGED_SYMBOL_CENSUS_PATH_B_ENABLED', false),
    // ACDE lever #2b — run the BroaderRegressionGate's affected-module suites on ./vendor/bin/phpunit
    // instead of `artisan test` (the latter's autoloader-redeclare exit-255 hazard would spuriously RED a
    // whole-directory run). Default OFF => `artisan test` => byte-identical for the gate's existing (obra)
    // consumer + its tests; arm it together with broader_regression_gate_live.
    'broader_regression_gate_phpunit' => (bool) env('ATLAS_LOOP_BROADER_REGRESSION_GATE_PHPUNIT', false),
    // ACDE lever #7 — per-TARGET_PATH hopeless skip. The strategy bandit buckets by target TYPE and so
    // can never say "THIS file went N attempts with 0 certs — stop re-rolling it." When ON, the grinder
    // short-circuits a target with >= per_target_skip_min_attempts REAL attempts (provider-invoked,
    // non-trivial tokens) and ZERO certifications to a terminal honest refusal (completeTask success=false,
    // never releaseClaim) BEFORE best-of-N burns. Attacks the measured "targets already-clean files"
    // waste. A skip is itself a DQS refusal (=defect): it produces no fake success, it frees budget.
    // Default OFF => verdict always 'open' (no extra query) => byte-identical.
    'per_target_skip_enabled' => (bool) env('ATLAS_LOOP_PER_TARGET_SKIP_ENABLED', false),
    'per_target_skip_min_attempts' => max(1, (int) env('ATLAS_LOOP_PER_TARGET_SKIP_MIN_ATTEMPTS', 6)),
    // ACDE lever #6 — SEQUENCED single-method extract decompose. The weak engine cannot one-shot a god-
    // class; when ON, the framework-refactor synthesizer tags each in-place worst-method reduction as one
    // STEP of a bounded extract sequence (AtlasLoopExtractSequencePlanner). Each grind wave re-discovers
    // the still-complex file and pins its CURRENT worst method, so the class is decomposed worst-first
    // across waves — the composition of certified single-method reductions IS the big delivery, all
    // through the proven Path B cert (never the blocking obra-DAG). tractable_cyclomatic is the decompose
    // target (the chain ends when the worst method drops below it); max_steps bounds the projected plan.
    // Default OFF => no sequence tag => byte-identical.
    'extract_sequence_enabled' => (bool) env('ATLAS_LOOP_EXTRACT_SEQUENCE_ENABLED', false),
    'extract_sequence_tractable_cyclomatic' => max(1, (int) env('ATLAS_LOOP_EXTRACT_SEQUENCE_TRACTABLE_CYCLOMATIC', 10)),
    'extract_sequence_max_steps' => max(1, (int) env('ATLAS_LOOP_EXTRACT_SEQUENCE_MAX_STEPS', 6)),
    // ACDE R3 — budgeted cross-file extract SEQUENCE: when the extract-CLASS branch runs, spin out a CHAIN
    // of distinct Support / Support2 / Support3 classes across waves (pick the lowest step whose file does
    // not yet exist), decomposing a god-class into cohesive classes instead of colliding on one name. The
    // chain is bounded by max_steps; once exhausted it falls through to in-place reduction. Default OFF =>
    // step 1 => the historical single `<Target>Support.php` => byte-identical.
    'extract_class_sequence_enabled' => (bool) env('ATLAS_LOOP_EXTRACT_CLASS_SEQUENCE_ENABLED', false),
    'extract_class_sequence_max_steps' => max(1, (int) env('ATLAS_LOOP_EXTRACT_CLASS_SEQUENCE_MAX_STEPS', 3)),
    // ACDE F2 — carry the feature COMPLETENESS CHECKLIST (one falsifiable criterion per verification atom)
    // on the compiled intent-verifier packet, so a delivery dossier reports per-criterion completeness
    // instead of one opaque green bit. Pure restatement of the atoms the verifier already enforces (no
    // self-grading); the verifier_hash is computed over atoms/acceptance/test_content, never the whole
    // packet, so the key never shifts cert. Default OFF => key absent => byte-identical.
    'feature_completeness_checklist_enabled' => (bool) env('ATLAS_LOOP_FEATURE_COMPLETENESS_CHECKLIST_ENABLED', false),
    // ACDE F8 (honest, non-Goodhart form) — paraphrase audit over the HUMAN-frozen atoms: flag near-
    // duplicate criterion pairs as a read-only operator advisory. NEVER infers/authors/weakens/grades an
    // atom (the literal inferred-atom version is canon-forbidden). Default OFF => key absent => byte-identical.
    'atom_paraphrase_audit_enabled' => (bool) env('ATLAS_LOOP_ATOM_PARAPHRASE_AUDIT_ENABLED', false),
    'atom_paraphrase_audit_threshold' => (float) env('ATLAS_LOOP_ATOM_PARAPHRASE_AUDIT_THRESHOLD', 0.85),
    // ACDE F3 — the per-delivery HMAC-signed dossier (feature-outcome ledger). recent() reads the merged
    // deliveries (atlas_loop_proposals) and emits signed dossiers carrying the D2 dimensions + F2 checklist;
    // no new table, no merge-path write. Substrate that compounds when origination (O2) reads it back.
    // Default OFF => empty => byte-identical. dossier_hmac_secret keys the tamper-evidence signature; the
    // built-in default keeps it verifiable within the box (override via env for cross-process trust).
    'delivery_dossier_enabled' => (bool) env('ATLAS_LOOP_DELIVERY_DOSSIER_ENABLED', false),
    'dossier_hmac_secret' => (string) env('ATLAS_LOOP_DOSSIER_HMAC_SECRET', ''),
    // ACDE F1 — carry the SEQUENCED-FEATURE plan on the compiled verifier packet: one huge feature's human-
    // frozen atoms partitioned into an ordered chain of <= max_step_atoms-sized steps, each step's frozen
    // sub-acceptance being exactly its atom subset (never a re-authored bar). Lets the loop build a big
    // feature incrementally. Default OFF => key absent => byte-identical.
    'feature_sequence_enabled' => (bool) env('ATLAS_LOOP_FEATURE_SEQUENCE_ENABLED', false),
    'feature_sequence_max_step_atoms' => max(1, (int) env('ATLAS_LOOP_FEATURE_SEQUENCE_MAX_STEP_ATOMS', 2)),
    // ACDE F4 — close the verified orphan: attach the EXECUTABLE walk of the F1 feature-sequence plan
    // (per-step active sub-acceptance atoms + prior steps held as regression) so the loop can grind a big
    // feature incrementally. Requires feature_sequence_enabled. Default OFF => no feature_sequence_steps
    // key => byte-identical (and never touches verifier_hash).
    'feature_sequence_walk_enabled' => (bool) env('ATLAS_LOOP_FEATURE_SEQUENCE_WALK_ENABLED', false),
    // ACDE B4b — on a certified+merged delivery, record the PROVEN provider-safe contract (changed public
    // symbol NAMES + consumer-set via blast-radius + machine-resolved D2 dimensions + deterministic
    // confidence) into atlas_loop_delivery_contracts — the brain-feedback write-end the B3 read-back uses.
    // Best-effort post-commit, wrapped + self-gated: the merge NEVER depends on it. Default OFF => the
    // recorder is a no-op => the merge path is byte-identical (AtlasLoopAutoMergeServiceTest stays green).
    'delivery_brain_feedback_enabled' => (bool) env('ATLAS_LOOP_DELIVERY_BRAIN_FEEDBACK_ENABLED', false),
    // ACDE X2 — deterministic parse-gate at the provider edit-apply site. When armed, a full-file .php block
    // that does not parse (nikic, in-process) is REJECTED before it is written, so a weak engine's broken
    // rewrite never poisons the scenario workspace (the fatal-autoload → diff-0 → certifies-nothing trap).
    // Default OFF => the check is skipped => writes are byte-identical to today.
    'parse_gate_enabled' => (bool) env('ATLAS_LOOP_PARSE_GATE_ENABLED', false),
    // ACDE QA1 — widen the mutation kill vocabulary with three extra deterministic decision operators
    // (exception_throw_noop / null_coalesce_null / early_return_delete). Pure static transforms, single-
    // sourced in AtlasLoopMutationOperators::map so the gate + characterization verifier agree. Default OFF
    // => the operator map is identical => byte-identical.
    'extra_mutation_operators_enabled' => (bool) env('ATLAS_LOOP_EXTRA_MUTATION_OPERATORS_ENABLED', false),
    // ACDE RF2+RF4 — per-operator kill VECTOR. The refactor mutation gate certifies over the FULL set of
    // killed decision mutants (mutants_sampled = real count) and attaches operator_kill_vector, instead of
    // the single remembered kill (mutants_sampled=1). This is the real denominator QA2's per-family floor
    // needs (a floor on a 1-mutant receipt is theater). Default OFF => certify over the single kill =>
    // receipt_hash byte-identical (the hash is over status/certified/blockers/sampled/killed/survived).
    'mutation_per_operator_vector_enabled' => (bool) env('ATLAS_LOOP_MUTATION_PER_OPERATOR_VECTOR', false),
    // ACDE QA2 — EXHAUSTIVE decision probing. The single-mutant lane certifies on the FIRST killed decision,
    // so a 2nd/3rd added decision line the frozen test does NOT cover slips through uncovered. When armed the
    // gate probes EVERY added decision line per target (same drift-guard + comment skip); a survivor anywhere
    // rejects. Default OFF => one decision per target (first-only) => byte-identical to the proven lane.
    'exhaustive_decision_probing_enabled' => (bool) env('ATLAS_LOOP_EXHAUSTIVE_DECISION_PROBING_ENABLED', false),
    // ACDE QA5 — coverage-union test selection. The broader-regression gate unions the learned
    // cross-module coverage edges (source_path -> test_path, from real coverage runs) onto its static
    // subtree-map selection, so a suite in another module that exercises a changed class is also run.
    // Purely ADDITIVE (never removes a suite). Default OFF => the ledger is never consulted => the
    // gate selects exactly the static map + siblings (byte-identical).
    'coverage_union_test_selection_enabled' => (bool) env('ATLAS_LOOP_COVERAGE_UNION_TEST_SELECTION_ENABLED', false),
    // ACDE U5 — operator CLARIFICATION QUEUE. When the structured planner abstains (vague goal, un-ready
    // spec, ill-formed DAG) and would silently fall back to the dumb one-shot, persist the abstention as
    // a pending clarification request (de-duped on goal_fingerprint+reason) so the loop ASKS instead of
    // guessing. Independently gated from plan_abstention_visible (log). Default OFF => no row enqueued =>
    // byte-identical planning fallback.
    'clarification_queue_enabled' => (bool) env('ATLAS_LOOP_CLARIFICATION_QUEUE_ENABLED', false),
    // ACDE U6 — clarification ANSWER CACHE. Before screening/planning, fold a cached operator answer for
    // the SAME goal (U5 queue, status=answered) back into the goal so the loop never re-asks what was
    // already clarified and plans with the missing anchor in hand. Default OFF => goal unchanged =>
    // byte-identical.
    'clarification_cache_enabled' => (bool) env('ATLAS_LOOP_CLARIFICATION_CACHE_ENABLED', false),
    // ACDE U7 — clarification ROUTING. A deterministic router (reason + family + recurrence) stamps each
    // queued clarification with a surface (operator_inbox | operator_urgent) and priority (low|normal|
    // high), so the operator's inbox sorts/routes instead of treating every abstention the same. Default
    // OFF => the router never runs => surface/priority stay NULL => byte-identical queue.
    'clarification_routing_enabled' => (bool) env('ATLAS_LOOP_CLARIFICATION_ROUTING_ENABLED', false),
    // ACDE DC4 — change-class landing prior (objective_kind axis). A change CLASS that empirically never
    // certifies on this engine abstains-and-asks at planning time instead of grinding another hopeless
    // obra. Reads the existing decomposition-outcomes ledger by normalized change-class (no new table).
    // Default OFF / thin corpus => no abstention => byte-identical.
    'change_class_prior_enabled' => (bool) env('ATLAS_LOOP_CHANGE_CLASS_PRIOR_ENABLED', false),
    'change_class_prior_min_samples' => (int) env('ATLAS_LOOP_CHANGE_CLASS_PRIOR_MIN_SAMPLES', 8),
    'change_class_prior_floor_rate' => (float) env('ATLAS_LOOP_CHANGE_CLASS_PRIOR_FLOOR_RATE', 0.15),
    'change_class_prior_window_hours' => (int) env('ATLAS_LOOP_CHANGE_CLASS_PRIOR_WINDOW_HOURS', 720),
    // ACDE DC6 — thin-prior max-uncertainty abstain-and-ask. PRE-hopeless: a change-class with some (but
    // not yet enough) evidence that already leans below the floor asks the operator before more budget is
    // burned. Independently armed from DC4's hopeless gate; composes with the U5 queue. Default OFF =>
    // byte-identical.
    'change_class_thin_prior_ask_enabled' => (bool) env('ATLAS_LOOP_CHANGE_CLASS_THIN_PRIOR_ASK_ENABLED', false),
    // ACDE DC7 — de-orphan the HeavyWorkSelector into the live refiller: rank the detected obra-cluster
    // candidates by proven-leap (measured leverage evidence + per-class accept stats) so the operator's
    // review surfaces the highest-value-proven cluster first. Read-only ranking over already-parked
    // candidates. Default OFF => no ranking key on the refill envelope => byte-identical.
    'obra_heavy_ranking_enabled' => (bool) env('ATLAS_LOOP_OBRA_HEAVY_RANKING_ENABLED', false),
    // ACDE MF2 — hub-first multi-file conversion: when a coupled cluster is detected, route the anchored HUB
    // through the proven single-file refactor lane (its own complexity proof) instead of the all-or-nothing
    // N-file conjunction that drops ~17% of conversions. Only under the known-shape guard (covered>=2, hub
    // anchored). Default OFF => the multi-file conjunction stands => byte-identical.
    'multi_file_hub_first_enabled' => (bool) env('ATLAS_LOOP_MULTI_FILE_HUB_FIRST_ENABLED', false),
    // ACDE MF5 — cluster-framing compounding degrade: a cluster framing that has thrashed (>=4 executed
    // attempts, <30% certified-rate in the decomposition corpus) auto-degrades to hub-only. Double-gated
    // with decomposition_corpus_enabled (history is empty without it). Default OFF => no degrade =>
    // byte-identical.
    'cluster_framing_degrade_enabled' => (bool) env('ATLAS_LOOP_CLUSTER_FRAMING_DEGRADE_ENABLED', false),
    // ACDE DC5 — compound the strategy-bandit UCB key with the work-class (type|tier|work-class) so a
    // strategy is not averaged across different work-classes of the same type+tier. Default OFF => bare
    // bucket => byte-identical. Dilution caveat: finer keys = fewer samples/cell (the bandit's own
    // min-attempts completion gate already guards against acting on thin cells).
    'bandit_compound_workclass_key_enabled' => (bool) env('ATLAS_LOOP_BANDIT_COMPOUND_WORKCLASS_KEY_ENABLED', false),
    // ACDE U4 — deterministic vagueness pre-screen at the planner goal-ingest boundary: a goal with NO
    // concrete anchor (no path/symbol/quoted-id/member-ref) skips the expensive structured planner (falls
    // back to buildPlan) and surfaces the abstention. Conservative (only zero-anchor goals). Default OFF =>
    // no screen => byte-identical.
    'vagueness_prescreen_enabled' => (bool) env('ATLAS_LOOP_VAGUENESS_PRESCREEN_ENABLED', false),
    // ACDE U2 — red-REASON discriminator. AtlasEvolutionTaskGenerator::isRed accepts ANY non-zero exit as a
    // real RED task, so a weak engine's structurally-broken test (does not parse / wrong require path) is
    // mistaken for genuine behavioral work. When armed, a generated RED must additionally be BEHAVIORAL
    // (deterministic: parses + exercises the target + fails without a load-time structural signature).
    // Default OFF => the gate never runs => task generation is byte-identical.
    'red_reason_gate_enabled' => (bool) env('ATLAS_LOOP_RED_REASON_GATE_ENABLED', false),
    // ACDE U1 — N-sampled comprehension: draw up to N independent generation readings and keep the FIRST
    // that yields a genuine verified-RED task (behavioral when U2 is armed), so a weak engine's misread
    // does not cost the whole task. Width raises HONEST task yield without lowering the bar. Default 1 =>
    // one pass => byte-identical. Each extra sample is one extra provider generation call (cost-bounded by N).
    'comprehension_samples' => max(1, (int) env('ATLAS_LOOP_COMPREHENSION_SAMPLES', 1)),
    // ACDE U3 — sample-N objective DIVERGENCE (Jaccard). With comprehension_samples>1, measure the SPREAD
    // of the K independent readings' proposed objectives; a mean pairwise Jaccard distance >= threshold
    // means the file is genuinely ambiguous => abstain-and-ask instead of committing to one arbitrary
    // reading. Default OFF => U1's first-RED early-return is preserved => byte-identical.
    'objective_divergence_enabled' => (bool) env('ATLAS_LOOP_OBJECTIVE_DIVERGENCE_ENABLED', false),
    'objective_divergence_threshold' => (float) env('ATLAS_LOOP_OBJECTIVE_DIVERGENCE_THRESHOLD', 0.85),
    // ACDE V1 — strengthen the changed-symbol coverage census from bare name-presence to "exercised": the
    // changed public symbol must be CALLED in the corpus AND the corpus must assert something. Deterministic,
    // no coverage driver (bounded — does not prove per-branch coverage). Default OFF => name-presence stands
    // => byte-identical.
    'symbol_branch_census_enabled' => (bool) env('ATLAS_LOOP_SYMBOL_BRANCH_CENSUS_ENABLED', false),
    // ACDE X4 — decomposition non-vacuity: refuse a self-authored DAG whose nodes all carry the SAME
    // file-agnostic change request (the weak engine "decomposing" by copy-pasting one change across N files).
    // The structural validator proves well-formed, not distinct. Default OFF => byte-identical.
    'decomposition_non_vacuity_enabled' => (bool) env('ATLAS_LOOP_DECOMPOSITION_NON_VACUITY_ENABLED', false),
    // ACDE P3 — spec-coverage readiness band: a plan whose nodes do not cover one of the spec's
    // acceptance_criteria (no salient keyword of the criterion appears in any node) REPLANS, so a
    // structurally-impeccable DAG cannot silently drop a behavioral dimension. Conservative (one shared
    // token => covered). Default OFF / no criteria on the plan => byte-identical.
    'spec_coverage_band_enabled' => (bool) env('ATLAS_LOOP_SPEC_COVERAGE_BAND_ENABLED', false),
    // ACDE P2 — hint-grounding: strip fictional *.php references (files the weak engine invented) from the
    // spec's decomposition_hint before the planner consumes it, so it cannot chase a non-existent file.
    // Default OFF => the spec is unchanged => byte-identical.
    'hint_grounding_enabled' => (bool) env('ATLAS_LOOP_HINT_GROUNDING_ENABLED', false),
    // ACDE P4 — plan-abstention visibility: when the structured planner gives up and the adapter falls back
    // to the dumb one-shot buildPlan, emit a provider-safe receipt to the log so the abstention is observable
    // instead of silent. Default OFF => no log, fallback contract unchanged => byte-identical. (Routing an
    // abstention to the operator is a separate governance decision.)
    'plan_abstention_visible_enabled' => (bool) env('ATLAS_LOOP_PLAN_ABSTENTION_VISIBLE_ENABLED', false),
    // ACDE WD4 — split the strategy-bandit UCB bucket by a MEASURED complexity tier (lo/mid/hi on total
    // cyclomatic) so surgical-vs-root_cause efficacy is no longer averaged across a trivial adapter and a
    // 200-method hub. Default OFF => targetType() returns the bare path-prefix bucket, no file read,
    // byte-identical. The lo/hi thresholds band the AtlasLoopSignalAnalyzer total cyclomatic score.
    'bandit_complexity_tier_enabled' => (bool) env('ATLAS_LOOP_BANDIT_COMPLEXITY_TIER_ENABLED', false),
    'bandit_complexity_tier_lo' => max(1, (int) env('ATLAS_LOOP_BANDIT_COMPLEXITY_TIER_LO', 20)),
    'bandit_complexity_tier_hi' => max(2, (int) env('ATLAS_LOOP_BANDIT_COMPLEXITY_TIER_HI', 80)),
    // Endurance guard: WD4's AST measurement is advisory, so never spend unbounded memory parsing
    // giant historical targets (notably config/atlas.php). Oversize/unwanted files fall back to the
    // coarse path-prefix bucket.
    'bandit_complexity_tier_max_bytes' => max(1024, (int) env('ATLAS_LOOP_BANDIT_COMPLEXITY_TIER_MAX_BYTES', 200000)),
    // ACDE M1 — work-class landing-rate prior at the DECIDE front. Reads the EXISTING explorations ledger,
    // groups attempts by a derived work-class (path family), and de-prioritizes (WITHIN the band) a class
    // whose Wilson-LB landing rate is below the floor after enough REAL attempts — so the loop stops
    // grinding a class that empirically never lands. Default OFF => no DB read, priority unchanged,
    // byte-identical. min_attempts gates the prior on sufficient real samples; floor_rate is the hopeless
    // threshold; max_penalty_fraction caps how much of the offset the nudge can remove.
    'work_class_prior_enabled' => (bool) env('ATLAS_LOOP_WORK_CLASS_PRIOR_ENABLED', false),
    'work_class_prior_min_attempts' => max(1, (int) env('ATLAS_LOOP_WORK_CLASS_PRIOR_MIN_ATTEMPTS', 8)),
    'work_class_prior_floor_rate' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_WORK_CLASS_PRIOR_FLOOR_RATE', 0.15))),
    'work_class_prior_max_penalty_fraction' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_WORK_CLASS_PRIOR_MAX_PENALTY_FRACTION', 0.8))),
    'work_class_prior_window_hours' => max(1, (int) env('ATLAS_LOOP_WORK_CLASS_PRIOR_WINDOW_HOURS', 336)),
    // ACDE C1 — ground the loss-observer's vague self_improve directive into the builder's AST-anchored,
    // worst-method-named, ≥9-frozen extract-class spec (gives the dead AtlasLoopSelfImprovementObjectiveBuilder
    // its caller). The builder's own double meta-flag gate + petreous harness guard still decide
    // admissibility. Default OFF => the vague objective stands => byte-identical.
    'self_improve_grounding_enabled' => (bool) env('ATLAS_LOOP_SELF_IMPROVE_GROUNDING_ENABLED', false),
    // ACDE C1b — when grounding is armed, try the smallest certifiable self-edit first: a single-file
    // framework refactor with a real sibling test, real caller, complexity proof and the ≥9 bar. Heavy
    // extract-class remains the fallback. Default ON is scoped behind self_improve_grounding_enabled.
    'self_improve_single_file_refactor_enabled' => (bool) env('ATLAS_LOOP_SELF_IMPROVE_SINGLE_FILE_REFACTOR_ENABLED', true),
    // ACDE X3 — route the certifier's namespaced rejection dimension (complexity_gate / quality_bar /
    // changed_symbol_uncovered / completeness / delivery_confidence / overfit / behavioral_equivalence) to a
    // SPECIFIC re-attempt directive the conductor appends to the next round's guidance, so a weak engine
    // fixes exactly the dimension that failed instead of re-rolling blind. Deterministic (canned per
    // dimension, never an LLM judge). Default OFF => guidance unchanged => byte-identical.
    'rejection_dimension_routing_enabled' => (bool) env('ATLAS_LOOP_REJECTION_DIMENSION_ROUTING_ENABLED', false),
    // ACDE DG1 — calibrated-confidence abstention at the merge gate. AtlasLoopConfidenceCalibrator fits the
    // honest threshold (lowest cert-time confidence at which observed post-merge precision >= target) from
    // the real {predicted, correct} samples the merge feeder writes; the live merge authority abstains
    // (parks for operator) below that band instead of merging on a hand-set default. Default OFF => the gate
    // is never consulted => byte-identical. Needs >= min_samples real outcomes to fit a band (else fail-open).
    'calibrated_confidence_gate_enabled' => (bool) env('ATLAS_LOOP_CALIBRATED_CONFIDENCE_GATE_ENABLED', false),
    'calibrated_confidence_target_precision' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_CALIBRATED_CONFIDENCE_TARGET_PRECISION', 0.93))),
    'calibrated_confidence_min_samples' => max(2, (int) env('ATLAS_LOOP_CALIBRATED_CONFIDENCE_MIN_SAMPLES', 20)),
    'calibrated_confidence_window_days' => max(1, (int) env('ATLAS_LOOP_CALIBRATED_CONFIDENCE_WINDOW_DAYS', 30)),
    // ACDE DG2 — per-change-class earned-autonomy drain gate. A certified proposal whose change class has not
    // earned autonomous trust (clean streak below atlas.ai.trust_ladder.thresholds.autonomous) PARKS for
    // operator review instead of auto-merging. Default OFF => byte-identical. CONSERVATIVE WHEN ARMED: the
    // trust-ladder thresholds are disabled by default, so a freshly-armed DG2 parks EVERY class until the
    // operator sets a positive atlas.ai.trust_ladder.thresholds.autonomous AND the class earns that streak.
    'change_class_drain_gate_enabled' => (bool) env('ATLAS_LOOP_CHANGE_CLASS_DRAIN_GATE_ENABLED', false),
    // OPERATOR DIRECTIVE (2026-06-18) — AUTONOMOUS SELF-IMPROVEMENT MERGE. A legitimate, certified self-edit
    // (the loop improving its OWN harness on a NON-forbidden target) must land on main autonomously, with NO
    // human reviewer — the deterministic cert + canary + reprove + net-direction + trust gates ARE the
    // legitimacy proof. ON => self-improvements skip the human-park gate and flow through the same machine
    // gates as any proposal. The FORBIDDEN_SELF_TARGETS core (judge, certifier, auto-merge, materializer,
    // priorizadores) stays pétreo regardless — it parks earlier. Default OFF => byte-identical park behavior.
    'self_improvement_auto_merge_enabled' => (bool) env('ATLAS_LOOP_SELF_IMPROVEMENT_AUTO_MERGE_ENABLED', false),
    // ── FIBONACCI COMPOUNDING SEAMS (2026-06-22) — make f(n) feed f(n+1) so the loop EVOLVES the scope
    //    exponentially instead of running linearly. Each default-OFF => byte-identical; arming them all
    //    (in capability order) is what turns the linear loop into a compounding one. See the four tests:
    //    Origination-on-starvation, CapabilityRungGrowth/CapabilitySignal, CompoundingFrontier,
    //    RegressionWatcher/MainHealthRepairWiring.
    // L4 — KILL THE STALL: when reactive supply dries the supervisor ORIGINATES the next leap (via the
    // grounded origination pipeline) instead of stopping at queue_starved. Abstain still parks (never fakes).
    'origination_on_starvation_enabled' => (bool) env('ATLAS_LOOP_ORIGINATION_ON_STARVATION_ENABLED', false),
    // Autonomous self-engineer: a GROUNDED + designed NOVEL origination PROCEEDS (the loop originates the
    // leap) instead of parking-and-asking. Floor = architect red→green + cert/refute downstream. Default
    // OFF = byte-identical (novelty parks). See AtlasLoopAbstainAndAsk.
    'proceed_on_grounded_novelty_enabled' => (bool) env('ATLAS_LOOP_PROCEED_ON_GROUNDED_NOVELTY_ENABLED', false),
    // Directive #2/#3 — LEVERAGE-FIRST origination: rank grounded candidates by leverage (the parked
    // CrossTypeLeverageSelector), drop clone-unification PROXY, originate the top MATERIAL (orphan-wiring)
    // candidate. Default OFF = the free-text writer path (slice 1a) byte-identical.
    'leverage_first_origination_enabled' => (bool) env('ATLAS_LOOP_LEVERAGE_FIRST_ORIGINATION_ENABLED', false),
    // ANCHORED grounding — the inventory grounding gate (AtlasLoopComprehensionGroundingGate) admits an
    // objective when at least this FRACTION of its cited symbols are real inventory members (and >=1 is),
    // dropping the loose minority instead of vetoing the whole leap. The old all-or-nothing rule (1.0)
    // wrongly refuted "6 real symbols + 1 doc" as hallucinated, starving the material lane. 0.5 = majority
    // must be real; 1.0 = strict fail-closed all-or-nothing. Defence-in-depth: the materializer still
    // hard-requires an EXISTING edit target, so a loosely-grounded objective never reaches a fake file.
    'grounding_inventory_min_resolved_ratio' => (float) env('ATLAS_LOOP_GROUNDING_INVENTORY_MIN_RESOLVED_RATIO', 0.5),
    'origination_scope_root' => (string) env('ATLAS_LOOP_ORIGINATION_SCOPE_ROOT', 'app/Services/Ai/AutonomousEvolution'),
    // L3 — THE RUNG GROWS: proven capability (CapabilityTrendService upward bend) lowers the ambition
    // risk-tolerance toward pure-magnitude, so the loop dares a BIGGER leap only once it has earned it.
    // slope_full = the trend slope at which the loop is fully risk-seeking (the [0,1] normaliser).
    'capability_ambition_enabled' => (bool) env('ATLAS_LOOP_CAPABILITY_AMBITION_ENABLED', false),
    'capability_slope_full' => max(1e-9, (float) env('ATLAS_LOOP_CAPABILITY_SLOPE_FULL', 1.0)),
    // L2/L5 — MERGE EXPANDS THE FRONTIER: the StateOfAtlas the picker reads each cycle now includes the
    // loop's own merged deliveries (proposals.merged_to_main), so cycle n+1 builds HIGHER on cycle n's gains.
    'compounding_frontier_enabled' => (bool) env('ATLAS_LOOP_COMPOUNDING_FRONTIER_ENABLED', false),
    // L6 — ANTI-REGRESSION NET: a post-merge regression is triaged to the loop's own merge and re-attempted
    // as a fix-forward repair, so a long run never silently knocks down a lower rung. Cert-moat untouched.
    'regression_sentinel_enabled' => (bool) env('ATLAS_LOOP_REGRESSION_SENTINEL_ENABLED', false),
    // L3 (SUPPLY half) — TERRITORY LADDER: the autonomous scope-widener. `territory_ladder_rungs` are the
    // operator-released roots the loop may climb INTO once capability is proven (canPromote: >=3 certified
    // leaps + compounding trend + a frozen judge under EVERY widened root — the no-blinder invariant).
    // Default [] => the ladder is a logged no-op (gradual scope-release; the operator defines rungs). And
    // `territory_widened_roots_drive_refill` makes the persisted widening actually DRIVE the next refill
    // (the QueueRefiller scans the widened roots) — default OFF => the refiller sources the global roots
    // exactly as before => byte-identical. Arming BOTH is what lets a proven leap grow the SUPPLY frontier.
    'territory_ladder_rungs' => array_values(array_filter(array_map(
        static fn ($r): string => trim((string) $r),
        explode(',', (string) env('ATLAS_LOOP_TERRITORY_LADDER_RUNGS', '')),
    ), static fn (string $r): bool => $r !== '')),
    'territory_widened_roots_drive_refill' => (bool) env('ATLAS_LOOP_TERRITORY_WIDENED_ROOTS_DRIVE_REFILL', false),
    // ANTI-PROXY (soak safety) — the FAXINA MAGNET. This single flag gates BOTH provider-less cleanup
    // work-types (deterministic dead-code AND unused-import; the supervisor runs both under it). It MUST
    // stay OFF for a material self-evolution soak — armed, the loop's path of least resistance becomes
    // cosmetic cleanup (the forbidden Goodhart proxy). Default OFF; atlas:loop:soak's arm-check refuses to
    // launch while it is on, and atlas:loop:soak-report's proxy_alarm watches for drift if it slips on.
    'deterministic_deadcode_supply_enabled' => (bool) env('ATLAS_LOOP_DETERMINISTIC_DEADCODE_SUPPLY_ENABLED', false),
    // ACDE M4 — telemetry window (hours) the obra cost estimator averages real per-provider spend over to
    // de-orphan the budget scheduler's cost input. Read-only; an absent ledger yields an empty cost map
    // (the obra stays honestly deferred). The scheduler itself has no live dispatch caller yet.
    'obra_cost_window_hours' => max(1, (int) env('ATLAS_LOOP_OBRA_COST_WINDOW_HOURS', 336)),
    // ACDE O1 — the in-lane ORIGINATION producer: author a PROPOSE-ONLY origination proposal (structure +
    // decomposition hint, NO frozen acceptance, EMPTY diff, NEVER executeAndProve). Safe by construction:
    // empty diff + no acceptance_contract => the drain reprove fails closed => the row is RETIRED on the
    // first pass (never merged, never clogs); forbidden self-targets are dropped before authoring. Default
    // OFF => produce() is inert => byte-identical (the producer is never constructed in the OFF path).
    'origination_producer_enabled' => (bool) env('ATLAS_LOOP_ORIGINATION_PRODUCER_ENABLED', false),
    // ACDE O2 (the 3rd MULTIPLIER) — record the operator ACCEPT/REJECT on O1 origination proposals
    // (atlas:loop:origination-review) and let the producer BACK OFF shapes the operator keeps rejecting.
    // The human accept/reject sharpens the next authored origination — the only ground truth for
    // origination quality. min_samples + target_rate are OPERATOR-FROZEN (anti-Goodhart: the loop never
    // self-tunes its own origination bar); a thin/novel shape never backs off. Default OFF => no consult =>
    // byte-identical. Feedback stays inside origination — never the shared readiness gate or merge door.
    'origination_outcome_enabled' => (bool) env('ATLAS_LOOP_ORIGINATION_OUTCOME_ENABLED', false),
    'origination_backoff_min_samples' => max(1, (int) env('ATLAS_LOOP_ORIGINATION_BACKOFF_MIN_SAMPLES', 3)),
    'origination_backoff_target_rate' => (float) env('ATLAS_LOOP_ORIGINATION_BACKOFF_TARGET_RATE', 0.5),
    // ACDE S2 (observe-only) — record a PROVIDER-SAFE trace of what the EarnedAutonomy gate WOULD decide
    // (decision + risk rank + earned tier + the safety booleans + changed paths), so the operator can
    // audit the door for a long while BEFORE any decision to actuate it. OBSERVE-ONLY by construction: it
    // has NO apply/merge/canonize actuator. The live actuator is OUT OF SCOPE + BLOCKED pending an operator
    // governance decision + registering the proposal-gate/selector seam as sacred. Default OFF => inert.
    'earned_autonomy_decision_trace' => (bool) env('ATLAS_LOOP_EARNED_AUTONOMY_DECISION_TRACE', false),
    // ACDE R2-read (the MULTIPLIER) — when ON, the framework synthesizer reads the decomposition corpus
    // (R2's in-lane writes) for THIS shape's fingerprint and, if it has historically THRASHED (>=
    // min_samples outcomes, certified-rate < target_rate), backs the chain off to a single worst-method
    // step this round — a smaller, more-certifiable obra. So delivery N's recorded outcome grounds
    // delivery N+1 (the curve bends). Needs decomposition_corpus_enabled (R2) armed to have history.
    // Default OFF, and a thin corpus (< min_samples) yields UNKNOWN => no back-off => byte-identical.
    'extract_sequence_prior_read_enabled' => (bool) env('ATLAS_LOOP_EXTRACT_SEQUENCE_PRIOR_READ_ENABLED', false),
    'extract_sequence_prior_min_samples' => max(1, (int) env('ATLAS_LOOP_EXTRACT_SEQUENCE_PRIOR_MIN_SAMPLES', 4)),
    'extract_sequence_prior_target_rate' => (float) env('ATLAS_LOOP_EXTRACT_SEQUENCE_PRIOR_TARGET_RATE', 0.5),
    // ACDE lever D1 — the per-DELIVERY capability-trend instrument: the loop's OWN clean-delivery-rate
    // (committed + canary-not-red, the D2 definition) over rolling time buckets + the Wilson LB + the
    // SLOPE (is capability(t) bending upward?). A SELF-trend, never engine-vs-engine (Rivals is dead). It
    // READS atlas_loop_proposals (merged_to_main + quality) — no new table, no merge-path write. The ONLY
    // instrument that answers "did the curve actually bend." Default OFF => empty/inert trend => byte-
    // identical. Surface: `php artisan atlas:loop:capability-trend`.
    'capability_trend_enabled' => (bool) env('ATLAS_LOOP_CAPABILITY_TREND_ENABLED', false),

    // ACDE Leap 8 (greenfield ceiling) — REUSABLE DECOMPOSITION ARCHETYPE library. Imports the single-
    // target moat into greenfield: a human freezes a small library of archetypes (frozen/obra-archetypes/
    // *.json) — each a deterministic token classifier + the structural invariants every obra of that shape
    // must honour (min nodes, a mandatory create-class file suffix, min distinct targets). A goal that
    // classifies into an archetype is held to its invariants for ANY novel objective in the family — no
    // per-goal fixture. OFF, or no archetype match => structural-only (byte-identical). Ships EMPTY (the
    // library grows one human-authored rule at a time — the honest greenfield edge).
    'decomposition_archetype_enabled' => (bool) env('ATLAS_LOOP_DECOMPOSITION_ARCHETYPE_ENABLED', false),
    'decomposition_archetype_dir' => env('ATLAS_LOOP_DECOMPOSITION_ARCHETYPE_DIR', base_path('frozen/obra-archetypes')),

    // L4-1: cooldown por target recente. Tasks/proposals recentes do mesmo path caem no
    // ranking para evitar farming do arquivo que acabou de render proposta.
    'target_cooldown_enabled' => (bool) env('ATLAS_LOOP_TARGET_COOLDOWN_ENABLED', true),
    'target_cooldown_hours' => max(1, (int) env('ATLAS_LOOP_TARGET_COOLDOWN_HOURS', 24)),

    // L4-4: autópsia diária do Loop. Observa perdas/rejeições dominantes no ledger e
    // abre intents de backlog dedupados quando um padrão cruza o limiar.
    'loss_observer' => [
        'enabled' => (bool) env('ATLAS_LOOP_LOSS_OBSERVER_ENABLED', true),
        'schedule_time' => (string) env('ATLAS_LOOP_LOSS_OBSERVER_SCHEDULE_TIME', '05:20'),
        'window_hours' => max(1, (int) env('ATLAS_LOOP_LOSS_OBSERVER_WINDOW_HOURS', 24)),
        'min_occurrences' => max(2, (int) env('ATLAS_LOOP_LOSS_OBSERVER_MIN_OCCURRENCES', 3)),
        'manifest_limit' => max(10, (int) env('ATLAS_LOOP_LOSS_OBSERVER_MANIFEST_LIMIT', 200)),
    ],

    // L4-2: auto-alimentador diário do manifesto de backlog. Destila sinais reais e
    // endereçáveis (loss observer, corpus de falhas, residuais de campanha, scorecard
    // ACOS fraco, achados de sweep) em intents dedupados para a discovery L3-2.
    'backlog_auto_feed' => [
        'enabled' => (bool) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_ENABLED', true),
        'schedule_time' => (string) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_SCHEDULE_TIME', '05:25'),
        'window_hours' => max(1, (int) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_WINDOW_HOURS', 24)),
        'min_signal_count' => max(2, (int) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_MIN_SIGNAL_COUNT', 2)),
        'max_items' => max(1, (int) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_MAX_ITEMS', 8)),
        'manifest_limit' => max(10, (int) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_MANIFEST_LIMIT', 200)),
        'include_scorecard_weak_receipts' => (bool) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_SCORECARD_WEAK_RECEIPTS', true),
        'include_sweep_findings' => (bool) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_SWEEP_FINDINGS', true),
    ],

    // L4-7: fila explícita para propostas parqueadas pelo auto-merge (ex.: alvo de
    // segurança do próprio harness). Não é schedulada; é uma ação soberana do operador.
    'operator_review' => [
        'enabled' => (bool) env('ATLAS_LOOP_OPERATOR_REVIEW_ENABLED', true),
        'limit' => max(1, (int) env('ATLAS_LOOP_OPERATOR_REVIEW_LIMIT', 10)),
    ],

    // L3-12: meta-loop — o Loop pode tocar o PRÓPRIO harness (não-segurança) quando ON.
    // O AtlasLoopHarnessGuard mantém o conjunto PROIBIDO pétreo (frozen judge, gates,
    // never-merge) INTOCÁVEL independentemente desta flag. Default OFF (anti-runaway).
    'meta_harness_targets' => (bool) env('ATLAS_LOOP_META_HARNESS_TARGETS', false),

    // L6-1: the "loop-proposes-harness" producer. When ON (and meta_harness_targets ON),
    // the backlog intent source deterministically enqueues admissible NON-SAFETY harness
    // files as named meta-improvement intents, so the meta_harness A/B arm fills as the
    // loop runs. Passes through the AtlasLoopHarnessGuard chokepoint (forbidden set stays
    // pétreo). Low priority so it never starves the ordinary backlog. Default OFF.
    'meta_harness_self_improve' => [
        'enabled' => (bool) env('ATLAS_LOOP_META_HARNESS_SELF_IMPROVE_ENABLED', true),
        'priority' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_META_HARNESS_SELF_IMPROVE_PRIORITY', 0.4))),
        'max_candidates' => max(1, min(50, (int) env('ATLAS_LOOP_META_HARNESS_SELF_IMPROVE_MAX_CANDIDATES', 6))),
    ],

    // L6-1: meta-harness A/B lift. Read-only measurement; strict completion
    // requires real meta-harness and ordinary cases plus positive certification lift.
    'meta_harness_ab_lift' => [
        'enabled' => (bool) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_ENABLED', true),
        'schedule_enabled' => (bool) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_SCHEDULE_ENABLED', true),
        'schedule_time' => (string) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_SCHEDULE_TIME', '06:15'),
        'window_hours' => max(1, (int) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_WINDOW_HOURS', 168)),
        'min_cases_per_arm' => max(1, (int) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_MIN_CASES_PER_ARM', 3)),
        'min_lift' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_MIN_LIFT', 0.01))),
        'max_tasks' => max(10, (int) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_MAX_TASKS', 1000)),
    ],

    // L6-2: judge self-calibration. Historical RED-canary fix-forward tasks
    // compile into frozen verifier packets. Tighten-only: no providers, no
    // merge policy writes, and forbidden self-targets are refused.
    'judge_self_calibration' => [
        'enabled' => (bool) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_ENABLED', true),
        'schedule_enabled' => (bool) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_SCHEDULE_ENABLED', true),
        'schedule_time' => (string) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_SCHEDULE_TIME', '06:20'),
        'window_hours' => max(1, (int) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_WINDOW_HOURS', 168)),
        'max_cases' => max(1, (int) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_MAX_CASES', 8)),
        'timeout_seconds' => max(30, (int) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_TIMEOUT_SECONDS', 300)),
        'write_packets' => (bool) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_WRITE_PACKETS', true),
        'manifest_path' => (string) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_MANIFEST_PATH', storage_path('app/atlas/evidence/judge-self-calibration.json')),
        'packet_dir' => (string) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_PACKET_DIR', storage_path('app/atlas/evidence/judge-self-calibration-packets')),
    ],

    // L6-3: explorer strategy portfolio bandit. Strategic routing only:
    // ranks existing scenario hints by target type from resolved attempt
    // metrics, applies only with measured certification-per-token lift.
    'explorer_strategy_bandit' => [
        'enabled' => (bool) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_ENABLED', true),
        'apply_enabled' => (bool) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_APPLY_ENABLED', true),
        'schedule_enabled' => (bool) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_SCHEDULE_ENABLED', true),
        'schedule_time' => (string) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_SCHEDULE_TIME', '06:25'),
        'window_hours' => max(1, (int) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_WINDOW_HOURS', 168)),
        'min_attempts_per_target_type' => max(1, (int) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_MIN_ATTEMPTS_PER_TYPE', 4)),
        'min_token_samples_per_strategy' => max(1, (int) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_MIN_TOKEN_SAMPLES_PER_STRATEGY', 1)),
        'min_token_efficiency_delta_per_1k' => max(0.0, (float) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_MIN_TOKEN_DELTA_PER_1K', 0.01)),
        'ucb_exploration_weight' => max(0.0, min(2.0, (float) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_UCB_EXPLORATION_WEIGHT', 0.35))),
        'receipt_path' => (string) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_RECEIPT_PATH', storage_path('app/atlas/evidence/explorer-strategy-bandit.json')),
    ],

    // L6-4: code-graph auto-architecture proposals. Proposal-only:
    // reads Code Intelligence, parks a reviewable structural refactor draft,
    // and relies on the existing admission gate to prevent apply.
    'auto_architecture_proposals' => [
        'enabled' => (bool) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_ENABLED', true),
        'schedule_enabled' => (bool) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_SCHEDULE_ENABLED', true),
        'scheduled_create_proposal' => (bool) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_SCHEDULED_CREATE_PROPOSAL', true),
        'schedule_time' => (string) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_SCHEDULE_TIME', '06:30'),
        'candidate_limit' => max(1, (int) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_CANDIDATE_LIMIT', 5)),
        'min_file_count' => max(1, (int) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_MIN_FILE_COUNT', 20)),
        'min_symbol_count' => max(1, (int) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_MIN_SYMBOL_COUNT', 120)),
        'receipt_path' => (string) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_RECEIPT_PATH', storage_path('app/atlas/evidence/auto-architecture-proposal.json')),
        'proposal_index_path' => (string) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_INDEX_PATH', 'atlas/loop/auto-architecture/proposal-index.json'),
    ],

    // L6-5: property-based + mutation adequacy gate. When semantic
    // certification is active, passing tests must kill a temporary mutant;
    // otherwise the proposal is refuted as an empty/weak-test survivor.
    'mutation_adequacy_gate' => [
        'enabled' => (bool) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_ENABLED', true),
        'schedule_enabled' => (bool) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_SCHEDULE_ENABLED', true),
        'schedule_time' => (string) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_SCHEDULE_TIME', '06:35'),
        'max_mutants' => max(1, (int) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_MAX_MUTANTS', 1)),
        'timeout_seconds' => max(10, (int) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_TIMEOUT_SECONDS', 120)),
        // For REFACTOR contracts (complexity_proof + metric_kind=minimize) sample DECISION
        // mutators only — a behaviour-preserving refactor that RELOCATES an unasserted string
        // literal must not be falsely rejected (mutation_survived) on that cosmetic mutant.
        // Default OFF = byte-identical legacy first-mutation-wins (fail-closed); flip ON once a
        // soak confirms real refactor certs appear. Never weakens true rejection: a surviving
        // DECISION mutant still rejects, and no producible decision mutant still rejects.
        'refactor_decision_aware' => (bool) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_REFACTOR_DECISION_AWARE', false),
        // ACDE lever #4 — route the FEATURE/bugfix lane through the SAME EXHAUSTIVE added-DECISION
        // survivor hunt the refactor lane uses (probe EVERY added decision line), instead of the default
        // "one mutant on the first mutable added line, sample <=max_mutants, return on first survivor".
        // Both paths are already added-line-confined at the exact NEW-file index (firstAddedLineMutation —
        // the old strpos/whole-file firstMutation that could kill a mutant in OLD covered code is gone);
        // the flag only widens the probe so the existing 0.5 kill-ratio floor becomes a REAL ratio over the
        // added-branch surface — the sufficiency partner to the changed-symbol census (#3) necessity check.
        // Default OFF => first-mutable-line sampling. feature_lane_max_decisions bounds the per-task cost
        // (one acceptance re-run per probed target); a surviving decision within the probed set still hard-rejects.
        'exhaustive_added_decisions_feature_lane' => (bool) env('ATLAS_LOOP_MUTATION_EXHAUSTIVE_FEATURE_LANE', false),
        'feature_lane_max_decisions' => max(1, (int) env('ATLAS_LOOP_MUTATION_FEATURE_LANE_MAX_DECISIONS', 12)),
        'receipt_path' => (string) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_RECEIPT_PATH', storage_path('app/atlas/evidence/mutation-adequacy-gate.json')),
    ],

    // L6-6: cross-file consumer verification. When the code graph can tie a
    // changed symbol to a consumer contract, semantic certification replays
    // that contract and refutes local-GREEN proposals that break consumers.
    'cross_file_consumer_gate' => [
        'enabled' => (bool) env('ATLAS_LOOP_CROSS_FILE_CONSUMER_GATE_ENABLED', true),
        'schedule_enabled' => (bool) env('ATLAS_LOOP_CROSS_FILE_CONSUMER_GATE_SCHEDULE_ENABLED', true),
        'schedule_time' => (string) env('ATLAS_LOOP_CROSS_FILE_CONSUMER_GATE_SCHEDULE_TIME', '06:40'),
        'timeout_seconds' => max(10, (int) env('ATLAS_LOOP_CROSS_FILE_CONSUMER_GATE_TIMEOUT_SECONDS', 120)),
        'receipt_path' => (string) env('ATLAS_LOOP_CROSS_FILE_CONSUMER_GATE_RECEIPT_PATH', storage_path('app/atlas/evidence/cross-file-consumer-gate.json')),
    ],

    // L6-7: long-horizon observed-behavior regression oracle. This extends
    // the self-improvement regression sentinel with behavior contracts that
    // came from real observations rather than test specs. Receipt-only and
    // read-model-only: it blocks promotion when called with drifting snapshots,
    // but never mutates code, policy, provider routing or merge state.
    'self_improvement_regression_oracle' => [
        'enabled' => (bool) env('ATLAS_LOOP_SELF_IMPROVEMENT_REGRESSION_ORACLE_ENABLED', true),
        'schedule_enabled' => (bool) env('ATLAS_LOOP_SELF_IMPROVEMENT_REGRESSION_ORACLE_SCHEDULE_ENABLED', true),
        'schedule_time' => (string) env('ATLAS_LOOP_SELF_IMPROVEMENT_REGRESSION_ORACLE_SCHEDULE_TIME', '06:45'),
        'receipt_path' => (string) env('ATLAS_LOOP_SELF_IMPROVEMENT_REGRESSION_ORACLE_RECEIPT_PATH', storage_path('app/atlas/evidence/self-improvement-regression-oracle.json')),
    ],

    // L6-8: formal-light invariant gate over the sensitive kernel floor.
    // Receipt-only and tighten-only: verifies reproducible proof envelopes
    // for the Constitutional Kernel, governed never-merge DB door and
    // HarnessGuard forbidden targets; never calls providers or mutates code.
    'formal_invariant_gate' => [
        'enabled' => (bool) env('ATLAS_LOOP_FORMAL_INVARIANT_GATE_ENABLED', true),
        'schedule_enabled' => (bool) env('ATLAS_LOOP_FORMAL_INVARIANT_GATE_SCHEDULE_ENABLED', true),
        'schedule_time' => (string) env('ATLAS_LOOP_FORMAL_INVARIANT_GATE_SCHEDULE_TIME', '06:50'),
        'receipt_path' => (string) env('ATLAS_LOOP_FORMAL_INVARIANT_GATE_RECEIPT_PATH', storage_path('app/atlas/evidence/formal-invariant-gate.json')),
    ],

    // 24h-autonomia: respawn automático do supervisor morto (heartbeat velho + processo
    // ausente ⇒ relança detached, resume). Motivado pela morte silenciosa de 12/06.
    'keepalive_enabled' => (bool) env('ATLAS_LOOP_KEEPALIVE_ENABLED', true),

    // L4-6: painel 24h no digest matinal. Read-only: agrega funil, merges,
    // impact receipts, canários, custo medido, eventos de keepalive e propostas
    // estacionadas para o operador. O keepalive também registra um JSONL curto
    // para esta leitura; falha de escrita nunca derruba o respawn.
    'morning_digest' => [
        'enabled' => (bool) env('ATLAS_LOOP_MORNING_DIGEST_ENABLED', true),
        'schedule_time' => (string) env('ATLAS_LOOP_MORNING_DIGEST_SCHEDULE_TIME', '05:35'),
        'window_hours' => max(1, (int) env('ATLAS_LOOP_MORNING_DIGEST_WINDOW_HOURS', 24)),
        'keepalive_event_log_enabled' => (bool) env('ATLAS_LOOP_KEEPALIVE_EVENT_LOG_ENABLED', true),
        'keepalive_event_log_path' => (string) env('ATLAS_LOOP_KEEPALIVE_EVENT_LOG_PATH', storage_path('app/atlas/loop/keepalive-events.jsonl')),
        'keepalive_event_log_max_lines' => max(100, (int) env('ATLAS_LOOP_KEEPALIVE_EVENT_LOG_MAX_LINES', 2000)),
    ],

    // L5-1: pauta semanal governada. Propõe prioridades a partir de
    // evidência resolvida (digest/delta/backlog/final-capture) e, no
    // schedule, grava somente um draft no backlog para aprovação humana.
    'weekly_agenda' => [
        'enabled' => (bool) env('ATLAS_LOOP_WEEKLY_AGENDA_ENABLED', true),
        'schedule_day' => max(0, min(6, (int) env('ATLAS_LOOP_WEEKLY_AGENDA_SCHEDULE_DAY', 1))),
        'schedule_time' => (string) env('ATLAS_LOOP_WEEKLY_AGENDA_SCHEDULE_TIME', '05:45'),
        'window_hours' => max(24, (int) env('ATLAS_LOOP_WEEKLY_AGENDA_WINDOW_HOURS', 168)),
        'max_items' => max(1, (int) env('ATLAS_LOOP_WEEKLY_AGENDA_MAX_ITEMS', 5)),
        'scheduled_create_proposal' => (bool) env('ATLAS_LOOP_WEEKLY_AGENDA_SCHEDULED_CREATE_PROPOSAL', true),
    ],

    // L5-14: weekly human-readable report. It is a source artifact for the
    // weekly agenda, not an approval surface and not a provider runtime.
    'weekly_report' => [
        'enabled' => (bool) env('ATLAS_LOOP_WEEKLY_REPORT_ENABLED', true),
        'schedule_enabled' => (bool) env('ATLAS_LOOP_WEEKLY_REPORT_SCHEDULE_ENABLED', true),
        'schedule_day' => max(0, min(6, (int) env('ATLAS_LOOP_WEEKLY_REPORT_SCHEDULE_DAY', 1))),
        'schedule_time' => (string) env('ATLAS_LOOP_WEEKLY_REPORT_SCHEDULE_TIME', '05:40'),
        'hours' => max(24, (int) env('ATLAS_LOOP_WEEKLY_REPORT_HOURS', 168)),
        'max_words' => max(120, min(600, (int) env('ATLAS_LOOP_WEEKLY_REPORT_MAX_WORDS', 420))),
        'report_path' => (string) env('ATLAS_LOOP_WEEKLY_REPORT_PATH', storage_path('app/atlas/evidence/weekly-engineering-report.json')),
        'markdown_path' => (string) env('ATLAS_LOOP_WEEKLY_REPORT_MARKDOWN_PATH', storage_path('app/atlas/evidence/weekly-engineering-report.md')),
    ],

    // L5-2: Loop -> Obra bridge. Builds a Forge handoff packet for
    // multi-file intents, but does not run providers or create Obras.
    'obra_bridge' => [
        'enabled' => (bool) env('ATLAS_LOOP_OBRA_BRIDGE_ENABLED', true),
        'min_files' => max(2, (int) env('ATLAS_LOOP_OBRA_BRIDGE_MIN_FILES', 2)),
        // When ON, the 24h supervisor auto-invokes the bridge preflight for any
        // claimed task whose intent spans >= min_files distinct files, parking a
        // governed Forge handoff packet for operator review. NEVER auto-merges and
        // NEVER dispatches a provider — the bridge stays preflight-only. Default OFF;
        // fail-open (a bridge error is logged to the ledger and the grind proceeds).
        'auto_escalate' => (bool) env('ATLAS_LOOP_OBRA_BRIDGE_AUTO_ESCALATE', false),
    ],

    // L5-13: fortnightly adversarial sweep. It reuses the governed backlog
    // manifest: LOW findings are queued as Loop intents, HIGH findings are
    // parked for operator review. No providers, no direct code mutation.
    'perpetual_sweep' => [
        'enabled' => (bool) env('ATLAS_LOOP_PERPETUAL_SWEEP_ENABLED', true),
        'schedule_enabled' => (bool) env('ATLAS_LOOP_PERPETUAL_SWEEP_SCHEDULE_ENABLED', true),
        'schedule_day' => max(0, min(6, (int) env('ATLAS_LOOP_PERPETUAL_SWEEP_SCHEDULE_DAY', 6))),
        'schedule_time' => (string) env('ATLAS_LOOP_PERPETUAL_SWEEP_SCHEDULE_TIME', '06:05'),
        'schedule_week_parity' => max(0, min(1, (int) env('ATLAS_LOOP_PERPETUAL_SWEEP_SCHEDULE_WEEK_PARITY', 0))),
        'low_auto_fix_enabled' => (bool) env('ATLAS_LOOP_PERPETUAL_SWEEP_LOW_AUTOFIX_ENABLED', true),
        'high_review_enabled' => (bool) env('ATLAS_LOOP_PERPETUAL_SWEEP_HIGH_REVIEW_ENABLED', true),
        'include_backlog_feed' => (bool) env('ATLAS_LOOP_PERPETUAL_SWEEP_INCLUDE_BACKLOG_FEED', true),
        'max_findings' => max(1, (int) env('ATLAS_LOOP_PERPETUAL_SWEEP_MAX_FINDINGS', 12)),
        'manifest_limit' => max(10, (int) env('ATLAS_LOOP_PERPETUAL_SWEEP_MANIFEST_LIMIT', 200)),
        'carryover_findings' => [
            [
                'path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopProposalPromotionGate.php',
                'reason' => 'snippet_payload_missing',
                'severity' => 'high',
                'priority' => 0.96,
                'objective' => 'Separar snippet_payload_missing de no_acceptance_contract na re-prova snippet para aposentadoria/autopsia honesta.',
                'source' => 'carryover:l4_12',
            ],
            [
                'path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopProposalMaterializer.php',
                'reason' => 'rename_diff_normalization_requires_refusal_or_safe_normalization',
                'severity' => 'high',
                'priority' => 0.95,
                'objective' => 'Refutar rename diffs no materializer: recusar ou normalizar explicitamente sem mascarar troca de path.',
                'source' => 'carryover:l4_12',
            ],
        ],
    ],

    // L5-5: TAXA² dial overlay. The existing campaign supervisor consumes
    // these effective dials at boot; this never starts a parallel runtime,
    // never calls a provider and never touches the governed merge door.
    'taxa2_dials' => [
        'enabled' => (bool) env('ATLAS_LOOP_TAXA2_DIALS_ENABLED', false),
        'kernel_sanctioned' => (bool) env('ATLAS_LOOP_TAXA2_DIALS_KERNEL_SANCTIONED', true),
        'schedule_enabled' => (bool) env('ATLAS_LOOP_TAXA2_DIALS_SCHEDULE_ENABLED', true),
        'schedule_time' => (string) env('ATLAS_LOOP_TAXA2_DIALS_SCHEDULE_TIME', '05:55'),
        'window_hours' => max(1, (int) env('ATLAS_LOOP_TAXA2_DIALS_WINDOW_HOURS', 24)),
        'receipt_on_command' => (bool) env('ATLAS_LOOP_TAXA2_DIALS_RECEIPT_ON_COMMAND', true),
        'receipt_on_supervisor_boot' => (bool) env('ATLAS_LOOP_TAXA2_DIALS_RECEIPT_ON_SUPERVISOR_BOOT', true),
        'max_delta_per_run' => max(1, (int) env('ATLAS_LOOP_TAXA2_DIALS_MAX_DELTA_PER_RUN', 2)),
        'max_queue_low_watermark' => max(1, (int) env('ATLAS_LOOP_TAXA2_DIALS_MAX_QUEUE_LOW_WATERMARK', 12)),
        'max_refill_batch' => max(1, (int) env('ATLAS_LOOP_TAXA2_DIALS_MAX_REFILL_BATCH', 24)),
        'min_certification_rate' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_TAXA2_DIALS_MIN_CERTIFICATION_RATE', 0.65))),
        'min_certified_to_merged' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_TAXA2_DIALS_MIN_CERTIFIED_TO_MERGED', 0.5))),
        'max_canary_failures_24h' => max(0, (int) env('ATLAS_LOOP_TAXA2_DIALS_MAX_CANARY_FAILURES_24H', 0)),
        'min_impact_receipt_coverage_pct' => max(0.0, min(100.0, (float) env('ATLAS_LOOP_TAXA2_DIALS_MIN_IMPACT_RECEIPT_COVERAGE_PCT', 95.0))),
        'min_cost_coverage_pct' => max(0.0, min(100.0, (float) env('ATLAS_LOOP_TAXA2_DIALS_MIN_COST_COVERAGE_PCT', 80.0))),
    ],

    // L5-7: daily cost governor. This is not a second runtime; the
    // existing campaign budget fields remain authoritative. When a running
    // campaign approaches its configured cost cap, the supervisor reduces
    // scenarios per task; once the cap is reached, budgetStopReason()
    // returns cost_cap and the campaign stops.
    'cost_governor' => [
        'enabled' => (bool) env('ATLAS_LOOP_COST_GOVERNOR_ENABLED', false),
        'throttle_at_pct' => max(0.0, min(100.0, (float) env('ATLAS_LOOP_COST_GOVERNOR_THROTTLE_AT_PCT', 80.0))),
        'min_scenarios_per_task' => max(1, (int) env('ATLAS_LOOP_COST_GOVERNOR_MIN_SCENARIOS_PER_TASK', 1)),
    ],

    // 24h+ sem intervenção: revive campanhas que pararam por STARVATION de fila (a única
    // parada permanente — completed com budget sobrando). O loop mergeia código → novos
    // alvos surgem → reviver throttled re-descobre trabalho. Sem isto o soak para sozinho
    // após esgotar os alvos atuais e nunca volta.
    'keepalive_revive_starved' => (bool) env('ATLAS_LOOP_KEEPALIVE_REVIVE_STARVED', true),
    'keepalive_starved_revive_minutes' => (int) env('ATLAS_LOOP_KEEPALIVE_STARVED_REVIVE_MINUTES', 20),
    // Frozen-kill backstop threshold: a `running` campaign whose process is ALIVE but whose
    // heartbeat is stale longer than this is genuinely STUCK (not just slow). It MUST exceed the
    // max single-grind time (task_timeout_seconds, default 1800s = 30min) — otherwise the
    // watchdog kills HEALTHY long refactor grinds mid-flight (the heartbeat is not beaten during
    // a serial in-process grind), causing thrash: re-claim → re-grind → re-kill, never completing
    // (observed live: heavy refactors of complex services never certified, only fast tasks did).
    // 40min = the 30min grind cap + buffer; a truly-hung supervisor is still reaped at 40min.
    'keepalive_frozen_kill_minutes' => max(20, (int) env('ATLAS_LOOP_KEEPALIVE_FROZEN_KILL_MINUTES', 40)),
    // Reap window: a `running` row with no live process whose heartbeat is older than this is
    // an ABANDONED campaign (not a recently-died soak) — the watchdog marks it completed
    // (stop_reason=reaped_orphan_no_process) instead of respawning it. The recency bound is
    // what lets the respawn filter safely include unbounded (max_seconds<=0) soaks without
    // resurrecting ancient test zombies. Default 24h.
    'keepalive_reap_after_minutes' => max(60, (int) env('ATLAS_LOOP_KEEPALIVE_REAP_AFTER_MINUTES', 1440)),
    // Out-of-process code-drift backstop grace. The in-process drift-restart (campaign.
    // restart_on_code_drift) only fires at the TOP of the supervisor loop, so it is starved
    // during a long grind and goes dark entirely if the boot-time git HEAD read returned null
    // (observed live 2026-06-15: a pipeline fix sat un-loaded for 3h). When the SAME
    // restart_on_code_drift flag is ON, the keepalive ALSO recycles an alive supervisor whose
    // PROCESS START predates the newest engine commit — immune to both inner-loop failure modes.
    // This grace avoids killing a just-respawned (already-fresh) supervisor; the decision is
    // self-clearing (a recycle moves the start past the commit). Gated by restart_on_code_drift
    // so one flag controls the operator's churn-vs-autonomy choice for both checks.
    'keepalive_code_drift_grace_seconds' => max(60, (int) env('ATLAS_LOOP_KEEPALIVE_CODE_DRIFT_GRACE_SECONDS', 120)),

    // O-2 slice (d): universal adversarial certification. When ON, the DISCOVERY
    // path (not just framework tasks) routes every proposal through the semantic
    // certifier + adversarial panel before certified_for_review — closing the
    // Goodhart hole where the default 24h path trusted only a self-written frozen
    // judge. DEFAULT OFF: turning it on changes the live loop judge's strictness,
    // so it is deliberate (destravada junto da política merge-livre em O-3). When
    // on, a proposal that errors the gate is dropped (fail-closed), never the task.
    'universal_certification' => (bool) env('ATLAS_LOOP_UNIVERSAL_CERTIFICATION', false),

    // Auto-characterization-test lane (default OFF). When ON, a `characterization_test` objective
    // (produced only by the coverage-gap feeder) routes to the mutant-killed verifier instead of
    // the refactor cert: a provider-written test is kept only if it PASSES on correct code AND
    // FAILS on the gate's surviving mutant. Raises refactor conversion by closing coverage gaps,
    // never by lowering the mutation bar. Inert while OFF — the normal grind path is unchanged.
    'characterization_test_lane_enabled' => (bool) env('ATLAS_LOOP_CHARACTERIZATION_TEST_LANE_ENABLED', false),
    // Controlled-soak first-proof mode. In the first self-scope 24h campaign, small
    // characterization tasks give fast, measurable evidence that the loop is alive before a
    // heavy self-improvement refactor can spend many minutes. Default OFF preserves the normal
    // EV/band ordering; ON promotes characterization tasks above the refactor band.
    'characterization_first_proof_priority_enabled' => (bool) env('ATLAS_LOOP_CHARACTERIZATION_FIRST_PROOF_PRIORITY_ENABLED', false),
    'characterization_first_proof_priority' => max(1, (int) env('ATLAS_LOOP_CHARACTERIZATION_FIRST_PROOF_PRIORITY', 5_200)),

    // L6-11: predictive outcome bridge. When ON, every terminal loop grind records a
    // real prediction + observed outcome into the predictive-failure calibration
    // surface (predictive_failure_insertions), so atlas:predict metrics compute
    // brier_score / avg_calibration_error on LIVE loop data instead of staying null.
    // DEFAULT OFF: this is loop telemetry feeding the cognitive calibration surface,
    // so turning it on is the operator's deliberate decision. The bridge is fail-open
    // (never crashes a grind) and writes telemetry only — no code, proposal, or merge.
    'predictive_outcome_bridge' => [
        'enabled' => (bool) env('ATLAS_LOOP_PREDICTIVE_OUTCOME_BRIDGE_ENABLED', false),
        'domain' => (string) env('ATLAS_LOOP_PREDICTIVE_OUTCOME_BRIDGE_DOMAIN', 'programming'),

        // L6-11 follow-up: daily recompute of the predictive_failure_calibration_metrics
        // read-model. Today those metrics (brier_score / avg_calibration_error) only
        // recompute on demand — when `atlas:predict metrics` runs or the L6-11 correlation
        // gate evaluates in live mode. Once the bridge above is feeding real loop grind
        // predictions+outcomes, this keeps the calibration table fresh for dashboards/digests
        // without waiting for a gate run. It recomputes the SAME domain the bridge writes.
        // DEFAULT OFF and gated on the bridge being ON too: with no bridge data there is
        // nothing to summarize, so turning it on is the operator's deliberate decision.
        'metrics_recompute' => [
            'enabled' => (bool) env('ATLAS_LOOP_PREDICTIVE_OUTCOME_BRIDGE_METRICS_RECOMPUTE_ENABLED', false),
            'schedule_enabled' => (bool) env('ATLAS_LOOP_PREDICTIVE_OUTCOME_BRIDGE_METRICS_RECOMPUTE_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LOOP_PREDICTIVE_OUTCOME_BRIDGE_METRICS_RECOMPUTE_SCHEDULE_TIME', '07:45'),
            'window_days' => max(1, (int) env('ATLAS_LOOP_PREDICTIVE_OUTCOME_BRIDGE_METRICS_RECOMPUTE_WINDOW_DAYS', 60)),
        ],
    ],

    // The 24h CAMPAIGN runtime — the durable supervisor around the per-task engine.
    // It self-feeds (discovery + generator refill the queue), grinds in parallel
    // workers, persists proposals, loops back, and survives crashes via leases.
    'campaign' => [
        // Parallel grind workers (the "orquestração de agentes"). cpu-aware ceiling
        // applied at runtime; this is the requested width.
        'workers' => max(1, (int) env('ATLAS_LOOP_WORKERS', 3)),
        // Refill the queue when pending tasks fall below this (keeps 24h fed).
        'queue_low_watermark' => max(1, (int) env('ATLAS_LOOP_QUEUE_LOW_WATERMARK', 4)),
        // Targets discovered + seeded per refill wave.
        'refill_batch' => max(1, (int) env('ATLAS_LOOP_REFILL_BATCH', 6)),
        // Default wall-clock budget for a campaign (seconds). 0 = no cap. Default 24h.
        'max_seconds' => max(0, (int) env('ATLAS_LOOP_CAMPAIGN_MAX_SECONDS', 86400)),
        // Optional hard caps (0/empty => unbounded; budget is then time-only).
        'max_proposals' => (int) env('ATLAS_LOOP_CAMPAIGN_MAX_PROPOSALS', 0),
        'max_tasks' => (int) env('ATLAS_LOOP_CAMPAIGN_MAX_TASKS', 0),
        // Per-task claim lease: a crashed worker's task is reclaimed after this.
        'task_lease_seconds' => max(60, (int) env('ATLAS_LOOP_TASK_LEASE_SECONDS', 1800)),
        // INDEPENDÊNCIA 24h+: teto de tempo de UM grind. Antes o grind herdava o budget
        // inteiro (7 dias) → uma chamada de provider travada congelaria o supervisor por
        // dias (keepalive não pega processo vivo). Capa em 30min/task; o budget total
        // segue respeitado (usa o menor entre os dois).
        'task_timeout_seconds' => max(60, (int) env('ATLAS_LOOP_TASK_TIMEOUT_SECONDS', 1800)),
        // Campaign exclusive lock lease: a crashed supervisor's campaign is resumable after this.
        'lock_lease_seconds' => max(60, (int) env('ATLAS_LOOP_LOCK_LEASE_SECONDS', 3600)),
        // Supervisor heartbeat cadence (seconds).
        'heartbeat_seconds' => max(5, (int) env('ATLAS_LOOP_HEARTBEAT_SECONDS', 30)),
        // L4-5: supervisor bootstraps the git HEAD it started under and exits
        // cleanly when that HEAD changes, leaving the campaign running for
        // keepalive to respawn fresh code.
        'restart_on_code_drift' => (bool) env('ATLAS_LOOP_RESTART_ON_CODE_DRIFT', true),
        // Operator control files — touch to gracefully pause / kill a running campaign.
        'kill_switch_file' => (string) env('ATLAS_LOOP_KILL_SWITCH', storage_path('atlas-loop/KILL')),
        'pause_switch_file' => (string) env('ATLAS_LOOP_PAUSE_SWITCH', storage_path('atlas-loop/PAUSE')),
        // Isolated checkout the grind runs against (never the operator's working tree).
        // '' => derive a dedicated sibling worktree at runtime.
        'worktree_path' => (string) env('ATLAS_LOOP_WORKTREE', ''),
        // Repo subtrees discovery researches for high-value, self-contained targets.
        'discovery_roots' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ATLAS_LOOP_DISCOVERY_ROOTS', 'app/Services,app/Support,app/Models'))
        ), static fn (string $p): bool => $p !== '')),
        // Per-attempt hard wall-clock kill (the TimeBoundedLoopExecutionDriver deadline):
        // one hung provider call can never wedge the 24h run.
        'attempt_hard_seconds' => max(30, (int) env('ATLAS_LOOP_ATTEMPT_HARD_SECONDS', 900)),
        // Disk governor: refuse a new scenario below this free-MB floor; reap scenario
        // workspaces older than the TTL (catches the crash path the happy-path cleanup can't).
        'min_free_mb' => max(0, (int) env('ATLAS_LOOP_MIN_FREE_MB', 512)),
        'max_live_workspaces' => max(0, (int) env('ATLAS_LOOP_MAX_LIVE_WORKSPACES', 0)), // 0 = no cap
        'orphan_ttl_seconds' => max(60, (int) env('ATLAS_LOOP_ORPHAN_TTL_SECONDS', 1800)),
        // Rate limit between cycles (0 = no sleep).
        'sleep_seconds' => max(0, (int) env('ATLAS_LOOP_SLEEP_SECONDS', 0)),
        // Long-soak liveness mode. Default-OFF preserves the historical clean
        // queue_starved stop for tests/short campaigns. When armed for a supervised
        // 24h soak, empty supply becomes a bounded idle/re-scan loop until budget,
        // kill switch, or newly discovered work ends the idle.
        'idle_on_starvation' => (bool) env('ATLAS_LOOP_IDLE_ON_STARVATION', false),
        'starvation_idle_seconds' => max(5, (int) env('ATLAS_LOOP_STARVATION_IDLE_SECONDS', 60)),
        // Transient-DB resilience: a brief Postgres blip during a 24h run is a
        // recoverable hiccup, not a fatal crash. Each durable hot-path write is
        // retried with reconnect + exponential backoff; a per-cycle DB failure that
        // survives the retries parks the cycle (log + skip + continue) and the
        // campaign is aborted only after the DB is unreachable for the sustained
        // window. Propose-only — grind/judge behaviour is untouched.
        'db_retry_attempts' => max(1, (int) env('ATLAS_LOOP_DB_RETRY_ATTEMPTS', 5)),
        'db_retry_base_ms' => max(10, (int) env('ATLAS_LOOP_DB_RETRY_BASE_MS', 500)),
        'db_retry_max_ms' => max(100, (int) env('ATLAS_LOOP_DB_RETRY_MAX_MS', 30000)),
        'db_outage_abort_seconds' => max(30, (int) env('ATLAS_LOOP_DB_OUTAGE_ABORT_SECONDS', 180)),
        'db_outage_poll_seconds' => max(1, (int) env('ATLAS_LOOP_DB_OUTAGE_POLL_SECONDS', 15)),
    ],

    // The parallel worker pool ships BUILT but GATED OFF: serial single-worker is the
    // proven default. Enabling N workers is a measured flip (needs a real-Postgres
    // no-double-claim run + per-worker /tmp namespacing + disk cap divided by workers),
    // not assumed free — treat --workers>1 as experimental until that flip is tested.
    'parallel' => [
        'enabled' => (bool) env('ATLAS_LOOP_PARALLEL_ENABLED', false),
        'max_workers' => max(1, (int) env('ATLAS_LOOP_MAX_WORKERS', 4)),
    ],

    // ITEM6 — SCENARIO-LEVEL fan-out. The explorer's N attempts per task run as a bounded PARALLEL
    // wave (Process::start + non-blocking harvest) to hide the ~14min/attempt provider latency behind
    // width. Default-OFF => the serial for-loop is byte-identical. Distinct from `parallel` above
    // (that is TASK-level: one grind-task subprocess per task).
    'scenario_fanout' => [
        'enabled' => (bool) env('ATLAS_LOOP_SCENARIO_FANOUT_ENABLED', false),
        'width' => max(1, (int) env('ATLAS_LOOP_SCENARIO_FANOUT_WIDTH', 4)),
        'timeout_seconds' => max(30, (int) env('ATLAS_LOOP_SCENARIO_FANOUT_TIMEOUT_SECONDS', 600)),
    ],
];
