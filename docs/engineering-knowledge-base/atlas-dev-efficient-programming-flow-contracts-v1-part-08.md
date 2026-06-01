---
id: atlas-dev-efficient-programming-flow-contracts-v1-part-08
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Contracts v1 · Parte 8
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Efficient Programming Flow Contracts v1: 7.2 FastPathErrorLedgerEntry ate 10. Resumo Operacional.
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
graph_id: atlas-dev-efficient-programming-flow-contracts-v1-part-08
graph_title: Atlas Dev Efficient Programming Flow Contracts v1 Parte 8
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-dev-efficient-programming-flow-contracts-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow Contracts v1 Parte 8
canonical_name: Atlas Dev Efficient Programming Flow Contracts v1 Parte 8
technical_name: atlas-dev-efficient-programming-flow-contracts-v1-part-08
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-08.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-08.md
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
  - symbol: FastPathErrorLedgerEntry
  - test: FastPathErrorLedgerEntryTest
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao contrato de documentação.
---
# Atlas Dev Efficient Programming Flow Contracts v1 · Parte 8

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Efficient Programming Flow Contracts v1: 7.2 FastPathErrorLedgerEntry ate 10. Resumo Operacional.

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
### 7.2 FastPathErrorLedgerEntry

Registro append-only de erros operacionais e missed escalations. Alimenta tuning futuro de thresholds.

#### Schema

```yaml
FastPathErrorLedgerEntry:
  schema_version: atlas.dev.fast_path_error_ledger.v1
  run_id: string
  failure_signature: string
  completion_state: passed|needs_review|failed|blocked|escalate_forge
  actual_failure_mode: wrong_file|wrong_scope|missed_test|bad_repair|missed_escalation|false_escalation|prompt_projection_error|context_error|other
  should_have_escalated: boolean|null
  missing_escalation_signals: list
  observed_signals:
    file_count: integer
    layers_touched: integer
    risk_keywords: list
    context_required_chars: integer|null
    prior_failure_in_area: boolean|null
    test_coverage_gap: boolean|null
  correction_recommendation: list
  reviewer_signed: boolean
  reviewer: string|null
  reviewed_at: string|null
  provider_safe: true
  entry_hash: string
```

#### Invariants

1. `reviewer_signed = true` exige `reviewer` e `reviewed_at` preenchidos.
2. `actual_failure_mode = missed_escalation` exige `should_have_escalated = true` E `missing_escalation_signals` nao vazio.
3. Append-only: entradas nao sao mutadas apos `reviewer_signed = true`.
4. Multiplas entradas por run sao permitidas (ex: missed escalation + wrong scope no mesmo run).

#### Identidade

- Chave: `(run_id, entry_hash)`.

#### PHP DTO Signature

```php
final class FastPathErrorLedgerEntry
{
    public function __construct(
        public readonly string $runId,
        public readonly string $failureSignature,
        public readonly string $completionState,
        public readonly string $actualFailureMode,
        public readonly ?bool $shouldHaveEscalated,
        public readonly array $missingEscalationSignals,
        public readonly ObservedSignals $observedSignals,
        public readonly array $correctionRecommendation,
        public readonly bool $reviewerSigned,
        public readonly ?string $reviewer,
        public readonly ?string $reviewedAt,
        public readonly string $entryHash,
    ) {}

    public function schemaVersion(): string { return 'atlas.dev.fast_path_error_ledger.v1'; }
}
```

---

## 8. PHP DTOs E Localizacao

### 8.1 Namespace

`App\Services\Ai\Programming\AtlasDev\Schemas\` (decisao locked 2026-05-16).

### 8.2 Mapa De Arquivos

```text
atlas-server/app/Services/Ai/Programming/AtlasDev/Schemas/
  OperationEnvelope.php
  CompactSdd.php
  MiniProgrammingSpec.php
  LightTaskContract.php
  ContextRetrievalPlan.php
  CodeDiscoveryManifest.php
  OpenBrainProgrammingProjection.php
  ProviderPromptProjection.php
  ScopeGuardReceipt.php
  VerificationReceipt.php
  FailureCapsule.php
  EscalationDecision.php
  FastPathTelemetry.php
  FastPathErrorLedgerEntry.php
  Components/
    GitState.php
    Preflight.php
    ContextBudget.php
    VerificationPlan.php
    RepairPolicy.php
    ProviderLock.php
    Budget.php
    Truncation.php
    PromptSections.php
    QualityChecks.php
    ScopeBaseline.php
    ScopeObserved.php
    ScopeContractView.php
    RepairSummary.php
    CostSummary.php
    CompletionSummary.php
    EscalationSummary.php
    EscalationSignals.php
    ObservedSignals.php
  Contracts/
    AtlasDevSchemaContract.php   # interface comum: schemaVersion(), toCanonicalArray(), hash()
