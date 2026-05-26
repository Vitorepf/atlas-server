---
id: atlas-dev-efficient-programming-flow-contracts-v1-part-02
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Contracts v1 · Parte 2
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Efficient Programming Flow Contracts v1: 4.1 OperationEnvelope ate 4.2 CompactSDD.
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
graph_id: atlas-dev-efficient-programming-flow-contracts-v1-part-02
graph_title: Atlas Dev Efficient Programming Flow Contracts v1 Parte 2
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-dev-efficient-programming-flow-contracts-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow Contracts v1 Parte 2
canonical_name: Atlas Dev Efficient Programming Flow Contracts v1 Parte 2
technical_name: atlas-dev-efficient-programming-flow-contracts-v1-part-02
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-02.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-02.md
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
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao contrato de documentação.
---
# Atlas Dev Efficient Programming Flow Contracts v1 · Parte 2

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Efficient Programming Flow Contracts v1: 4.1 OperationEnvelope ate 4.2 CompactSDD.

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
### 4.1 OperationEnvelope

Entrada normalizada do intake. Toda execucao comeca aqui.

Boundary anti-duplicacao: `AtlasDevOperationEnvelope` e a classe real do DTO
local do Atlas Dev e carrega `schema_version:
atlas.dev.operation_envelope.v1`. O FQCN curto antigo `OperationEnvelope`
existe apenas como alias compat. Ele nao e
`App\Services\Ai\Kernel\Envelope\OperationEnvelope` (`atlas.envelope.v1`) e nao
deve ser importado como contrato Kernel. Ponte entre Atlas Dev e Kernel exige
adapter explicito e teste de contrato.

#### Schema

```yaml
OperationEnvelope:
  schema_version: atlas.dev.operation_envelope.v1
  run_id: string             # UUID v7
  surface_id: atlas_cli_dev|atlas_desktop_ai|atlas_app|atlas_api_interaction
  surface_context:
    product_surface: atlas_ai_desktop_mac|cli_dev|atlas_app|api_interaction
    thread_id: string|null
    conversation_id: string|null
    composer_mode: general|operational|programming|null
    composer_task: direct|plan|review|dev|debug|null
    provider_choice: auto|claude_cli|codex_cli|gemini_cli|claude_codex|null
  flow_id: atlas_dev                   # fluxo especializado que este contrato governa
  flow_origin: atlas_ai_router|direct   # Router decifrou pedido vs surface chamou direto
  command_intent: string|null           # slash command decifrado (fix|explain|research|debug|conversation|...) quando flow_origin=atlas_ai_router
  business_context:                      # Kernel estagio 6
    organization: string|null            # ex: blacklink|atlas|cliente_xyz
    project: string|null                 # ex: atlas-server, atlas-desktop, customer-app
    environment: dev|staging|production|null
    customer_id: string|null             # quando organization=cliente_xyz
    privacy_class: public|internal|confidential|restricted|null
  workspace: string          # absolute path
  workspace_hash: string     # sha256 do (git_root + "::" + git_head_sha)
  git_state:
    head_sha: string|null
    dirty: boolean
    untracked_count: integer
    pending_changes_count: integer
  raw_intent: string         # texto bruto do operador
  normalized_intent: string  # normalizacao sem trocar significado
  user_constraints: list     # restricoes explicitas (ex: ["nao tocar tests/", "max 3 arquivos"])
  intent_clarity_level: high|medium|low|blocking
  attachments:               # Kernel estagio 3 (Atlas Input multimodal)
    - kind: image|file|paste|audio
      ref: string            # path absoluto OU sha256 do conteudo inline
      content_inline: string|null   # base64 quando inline; preferir ref quando volumoso
      mime_type: string      # ex: image/png, text/plain
      hash: string           # sha256 do conteudo
      reason: string|null    # por que o operador anexou
  dirty_worktree_policy: preserve_user_changes
  preflight:
    workspace_resolved: boolean
    permission_mode: read|write|danger
    write_allowed: boolean
    operator_explicit: boolean
  provider_safe: true
  envelope_hash: string      # sha256 do payload canonicalizado sem este campo
```

#### Invariants

