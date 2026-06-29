# Fun facts

A few of the more striking things about the Atlas Server codebase at `/Users/vitorepf/develop/Atlas/atlas-server`. For the full quantitative picture see [By the numbers](by-the-numbers.md); for the history see [Lore](lore.md).

## A single 52,717-line PHP file

The largest file in the repo, `app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php`, is 52,717 lines of PHP in one class. That is larger than many complete applications, and it is not a close call: the runner-up (`app/Services/Ai/Holding/ExternalActionMandateRegistryService.php`) is 22,989 lines, less than half its size. Five of the six largest files in the codebase live under `app/Services/Ai/SelfConstruction/`, all of them readiness-projection sections for the self-construction government.

The file is part of the readiness machinery that decides whether the self-construction system is fit to take on more autonomy. Why it grew to 52K lines in a single class rather than being split is not obvious from the commits; the most likely explanation is that the loop generated successive projection sections against the same readiness surface and they accreted into one file. Either way, it is the single densest object in a 1.73M-line codebase.

## 1,227 artisan commands

There are 1,227 PHP files under `app/Console/Commands/`, every one of them an `atlas:*` artisan command. That is more commands than many frameworks ship built-in methods. The HTTP layer (`routes/api.php`, 82 touches in the last 90 days) is thin by design; the real product surface is the terminal, reached through the `bin/atlas` bash launcher, which resolves PHP 8.4+ and dispatches subcommands into this command fleet.

The command count is also a growth signal. `app/Console/Commands/` is the second-largest directory in `app/` after `app/Services/Ai/`, and the loop tends to wire every new capability into a command so the operator (and the loop itself) can invoke it from the shell.

## Naming in Portuguese

The operator is Portuguese-speaking, and several canonical terms are Portuguese words used as structural names. They appear in code, docs, and memory as the canonical identifier, glossed once here.

- **pétreo** ("stone") — the immutable core. The set of certificate, merge, switch, and guard organs the loop is forbidden from editing, so it cannot saw off the branch it sits on. Referenced throughout `docs/` and `app/` as `pétreo` (e.g. the "pétreo keepalive" in `docs/agent-governance-control-plane.md`).
- **babá** ("nanny") — the fleet reconciler, `app/Services/Ai/AgentGovernance/AtlasAgentReconciler.php`. Each tick it converges the running agent fleet toward the operator's desired state. Its own command description says it plainly: "The babá: converge the running fleet toward the operator desired-state (start desired, stop unsanctioned)." By default the babá can only ever reduce unsanctioned spend, because START is gated and default-suppressed while STOP always runs.
- **FREIO** ("brake") — the auto-OFF mechanism on `app/Services/Ai/AgentGovernance/AgentDesiredState.php`. Every desired-ON agent can carry a FREIO with a wall-clock TTL (`ttl_expires_at`) and a spend ceiling (`budget_limit_usd`). When the FREIO trips, the agent goes to auto-OFF and the babá stops it.
- **Obra** ("work") — the unit of delivered work in the loop, lived in `app/Services/Ai/Obra/` (`AtlasObraExecutor.php`, `AtlasObraCertificationService.php`, `AtlasObraReceiptStamp.php`, and friends). An obra is what the loop assembles, certifies, and merges.

The wider vocabulary (Loop, cérebro = brain, faculdade de ambição = ambition faculty) is covered in the [Glossary](overview/glossary.md).

## The codebase that built itself

A meaningful fraction of this codebase was written by the autonomous evolution loop, not by a human. 226 commits name `atlas-loop`, `atlas`, or `Atlas` as the author, which is a lower bound: many loop-produced diffs are committed under the operator's identity because the loop drives external provider CLIs (Claude, Codex) whose output the operator tooling then commits.

The clearest evidence is the `atlas loop auto-merge:` commit prefix that runs through June, each entry naming one file and a short hash. These are changes the loop produced, certified through its frozen-judge pipeline, and merged into `main` through its own merge service after the operator's "merge-livre" decision authorized it. The loop also edits its own organs: the 2026-06-28 "Cycle Capsule + Internalization" commit retrofitted a new learning substrate onto the brain, and the same day a `Revert` of a "causal compounding gate" (tagged `anti-fabricated-compounding`) shows the loop undoing its own work when it detected a fake signal. The [Lore](lore.md) page traces this arc; [By the numbers](by-the-numbers.md) has the commit counts.

## Probe scripts in the repo root

Three PHP scripts sit in the repository root prefixed with `_`, which is unusual for a Laravel app:

- `_smoke.php` — loads `app/Services/Ai/AutonomousEvolution/AtlasLoopBroaderRegressionGate.php` and asserts that the loop's broader-regression test selector picks the expected invariant tests for a loop engine file and an obra file.
- `_probe_nts.php` and `_probe_nts2.php` — both load `app/Services/Ai/MarketingDomain/Content/NarrativeTensionScorer.php` and print scored output for sample copy, exercising the marketing domain's narrative-tension grader by hand.

These are not part of the PHPUnit suite. They are operator scratch scripts for manually probing specific services from the repo root, dated late June (2026-06-22 and 2026-06-23). They survived in the working tree rather than being tucked under `tests/` or `scripts/`, which is a small tell about how fast the codebase moved: a service gets built, a one-off probe gets dropped in the root to poke at it, and the next thing takes over before the probe gets cleaned up.

See also: [By the numbers](by-the-numbers.md), [Lore](lore.md), [Glossary](overview/glossary.md), [Architecture](overview/architecture.md).
