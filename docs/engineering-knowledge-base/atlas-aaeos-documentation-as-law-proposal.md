---
id: atlas-aaeos-documentation-as-law-proposal
type: engineering_knowledge
title: AAEOS Documentation-as-Law Proposal — navigable, machine-verified doc governance
doc_schema: atlas_canonical_module_doc.v1
status: planned
implementation_state: proposal_no_runtime
authority_class: proposal
category: agentic-engineering
priority: 99
summary: Proposta canonica (aprovada pelo operador, ainda nao implementada) para elevar a documentacao AAEOS a Lei navegavel e verificada por maquina. Inverte a posse do implementation_state (deixa de ser auto-declarado em prosa e passa a ser computado do indice de codigo via atlas:aaeos:maturity), da a qualquer IA um owner-doc resolver deterministico (atlas:docs:locate) e converte o docs-health de vermelho-perpetuo em trava verde-alcancavel via baseline freeze + ratchet. As referencias abaixo foram verificadas como dependencias/targets de analise, nao como runtime entregue. Nao e runtime; e o plano que a implementacao seguira.
owner: operator (Vitor)
risk_level: medium
tags:
  - atlas-ai
  - aaeos
  - documentation-governance
  - doc-as-law
  - implementation-truth
  - docs-health
capabilities:
  - doc_as_law_proposal
  - machine_verified_implementation_state
  - owner_doc_resolver
  - docs_health_baseline_ratchet
decisions:
  - Um doc nao DECLARA implementation_state; ele REIVINDICA evidence_refs e o estado e COMPUTADO do indice de codigo.
  - docs-health deixa de ser vermelho-global; vira verde-alcancavel que so pune regressao (nova violacao blocking) e over-claim.
  - Cada novo ativo deve aposentar um fardo antigo ou rodar dentro de um pipeline existente; nada de infraestrutura solta.
  - O caminho de menor resistencia (escrever mais doc) nunca pode subir um estado; so shipar codigo/teste/merge sobe.
maintenance:
  - Atualizar a ordem de implementacao apenas quando um passo mudar de estado real (medido, nao declarado).
  - Manter os nomes de servico/arquivo/comando alinhados aos arquivos load-bearing verificados.
  - Esta proposta vira contract ratificado quando o Passo 1 (R5-freeze) tiver codigo + teste + merge honesto.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-implementation-reality.md
  - docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md
  - docs/engineering-knowledge-base/atlas-aaeos-l7-convergence-roadmap.md
graph_id: atlas-aaeos-documentation-as-law-proposal
graph_title: AAEOS Documentation-as-Law Proposal
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-ai-knowledge-governance-system
graph_status: planned
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
allowed_changes:
  - Atualizar estado real de cada passo quando o codigo/teste/merge mudar.
  - Refinar schemas (implementation_state.v1, capability_truth_ledger.v1) antes de ratificar.
forbidden_changes:
  - Do NOT treat proposal rows as delivered runtime.
  - Do NOT mark a step done without code, test, evidence and honest merge.
  - Do NOT add a new schema/command/gate without retiring an old burden or running inside an existing pipeline.
  - Do NOT use the words Jarvis, Rivals, benchmark, superiority, concurrent in this doc.
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-ai-documentation-operating-system
  - atlas-aaeos-department-maturity-matrix
  - atlas-agentic-engineering-os-implementation-reality
flows_to:
  - atlas-aaeos-l7-convergence-roadmap
unlocks:
  - machine_verified_doc_law
  - any_ai_navigable_documentation
governs:
  - aaeos.documentation_as_law_plan
evidence:
  - docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
analysis_refs:
  - symbol: EngineeringDocumentationHealthService
  - symbol: AtlasAaeosImplementationTruthService
  - command: atlas:aaeos:maturity
  - test: AtlasAaeosImplementationTruthServiceTest
  - receipt: docs/engineering-knowledge-base/.governance/docs-health-baseline.json
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
next_actions:
  - Implementar o Passo 1 (R5 baseline freeze + ratchet) antes de qualquer outro mecanismo.
---
# AAEOS Documentation-as-Law Proposal

## Resumo

Plano canonico para fazer a documentacao AAEOS virar **Lei navegavel e verificada por maquina**: qualquer IA (engine intercambiavel — Claude, Codex, Gemini), sem memoria previa do Atlas, descobre em **um comando deterministico** onde algo mora e onde algo novo deve nascer; **nunca confia em prosa** para saber o que esta implementado vs. spec, porque o estado e **computado do indice de codigo** (90.018 simbolos / 155.832 doc-links ja materializados), nao auto-declarado; e **nao consegue mentir sobre conclusao**, porque o linter de docs deixa de ser vermelho-perpetuo global e vira trava verde-alcancavel que so pune **regressao** e **over-claim**.

