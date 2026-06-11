---
id: AP-817-blog-editorial-planning-contract
type: architecture_proposal
title: AP-817 Blog Editorial Planning Contract
status: proposed
owner: atlas-kernel
line_limit: 180
related_paths:
  - docs/engineering-knowledge-base/atlas-blog-editorial-planning-system.md
  - app/Services/Ai/Publishing/BlogEditorialPlannerService.php
  - app/Services/Ai/Publishing/BlogEditorialContextService.php
  - app/Console/Commands/AtlasBlogEditorialPlanCommand.php
  - tests/Feature/Ai/Publishing/AtlasBlogEditorialPlanCommandTest.php
depends_on:
  - AP-201
  - AP-811
  - AP-812
  - AP-815
---

# AP-817 - Blog Editorial Planning Contract

## Purpose

AP-817 defines the governed Atlas area that plans Vitor Freire's public blog
sequence. The area keeps the public archive ordered, checks prerequisites, and
prevents advanced Atlas topics from being published before the reader has enough
foundation.

## Position

This is a planning and governance area, not a publishing bot.

It may read:

- the public site backlog;
- already published public posts;
- canonical Atlas docs;
- Open Brain/context pack summaries;
- Code Intelligence and graph retrieval outputs when invoked through existing
  Atlas contracts.

It must not create a parallel memory store, context store, graph, RAG runtime or
publication queue outside Atlas governance.

## P0 Scope

P0 is deterministic and read-only:

1. read the site backlog YAML;
2. read published post slugs from the public site data;
3. validate ordering, duplicate slugs and prerequisite direction;
4. report the next ready post;
5. report blocked posts and missing prerequisites;
6. emit JSON for Atlas, Codex and Claude.

P0 does not call Python, embeddings, graph RAG, providers or publishing APIs.

## P1 Scope

P1 may enrich the plan with existing Atlas context:

1. context-pack query suggestions per post;
2. owner-doc hints for technical subjects;
3. privacy/sensitivity review prompts;
4. "covered vs missing" signals from public posts and Atlas docs;
5. reviewable backlog candidates derived from existing KB/code read-models or bounded graph evidence;
6. read-only source, review-queue, coverage, operating-state, radar, golden-set, bounded graph-context/candidate, signal-mesh, public-knowledge, writing and daily operations packets;
7. public archive reconciliation for bridges, duplicate risks and prior artifacts;
8. Open Brain handoff/execution packets for audited context export.
9. graph/RAG readiness preflight without invoking graph or Python.

P1 stays kernel-first. It may read Engineering Knowledge, Code Intelligence and
audited Open Brain context, but only as editorial references, not publication facts.
Candidate backlog items are review packets. Graph-derived candidates require
`--editorial-graph-candidates` before acceptance. They do not become part of the
publication sequence until Vitor accepts them and explicitly promotes them.
Writing packets are private preparation packets. They may assemble sequence,
prerequisites, concept progression, refs, outline hints, reader promise, public
archive artifacts, duplicate/rewrite risk, safety prompts and "avoid for now"
topics. They must not generate a complete article, draft, publish content or
bypass the backlog sequence.
Published posts that are outside the current planned backlog are not promoted
to ordered prerequisites automatically. They may inform a planned post as prior
public evidence, but Vitor must decide whether to link, rewrite or keep them as
historical material.

Acceptance is two-stage:

1. dry-run acceptance emits a YAML snippet for review;
2. explicit `--write` appends the candidate to a review queue file, not to the
   main scheduled backlog.

Promotion is also explicit:

1. dry-run promotion emits the YAML that would be appended to the main backlog;
2. explicit `--write` appends a new "Fila revisada" week to the main backlog;
3. promotion never removes the review queue entry and never publishes content.

## P2 Scope

P2 may use graph/RAG only by reusing existing governed paths:

- AP-811 real code graph traversal;
- AP-812 python_ai_data runtime when promoted for the needed op;
- AP-815 cross-project context engine;
- `atlas-ai-runtime-language-boundaries.md`.

Every Python/data/graph call requires a Kernel decision receipt. No surface,
tool or script may call the runtime directly.

## Non-Scope

- automatic publication;
- automatic LinkedIn posting;
- hidden content generation without Vitor review;
- provider/model selection;
- new memory/RAG/graph storage;
- leaking private paths, prompts, traces or internal details;
- replacing the public site's own content model.

## Acceptance

P0 is acceptable when:

- `atlas:blog:editorial-plan --json` returns `atlas.blog_editorial_planner.v1`;
- the command is read-only;
- backlog order is validated;
- prerequisites cannot point forward;
- the next post is derived from published status and prerequisites;
- focused tests cover ready, blocked and invalid backlog cases.

