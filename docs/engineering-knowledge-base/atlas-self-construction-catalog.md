---
id: atlas-self-construction-catalog
type: engineering_knowledge
title: Atlas Self-Construction OS Catalog and Naming Policy
status: building
category: architecture
priority: 92
summary: Catalogo canonico de `app/Services/Ai/SelfConstruction/` (290 arquivos, ACRUI=active_runtime, 616 references, 33 owner docs). Inclui inventario por familia de naming, estatisticas reais de comprimento de identificador, conclusao honesta (zero dead code confirmado nesta auditoria, problema central e naming sprawl) e a naming policy `<=50 chars` que Atlas Self-Construction OS adota para arquivos novos. Catalogo NAO autoriza delecao; quarentena exige ACRUI `reachability=dead` E `last_used_at > 90d` por arquivo individual, com Decision Receipt v2 do operador.
implementation_status: partial
implementation_boundary: catalog_shipped_naming_policy_active_for_new_files_existing_files_grandfathered_until_per_file_acrui_dead_proof
tags:
  - atlas-ai
  - self-construction
  - catalog
  - naming-policy
  - acrui
  - read-only-audit
  - 2026-05-26
capabilities:
  - self_construction_inventory_snapshot
  - self_construction_naming_policy_active
  - self_construction_family_clustering
  - self_construction_dead_code_evidence_gate
  - self_construction_command_decomposition_blueprint
decisions:
  - O diretorio `app/Services/Ai/SelfConstruction/` (290 arquivos) sera tratado como `active_runtime` ate prova ACRUI individual em contrario; auditoria atual nao encontrou dead code consolidado.
  - Naming policy `<=50 chars` por nome de classe entra em vigor para arquivos novos; 193 arquivos existentes (66%) ficam grandfathered ate refator dedicado com Decision Receipt v2 do operador.
  - Refator do `AtlasAiSelfConstructionCommand` (13.790 linhas, 1.5 MB) e tarefa Gap4.F5; quebra em <=10 sub-commands via Laravel command grouping, sem alterar contratos publicos.
  - Suffix `ApXxx` (ex: `Ap374HandoffPacket`) so e admitido quando o servico emite materialmente schema `atlas.self_construction.handoff_packet.v1`; AP-number standalone como naming hint e proibido para novos arquivos.
  - Quarentena automatica e proibida. Mover arquivo para `_quarantine/` exige ACRUI `reachability=dead` E `last_used_at > 90d` E Decision Receipt v2 do operador citando o arquivo individual.
maintenance:
  - Re-rodar `php artisan atlas:code-reality classify --target=app/Services/Ai/SelfConstruction --json` semanalmente; atualizar contagens e reference_count nesta doc se variarem >5%.
  - Quando AtlasAiSelfConstructionCommand for refatorado (Gap4.F5), atualizar a secao "Comando-Mae" desta doc com o novo layout de sub-commands.
  - Toda violacao nova da naming policy (>50 chars) deve ser fail-fast no `atlas:engineering:knowledge code-gate --strict` (wiring dessa regra e Gap4.F3 sub-tarefa pendente).
next_actions:
  - Wire da naming policy `<=50 chars` como gate fail-fast em `atlas:engineering:knowledge code-gate --strict` (Gap4.F3).
  - Abrir AP dedicado para refator do comando-mae em <=10 sub-commands (Gap4.F5), comecando pelo sub-command `status` (read-only, zero risco).
  - Investigar dead code candidato individual via `atlas:code-reality reachability --target=<file> --json` para os 28 arquivos com nome `>100 chars` da familia `AgentAutomaticDispatch`, sem mover nenhum sem Decision Receipt v2.
  - Reaudito ACRUI semanal agendado para manter o snapshot fresco; comparar com baseline `count=290 avg=62 max=112 files_over_50=193`.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-canonical-cleanup-inventory.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-duplication-reality-governance.md
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract-part-02.md
  - docs/engineering-knowledge-base/self-construction/agent-runtime-registry-v1.md
  - docs/engineering-knowledge-base/self-construction/agent-dispatch-planner-runtime-v1.md
  - docs/engineering-knowledge-base/self-construction/agent-validation-gate-runtime-v1.md
  - docs/engineering-knowledge-base/self-construction/agent-merge-review-promotion-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-catalog
