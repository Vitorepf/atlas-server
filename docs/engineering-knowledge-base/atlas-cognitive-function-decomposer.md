---
id: atlas-cognitive-function-decomposer
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Cognitive Function Decomposer
slug: atlas-cognitive-function-decomposer
status: building
implementation_state: runtime_available_heuristic_decomposer
category: cognition
priority: 94
summary: Decompositor deterministico que transforma pedido humano em vetor canonico de seis funcoes cognitivas para roteadores, sem chamar provider externo e sem virar router paralelo.
tags: [atlas-ai, acos, cognition, patamar-4, routing-signal]
capabilities: [cognitive_function_decomposition, six_axis_cognitive_vector, deterministic_request_profile, provider_safe_routing_signal]
decisions:
  - ACFD fornece vetor cognitivo para roteadores; nao decide fluxo sozinho.
  - Cognitive Function Atlas continua sendo read-model de saude, nao decompositor de pedidos.
  - O decompositor e heuristico, deterministico e provider-safe; ajustes de rules exigem teste.
maintenance:
  - Atualizar rules, vieses e consumers somente com evidencia em service e testes.
  - Manter CLI e doc alinhados ao schema atlas.cognitive_function.decomposition.v1.
risk_level: medium
owner: atlas-ai
graph_id: atlas-cognitive-function-decomposer
graph_title: Atlas Cognitive Function Decomposer
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-cognition-operating-system
graph_status: building
graph_source: repo
depends_on:
  - atlas-cognition-operating-system
  - atlas-cognitive-function-atlas
authority_class: implementation_contract
related_paths:
  - docs/engineering-knowledge-base/atlas-cognitive-function-decomposer.md
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - docs/engineering-knowledge-base/atlas-cognitive-function-atlas.md
  - app/Services/Ai/Cognition/AtlasCognitiveFunctionDecomposerService.php
  - app/Console/Commands/AtlasCognitiveFunctionDecomposeCommand.php
  - tests/Unit/Ai/Cognition/AtlasCognitiveFunctionDecomposerServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-cognitive-function-decomposer.md
  - app/Services/Ai/Cognition/AtlasCognitiveFunctionDecomposerService.php
  - app/Console/Commands/AtlasCognitiveFunctionDecomposeCommand.php
flows_to: [atlas-ai-mission-mode-integration, atlas-cognitive-function-atlas, atlas-cognition-operating-system]
unlocks: [cognitive_vector_routing_signal, mission_mode_request_profile, programming_governance_request_profile]
governs: [cognitive_function_decomposition]
evidence:
  - app/Services/Ai/Cognition/AtlasCognitiveFunctionDecomposerService.php
  - app/Console/Commands/AtlasCognitiveFunctionDecomposeCommand.php
  - tests/Unit/Ai/Cognition/AtlasCognitiveFunctionDecomposerServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/Cognition/AtlasCognitiveFunctionDecomposerServiceTest.php"
  - "php artisan atlas:cognitive-function:decompose \"refatore o controller\" --role=engineer --framework=programming --json"
next_actions:
  - Adicionar teste de feature para o comando CLI se o contrato de superficie evoluir.
  - Conectar consumidores somente como leitura do vetor, sem promover ACFD a router.
allowed_changes:
  - Add deterministic keyword rules with tests.
  - Add consumer read-only integrations with receipts.
forbidden_changes:
  - call_external_provider_from_decomposer
  - introduce_parallel_router_decision
  - mutate_mission_or_hyperflow_state_from_decomposer
requires_evidence: true
line_limit: 520
schema:
  - atlas.cognitive_function.decomposition.v1
---

# Atlas Cognitive Function Decomposer — Patamar 4 F5 (canon)

> **Status:** `building`
> **Group:** patamar4 / cognition
> **ACOS subsystem:** `ACFD — Cognitive Function Decomposer`
> **Authority:** docs canônicos governam implementação.
> **Claim policy:** provider-safe. Nada de benchmark, rivals, superiority, external_rivals.

## Resumo

ACFD transforma um pedido humano em vetor canonico de seis funcoes cognitivas:
`reasoning`, `retrieval`, `generation`, `code`, `vision` e `audit`.

## Papel no Atlas

`AtlasCognitiveFunctionAtlasService` é probe de **saúde** da malha cognitiva — diz quais subsistemas (G0..G8, AUCRI 18, ACIE, APCR, AEMOR etc.) existem e estão saudáveis. Ele **não decide** qual função cognitiva o pedido humano demanda. Atlas precisa, antes de roteador (Mission Mode / Hyperflow / SDD / BDD / Programming Governance), de **um vetor cognitivo** que descreva o pedido: quanto é raciocínio, quanto é recuperação, quanto é geração, quanto é código, visão ou auditoria.

**ACFD entrega esse vetor.** É puramente heurístico + rules canon + opcional override de framework/role. Determinístico para input idêntico. Append-only JSONL receipt com `decomposition_hash`.

## Onde Se Encaixa

ACFD fica abaixo dos roteadores e acima do input humano normalizado. Ele fornece sinal, nao decisao final.

## Contratos

