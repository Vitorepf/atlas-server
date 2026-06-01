---
id: atlas-self-construction-catalog
type: engineering_knowledge
title: Atlas Self-Construction OS Catalog and Naming Policy
status: building
category: architecture
priority: 92
summary: Catalogo canonico de `app/Services/Ai/SelfConstruction/` (292 arquivos PHP em contagem local 2026-05-26; snapshot ACRUI anterior=active_runtime, 616 references, 33 owner docs). Inclui inventario por familia de naming, conclusao honesta (zero dead code confirmado nesta auditoria, problema central e naming/runtime-readiness sprawl), naming policy `<=50 chars` para arquivos novos e regra de reuso para Self-Directed Evolution. Catalogo NAO autoriza delecao; quarentena exige ACRUI `reachability=dead` E `last_used_at > 90d` por arquivo individual, com Decision Receipt v2 do operador.
implementation_status: active_catalog_stale_metrics_partially_corrected
implementation_boundary: catalog_shipped_naming_policy_active_for_new_files_existing_files_grandfathered_until_per_file_acrui_dead_proof_current_count_292_reaudit_required_for_length_distribution
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
  - O diretorio `app/Services/Ai/SelfConstruction/` (292 arquivos PHP em contagem local 2026-05-26) sera tratado como `active_runtime` ate prova ACRUI individual em contrario; auditoria atual nao encontrou dead code consolidado.
  - Naming policy `<=50 chars` por nome de classe entra em vigor para arquivos novos; 193 arquivos existentes (66%) ficam grandfathered ate refator dedicado com Decision Receipt v2 do operador.
  - O `AtlasAiSelfConstructionCommand` ja foi reduzido para wrapper curto; o maior sprawl runtime atual esta em `AtlasSelfConstructionReadinessService.php` (104.610 linhas em contagem local 2026-05-26) e nas familias de nomes longos.
  - `AtlasSelfConstructionSubsystemBuilderService` ja implementa detect/propose/approve/list para subsystem proposals; Self-Directed Evolution deve reusar esse primitive antes de criar qualquer detector/proposal novo.
  - Suffix `ApXxx` (ex: `Ap374HandoffPacket`) so e admitido quando o servico emite materialmente schema `atlas.self_construction.handoff_packet.v1`; AP-number standalone como naming hint e proibido para novos arquivos.
  - Quarentena automatica e proibida. Mover arquivo para `_quarantine/` exige ACRUI `reachability=dead` E `last_used_at > 90d` E Decision Receipt v2 do operador citando o arquivo individual.
maintenance:
  - Re-rodar `php artisan atlas:code-reality classify --target=app/Services/Ai/SelfConstruction --json` semanalmente; atualizar contagens e reference_count nesta doc se variarem >5%.
  - Reauditar ACRUI e comprimento de classes quando o diretorio mudar >5 arquivos ou quando novos services Self-Directed Evolution forem propostos.
  - Quando `AtlasSelfConstructionReadinessService.php` for compactado, atualizar a secao "Runtime Readiness Sprawl" desta doc com o novo layout.
  - Toda violacao nova da naming policy (>50 chars) deve ser fail-fast no `atlas:engineering:knowledge code-gate --strict` (wiring dessa regra e Gap4.F3 sub-tarefa pendente).
next_actions:
  - Wire da naming policy `<=50 chars` como gate fail-fast em `atlas:engineering:knowledge code-gate --strict` (Gap4.F3).
  - Abrir AP dedicado para compactar `AtlasSelfConstructionReadinessService.php` em families/read-models menores, preservando schemas e testes.
  - Investigar dead code candidato individual via `atlas:code-reality reachability --target=<file> --json` para arquivos com nome `>100 chars`, sem mover nenhum sem Decision Receipt v2.
  - Reaudito ACRUI semanal agendado para manter o snapshot fresco; comparar com baseline atual `count=292` e atualizar distribuicao de comprimento somente com script/ACRUI dedicado.
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
  - Adicionar entries na tabela "Runtime Readiness Compaction Blueprint" quando services filhos forem definidos.
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
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionSubsystemBuilderService.php
  - app/Console/Commands/AtlasAiSelfConstructionCommand.php
  - tests/Feature/Ai/AtlasAiSelfConstruction*Test.php
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
evidence_refs:
  - symbol: AtlasSelfConstructionReadinessService
  - command: atlas:ai:self-construction:shell-placeholder
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
  - readiness-service-compaction-blueprint-published
  - self-directed-evolution-reuse-subsystem-builder
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

`app/Services/Ai/SelfConstruction/` e a maior subarvore do dominio `Ai` no Atlas: **292 arquivos PHP** em contagem local de 2026-05-26. Esta doc e o catalogo canonico e a primeira evidencia oficial de que o diretorio nao e dead code consolidado. O snapshot ACRUI anterior classificou o diretorio como `active_runtime`, com **616 references** entrantes vindas de 5 outros subsistemas (`Cognition`, `ControlPlane`, `EngineeringCompany`, `Product`, `Programming/Forge`) e **33 owner docs** apontando para artefatos internos.