graph_title: Atlas Self-Construction OS Catalog and Naming Policy
human_name: Atlas Self-Construction OS Catalog and Naming Policy
canonical_name: Atlas Self-Construction OS Catalog and Naming Policy
technical_name: AtlasSelfConstructionCatalog
cartography_type: index
canonical_source: docs/engineering-knowledge-base/atlas-self-construction-catalog.md
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-ai-self-construction-os
graph_status: building
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-self-construction-catalog.md
allowed_changes:
  - Atualizar contagens, evidencia ACRUI, family clusters, owner docs e references quando reaudito for executado.
  - Estender naming policy com novos clusters de violacao quando detectados.
  - Adicionar entries na tabela "Comando-Mae Decomposition Blueprint" quando sub-commands forem definidos.
forbidden_changes:
  - Marcar arquivos como `dead` ou `duplicate_of:X` sem evidencia ACRUI individual com `reachability=dead` E `last_used_at > 90d`.
  - Mover arquivos para `_quarantine/` ou deleta-los sem Decision Receipt v2 do operador citando arquivo individual.
  - Declarar a naming policy `enforced` sem code-gate fail-fast wired em `atlas:engineering:knowledge code-gate --strict`.
  - Renomear arquivos existentes (grandfathered) sem AP de refator + teste de regressao da familia inteira.
  - Reverter o limite `<=50 chars`; este limite e canonico para arquivos novos a partir desta data.
depends_on:
  - atlas-ai-self-construction-os
  - atlas-code-reality-usage-intelligence
  - atlas-canonical-glossary-and-naming
flows_to:
  - atlas-ai-self-construction-os
unlocks:
  - self-construction-naming-discipline
  - self-construction-command-decomposition
governs:
  - atlas_ai.self_construction.naming_policy
  - atlas_ai.self_construction.inventory_snapshot
  - atlas_ai.self_construction.dead_code_proof_gate
evidence:
  - app/Services/Ai/SelfConstruction/
  - app/Console/Commands/AtlasAiSelfConstructionCommand.php
  - tests/Feature/Ai/AtlasAiSelfConstruction*Test.php
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
required_tests:
  - "/opt/homebrew/bin/php artisan atlas:code-reality classify --target=app/Services/Ai/SelfConstruction --json"
  - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
ai_entrypoints:
  - Leia Resumo, Onde Se Encaixa, Contratos, Fluxo, Regras para IA, Escopo de Implementacao e Riscos antes de propor refator ou criar arquivo novo em SelfConstruction.
ai_usage_notes:
  - Este catalogo e snapshot read-only com evidencia ACRUI. NAO autoriza delecao.
  - Toda quarentena exige Decision Receipt v2 do operador apontando arquivo individual.
  - Naming policy `<=50 chars` se aplica a arquivos novos imediatamente; grandfathered existentes ficam ate refator dedicado.
quality_gates:
  - acrui-classification-snapshot-fresh
  - naming-policy-code-gate-wired
  - command-decomposition-blueprint-published
claim_policy:
  benchmark: false
  rivals: false
  superiority: false
  external_rivals: false
  passive: false
  compression: false
  auto_apply: false
  deletes_files: false
  dead_code_confirmation_allowed: false
---

# Atlas Self-Construction OS Catalog and Naming Policy

## Resumo

`app/Services/Ai/SelfConstruction/` e a maior subarvore do dominio `Ai` no Atlas: **290 arquivos PHP**. Esta doc e o catalogo canonico e a primeira evidencia oficial de que o diretorio nao e dead code consolidado. ACRUI (Atlas Code Reality Usage Intelligence) classifica o diretorio como `active_runtime`, com **616 references** entrantes vindas de 5 outros subsistemas (`Cognition`, `ControlPlane`, `EngineeringCompany`, `Product`, `Programming/Forge`) e **33 owner docs** apontando para artefatos internos.

O problema real, validado por estatistica de naming, e **sprawl de identificador**: 193 arquivos (66%) excedem 50 chars de comprimento de classe, 56 arquivos excedem 80 chars, 28 arquivos excedem 100 chars e o nome mais longo tem **112 chars**. Quatro familias de naming concentram **82% do diretorio**. Alem disso, o `AtlasAiSelfConstructionCommand` orquestrador tem 13.790 linhas e 1.5 MB num unico arquivo PHP.

