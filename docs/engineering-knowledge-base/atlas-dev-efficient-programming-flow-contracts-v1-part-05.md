---
id: atlas-dev-efficient-programming-flow-contracts-v1-part-05
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Contracts v1 · Parte 5
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Efficient Programming Flow Contracts v1: 5.2 CodeDiscoveryManifest ate 6. Camada Receipt.
tags:
  - atlas-dev
  - split-doc
  - cartography-readable
capabilities:
  - atlas_documentation_split
  - atlas_cartography_readable_docs
decisions:
  - Este recorte preserva uma parte operacional do documento maior sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md quando o documento dono mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-contracts-v1-part-05
graph_title: Atlas Dev Efficient Programming Flow Contracts v1 Parte 5
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-dev-efficient-programming-flow-contracts-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow Contracts v1 Parte 5
canonical_name: Atlas Dev Efficient Programming Flow Contracts v1 Parte 5
technical_name: atlas-dev-efficient-programming-flow-contracts-v1-part-05
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-05.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-05.md
allowed_changes:
  - Atualizar somente a parte descrita neste recorte.
forbidden_changes:
  - Adicionar nova responsabilidade que pertença ao índice ou a outro recorte.
depends_on:
  - atlas-dev-efficient-programming-flow-contracts-v1
flows_to:
  - atlas-dev-efficient-programming-flow-contracts-v1
unlocks:
  - atlas_cartography_readable_documentation
governs:
  - atlas.documentation.split_docs
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
evidence_refs:
  - symbol: AtlasDevEffProgFlowContractsV1Part05Service
  - command: atlas:aaeos:atlas-dev-eff-prog-flow-contracts-v1-part05
  - test: AtlasDevEffProgFlowContractsV1Part05Test
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao contrato de documentação.
---
# Atlas Dev Efficient Programming Flow Contracts v1 · Parte 5

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Efficient Programming Flow Contracts v1: 5.2 CodeDiscoveryManifest ate 6. Camada Receipt.

## Papel no Atlas

Mantém detalhe canônico fora do índice principal para que a cartografia e o modal humano continuem legíveis.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md` e deve ser lido apenas quando a pessoa precisar deste detalhe.

## Contratos

Segue o documento dono, o glossário canônico e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → decisão, implementação ou revisão correspondente.

## Regras para IA

Não inferir responsabilidade nova. Não misturar patamar, versão, fonte, risco, regra ou prova. Preservar backlink para o índice.

## Escopo de Implementacao

Este arquivo só guarda o detalhe extraído do documento maior.

## Dependencias

Depende do índice `atlas-dev-efficient-programming-flow-contracts-v1` e da documentação canônica relacionada.

## Evidencias

A evidência de origem é o documento principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém editar este recorte como se fosse novo dono de fluxo, duplicando contrato.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a parte correspondente mudar e rodar docs-health.

## Conteudo Extraido
### 5.2 CodeDiscoveryManifest

Likely files/symbols/tests com niveis de confianca. Substitui "Opus alucina path" pelo Atlas com lacunas explicitas.

#### Schema

```yaml
CodeDiscoveryManifest:
  schema_version: atlas.dev.code_discovery_manifest.v1
  run_id: string
  compact_sdd_hash: string
  objective: string
  confidence: confirmed_fact|strong_inference|hypothesis|blocking_ambiguity
  likely_files:
    - path: string                # path absoluto validado por filesystem
      reason: string
      confidence: number          # 0.0 - 1.0
      symbols: list               # simbolos relevantes naquele arquivo
  related_symbols:
    - name: string
      kind: class|function|method|trait|interface|type
      file: string
      line: integer|null
  related_tests:
    - path: string
      reason: string
  forbidden_files: list           # paths que NAO podem ser tocados
  missing_refs:                   # lacunas honestas; nao inventar path
    - what: string
      why_missing: string
  provider_safe: true
  manifest_hash: string
