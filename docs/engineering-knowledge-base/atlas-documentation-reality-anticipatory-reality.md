---
id: atlas-documentation-reality-anticipatory-reality
type: engineering_knowledge
title: Atlas Documentation Reality Anticipatory Reality
status: active
category: documentation-governance
priority: 99
summary: P1 do salto gerativo do ADRS — a Realidade Antecipatoria. Promove o Software Twin (ASTR) de espelho reativo para um simulador PRE-WRITE que preve o resultado imune de um artefato PROPOSTO antes de ele ser escrito (duplicacao, drift, dono, blast radius). Caso-chave: prever um servico/doc DUPLICADO antes da escrita, para a IA decidir com previsao.
human_name: Realidade Antecipatoria
human_summary: O ADRS de hoje so avisa no commit, DEPOIS que a IA ja escreveu. Este bloco preve o erro ANTES da escrita: dado um doc ou simbolo proposto, ele diz "isto vai duplicar X, mentir sobre Y, nao tem dono Z" — para a IA nem chegar a escrever lixo.
human_what: Primeiro multiplicador do salto gerativo — o simulador pre-write do ADRS, evoluido do Software Twin existente.
human_purpose: Mover o sistema imune de reativo (checa no write) para preditivo (decide antes do write), cortando o custo de fazer certo na origem.
human_input: Recebe uma PROPOSTA (kind doc|symbol, graph_id, owner, capabilities, governs, implementation_state, evidence_refs, symbol_name) e o corpus/indice ja existentes.
human_output: Entrega um veredito previsto (clean | would_duplicate | would_drift | needs_owner_review) com colisoes, drift previsto, dono e blast radius — read-only, sem autorizar a escrita.
human_change_when: Mexa quando o ASTR, a verdade de implementacao (AAEOS) ou o grafo de autoridade evoluirem, ou quando P2/P3 forem promovidos e precisarem consumir a previsao.
human_block_when: Bloqueie se alguem tentar fazer o simulador AUTORIZAR a escrita ou virar segunda fonte canonica; ele preve, nao decide mudanca destrutiva — a escrita real segue os gates e o Evidence Ledger.
canonical_name: Atlas Documentation Reality Anticipatory Reality
technical_name: AtlasSoftwareTwinRuntimeService
cartography_type: contract
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - predictive
  - software-twin
  - antifragile
capabilities:
  - predictive_change_simulation
  - pre_write_duplicate_prediction
  - pre_write_drift_prediction
  - pre_write_owner_resolution
decisions:
  - P1 EVOLUI o Software Twin (ASTR) existente; nao cria servico paralelo — o metodo simulate() e adicionado a AtlasSoftwareTwinRuntimeService.
  - A previsao e read-only e nunca autoriza a escrita; a mudanca real segue os mesmos gates e o Evidence Ledger.
  - Reutiliza primitivos ja existentes: impact()/reachability (blast radius), driftForFrontmatter (drift previsto), authority graph locate() (dono), indice de simbolos (duplicacao de simbolo) e graph_id do frontmatter canonico (colisao de doc).
  - Veredito: duplicate ou drift => nao clean; dono ausente/ambiguo => needs_owner_review; so clean libera.
  - implementation_state e partial (simbolo + comando resolvem no indice); so vira verified quando houver teste + receipt resolvendo.
maintenance:
  - Manter abaixo de 520 linhas.
  - Revisar quando ASTR, AAEOS implementation-truth ou o grafo de autoridade mudarem.
  - Rodar docs-health, docs-authority-audit e atlas:aaeos:maturity apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
  - docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md
  - docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
  - app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-reality-anticipatory-reality
graph_title: Atlas Documentation Reality Anticipatory Reality
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-reality-generative-leap
graph_status: active
graph_source: repo
owner: documentation-governance
implementation_state: partial
depends_on:
  - atlas-documentation-reality-generative-leap
  - atlas-software-twin-verified-evolution-runtime
  - atlas-aaeos-documentation-as-law-proposal
  - atlas-documentation-reality-system
flows_to:
  - atlas-documentation-reality-generative-leap
  - atlas-software-twin-verified-evolution-runtime
unlocks:
  - anticipatory_rot_prevention
  - pre_write_decision_with_foresight
governs:
  - documentation-governance-anticipatory-reality
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-anticipatory-reality.md
  - app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php
  - app/Console/Commands/AtlasSoftwareTwinCommand.php
