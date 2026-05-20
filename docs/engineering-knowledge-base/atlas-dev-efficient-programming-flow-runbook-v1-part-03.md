---
id: atlas-dev-efficient-programming-flow-runbook-v1-part-03
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1 · Parte 3
status: active
category: programming
priority: 104
summary: Recorte focado do runbook Atlas Dev Efficient Programming Flow v1: 7.2 PRs Sugeridos ate 8.1 Objetivo.
tags:
  - atlas-dev
  - efficient-programming-flow
  - runbook
  - split-doc
capabilities:
  - atlas_dev_implementation_runbook
  - atlas_dev_efficient_programming_flow
decisions:
  - Este recorte preserva uma parte operacional do runbook sem ampliar responsabilidade do indice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md quando o runbook Atlas Dev mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-runbook-v1-part-03
graph_title: Atlas Dev Efficient Programming Flow Runbook v1 Parte 3
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-runbook-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-03.md
allowed_changes:
  - Atualizar somente a parte operacional descrita neste recorte.
forbidden_changes:
  - Adicionar nova responsabilidade que pertença ao índice ou a outro recorte.
depends_on:
  - atlas-dev-efficient-programming-flow-runbook-v1
flows_to:
  - atlas_dev_efficient_flow_runtime
unlocks:
  - atlas_dev_operational_execution
governs:
  - atlas_dev.implementation.slices
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao runbook operacional.
---
# Atlas Dev Efficient Programming Flow Runbook v1 · Parte 3

## Resumo

Este recorte preserva uma parte operacional do runbook Atlas Dev Efficient Programming Flow v1: 7.2 PRs Sugeridos ate 8.1 Objetivo.

## Papel no Atlas

Mantém o detalhe executável fora do índice principal para que a cartografia e o modal humano continuem legíveis.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md` e deve ser lido apenas quando a pessoa precisar do detalhe desta fatia.

## Contratos

Segue o contrato do runbook principal, o glossário canônico e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → execução ou revisão da fatia correspondente.

## Regras para IA

Não inferir responsabilidade nova. Não misturar patamar, versão, fonte, risco, regra ou prova. Preservar backlink para o índice.

## Escopo de Implementacao

Este arquivo só guarda o detalhe operacional extraído do runbook maior.

## Dependencias

Depende do índice `atlas-dev-efficient-programming-flow-runbook-v1` e da documentação canônica relacionada.

## Evidencias

A evidência de origem é o runbook principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém editar este recorte como se fosse novo dono de fluxo, duplicando contrato.

## Exemplos

Os exemplos abaixo são o conteúdo operacional extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a fatia correspondente do Atlas Dev mudar e rodar docs-health.

## Conteudo Extraido
### 7.2 PRs Sugeridos

#### PR 1.1 — DocContextTierSelector

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Discovery/DocContextTierSelector.php
```

Signature:

```php
namespace App\Services\Ai\Programming\AtlasDev\Discovery;

use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;

final class DocContextTierSelector
{
    public function select(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
    ): ContextRetrievalPlan;
}
```

Regras (alinhadas a tabela 11.1 do contrato principal):

- `core` sempre se `task_kind != question`;
- `code_intelligence` sempre se `workspace_resolved = true`;
- `sdd` se `task_kind in (patch, repair)` ou `risk_level >= R2`;
- `interface` se `task_kind = frontend` ou surface `atlas_desktop_ai|atlas_app|atlas_code`;
- `forge` se `risk_level >= R4`;
- `obras` se thread >= 12 mensagens (heuristica) ou se compact_sdd indicar continuidade.

Budget chars do plan vem da tabela 11.2 mapeada por mode.

DoD do PR 1.1:

- [x] 7 cenarios de selecao cobertos por teste (1 por task_kind + edge cases).
- [x] `select()` e puro: mesma entrada = mesma saida.
- [x] Output passa por `ContextRetrievalPlanValidator`.

