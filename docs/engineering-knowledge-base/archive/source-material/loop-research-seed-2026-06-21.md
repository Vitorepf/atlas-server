# Loop Research Seed — 2026-06-21 (P27 hybrid seed, computer-research by Claude)

Source material (archived, advisory — NEVER gates a cert). This is the one-off DEEP research seed of the P27
hybrid: a powerful web-research pass that bootstraps the loop's improvement knowledge. The 24/7 regime then
runs autonomously on Hermes's native `browser`/`web` tools (sovereign, no human). Per the research contract,
every candidate below is IDEATION CONTEXT ONLY — it earns nothing until the loop turns it into a failing-test-
first (RED) change that the FrozenJudge + SemanticImplementationCertifier prove.

## What the research found about the loop itself

The Atlas loop already implements most of the 2026 SOTA for self-improving coding agents: a closed-loop
automated verifier (FrozenJudge + mutation cert) replacing human judgment, verifier-first / objective-checkable
work, parallel isolated-worktree workers, and persisted lessons/patterns (LoopPatternRegistry, learning
ledger). So the high-value candidates are NOT "catch up to SOTA" — they are sharpenings of the loop's own
verification + supply, grounded in 2025 mutation-testing research.

## Actionable, RED-provable improvement candidates (each maps to real loop code)

### C1 — Equivalent-mutant handling in kill_ratio — ⛔ INVESTIGATED 2026-06-21 → DEAD-END, DO NOT PURSUE
Hypothesis: the kill-ratio gate penalises EQUIVALENT survivors (false-negative). Investigation of the real
code (`AtlasLoopBehavioralEquivalenceGate` + `AtlasLoopSemanticImplementationCertifier:164` +
`AtlasLoopMutationAdequacyGateService`) DISPROVED it on two independent grounds:
  1. **No live false-negative to fix.** The kill-ratio strength gate is FAIL-OPEN and `mutation_kill_ratio_floor`
     defaults to **0.0 ⇒ the gate is OFF by default** (the certifier comment: "default 0.0 => OFF => byte-identical
     until armed"). It penalises nothing today. The primary anti-empty-test gate is BINARY (any surviving DECISION
     mutant hard-rejects) — not a ratio — and already skips cosmetics/comments and is decision-position-confined.
  2. **The valuable form is a GAMING HOLE.** Excluding "equivalent" survivors from the denominator is textbook
     mutation testing ONLY with GLOBAL equivalence. Global equivalence is ~undecidable in PHP; the tractable
     proxy (byte-identical output under the *suite's own inputs*) lets a NARROW test exclude everything it doesn't
     exercise and inflate its ratio to 1.0 — directly defeating the anti-Goodhart cert. The safe (provably-global)
     form fires ~never. So: near-zero value safe, or a hole. Either way NOT a loop improvement.
RULE for the regime: do not re-mint this topic. Equivalent-mutant handling only becomes worth it as a
PREREQUISITE if the operator ever wants to ARM the kill-ratio floor — and only with a gate-controlled,
reachability-proven differential oracle, which is its own obra, not a quick cert tweak.
Source (why it's subtle): https://link.springer.com/chapter/10.1007/978-3-031-94544-1_12

### C2 — Scientific-debugging mutation killing (improves cert CONVERSION, not the bar)
Naive LLM test generation imitates training-data tests instead of reasoning about execution semantics — weak at
killing specific mutants. The 2025 "Scientific Debugging" method has the model form a HYPOTHESIS about how to
kill a surviving mutant, then iterate test→run→refine with explanations, consistently outperforming naive
generation. CANDIDATE: when a characterization/refactor cert leaves a surviving mutant, drive a bounded
hypothesis→iterate loop targeted at THAT mutant (not a blind retry). Raises conversion without lowering the
bar. RED proof: a target whose blind generation leaves a survivor that the targeted loop kills. Loop code: the
coverage/characterization synthesizer + the cert's surviving-mutant feedback.
Source: https://arxiv.org/abs/2503.08182

### C3 — Mutation-guided characterization (higher-value coverage, less blind padding)
Mutation-guided unit-test generation targets the surviving mutants directly, yielding higher fault detection
than coverage-blind tests. CANDIDATE: when the coverage lane mints a characterization test for an untested
file, GUIDE it by the file's surviving frozen mutants (pin the behaviour the mutants would break) instead of
blanket coverage — turns "coverage padding" into mutation-earned verification, tightening the anti-proxy story.
RED proof: a coverage task whose mutation-guided test kills a mutant the blind test misses. Loop code: the
coverage-deficit / characterization lane in the refiller + AtlasLoopCoverageGapDetector.
Source: https://arxiv.org/pdf/2506.02954

## How the 24/7 regime consumes this (autonomous, sovereign)

The loop's `AtlasLoopResearchOriginator` (P27 slice-1) turns a research TOPIC into a RED-gated, source-
quarantined objective; the grind then researches the topic LIVE via Hermes's native `browser`/`web`/
`web_extract` tools (no human, no extra API key) and must prove the improvement with its own RED change. This
seed gives the regime a warm start: C1–C3 are concrete, sourced, RED-shaped topics it can pursue first.

## Sources
- Mutation testing for behavioural consistency in refactoring — https://link.springer.com/chapter/10.1007/978-3-031-94544-1_12
- Scientific-debugging LLM mutation killing — https://arxiv.org/abs/2503.08182
- Mutation-guided unit test generation — https://arxiv.org/pdf/2506.02954
- 2026 closed-loop self-improvement architecture — https://tianpan.co/blog/2026-04-10-agents-teach-themselves-closed-loop-self-improvement
- Self-improving coding agents (Osmani) — https://addyosmani.com/blog/self-improving-agents/