allowed_changes:
  - Refinar a previsao (novas dimensoes de duplicacao/drift/dono) mantendo read-only.
  - Promover para verified quando teste e receipt resolverem no indice, com drift zero.
forbidden_changes:
  - Fazer o simulador autorizar a escrita ou aplicar mutacao.
  - Criar segunda fonte canonica de verdade documental.
  - Declarar verified sem teste e receipt que resolvem no indice.
required_tests:
  - "php artisan atlas:software-twin simulate --kind=doc --graph-id=atlas-documentation-reality-system --json --strict"
  - "php artisan atlas:aaeos:maturity --capability=atlas-documentation-reality-anticipatory-reality --json"
  - "php artisan test --filter=AtlasSoftwareTwinPredictiveSimulatorTest"
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-anticipatory-reality.md
  - app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php
evidence_refs:
  - symbol: AtlasSoftwareTwinRuntimeService
  - command: atlas:software-twin
  - test: AtlasSoftwareTwinPredictiveSimulatorTest
requires_evidence: true
risk_level: high
line_limit: 520
ai_entrypoints:
  - Leia este doc para entender o P1 (preditivo) do salto gerativo do ADRS, ja com runtime parcial.
  - Use o comando simulate ANTES de escrever um doc ou servico novo, para prever duplicacao/drift/dono.
ai_usage_notes:
  - Este bloco e runtime PARCIAL — simbolo e comando resolvem no indice; ele preve, nao autoriza a escrita.
  - A previsao reutiliza os mesmos primitivos do impact()/AAEOS/grafo de autoridade; nao reinventa verdade.
next_actions:
  - Promover a verified quando teste e receipt resolverem no indice (drift zero).
  - Ligar a previsao ao enforcement write-bound para que a IA receba o veredito no fluxo, antes do write.
---

# Atlas Documentation Reality Anticipatory Reality

## Resumo

Este doc filho promove o **P1 — Anticipatory Reality** (o pilar preditivo do salto
gerativo do ADRS) de north-star para **runtime parcial**. O ADRS de hoje e
**reativo**: ele verifica no commit, *depois* que a IA ja escreveu o artefato.
P1 fecha esse atraso: dado um artefato **proposto** que ainda nao existe, ele
preve o resultado imune **antes da escrita** — para a IA decidir com previsao.

```text
hoje (reativo)    : IA escreve  -> commit  -> ADRS reprova  -> refator
P1 (preditivo)    : IA propoe   -> simulate -> veredito previsto -> nao escreve lixo
```

O caso-chave: **prever um doc/servico DUPLICADO antes de ser escrito**.

## Papel no Atlas

P1 e o **primeiro multiplicador** do salto gerativo. Ele nao cria um servico
novo: **evolui o Software Twin (ASTR)** que ja existe. O `impact()` do ASTR ja
computa risco, reachability, owner docs e blast radius para um alvo que
**existe**. P1 adiciona `simulate()`, que faz a mesma leitura para um artefato
**hipotetico** que **ainda nao existe** — reutilizando os mesmos primitivos, sem
nenhuma segunda fonte de verdade.

## Onde Se Encaixa

```text
atlas-documentation-reality-generative-leap (P1/P2/P3, north-star)
  -> ESTE doc (P1 com runtime parcial)
     simulate() vive em AtlasSoftwareTwinRuntimeService (ASTR)
     reutiliza:
       impact()/reachability ............ blast radius do alvo que a proposta estende
       driftForFrontmatter (AAEOS) ...... drift previsto do implementation_state proposto
       authority graph locate() ......... dono previsto da capability/governs
       indice de simbolos ............... duplicacao de simbolo proposto
       graph_id do frontmatter canonico . colisao de id de doc proposto
```

## Contratos

| Campo | Valor |
|---|---|
| Nome canonico | Atlas Documentation Reality Anticipatory Reality |
| Pilar | P1 Anticipatory Reality (preditivo) |
| Evolui de | Software Twin / ASTR (`AtlasSoftwareTwinRuntimeService`) |
| Estado | runtime parcial (simbolo + comando resolvem no indice) |
| Doc-mae | atlas-documentation-reality-generative-leap |
| Politica | read-only; preve, nao autoriza a escrita |

Entrada (`$proposed`):

```text
{ kind: doc|symbol, slug?, graph_id?, owner?, capabilities?[], governs?[],
  implementation_state?, evidence_refs?, symbol_name?, extends? }
```

