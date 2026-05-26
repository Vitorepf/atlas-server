---
id: atlas-learning-mutation-runtime
type: engineering_knowledge
title: Atlas Learning Mutation Runtime
slug: atlas-learning-mutation-runtime
status: building
risk_level: critical
authority_class: gated_executor
category: self-improvement
priority: 91
summary: Runtime guardado para avaliar learning proposals e aplicar mutacoes seguras somente com approval humano, sem tocar petreos, policy, providers ou memoria critica.
tags:
  - atlas-ai
  - learning
  - mutation
  - self-improvement
  - gated-executor
capabilities:
  - learning_mutation_evaluation
  - safe_mutation_receipt
  - operator_approval_gate
  - learning_proposal_closure
  - petreo_mutation_blocklist
decisions:
  - Learning proposals podem ser avaliadas por runtime, mas aplicacao exige approval humano.
  - Mutacoes permitidas ficam limitadas a prompt_template, doc_skeleton e elastic_runtime_threshold.
  - Petreos, policy runtime, provider config, memory critical path, claim policy e external rivals certification sao bloqueados.
  - CLI atlas:learning:mutation existe para evaluate/apply/list; fluxo permanece building ate command tests e aplicacao operacional estarem provados.
maintenance:
  - Atualizar quando AtlasLearningMutationRuntimeService, AtlasLearningProposalService ou AtlasSddLearningProposal mudarem.
  - Nao declarar active enquanto command, testes e receipts de aplicacao nao estiverem provados.
  - Manter este doc alinhado ao Self-Improvement L7 e ao Constitutional Kernel.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Compounding/AtlasLearningMutationRuntimeService.php
  - app/Console/Commands/AtlasLearningMutationCommand.php
  - app/Services/Ai/Compounding/AtlasLearningProposalService.php
  - app/Models/AtlasSddLearningProposal.php
  - app/Services/Ai/Governance/AtlasConstitutionalKernelService.php
  - app/Services/Ai/Governance/AtlasAutonomyAdmissionService.php
  - docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-learning-mutation-runtime
graph_title: Atlas Learning Mutation Runtime
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-self-improvement-closed-loop-level7-v1
graph_status: building
graph_source: repo
owner: self-improvement
repo_paths:
  - docs/engineering-knowledge-base/atlas-learning-mutation-runtime.md
  - app/Services/Ai/Compounding/AtlasLearningMutationRuntimeService.php
depends_on:
  - atlas-self-improvement-closed-loop-level7-v1
  - atlas-constitutional-kernel
  - atlas-autonomy-admission
  - atlas-cognition-operating-system
allowed_changes:
  - Adicionar command e testes para evaluation/apply sem auto-apply.
  - Evoluir whitelist somente com owner decision e teste de kernel/admission.
forbidden_changes:
  - mutate_petreo_kernel_invariant
  - mutate_policy_runtime
  - mutate_provider_config
  - mutate_memory_critical_path
  - auto_apply_without_operator_approval
flows_to:
  - self-improvement-l7
  - learning-proposals
unlocks:
  - safe-learning-mutation-review
  - proposal-to-receipt-closure
governs:
  - learning-mutation-evaluations
  - learning-mutation-applications
evidence:
  - app/Services/Ai/Compounding/AtlasLearningMutationRuntimeService.php
  - app/Console/Commands/AtlasLearningMutationCommand.php
  - app/Services/Ai/Compounding/AtlasLearningProposalService.php
  - app/Models/AtlasSddLearningProposal.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test --filter=AtlasLearningMutationRuntime"
  - "php artisan atlas:learning:mutation --action=list-evaluations --json"
requires_evidence: true
next_actions:
  - Adicionar teste unitario para evaluation de target permitido.
  - Adicionar teste unitario para blocklist petrea.
  - Adicionar teste de command para evaluate/apply/list.
schema:
  - atlas.learning.mutation_evaluation.v1
  - atlas.learning.mutation_application_receipt.v1
---

# Atlas Learning Mutation Runtime

## Resumo

Atlas Learning Mutation Runtime fecha o caminho entre learning proposal, evaluation e application receipt. Ele permanece `building`: o service e o command existem, mas command tests e aplicacao operacional ainda precisam ser provados antes de qualquer claim de runtime ativo.

## Papel no Atlas

Learning nao pode virar auto-mutacao solta. Este runtime transforma proposals em avaliacao auditavel e, quando aprovado pelo operador, em receipt append-only. Ele preserva a regra: aprendizado sugere, governance decide, operador aprova mutacao.

## Onde Se Encaixa

Fica abaixo do Self-Improvement L7 e acima dos pontos mutaveis permitidos. Ele consulta Constitutional Kernel e Autonomy Admission antes de qualquer evaluation/apply.

## Contratos

- `atlas.learning.mutation_evaluation.v1` registra avaliacao, target_kind, score, kernel/admission e recomendacao.
- `atlas.learning.mutation_application_receipt.v1` registra proposta, approval receipt, hashes, rollback hint e decisoes.
- Aplicacao exige approval humano e backup do conteudo original.

## Fluxo

1. Learning proposal entra por `AtlasLearningProposalService`.
2. Runtime avalia target, score e blocklist.
3. Kernel e Admission decidem se evaluation pode prosseguir.
4. Operador aprova com receipt.
5. Apply registra hashes e rollback hint em log append-only.

## Regras para IA

- Nao aplique proposal automaticamente.
- Nao toque petreos, policy, provider config, memory critical path, claim policy ou external rivals certification.
- Nao registre command antes de teste de evaluation e blocklist.
- Nao trate este doc como permissao para mutar runtime critico.

## Escopo de Implementacao

