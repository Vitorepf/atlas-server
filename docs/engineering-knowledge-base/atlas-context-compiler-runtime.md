---
id: atlas-context-compiler-runtime
type: engineering_knowledge
title: Atlas Context Compiler Runtime
status: active
implementation_state: partial_runtime_with_future_scope
blocker: Provider-call enforcement final depende de ATER/ACPFR; runtime ACCR read-only ja compila pack provider-aware, loss check e budget receipt.
category: intelligence-runtime
priority: 99
summary: Doc filha AUCRI para compilar o contexto recuperado em input final provider-aware, menor, auditavel, evidence-safe e com perda de informacao medida.
tags: [atlas-ai, aucri, accr, context-compiler, prompt-compiler, provider-aware]
capabilities: [context_compilation, provider_profile, loss_check, prompt_budgeting, evidence_safe_prompt]
decisions:
  - ACCR e compilador de contexto, nao retrieval nem mero prompt template.
  - O input final ao provider deve ser menor, melhor ordenado e auditavel.
  - Must-keep, evidence refs, policy e output contract nao podem ser removidos.
maintenance:
  - Atualizar antes de mudar formato final de prompt, provider profile ou loss check.
related_paths:
  - docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-cognitive-memory-fabric.md
  - docs/engineering-knowledge-base/atlas-context-ranking-system.md
  - docs/engineering-knowledge-base/atlas-context-freshness-quality-gate.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Context/AtlasContextCompilerRuntimeService.php
  - app/Console/Commands/AtlasContextCompilerRuntimeCommand.php
  - tests/Feature/Ai/Context/ContextCompilerRuntimeTest.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Context Compiler Runtime
runtime_acronym: ACCR
internal_product_name: Atlas Prompt Compiler
technical_runtime: AtlasContextCompilerRuntimeService
graph_id: atlas-context-compiler-runtime
graph_title: Atlas Context Compiler Runtime
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-context-compiler-runtime.md
allowed_changes:
  - Definir compiler stages, provider profiles, prompt budgets e loss checks.
forbidden_changes:
  - Remover must-keep refs para reduzir token.
  - Misturar fato, inferencia, memoria e estimativa no contexto final.
  - Declarar economia de token sem sufficiency/evidence preservadas.
depends_on: [atlas-unified-context-retrieval-intelligence, atlas-cognitive-memory-fabric]
flows_to: [atlas-quality-preserving-efficiency-system, atlas-ai-router-runtime-enterprise-upgrade, atlas-ai-product-certification]
unlocks: [provider_aware_context_pack, prompt_loss_check, token_efficient_reasoning_input]
governs: [final_context_pack, provider_prompt_contract, context_loss_accounting]
evidence:
  - docs/engineering-knowledge-base/atlas-context-compiler-runtime.md
evidence_refs:
  - symbol: AtlasContextCompilerRuntimeService
  - command: atlas:context:compile
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:context:compile --json"
  - "php artisan test tests/Feature/Ai/Context/ContextCompilerRuntimeTest.php"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Implementar compiler read-only com fixtures de provider e loss accounting.
---

# Atlas Context Compiler Runtime

## Resumo

ACCR e o bloco 16 da AUCRI. Ele transforma o contexto recuperado, ranqueado,
validado e aquecido em um input final otimizado para o provider, o flow, o risco
e o contrato de resposta.

## Papel no Atlas

Retrieval bom nao garante resposta boa. ACCR fecha a cadeia: ele compila fatos,
decisoes, constraints, evidence refs, riscos e instrucoes no melhor pacote para
o LLM. O ganho esperado e menos token, menos ruido e mais aderencia ao objetivo.

## Onde Se Encaixa

```text
AHRI/AARF -> ACRS -> ACFQ -> ACMF -> ACCR -> provider
```

ACCR nao busca fontes. Ele recebe candidatos aprovados e decide a forma final do
context pack.

## Contratos

- `atlas.context.compiler_input.v1`
- `atlas.context.compiled_pack.v1`
- `atlas.context.provider_profile.v1`
- `atlas.context.loss_check.v1`
- `atlas.context.prompt_budget_receipt.v1`

Campos minimos: `flow_id`, `provider`, `risk_level`, `must_keep_refs`,
`included_refs`, `excluded_refs`, `compression_strategy`, `loss_score`,
`token_budget`, `output_contract`, `compiled_hash`.

## Fluxo

1. Receber contexto aprovado por ACFQ e ACMF.
2. Carregar provider profile.
3. Separar fatos, inferencias, memorias, decisoes, blockers e riscos.
4. Aplicar budget sem remover must-keep.
5. Ordenar por utilidade cognitiva e dependencia causal.
6. Gerar context pack final e output contract.
7. Rodar loss check e bloquear se perda critica existir.
8. Emitir prompt budget receipt e compiled hash.

## Regras para IA

- Nao usar template unico para todos os providers.
- Nao comprimir fonte critica sem manter ref e hash.
- Nao misturar instrucao de sistema com evidencia operacional.
- Nao enviar contexto bruto quando contexto compilado e suficiente.
- Nao declarar token saving se o loss check falhar.

## Escopo de Implementacao

Componentes obrigatorios cobertos pelo runtime read-only:

- Provider Profile Registry.
- Context Segmenter.
- Must-Keep Preserver.
- Prompt Budget Allocator.
- Evidence-Safe Formatter.
- Tool/Output Contract Injector.
- Loss Accounting Engine.
- Compiled Pack Hasher.
- Readiness/Certification command.

## Dependencias

Depende de AUCRI para fontes, ACRS para ranking, ACFQ para qualidade, ACMF para
delta/working memory, ARPTL para privacy e ACOP para observabilidade.

## Evidencias

Evidencia atual:

- `atlas:context:compile --json`;
- compiled pack com hash deterministico;
- loss check com must-keep coverage;
- teste que prova que decision/blocker/DoD nao somem;
- teste provider-aware para Claude/GPT/Gemini/local;
- receipt com token budget antes/depois.

## Riscos

- Compilador virar prompt template fragil.
- Compressao remover nuance critica.
- Provider profile ficar desatualizado.
- Otimizar para token e piorar qualidade.
- Output contract conflitar com policy superior.

## Exemplos

Para Claude, ACCR pode favorecer blocos narrativos estruturados. Para GPT, pode
favorecer contratos objetivos e criterio de aceite. Para Gemini, pode manter
contexto maior, mas com anchors e ordering forte. A decisao fica em receipt.

## Proximas Acoes

1. Enforcar ACCR antes de provider calls.
2. Conectar ACCR ao ATER e ACPFR.
3. Integrar compiler read-only em Atlas Dev e Forge.
4. Expandir fixtures de prompt bruto vs compilado.
5. Manter must-keep coverage = 1.0.