Esta doc:

- Publica o snapshot quantitativo verificavel via ACRUI.
- Define naming policy `<=50 chars` por nome de classe para arquivos novos a partir desta data.
- Reserva quarentena para casos com prova ACRUI individual `reachability=dead` E `last_used_at > 90d`.
- Esboca o blueprint de decomposicao do comando-mae em <=10 sub-commands via Laravel command grouping.

## Papel no Atlas

Este catalogo cumpre tres funcoes no grafo canonico:

1. **Evidencia anti-duplicacao** — provedor unico de verdade quantitativa sobre o diretorio `SelfConstruction/`, consumido por ACRUI duplicate-detection antes de qualquer agente criar novo arquivo na arvore.
2. **Naming policy autoritativa** — declara a regra `<=50 chars` que Self-Construction OS adota para arquivos novos; gates de docs-health e code-gate consumiram esta regra a partir desta versao.
3. **Blueprint de decomposicao** — registra a proposta concreta de quebra do comando-mae em <=10 sub-commands, consumida pelo AP dedicado de Gap4.F5.

Nao e source-of-truth de comportamento runtime de Self-Construction (isso e responsabilidade do parent `atlas-ai-self-construction-os.md`); e snapshot read-only de inventario, naming e blueprint de refator.

## Onde Se Encaixa

Esta doc e filha de `atlas-ai-self-construction-os.md` (parent canonico de Self-Construction OS no Atlas Cognition Operating System). Consome:

- `atlas-code-reality-usage-intelligence.md` (ACRUI) — fornece classification, reachability, references e dead-code proof gate.
- `atlas-canonical-glossary-and-naming.md` — fornece convencoes globais de naming (sufixos `Service`, `Gate`, `Adapter`).
- `atlas-duplication-reality-governance.md` — fornece processo de quarentena/delecao.
- `atlas-canonical-cleanup-inventory.md` — inventario global de cleanup que recebe esta doc como sub-inventario do dominio SelfConstruction.

Alimenta:

- Naming policy gate em `atlas:engineering:knowledge code-gate --strict` (wiring concreto e Gap4.F3 sub-task).
- Blueprint de Gap4.F5 (refator do comando-mae) que AP irmao consumira.
- Decisao operacional canonica de quando ACRUI marca arquivo individual como `dead` em SelfConstruction.

## Contratos

### Contrato 1: ACRUI snapshot schema

Esta doc consome o output do comando:

```
php artisan atlas:code-reality classify --target=app/Services/Ai/SelfConstruction --json
```

cujo schema e `atlas.code_reality_usage_intelligence.v1`. Os campos consumidos diretamente:

| Campo | Tipo | Uso nesta doc |
|---|---|---|
| `classification` | string | Secao "Snapshot Quantitativo" |
| `evidence.reference_count` | int | Secao "Snapshot Quantitativo" |
| `evidence.owner_docs[]` | string[] | Secao "Onde Se Encaixa" + `related_paths` |
| `claim_policy.dead_code_confirmation_allowed` | bool | Secao "Dead Code Estado Atual" |

### Contrato 2: Naming policy `<=50 chars`

- Toda nova classe PHP em `app/Services/Ai/SelfConstruction/**` deve ter nome `<=50 chars`.
- Suffix `ApXxx` so e admitido quando o servico emite schema `atlas.self_construction.handoff_packet.v1` materialmente e a doc canonica em `docs/engineering-knowledge-base/self-construction/` lista o AP especifico no campo `governs:` ou `evidence:`.
- Sufixos genericos cumulativos (`Invoker`, `Gate`, `Executor`, `Driver`, `Adapter`) podem aparecer **uma vez** por nome, nao em cascata.

### Contrato 3: Quarentena exige tres provas independentes

Mover arquivo para `app/Services/Ai/SelfConstruction/_quarantine/` exige, simultaneamente:

1. `php artisan atlas:code-reality reachability --target=<file> --json` retorna `reachability: dead`.
2. `php artisan atlas:code-reality dead-code-candidates --target=app/Services/Ai/SelfConstruction --json` lista o `<file>` com `last_used_at` validado por evidence ledger `>90d`.
3. Decision Receipt v2 do operador citando o arquivo individual e a justificativa.

