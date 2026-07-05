---
id: atlas-sovereign-acceptance-gate-obra
type: obra_spec
title: "Obra #1 — Sovereign AcceptanceGate + Kill Fake-Green"
doc_schema: atlas_canonical_module_doc.v1
status: active
implementation_state: runtime_available
priority: 1000
category: engineering-delivery-floor
owner: atlas-ai
canonical_source: docs/engineering-knowledge-base/atlas-sovereign-acceptance-gate-obra.md
tags:
  - atlas-ai
  - engineering-kernel
  - acceptance-gate
  - fake-green
capabilities:
  - sovereign_acceptance_gate
  - honesty_floor
  - delivery_certification
decisions:
  - AcceptanceGate e o ponto soberano de certificacao de entrega.
  - Honesty floor nao pode ser afrouxado por config.
  - Dev, Forge e Autonomos devem compartilhar o mesmo bar de invariantes.
maintenance:
  - Atualizar quando EngineeringKernel, AcceptanceGate, honesty floor ou consumers de certificacao mudarem.
  - Nao tratar esta spec como runtime entregue sem codigo, testes e receipts.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/EngineeringKernel
  - app/Services/Ai/RealExecution
  - app/Services/Ai/Programming/AtlasDev
  - docs/engineering-knowledge-base/atlas-spec-adversary-obra.md
summary: >
  Substituir a certificação fragmentada (~83-90 *CertificationService) e o kernel
  fake-green por UM AcceptanceGate soberano com honesty floor não-sobrescrevível,
  por onde Dev, Forge e Autonomos passam FISICAMENTE. Objetivo: o Atlas parar de
  certificar CONFIANÇA e passar a certificar CORREÇÃO. É a pré-condição de todas
  as obras seguintes do fluxo canônico de entrega.
human_summary: >
  Hoje o Atlas consegue "parecer forte sem ser": existe um kernel que sela green
  rodando só `php -l`, e não existe um portão único de aceitação. Esta obra mata
  o falso-green e constrói o portão soberano.
depends_on:
  - atlas-agentic-engineering-os-runtime-gap-matrix
unlocks:
  - atlas-spec-adversary-obra
  - non-functional-gates (security/perf/data/architecture)
  - compound-and-rivals-recognition
requires_evidence: true
risk_level: high
graph_id: atlas-sovereign-acceptance-gate-obra
graph_title: Sovereign Acceptance Gate Obra
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-agentic-engineering-os-runtime-gap-matrix
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-sovereign-acceptance-gate-obra.md
  - app/Services/Ai/EngineeringKernel
allowed_changes:
  - Refinar contrato, slices e evidencias da Obra #1.
  - Atualizar paths quando AcceptanceGate ou honesty floor mudarem.
forbidden_changes:
  - Declarar runtime entregue sem codigo, testes verdes e receipts.
  - Rebaixar o honesty floor ou criar bypass de certificacao.
flows_to:
  - atlas-spec-adversary-obra
  - engineering-kernel-delivery-certification
governs:
  - acceptance-gate
  - engineering-delivery-certification
evidence:
  - docs/engineering-knowledge-base/atlas-sovereign-acceptance-gate-obra.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:documentation:enforce --task="manter doc canonica da Obra #1 sovereign acceptance gate" --feature="sovereign acceptance gate" --strict --json
next_actions:
  - Runtime entregue em 2026-07-04 (b36244406c); manter gate e floor sob os testes de tests/Unit/Ai/EngineeringKernel.
  - Manter esta spec alinhada com AcceptanceGate, SpecAdversary e EngineeringKernel.
---

# Obra #1 — Sovereign `AcceptanceGate` + Kill Fake-Green

> Contrato inviolável que esta obra instala: **ninguém entrega antes de `CERTIFY`, e `CERTIFY` passa a ser único e REAL.** Enquanto o fake-green existir, Dev/Forge/Autonomos podem parecer fortes sem serem — e o Atlas não pode aceitar isso.

## Resumo

Spec canônica da Obra #1: substituir certificação fragmentada e fake-green por
um `AcceptanceGate` soberano no `EngineeringKernel`, com honesty floor que nao
pode ser afrouxado por config.

## Papel no Atlas

Define o bar de aceitação de entrega para Dev, Forge e Autonomos. Runtime
entregue em 04/07/2026 (b36244406c: `AcceptanceGate`, `SovereignHonestyFloor`,
adapter AtlasDev + testes; adapters Forge/Autonomos em 17aa692c0e/3dee8f4a4f).
Correção continua exigindo código, testes e receipts — nunca só este doc.

## Onde Se Encaixa

Filho da runtime gap matrix AAEOS e predecessor da Obra #2 (`SpecAdversary`).
O gate sela fidelidade da implementação contra critérios congelados.

