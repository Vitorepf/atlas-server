# Anti-Goodhart and no-proxy

Goodhart's law says that when a measure becomes a target, it ceases to be a good measure. Atlas takes this seriously because it is a self-improving system: if the autonomous loop optimizes a measurable surrogate instead of real capability, it will farm the metric rather than evolve the product. The anti-Goodhart doctrine is the structural defense against this.

## The core rule

Before the loop touches anything, it asks: does this evolve the scope exponentially, leaving Atlas more capable? If the change is small cleanup, proxy-metric optimization, or a behavior-preserving refactor, the answer is no. Refactor that preserves behavior is zero improvement. Removing dead code, renaming variables, reformatting whitespace, or shuffling files are not evolution. They are churn dressed as progress.

The prohibited surrogates include line count, test count, cyclomatic complexity, and landing rate. None of these measure real capability. A system can have more tests and less coverage, lower cyclomatic complexity and weaker behavior, or a higher landing rate and worse changes.

## NO-SCALAR perception

The loop's perception organs emit only facts and counts, never a learned score the system could game. This is the NO-SCALAR principle. A perception organ reports "37 callers reference this symbol" or "4 tests cover this method," but it never reports "this method has a quality score of 8.2." A score is a target. A fact is evidence.

The next-work decider (`app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopNextWorkDecider.php`) computes priority as `BAND(shape) + OFFSET(leverage re-resolved FRESH from the working tree)`. It never trusts a stored writable score. The leverage offset is recomputed from the live working tree each cycle, so a model cannot inflate a stored number to game the ranking.

## The frozen judge

The frozen judge (`app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php`, 46KB) is an out-of-process verifier that re-runs frozen acceptance tests itself. It is a FORBIDDEN self-target: the loop can never edit the judge that judges it. The judge runs Guards 1 through 4e:

| Guard | What it checks |
|-------|---------------|
| 1 TAMPER | the frozen contracts have not been modified |
| 2 SCOPE | the change is within the allowed scope |
| 2b REQUIRED-OUTPUTS | all required outputs are present |
| 3 RE-PROOF | re-runs frozen acceptance from scratch |
| 4 DIFF-EARNED | reverting the diff turns a frozen check RED |
| 4b COMPLEXITY-EARNED | complexity reduction is real, not cosmetic |
| 4c PERFORMANCE-EARNED | performance claims are measured |
| 4d DEDUP-EARNED | deduplication removes real duplication |
| 4e WIRED-EARNED | the change is wired into a real caller |

Guard 4 (DIFF-EARNED) is the anti-false-green gate. A diff is real if and only if reverting it turns a frozen check red. If reverting the diff leaves all checks green, the diff did nothing, and the change is rejected.

## The anti-farm floor

The anti-farm floor (`app/Services/Ai/AutonomousEvolution/AtlasLoopAntiFarmFloor.php`) is the merge-eligibility floor. A change can only merge if it clears two conditions:

1. **BITES** — the diff is load-bearing. It changes behavior that a frozen test verifies, not just formatting or dead code.
2. **PRODUCTION-PATH-PROVEN** — the change is wired into a real caller. A function that exists but is never called is not production-path-proven.

The floor can never be loosened. It is a one-way ratchet.

## Cross-model triangulation

On disputed or hard certify verdicts, the loop uses cross-model triangulation (`app/Services/Ai/AutonomousEvolution/AtlasLoopCrossModelTriangulator.php`). Three or more distinct providers judge the same frozen-acceptance bundle hash. The triangulator strips Goodhart keys (score, confidence, average) and feeds only categorical line-items to the consensus gate. The verdict is categorical agreement or dissent, never a smoothed scalar. The writer model is excluded from its own panel by construction.

## How it manifests elsewhere

The Goal and Value System in the Self-Construction Government has an `AntiProxyGate` (`app/Services/Ai/SelfConstruction/GoalValue/AtlasGoalValueAntiProxyGate.php`) that rejects task-count and green-tests-as-value before work enters the pipeline. The [Self-Construction Government](../systems/self-construction-government/index.md) applies the same anti-proxy filter at the value-definition stage, before the Loop ever sees the work.

## Related pages

- [Quality gates and certification](../systems/evolution-loop/quality-gates-and-certification.md) — the full frozen judge and certify pipeline
- [Separation of powers](separation-of-powers.md) — the writer never judges its own change
- [Evidence and receipts](evidence-and-receipts.md) — how receipts record what the judge verified
- [Earned autonomy](earned-autonomy.md) — autonomy is earned through proven value, not proxy metrics
- [Glossary](../overview/glossary.md) — frozen judge, diff-earned, anti-farm floor, NO-SCALAR
