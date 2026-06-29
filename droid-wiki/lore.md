# Lore

A narrative history of how the Atlas Server codebase at `/Users/vitorepf/develop/Atlas/atlas-server` evolved, derived from git commit timestamps and messages. Dates come from `git log --reverse` and `git log --oneline` on the `main` branch. Where the "why" behind a change is not clear from the commits, the text says so.

The codebase is young: 3,471 commits across roughly two months, from the first commit on 2026-04-27 to the last surveyed commit on 2026-06-28. The growth curve is steep (11 commits in April, 799 in May, 2,661 in June), and a large share of the later commits were produced by the autonomous evolution loop grinding on itself. See [By the numbers](by-the-numbers.md) for the raw counts.

## Eras

### Era 1: the personal-data backend (2026-04-27 to 2026-05-01)

The repository opened as a single-tenant personal-data capture backend. The very first commit ("Initial commit", 2026-04-27) was a Fastify + PostgreSQL + Docker Compose Node server, and the next several commits that day are a stack of `fix:` messages wrestling with ESM modules, multi-stage Docker builds, and a PostgreSQL port conflict (`5433 para evitar conflito com BlackInk`).

On 2026-04-28 the server was rewritten in Laravel ("feat: rewrite server in Laravel and implement core V1 functional scope"). This is the only full-stack rewrite in the codebase's history, and it happened on day two. From that point on the codebase is a Laravel monolith and stays one. The rest of April (a handful of `implementacao` / `fix` commits through 2026-04-30) filled in the V1 capture, check-in, and sync surface described in the [overview](overview/index.md).

### Era 2: the mother structure and the AI gateway (2026-05-01 to 2026-05-14)

May opened with the AI Gateway taking shape. The 2026-05-01 commit run is almost entirely `feat(ai):` and `fix(ai):` messages building the worker that runs provider CLIs: rate-limit reset parsing, a provider choice menu, `fallback_model` config, and the `/api/ai/jobs/{job}/resume-choice` endpoint. The pattern established here (an interaction becomes a trace and a job, a local worker runs the provider CLI) is one of the longest-standing designs in the repo.

On 2026-05-03 the Open Brain's MCP surface appears: a "tools contract v1.1 with 10-tool inventory" and a rapid sequence of `feat(mcp):` commits adding `atlas_memory_record`, `atlas_code_find_relevant` (against the 8.7k symbol index), `atlas_docs_lookup`, `atlas_capabilities`, `atlas_workspace_info`, `atlas_recent_changes`, and `atlas_decision_query`. The MCP tool set would later grow to roughly 60 tools.

Then from 2026-05-05 through 2026-05-14 the commit log is dominated by a long series titled `estrutura mae` ("mother structure"), numbered parts 1 through 37. These appear to be the architectural skeleton of the Atlas platform laid down one slice at a time, interspersed with `Merge` commits and a few `modulo voice estacionado` ("voice module parked") notes. The intent behind the part numbering is not stated in the commits; the most plausible reading is that the operator was assembling the master structure of the platform in ordered pieces. By 2026-05-12 the `atlas-code` MVP endpoints and the `atlas-desktop` bridge appear, and on 2026-05-14 `atlas forge` and the Dev Cockpit land.

### Era 3: the Loop's first autonomous runs (2026-05-15 to 2026-05-31)

The second half of May is where the codebase pivots from "platform built by a human" to "platform that runs itself." A 2026-05-08 commit titled `higiene governamental` ("governmental hygiene") hints at the separation-of-powers doctrine that would become the Self-Construction OS. Through mid-May the work continues as `versao melhorada` / `progresso` / `evoluçao` commits and the tail of the `estrutura mae` series.

Late May is the inflection. From roughly 2026-05-30 the commit messages switch to a new voice: `Atlas autonomous evolution: ...` prefixed lines, often tagged with `[area=aaeos route=atlas_dev r_level=R1 ...]` metadata, and a flood of `AP-790` / `AP-786` / `AP-809` / `AP-792` tagged commits about quarantining rejected slices, gating unsafe provider diffs, and bounding factory seeds. The 2026-05-31 commits introduce the AAEOS (Atlas Autonomous Engineering OS) "leap backlogs" with explicit slice counts ("cognitive-plane atomic leap backlog (33 loop-ready N×M slices)", "reliability/Test-OS/integration atomic leap backlog (20 loop-ready)"). This is the loop beginning to decompose its own future work into bounded, loop-ready units.

### Era 4: the Loop builds itself (2026-06-01 to 2026-06-28)