1. `run_id` e UUID v7 (timestamp embedded). Nao reutilizar entre runs.
2. `workspace` e path absoluto existente. Se nao existir, falha imediata (`blocked_no_workspace`).
3. `workspace_hash` muda quando `git_head_sha` muda; mesma sessao com commits novos = envelopes diferentes downstream.
4. `raw_intent` e o texto original; `normalized_intent` nao pode contradizer (so normaliza acentos, whitespace, capitalizacao).
5. `intent_clarity_level = blocking` impede progredir; runtime deve pedir esclarecimento ou marcar `blocked`.
6. `write_allowed = true` exige `permission_mode in (write, danger)` E `operator_explicit = true` para modo `danger`.
7. `surface_id = atlas_desktop_ai` exige `surface_context.product_surface = atlas_ai_desktop_mac`.
8. `surface_context.composer_mode = programming` exige `workspace` resolvido antes de write.
9. `flow_id` e sempre `atlas_dev`. Outro fluxo deve usar contrato proprio ou ser roteado pelo Atlas AI Router antes de chegar aqui.
10. `flow_origin = atlas_ai_router` significa que o Router ja decidiu que este envelope pertence ao Atlas Dev; Atlas Dev nao reinterpreta slash commands globais.
11. `flow_origin = atlas_ai_router` exige `command_intent != null` quando o Router decifrou slash command; senao `command_intent = null`.
12. `command_intent` aceita apenas o conjunto canonico (fix, explain, research, test, refactor, review, debug, plan, conversation); valor fora do conjunto rejeita envelope ou retorna `delegate_to_other_flow`.
12.1. `command_intent` e **hint roteado pelo Atlas AI Router**, nao autoridade de UI. Atlas Dev nao pode reinterpretar slash commands globais nem promover `command_intent` a decisao de produto: rota inter-fluxo continua sendo prerrogativa do Router; risco/escopo/provider continua sendo prerrogativa do core Atlas Dev.
13. `business_context` e obrigatorio a partir da Fatia 5 (paridade de surfaces). Em fatias anteriores pode vir todo `null`, mas a Fatia 5 enforces preenchimento minimo de `organization` + `project`.
14. `business_context.privacy_class = confidential|restricted` forca governanca extra: prompt projection oculta paths absolutos, telemetria omite excerpts crus.
15. `attachments[].kind = image` exige provider com vision capability; validator falha se `provider_lock.model_family` nao suporta multimodal.
16. `attachments[].ref` e path absoluto OU `sha256:<hash>` referenciando blob persistido em `storage/atlas-dev/attachments/<run_id>/`.
17. `envelope_hash` e auto-referente: calculado sobre todo o resto canonicalizado.

#### Identidade

- Chave: `run_id`.
- Hash: `envelope_hash`.

#### Exemplo Valido

> Nota: este exemplo mostra o envelope **interno**, ja apos a resolucao
> Surface -> core. Em payloads de entrada HTTP, a surface Desktop envia
> `workspace` como **slug** de Projeto (ex: `"atlas-server"`); o `PlanController`
> resolve esse slug por `config/atlas_projects.php` antes de criar o
> `OperationEnvelope`. CLI/App/API podem enviar path absoluto direto. Em
> qualquer caso, o `OperationEnvelope` persistido carrega `workspace` absoluto
> e canonico. A projecao HTTP de saida troca `workspace` por `workspace_label`
> + `workspace_hash` (ver 3.5.3).

```yaml
schema_version: atlas.dev.operation_envelope.v1
run_id: "0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d"
surface_id: atlas_desktop_ai
surface_context:
  product_surface: atlas_ai_desktop_mac
  thread_id: "thread_01J..."
  conversation_id: "conversation_01J..."
  composer_mode: programming
  composer_task: dev
  provider_choice: auto
flow_id: atlas_dev
flow_origin: atlas_ai_router
command_intent: fix
business_context:
  organization: atlas
  project: atlas-server
  environment: dev
  customer_id: null
  privacy_class: internal
workspace: "/Users/op/code/atlas-server"
workspace_hash: "a1b2c3..."
git_state:
  head_sha: "f4e5d6..."
  dirty: false
  untracked_count: 0
  pending_changes_count: 0
raw_intent: "corrija o teste falhando em AtlasCliDevWorkflowServiceTest"
normalized_intent: "corrigir teste falhando em AtlasCliDevWorkflowServiceTest"
user_constraints: []
intent_clarity_level: high
attachments: []
dirty_worktree_policy: preserve_user_changes
preflight:
  workspace_resolved: true
  permission_mode: write
  write_allowed: true
  operator_explicit: false
provider_safe: true
envelope_hash: "deadbeef..."
```

#### Exemplos Invalidos

| Caso | Motivo |
| --- | --- |
| `run_id: "123"` | Nao e UUID v7 valido |
| `workspace: "./atlas-server"` | Nao e absoluto |
| `normalized_intent` diz "implementar feature X" quando `raw_intent` diz "explicar feature X" | Normalizacao mudou significado |
| `intent_clarity_level: blocking` + downstream prossegue | Violacao de invariant 5 |
| `permission_mode: danger` + `operator_explicit: false` + `write_allowed: true` | Violacao de invariant 6 |
| `surface_id: atlas_desktop_ai` + `product_surface: atlas_app` | Violacao de invariant 7 |
| `flow_id: atlas_research` | Este contrato governa apenas `atlas_dev` |
| `flow_origin: atlas_ai_router` + `command_intent: null` quando houve slash command roteado | Router perdeu o intent decifrado |
| `command_intent: brainstorm` | Fora do conjunto canonico |

#### PHP DTO Signature