P1 is acceptable when:

- `atlas:blog:editorial-plan --with-context --json` returns mode
  `read_only_governed_p1`;
- refs are sourced only from existing Atlas read-models;
- per-post context keeps `uses_graph_rag=false` and `uses_python_runtime=false`;
- `atlas:blog:editorial-plan --suggest-candidates --json` returns `backlog_candidates` with `writes_backlog=false`; `--review-queue` reads accepted candidates and dedupes suggestions;
- `atlas:blog:editorial-plan --source-map --json` returns source readiness and
  keeps `graph_retrieval.status=future_governed` until P2 is promoted;
- `source_map.archive_reconciliation` reports planned published posts,
  external published posts, external coverage by kind/collection and bridge
  candidates matched to planned posts without changing prerequisites;
- `atlas:blog:editorial-plan --coverage-map --json` returns foundation
  coverage, topic index, depth warnings and safe next arcs;
- `atlas:blog:editorial-plan --operating-state --json` returns the compact read-only state for the future Atlas blog area;
- `atlas:blog:editorial-plan --editorial-radar --json` returns lanes, gaps, review-queue state, insertion windows, candidates and source readiness;
- `atlas:blog:editorial-plan --editorial-golden-set --json` returns passing sequence fixtures before P2 graph/RAG removes the foundation blocker;
- `atlas:blog:editorial-plan --editorial-graph-context --json` returns bounded World Model evidence, and `--editorial-graph-candidates` returns review-only candidates with no write/reorder/publish power;
- `atlas:blog:editorial-plan --graph-rag-readiness --json` returns P2
  readiness while keeping graph/Python invocation off;
- `atlas:blog:editorial-plan --operations --json` returns
  `operations_packet` with next action, daily focus, writing packet, public
  archive risks, blockers, review queue, source/coverage snapshots, candidate feed and
  `open_brain_handoff` while keeping all write/publish/graph guardrails false;
- `operations_packet.atlas_signal_mesh` consolidates Atlas editorial signals
  across backlog, public archive, canonical docs, code intelligence, Open Brain,
  indirect vector readiness, bounded graph posture, review queue, candidate feed
  and intake without writing or granting publication/reorder authority;
- `operations_packet.public_knowledge_map` reports published reader knowledge,
  assumable topics, not-yet-assumable topics, archive bridges and the current
  reader contract without writing, reordering, publishing or creating parallel
  memory;
- `operations_packet.agent_operating_queue` reports read-only agent lanes for
  what can be prepared now, prepared later, reviewed by a human or held behind
  prerequisites, without writing drafts, publishing, reordering, promoting
  candidates or invoking graph/RAG;
- `operations_packet.agent_handoff_packet` reports the current AI handoff brief:
  mission, required refs, reader contract, evidence bundle, prompt seed and
  safety checklist, without granting write/publish/reorder or graph/RAG power;
- `atlas:blog:editorial-plan --writing-packet --json` returns
  `writing_packet` for the next ready post with
  `generates_full_article=false`, `writes_draft=false` and
  `publishes_content=false`;
- `writing_packet.concept_progression_map` separates introduced, current,
  allowed and future terms so deep ideas are not assumed too early;
- `writing_packet.open_brain_handoff` returns audited context export contract;
- `atlas:blog:editorial-plan --writing-packet --execute-open-brain --json`
  returns safe `writing_packet.open_brain_context`, not the raw context pack;
- `writing_packet.public_archive_context` reports planned matches and prior
  public artifacts so the writer can link, rewrite or avoid repetition;
- `atlas:blog:editorial-plan --writing-packet --writing-slug=<slug> --json`
  prepares a planned post by slug without writing files or changing schedule;
- `atlas:blog:editorial-plan --editorial-graph-candidates --accept-candidate=<slug> --json` can preview a bounded graph candidate review queue entry without writing;
- `atlas:blog:editorial-plan --accept-candidate=<slug> --write --json` writes
  only the review queue and keeps `writes_main_backlog=false`;
- `atlas:blog:editorial-plan --promote-candidate=<slug> --json` previews the
  main backlog append without writing;
- `atlas:blog:editorial-plan --promote-candidate=<slug> --write --json`
  appends to the main backlog with `append_only_backlog_update=true`;
- focused tests prove KB/code refs can be attached without publication.

## Promotion Rule

Moving beyond P0 requires explicit review of this AP and the runtime boundary.
Graph/RAG extends Open Brain and Code Intelligence, not a new editorial brain.