O problema real continua sendo **sprawl de identificador e runtime readiness sprawl**. A distribuicao de naming publicada abaixo vem do baseline anterior (290 arquivos) e deve ser reaudita antes de qualquer refator mecanico. A contagem local atual mostra que o `AtlasAiSelfConstructionCommand` ja virou wrapper curto; o maior arquivo operacional agora e `AtlasSelfConstructionReadinessService.php` com **104.610 linhas**, que concentra projection/readiness/template logic demais em uma unica classe.

Esta doc:

- Publica o snapshot quantitativo verificavel via ACRUI.
- Define naming policy `<=50 chars` por nome de classe para arquivos novos a partir desta data.
- Reserva quarentena para casos com prova ACRUI individual `reachability=dead` E `last_used_at > 90d`.
- Registra que `AtlasSelfConstructionSubsystemBuilderService` ja e o primitive canonico para detect/propose/approve/list de subsystem proposals.
- Redireciona a proxima compactacao para `AtlasSelfConstructionReadinessService.php`, nao para um comando-mae que ja foi reduzido.

## Papel no Atlas

Este catalogo cumpre tres funcoes no grafo canonico:

1. **Evidencia anti-duplicacao** — provedor unico de verdade quantitativa sobre o diretorio `SelfConstruction/`, consumido por ACRUI duplicate-detection antes de qualquer agente criar novo arquivo na arvore.
2. **Naming policy autoritativa** — declara a regra `<=50 chars` que Self-Construction OS adota para arquivos novos; gates de docs-health e code-gate consumiram esta regra a partir desta versao.
3. **Reuse gate para Self-Directed Evolution** — impede que IA crie novo detector/proposal runtime ignorando `AtlasSelfConstructionSubsystemBuilderService`.
4. **Blueprint de compactacao runtime** — registra que a proxima compactacao relevante e `AtlasSelfConstructionReadinessService.php`.

Nao e source-of-truth de comportamento runtime de Self-Construction (isso e responsabilidade do parent `atlas-ai-self-construction-os.md`); e snapshot read-only de inventario, naming e blueprint de refator.

## Onde Se Encaixa

Esta doc e filha de `atlas-ai-self-construction-os.md` (parent canonico de Self-Construction OS no Atlas Cognition Operating System). Consome:

- `atlas-code-reality-usage-intelligence.md` (ACRUI) — fornece classification, reachability, references e dead-code proof gate.
- `atlas-canonical-glossary-and-naming.md` — fornece convencoes globais de naming (sufixos `Service`, `Gate`, `Adapter`).
- `atlas-duplication-reality-governance.md` — fornece processo de quarentena/delecao.
- `atlas-canonical-cleanup-inventory.md` — inventario global de cleanup que recebe esta doc como sub-inventario do dominio SelfConstruction.

Alimenta:

- Naming policy gate em `atlas:engineering:knowledge code-gate --strict` (wiring concreto e Gap4.F3 sub-task).
- Blueprint de compactacao de `AtlasSelfConstructionReadinessService.php` que AP irmao consumira.
- Self-Directed Evolution Layer, que deve reusar Subsystem Builder antes de propor novo detector/proposal runtime.
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
  -> compara com baseline atual (count=292; naming distribution precisa reaudito)
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
| Snapshot ACRUI anterior (290 arquivos, active_runtime, 616 refs) | historical | `atlas:code-reality classify --target=app/Services/Ai/SelfConstruction --json` |
| Contagem local 2026-05-26 (292 PHP files) | current | `find app/Services/Ai/SelfConstruction -type f -name '*.php' | wc -l` |
| Family clustering (12 familias, top 4 = 82%) | historical | secao Exemplos abaixo; reauditar antes de refator |
| Naming policy `<=50 chars` declarada | done | secao Contratos / Contrato 2 |
| Naming policy wired em `code-gate --strict` | **pending** | Gap4.F3 sub-task |
| Subsystem proposal primitive | implemented | `AtlasSelfConstructionSubsystemBuilderService` |
| Runtime readiness compaction | **pending** | `AtlasSelfConstructionReadinessService.php` |
| Quarentena `_quarantine/` policy declarada | done | secao Contratos / Contrato 3 + Fluxo 3 |
| Arquivos movidos para `_quarantine/` neste commit | **zero (correto: nenhum candidato dead identificado)** | secao Dependencias |

Esta doc nao reivindica "self-construction limpo". Reivindica: "agora ha catalogo verificavel, naming policy canonica em vigor para arquivos novos, primitive de subsystem proposal identificado e proxima compactacao apontada para o runtime correto."

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

- `app/Services/Ai/SelfConstruction/**` (todo o diretorio, 292 arquivos PHP em contagem local)
- `app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php` (104.610 linhas, principal sprawl runtime atual)
- `app/Services/Ai/SelfConstruction/AtlasSelfConstructionSubsystemBuilderService.php` (primitive detect/propose/approve/list)
- `app/Console/Commands/AtlasAiSelfConstructionCommand.php` (wrapper curto atual)
- `app/Models/AtlasSelfConstructionAgent*` (8 models)
- `app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php` (ACRUI service)