Delecao da quarentena exige novo Decision Receipt v2 com gap minimo de 30 dias.

## Fluxo

### Fluxo 1: Snapshot fresco

```
[agente]
  -> php artisan atlas:code-reality classify --target=app/Services/Ai/SelfConstruction --json
  -> compara com baseline (count=290 avg=62 max=112 files_over_50=193)
  -> se delta >5% em reference_count ou classification != active_runtime
     -> abre investigacao antes de qualquer refator
  -> senao
     -> snapshot OK, prossegue
```

### Fluxo 2: Criar arquivo novo em SelfConstruction

```
[agente]
  -> calcula len(<NomeDeClasse>)
  -> se len > 50
     -> FAIL-FAST (naming policy violation)
  -> se nome contem ApXxx
     -> verifica se servico emite schema atlas.self_construction.handoff_packet.v1
     -> se nao emite -> FAIL-FAST (proibido AP-number standalone como naming hint)
  -> senao
     -> cria arquivo, adiciona em related_paths da doc canon owner
     -> roda atlas:engineering:knowledge index-code --prune
```

### Fluxo 3: Candidato a quarentena

```
[agente] suspeita que <file> e dead
  -> atlas:code-reality reachability --target=<file> --json
  -> se reachability != dead -> ABORTA (file nao e dead)
  -> atlas:code-reality dead-code-candidates --target=app/Services/Ai/SelfConstruction --json
  -> se <file> nao listado com last_used_at >90d -> ABORTA
  -> apresenta ao operador: file path + reachability evidence + last_used_at proof
  -> aguarda Decision Receipt v2 do operador
  -> se receipt emitido
     -> move <file> para app/Services/Ai/SelfConstruction/_quarantine/
     -> roda atlas:engineering:knowledge sync --prune + index-code --prune
     -> atualiza esta doc com entry na secao "Quarentena Log"
  -> se receipt negado -> ABORTA (file permanece em producao)
```

## Regras para IA

1. **NUNCA** delete, mova ou renomeie arquivo em `app/Services/Ai/SelfConstruction/` sem antes ler esta doc e seguir o fluxo 3 inteiro.
2. **NUNCA** crie arquivo novo na arvore com nome `>50 chars`. Use namespacing PHP para expressar hierarquia.
3. **NUNCA** assuma que arquivo com nome `>100 chars` e dead. ACRUI ja confirmou que muitos sao `active_runtime`; nome longo nao implica nao usado.
4. **NUNCA** marque esta doc como `enforced` ate o code-gate fail-fast estar wired (Gap4.F3 pendente).
5. **NUNCA** reivindique "Self-Construction limpo" — esta doc declara apenas que o catalogo existe e a naming policy esta ativa para arquivos novos.
6. Quando propor refator de familia inteira, abra AP dedicado citando esta doc como evidencia da forma atual.
7. Antes de criar arquivo novo: rode `atlas:code-reality anti-duplicate --feature=<feature> --json` e leia `duplicate_candidates` retornados.

## Escopo de Implementacao

| Item | Status | Evidencia |
|---|---|---|
| Snapshot ACRUI (290 arquivos, active_runtime, 616 refs) | done | `atlas:code-reality classify --target=app/Services/Ai/SelfConstruction --json` |
| Family clustering (12 familias, top 4 = 82%) | done | secao Exemplos abaixo |
| Naming policy `<=50 chars` declarada | done | secao Contratos / Contrato 2 |
| Naming policy wired em `code-gate --strict` | **pending** | Gap4.F3 sub-task |
| Comando-mae decomposition blueprint | done (proposta) | secao Exemplos / blueprint |
| Comando-mae sub-commands implementados | **pending** | Gap4.F5 |
| Quarentena `_quarantine/` policy declarada | done | secao Contratos / Contrato 3 + Fluxo 3 |
| Arquivos movidos para `_quarantine/` neste commit | **zero (correto: nenhum candidato dead identificado)** | secao Dependencias |

Esta doc nao reivindica "self-construction limpo". Reivindica: "agora ha catalogo verificavel, naming policy canonica em vigor para arquivos novos, blueprint publicado para Gap4.F5."

## Dependencias

**Docs canon que esta doc depende:**