June is 2,661 commits, the large majority of them produced by the loop running 24/7. Two visible signals confirm this. First, a long run of bare `save` commits through early-to-mid June (hundreds of them on some days), which read as the loop's autonomous checkpoints. Second, from about 2026-06-19 onward, a stream of `atlas loop auto-merge:` commits, each naming a single file and a short hash, marking work the loop merged into `main` through its own merge service. The [overview](overview/index.md) and [By the numbers](by-the-numbers.md) describe the merge-livre decision that authorized this.

The middle of June built out the "LOOP-OS" in labeled groups: Grupo A (constitution gates, frozen batteries, cert-chain closure), Grupo B (perf-cert, bug-fix lane, comprehension, coverage), Grupo C (drift-restart debounce, self-research intake, NL intent door). These are dated 2026-06-18 and use a slice numbering (Slice 1, 1.5, 2, 3, 4, 4.5, 5, 6, 7, 8, 9, 10, 11, 12, 14.5, 15) that suggests a planned rollout.

The last week (2026-06-27 to 2026-06-28) is the "brain" work: `atlas-brain 9.3` with numbered slices from roughly S177 up through S216, each paired with a `journal:` commit. The brain is the organ that decides what the loop should evolve next, and this stretch wires its perception bundle (EWMA crossovers, HHI concentration, critic-independence scores, contrarian requirements, rubber-stamp detectors) and the provenance/coverage/calibration organs. The final surveyed commits (2026-06-28) add the "Cycle Capsule + Internalization" pipeline, described as "the V3 internalization substrate (was MISSING)", and a `Revert` of a "causal compounding gate" tagged `anti-greenfield` and `anti-fabricated-compounding`. The revert-and-journal pattern is visible elsewhere too, and reads as the loop policing itself against fake compounding signals.

## Longest-standing features

A few designs from the first days survived every later rewrite and expansion:

- **The Laravel monolith foundation.** Established 2026-04-28 and never replaced. Every later subsystem was added into this one app.
- **The V1 capture and sync surface.** The `client_id`-based idempotent upsert and the `/sync` envelope from the V1 commits are still the ingestion contract, now sitting alongside the AI platform.
- **The AI Gateway worker pattern.** The 2026-05-01 design (interaction to trace to job, local worker runs the provider CLI) is still how the app reaches models. The provider-choice menu and `fallback_model` handling from that same day are still present.
- **The MCP tools contract.** The 10-tool v1.1 inventory from 2026-05-03 grew into the roughly 60-tool AOBG server, but the contract shape (capability negotiation, workspace info, memory record, code find, docs lookup, decision query) is recognizable in the current `app/Services/Ai/AtlasOpenBrainMcpService.php`.

## Major rewrites

- **Fastify to Laravel (2026-04-28).** The only from-scratch platform rewrite, on day two. Everything since has been additive.
- **The "estrutura mae" skeleton (2026-05-05 to 2026-05-14).** Not a rewrite of code so much as a rewrite of the architecture: 37 numbered slices that erected the master structure the rest of the platform hangs on. The Voice module was explicitly "parked" during this period and does not appear to have returned.
- **The Loop's self-modification (June 2026).** The loop editing its own organs is the most consequential "rewrite" in the codebase, and it is ongoing rather than discrete. The clearest single instance is the 2026-06-28 "Cycle Capsule + Internalization Pipeline", described in its own commit as "the V3 internalization substrate (was MISSING)", which retrofitted a whole new learning substrate onto the brain. The same day's revert of the causal compounding gate shows the loop also undoing its own changes when they fail anti-fabrication checks.

## Growth trajectory

| Month | Commits | What appeared |
|---|---|---|
| 2026-04 (11) | 11 | V1 personal-data backend; Fastify then Laravel |
| 2026-05 (799) | 799 | AI Gateway worker; MCP tools contract; the `estrutura mae` skeleton; atlas forge; the AAEOS leap backlogs; the loop's first autonomous runs |
| 2026-06 (2,661) | 2,661 | LOOP-OS groups A/B/C; the brain's perception bundle and provenance organs; loop auto-merge into main; Cycle Capsule + Internalization |

The densest subdirectories track this trajectory. `app/Services/Ai/AutonomousEvolution/` (870 files) and `app/Services/Ai/SelfConstruction/` (654) and `app/Services/Ai/Kernel/` (647) are the three largest trees in the codebase, and they are the ones the loop builds and edits most. The churn hotspot list in [By the numbers](by-the-numbers.md) is dominated by the loop's own runtime files and its living journal `docs/loop-evolution-journal/brain-24h.md`, which is consistent with a codebase being modified as it runs rather than between releases.

The trajectory is not finished. The final surveyed commit is dated 2026-06-28 and the loop is designed to keep running, so these numbers will already be stale by the time this page is read.

See also: [By the numbers](by-the-numbers.md), [Fun facts](fun-facts.md), [Architecture](overview/architecture.md), [Glossary](overview/glossary.md).
