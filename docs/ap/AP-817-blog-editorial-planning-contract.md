---
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
4. "covered vs missing" signals from public posts and Atlas docs.
5. reviewable backlog candidates derived from existing KB/code read-models.

P1 still stays kernel-first and read-only. It may read the Engineering
Knowledge Base and Code Intelligence read-models, but it must present them as
candidate editorial references, not as generated facts ready for publication.
Candidate backlog items are review packets. They do not mutate the public site
YAML and do not become part of the publication sequence until Vitor accepts
them.

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

- `atlas:blog:editorial-plan --json` returns schema
  `atlas.blog_editorial_planner.v1`;
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
- safety review prompts are emitted with every attached context packet;
- `atlas:blog:editorial-plan --suggest-candidates --json` returns
  `backlog_candidates` with `writes_backlog=false`;
- focused tests prove KB/code refs can be attached without publication.

## Promotion Rule

Moving beyond P0 requires explicit review of this AP and the runtime boundary.
Graph/RAG enrichment is an extension of Atlas Open Brain and Code Intelligence,
not a new editorial brain.
