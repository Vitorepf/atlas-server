---
id: atlas-ai-domain-routing-governance
type: engineering_knowledge
title: Atlas AI Domain Routing Governance
status: active
category: architecture
priority: 99
summary: Guia canonico para IAs decidirem qual dominio, flow, profile ou novo dominio usar no Atlas AI, evitando duplicacao, bagunca, prompts soltos e criacao indevida de dominios.
tags:
  - atlas-ai
  - domains
  - routing
  - governance
  - domain-runtime
capabilities:
  - domain_routing_governance
  - flow_selection
  - domain_creation_gate
  - prompt_to_domain_matrix
decisions:
  - Toda intencao nao trivial deve ser roteada para dominio existente antes de propor dominio novo.
  - Novo dominio e excecao; flow/profile/capability dentro de dominio existente e o default.
  - Dominio nao e ferramenta, agent, surface, provider, linguagem, runtime tecnico ou departamento interno.
  - Ambiguidade deve gerar primary_domain, secondary_domains, reason, blockers e next_action, nao chute silencioso.
maintenance:
  - Atualize este guia quando domain specs, Domain Runtime Contract, Router Runtime ou Domain Company Runtimes mudarem.
  - Manter abaixo de 520 linhas.
related_paths:
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-domain-routing-governance
graph_title: Atlas AI Domain Routing Governance
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-ai-domain-specs-index
graph_status: active
graph_source: repo
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/domain-routing-governance.md
allowed_changes:
  - Refinar matriz prompt->dominio, gates de criacao de dominio e regras de flow/profile.
forbidden_changes:
  - Criar dominio novo sem aplicar Domain Creation Gate.
  - Tratar surface, tool, provider, linguagem, agent ou harness como dominio.
depends_on:
  - atlas-domain-company-runtimes
  - atlas-domain-runtime-contract
  - atlas-ai-router-runtime-enterprise-upgrade
flows_to:
  - atlas_router_runtime
  - atlas_domain_runtime
unlocks:
  - reliable_multi_domain_routing
governs:
  - atlas_ai.domain_routing
evidence:
  - docs/engineering-knowledge-base/domains/domain-routing-governance.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Resumo, Decisao Rapida, Matriz Prompt->Dominio, Flow vs Dominio Novo e Domain Creation Gate antes de criar ou alterar dominio.
quality_gates:
  - existing-domain-checked
  - flow-profile-checked
  - domain-creation-gate-passed
  - routing-reason-recorded
failure_modes:
  - IA cria dominio novo para cada prompt.
  - IA chama Programming para qualquer coisa com ferramenta.
  - IA confunde Security defensivo com Cyber Company Runtime ofensivo autorizado.
  - IA confunde Strategic Decision review-only com Venture Studio operacional.
observability_signals:
  - primary_domain
  - secondary_domains
  - selected_flow
  - creation_gate_status
next_actions:
  - Manter a matriz sincronizada com manifests e Router Runtime.
line_limit: 520
---
# Atlas AI Domain Routing Governance

## Resumo

Este guia ensina uma IA a decidir rapidamente qual dominio do Atlas entra quando
o operador descreve um fluxo, meta ou problema. O objetivo e evitar bagunca:
dominio duplicado, flow solto, agent isolado, ferramenta virando dominio ou
prompt humano sendo tratado sem governanca.

Regra central: **primeiro tente dominio existente; depois flow/profile; depois
capability; dominio novo somente apos gate formal.**

## Papel no Atlas

Este documento governa roteamento sem substituir:

- `atlas-domain-company-runtimes.md`, que define a forma de empresa digital;
- `atlas-domain-runtime-contract.md`, que define manifest/registry/capability;
- `atlas-ai-router-runtime-enterprise-upgrade.md`, que implementa roteamento;
- specs dentro de `domains/`, que definem semantica especifica.

## Onde Se Encaixa

```text
Prompt / Objective
-> Intent Kernel
-> Domain Routing Governance
-> Domain Runtime Registry
-> Flow/Profile/Capability
-> Policy/Evidence/Tool gates
```

## Decisao Rapida

