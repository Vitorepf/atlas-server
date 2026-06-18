# Loop Self-Evolution Architecture (the closed cycle)

Blueprint for the Loop that runs autonomously for hours, each cycle finding + executing the biggest
evolution leap Atlas can have in the least time, closing on main. Built ON existing machinery (Open
Brain, code-graph, cartography, reality-graph, NextWorkDecider/QueueRefiller, FeatureObjectiveBuilder,
ADEP, the diff-earned gate, auto-merge) — NOT reinventing.

## The closed cycle (one tick)

```
AtlasLoopSelfEvolutionOrchestrator::runCycle(campaign)
  1. STATE      AtlasLoopStateOfAtlasReader::read(repo)        → StateOfAtlas
                  (brain comprehension: areas, maturity, gaps, problems, strategic priorities,
                   where-NOT-to-go; from memory/goals + cartography/domains + code-graph/complexity
                   + reality-graph/evidence + petreo/proibido)
  2. SELECT     AtlasLoopObjectiveProducer::selectLeap(state, candidates)   [the rédea, exists]
                  → leverage = (impact × breadth × compounding) / (cost × risk)  [LeverageScorer]
                  → AtlasLoopAdversarialCritic::challenge(pick, runnerUp)   "biggest or easy-looking?"
                  → the winning leap (target + shape: refactor | feature) above the ambition floor
  3. ORIGINATE  AtlasLoopOriginationBuilder::build(leap, state)            [the CEILING LIFT]
                  → refactor: heavy structural objective w/ behavior anchor (existing synthesizers)
                  → feature : NEW capability objective w/ an authored RED acceptance test
                  → a verifiable objective contract (scope + plan + acceptance: RED→GREEN / metric)
  4. IMPLEMENT  ADEP iterate-to-green                                       [exists] strong engine
  5. GATE       diff-earned (revert_recheck) + real suite green             [exists] the MOAT
  6. MERGE      auto-merge to main                                          [exists]
  7. REPEAT     fresh main → next cycle
```

`AtlasLoopAutonomousRunner` drives `runCycle` for hours: health, budget, fresh-main contract, stop
on kill-switch/budget. Every cycle writes a receipt to the living report.

## Components (NEW, built on existing)

| Component | Role | Builds on |
|---|---|---|
| `AtlasLoopStateOfAtlasReader` + `StateOfAtlas` | deep brain comprehension → structured state | OpenBrain context pack, AtlasAiDomainCatalogService, DomainMaturity, code-graph, reality-graph, memory recall |
| `AtlasLoopLeverageScorer` ✅ | the leverage math + ambition floor | (done) |
| `AtlasLoopObjectiveProducer` ✅ (extend) | the rédea: select the biggest leap | LeverageScorer + State + brain |
| `AtlasLoopAdversarialCritic` | self-critique: biggest leap or easy-looking? | pure reasoning over scored candidates |
| `AtlasLoopOriginationBuilder` | the ceiling lift: originate a BIG objective (refactor OR feature) | RefactorObjectiveSynthesizer, FrameworkRefactorSynthesizer, FeatureObjectiveBuilder |
| `AtlasLoopSelfEvolutionOrchestrator` | drive one closed cycle | producer + builder + ADEP + gate + auto-merge |
| `AtlasLoopAutonomousRunner` | run the cycle for hours, healthy | orchestrator + budget/health |

## Non-negotiables (enforced by construction)
- main NEVER breaks: the diff-earned gate + suite run BEFORE any merge; fail-closed.
- anti-gaming is the moat: leverage is strategic-progress-per-effort, never diff size; objectives
  must be verifiable or they are rejected; value is judge-measured, never self-declared.
- build on existing; respect petreo/proibido/local-first; every component flag-gated default-OFF,
  fail-open, byte-identical until armed.
- honesty: each leap proven with a real delta; potential is never sold as fact.

## Delivery mode
Assemble ALL the logic + architecture first (this doc → all components, wired). Test + PROVE at the
END (one real high-value closed cycle on main, then hours of composed leaps). Until then: lint only.
