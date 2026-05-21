---
id: atlas-ai-skill-system
type: engineering_knowledge
title: Atlas AI Skill System
status: active
category: runtime-governance
priority: 83
summary: Contrato canonico para skills do Atlas AI como capacidades pequenas, versionadas, provider-neutral, avaliadas por evidencia e ativadas por roteamento governado.
tags:
  - atlas-ai
  - skills
  - runtime
  - governance
capabilities:
  - skill_registry
  - provider_neutral_runtime
  - evidence_based_promotion
decisions:
  - Skill e contrato operacional versionado, nao persona, prompt bonito ou agente.
  - Skill vem antes de agente; agente e papel temporario executado sob skill, flow e policy.
  - Skill sem eval/evidence nao vira default, salvo excecao constitucional explicitamente revisada.
maintenance:
  - Atualizar quando skill routing, manifests, evals, trace metadata ou provider projection mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-layer-0-glossary.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
  - resolver-o-que-vale-a-pena/root-md/Atlas_AI_Skill_System_v1.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-skill-system

graph_title: Atlas AI Skill System

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Skill System
canonical_name: Atlas AI Skill System
technical_name: atlas-ai-skill-system
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-ai-skill-system.md

owner: runtime-governance

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-skill-system.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - runtime-governance

evidence:
  - docs/engineering-knowledge-base/atlas-ai-skill-system.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - policy
  - runtime-governance

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Skill System

Skills transformam uso recorrente de modelos em capacidade Atlas: pequena,
versionada, provider-neutral, medida contra baseline e melhorada com evidencia.

## Fronteira

| Conceito | Regra |
|---|---|
| Skill | Lente, policy, procedimento, output contract, permissao e eval. |
| Workflow | Sequencia que pode combinar uma skill principal e poucas auxiliares. |
| Agent | Papel temporario em uma operacao; nao e autoridade propria. |
| Provider | Motor substituivel que executa sob a skill. |
| Tool Runtime | Executa acoes permitidas pela policy/skill/receipt. |

## Invariantes

- Poucas skills fortes vencem catalogo gigante.
- Metadados leves entram primeiro; corpo da skill so entra quando necessario.
- Skills pedem context packs pequenos e provider-safe.
- Skill externa e dependencia de software: precisa revisao, pinning e rollback.
- Toda resposta relevante deve poder registrar skill principal, versao/hash,
  provider, context refs e outcome.
- Conflito entre skills deve ser resolvido por Output Governor, Kernel policy
  ou flow owner, nessa ordem.

## Lifecycle

| Estado | Entrada | Saida |
|---|---|---|
| `draft` | Ideia recorrente ou falha observada. | Spec curta e eval proposta. |
| `candidate` | Metadados, permission scope e casos A/B. | Traces comparaveis. |
| `ready` | Evidencia de ganho contra baseline. | Pode ser ativada por router. |
| `default` | Revisao humana e safety aprovadas. | Entra por padrao nos flows elegiveis. |
| `deprecated` | Skill piorou, conflitou ou foi substituida. | Redirect/rollback e trace historico. |

## Source Material

- `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Skill_System_v1.md`

## Resumo

Contrato canonico para skills do Atlas AI como capacidades pequenas, versionadas, provider-neutral, avaliadas por evidencia e ativadas por roteamento governado.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
