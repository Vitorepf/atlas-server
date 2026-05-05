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
decisions:
  - Backlog legado e source material, nao compromisso de implementacao.
  - Itens de Personal Development, sensores e vida pessoal exigem privacy/redaction antes de qualquer promocao.
  - ROI legado ajuda triagem, mas nao substitui safety, domain owner, evidence e current roadmap.
maintenance:
  - Atualizar quando itens do resolver/root-md forem promovidos para domain specs, ADRs ou plans ativos.
related_paths:
  - docs/engineering-knowledge-base/domains/personal-development.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - resolver-o-que-vale-a-pena/root-md/Atlas_Gaps_Achamos_Nao_Esquecer.md
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
