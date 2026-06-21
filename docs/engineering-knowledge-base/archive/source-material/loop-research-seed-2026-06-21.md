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

### C1 — Equivalent-mutant handling in kill_ratio (HIGHEST value, fixes a real false-negative)
The cert gates on kill_ratio ≥ 0.5 (surviving mutants = test gap). But some surviving mutants are EQUIVALENT
(they don't change behaviour) — penalising them is a false-negative that rejects honest material work. Research:
an LLM equivalent-mutant detector rose from precision 0.79 / recall 0.47 to **0.95 / 0.96 with simple
pre-processing**. CANDIDATE: pre-filter equivalent mutants before computing kill_ratio so the cert stops
penalising provably-equivalent survivors. RED proof: a fixture with a known equivalent mutant whose current
cert wrongly fails and passes after. Loop code: the SemanticImplementationCertifier / mutation-cert path.
Source: https://link.springer.com/chapter/10.1007/978-3-031-94544-1_12

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