Resultado: a documentacao para de ser o caminho de menor resistencia para uma IA "parecer produtiva" e vira o trilho que **forca runtime verificado**. Origem: workflow multi-agente (13 agentes) com os 3 seams load-bearing **re-verificados direto no codigo** desta sessao.

## Papel no Atlas

Esta proposta **define o plano e os contratos-alvo** (schemas, comandos, gates) para a governanca documental; ela **nao executa** e nao sobrescreve as fontes canonicas. `implementation_state: proposal_no_runtime` ate o Passo 1 ter codigo + teste + merge honesto. A regra-mae vale aqui tambem: *claim de pronto sem evidence e falso completo*.

## Onde Se Encaixa

```text
atlas-ai-knowledge-governance-system            (autoridade de governanca de conhecimento)
  +-- atlas-aaeos-documentation-as-law-proposal  (este doc: o plano)
        +-- atlas:aaeos:maturity     (a ESPINHA: implementation_state computado)
        +-- atlas:docs:locate        (R1/R2: owner-doc resolver)
        +-- docs-health baseline+ratchet (R5: Lei verde-alcancavel)
  flows_to -> atlas-aaeos-l7-convergence-roadmap (a Fase 0 do L7 ja pedia o instrumento de maturidade)
```

## Contratos

### Os 5 requisitos do operador -> os 5 mecanismos (pos-critica)

| Req | A IA precisa | Mecanismo | Artefato | Comando / Gate |
|---|---|---|---|---|
| **R1** | Saber onde tudo esta | Owner-Doc Resolver (read-model sobre 3 tabelas existentes) | tabela `atlas_docs_authority_graph` | `atlas:docs:locate <needle>` (advisory; `--strict`) |
| **R2** | Onde coisa nova vai | Dobrado no R1: regra `--strict` "novo `*os*.md` precisa de parent" | (sem tabela nova) | `atlas:docs:locate --strict` |
| **R4** | Implementado vs. nao (verdade de maquina) | **`implementation_state` computado do indice — a ESPINHA** | enum 3-tier + `evidence_refs[]` + ledger JSON | `atlas:aaeos:maturity` + gate "verified sem prova = block" |
| **R3** | Nunca errar por falta de contexto | Adiado ate R4 existir (check `depends_on_state` reusa R4) | (reusa frontmatter R4) | (futuro) |
| **R5** | Lei que nao vira ruido | Baseline freeze + ratchet de severidade + projecao auto-heal | `.governance/docs-health-baseline.json` | `docs-health-baseline --freeze` + `docs-health --enforce` |

### A espinha unica

**`atlas:aaeos:maturity`** — o ledger que computa `implementation_state` do indice de codigo. Ataca a patologia-raiz: hoje a IA **escreve a matriz de maturidade E a le para decidir o que construir** (corrige a propria prova, sem instrumento — `atlas:aaeos:maturity` e `atlas:aaeos:quality-bar` **nao existem**, confirmado). Invertendo a posse — o doc nao *declara* estado, ele *reivindica* `evidence_refs` e o comando **computa** o tier resolvendo cada ref — a unica forma de subir um estado e **fazer um ref resolver** (shipar codigo/teste/merge). R1 diz *onde* algo mora; **R4 diz se aquilo e real**.

### Schemas-alvo

- **`atlas.aaeos.implementation_state.v1`** (enum fechado 3-tier): `spec` (rank 0, nada exigido) -> `partial` (rank 1, >=1 simbolo + (rota OU comando) resolvem) -> `verified` (rank 2, acima + >=1 teste verde + merge-receipt `merge_performed=true`).
- **`atlas.aaeos.capability_truth_ledger.v1`** (emitido como JSON, sem tabela nova): `{capability_id, owner_doc, claimed_state, computed_state, drift: bool, unmet_evidence[], proof_refs_resolved[]}`.
- **`atlas_docs_authority_graph`** (tabela, v1 enxuto): `needle_kind, needle, owner_doc_path, owner_basis ∈ {governs_frontmatter, doclink_density, module_owner, keyword_fallback}, confidence, owner_implementation_state`.

### Criterio de "virou Lei" (tudo verdade de maquina)

1. `docs-health = green` ou `debt_holding`, e **blocking**: `blocking_count=0` e `legacy_debt` so decresce (`monotonic_decrease_only` nunca quebrado).
2. **0 drift declarado-vs-computado**: `atlas:aaeos:maturity --json` retorna `drift:false` em todo doc.
3. Todo claim `verified` tem >=1 proof ref que resolve (simbolo + teste verde + merge-receipt). Zero `verified` fantasma.
4. `atlas:docs:locate <topic>` resolve em **1 passo** com confidence alta; `place-feature`/`bootstrap` consomem o resolver; `$map` hardcoded deletado.
5. **0 orfaos** entre os novos (regra `--strict` nunca bypassada num merge).
6. Projecao sem drift: simbolos no CLAUDE.md batem com o indice vivo (8.700-vs-90.018 impossivel por construcao).
7. Criterio-mestre: **merge-truth (`main_before != main_after`) e a unica prova de "pronto"** — e agora todo gate o exige.

