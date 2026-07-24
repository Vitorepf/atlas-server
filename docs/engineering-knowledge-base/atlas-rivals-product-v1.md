---
id: atlas-rivals-product-v1
type: engineering_knowledge
title: Atlas Rivals — Product Definition v1
status: active
category: programming
priority: 100
summary: Definicao do produto Rivals 2.0 — o benchmark interno rigoroso do Atlas (model-vs-model + Atlas uplift). Fonte de verdade do que Rivals e e do que nao e.
tags:
  - atlas
  - rivals
  - benchmark
  - product
capabilities:
  - rivals_product_definition
  - rivals_benchmark_authority
decisions:
  - Rivals e o benchmark do Atlas; nao e arena de marketing nem score unico.
  - Objetivo final e ser a regua mais rigorosa e relevante de medicao de modelos/providers (e do uplift Atlas).
  - Suites externas sao adapters; o juiz final e sempre o Rivals local.
  - Media global dos N benches nunca e claim oficial.
maintenance:
  - Atualizar quando o escopo do produto ou as invariantes de claim mudarem.
  - Manter alinhado com atlas-rivals-structure-v1 e config/atlas_rivals.php.
related_paths:
  - docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md
  - docs/engineering-knowledge-base/atlas-rivals-structure-v1.md
  - docs/engineering-knowledge-base/atlas-rivals-external-suites-v1.md
  - docs/engineering-knowledge-base/atlas-rivals-internal-corpus-v1.md
  - docs/engineering-knowledge-base/atlas-rivals-claims-and-reporting-v1.md
  - docs/engineering-knowledge-base/atlas-rivals-operator-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-rivals-phase-a-enterprise-report-v1.md
  - docs/engineering-knowledge-base/atlas-rivals2-rebuild-map-v1.md
  - docs/engineering-knowledge-base/thesis/rivals-validation.md
  - config/atlas_rivals.php
  - app/Services/Ai/Rivals/
  - app/Console/Commands/AtlasRivalsCommand.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-rivals-product-v1
graph_title: Atlas Rivals Product v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-programming-forge-flow
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-rivals-product-v1.md
  - config/atlas_rivals.php
  - app/Services/Ai/Rivals/
allowed_changes:
  - Refinar definicao de produto, eixos de medicao e proibicoes com evidencia.
forbidden_changes:
  - Declarar score unico / best overall como veredito oficial.
  - Tratar atlas-forge-rivals-* como runtime vivo.
  - Transformar Rivals em leaderboard publico sem claim gate.
depends_on:
  - atlas-ai-knowledge-governance-system
flows_to:
  - atlas-rivals-structure-v1
unlocks:
  - rivals2_runtime
governs:
  - rivals_product
evidence:
  - config/atlas_rivals.php
  - app/Services/Ai/Rivals/Core/Adjudicator.php
  - tests/Feature/Ai/Rivals
required_tests:
  - php artisan atlas:rivals doctor --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter product/structure sincronizados com o registry de suites e o runbook.
---

# Atlas Rivals — Product Definition v1

## PÉTRO — Curriculum ladder / anti-ceiling fallacy

**Canon:** `docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md`

- **80–100% num teste = aprovação de NÍVEL escolar**, nunca teto do modelo, do Atlas, nem “inteligência suprema”.
- Suite saturada → **S_sanity** (regressão/paridade). Multiplicador e claim forte → só em **S_frontier** não saturado.
- Raw ou Atlas dominou o frontier → **promover L_{k+1}** (próximo livro). **Proibido** dizer “50× / N×M impossível para sempre porque já faz 80%”.
- **2026 ≠ 2030 ≠ 2040 ≠ 2050**; o currículo **sobe**. Atlas 80% em teste medíocre = **inútil como meta final** — elevar o nível.
- Essência Rivals: medir com/sem Atlas e **sempre melhorar o Atlas no frontier**, não congelar tabuada.



## Resumo

**Rivals** e o nome do benchmark do Atlas (versao **2.0**). E a regua local, fail-closed, que mede modelos/providers e o uplift do runtime Atlas com evidencia auditavel.

## Papel no Atlas

Camada de **medicao** do dominio Programming. Nao executa entregas (isso e Dev / Forge / Autonomos). Nao substitui avaliacao per-delivery. Responde: qual modelo e melhor em que tarefa, a que custo; e se Atlas multiplica, empata ou piora o mesmo modelo.

