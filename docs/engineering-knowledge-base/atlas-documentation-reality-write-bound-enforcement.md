---
id: atlas-documentation-reality-write-bound-enforcement
type: engineering_knowledge
title: Atlas Documentation Reality Write-Bound Enforcement Gate
status: active
category: documentation-governance
priority: 99
summary: Contrato do gate L0 write-bound do ADRS que emite um unico veredito fail-closed sobre uma mudanca proposta, ligado a fronteira de commit, sobre a superficie de docs canonicos.
human_name: Gate de Blindagem Write-Bound do ADRS
canonical_name: Atlas Documentation Reality Write-Bound Enforcement Gate
technical_name: AtlasDocumentationRealityWriteGateService
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-write-bound-enforcement.md
tags:
  - atlas-ai
  - documentation
  - write-bound
  - enforcement
  - immune-system
capabilities:
  - documentation_reality_write_bound_enforcement
  - commit_boundary_doc_gate
  - docs_health_ratchet_binding
  - over_claim_drift_binding
decisions:
  - Nome canonico/produto obrigatorio: Atlas Documentation Reality Write-Bound Enforcement Gate.
  - Acronimo tecnico obrigatorio: ADRS-WBG.
  - Nome interno de experiencia/superficie: Atlas Documentation Reality Commit Guard.
  - Runtime tecnico: AtlasDocumentationRealityWriteGateService (decider puro) + comando atlas:documentation-reality-write-gate + hook pre-commit.
  - ADRS-WBG nao reimplementa docs-health nem o ledger de verdade AAEOS; ele liga esses primitivos a fronteira de escrita.
  - Gate so bloqueia em violacao NOVA (ratchet) ou drift de over-claim em doc canonico tocado; legacy_debt nunca bloqueia.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando EngineeringDocumentationHealthService::report() ou AtlasAaeosImplementationTruthService::ledger() mudarem o contrato.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md
  - docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-reality-write-bound-enforcement
graph_title: Atlas Documentation Reality Write-Bound Enforcement Gate
graph_world: atlas
graph_layer: flow
graph_kind: contract
graph_parent: atlas-documentation-reality-system
graph_status: active
graph_source: repo
owner: documentation-governance
implementation_state: partial
repo_paths:
  - app/Services/Engineering/AtlasDocumentationRealityWriteGateService.php
  - app/Console/Commands/AtlasDocumentationRealityWriteGateCommand.php
  - scripts/hooks/pre-commit
  - scripts/install-git-hooks.sh
  - tests/Feature/Engineering/AtlasDocumentationRealityWriteGateServiceTest.php
allowed_changes:
  - Refinar regras de normalizacao de path, atribuicao de blocker e wiring do hook.
  - Adicionar saidas de telemetria sem mudar a semantica fail-closed.
forbidden_changes:
  - Bloquear a partir de legacy_debt (apenas violacao NOVA bloqueia).
  - Reimplementar docs-health ou o ledger de verdade dentro deste gate.
  - Permitir silenciosamente em erro interno (deve cair em needs_review).
depends_on:
  - atlas-documentation-reality-system
  - atlas-aaeos-documentation-as-law-proposal
flows_to:
  - atlas-ai-documentation-operating-system
unlocks:
  - commit-boundary-doc-enforcement
  - loop-write-path-doc-guard
governs:
  - documentation-governance
  - commit-gate
evidence:
  - app/Services/Engineering/AtlasDocumentationRealityWriteGateService.php
  - app/Console/Commands/AtlasDocumentationRealityWriteGateCommand.php
  - scripts/hooks/pre-commit
  - tests/Feature/Engineering/AtlasDocumentationRealityWriteGateServiceTest.php
evidence_refs:
  - symbol: AtlasDocumentationRealityWriteGateService
  - command: atlas:documentation-reality-write-gate
  - test: AtlasDocumentationRealityWriteGateServiceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - contract
  - write-bound
  - documentation-reality
ai_entrypoints:
  - Leia este doc quando precisar ligar um veredito doc a fronteira de commit ou de escrita do loop.
ai_usage_notes:
  - Use o decider puro AtlasDocumentationRealityWriteGateService::decide() para obter um unico veredito allowed|blocked|needs_review.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
failure_modes:
  - Gate bloquear em legacy_debt em vez de violacao nova.
  - Gate permitir em erro interno em vez de needs_review.
  - Path absoluto nao normalizado escapar do match de doc canonico.
observability_signals:
  - write_gate_decision
  - new_docs_health_blocker_count
  - drift_over_claim_count
  - touched_doc_count
next_actions:
  - Ligar o decider tambem na write-path do loop autonomo (mesmo veredito antes de mutacao de doc).
  - Promover implementation_state para verified quando receipt de execucao do gate for arquivado.
---
# Atlas Documentation Reality Write-Bound Enforcement Gate

## Resumo