Saida (envelope hash `atlas.software_twin.predictive.v1`):

| Campo previsto | Significado |
|---|---|
| would_duplicate | doc: colisao de graph_id + overlap de capability/governs; symbol: nome igual/boundary no indice |
| would_drift | doc que declara partial/verified com evidence_refs vazio/irresolvivel => drift=true |
| owner | dono previsto via grafo de autoridade (quem governa) |
| blast_radius | se a proposta estende um alvo existente, reusa o impact(); senao vazio |
| verdict | clean \| would_duplicate \| would_drift \| needs_owner_review |

Regra de autoridade (herdada do ADRS, inalterada): repo docs canonicos
> codigo/testes > Evidence Ledger > read models > cartografia > chat.

## Fluxo

```text
IA propoe um doc/symbol
-> simulate($proposed)
   -> graph_id colide com doc canonico existente?        -> would_duplicate
   -> capability/governs ja tem dono diferente?           -> would_duplicate
   -> symbol_name ja existe no indice?                    -> would_duplicate
   -> implementation_state over-claim (drift previsto)?   -> would_drift
   -> dono ausente/ambiguo no grafo de autoridade?        -> needs_owner_review
   -> nada disso                                          -> clean
-> IA recebe o veredito ANTES de escrever; decide com previsao
```

## Regras para IA

- NUNCA tratar a previsao como autorizacao: `simulate()` e read-only; a escrita real segue os gates e o Evidence Ledger.
- NUNCA criar servico paralelo ao ASTR; P1 e um metodo do ASTR.
- NUNCA declarar verified sem teste e receipt que resolvem no indice; partial e o estado honesto enquanto so simbolo+comando resolvem.
- Usar `atlas:software-twin simulate ... --strict` retorna nao-zero quando o veredito nao e clean, para travar fluxo automatizado.

## Escopo de Implementacao

Runtime entregue por este doc:

- `AtlasSoftwareTwinRuntimeService::simulate(array $proposed): array` — o simulador pre-write.
- Acao `simulate` no comando `atlas:software-twin` (flags discretas ou `--json-input`).
- Teste `AtlasSoftwareTwinPredictiveSimulatorTest` cobrindo os casos deterministicos (colisao, drift, clean, symbol).

O ASTR continua **read-only** (`claim_policy.read_only = true`); nenhuma mutacao
e autorizada por este bloco.

## Dependencias

- atlas-documentation-reality-generative-leap (doc-mae do salto, north-star).
- atlas-software-twin-verified-evolution-runtime (semente do P1, o ASTR).
- atlas-aaeos-documentation-as-law-proposal (verdade de implementacao / drift).
- atlas-documentation-reality-system (doc-mae do ADRS, categoria imune).

## Evidencias

A evidencia resolve no indice de codigo: o simbolo
`AtlasSoftwareTwinRuntimeService`, o comando `atlas:software-twin` e o teste
`AtlasSoftwareTwinPredictiveSimulatorTest`. O estado declarado e **partial**
porque simbolo e comando resolvem; a promocao a verified exige teste e receipt
resolvendo, com drift zero no `atlas:aaeos:maturity`.

## Riscos

- **Previsao virar autorizacao:** mitigacao — `simulate()` e read-only e so preve; a escrita passa pelos gates.
- **Segunda fonte canonica:** mitigacao — toda verdade continua materializada dos docs canonicos e do indice; o simulador apenas le.
- **Falso clean por indice stale:** mitigacao — re-indexar (`index-code`) antes de confiar; a duplicacao de doc le o corpus em disco, nao so o read model.
- **Over-claim do proprio doc:** mitigacao — `implementation_state` parcial e enforcado pelo gate de drift do AAEOS.

## Exemplos

```text
# prever um DUPLICADO (reusa um graph_id canonico existente) -> would_duplicate, exit 1
php artisan atlas:software-twin simulate --kind=doc \
  --graph-id=atlas-documentation-reality-system --json --strict

# prever uma proposta LIMPA (graph_id fresco + dono que resolve) -> clean, exit 0
php artisan atlas:software-twin simulate --kind=doc \
  --graph-id=atlas-nova-capability-fresca --owner=documentation-governance \
  --implementation-state=spec --json --strict
```

## Proximas Acoes

- Promover a verified quando teste e receipt resolverem no indice (drift zero).
- Ligar a previsao ao enforcement write-bound para a IA receber o veredito no proprio fluxo, antes do write.
