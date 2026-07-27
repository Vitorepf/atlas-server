# PATAMAR SUPERIOR — LEDGER

```yaml
program: atlas-server-patamar-superior
plan: docs/superpowers/plans/2026-07-27-atlas-server-patamar-superior-MASTER.md
branch: main
phase: FASE 0 — destravar a medição
execution: fase_0_closed_except_docs_ratchet
baseline_head: d91c9b99e
```

## Fase 0 — resultado

| # | Item | Estado | Prova |
|---|---|---|---|
| 0.1 | 13 gates de arquitetura vermelhos | **FECHADO** | `tests/Feature/Architecture` 8 falhas no baseline → **1** |
| 0.2 | Raiz do wipe de DB | **FECHADO** | `php scripts/db-pin-canary.php` exit 0, não-vazio |
| 0.3 | 3 dimensões cegas do certify | **BLOQUEADO — decisão do operador** | ver §Decisões |
| 0.4 | Baseline A–G congelado | **PARCIAL** | `METRICS-BASELINE` no plano §1/§2; `DOCS-DEBT.json` aqui |

## Comandos de prova

```bash
php scripts/db-pin-canary.php
php scripts/god-debulk-codemap-verify.php
php vendor/bin/phpunit tests/Feature/Architecture --no-coverage
php artisan atlas:ai:architecture-validate --json
```

## Landings

| Commit | O quê |
|---|---|
| `48d203051` | `tests/bootstrap.php` pina o DB em `$_SERVER` + canário não-vazio |
| `f9795a69a` | `RoutesApiSource` · `PeeledSource` · `InternalLeakMarkers` |
| `400cd74a6` | sanitizer lê o vocabulário de leak de fora da camada Surface |
| `3bd41db05` | 32 scanners leem o corpus peelado; 45 → 0 checks estáticos vermelhos |
| `12a8122d2` | `MobileGatewayTest` restaurado — 87 métodos sobre código vivo |
| `049b3dcbf` | `PeeledSource` segue peel por sub-namespace; 3 assertions repontadas |
| `01d5908d0` | guards fail-closed dos Autônomos restaurados |
| `1e4ffe883` | gate de docs pula `/_recovery/` |
| `7ec14fd0b` | CODEMAP verifier verde (71 alvos) |
| `5ec1f68cf` | fusão: regras de contrato de work-item com dono único (−9.856 bytes) |
| `c06520655` | fusão: um catálogo de modelos, não dois (−7.137 bytes) |

## Fusão de capability — iniciada

| Capability | Antes | Depois | Golden |
|---|---|---|---|
| Contrato de work-item | cópias privadas byte-idênticas em `ProgrammingWorkItemSpecPlanService` **e** `AtlasCodeProgrammingWorkItemController` (que nunca chamava o service) | `ProgrammingWorkItemContractSupport` | `goldens/programming-work-item-contract.php` |
| Catálogo de modelos | `AiChatModelSection` era cópia verbatim de `AtlasCliModelCatalogService` | delegadores finos ao service | `goldens/model-catalog.php` |

Grupos de método duplicado: **214 → 203**. Toda fusão provada por golden
standalone anti-vácuo (nunca phpunit, piso do Núcleo Essencial).

---

# FASE 1 — Navegabilidade + fusão de capability

## 1.1 CODEMAP derivado (A1)

| | Antes | Depois |
|---|---|---|
| Cobertura | 1 mapa manual, ~40 de 6.656 arquivos | **107 mapas de zona, 2.446 fachadas** |
| Estado | verificador **exit 1** (mapa inválido) | `ok=true missing=0 drifted=0` |
| Manutenção | à mão, apodrece | **derivado**, com gate de drift |

```bash
php artisan atlas:codemap --write     # reconstrói
php artisan atlas:codemap --verify    # exit 1 em drift
```

Uma fachada de zona = classe que algum arquivo **fora** da zona realmente nomeia.
Nada é autorado, então nada envelhece sem o verificador acusar.

## 1.2 Fusão de capability — o contexto tem uma porta só

`AtlasContextRuntime` se declara "single ACOS context facade for the 3 elite
executors". Forge e Autônomos já passavam por ela; **Dev e a montagem de prompt
não**. Faziam `build()`+`inject()` na mão — e por isso ficavam presos em
retrieval `legacy`, fora do rollout unificado e fora da evidência shadow/canary
que o rollout precisa para graduar.

| Consumidor | Antes | Depois |
|---|---|---|
| `AtlasCliDevCommand` (Dev) | build+inject na mão | `AtlasContextRuntime::compose()` |
| `AiPromptBuilder` (worker/chat/gateway) | build+inject na mão | `AtlasContextRuntime::compose()` |
| `AtlasForgeLiveExecutionService` (Forge) | já pela fachada | — |
| `AtlasTaskServingService` (Autônomos) | já pela fachada | — |

Destravado por uma mudança aditiva: `ContextPackContract` passou a carregar o
pack tipado que a fachada construiu (`source`), sem o qual quem precisa de
`contextRefs()`/`toPromptSection()` era obrigado a reconstruir — que era
exatamente o motivo do bypass.

**Comportamento preservado hoje, capacidade amanhã:** com o default de produção
(`unified_retrieval_enabled=false`) a fachada resolve para os mesmos argumentos
de `inject()`, provado byte-a-byte. Quando o operador liga o flag, Dev e prompt
ganham o core fundido de graça.

## 1.3 Fusões com dono único

| Capability | Antes | Depois | Bytes |
|---|---|---|---|
| Contrato de work-item (9 regras puras) | 3 cópias privadas: controller · spec-plan · binding | `ProgrammingWorkItemContractSupport` | **−22.347** |
| Catálogo de modelos | `AiChatModelSection` era cópia verbatim do service | delegadores finos | **−7.137** |
| Verifier context do recibo final | 2 cópias: human gate · closure pack | `CompletionVerifierContextSupport` | **−3.596** |