1. Identifique o verbo principal: pesquisar, programar, analisar, vender,
   investir, proteger, automatizar, aprender, escrever, operar.
2. Identifique o objeto: codigo, mercado, carteira, campanha, vulnerabilidade,
   processo, rotina, documento, saude, decisao.
3. Escolha `primary_domain`.
4. Adicione `secondary_domains` quando a meta exige composicao.
5. Escolha flow/profile dentro do dominio.
6. Se nao couber, aplique Domain Creation Gate.
7. Registre motivo, risco, gates e proxima acao.

## Matriz Prompt Para Dominio

| Sinal no prompt | Primary domain | Secondary domains comuns | Observacao |
|---|---|---|---|
| implementar, corrigir bug, refatorar, teste, PR | `programming` | `qa`, `security`, `operations` | Forge entra quando for obra pesada. |
| pesquisar fontes, relatorio, papers, mercado | `research` | `strategy`, `finance`, `marketing` | Exige source plan e citations. |
| oportunidade, criar empresa, escalar, TAM, GTM | `strategy` | `research`, `marketing`, `finance` | Venture Studio operacional, nao decision review. |
| carteira, ativo, valuation, risco, macro, trade | `finance` | `research`, `strategy` | Live trade bloqueado por default. |
| campanha, copy, criativo, funil, ads, ICP | `marketing` | `research`, `strategy`, `sales` | Publicacao/gasto exige approval. |
| pentest, bug bounty, vulnerabilidade, compliance | `security` ou `cyber_company` | `programming`, `operations` | Ofensivo so com autorizacao/RoE. |
| rotina, habito, estudo, professor, performance pessoal | `personal_development` ou `learning` | `health` | Non-clinical por default. |
| texto, artigo, roteiro, editar, publicar | `writing` | `research`, `marketing` | Publicacao exige review. |
| deploy, incidente, runbook, sistema fora do ar | `operations` | `programming`, `security` | Mutacao infra exige approval. |
| schedule, cron, daemon, rodar depois | `background` | `operations`, `policy` | Nao inicia job sem stop conditions. |
| pergunta simples ou conversa sem tarefa | `general` | dominio especializado se necessario | General triage, nao substitui dominio. |
| usar browser/API/terminal, criar ferramenta | `automation` ou `tool_factory` | `policy`, `evidence` | Ferramenta nao e dominio por si so. |

## Dominios Canonicos

| Dominio | Quando usar | Limite principal |
|---|---|---|
| `programming` | codigo, debug, review, QA, Forge | nao resolver negocio/marketing como codigo. |
| `research` | fonte externa, sintese, contradicoes | nao entregar resumo sem fontes. |
| `strategy` | oportunidade, venture, mercado, operacao | nao executar compromissos autonomos. |
| `finance` | research, valuation, portfolio, risk | sem ordem real por default. |
| `marketing` | campanha, ICP, copy, funil | sem publicar/gastar sem approval. |
| `security` | review defensivo, AppSec, GRC | exploit/scan so com autorizacao. |
| `personal_development` | metas, rotina, foco, reflexao | non-clinical, privado por default. |
| `learning` | estudo e pratica deliberada | nao altera Learning Plane core. |
| `writing` | rascunho, edicao, voice review | sem auto-publicacao. |
| `operations` | diagnostico, runbook, incidente | sem deploy/restart/delete sem gate. |
| `qa` | acceptance, evidence audit | nao executa testes no lugar do dono. |
| `background` | recorrencia, heartbeat, cron | nao agenda sem permissao. |
| `self_improvement` | melhorar o Atlas | proposal/review antes de mutacao. |
| `general` | conversa simples e triagem | nao burla dominios especializados. |

## Flow Vs Dominio Novo

Use **flow novo** quando:

- o objetivo usa a mesma ontologia do dominio;
- os artifacts e gates sao parecidos;
- so muda profundidade, risco, autonomia ou ferramenta;
- pode ser implementado como profile/capability.

Use **dominio novo** somente quando:

- ha charter proprio;
- ha ontologia propria;
- ha artifacts e metrics proprios;
- ha policies/gates distintos;
- ha memoria e evidence schema especificos;
- nao cabe limpo em dominio existente.

