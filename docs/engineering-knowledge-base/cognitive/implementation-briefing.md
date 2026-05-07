---
id: atlas-ai-cognitive-implementation-briefing
type: engineering_knowledge
title: Atlas AI Cognitive Plane - Implementation Briefing
status: active
category: implementation-governance
priority: 98
summary: Briefing canonico para qualquer IA implementar o Cognitive Plane sem confundir APs, comandos, status, maturidade, validacoes ou fronteiras de arquitetura.
tags:
  - atlas-ai
  - cognitive
  - implementation
  - governance
  - ap
capabilities:
  - cognitive_implementation_briefing
  - cognitive_ap_governance
decisions:
  - Cognitive Plane e sub-arquitetura seria do Atlas AI, nao feature solta do domain learning.
  - Este briefing e a porta operacional para implementar APs cognitivos; README e a porta de leitura humana.
  - Comandos canonicos locais sao `php artisan atlas:*`; wrappers de produto `atlas ...` podem existir depois, mas nao sao contrato de implementacao.
  - Status de AP precisa distinguir read-model, runtime, surface integration e self-improvement; `implemented` generico e proibido.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar sempre que um AP cognitivo mudar de status, criar migration, command, gate, SLO ou event family.
related_paths:
  - docs/engineering-knowledge-base/cognitive/README.md
  - docs/engineering-knowledge-base/cognitive/overview.md
  - docs/engineering-knowledge-base/cognitive/principles.md
  - docs/engineering-knowledge-base/cognitive/capabilities-core.md
  - docs/engineering-knowledge-base/cognitive/multiplier-edge.md
  - docs/engineering-knowledge-base/cognitive/pipeline-overlay.md
  - docs/engineering-knowledge-base/cognitive/roadmap.md
  - docs/engineering-knowledge-base/domains/learning.md
  - docs/ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md
  - docs/ap/AP-164-cognitive-worked-example-engine.md
  - docs/ap/AP-165-cognitive-process-pattern-catalog.md
  - docs/ap/AP-166-cognitive-failure-signature-tracker.md
  - docs/ap/AP-167-cognitive-self-regulated-learning-orchestrator.md
  - docs/ap/AP-168-cognitive-productive-failure-flow.md
  - docs/ap/AP-169-cognitive-personal-worked-examples-generator.md
  - docs/ap/AP-170-cognitive-predictive-failure-insertion.md
owner: atlas-ai
layer: 2-and-3
line_limit: 260
---

# Atlas AI Cognitive Plane — Implementation Briefing

Este doc e o pacote operacional para Codex, Claude, Gemini ou humano implementar o Cognitive Plane sem ler conversa antiga.

## Definicao

O **Cognitive Development Plane** governa a trajetoria cognitiva do operador: estudo, consolidacao, dominio, transferencia, descoberta de Pareto, reuso de padroes humanos e aprendizado por falha diferente, sempre dentro do pipeline canonico de 17 etapas.

## Ordem obrigatoria de leitura

1. `atlas-ai-thesis-multiplier-channel.md`
2. `atlas-ai-canonical-architecture-index.md`
3. `atlas-ai-pipeline.md`
4. `atlas-ai-core-vs-domain.md`
5. `cognitive/README.md`
6. `cognitive/overview.md`
7. `cognitive/principles.md`
8. `cognitive/capabilities-core.md`
9. `cognitive/multiplier-edge.md`
10. `cognitive/pipeline-overlay.md`
11. `cognitive/roadmap.md`
12. `domains/learning.md`
13. AP especifico em `docs/ap/AP-###-cognitive-*.md`

## Taxonomia de status