## Fluxo

A ordem reflete a convergencia dos dois criticos: **R5-freeze -> R1 -> R4**, com R3/de-sprawl atras de necessidade provada.

```text
R5 baseline freeze (verde-alcancavel)        <- desbloqueia: sem isso o gate do R4 herda o vermelho e e ignorado
   -> R1 atlas:docs:locate (deleta o $map)   <- carrega owner_implementation_state -> semeia o R4
      -> R4 atlas:aaeos:maturity (a espinha)  <- computa estado; gate bloqueia over-claim; entra no loop runner
         -> R2 = a flag --strict do R1
         -> R3 (depends_on_state) + de-sprawl  <- so depois que R4 provar valor
```

## Regras para IA

1. **Toda trava e verde por construcao, nunca vermelho-global.** Pune-se **mentir que algo roda**, nunca admitir que nao roda. `spec` honesto com zero evidencia e sempre permitido.
2. **Nenhum mecanismo exige authoring em massa.** R1 deriva do indice; R4 `evidence_refs` e opt-in (computa `spec` quando ausente); R3 (que exigiria authoring em 897 docs) foi adiado por isso.
3. **Cada novo ativo aposenta um fardo ou roda dentro de um pipeline existente.** R1 deleta o `$map` + scan O(repo); R5 roda no `docs-health`/`index-code`; R4 no gate-runner + loop.
4. **Teatro (de-sprawl, `required_context`) fica por ultimo, atras de prova.**
5. **O loop flagship recebe os gates reais**: o gate R4 entra no `Reliable24hLoopRunnerService` (hoje cego a docs-health) — fecha o gap, nao o alarga.
6. Esta proposta **ordena e contrata**; as fontes canonicas e a verdade computada **mandam**.

## Escopo de Implementacao

### Passo 1 — R5 Baseline Freeze + Ratchet (LOW effort, zero deps)
- **Artefato:** `docs/engineering-knowledge-base/.governance/docs-health-baseline.json` — snapshot das violacoes atuais (114 canonical_module + 8 oversized + 9 frontmatter) congeladas como `legacy_debt`, `"ratchet":"monotonic_decrease_only"`.
- **Codigo:** em `EngineeringDocumentationHealthService.php`, trocar `status => $violations === [] ? 'ok' : 'failed'` (~linha 406) por severidade 3-tier `{blocking, legacy_debt, warning}`: uma violacao e `legacy_debt` **sse** o par `(path, message)` existe no lockfile; senao `blocking`. Novo `status: green | debt_holding | failed`.
- **Comandos:** `atlas:engineering:docs-health-baseline --freeze` (one-shot, commitado) + flag `--enforce` (exit≠0 so quando `blocking>0` OU `legacy_debt` cresce).
- **Gate (assimetrico):** `ProgrammingDocsHealthGate` consome o novo `status` e bloqueia so regressao.
- **Wiring de graca:** anexar `AtlasMemoryProjectionCommand --write` ao pos-passo do `index-code` (mata o drift de simbolos); `.husky/pre-commit` (hoje vazio) roda `docs-health --enforce` nos `*.md` staged.

### Passo 2 — R1 `atlas:docs:locate` (LOW-MED; unica peca que aposenta divida)
- **Construcao:** `AtlasDocsAuthorityGraphService::build()` roda **dentro do `index-code --prune`**, join-and-upsert sobre as 3 tabelas ja populadas (`atlas_engineering_doc_links`, `atlas_engineering_code_symbols`, `atlas_engineering_code_modules`) + frontmatter (`governs`/`capabilities`). Precedencia: `governs`(100) > densidade doc->link > module owner > keyword(≤40).
- **Integracao que DELETA divida:** `AtlasFeaturePlacementService::ownerDocs()` (L348) e `duplicateCandidates()` (L431) — o `$map` hardcoded + scan O(repo) — passam a delegar para `locate()`.
- **Gate:** advisory; `keyword_fallback` sempre retorna best-effort -> `unmapped` impossivel. **R2** = `--strict` (diff que adiciona `*os*.md` sem parent -> exit≠0).

