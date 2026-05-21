---
id: atlas-self-construction-os-open-questions-v1
type: engineering_knowledge
title: Atlas Self-Construction OS Open Questions v1
status: active
category: audit
priority: 80
summary: Open questions that require a human decision before Self-Construction OS can advance from contract/certification/dry-run into runtime.
tags:
  - atlas-ai
  - self-construction
  - audit
  - open-questions
capabilities:
  - self_construction_atlas_self_construction_os_open_questions_v1
  - decision_inbox
decisions:
  - These questions block runtime activation; they are not for AI to answer alone.
  - Each question is paired with a default position the operator can accept, modify or reject.
maintenance:
  - Update when an answer is recorded, when a question is split, or when a new question emerges during read-only audit.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-gap-audit-v1.md
  - docs/engineering-knowledge-base/self-construction/audits/atlas-agent-control-plane-runtime-readiness-gap-matrix-v1.md
  - docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-risk-register-v1.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-os-open-questions-v1
graph_title: Atlas Self-Construction OS Open Questions v1
graph_world: atlas
graph_layer: gear
graph_kind: runbook
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Self-Construction OS Open Questions v1
canonical_name: Atlas Self-Construction OS Open Questions v1
technical_name: atlas-self-construction-os-open-questions-v1
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-open-questions-v1.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-open-questions-v1.md
allowed_changes:
  - Atualizar quando o operador responder uma pergunta ou novas perguntas aparecerem.
forbidden_changes:
  - Responder no lugar do operador; encerrar uma questao sem registro humano.
depends_on:
  - atlas-ai-documentation-operating-system
flows_to:
  - atlas-cartography
unlocks:
  - ai-safe-implementation-context
governs:
  - self-construction
evidence:
  - docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-open-questions-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - audit
  - self-construction
ai_entrypoints:
  - Antes de propor runtime, leia as 10 questoes e sinalize quais bloqueiam o plano.
ai_usage_notes:
  - Default position e ponto de partida, nao decisao final.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - IA responder por conta propria e marcar fase promovida.
observability_signals:
  - none until a question is answered
next_actions:
  - Operador humano responde uma questao por vez, grava decisao no doc, anexa evidencia.
---
# Atlas Self-Construction OS Open Questions v1

Each question is a blocker for runtime promotion. Each has:

- the question itself;
- why it blocks;
- default position the AI suggests (operator may accept, modify or reject);
- evidence required when the question is closed.

Until a question is answered with operator-attached evidence, the related runtime stays disabled.

## OQ-1 — When can runtime real begin?

- Why it blocks: every "runtime" cell in the gap matrix is N. Turning the first cell to Y requires a deliberate human moment, not a drift.
- Default position: only after the next required slice (`activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract`) has a green Promotion Gate, a green Replay Diff, a Release Dossier with `risk: low|medium` and a written kill switch path.
- Evidence required to close: signed promotion record + dossier hash + kill switch test.

## OQ-2 — Which provider runs first?

- Why it blocks: the post-start chain is Codex-shaped. The contract names Codex everywhere. But the audit cannot assume Codex is the operator's first real target.
- Default position: Codex (matches the chain), running inside the Forge Workspace, with an externally-started terminal (per current contract — Atlas does not spawn).
- Evidence required to close: operator-signed selection record naming provider + model + adapter version + workspace + max budget.

## OQ-3 — What is the initial budget?

- Why it blocks: no Budget Guard runtime exists. Spending without a ceiling is risk SC-OS-R-004.
- Default position: USD-equivalent ceiling per session AND per day, with a tripwire that revokes the receipt and freezes the projection on exceed. Concrete numbers are the operator's call.
- Evidence required to close: budget receipt with token limit, USD limit, time window and revocation behavior.

## OQ-4 — What is the kill switch?

- Why it blocks: every risk above ends with the same observable — flags going `true` without signed evidence. No automated revoke exists yet.
- Default position: a single artisan command + a single UI button that flips `dispatch_allowed`, `provider_started`, `adapter_execution_allowed`, `token_spend_allowed`, `self_programming_allowed` back to false, marks the active dispatch receipt as `revoked`, and writes a ledger event.
- Evidence required to close: command + UI binding + test that proves it cuts an in-flight dispatch under load.

## OQ-5 — What is workspace isolation?

- Why it blocks: cross-Claude write conflict (SC-OS-R-014) and multi-agent write conflict (SC-OS-R-008) need a real boundary.
- Default position: per-session worktree under Forge Workspace, with read-only mounts of the rest of the repo and a scope-locked write set. Until that is real, isolation stays procedural (each Claude declared allowed paths up-front, as in this audit pack).
- Evidence required to close: worktree provisioner + scope lock runtime + test that proves two parallel sessions cannot write the same file.

## OQ-6 — What is the merge policy?