| Status | Significado | Pode ser chamado pronto? |
|---|---|---|
| `scaffold` | contrato desenhado; sem runtime confiavel | nao |
| `implemented-operational-read-model` | persiste, consulta, testa e emite evidence; consumers ainda podem faltar | parcialmente |
| `implemented-runtime` | executa fluxo principal end-to-end sob receipt/gates | sim, para runtime |
| `implemented-surface-integrated` | CLI/App/Mobile/Voice expõem UX canonica | sim, para uso diario |
| `implemented-self-improving` | Self-Improvement consome metricas e gera proposal governada | sim, maturidade alta |

`implemented` generico e proibido em novos APs porque esconde lacuna.

## Estado dos APs

| AP | Capability | Status canonico | Observacao |
|---|---|---|---|
| AP-163 | Dreyfus Dynamic Pedagogy | `implemented-operational-read-model` | CLI `atlas:dreyfus` e `atlas:study`; Dreyfus gate/SLO/events ativos |
| AP-164 | Worked Example Engine | `implemented-operational-read-model` | catalogo, selector, fading e CLI `atlas:worked-example` |
| AP-165 | Process Pattern Catalog | `implemented-operational-read-model` | catalogo e matcher; personal detector fica para AP futuro |
| AP-166 | Failure Signature Tracker | `implemented-operational-read-model` | classifica falha, alerta repeticao, registra `learning.failure_review`; proposal emission futura |
| AP-167 | SRL Orchestrator | `implemented-operational-read-model` | opt-in forethought/performance/reflection; hooks de surface ficam para consumers |
| AP-168 | Productive Failure Flow | `scaffold` | precisa fechar Generation/Transfer runtime minimo e contrato `prediction_error_delta`, ou declarar AP pre-req |
| AP-169 | Personal Worked Examples Generator | `scaffold` | depende de AP-164 + Evidence Ledger maduro |
| AP-170 | Predictive Failure Insertion | `scaffold` | depende de AP-166 + Knowledge Graph maduro |

## Politica de comandos

| Camada | Formato | Status |
|---|---|---|
| Laravel local canonico | `php artisan atlas:dreyfus <node>` | contrato de implementacao |
| Product wrapper futuro | `atlas dreyfus <node>` | alias permitido, nao contrato |
| Docs de AP implementado | devem mostrar `php artisan atlas:*` | obrigatorio |
| Docs de AP futuro | podem mostrar wrapper se marcado como `future product alias` | permitido |

## Como implementar um AP

1. Leia a ordem obrigatoria.
2. Confirme que o AP tem status, objetivo, nao-objetivo, authority, schema, components, gates, SLO, events, tests e DoD.
3. Implemente migration idempotente; nunca inserir manualmente em `migrations`.
4. Implemente services em `app/Services/Ai/Cognitive/<Area>/`.
5. Registre gates em `app/Services/Ai/Kernel/Gates/` quando o AP declarar.
6. Registre `LedgerEventType` aditivo e SLOs em `KernelSloTargets`.
7. Registre flow em domain/profile somente se o flow realmente tem runtime ou read-model consumivel.
8. Exponha command `php artisan atlas:<capability>` se o AP exige CLI.
9. Atualize status do AP e specs cognitivas.
10. Rode validacoes.

## Principios que bloqueiam merge

- C1-C22 em `cognitive/principles.md`
- Surface nao decide
- Provider nao decide
- Tool nao decide
- Domain nao burla policy
- Runtime nao executa sem Decision Receipt
- Tudo repetido vira Core
- Learning nao altera comportamento critico sem proposal/review

## Validation commands

```bash
php artisan migrate
php artisan test tests/Unit/Ai/Cognitive tests/Feature/Ai/Cognitive
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune
php -d memory_limit=1G artisan atlas:engineering:knowledge index-code --prune
atlas engineering knowledge docs-health
```

## Regra de continuidade

Se uma IA encontrar doc antigo contradizendo este briefing, ela deve:

1. obedecer `atlas-ai-canonical-architecture-index.md`;
2. corrigir o doc antigo no mesmo PR;
3. registrar a decisao no AP ou spec cognitiva afetada;
4. validar docs-health e architecture-validate.
