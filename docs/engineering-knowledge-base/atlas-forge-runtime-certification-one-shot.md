---
id: atlas-forge-runtime-certification-one-shot
type: engineering_knowledge
title: Atlas Forge Runtime Certification One Shot
status: active
category: programming-forge
priority: 100
summary: Meta grande, prompt one-shot e protocolo de revisao para certificar o runtime real do Atlas Forge sem falso positivo de maturidade.
tags:
  - atlas
  - forge
  - programming
  - certification
capabilities:
  - forge_runtime_certification
  - atlas_code_forge_only
  - obras_forge_workspace_binding
  - evidence_based_review
decisions:
  - O Forge Runtime Real v1 so pode ser certificado por evidencia ponta-a-ponta, nao por documentacao isolada.
  - Atlas Code SCOR-1 entra sempre por Obra e Forge Workspace antes de `programming.forge`.
  - Rivals externo pode permanecer blocked sem impedir uma certificacao separada do Forge core, se o relatorio separar os eixos honestamente.
maintenance:
  - Atualize este prompt quando o fluxo canonico do Forge, Atlas Code SCOR-1, Obras, Harness, Evidence ou Completion Audit mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
  - docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-runtime-certification-one-shot
graph_title: Atlas Forge Runtime Certification One Shot
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-programming-forge-flow
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md
allowed_changes:
  - Ajustar metas, gates e prompt quando houver novo runtime ou nova evidencia.
forbidden_changes:
  - Enfraquecer gates para declarar Forge completo.
  - Misturar score Rivals externo com certificacao local do Forge core.
depends_on:
  - atlas-programming-forge-flow
  - atlas-desktop-code-surface
flows_to:
  - atlas-forge-operating-system
unlocks:
  - forge-runtime-real-v1-certification
  - atlas-code-forge-e2e-review
governs:
  - forge-runtime-certification
evidence:
  - docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - forge
  - certification
ai_entrypoints:
  - Use este documento para executar ou revisar a certificacao one-shot do Forge Runtime Real v1.
ai_usage_notes:
  - Nao declare certified se qualquer gate obrigatorio estiver vermelho ou sem evidencia.
quality_gates:
  - docs-health
  - architecture-validate
  - programming-completion-audit
  - atlas-code-e2e
failure_modes:
  - Declarar runtime completo com docs verdes, mas sem E2E real.
  - Remover blockers de auditoria para produzir status verde.
observability_signals:
  - forge_core_status
  - external_rivals_status
  - e2e_command
  - evidence_paths
next_actions:
  - Executar o prompt em Claude e trazer diff, outputs e evidencias para revisao.
---
# Atlas Forge Runtime Certification One Shot

## Resumo

Certificar o **Atlas Forge Runtime Real v1** com evidencia executavel. O fluxo
alvo e:

## Papel no Atlas

Este doc e o pacote de handoff para uma IA executar a meta grande e para Codex
revisar depois. Ele nao certifica o Forge sozinho; ele define o que precisa ser
feito, testado e provado.

## Onde Se Encaixa

Ele fica abaixo de `atlas-programming-forge-flow.md` e acima dos comandos de
auditoria. Usa Atlas Code SCOR-1, Obras, Forge Workspace, Kernel, Programming
Domain, Harness, Evidence Ledger e Cartografia como fronteiras de verificacao.

## Contratos

- Nao declarar Forge completo sem E2E replayable.
- Nao enfraquecer gates para produzir status verde.
- Separar Forge core de Rivals externo quando custo/aprovacao externa bloquear.
- Toda maturidade declarada precisa de teste, comando ou evidence path.

## Fluxo

```text
Atlas Code -> Obra -> Obras Shared Workspace -> Forge Workspace
-> programming.forge -> Kernel Pipeline -> Atlas Decide / Decision Receipt
-> Agentic RAG / Semantic Code Graph / Context Pack
-> Programming Governance -> Forge Intake / Packets / Dependency DAG
-> Tool Runtime Gateway -> Engineering Harness Runner
-> Tests / Gates / Score -> Repair Loop
-> Evidence Ledger -> Learning -> Code Intelligence Refresh
-> Docs / Cartography -> Output
```

## Regras para IA

Use o prompt abaixo sem apagar blockers honestos. Se um item nao puder ser
certificado, reporte como `blocked` com causa e evidence path.

## Escopo de Implementacao

Permitido: testes, comandos de auditoria, docs canonicas, binding Obra/Forge,
relatorio E2E e isolamento honesto de blockers. Proibido: mocks, fake evidence,
fake score, remocao de gates e mudancas destrutivas.

## Dependencias

- `atlas-programming-forge-flow.md`
- `atlas-desktop-code-surface.md`
- `atlas-code-scor-1-implementation-contract.md`
- `obras/shared-workspace-and-forge.md`
- `atlas:programming:completion-audit`
- `atlas:ai:architecture-validate`
- `atlas:engineering:knowledge docs-health`