```

#### Invariants

1. `likely_files[].path` deve existir no filesystem ao tempo da geracao (`is_file()` true).
2. `likely_files[].confidence` in [0.0, 1.0].
3. `manifest.confidence = blocking_ambiguity` impede progredir; runtime marca `blocked`.
4. `missing_refs` nao vazio se confidence < strong_inference.
5. `related_tests` nao vazio se `task_kind in (patch, repair)` e ha testes no workspace.
6. Nenhum path em `likely_files` aparece em `forbidden_files`.
7. Paths nao validados nao entram em `likely_files` — vao para `missing_refs`.

#### Identidade

- Chave: `(run_id, manifest_hash)`.

#### Exemplo Valido

```yaml
schema_version: atlas.dev.code_discovery_manifest.v1
run_id: "0192b5d2-..."
compact_sdd_hash: "feedcafe..."
objective: "Localizar codigo do bug em AtlasCliDevWorkflowService"
confidence: strong_inference
likely_files:
  - path: "/Users/op/code/atlas-server/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
    reason: "nome direto no test name"
    confidence: 0.95
    symbols: ["AtlasCliDevWorkflowService", "resolveWorkspace"]
related_symbols:
  - name: "AtlasCliDevWorkflowService"
    kind: class
    file: "/Users/op/code/atlas-server/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
    line: 45
related_tests:
  - path: "/Users/op/code/atlas-server/tests/Unit/AtlasCliDevWorkflowServiceTest.php"
    reason: "teste falhando explicito no intent"
forbidden_files:
  - "vendor/*"
  - "node_modules/*"
missing_refs: []
provider_safe: true
manifest_hash: "dec0ded0..."
```

#### Exemplos Invalidos

| Caso | Motivo |
| --- | --- |
| `likely_files[0].path` aponta para arquivo que nao existe | Violacao de invariant 1 |
| `likely_files[0].confidence: 1.5` | Violacao de invariant 2 |
| `confidence: blocking_ambiguity` + downstream gera task_contract | Violacao de invariant 3 |
| `missing_refs: []` + `confidence: hypothesis` | Violacao de invariant 4 |
| Path inventado sem `is_file()` check | Violacao de invariant 7 |

#### PHP DTO Signature

```php
final class CodeDiscoveryManifest
{
    public function __construct(
        public readonly string $runId,
        public readonly string $compactSddHash,
        public readonly string $objective,
        public readonly string $confidence,
        public readonly array $likelyFiles,
        public readonly array $relatedSymbols,
        public readonly array $relatedTests,
        public readonly array $forbiddenFiles,
        public readonly array $missingRefs,
        public readonly string $manifestHash,
    ) {}

    public function isBlocking(): bool { return $this->confidence === 'blocking_ambiguity'; }
    public function schemaVersion(): string { return 'atlas.dev.code_discovery_manifest.v1'; }
}
```

---

### 5.3 OpenBrainProgrammingProjection

Projection compacta do Open Brain. Refs preferidas a texto.

#### Schema

```yaml
OpenBrainProgrammingProjection:
  schema_version: atlas.open_brain.programming_projection.v1
  run_id: string
  surface: string
  mode: programming
  workspace_hash: string
  objective_hash: string          # sha256(normalized_intent)
  tier_selection: list
  context_pack_hash: string
  memory_refs:                    # refs, nao texto
    - kind: decision|learning|technical_context|harness_learning
      ref: string
      reason: string
  knowledge_refs:
    - kind: doc|section
      ref: string
      reason: string
  code_refs:
    - kind: symbol|file|route|command|test|doc_link
      ref: string
      reason: string
  selected_files: list
  required_sources: list
  missing_sources: list
  budget:
    chars_requested: integer
    chars_used: integer
  truncation:
    truncated: boolean
    reasons: list
  provider_safe: true
  projection_hash: string