## Contratos

- Um caminho de entrega só promove depois de `AcceptanceGate.certify()`.
- O honesty floor aplica `max(piso_soberano, config)`.
- Trust level muda witness-set, nunca invariantes.

## Fluxo

Bundle de entrega entra no `AcceptanceGate`, passa pelo `SovereignHonestyFloor`,
compõe adapters reais de Dev/Forge/Autonomos e emite `CertVerdict` com receipt.

## Regras para IA

- Nao declarar a Obra entregue a partir deste doc.
- Nao recriar gates paralelos ao `EngineeringKernel`.
- Nao aceitar `php -l` ou smoke fixo como suíte de impacto.

## Escopo de Implementacao

Criar contrato, floor soberano, adapters, wiring dos consumers, corte de
segurança e aposentadoria do stub fake-green, em slices pequenos. Entregue em
04/07/2026 (b36244406c); o caminho `php -l` remanescente é rotulado honesto e
o gate o recusa (`false_claim_blocked`).

## Dependencias

- Runtime gap matrix AAEOS.
- `EngineeringKernel`.
- Maquina real de certificação AtlasDev/Forge.

## Evidencias

- Este doc.
- Testes dos slices da Obra #1 em `tests/Unit/Ai/EngineeringKernel/` (entregues em b36244406c, 04/07/2026).
- Receipts do `AcceptanceGate` e prova de zero consumer do stub fake-green.

## Riscos

- Reintroduzir certificação proxy.
- Deletar consumers antes de migrar por strangler.
- Rodar testes com DB vivo; manter wiper-safe.

## Exemplos

Um bundle cujo único "teste" é `php -l` deve retornar `refuse` com blocker
`false_claim_blocked`.

## Proximas Acoes

- Slices entregues em 04/07/2026 (b36244406c), incluindo dogfood (`ObraDogfoodTest`).
- Manter gate, floor e adapters sob os testes existentes; não recriar gates paralelos.

## 0. Por que esta obra é a #1 (inegociável)

Três análises independentes (20-agentes + painel 5-lentes + Codex) convergiram no mesmo veredito, com os mesmos `file:line`: o design do fluxo de entrega está certo, mas a implementação o **refuta no ponto mais perigoso — a certificação**. Todas as outras obras (spec-adversary, gates não-funcionais, compound, reconhecimento mundial) certificam através deste portão. Se o portão é falso, tudo a jusante é teatro. Logo: **portão soberano primeiro.**

## 1. Estado verificado (a doença)