Escopo atual: service `AtlasLearningMutationRuntimeService` como executor guardado e command `atlas:learning:mutation` para evaluate/apply/list. Fora do escopo atual: UI, auto-apply, mutacao de policy/provider/memoria critica e relaxamento de approval humano.

## Dependencias

- `AtlasLearningProposalService`
- `AtlasSddLearningProposal`
- `AtlasConstitutionalKernelService`
- `AtlasAutonomyAdmissionService`
- Self-Improvement L7

## Evidencias

- `app/Services/Ai/Compounding/AtlasLearningMutationRuntimeService.php`
- `app/Console/Commands/AtlasLearningMutationCommand.php`
- `app/Services/Ai/Compounding/AtlasLearningProposalService.php`
- `app/Models/AtlasSddLearningProposal.php`

## Riscos

- Uma IA pode interpretar learning como permissao para auto-mutacao.
- Uma whitelist ampla demais pode vazar para policy/provider/memoria.
- Approval receipt fraco pode virar bypass de operador.

## Exemplos

Uma proposal para melhorar doc skeleton pode ser avaliada. Uma proposal para mudar invariant petreo deve ser bloqueada, mesmo com alto impact_score.

## Proximas Acoes

1. Adicionar testes unitarios de evaluation segura.
2. Adicionar testes de blocklist petrea.
3. Adicionar teste focado para `AtlasLearningMutationCommand`.
4. Rodar docs-health e ADER antes de declarar pronto.

## Por que existe

Audit canon 2026-05-26 identificou gap leverage 6/10: existe `AtlasLearningProposalService` que captura proposals e `AtlasSddLearningProposal` model que persiste, mas **falta o executor que fecha o loop**: proposal → evaluation → safe mutation → receipt. Sem isso, Learning é one-way in (proposals entram, nada sai).

Este runtime fecha esse gap mantendo invariantes pétreos absolutos.

## Princípio de não-duplicação

| Conceito | Fonte canon |
|----------|-------------|
| Proposal capture | `AtlasLearningProposalService` (existing) |
| Proposal model | `AtlasSddLearningProposal` (existing) |
| Pétreo invariants | `AtlasConstitutionalKernelService` |
| Autonomy admission | `AtlasAutonomyAdmissionService` |
| SDD Learning Curator role | `app/Services/Ai/Programming/Sdd/Agents/` canon |
| L7 closed loop | `atlas-self-improvement-closed-loop-level7-v1.md` |

## API

```php
evaluate(string $proposalId, array $context = []): array  // atlas.learning.mutation_evaluation.v1
apply(string $proposalId, string $proposalHash, string $approverActor, string $approvalReceipt): array  // atlas.learning.mutation_application_receipt.v1
listEvaluations(): array
listApplications(): array
```

## Gates pétreos (NUNCA negociáveis)

1. **`requires_operator_approval = true`** hardcoded em toda application
2. **Mutation target whitelist** — apenas:
   - `prompt_template` (Atlas Dev/Forge prompt templates)
   - `doc_skeleton` (canonical doc templates)
   - `elastic_runtime_threshold` (dentro do range declarado em Constitutional Kernel)
3. **Mutation target blacklist** (pétreo):
   - `constitutional_kernel_invariant` — BLOCK absoluto
   - `policy_runtime` — BLOCK absoluto
   - `provider_config` — BLOCK absoluto
   - `memory_critical_path` — BLOCK absoluto
   - `claim_policy` — BLOCK absoluto
   - `external_rivals_certification` — BLOCK absoluto

4. **Constitutional Kernel + Autonomy Admission obrigatórios** antes de qualquer evaluation
5. **Approval receipt obrigatório** em apply — operator must signed it (HMAC verification)
6. **Backup obrigatório** antes de mutação — original_content_hash gravado no receipt
7. **Append-only mutation log** — `storage/atlas/learning/mutations.jsonl`

## Evaluation envelope

```json
{
  "schema_version": "atlas.learning.mutation_evaluation.v1",
  "evaluation_id": "lme_...",
  "proposal_id": "...",
  "evaluated_at": "ISO",
  "safety_score": 0..1,
  "impact_score": 0..1,
  "target_kind": "prompt_template|doc_skeleton|elastic_runtime_threshold",
  "target_is_blacklisted": false,
  "kernel_decision": "...",
  "admission_decision": "...",
  "recommendation": "safe_to_apply|requires_review|reject",
  "reason": [...],
  "claim_policy": {
    "auto_apply_allowed": false,
    "requires_operator_approval": true
  }
}
```

## Application receipt

```json
{
  "schema_version": "atlas.learning.mutation_application_receipt.v1",
  "application_id": "lma_...",
  "applied_at": "ISO",
  "proposal_id": "...",
  "proposal_hash": "...",
  "approver_actor": "operator",
  "approval_receipt": "hmac:...",
  "target_kind": "...",
  "target_path": "...",
  "original_content_hash": "sha256:...",
  "new_content_hash": "sha256:...",
  "rollback_hint": "...",
  "kernel_decision": "...",
  "admission_decision": "...",
  "receipt_hash": "sha256:..."
}
```

## CLI

```
php artisan atlas:learning:mutation --action=evaluate --proposal-id=X [--json]
php artisan atlas:learning:mutation --action=apply --proposal-id=X --proposal-hash=Y --approver=operator --approval-receipt=hmac:Z [--json]
php artisan atlas:learning:mutation --action=list-evaluations [--json]
php artisan atlas:learning:mutation --action=list-applications [--json]
```

## Não-objetivos

- Não auto-aplica (operador sempre no loop)
- Não toca pétreos do Kernel
- Não muta policy/provider/memory_critical
- Não claim de winner/rivals
- Não substitui Self-Improvement L7 — alimenta