- Why it blocks: after isolation, the outputs from parallel sessions need a defined merge. Without policy, multi-agent runtime is unsafe.
- Default position: each session produces a PR-like artifact against `main` with green tests, green docs-health, green architecture-validate; merge requires human ack OR a signed "auto-merge low risk" receipt that the operator may or may not enable.
- Evidence required to close: merge policy doc + test for the auto-merge path (if enabled).

## OQ-7 — Which UI surface is mandatory?

- Why it blocks: the gap matrix shows UI=N for every block. Operators cannot govern by JSON alone.
- Default position: an Atlas Desktop panel showing: current pointer, runtime safety flags, active claims/leases, scope locks, latest dispatch receipt status, last ledger event, kill switch button. Read-only first, then control. This belongs to the atlas-desktop corridor (out of scope for this audit pack to write).
- Evidence required to close: panel design doc + read-only first build + smoke test in the desktop app.

## OQ-8 — How to integrate Forge Activation with Agent Control Plane?

- Why it blocks: Forge Activation, Self-Improvement Activation and Agent Control Plane all want to be the "single ignition." Activation collision = duplicate scope, duplicate cost, drift.
- Default position: Agent Control Plane stays the chain-of-custody for AI provider sessions; Forge Activation triggers human-led work; Self-Improvement Activation triggers Atlas-internal improvement; integration happens AFTER the first signed real dispatch lands in Agent Control Plane, not before.
- Evidence required to close: integration contract that names ownership boundaries + a smoke run where each surface activates without overlap.

## OQ-9 — When does self-programming become the goal?

- Why it blocks: SC-OS-R-015 (premature self-programming activation) is critical. Even after dispatch runtime exists, self-programming requires the full safety contract.
- Default position: self-programming is NOT the next goal. The next goal is the post-start receipt contract slice. Self-programming is gated on: docs current, context pack fresh, meta-SDD spec, allowed/forbidden files, gates runnable, rollback strategy, evidence requirements, drift detector. Until all eight are real, the answer is "not yet."
- Evidence required to close: explicit operator decision + the 8 preconditions of `self-programming-safety-contract.md` checked off with evidence.

## OQ-10 — What declares Self-Construction OS complete?

- Why it blocks: no current artifact promotes the OS to "complete." Without a definition, premature claims become possible.
- Default position: completion requires (a) every row in the gap matrix Runtime column = Y with cited evidence; (b) green Release Dossier; (c) green Replay Diff vs. snapshot stored at completion; (d) green Promotion Gate; (e) green Mutation Guard; (f) human-signed "OS complete" receipt; (g) end-to-end smoke run of one packet from claim through completion with a real provider; (h) Forge / Self-Improvement integration smoke. Until (a)-(h), the OS is NOT complete.
- Evidence required to close: a future `atlas-self-construction-os-complete-vN.md` artifact citing all eight items.

## How to Answer

Operator answers a question by:

1. Writing the decision in this doc (replace "Default position" with the chosen position, dated).
2. Linking the evidence (receipt, dossier, gate, test).
3. Updating the corresponding row in the Gap Matrix only if the decision changes a cell from N to partial or Y.
4. Re-running the audit pack to confirm no other invariant broke.

## Closing Note

Until at least OQ-1 + OQ-4 + OQ-5 + OQ-7 are answered with evidence, runtime promotion should remain on hold. The audit pack records this as the canonical position on 2026-05-14.

## Resumo

10 perguntas em aberto que precisam de decisao humana antes de Self-Construction OS sair de read-only.

## Papel no Atlas

E o decision inbox do OS; sem isso, runtime fica parado por design.

## Onde Se Encaixa

Companion do Gap Audit, da Matrix e do Risk Register; consumido pelo operador.

## Contratos

Nao altera contratos; aponta para `agent-control-plane-contract.md`, `runtime-implementation-roadmap.md` e `self-programming-safety-contract.md`.

## Fluxo

Operador le -> escolhe uma questao -> escreve decisao + evidencia -> matriz e risk register atualizam.

## Regras para IA

IA nao responde nenhuma questao por conta propria; pode propor evidencia, nao pode encerrar a questao.

## Escopo de Implementacao

Mudancas em `docs/engineering-knowledge-base/self-construction/audits/`.

## Dependencias

Depende do Gap Audit, da Matrix e do Risk Register companions.

## Evidencias

Default positions baseadas em outputs read-only de 2026-05-14; evidencia final pertence ao operador.

## Riscos

Risco: IA responder por conta propria e promover slice sem operador. Mitigacao: estas perguntas sao explicitamente humanas.

## Exemplos

OQ-1 (quando runtime pode comecar) so fecha com promotion gate verde + dossier + kill switch testado.

## Proximas Acoes

- Operador responde OQ-1, OQ-4, OQ-5, OQ-7 antes de qualquer promocao.
- Demais questoes ficam abertas ate a slice correspondente entrar em foco.