#### PR 1.2 — CodeDiscoveryEngine

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Discovery/CodeDiscoveryEngine.php
```

Signature:

```php
final class CodeDiscoveryEngine
{
    public function __construct(
        private readonly CodeIntelligenceService $codeIntelligence,  // existente
        private readonly RipgrepRunner $rg,                          // existente ou novo wrapper
    ) {}

    public function discover(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
    ): CodeDiscoveryManifest;
}
```

Algoritmo (ordem):

1. extrair simbolos provaveis do `normalized_intent` (regex de identificadores PHP/TS, nomes Camel/Snake);
2. consultar `CodeIntelligenceService` por simbolos -> arquivos candidatos com confidence;
3. validar paths via `is_file()` no workspace;
4. confirmar matches via `rg` para reforcar/derrubar confidence;
5. listar testes relacionados via convencao (`tests/Unit/<Class>Test.php`, `tests/Feature/...`);
6. derivar `forbidden_files` por padroes (vendor/*, node_modules/*, .env*);
7. preencher `missing_refs` quando confidence < strong_inference.

Limites:

- Paths nao validados nunca entram em `likely_files` (decisao locked).
- Se nenhum simbolo extraido E nenhum hit via grep, `confidence = blocking_ambiguity` e marca `missing_refs`.

DoD do PR 1.2:

- [x] Cenario 1: intent com simbolo claro -> manifest com `confidence = strong_inference` e 1+ `likely_files` validos.
- [x] Cenario 2: intent vago -> `confidence = blocking_ambiguity` + `missing_refs` nao vazio.
- [x] Cenario 3: intent menciona path inexistente -> path vira `missing_refs`, nao `likely_files`.
- [x] Output passa por `CodeDiscoveryManifestValidator`.
- [x] Fixture com workspace temporario criado em `tests/Fixtures/AtlasDev/CodeDiscovery/workspace_a/`.

#### PR 1.3 — OpenBrainProjectionAdapter

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Discovery/OpenBrainProjectionAdapter.php
```

Signature:

```php
final class OpenBrainProjectionAdapter
{
    public function __construct(
        private readonly OpenBrainContextInjector $injector,  // existente
    ) {}

    public function projectFor(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
        ContextRetrievalPlan $plan,
    ): OpenBrainProgrammingProjection;
}
```

Regras:

- consume Open Brain via adapter existente;
- monta projection com `schema_version: atlas.open_brain.programming_projection.v1` (alinhado ao schema upstream);
- `mode: programming` sempre;
- `objective_hash = sha256(normalized_intent)`;
- respeita budget de chars do plan;
- registra `missing_sources` se `required_sources` faltar;
- aplica filtros provider-safe (decisao do contrato principal secao 11).

DoD do PR 1.3:

- [x] Cenario 1: budget caber -> `truncation.truncated = false`.
- [x] Cenario 2: budget estourado -> `truncation.truncated = true` + `reasons`.
- [x] Cenario 3: required_source faltando -> retorna projection com `missing_sources` populado E sinaliza para o pipeline escalar.
- [x] Output passa por `OpenBrainProgrammingProjectionValidator`.

### 7.3 DoD Operacional Da Fatia 1

- `composer test --filter=AtlasDev/Discovery` verde.
- Lint zero issues na pasta `Discovery/`.
- Para um workspace fixture conhecido + envelope fixture, a saida e **byte-identica** entre duas execucoes (determinismo).
- Logs no canal `atlas_dev` registram tier selection com motivos.
- **Sem provider call. Sem prompt. Sem patch.**

---

## 8. Fatia 1.5 — Runtime Quality Foundations

### 8.1 Objetivo

Garantir que, ao chegar na Fatia 3 (provider real), exista **fundacao operacional** completa:

- Prompt nao improvisado (`ProviderPromptProjection`);
- Telemetria desde o run 1;
- Error ledger desde o run 1;
- Persistencia local de receipts.

Isto **nao** e medicao competitiva — e engenharia de runtime auditavel.