ADRS-WBG e o gate L0 de blindagem write-bound do ADRS. O ADRS e o sistema
imunologico de uma base de codigo construida por IA, mas seus checks rodavam
read-only / pipeline-bound: eles diziam a verdade, porem nao paravam uma
mudanca ruim no momento em que ela entra.

Este contrato fecha essa lacuna. Ele emite **um unico veredito fail-closed**
sobre uma mudanca proposta, ligado a **fronteira de commit**, e somente sobre a
**superficie de docs canonicos** (`docs/engineering-knowledge-base/*.md`).

```text
Mudanca proposta -> ADRS-WBG decide -> allowed | blocked | needs_review
```

O gate **fortalece** primitivos existentes; ele **nao reimplementa**
docs-health nem o ledger de verdade AAEOS.

## Papel no Atlas

ADRS-WBG e a primeira camada da escada de blindagem (L0). Sem ele, o ADRS sabe
quando um doc canonico regrediu mas nao tem onde aplicar essa verdade: o saber
nao vira veto. Com ele, a verdade do ADRS vira um veto auditavel exatamente na
borda de escrita.

Ele protege duas coisas que tornam o ecossistema antifragil:

- O **ratchet de docs-health**: a divida documental so pode diminuir. Uma
  violacao NOVA (fora do baseline congelado) em um doc tocado bloqueia o commit;
  a divida legada congelada nunca bloqueia.
- A **honestidade de completude**: um doc canonico tocado que afirma mais do que
  o indice de Code Intelligence consegue provar (over-claim drift) bloqueia o
  commit. A IA nao pode mentir para si mesma sobre o que ja esta pronto.

## Onde Se Encaixa

```text
ADRS define a verdade documental (read models, blocos, score)
ADR-BUM eleva cada bloco ate ficar testavel
ADRS-WBG (este doc) liga a verdade a fronteira de ESCRITA  <- camada L0
Documentation OS compila regra doc em lint/gate aplicavel
```

ADRS-WBG e a camada L0 de blindagem write-bound da escada. Ele e o ponto onde o
veredito read-only do ADRS passa a ter consequencia no momento do commit.

Se houver conflito de autoridade:

1. ADRS decide a existencia e o nome do bloco e da verdade.
2. ADRS-WBG decide se a mudanca proposta pode atravessar a borda de escrita.
3. O hook pre-commit aplica o veredito (fail-closed com `--strict`).

## Contratos

### Entrada

O decider recebe um array simples:

```text
{ touched_paths: string[], is_mutating?: bool = true }
```

- `touched_paths`: caminhos tocados (absolutos ou repo-relativos). Apenas os que
  estao sob `docs/engineering-knowledge-base/` e terminam em `.md` participam.
- `is_mutating`: `false` significa leitura/readiness e sempre passa.

### Saida

```text
{
  schema_version: "atlas.documentation_reality.write_gate.v1",
  decision: "allowed" | "blocked" | "needs_review",
  is_mutating: bool,
  touched_doc_count: int,
  new_docs_health_blockers: string[],
  drift_blockers: [{ owner_doc, claimed_state, computed_state }],
  blocker: "adrs_write_bound_violation" | null,
  reason: string
}
```

### Blockers

- **new_docs_health_blockers**: strings de violacao NOVA do `report()["blocking"]`
  cujo alvo e um doc tocado. Cada string comeca pelo path repo-relativo do doc,
  entao o match e por starts-with OU contains. `legacy_debt` nunca entra aqui.
- **drift_blockers**: linhas do `ledger()["capabilities"]` cujo `owner_doc` e um
  doc tocado e cujo `drift === true` (over-claim). Under-claim nunca bloqueia.

## Fluxo

### Caminho pre-commit (borda de commit)

1. `scripts/hooks/pre-commit` faz `cd` para a raiz do repo.
2. Se `ATLAS_SKIP_ADRS_GATE=1`, sai 0 (override intencional de uma maquina so).
3. Roda `php artisan atlas:documentation-reality-write-gate --staged --strict --json`.
4. O comando resolve os paths staged via
   `git diff --cached --name-only --diff-filter=ACM` e chama o decider.
5. Decisao `blocked` com `--strict` retorna codigo 1; o hook imprime a mensagem
   clara e aborta o commit.

### Caminho write-path do loop

O mesmo decider deve ser chamado antes de uma mutacao de doc na write-path do
loop autonomo, com `is_mutating=true`, para obter o mesmo veredito fail-closed
antes de escrever. Assim o gate guarda a superficie imunologica tanto no commit
humano quanto na escrita do loop.

## Regras para IA

- Nao bloqueie a partir de `legacy_debt`; apenas violacao NOVA (ratchet) bloqueia.
- Nao reimplemente docs-health nem o ledger; consuma `report()` e `ledger()`.
- Nao permita silenciosamente quando um colaborador lanca excecao; caia em
  `needs_review` (fail-to-human).
