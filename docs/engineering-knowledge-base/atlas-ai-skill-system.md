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