| Fato | Evidência |
|---|---|
| **Kernel fake-green** | `app/Services/Ai/RealExecution/AtlasRealEngineeringExecutionKernelService.php`: `executePatch()` (l.109) escreve arquivo smoke fixo `runtime/atlas_real_execution_smoke.php`; `runImpactedTests()` (l.159) roda só `php -l`; (l.199) reporta `"focused impact suite passed"`; `certify()` só checa que linhas de DB com status-string existem, nunca que algo real foi construído/testado. |
| **Sem `AcceptanceGate` soberano** | Zero classe central; certificação fragmentada em ~83-90 `*CertificationService`. `bar(dev)=bar(forge)=bar(autonomos)` é slogan que o código contradiz. |
| **Honesty floor banguela** | `mutation_adequacy_gate.enabled=true`, MAS `mutation_kill_ratio_floor=0.0` (config/atlas.php:2680) + `max_mutants=1` (l.3294) → passa byte-identical. Comentário do certifier admite "0.0 => OFF => byte-identical" (`AtlasLoopSemanticImplementationCertifier.php:163`). Ligado e sem dente. |
| **Security scan solto** | `EngineeringQualityScanService.php:212` planeja gitleaks/semgrep/osv/trivy — mas só CLI/controller, nunca gate obrigatório da certificação. (Exatamente o que pega o vazamento .env + o wiper.) |
| **Spec heurística** | `IntentActionExtractor.php:59` = tabela fixa de verbos; `SpecComposer.php:91` depende dela. (Conserto é a Obra #2 spec-adversary — fora do escopo desta.) |

**Consumers do stub (a serem migrados, não órfanados):** `AtlasEngineeringDeliverCommand`, `AtlasAiRealEngineeringKernelCommand`, `AtlasLiveCodeDeliveryService`, `AtlasRealEngineeringCompanyRuntimeService`.

**Máquina REAL que já existe (a ser promovida, não reescrita):** `app/Services/Ai/Programming/AtlasDev/Mutation` (9), `.../Regression` (8), `.../Differential/Shadow` (14/10), `.../Pipeline/SpecComposer`, `AtlasProgrammingFinalCertificationService`, `AgentMergeReviewCertificationService`, `EngineeringQualityScanService`. Modelos `AtlasAcceptanceCriteria`, `AtlasSpecTraceability`.

## 2. Objetivo e não-objetivos

**Objetivo (uma frase):** um único `AcceptanceGate.certify(bundle, trust_level)` soberano, com honesty floor não-sobrescrevível-pra-baixo, por onde Dev/Forge/Autonomos passam fisicamente, e que **rejeita** evidência fake-green.

**Não-objetivos (disciplina de escopo — cada um é uma obra futura):**
- ❌ Spec-adversary / conserto do oráculo falível (Obra #2).
- ❌ Gates de performance, dados/migração, arquitetura como novos detectores (Obra #3+ — mas o floor JÁ deixa os *slots* prontos).
- ❌ POST-MERGE re-certify, OBSERVE, COMPOUND, Rivals-bridge (obras posteriores).
- ❌ Reescrever a máquina real do AtlasDev — esta obra a **promove por adapter**, não a recria.

## 3. Arquitetura alvo

Nova casa: `app/Services/Ai/EngineeringKernel/` (hoje 5 interfaces: `ProviderPort`, `BudgetMeter`, `ReceiptLedger`, `WorkcellExecutor`, `MergeActuator` — **falta a 6ª**).

```
                         ┌───────────────────────────────────────────┐
  Dev  ─┐                │  AcceptanceGate (interface, soberano)     │
  Forge ─┼──> certify(bundle, trust_level) ─────────────────────────┐│
  Auton ─┘                │  = SovereignHonestyFloor (não-overridable)││
                          │    ∧ AtlasDevGateAdapter (máquina real)   ││
                          └───────────────────────────────────────────┘
   trust_level só troca a WITNESS-SET (humano ↔ FrozenJudge clean-checkout);
   os INVARIANTES + o floor são IDÊNTICOS pros três.
```

### 3.1 O contrato do portão

```php
// app/Services/Ai/EngineeringKernel/AcceptanceGate.php
interface AcceptanceGate
{
    /** Sela promote SOMENTE se TODOS os invariantes + o honesty floor passarem. */
    public function certify(AcceptanceBundle $bundle, TrustLevel $trust): CertVerdict;
}
```
- `AcceptanceBundle`: spec + `AtlasAcceptanceCriteria` + diff + evidências de execução REAIS (test runs, mutation report, regression, security scan) + criteria_hash.
- `TrustLevel`: enum `{dev, forge, autonomos}` — parametriza **só a witness-set**, nunca os invariantes.
- `CertVerdict`: `{promote|hold|refuse|review}` + `blockers[]` + `receipt_ref`. Fail-closed.

### 3.2 O honesty floor — regra de ouro: **config só APERTA, nunca afrouxa**

`SovereignHonestyFloor` aplica conjuntos sempre-ligados, idênticos pros três trust_levels. Cada threshold é `max(piso_soberano, config)` — o operador pode subir, **nunca descer**:

| Invariante | Regra |
|---|---|
| `false_claim_blocked` | nenhum claim de "passou" sem evidência de execução REAL (rejeita `php -l`-como-suíte, smoke fixo). |
| `context_sufficiency` | re-provado DENTRO do gate, não confiado do upstream. |
| `mutation_kill_ratio` | `max(0.6, config)` sobre a superfície de decisão ADICIONADA (não byte-identical). `max_mutants` idem — piso soberano ignora o `1`. |
| `changed_public_symbol_census` | todo símbolo público novo/alterado tem critério + teste que o exercita. |
| `security_free` | `EngineeringQualityScanService` como corte duro: `secret_free ∧ no_critical_sast ∧ no_critical_cve`. |
| `criteria_hash == frozen_hash` | a suíte certificada é a congelada; drift = refuse. |
| `judge_diversity` | ≥2 famílias de provider distintas entre juízes que aprovaram (anti-captura). |

Slots reservados (Obra #3+), default `pass` até o detector existir, para não travar hoje: `performance_budget`, `migration_safety`, `architecture_no_regression`, `property_clean_for_tagged`.

### 3.3 A witness-set por trust_level (o único que varia)

| trust_level | quem admite o bundle |
|---|---|
| `dev` | humano testemunha SÓ a desambiguação; os invariantes de engenharia são máquina-provados; sign-off leve (recibo oferecido, não exigido). |
| `forge` | selo de planejamento humano (fato selado no bundle) + 1 agente revisor re-checa os invariantes. |
| `autonomos` | **FrozenJudge re-aplica o diff em checkout LIMPO** e re-roda a suíte congelada que não autorou; author≠judge. Estritamente ≥ um olho humano. |

`bar(dev)=bar(forge)=bar(autonomos)` no nível dos invariantes — provado por teste (Slice 5).

## 4. Slices (ordenados, cada um verde + prova própria)

> Padrão strangler: **adapter primeiro → cutover → delete**. Nada é órfanado. Do Slice 3 em diante a obra se **dogfooda** (o próprio portão certifica a obra).

**Slice 0 — Contrato puro (zero comportamento).**
Cria `AcceptanceGate` (interface), `TrustLevel` (enum), `AcceptanceBundle`, `CertVerdict` em `EngineeringKernel/`.
*Prova:* teste unitário do enum/DTO; `php -l` limpo; zero consumer ainda.

**Slice 1 — `SovereignHonestyFloor` não-sobrescrevível.**
Implementa os invariantes sempre-ligados com `max(piso, config)`.
*Prova:* teste que seta `mutation_kill_ratio_floor=0.0` no config e **assere que o piso efetivo continua ≥0.6** (config só aperta).

**Slice 2 — Teste anti-fake-green (o dogfood do veredito).**
Alimenta o gate com um bundle cuja "impact suite" é a saída de `php -l` / o smoke fixo.
*Prova:* `assertSame('refuse', $verdict->status)` com blocker `false_claim_blocked`. Este teste é a prova viva de que a doença está curada.

**Slice 3 — `AtlasDevGateAdapter` (promove a máquina real).**
Compõe `AtlasProgrammingFinalCertificationService` + `Mutation` + `Regression` + `Differential/Shadow` + `AgentMergeReviewCertificationService` atrás da interface.
*Prova:* teste de regressão — o caminho green ATUAL do Dev continua certificando, agora via o portão soberano.

**Slice 4 — Segurança como corte duro.**
Liga `EngineeringQualityScanService` como conjunto obrigatório do floor.
*Prova:* bundle com secret plantado → `refuse` (blocker `security_free`).

**Slice 5 — Rotear Forge + Autonomos pelo MESMO gate.**
`AtlasLiveCodeDeliveryService`, `AtlasRealEngineeringCompanyRuntimeService` e o caminho de cert do Forge passam a chamar `AcceptanceGate.certify()`.
*Prova:* **teste de bar-equality** — o MESMO bundle certifica idêntico nos três trust_levels, exceto a witness-set.

**Slice 6 — Aposentar o stub.**
Remove `certify()`/`executePatch()`/`runImpactedTests()` fake de `AtlasRealEngineeringExecutionKernelService`; migra os 4 consumers pro adapter real.
*Prova:* `rg` mostra zero consumer de runtime do path fake; teste assere que o path antigo não emite green.

**Slice 7 — Recibo selado.**
Recibo registra witness-set + versão do floor + hashes; `ReceiptLedger`.
*Prova:* teste de schema do recibo + provenance auditável.

## 5. Critérios de aceitação da OBRA (meta)

A obra só é `done` quando TODOS provados por teste:
1. Nenhum `certify()` no codebase emite green a partir de `php -l`-como-suíte (Slice 2).
2. Dev/Forge/Autonomos chamam **um** `AcceptanceGate.certify()` (Slice 5, bar-equality).
3. O honesty floor é não-sobrescrevível-pra-baixo (Slice 1).
4. Security scan é conjunto de corte duro (Slice 4).
5. O stub fake-green está aposentado, zero consumer de runtime (Slice 6).
6. **Dogfood:** a própria obra é certificada pelo portão que ela constrói (do Slice 3 em diante).

## 6. Riscos + mitigações

- **Cicatriz do wiper:** nenhum teste desta obra pode rodar `RefreshDatabase` no pgsql vivo; usar o disco/DB de teste isolado. (ref. incidente wiper 15/06→02/07.)
- **4 consumers do stub são runtime, não só CLI:** migrar por strangler (adapter → cutover → delete), nunca deletar antes de migrar.
- **Colisão com o worker codex na árvore:** trabalhar em branch; aditivo-primeiro; anunciar no blackboard antes de tocar `EngineeringKernel/`, `RealExecution/`, `Programming/`.
- **Não quebrar o caminho green atual do Dev:** Slice 3 tem regressão obrigatória antes de qualquer cutover.
- **Bootstrap do dogfood:** Slices 0-2 usam phpunit puro; o portão só se auto-certifica a partir do Slice 3.

## 7. Como saber que ficou perfeita

O portão é perfeito quando: (a) rejeita a própria doença que motivou a obra (fake-green), (b) os três surfaces provam o mesmo bar por teste, (c) o floor não pode ser afrouxado por config, e (d) a obra passou pelo portão que construiu. Aí — e só aí — `bar(dev)=bar(forge)=bar(autonomos)` deixa de ser slogan e vira fato de código, e o Atlas passa a certificar **correção**, não confiança.
