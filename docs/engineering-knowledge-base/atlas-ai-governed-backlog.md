---
id: atlas-ai-governed-backlog
type: engineering_knowledge
title: Atlas AI Governed Backlog
status: active
category: roadmap-governance
priority: 78
summary: Contrato para preservar backlog legado de alto valor sem transformar notas pessoais, sensores ou ideias cruas em runtime, provider context ou roadmap automatico.
tags:
  - atlas-ai
  - backlog
  - governance
  - personal-development
capabilities:
  - backlog_governance
  - privacy_review
  - domain_promotion
  - qualitative_levels_roadmap
decisions:
  - Backlog legado e source material, nao compromisso de implementacao.
  - Itens de Personal Development, sensores e vida pessoal exigem privacy/redaction antes de qualquer promocao.
  - ROI legado ajuda triagem, mas nao substitui safety, domain owner, evidence e current roadmap.
  - Patamares qualitativos entram por `atlas-ai-qualitative-levels-roadmap.md` e fila QL, nao por execucao direta do roadmap longo.
  - Agent Behavior Contract entra como backlog ativo de qualidade de programacao, nao como prompt solto.
  - Novidades de labs/providers entram por Provider Release Ingestion: benchmark, AP, connector, skill pack, policy signal ou descarte governado.
maintenance:
  - Atualizar quando itens do resolver/root-md forem promovidos para domain specs, ADRs ou plans ativos.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-autonomy-power-backlog.md
  - docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md
  - docs/engineering-knowledge-base/domains/personal-development.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - resolver-o-que-vale-a-pena/root-md/Atlas_Gaps_Achamos_Nao_Esquecer.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-governed-backlog

graph_title: Atlas AI Governed Backlog

graph_world: atlas

graph_layer: flow

graph_kind: policy

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo

owner: roadmap-governance

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-governed-backlog.md

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
  - roadmap-governance

evidence:
  - docs/engineering-knowledge-base/atlas-ai-governed-backlog.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - flow
  - policy
  - roadmap-governance

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
# Atlas AI Governed Backlog

Este documento governa ideias legadas de alto valor. Ele existe para que o Atlas
nao perca bons gaps, mas tambem nao transforme lista antiga em roadmap
automatico.

## Regras

- Todo item promovido precisa declarar domain, flow, safety boundary, evidence e
  owner.
- Personal Development continua privado, non-clinical e plan-only por default.
- HealthKit, atividade digital, relacoes, estado mental e sensores pessoais
  exigem redaction e privacy review.
- Executive action, Calendar, Reminders e mutacoes externas exigem confirmacao
  humana e capability gate.
- Backlog de ROI nao pode bypassar Kernel, Master Architecture ou Domain Specs.

## Estados

| Estado | Significado |
|---|---|
| `source_material` | Ideia preservada, ainda nao triada. |
| `candidate` | Parece valiosa, mas precisa owner/safety/evidence. |
| `promoted` | Virou doc canonico, ADR, plan ativo ou issue governada. |
| `rejected` | Nao vale agora ou viola safety/strategy. |
| `archived` | Preservada apenas por historia. |

## Source Material

- `resolver-o-que-vale-a-pena/root-md/Atlas_Gaps_Achamos_Nao_Esquecer.md`
- `resolver-o-que-vale-a-pena/root-md/Atlas_Adendo_Sensor4_Atividade_Digital.md`

## Promoted Backlogs

- `atlas-ai-qualitative-levels-roadmap.md`: fila governada QL-0..QL-6 para
  patamares P1-P7, Rivals Strategy, dominio Strategic Decision plan-only,
  Curator mutation classes e presence/eclipse governance. O roadmap longo
  `docs/atlas-outro-patamar-roadmap.md` e source material expandido.
- `atlas-ai-autonomy-power-backlog.md`: fila governada de longo prazo para
  Dynamic Compute Market, Tool Synthesis, Zero-Click Shadow Mode, Real-World
  Feedback Loop, contexto multimodal continuo e transferencia de heuristica
  inter-dominios. Este doc e a fonte para esses superpoderes; este arquivo fica
  como guarda-chuva de governanca geral.
- `atlas-ai-agent-behavior-contract.md`: backlog ativo para absorver principios
  tipo Karpathy como contrato verificavel de agentes: suposicoes explicitas,
  simplicidade, diff cirurgico e loop de verificacao. Deve virar provider/identity
  fragment, Programming Domain contract e Quality Gate futuro.
- Provider releases: lancamentos como finance agents, realtime voice, long
  context, managed agents, connectors ou tool use avancado devem ser triados
  contra a tese. Se multiplicam Atlas, viram AP/benchmark/skill pack/connector;
  se so competem com provider, sao descartados ou viram baseline Rivals.

## Resumo

Contrato para preservar backlog legado de alto valor sem transformar notas pessoais, sensores ou ideias cruas em runtime, provider context ou roadmap automatico.

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