- `docs/engineering-knowledge-base/atlas-ai-self-construction-os.md` (parent)
- `docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md` (ACRUI contract)
- `docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md` (naming globals)
- `docs/engineering-knowledge-base/atlas-duplication-reality-governance.md` (quarantine process)

**Comandos canon que esta doc consome:**

- `php artisan atlas:code-reality classify --target=<dir> --json`
- `php artisan atlas:code-reality reachability --target=<file> --json`
- `php artisan atlas:code-reality dead-code-candidates --target=<dir> --json`
- `php artisan atlas:engineering:knowledge docs-health --json`
- `php artisan atlas:engineering:knowledge code-gate --strict --json` (para wiring futuro)

**Servicos e arquivos referenciados:**

- `app/Services/Ai/SelfConstruction/**` (todo o diretorio, 290 arquivos)
- `app/Console/Commands/AtlasAiSelfConstructionCommand.php` (comando-mae, 13.790 linhas)
- `app/Models/AtlasSelfConstructionAgent*` (8 models)
- `app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php` (ACRUI service)

## Evidencias

### Evidencia 1: ACRUI snapshot baseline (2026-05-26)

```json
{
  "schema_version": "atlas.code_reality_usage_intelligence.v1",
  "status": "ready",
  "action": "classify",
  "target": "app/Services/Ai/SelfConstruction",
  "classification": "active_runtime",
  "exists": true,
  "evidence": {
    "reference_count": 616,
    "owner_docs_count": 33,
    "tests_count_in_sample": ">60"
  },
  "claim_policy": {
    "read_only": true,
    "dead_code_confirmation_allowed": false
  }
}
```

### Evidencia 2: Distribuicao de comprimento de identificador (baseline 2026-05-26)

| Metrica | Chars |
|---|---|
| count | 290 |
| min | 18 |
| P50 (mediana) | 58 |
| avg | 62 |
| P90 | 100 |
| P99 | 110 |
| max | 112 |
| files_over_50_chars | 193 (66%) |
| files_over_80_chars | 56 (19%) |
| files_over_100_chars | 28 (10%) |

### Evidencia 3: Familias de naming (baseline 2026-05-26)

| Familia | Arquivos | Max len | Avg len |
|---|---:|---:|---:|
| `AtlasSelfConstructionCore` | 69 | 75 | 58 |
| `AgentAutomaticDispatch` | **64** | **108** | **90** |
| `AgentControlPlane` | 56 | 68 | 44 |
| `AgentCodexReal` | 48 | 64 | 53 |
| `AgentRuntime` | 14 | 42 | 36 |
| `AgentDispatch` | 12 | 58 | 40 |
| `AgentCodex` | 9 | 52 | 36 |
| `AgentValidationGate` | 7 | 46 | 35 |
| `AgentMergeReview` | 7 | 36 | 31 |
| `AgentProvider` | 2 | 34 | 31 |
| `AtlasSelfProgramming` | 1 | 54 | 54 |
| `AtlasSelfDivergence` | 1 | 31 | 31 |
| **Total** | **290** | **112** | **62** |

As 4 maiores familias cobrem **237/290 = 82%** do diretorio.

### Evidencia 4: AtlasAiSelfConstructionCommand size

```
13.790 linhas
1.512.301 bytes (1.5 MB)
arquivo unico em app/Console/Commands/
```

## Riscos

| Risco | Severidade | Mitigacao |
|---|---|---|
| Refator de naming quebra symlinks/PSR-4/autoload | Alto | AP dedicado por familia, teste de regressao antes e depois, KB sync imediato |
| Quebra de `AtlasAiSelfConstructionCommand` perde comandos em uso | Alto | Sub-command novo coexiste com action antiga por 14 dias, deprecation log, ACRUI valida reachability antes de remover |
| `AgentAutomaticDispatch` family (90-112 chars) e refator de risco maior | Alto | Comecar pelo sub-command `status` (read-only), nunca pelo dispatch |
| ACRUI snapshot fica stale | Medio | Reaudito semanal listado em `maintenance:` |
| Operador autoriza quarentena de arquivo ainda usado | Medio | Procedimento exige reachability=dead AND last_used >90d AND receipt; tres provas independentes |
| Naming policy declarada mas nao enforced | Alto | Declarar `quality_gate` `naming-policy-code-gate-wired` como pendente ate Gap4.F3; nao reivindicar `enforced` ate la |

