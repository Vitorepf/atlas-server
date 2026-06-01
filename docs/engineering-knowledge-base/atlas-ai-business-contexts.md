---
id: atlas-ai-business-contexts
type: engineering_knowledge
title: Atlas AI Business Contexts
status: active
category: architecture
priority: 97
summary: Contrato canonico que separa empresas/produtos do Vitor, como Blackink, dos dominios cognitivos e operacionais do Atlas AI.
tags:
  - atlas-ai
  - business-context
  - blackink
  - domains
  - mobile
capabilities:
  - business_context_routing
  - product_domain_governance
  - company_context_memory
decisions:
  - Blackink e futuras empresas entram como Business Contexts/Product Domains, nao como Atlas AI Domains por padrao.
  - Atlas AI Domains sao capacidades cognitivas/operacionais como Programming, Marketing, Finance, Operations e Strategic Decision.
  - Um pedido pode ter simultaneamente business_context=blackink e ai_domain=programming.
  - Uma empresa so vira Atlas AI Domain dedicado se exigir runtime, gates, memoria, ferramentas e evidence schema proprios.
  - Business Contexts em producao devem usar privacidade conservadora por default.
maintenance:
  - Manter abaixo de 240 linhas.
  - Atualizar quando criar nova empresa, produto, workspace empresarial ou business context.
  - Atualizar antes de alterar app mobile, routing sheet, capture domains, AtlasVault business folders ou policy de privacidade por empresa.
  - Nao priorizar hardening estetico/renomeacao de UI antes das frentes estruturais da arquitetura-mae, salvo quando a tarefa for mobile/business-context.
related_paths:
  - config/atlas.php
  - app/Services/AtlasDomainRegistry.php
  - app/Services/CapturePrivacyService.php
  - app/Services/Ai/Surface/DomainCatalogSurfaceSelectionService.php
  - app/Console/Commands/AtlasAiDecideCommand.php
  - docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-business-contexts

graph_title: Atlas AI Business Contexts

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Business Contexts
canonical_name: Atlas AI Business Contexts
technical_name: atlas-ai-business-contexts
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-business-contexts.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-business-contexts.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-business-contexts.md

evidence_refs:
  - symbol: AtlasDomainRegistry
  - command: atlas:ai:decide
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture

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
# Atlas AI Business Contexts

Este documento responde onde Blackink e futuras empresas entram no Atlas.

## Regra Mae

```text
Empresa/produto do Vitor = Business Context / Product Domain.
Capacidade cognitiva do Atlas = Atlas AI Domain.
```

Blackink nao deve ser o Atlas, nem deve virar dominio cognitivo so por ser
importante. Blackink e um contexto de negocio de alta prioridade sobre o qual os
dominios do Atlas trabalham.

## Vocabulário Canonico

| Termo | Exemplo | O que significa |
|---|---|---|
| Business Context | `blackink` | Empresa, produto, cliente, workspace ou fonte de renda do Vitor. |
| Product Domain | `blackink`, `atlas`, `financas`, `saude` | Nome legado usado por capturas, tasks e app mobile. |
| Atlas AI Domain | `programming`, `marketing`, `finance`, `operations` | Capacidade cognitiva/operacional com flows, gates e runtime. |
| Flow | `programming.repair`, `marketing.campaign` | Processo executavel dentro de um AI Domain. |

`product_domain` pode continuar existindo em payloads por compatibilidade, mas a
documentacao e novas UIs devem preferir `business_context` quando o significado
for empresa/produto.

## Blackink

Blackink e:

1. empresa/produto em producao;
2. fonte de renda;
3. contexto operacional sensivel;
4. alvo de tarefas tecnicas, produto, marketing, suporte, operacao e estrategia.

Blackink nao e:

1. substituto do dominio Programming;
2. substituto do dominio Marketing;
3. substituto do dominio Finance;
4. fonte canonica da arquitetura do Atlas;
5. default implicito para todo conteudo de negocio.

Exemplos corretos:

| Pedido | Business Context | Atlas AI Domain | Flow |
|---|---|---|---|
| Corrigir bug em producao da Blackink | `blackink` | `programming` | `programming.repair` ou `programming.forge` |
| Criar campanha para Blackink | `blackink` | `marketing` | `marketing.campaign` |
| Avaliar precificacao da Blackink | `blackink` | `finance` + `strategic_decision` | `finance.risk_review` ou `strategic_decision.review` |
| Revisar roadmap da Blackink | `blackink` | `operations` ou `strategic_decision` | `operations.diagnostic` |

## Futuras Empresas

Toda empresa futura deve entrar primeiro como Business Context:

```text
business_context=<empresa>
ai_domain=<capacidade>
flow=<processo>
sensitivity=<normal|private|sensitive>
environment=<local|staging|production>
```

Ela so deve virar Atlas AI Domain dedicado quando cumprir todos os criterios:

1. tem metodologia propria que nao cabe em Programming, Marketing, Finance,
   Operations ou Strategic Decision;
2. exige tools ou runtime proprios;
3. exige gates especificos;
4. tem memoria/projecao propria;
5. tem evidence schema proprio;
6. tem benchmark ou dataset proprio;
7. tem owner e documentacao canonica;
8. passa por Domain Onboarding e arquitetura valida.

Se nao cumprir esses criterios, criar um AI Domain dedicado e duplicacao.

## Mobile E Surfaces

O app mobile pode mostrar Blackink em capturas, inbox, tarefas e contexto de
negocio. No routing de IA, ele deve separar duas escolhas:

1. contexto: Blackink, Atlas, Financas, Saude, outra empresa;
2. capacidade/fluxo: Programming, Marketing, Finance, Operations, Strategic
   Decision, Self-Improvement.

Regra de UI:

```text
"para Blackink" descreve contexto.
"programacao/review/campanha/decisao" descreve AI Domain + Flow.
```

Se a UI usar uma unica palavra `dominio`, ela deve explicar internamente se esta
preenchendo `product_domain/business_context` ou `domain_id`.

## Privacidade E Produção

Business Context de empresa em producao deve ser conservador:

1. default `private`;
2. IA externa bloqueada para conteudo private/sensitive;
3. permissao explicita para enviar conteudo normal a provider externo;
4. logs e Evidence Ledger com source refs e redaction quando necessario;
5. deploy, dados de cliente, chaves, incidentes e receita nunca tratados como
   conteudo normal por default.

Blackink usa default privado porque envolve cliente, receita, operacao e codigo
de producao.

## Relação Correta

Atlas e o sistema operacional cognitivo.

Blackink e uma das empresas operadas com ajuda do Atlas.

Os dominios cognitivos do Atlas executam trabalho sobre Blackink, mas Blackink
nao deve definir provider, runtime, gates, repair, memoria global ou arquitetura
do Atlas.

## Definition Of Done

Antes de adicionar empresa/produto novo:

1. criar/atualizar registro em `config/atlas.php` ou tabela `atlas_domains`;
2. definir label, descricao, cor, default_sensitivity e external_ai_policy;
3. garantir que nao aparece como `domain_id` em `GET /ai/domains` sem Domain
   Onboarding formal;
4. testar payload com `business_context/product_domain` separado de `domain_id`;
5. atualizar docs e, se houver app mobile, labels de UI para evitar confusao.

## Backlog Futuro Governado

O essencial ja esta resolvido: Blackink e futuras empresas sao Business Contexts,
nao Atlas AI Domains por padrao. Os itens abaixo sao hardening futuro e nao devem
interromper frentes mais importantes da arquitetura-mae:

1. adicionar `business_context` como campo canonico novo, mantendo
   `product_domain` como alias legado;
2. ajustar a UI mobile para separar visualmente `contexto` de `AI Domain/Flow`;
3. criar teste explicito garantindo que `blackink` nao aparece em
   `GET /ai/domains`;
4. criar checklist/runbook curto para cadastrar futuras empresas.

Uma IA futura deve executar esses itens quando estiver trabalhando
especificamente em mobile routing, payload contracts ou governanca de empresas.
Fora desse escopo, manter a decisao atual e seguir prioridades maiores.

## Resumo

Contrato canonico que separa empresas/produtos do Vitor, como Blackink, dos dominios cognitivos e operacionais do Atlas AI.

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