```

### 8.3 Padroes De DTO

- `final class` para impedir extensao acidental.
- Todos os campos `public readonly` (PHP 8.1+).
- Construtor recebe **todos** os campos; nenhum opcional sem default explicito.
- `schemaVersion(): string` retorna constante.
- `toCanonicalArray(): array` retorna array com chaves ordenadas (alphabetical recursivo).
- `toJson(): string` retorna `json_encode(toCanonicalArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`.
- `hash(): string` retorna `hash('sha256', $this->toJson())`, excluindo o proprio campo `<entity>_hash` durante o calculo.
- Construtores **nao** validam invariants. Validacao acontece em validators dedicados (ver `AtlasDevSchemaValidator` no runbook).

### 8.4 Hash Sem Recursao

Cada DTO calcula seu hash **sem** incluir o proprio campo de hash. Ordem:

```php
$canonical = $this->toCanonicalArrayWithoutHash();
$json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$hash = hash('sha256', $json);
```

Quando o DTO referencia outro DTO por hash (ex: `task_contract_hash` em `VerificationReceipt`), o hash **e** incluido no payload — e referencia estavel, nao auto-referencia.

---

## 9. Regras De Uso

### 9.1 Sequencia Obrigatoria

```text
OperationEnvelope -> ContextRetrievalPlan -> CodeDiscoveryManifest
-> OpenBrainProgrammingProjection -> CompactSDD
-> MiniProgrammingSpec -> LightTaskContract -> ProviderPromptProjection
-> [provider call] -> ScopeGuardReceipt -> VerificationReceipt
-> FailureCapsule (se aplicavel) -> EscalationDecision (se aplicavel)
-> FastPathTelemetry -> FastPathErrorLedgerEntry (se aplicavel)
```

Pular etapa = bug operacional.

### 9.2 Hash Referencia Antes De Construir

Ao construir artefato downstream, **incluir hash do upstream**. Ex: `MiniProgrammingSpec.compact_sdd_hash` e referencia obrigatoria.

Se hash referenciado nao existe ou nao bate com artefato persistido, runtime falha (`receipt_gate` bloqueia).

### 9.3 Provider Safe E Filtros

`provider_safe: true` significa "pode ir no prompt sem revelar dado interno". Filtros aplicaveis ao construir `ProviderPromptProjection`:

- IDs internos viram refs;
- traces/system instructions internas omitidas;
- credenciais nunca aparecem;
- referencias a Rivals/medicao removidas (nao deveriam existir aqui, mas filtro defensivo).

### 9.4 Persistencia Antes Da Proxima Etapa

Cada artefato deve ser persistido em `storage/atlas-dev/receipts/<run_id>/` **antes** de a proxima etapa comecar. Crash entre etapas deve permitir resume baseado no que ja foi persistido.

### 9.5 Versionamento E Compatibilidade

- Leitores **nao** podem ler `vN+1` produzido por escritor mais novo.
- Escritor que sabe ler `vN` e produzir `vN+1` esta autorizado a fazer migracao explicita.
- `schema_version` em todo payload e obrigatorio. Payload sem `schema_version` e rejeitado.

### 9.6 Forbidden Cross-Contamination

- Schemas Atlas Dev **nao** importam schemas de Forge/Rivals/Open Brain alem do que esta declarado em `depends_on`.
- Schemas Atlas Dev **nao** definem regras de medicao/benchmark.
- Mudancas neste doc que adicionem campos relacionados a medicao competitiva sao rejeitadas.

---

## 10. Resumo Operacional

| Camada | Quem produz | Quem consome |
| --- | --- | --- |
| Plano | Intake + Classifier + Spec Composer | Pipeline e Provider Builder |
| Contexto | Tier Selector + Code Discovery + Open Brain Adapter + Prompt Builder | Provider chamado, validators |
| Receipt | Scope Guard + Verifier + Repair Loop + Escalation Engine | Completion gate, persistence |
| Telemetria | Telemetry Emitter + Error Ledger Writer | Sessao posterior, revisao operacional |

Schemas sao a fronteira contratual. Tudo o que cruza fatia cruza um schema deste doc.