```php
namespace App\Services\Ai\Programming\AtlasDev\Schemas;

final class AtlasDevOperationEnvelope
{
    public function __construct(
        public readonly string $runId,
        public readonly string $surfaceId,
        public readonly SurfaceContext $surfaceContext,
        public readonly string $flowId,
        public readonly string $flowOrigin,
        public readonly ?string $commandIntent,
        public readonly BusinessContext $businessContext,
        public readonly string $workspace,
        public readonly string $workspaceHash,
        public readonly GitState $gitState,
        public readonly string $rawIntent,
        public readonly string $normalizedIntent,
        public readonly array $userConstraints,
        public readonly string $intentClarityLevel,
        public readonly array $attachments,
        public readonly string $dirtyWorktreePolicy,
        public readonly Preflight $preflight,
        public readonly string $envelopeHash,
    ) {}

    public function schemaVersion(): string { return 'atlas.dev.operation_envelope.v1'; }
    public function toCanonicalArray(): array { /* canonical key order */ }
    public function isProviderSafe(): bool { return true; }
}
```

---

### 4.2 CompactSDD

Classificacao + risco + scope mode + context budget + hashes dos artefatos seguintes. E o **mapa do run**.

#### Schema

```yaml
CompactSDD:
  schema_version: atlas.dev.compact_sdd.v1
  run_id: string
  envelope_hash: string
  intent_raw: string
  intent_normalized: string
  task_kind: question|patch|repair|review|frontend|risky
  risk_level: R0|R1|R2|R3|R4|R5
  scope_mode: compact|structural
  mode: read_only|plan_only|patch|repair|escalate_preview
  context_budget:
    max_chars: integer        # decisao locked: chars
    max_docs: integer
    max_candidate_files: integer
    max_plan_steps: integer
    max_provider_calls: integer
    max_repair_attempts: integer
  doc_tiers_required: list    # subset de [core, code_intelligence, sdd, interface, forge, obras]
  verification_profile: php_laravel|ts_react|generic_no_test
  context_digest: string|null  # hash do contexto montado, preenchido apos retrieval
  escalation_triggers: list    # quais sinais ja indicam escalada potencial
  mini_spec_hash: string|null  # preenchido quando mini_spec fechar
  task_contract_hash: string|null  # preenchido quando task_contract fechar
  provider_safe: true
  compact_sdd_hash: string
```

#### Invariants

1. `risk_level = R4|R5` forca `mode = escalate_preview`. Nunca patch direto no fast path.
2. `task_kind = question` forca `mode = read_only`.
3. `task_kind = risky` forca `risk_level >= R4`.
4. `context_budget.max_chars` segue tabela do contrato principal (secao 11.2).
5. `verification_profile` e exigido se `task_kind in (patch, repair, frontend)`.
6. `mini_spec_hash` e `task_contract_hash` sao null no nascimento e preenchidos canonicamente quando os artefatos seguintes fecham. Mutacao monotônica.
7. `compact_sdd_hash` e calculado **excluindo** `mini_spec_hash` e `task_contract_hash` (que mudam depois). Ele garante identidade da decisao de classificacao, nao do conjunto inteiro.

#### Identidade

- Chave: `(run_id, compact_sdd_hash)`.
- Hash: `compact_sdd_hash`.

#### Exemplo Valido (Patch R2)

```yaml
schema_version: atlas.dev.compact_sdd.v1
run_id: "0192b5d2-..."
envelope_hash: "deadbeef..."
intent_raw: "corrija o teste falhando em AtlasCliDevWorkflowServiceTest"
intent_normalized: "corrigir teste falhando em AtlasCliDevWorkflowServiceTest"
task_kind: repair
risk_level: R2
scope_mode: compact
mode: repair
context_budget:
  max_chars: 12000
  max_docs: 4
  max_candidate_files: 6
  max_plan_steps: 4
  max_provider_calls: 1
  max_repair_attempts: 1
doc_tiers_required: [core, code_intelligence, sdd]
verification_profile: php_laravel
context_digest: null
escalation_triggers: []
mini_spec_hash: null
task_contract_hash: null
provider_safe: true
compact_sdd_hash: "feedcafe..."
```

#### Exemplos Invalidos

| Caso | Motivo |
| --- | --- |
| `task_kind: risky` + `risk_level: R2` | Violacao de invariant 3 |
| `risk_level: R4` + `mode: patch` | Violacao de invariant 1 |
| `task_kind: patch` + `verification_profile: null` | Violacao de invariant 5 |
| `context_budget.max_chars: 32000` + `task_kind: question` | Excede budget para read-only (4-6k chars) |

#### PHP DTO Signature

```php
final class CompactSdd
{
    public function __construct(
        public readonly string $runId,
        public readonly string $envelopeHash,
        public readonly string $intentRaw,
        public readonly string $intentNormalized,
        public readonly string $taskKind,
        public readonly string $riskLevel,
        public readonly string $scopeMode,
        public readonly string $mode,
        public readonly ContextBudget $contextBudget,
        public readonly array $docTiersRequired,
        public readonly string $verificationProfile,
        public readonly ?string $contextDigest,
        public readonly array $escalationTriggers,
        public readonly ?string $miniSpecHash,
        public readonly ?string $taskContractHash,
        public readonly string $compactSddHash,
    ) {}

    public function withMiniSpecHash(string $hash): self { /* monotonic */ }
    public function withTaskContractHash(string $hash): self { /* monotonic */ }
    public function schemaVersion(): string { return 'atlas.dev.compact_sdd.v1'; }
}
```

---
