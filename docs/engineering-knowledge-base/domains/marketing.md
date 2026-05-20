---
id: atlas-ai-marketing-domain
type: engineering_knowledge
title: Atlas AI Marketing Domain
status: active
category: architecture
priority: 90
summary: Contrato canonico review-only do dominio Marketing / Growth para estrategia, ICP, campanha, copy, funil, brand review, analytics e experimentos sem publicacao, gasto ou promessa externa sem aprovacao humana.
tags:
  - atlas-ai
  - domains
  - marketing
  - growth
  - approval
capabilities:
  - marketing_domain
  - campaign_planning
  - brand_review
  - funnel_analysis
  - experiment_design
decisions:
  - Marketing e dominio de estrategia, pesquisa, copy, campanha, funil, analytics e experimentos.
  - Marketing nao publica, nao gasta budget, nao altera campanha real e nao promete resultado externo sem aprovacao humana.
  - Saidas devem ser review-only ate existir runtime especializado, gates de budget/publicacao e evidence pack.
maintenance:
  - Atualize este documento quando Marketing ganhar runtime/orchestrator dedicado, gates novos, approval flow ou artefatos canonicos.
  - Leia junto de domain-routing-governance, domain runtime contract e master architecture antes de implementar qualquer automacao de Marketing.
related_paths:
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/domains/domain-routing-governance.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-marketing-domain
graph_title: Atlas AI Marketing Domain
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: building
graph_source: repo
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/marketing.md
allowed_changes:
  - Criar planejamento, pesquisa, copy, experimentos, analise de funil e proposta de campanha em modo review-only.
  - Adicionar gates quando existirem testes e receipts de publicacao/budget.
forbidden_changes:
  - Publicar conteudo externo automaticamente.
  - Gastar budget, alterar campanha real ou enviar promessa comercial sem approval humano.
  - Declarar Marketing implemented/ready sem runtime dedicado, gates e evidencia.
depends_on:
  - atlas-ai-documentation-operating-system
  - domain-routing-governance
  - atlas-domain-runtime-contract
flows_to:
  - atlas-cartography
  - atlas-code
unlocks:
  - marketing-review-only-planning
governs:
  - marketing.copy
  - marketing.campaign_planning
  - marketing.experiment_design
evidence:
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia este contrato antes de pedir para IA criar campanha, copy, funil, budget, growth experiment ou publicacao.
ai_usage_notes:
  - Marketing pode planejar e revisar; qualquer publicacao, spend ou promessa externa exige approval humano e evidence pack.
quality_gates:
  - docs-health-passes
  - human-approval-before-publish-or-spend
failure_modes:
  - IA publica ou gasta sem approval humano.
  - IA promete resultado de marketing sem evidencia.
  - Marketing e confundido com dominio implemented-ready sem runtime dedicado.
observability_signals:
  - marketing-approval-required
  - docs-health-status
next_actions:
  - Criar runtime/orchestrator dedicado apenas quando gates de publicacao, budget, brand e evidence estiverem especificados.
  - Mapear fluxos de ICP, campanha, copy, funil, analytics e experimentos em docs dedicadas.
line_limit: 220
---
# Atlas AI Marketing Domain

## Resumo

Marketing / Growth e o dominio do Atlas AI para estrategia de mercado, ICP,
campanhas, copy, brand review, funil, analytics e experimentos. Hoje ele deve
ser tratado como planejamento e revisao governada, nao como automacao livre.

## Papel no Atlas

Este dominio ajuda a transformar contexto de negocio em propostas de campanha,
mensagens, hipoteses de experimento e analises de funil. Ele nao substitui
approval humano para publicar, gastar budget ou assumir compromisso externo.

## Onde Se Encaixa

Marketing fica no Domain Plane. Ele depende do Kernel, Policy, Evidence,
Business Context e Human Review. Quando aparecer na Cartografia, deve ser
visto como dominio lateral que alimenta fluxos de planejamento, nao como
etapa principal do pipeline operacional.

## Contratos

- Saida padrao: review-only.
- Publicacao externa: sempre bloqueada ate approval humano.
- Spend/budget: sempre bloqueado ate approval humano.
- Brand/legal claims: precisam de revisao humana quando houver risco externo.
- Evidencia: proposta deve citar fonte, contexto, objetivo e risco.

## Fluxo

```text
Business Context
-> Marketing brief
-> ICP / audience / offer
-> Copy / campaign / funnel hypothesis
-> Evidence and risk review
-> Human approval
-> Only then external publish/spend
```

## Regras para IA

- Nao publicar.
- Nao gastar.
- Nao prometer resultado.
- Nao declarar campanha pronta sem evidencia.
- Nao confundir planejamento de Marketing com runtime implemented-ready.

## Evidencias

- `docs/engineering-knowledge-base/domains/README.md`
- `docs/engineering-knowledge-base/atlas-domain-runtime-contract.md`
- `docs/engineering-knowledge-base/atlas-domain-company-runtimes.md`

## Escopo de Implementacao

Implementacoes de Marketing devem permanecer em comandos, services, migrations,
tests e docs do dominio de AI/Marketing ou da holding quando forem parte do
portfolio enterprise. Mudancas com publicacao, spend, CRM write, ads write ou
claims comerciais externos exigem gate dedicado e approval humano.

## Dependencias

- Kernel/Maestro para decisao, policy e receipts.
- Evidence Ledger e source registry para claims e contexto.
- Approval gate para publish, spend, CRM write e experimentos externos.
- Domain Runtime Contract para manifest, capabilities e artefatos.

## Riscos

- Publicacao indevida.
- Gasto indevido.
- Promessa comercial sem evidencia.
- Alucinacao de audiencia, ICP ou resultado.

## Exemplos

- Permitido: gerar brief, ICP, copy draft, funnel analysis ou experiment plan
  review-only com fontes, riscos e criterio de aprovacao.
- Bloqueado sem approval: publicar campanha, gastar budget, alterar audiencia
  real, escrever em CRM ou prometer resultado externo.

## Proximas Acoes

1. Criar docs dedicadas para ICP, campaign planning, copy review, funnel
   analysis e experiment design.
2. Criar gates de budget, brand, legal e approval antes de qualquer runtime.
3. Adicionar testes quando o runtime dedicado existir.