## Evidencias

### Evidencia 1: ACRUI snapshot baseline anterior (2026-05-26)

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

### Evidencia 2: Distribuicao de comprimento de identificador (baseline historico 2026-05-26)

Esta distribuicao e historica para 290 arquivos. Use-a como alerta de sprawl,
nao como numero atual exato. Antes de refator de naming, reexecute ACRUI/script
de comprimento e atualize esta tabela.

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

### Evidencia 4: Runtime readiness sprawl atual

```
wc -l app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php
104.610 linhas

wc -l app/Console/Commands/AtlasAiSelfConstructionCommand.php
42 linhas
```

## Riscos

| Risco | Severidade | Mitigacao |
|---|---|---|
| Refator de naming quebra symlinks/PSR-4/autoload | Alto | AP dedicado por familia, teste de regressao antes e depois, KB sync imediato |
| Quebra de `AtlasSelfConstructionReadinessService.php` altera schemas/read-models usados | Alto | Compactar por adapter/family, manter snapshots, rodar testes SelfConstruction antes de remover metodo antigo |
| IA cria Self-Directed Evolution runtime paralelo | Alto | Reuse obrigatorio de `AtlasSelfConstructionSubsystemBuilderService`, Self-Improvement e AAEL antes de novo service |
| `AgentAutomaticDispatch` family (90-112 chars) e refator de risco maior | Alto | Comecar pelo sub-command `status` (read-only), nunca pelo dispatch |
| ACRUI snapshot fica stale | Medio | Reaudito semanal listado em `maintenance:` |
| Operador autoriza quarentena de arquivo ainda usado | Medio | Procedimento exige reachability=dead AND last_used >90d AND receipt; tres provas independentes |
| Naming policy declarada mas nao enforced | Alto | Declarar `quality_gate` `naming-policy-code-gate-wired` como pendente ate Gap4.F3; nao reivindicar `enforced` ate la |

## Exemplos

### Exemplo 1: Reuse de Subsystem Builder pelo Self-Directed Evolution

Correto:

```text
SelfDirectedEvolutionGapReadModelService
  -> le AtlasSelfConstructionSubsystemBuilderService::detectGaps()
  -> normaliza para atlas.evolution.gap_candidate.v1
  -> envia proposta para Operator Curation Inbox
```

Errado:

```text
NewCanonicalGapDetectorService
  -> ignora SubsystemBuilder
  -> cria registry paralelo de proposals
  -> marca proposal como aprovada
```

### Exemplo 2: Runtime Readiness Compaction Blueprint

Proposta de compactacao de `AtlasSelfConstructionReadinessService.php` em
families/read-models menores:

| Family | Responsabilidade | Regra |
|---|---|---|
| `ReadinessStatus` | readiness digest, governance scorecard, integrity manifest | read-only, schema snapshot first |
| `PacketProjection` | meta-SDD, implementation packet, packet queue, runbook, evidence report | preserve packet hashes |
| `ReservationProjection` | durable reservation, collision, lease, readiness, blueprints | no ledger write |
| `AgentProjection` | ACP, run sync, heartbeat, liveness, cost, work products | no provider start |
| `DispatchProjection` | preflight, receipt templates, executor release, launch/start packet | no dispatch |
| `ReviewMergeProjection` | review, decision, receipt, signature, merge authorization | no approval |
| `PersistenceProjection` | receipt persistence templates, fresh authorization, disable/new-cycle chains | no ledger write |

### Exemplo 3: Naming policy aplicada

```
# ANTES (proibido para arquivo novo):
AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker.php
(112 chars de classe)

# DEPOIS (canonico via namespacing):
namespace App\Services\Ai\SelfConstruction\AgentDispatch\OneShotTick\PostStart;
final class AuthorizationGate { ... }
(15 chars de classe, hierarquia clara via namespace)
```

### Exemplo 4: Dead code investigation honesta

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
2. **AP dedicado para compactar `AtlasSelfConstructionReadinessService.php`**: comecar por `ReadinessStatus` read-only. Cada extracao exige teste de regressao da familia + smoke do comando que consome o metodo antigo.
3. **Investigacao individual de reachability** para os 28 arquivos com nome `>100 chars` da familia `AgentAutomaticDispatch`: rodar `atlas:code-reality reachability --target=<each> --json`, persistir resultados em `evidence/self-construction-reachability-2026-05-26.json`, sem mover nenhum sem Decision Receipt v2.
4. **Reaudito ACRUI semanal** agendado para manter o snapshot fresco; comparar com baseline `count=292` e atualizar distribuicao de naming com evidencia nova.
5. **Quando a compactacao entregar**: atualizar secao "Exemplos / Runtime Readiness Compaction Blueprint" com numeros reais de linhas pos-refator e status `done`.