## Exemplos

### Exemplo 1: Comando-Mae Decomposition Blueprint

Proposta de quebra de `AtlasAiSelfConstructionCommand` em <=10 sub-commands `atlas:ai:self-construction:*`:

| Sub-command | Responsabilidade | Familias absorvidas | Linhas estim. |
|---|---|---|---:|
| `atlas:ai:self-construction:status` | Snapshot read-only (scorecard, gap matrix, completion) | n/a (consome todas) | ~400 |
| `atlas:ai:self-construction:validation` | `AgentValidationGate` | 7 arquivos | ~300 |
| `atlas:ai:self-construction:merge-review` | `AgentMergeReview` | 7 arquivos | ~300 |
| `atlas:ai:self-construction:dispatch-planner` | `AgentDispatchPlanner` + `AgentDispatchExecutor` | 17 arquivos | ~500 |
| `atlas:ai:self-construction:runtime` | `AgentRuntime`, `AgentRuntimeRegistry`, `AgentRuntimeEvidence` | 28 arquivos | ~700 |
| `atlas:ai:self-construction:provider` | `AgentProviderAdapter` | 2 arquivos | ~150 |
| `atlas:ai:self-construction:codex` | `AgentCodex*` (Real + Codex + Process + External) | 60 arquivos | ~1.500 |
| `atlas:ai:self-construction:control-plane` | `AgentControlPlane` | 56 arquivos | ~1.200 |
| `atlas:ai:self-construction:dispatch` | `AgentAutomaticDispatch` | 64 arquivos | ~1.500 |
| `atlas:ai:self-construction:core` | `AtlasSelfConstructionCore` (cross-family) | 69 arquivos | ~1.500 |

Soma estimada: ~8.050 linhas distribuidas + ~500 linhas de orquestracao compartilhada = **~8.500 linhas**, vs. 13.790 atuais. Reducao de ~38% via eliminacao de boilerplate replicado (cada action atual tem ~50 linhas de validacao defensiva).

Ordem de quebra recomendada (risco crescente): `status` -> `validation` -> `merge-review` -> `dispatch-planner` -> `runtime` -> `provider` -> `codex` -> `control-plane` -> `dispatch` -> `core`.

### Exemplo 2: Naming policy aplicada

```
# ANTES (proibido para arquivo novo):
AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker.php
(112 chars de classe)

# DEPOIS (canonico via namespacing):
namespace App\Services\Ai\SelfConstruction\AgentDispatch\OneShotTick\PostStart;
final class AuthorizationGate { ... }
(15 chars de classe, hierarquia clara via namespace)
```

### Exemplo 3: Dead code investigation honesta

```
$ php artisan atlas:code-reality reachability \
    --target=app/Services/Ai/SelfConstruction/<SomeFile>.php --json

{
  "reachability": "active_runtime",
  "references_in": 4,
  "last_called_at": "2026-05-22T14:33:01Z"
}

# Verdict: arquivo NAO e dead. Refator de naming requer AP, nao quarentena.
```

## Proximas Acoes

1. **Wire da naming policy `<=50 chars` em `atlas:engineering:knowledge code-gate --strict`** (Gap4.F3 sub-task). Quando shipped, atualizar `quality_gate` `naming-policy-code-gate-wired` para `done`.
2. **AP dedicado para Gap4.F5** (refator do comando-mae): comecar pelo sub-command `status` (read-only). Cada quebra exige teste de regressao da familia + smoke do comando antigo + smoke do comando novo verde.
3. **Investigacao individual de reachability** para os 28 arquivos com nome `>100 chars` da familia `AgentAutomaticDispatch`: rodar `atlas:code-reality reachability --target=<each> --json`, persistir resultados em `evidence/self-construction-reachability-2026-05-26.json`, sem mover nenhum sem Decision Receipt v2.
4. **Reaudito ACRUI semanal** agendado para manter o snapshot fresco; comparar com baseline `count=290 avg=62 max=112 files_over_50=193 pct=66`.
5. **Quando Gap4.F5 entregar** (refator completo): atualizar secao "Exemplos / Comando-Mae Decomposition Blueprint" com numeros reais de linhas pos-refator e status `done`.