- Nao trate uma mudanca somente de codigo como bloqueada por ESTE gate; ele
  guarda a superficie de docs canonicos, nao o repo inteiro.
- Nao mude a semantica fail-closed para reduzir ruido; ajuste apenas telemetria.

## Escopo de Implementacao

| Parte | Artefato | Estado |
|---|---|---|
| Decider puro | `AtlasDocumentationRealityWriteGateService::decide()` | runtime |
| Comando write-bound | `atlas:documentation-reality-write-gate` | runtime |
| Hook de borda | `scripts/hooks/pre-commit` | runtime |
| Instalador idempotente | `scripts/install-git-hooks.sh` | runtime |
| Teste do decider | `AtlasDocumentationRealityWriteGateServiceTest` | runtime |
| Write-path do loop | binding na write-path autonoma | proximo |

`implementation_state: partial` — o decider, o comando, o hook e o teste rodam;
o estado sobe para `verified` quando um receipt de execucao do gate for
arquivado e a write-path do loop estiver ligada.

## Dependencias

- `EngineeringDocumentationHealthService::report()` — fornece `blocking`
  (violacoes NOVAS vs baseline congelado), `legacy_debt` (congelado, ignorado) e
  `enforcement.status`.
- `AtlasAaeosImplementationTruthService::ledger()` — fornece
  `capabilities[].{owner_doc, drift, claimed_state, computed_state}`.
- Auto-discovery de comandos em `app/Console/Commands` (mesmo mecanismo de
  `atlas:documentation-reality`).

## Evidencias

- `app/Services/Engineering/AtlasDocumentationRealityWriteGateService.php`
- `app/Console/Commands/AtlasDocumentationRealityWriteGateCommand.php`
- `scripts/hooks/pre-commit`
- `scripts/install-git-hooks.sh`
- `tests/Feature/Engineering/AtlasDocumentationRealityWriteGateServiceTest.php`

Os `evidence_refs` no frontmatter resolvem contra o indice de Code Intelligence:
o simbolo da classe, o comando artisan e o teste. O estado computado nunca sobe
sozinho; ele so sobe quando uma ref resolve de verdade.

## Riscos

- **Ratchet invertido**: bloquear em `legacy_debt` congelaria todo commit. O gate
  usa apenas `blocking`.
- **Permitir em erro**: se `report()` ou `ledger()` lancam, permitir seria um
  buraco imunologico. O gate cai em `needs_review`, que e fail-closed (`--strict`
  sai diferente de zero tambem em `needs_review`, nao so em `blocked`).
- **Path nao normalizado / case**: o match e ancorado ao token-sujeito da
  violacao (`<path>:`), case-insensitive e exato — nunca substring (mensagens
  docs-health embutem o path de OUTROS docs no corpo).
- **Rename/delete invisivel**: a resolucao staged usa `--name-status -M -C` e
  inclui os dois lados de um rename e os deletes; remover um doc obrigatorio e um
  blocker novo no path antigo, e nao pode escapar.
- **TOCTOU index vs worktree**: se um doc canonico tocado tem worktree != index
  (parcialmente staged), o gate cai em `needs_review` — nao confia numa visao
  partida, pois os analisadores leem o worktree e o commit grava o index.
- **Naked over-claim**: `partial`/`verified` sem `evidence_refs` (que o ledger
  pula) e bloqueado via `compute(state, [])`.
- **Limite L0 -> L1 (honesto)**: over-claim apenas em PROSA com
  `implementation_state` ausente/`spec` (o doc grita "shipado" mas nao declara
  estado nem evidencia) NAO e pego pelo drift estrutural do L0; deteccao
  semantica/preditiva e capacidade do L1 (generative-leap), nao deste degrau.
- **Override abusivo**: `ATLAS_SKIP_ADRS_GATE=1` existe para a maquina do
  operador; o uso fica visivel no output do hook.

## Exemplos

Demo read-only sobre um doc canonico limpo (espera `allowed`):

```bash
php artisan atlas:documentation-reality-write-gate \
  --paths=docs/engineering-knowledge-base/atlas-documentation-reality-system.md --json
```

Aplicacao na borda de commit (fail-closed):

```bash
php artisan atlas:documentation-reality-write-gate --staged --strict --json
```

Override intencional de um commit:

```bash
ATLAS_SKIP_ADRS_GATE=1 git commit -m "..."
```

Mudanca somente de codigo passa este gate:

```text
touched_paths = ["app/Foo.php"] -> decision=allowed, reason=no_canonical_docs_touched
```

## Proximas Acoes

- Ligar o decider na write-path do loop autonomo (mesmo veredito antes de
  mutacao de doc).
- Arquivar um receipt de execucao do gate e promover `implementation_state` para
  `verified`.
- Expor `write_gate_decision` na telemetria de governanca para medir uso real.