### Passo 3 — R4 `atlas:aaeos:maturity` (MED; a ESPINHA)
- **Comando:** `atlas:aaeos:maturity --json [--capability=X] [--strict]`, backed por `AtlasAaeosImplementationTruthService` que **delega ao `EngineeringCodeIntelligenceService` existente** (`symbols()`, `module()->doc_links`, `contextRefs()`) + readers de teste/merge. Reusa o padrao never-fabricate do `MetricLedgerService` (check nao-rodado nunca e pass; ref ausente -> tier mais baixo). Deleta o `const DEPARTMENTS` circular do `AtlasAaeosDepartmentMaturityService`.
- **Frontmatter:** `implementation_state` (o CLAIM) + `evidence_refs[]` (`{kind: symbol|route|command|test|receipt, ref}`). **Sem write-back** — o computed vive no ledger JSON.
- **Gate (uma regra):** `drift = rank(claim) > rank(computed)`. Over-claim = **block**; under-claim = warning. Registrado na governanca **e adicionado ao gate-set do `Reliable24hLoopRunnerService`**.

### Passo 4 / 5 — R2 (ja entregue como `--strict`); R3 + de-sprawl (so apos R4 provar valor)

**Net (vs. proposta ingenua de 5 schemas / 8 comandos / 5 gates):** **2 comandos + 1 lockfile + 1 resolver + 2 regras de gate + 2 wirings** entregam ~80%. Colapso de ~60%.

## Dependencias

- **Code Intelligence ja populado:** 23 modulos / 90.018 simbolos / 155.832 doc-links / 17.898 testes (via `index-code --workspace "$(pwd)"`). R1 e R4 fazem join sobre isso.
- **Servicos existentes reaproveitados:** `EngineeringCodeIntelligenceService`, `MetricLedgerService` (never-fabricate), `AtlasFeaturePlacementService`, `ProgrammingDocsHealthGate`, `AtlasMemoryProjectionCommand`.
- **Loop runner:** `Reliable24hLoopRunnerService` (recebe o gate R4).
- **Governanca-mae:** `atlas-ai-knowledge-governance-system`, `atlas-ai-documentation-operating-system`.

## Evidencias

Seams load-bearing **verificados direto na fonte nesta sessao**:
- `app/Services/Engineering/EngineeringDocumentationHealthService.php` — `status => $violations === [] ? 'ok' : 'failed'` (~L406): a raiz binaria do vermelho-perpetuo.
- `app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php` — `ownerDocs()` (L348) + `duplicateCandidates()` (L431): o `$map` hardcoded + scan O(repo) a redirecionar.
- `app/Services/Engineering/EngineeringCodeIntelligenceService.php` — API resolver do R4.
- `database/migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php` — as 3 tabelas ja persistidas.
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/MetricLedgerService.php` — padrao never-fabricate (R4) e o ratchet (R5).
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php` — gate-set do loop **sem docs-health hoje** (confirmado).
- `php artisan atlas:engineering:knowledge docs-health --json` — taxonomia de violacoes (114 canonical / 8 oversized / 9 frontmatter / 10 warnings).
- Confirmado ausente: comando `atlas:aaeos:maturity`.

## Riscos

- **Reproduzir o vermelho-perpetuo:** se algum gate punir divida congelada em vez de so regressao, a IA volta a ignorar gates. Mitigacao: ratchet assimetrico (so pune `blocking` novo e crescimento de `legacy_debt`).
- **Gerar mais spec que a IA Tier-0 nao shipa:** o failure-mode central. Mitigacao: nenhum mecanismo exige authoring em massa; teatro (de-sprawl, `required_context`) fica por ultimo.
- **Instrumento ausente tratado como verdade:** check nao-rodado **nunca** e pass (never-fabricate); ref ausente computa `spec`, nunca assume `verified`.
- **Drift de schema vs runtime:** sem write-back no frontmatter; o computed vive no ledger, evitando merge-conflict.

## Exemplos

**Antes (hoje):** um doc declara `implementation_state: runtime_verified` em prosa; nenhum gate checa; a IA le isso e constroi em cima de uma capability que nao existe.

**Depois (R4):** o doc reivindica `evidence_refs: [{kind: command, ref: atlas:aaeos:maturity}, {kind: test, ref: AtlasAaeosImplementationTruthServiceTest}]`. `atlas:aaeos:maturity --capability=X` resolve cada ref contra o indice: comando existe? teste verde? merge-receipt? Se um nao resolve, `computed_state=partial`, `drift=true` (claim `verified` > computed `partial`) -> **gate bloqueia**. A unica forma de o doc ficar `verified` e o ref resolver — isto e, shipar o codigo/teste/merge.

## Proximas Acoes

- **Passo 1 (R5 baseline freeze + ratchet + projecao auto-regen)** — LOW effort, zero deps, desbloqueador. Construir antes de tudo: senao o gate do R4 herda o vermelho-perpetuo.
- Depois R1 (`atlas:docs:locate` + deletar o `$map`) -> R4 (`atlas:aaeos:maturity` + gate) -> R2 (ja como `--strict`) -> R3/de-sprawl.
- Antes de codar: rodar `atlas:ai:session-bootstrap` e `atlas:ai:place-feature` para confirmar placement dos 2 comandos novos e do artefato `.governance/`.