```

#### Invariants

1. `schema_version` e fixo `atlas.open_brain.programming_projection.v1` (alinhado ao schema existente do Open Brain).
2. `mode` e sempre `programming` neste fluxo.
3. `objective_hash = sha256(normalized_intent)` para audit reproducible.
4. `budget.chars_used <= budget.chars_requested`.
5. `truncation.truncated = true` exige `truncation.reasons` nao vazio.
6. `missing_sources` nao vazio bloqueia progresso se `required_sources` faltar.
7. `provider_safe: true` sempre (Open Brain ja sanitiza); se houver risco, projection deve ser regenerada.

#### Identidade

- Chave: `(run_id, projection_hash)`.

#### PHP DTO Signature

```php
final class OpenBrainProgrammingProjection
{
    public function __construct(
        public readonly string $runId,
        public readonly string $surface,
        public readonly string $workspaceHash,
        public readonly string $objectiveHash,
        public readonly array $tierSelection,
        public readonly string $contextPackHash,
        public readonly array $memoryRefs,
        public readonly array $knowledgeRefs,
        public readonly array $codeRefs,
        public readonly array $selectedFiles,
        public readonly array $requiredSources,
        public readonly array $missingSources,
        public readonly Budget $budget,
        public readonly Truncation $truncation,
        public readonly string $projectionHash,
    ) {}

    public function schemaVersion(): string { return 'atlas.open_brain.programming_projection.v1'; }
}
```

---

### 5.4 ProviderPromptProjection

O prompt enviado ao provider nao pode ser improvisado. E projecao deterministica de todos os artefatos anteriores.

#### Schema

```yaml
ProviderPromptProjection:
  schema_version: atlas.dev.provider_prompt_projection.v1
  run_id: string
  task_contract_hash: string      # raiz da projecao
  provider: claude_cli
  model_family: sonnet
  sections:
    objective: string             # = mini_spec.goal
    operating_rules: list         # regras gerais do Atlas Dev
    mini_spec_ref: string         # hash + path do mini_spec
    task_contract_ref: string     # hash + path do task_contract
    context_refs: list            # refs do open_brain_projection
    code_discovery_ref: string    # hash + path do code_discovery_manifest
    allowed_files: list
    forbidden_files: list
    expected_tests: list
    acceptance_criteria: list
    stop_conditions: list
    escalation_conditions: list
    output_contract:              # como o provider deve responder
      - "diff em formato unified"
      - "lista de changed_files"
      - "razao se no_patch_needed"
  quality_checks:
    no_missing_required_sections: boolean
    no_unbounded_scope: boolean
    no_hidden_benchmark_instruction: boolean
    no_conflicting_file_rules: boolean
    no_forge_or_council_leakage: boolean
    provider_safe: boolean
  rendered_prompt_text: string       # obrigatorio; gerado pelo ProviderPromptBuilder
  prompt_projection_hash: string
```

#### Invariants

1. Toda secao obrigatoria nao vazia. Falta = `no_missing_required_sections: false` = bloqueia envio.
2. `allowed_files` e `forbidden_files` consistentes com `task_contract`.
3. `output_contract` exige resposta verificavel (diff/lista, nao texto livre).
4. `no_hidden_benchmark_instruction: false` bloqueia. Prompt nao pode conter referencias a Rivals/medicao.
5. `no_forge_or_council_leakage: false` bloqueia. Prompt nao pede council/topology/Forge direto.
6. `no_conflicting_file_rules: false` bloqueia. Allowed e forbidden disjuntos.
7. `rendered_prompt_text` e obrigatorio antes de qualquer provider call.
8. `prompt_projection_hash` muda a cada mudanca em qualquer secao ou no rendered prompt.

#### Identidade

- Chave: `(run_id, prompt_projection_hash)`.

#### PHP DTO Signature

```php
final class ProviderPromptProjection
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly string $provider,
        public readonly string $modelFamily,
        public readonly PromptSections $sections,
        public readonly QualityChecks $qualityChecks,
        public readonly string $renderedPromptText,
        public readonly string $promptProjectionHash,
    ) {}

    public function isSendable(): bool { return $this->qualityChecks->allPassed(); }
    public function schemaVersion(): string { return 'atlas.dev.provider_prompt_projection.v1'; }
}
```

---

## 6. Camada Receipt