## Evidencias

Evidencias aceitas: diff, testes PHPUnit, build/lint Desktop, auditorias JSON,
Evidence Ledger refs, context pack/retrieval receipt, command output e paths de
artefatos replayable.

## Riscos

- Confundir doc completa com runtime completo.
- Misturar Forge core com benchmark Rivals externo.
- Aceitar `source_id` sem `obra_id` como contrato canonico.
- Declarar certified quando o resultado correto ainda e blocked.

## Prompt One-Shot Para Claude

```text
Voce esta no repo Atlas. Sua missao e completar e certificar o Atlas Forge
Runtime Real v1.

Contexto critico:
- O fluxo canonico vive em docs/engineering-knowledge-base/atlas-programming-forge-flow.md.
- Atlas Code e surface desktop; Atlas Code SCOR-1 e Forge-only.
- Programming Domain e o setor; programming.forge e o flow pesado.
- Obra e unidade produtiva obrigatoria.
- Obras Shared Workspace e workspace persistente da Obra.
- Forge Workspace e especializacao do Obras Shared Workspace para programming.forge.
- Engineering Harness Runner e executor, nao o Forge inteiro.

Objetivo:
Provar o caminho Atlas Code -> Obra -> Forge Workspace -> programming.forge
-> Kernel -> Decide/Receipt -> Agentic RAG/Code Graph -> Governance
-> Forge Intake/Packets/DAG -> Harness/Tools -> Repair -> Evidence
-> Learning/Cartography.

Regras absolutas:
1. Nao criar mock, fake evidence, fake gates, fake Obra ou fake runtime.
2. Nao remover bloqueios reais so para deixar auditoria verde.
3. Nao enfraquecer completion-audit, docs-health ou architecture validation.
4. Separar forge_runtime_certification de external_rivals_certification.
5. Nao gastar benchmark externo pago sem aprovacao explicita.
6. Nao usar git reset, checkout destrutivo ou apagar mudancas do usuario.
7. Toda claim de maturidade precisa de teste, comando ou evidence path.

Primeiro rode:
- git status --short
- php artisan atlas:programming:completion-audit --json
- php artisan atlas:ai:architecture-validate --json
- php artisan atlas:engineering:knowledge docs-health --json
- npm run lint --workspace=@atlas/desktop
- npm run build --workspace=@atlas/desktop

Implemente o minimo necessario para:
1. Atlas Code/Forge falhar fechado ou degradar explicitamente sem Obra.
2. Payload Forge carregar requires_obra=true, obra_id e forge_workspace.obra_id.
3. Criar comando ou teste E2E replayable provando:
   Atlas Code interaction -> Obra binding -> atlas_code/programming.forge
   -> Atlas Decide -> Decision Receipt -> task_profile/signals com obra_id
   -> context pack ou fallback declarado -> governance/harness route ou blocker
   -> evidence refs ou evidence blocker.
4. Corrigir docs canonicas sem baguncar taxonomia.
5. Corrigir docs-health corretamente.
6. Corrigir lint do Desktop se bloquear certificacao da surface.
7. Ajustar completion-audit para nao misturar Forge core com Rivals externo.

Comandos finais minimos:
- php artisan test --filter AtlasCodeContractTest
- php artisan test --filter AiAtlasDecideContractTest
- php artisan test --filter SurfaceAdaptersTest
- php artisan test --filter DomainCatalogSurfaceSelectionServiceTest
- php artisan test --filter KernelPipelinePlanGuardTest
- php artisan test --filter SurfaceDomainCatalogInteractionApiTest
- php artisan atlas:engineering:knowledge docs-health --json
- php artisan atlas:ai:architecture-validate --json
- php artisan atlas:programming:completion-audit --json
- npm run build --workspace=@atlas/desktop
- npm run lint --workspace=@atlas/desktop
- git diff --check

Entrega final:
Explique em portugues:
1. O que foi implementado.
2. Arquivos alterados.
3. Comandos que passaram.
4. Comandos que ainda falham e por que.
5. Se Forge Runtime Real v1 pode ser certified ou segue blocked.
6. Nao diga completo se qualquer gate obrigatorio estiver vermelho.
```

## Checklist De Revisao

| Requisito | Evidencia exigida |
|---|---|
| Atlas Code Forge-only | diff e teste `atlas_code` -> `programming.forge` |
| Obra obrigatoria | `requires_obra`, `obra_id`, `forge_workspace.obra_id` |
| Forge Workspace correto | especializacao do Obras Shared Workspace |
| Kernel/Decide/Receipt | JSON com receipt e `obra_id` |
| Agentic RAG/Code Graph | context pack ou blocker explicito |
| Governance/Harness | rota real, gates ou blocker honesto |
| Repair Loop | failure packet/repair decision ou nao acionamento justificado |
| Evidence Ledger | refs reais ou evidence blocker |
| Docs canonicas | docs-health verde ou falha isolada |
| Runtime E2E | comando replayable ponta-a-ponta |
| Desktop | build verde; lint verde ou divida isolada |
| Completion audit | sem fake score ou fake completion |
| Architecture validate | kernel/capabilities/static scan verdes |