```
decompose(input: string, context: array): array
context = { role?, framework?, privacy_class? }
return = {
  schema_version: "atlas.cognitive_function.decomposition.v1",
  weights: { reasoning, retrieval, generation, code, vision, audit }, // soma=1.0
  dominant_function: <axis>,
  debug: { hits: {...} },
  decomposition_hash: "sha256:..."
}
```

**6 funções canon (ordem fixa):**
1. `reasoning` — análise, decisão, comparação, lógica
2. `retrieval` — busca, recuperação, listagem, memória
3. `generation` — escrita, redação, síntese, narrativa
4. `code` — engenharia de software (refator, teste, build, deploy)
5. `vision` — imagem, design, layout, mockup
6. `audit` — verificação, governance, kernel, cartografia, evidence

## Fluxo

Input humano + contexto opcional -> rules deterministicas -> nudges de framework/role -> normalizacao dos pesos -> envelope com schema e hash -> append-only JSONL.

## Regras para IA

- Usar ACFD como sinal de funcao cognitiva, nao como router final.
- Nao chamar provider externo dentro do decompositor.
- Nao criar segunda taxonomia de funcoes cognitivas.
- Nao tratar `AtlasCognitiveFunctionAtlasService` como decompositor de pedidos.
- Alterar keywords ou biases somente com teste.

## Escopo de Implementacao

Service e CLI existem. O service gera envelope `atlas.cognitive_function.decomposition.v1`, calcula pesos determinísticos e registra JSONL append-only.

## Dependencias

- `AtlasCognitionScoreCardService` e ACOS como contexto de grupo cognitivo.
- Roteadores consumidores: Mission Mode, Hyperflow, SDD/BDD e Programming Governance.

## Evidencias

- `app/Services/Ai/Cognition/AtlasCognitiveFunctionDecomposerService.php`
- `app/Console/Commands/AtlasCognitiveFunctionDecomposeCommand.php`
- `tests/Unit/Ai/Cognition/AtlasCognitiveFunctionDecomposerServiceTest.php`

## Riscos

- Virar router paralelo e competir com Mission Mode/Hyperflow.
- Receber rules ad hoc sem teste e perder determinismo.
- Ser confundido com Cognitive Function Atlas, que e read-model de saude.

## Exemplos

```bash
php artisan atlas:cognitive-function:decompose "refatore o controller" \
  --role=engineer --framework=programming --json
```

## Proximas Acoes

- Criar teste feature dedicado para a CLI se a superficie crescer.
- Conectar novos consumidores apenas como leitura do vetor.
- Manter o schema `atlas.cognitive_function.decomposition.v1` estavel.

## Heurística canon

- Keyword rules `RULES = [axis => [pt, en keywords]]` (case-insensitive, accent-stripped).
- Soma de hits por axis → distribuição de probabilidade.
- `framework` no contexto adiciona viés: `cartography`→audit, `programming`→code+audit, `vision`→vision, etc.
- `role` no contexto adiciona viés: `engineer`→code, `auditor`→audit, `writer`→generation.
- Sem nenhum sinal → distribuição default lean-reasoning (`reasoning:0.5, retrieval:0.2, …`).
- Input vazio → `audit=1.0` (algo está errado, audite).

## CLI

```
php artisan atlas:cognitive-function:decompose "refatore o controller" \
  --role=engineer --framework=programming --json
```

Saída inclui barra ASCII por axis e hash determinístico.

## Invariants

1. 6 funções canon na ordem fixa.
2. Pesos em [0.0, 1.0], soma ≤ 1.0 (normalizada).
3. Determinístico — mesmo input + mesmo contexto → mesmo hash.
4. Append-only JSONL em `storage/atlas/cognition/function_decompositions.jsonl`.
5. claim_policy provider-safe.
6. Nunca chama provider externo.
7. Read-only para os roteadores que consomem.

## Consumidores

ACFD é fornecedor de **vetor cognitivo** para:
- **Mission Mode** — decide se cria mission vs single-turn.
- **Hyperflow** — calibra steps específicos.
- **SDD/BDD/Programming Governance** — quando `code+audit` domina.
- **Cognitive Function Atlas** — operador pode comparar perfil de pedidos (`recent_decompositions`) vs gaps do self-model.

ACFD **não substitui nenhum desses**. Entrega o vetor que cada um já assume como dado.

## Filtro 5 perguntas

1. **Wrapper composto?** Sim — vetor cognitivo é insumo de todos os roteadores; sem ele cada um inventa heurística própria.
2. **Antifrágil?** Sim — heurística pura, sem dependência externa; cresce quando operador estende `RULES`.
3. **Linguagem natural?** Sim — input é frase humana.
4. **Destrava função?** Sim — pré-condição para Atlas operar como produto único.
5. **Local-first?** Sim — zero rede.

## Testes canon

- `tests/Unit/Ai/Cognition/AtlasCognitiveFunctionDecomposerServiceTest.php` — 6-axis canon, dominante por keyword, framework/role nudges, determinismo, JSONL append-only, claim_policy.
- `php artisan atlas:cognitive-function:decompose "refatore o controller" --role=engineer --framework=programming --json` — CLI smoke manual ate existir teste de feature dedicado.

## Cross-references

- `AtlasCognitiveFunctionAtlasService` (probe consumer downstream).
- `AtlasMissionDetectionService`, `AtlasHyperflowEntryService` (consumers).