## Onde Se Encaixa

- Runtime: `app/Services/Ai/Rivals/`, comando `atlas:rivals`, config `config/atlas_rivals.php`
- Storage: `storage/atlas/rivals` (JSONL + hash chain)
- Canon irmao: structure, external-suites, internal-corpus, claims-and-reporting, operator-runbook
- Kill-map historico do 1.0: `atlas-rivals2-rebuild-map-v1.md` (nao e fonte de produto)

## Contratos

### O que Rivals e

1. Benchmark **extremamente rigoroso** do que importa de verdade:
   - resolucao / qualidade util por `task_type`
   - inteligencia util (nao puzzle saturado)
   - custo e **custo por tarefa**
   - tokens, tempo, estabilidade / repeticoes
   - uplift Atlas (bare vs `atlas_dev` / forge / autonomous) quando o runtime existir
2. Duas faces na mesma regua:
   - **Mercado:** model vs model / provider vs provider, escopado
   - **Atlas:** mesmo modelo + case + budget; unica variavel = runtime
3. Juiz final **sempre local** (Adjudicator + ledger + evidence + replay). Suites externas so fornecem tarefas/resultados via adapters.

### O que Rivals nao e

- Arena / monolitico ForgeRivals 1.0 (morto)
- Media dos N benches como score oficial
- "Best overall" / leaderboard colapsado
- Corpus auto-autorado industrial (Goodhart)
- Superficie HTTP de rivals (removida no Slice 6)
- Avaliacao per-delivery do Autonomos

### Nomenclatura

| Publico | Valor |
|---|---|
| Produto | Rivals |
| Versao | 2.0 |
| Comando | `atlas:rivals` (`atlas:rivals2` = alias temporario) |
| Config | `config/atlas_rivals.php` (`version=2.0`) |
| Namespace | `App\Services\Ai\Rivals` |
| Interno preservado | schemas `atlas.rivals2.*`, env `ATLAS_RIVALS2_*` |

## Fluxo

```
suites externas (adapters) + corpus interno (AtlasBench/Elite)
        → plan / run / receipts
        → evidence + replay
        → adjudicate (hard gates)
        → report multi-eixo (escopado)
        → (opcional) uplift bare vs atlas_*
        → ledger append-only
```

Detalhe operacional: `atlas-rivals-operator-runbook-v1`. Estrutura de fases: `atlas-rivals-structure-v1`.

## Regras para IA

1. Antes de implementar ou recomendar Rivals, ler este doc + `atlas-rivals-structure-v1`.
2. Nunca tratar `atlas-forge-rivals-*` como runtime (status superseded).
3. Nunca promover media global / best overall a claim.
4. Pipeline valido nao e claim. Claims internos so com escopo completo e
   `internal_claim_allowed=true`; claims publicos exigem
   `public_claim_allowed=true` (ver claims-and-reporting).
5. Uplift nunca simulado: sem runtime Atlas ⇒ `uplift_supported=false`.
6. `local_fake`, `mockllm`, fixtures e arms `harness_*` provam somente o
   mecanismo; nunca viram claims de mercado ou Atlas.

## Escopo de Implementacao

`app/Services/Ai/Rivals/`, `AtlasRivalsCommand`, `config/atlas_rivals.php`, `storage/atlas/rivals/`, docs `atlas-rivals-*-v1`.

## Dependencias

- Knowledge governance (`atlas-ai-knowledge-governance-system`)
- Structure / suites / internal / claims / runbook (este pacote)
- Rebuild map (historico)

## Evidencias

- `php artisan atlas:rivals doctor --json` → status ok, ledger chain verified
- Adjudicator: so hard gates (`app/Services/Ai/Rivals/Core/Adjudicator.php`)
- Testes `tests/{Unit,Feature}/Ai/Rivals`

## Riscos

- Goodhart se corpus interno for auto-autorado ou se media virar meta
- Contaminacao de cases long-lived (mitigado: ContaminationGuard + refresh)
- Vendor-style bias se so medir no harness Atlas sem adapters externos de referencia
- Spend aberto sem aprovacao por run

## Exemplos

```bash
php artisan atlas:rivals doctor --json
php artisan atlas:rivals benchmarks --json
php artisan atlas:rivals report-all --json
```

## Proximas Acoes

Ver `atlas-rivals-structure-v1` (Fase A adapters → Fase B internalizar) e o runbook para operacao.