Exemplos:

- `programming.frontend` e flow/profile de `programming`, nao dominio.
- `finance.valuation` e flow de `finance`, nao dominio.
- `cyber.bug_bounty_authorized` pode ser flow de `security/cyber_company`;
  nao cria dominio `bug_bounty`.
- `venture_studio` e dominio/Company Runtime separado de
  `strategic_decision` quando inclui mercado, GTM, experimentos e operacao.

## Domain Creation Gate

Antes de criar dominio, responda:

1. Qual dominio existente foi testado e por que falhou?
2. Por que nao basta flow/profile/capability?
3. Qual charter unico?
4. Qual ontologia unica?
5. Quais artifacts finais?
6. Quais policies, forbidden actions e gates?
7. Quais evidence schemas?
8. Quais metrics e maturity stages?
9. Quais handoffs com dominios existentes?
10. Quem e owner e qual promotion gate?

Se qualquer resposta for fraca, crie flow/profile no dominio existente.

## Contratos

- `atlas.ai.domain_routing_decision.v1`
- `atlas.ai.domain_creation_gate.v1`
- `atlas.ai.flow_profile_selection.v1`

Campos minimos:

- `prompt_or_objective_ref`;
- `primary_domain`;
- `secondary_domains`;
- `selected_flow`;
- `selected_profile`;
- `routing_reason`;
- `rejected_domains`;
- `risk_level`;
- `required_gates`;
- `domain_creation_gate_status`;
- `next_action`.

## Fluxo

1. Receber prompt/objective.
2. Classificar intent e risco.
3. Consultar matriz prompt->dominio.
4. Consultar manifests existentes.
5. Selecionar dominio primario e secundarios.
6. Selecionar flow/profile/capability.
7. Se nao couber, rodar Domain Creation Gate.
8. Emitir decision receipt.
9. Encaminhar para Domain Runtime.

## Regras para IA

- Nao criar dominio por nome bonito.
- Nao criar dominio para uma ferramenta.
- Nao criar dominio para uma linguagem/framework.
- Nao criar dominio para um agent/persona.
- Nao criar dominio para uma tela/surface.
- Nao chamar `general` se um dominio especializado se aplica.
- Nao chamar `programming` so porque a solucao pode exigir codigo.
- Nao misturar `strategic_decision` review-only com Venture Studio operacional.
- Nao promover dominio para implemented/ready sem tests, docs e registry.

## Escopo de Implementacao

Implementacoes devem usar este guia para:

- treinar/validar Router Runtime;
- criar fixtures de roteamento;
- revisar prompts ambiguos;
- decidir quando criar manifests;
- auditar handoffs e domain decisions.

## Dependencias

- Domain Company Runtimes;
- Domain Runtime Contract;
- Router Runtime;
- Policy/Evidence/Tool Runtime;
- Domain specs individuais.

## Evidencias

Evidencia aceitavel:

- decision receipt de roteamento;
- manifest consultado;
- lista de dominios rejeitados;
- Domain Creation Gate preenchido;
- tests de routing fixtures;
- docs-health e registry readiness.

## Riscos

- Explosao de dominios pequenos.
- Dominios genericos demais.
- Perda de contexto em handoff.
- Safety bypass por dominio errado.
- UX confusa por nomes inconsistentes.

## Exemplos

Prompt: "crie uma campanha para vender meu SaaS".
Rota: `marketing`; secundarios `strategy`, `research`; flow `campaign_plan`.

Prompt: "analise minha carteira e diga riscos".
Rota: `finance`; flow `portfolio_analysis`; live trade bloqueado.

Prompt: "ache bugs num programa HackerOne".
Rota: `security/cyber_company`; exige autorizacao, escopo e RoE.

Prompt: "automatize extracao de PDF mensal".
Rota: `automation/tool_factory`; secundarios `data`, `operations`.

## Proximas Acoes

1. Criar fixtures de roteamento para cada linha da matriz.
2. Conectar Router Runtime a manifests reais.
3. Auditar dominios implemented/ready contra Company Runtime.
4. Separar Marketing/Research/Strategy specs dedicadas quando os runtimes
   estiverem implementados.