## Matriz Prompt Para Artefato

| Pedido explicito | Artefato esperado | Aceite |
|---|---|---|
| Meta grande de certificacao | Este doc e relatorio final do Claude | Objetivo, fluxo alvo, gates e status final declarados |
| Prompt one-shot para Claude | Secao `Prompt One-Shot Para Claude` | Pode ser executado sem depender do chat |
| Atlas Code Forge-only | Diff em surface/bridge/backend e testes | `atlas_code` aceita somente `programming.forge` |
| Obra no Forge | Payload, controller, Decide e testes | `obra_id` aparece em payload, task profile e signals |
| Forge Workspace | Docs e payload runtime | `forge_workspace.obra_id` aponta para a Obra |
| E2E replayable | Comando/teste novo ou existente | Saida JSON prova caminho ou blocker explicito |
| Docs canonicas | Docs alterados e docs-health | Sem violacao nova; falhas antigas isoladas |
| Desktop build/lint | Outputs npm | Build verde; lint verde ou divida nao Forge isolada |
| Architecture validate | Output JSON | Kernel, surfaces, capabilities e scans verdes |
| Completion audit | Output JSON | Nao declara complete com Rivals externo invalido |
| Revisao Codex | Checklist preenchido | Cada item tem evidencia real ou status blocked |

## Pacote Para Revisao

Quando Claude terminar, a resposta precisa trazer:

- `git status --short`;
- `git diff --stat`;
- lista de arquivos alterados;
- outputs dos comandos finais;
- paths de relatorios/evidencias criados;
- decisao final `forge_core_status`;
- decisao final `external_rivals_status`;
- blockers remanescentes com owner e proximo comando.

## Template De Resposta Do Claude

```text
forge_runtime_certification_report:
  forge_core_status: passed|blocked
  external_rivals_status: passed|blocked|requires_operator_approval
  certified: true|false
  certification_reason: "..."

changed_files:
  - path: "..."
    reason: "..."

commands:
  - command: "php artisan test --filter AtlasCodeContractTest"
    status: passed|failed|not_run
    evidence: "resumo ou path"
  - command: "php artisan atlas:engineering:knowledge docs-health --json"
    status: passed|failed|not_run
    evidence: "resumo ou path"
  - command: "php artisan atlas:ai:architecture-validate --json"
    status: passed|failed|not_run
    evidence: "resumo ou path"
  - command: "php artisan atlas:programming:completion-audit --json"
    status: passed|failed|not_run
    evidence: "resumo ou path"
  - command: "npm run build --workspace=@atlas/desktop"
    status: passed|failed|not_run
    evidence: "resumo ou path"
  - command: "npm run lint --workspace=@atlas/desktop"
    status: passed|failed|not_run
    evidence: "resumo ou path"

e2e:
  command: "..."
  status: passed|blocked|not_run
  proves:
    - "Atlas Code -> Obra"
    - "Obra -> Forge Workspace"
    - "programming.forge -> Decision Receipt"
    - "context/evidence path"

remaining_blockers:
  - blocker: "..."
    scope: forge_core|external_rivals|docs|desktop|unknown
    owner: "..."
    next_command: "..."
```

## Exemplos

Exemplo de conclusao valida: `forge_core_status=passed` e
`external_rivals_status=blocked_requires_operator_approval`, com comandos e
evidencias separados.

Exemplo de conclusao invalida: `certified=true` enquanto docs-health,
completion-audit, E2E ou evidence refs seguem vermelhos.

## Comando Canonico Implementado

O comando `atlas:forge:runtime-certify` materializa este protocolo:

```bash
php artisan atlas:forge:runtime-certify --json
php artisan atlas:forge:runtime-certify --obra=<uuid> --json
php artisan atlas:forge:runtime-certify --obra=<uuid> --strict
```

Schema canonico: `atlas.forge_runtime_certification.v1`.
Service: `app/Services/Ai/Programming/AtlasForgeRuntimeCertificationService.php`.
Testes: `tests/Feature/Ai/Programming/AtlasForgeRuntimeCertificationTest.php`.

O bloco `forge_runtime_certification` aparece tambem em
`atlas:programming:completion-audit --json`, isolado de
`external_rivals_certification`. Sessoes futuras devem usar este comando como
fonte de `forge_core_status` para o Forge Runtime Real v1.

## Proximas Acoes

1. Rodar `atlas:forge:runtime-certify --obra=<uuid> --json` ou executar o prompt em Claude.
2. Trazer `git diff --stat`, `git status --short`, outputs finais e evidencias.
3. Codex revisa contra o checklist e so aceita certified se todos os gates
   obrigatorios estiverem cobertos.