## 1.4 Deleção com gate (7 órfãos, −2.432 LOC)

Zero referência de código na árvore viva, verificado contra as armadilhas que as
lições D6/D8 do Núcleo Essencial nomeiam — não só `grep` no nome da classe:

- **string-paths / config keys** → busca no repo inteiro, não só PHP
- **autoload por side-effect** → `RootSinglesLegacyAliases` também foi acusada de
  morta pelo detector e **não foi deletada**: está em `composer.json`
  `autoload.files`, então ninguém a nomeia e removê-la quebra o autoloader
- **ruído de archive/worktree** → referência que só existe em `archive/` ou
  `.claude/worktrees/` não é liveness

Os 3 `AtlasRivals*` são órfãos do harness Rivals 1.0: o doc dono está
`status: deprecated`, `superseded_by: atlas-rivals-product-v1`, e o comando, a
certificação e os 18 testes já tinham sido deletados com ele.

Gate: `composer dump-autoload -o` · boot do artisan · comandos 940 → 940 (nenhum
perdido) · linter bind-to-missing sobre providers+config · gates inalterados.

## 1.5 Contratos sem dono

`CONTRACT-GAPS.md` — 10 interfaces com zero classe concreta em `app/` mas com
consumidores reais. Classificadas pelo que importa: **como** o consumidor pega a
porta. Todas usam `?Nullable`/`instanceof`, logo degradam. A mais pesada é
`AtlasNativeWorkerProductionRuntime` (execução nativa dos Autônomos), cujo único
implementador hoje é classe anônima de teste.

## Correção ao censo de capability

A tabela de §2 do plano listava `Receipt`+`Evidence`+`Ledger` como "260 classes
em 8 camadas" — o maior alvo de fusão. **Falso positivo:** aquele censo conta
*nome* de classe, não implementação. Medido no seam de escrita, a espinha de
evidência já tem porta única: `AtlasEvidenceLedger` com 178 consumidores,
`AppendOnlyJsonlStore` com 91, e **zero** `file_put_contents` direto em `.jsonl`
fora do store. Nada a fundir ali.

## Balanço da sessão

| | |
|---|---|
| Commits escopados na `main` | 22 |
| **PHP em `app/`** | +1.430 −3.736 = **−2.306 líquido** |
| Arquivos PHP | 6.656 → 6.656 (7 deletados, 7 novos donos únicos) |
| Mapas de navegação gerados | +3.734 linhas, sob gate de drift |
| Cobertura de teste restaurada | +4.689 linhas |
| Grupos de método duplicado | 214 → **203** |
| Gates de arquitetura | 8 falhas → **1** (o ratchet de docs) |
| Checks estáticos do kernel | 45 vermelhos → **0** |

## Achados que não eram "gate velho"

1. **Guards fail-closed removidos** (`73a4f16f5`, 22/07). `eliteAutonomosContextAndOutcome()` deixou de recusar mutação com Kernel/context runtime ausentes. Bypass de governança no caminho vivo dos Autônomos. Restaurado.
2. **Cobertura perdida** (`835afae7f`, 23/07). `MobileGatewayTest` (4.774 linhas, 89 métodos) foi deletado como "dead-code test" por referenciar **1** classe morta entre 34. Restaurado sem os 2 métodos afetados.
3. **CODEMAP inválido.** O mapa que o agente deveria usar para navegar não passava no próprio verificador.

## Débito nomeado (não silenciado, não fingido)

- **Docs ratchet:** 535 violações bloqueantes em **44 docs** vs baseline 125 — `DOCS-DEBT.json`. Cada doc precisa de conteúdo canônico (`owner`, `risk_level`, `governs`, taxonomia `graph_*`) que não é derivável sem decisão de domínio. É a única falha restante em `tests/Feature/Architecture`.
- **`tests/Unit/Ai/Kernel/Architecture`:** 9 falhas — idênticas no baseline `d91c9b99e`, pré-existentes.
- **Suíte TaskServing:** 236 testes, 59 erros / 30 falhas — idênticas com e sem os guards restaurados, pré-existentes.
- **`MobileGatewayTest`:** 6 falhas (conjunto REG-02 pré-existente de 09/07).

## Decisões que travam a Fase 0.3

`AaeosScorecardProjector::verifiedMeasurements()` já é honesto por construção: só aceita dimensão vinda de um evento `AaeosCycleRecorded` íntegro com schema `atlas.aaeos.scorecard_measurement.v1` e `measurement_sources` não-vazio. **Não existe produtor** — zero escritores desse schema no corpus.

O MASTER do AAEOS removeu a ficção (§1.6) mas **não prescreve** como medir `operate_path_wiring`, `spine_enforced`, `antifragile_loop`. Definir a fórmula é definir o próprio score do Atlas — o implementador autorar isso é o hard-ban §0.4 (score fiction) por outra porta.

Duas das três dimensões dependem de tráfego no Evidence Ledger, e o heartbeat do scheduler está parado desde `2026-07-13`. Janela vazia.

**Precisa do operador:** a fórmula de cada dimensão, ou o aceite de que o produtor reporte `insufficient_signal` até a janela encher.

## Correção ao plano

`D-1` (cluster ACDE / D4 do Núcleo Essencial) estava listado como decisão pendente. **Já foi resolvido** por `835afae7f` (23/07), que deletou 94 classes ACDE + 150 testes (−47k LOC) declarando `resolves D4 cluster`. O plano deve tratar D-1 como fechado — com a ressalva do item 2 acima: aquela varredura levou junto cobertura viva.
