# Obra #5 — Certifier Sovereignty & Consolidation

**Data da spec:** 2026-07-05
**Status:** FREEZE concedido pelo operador ("cria a spec completa da obra 5 e implemente você mesmo", 05/07)
**Risk band:** MÉDIO-ALTO (toca os 3 juízes de entrega vivos; não toca o EngineeringKernel em si — só ADAPTERS novos no padrão existente)
**Antecedentes:** Obras #1 (AcceptanceGate soberano), #2 (spec-adversary), #3 (probes não-funcionais), #4 (self-hardening: floor v3/13 invariantes, RegressionLock, RepairBrain, OutcomeFlywheel)

## 1. Tese

**"Todo veredito de ENTREGA passa pelo juiz soberano; todo relatório de ESTADO usa um motor só."**

A meta original do F4 da limpeza ("81 certifiers → <20 adapters do gate") assumia que os 80
`*CertificationService` eram mini-juízes concorrentes do AcceptanceGate. A classificação um-a-um
(05/07, agente + verificação por amostra) provou outra coisa:

| Categoria | Nº | O que são | Destino nesta obra |
|---|---|---|---|
| **A_DELIVERY** | 3 | Juízes de ENTREGA reais (veredito bloqueia merge/escalação/execução): `AtlasObraCertificationService`, `ForgeObraCertificationService`, `AtlasVerifiedExecutionCertificationService` | **S1**: rotear pelo AcceptanceGate via adapter (only-adds: gate só pode APERTAR o veredito local) |
| **B_STATE** | 35 | Auditores de estado/readiness/doc-realidade (`certify() → checks[]`, ninguém bloqueia em cima) | **S2**: motor único de state-certification; classes viram definições finas de checks |
| **C_PARKED** | 23 | Série AreaFocusLoop L7-L10 + stewardship (decisão #1 do operador pendente) | FORA da obra |
| **D_ISOLADO** | 19 | Control-plane dry-run read-only + Vox gates — já isolados por construção | FORA da obra (documentados) |

Forçar os 35 B_STATE no AcceptanceGate seria erro de categoria (auditor ≠ juiz) — exatamente o
tipo de unificação cega que a memória `eng-kernel-unification-map` veta. A soberania exigida é
outra: **nenhum veredito que BLOQUEIA entrega pode viver fora do piso soberano** (hoje: 3 vivem).

## 2. Fatos verificados (com paths)

- 80 `*CertificationService`, **38.097 linhas**; ZERO deles consome `AcceptanceGate`/`SovereignHonestyFloor` diretamente (2 têm menção indireta).
- Padrão adapter existente: `EngineeringKernel/Adapters/AtlasDevGateAdapter.php` (169L), `AtlasForgeGateAdapter` (93L), `AtlasAutonomosGateAdapter` (72L) — `certify(AcceptanceBundle, TrustLevel): CertVerdict` + `certifyXDelivery(array $evidence)`.
- Floor v3 pós-Obra #4: 13 invariantes, waive-se-não-toca / fail-closed-se-toca.
- Os 3 A_DELIVERY: `Obra/AtlasObraCertificationService` (caller: AtlasObraExecutor; `certified=false` bloqueia merge), `Programming/Forge/Qa/ForgeObraCertificationService` (`status=blocked` bloqueia escalação), `VerifiedExecution/AtlasVerifiedExecutionCertificationService` (caller: AtlasAverCertifyCommand; P0 fail bloqueia execução).
- B_STATE têm assinatura homogênea `certify(options?) → {status, checks[], summary}` — engine comum é extraível; scaffold parcial já existe (`Support/CertificationScaffoldHelpers`, criado na limpeza F3-f).

## 3. Não-objetivos (o adversary cobra)

- **NÃO** rotear B_STATE pelo AcceptanceGate (auditor ≠ juiz; veto de unificação cega).
- **NÃO** tocar `EngineeringKernel/` core (floor/gate) — só adapters novos no diretório de adapters, padrão aditivo.
- **NÃO** tocar a série C_PARKED (decisão #1 do operador).
- **NÃO** mudar contrato público dos 3 juízes (callers continuam chamando o mesmo método; o gate entra POR DENTRO).
- **NÃO** enfraquecer veredito: o gate soberano só pode transformar pass→blocked, nunca blocked→pass (only-adds).

## 4. Slices

### S0 — Ledger de classificação (pequeno, enabling)
`EngineeringKernel/Adapters/CertifierClassificationLedger` (const array A/B/C/D por FQCN) + teste
que FALHA se um `*CertificationService` novo aparecer sem classificação (anti-drift: a esteira
cria certifiers novos toda semana — cada um nasce classificado ou o teste quebra).
**AC-0.1:** ledger cobre 100% dos `*CertificationService` existentes (teste conta via glob).
**AC-0.2:** certifier novo não-classificado = teste vermelho com mensagem acionável.

### S1 — Os 3 juízes de entrega sob o piso soberano
Para cada A_DELIVERY, um adapter fino em `EngineeringKernel/Adapters/` (padrão AtlasDevGateAdapter):
monta `AcceptanceBundle` da evidência que o certifier JÁ tem (nada fabricado; ausente = ausente,
o floor waiva ou recusa por invariante nomeado) e chama `certify()` com o TrustLevel da superfície.
No certifier: passo final `sovereignVerdict()` — se o gate REFUSE, o veredito local vira
blocked/failed com blocker `sovereign_floor:<invariante>` (only-adds). Config por certifier
`atlas.engineering_kernel.certifier_gate_mode` = off|observe|enforce, **default observe**
(CORREÇÃO pós-leitura do floor: context_sufficiency tem piso duro 80 e judge_diversity exige
≥2 famílias, sem waiver — enforce por default bloquearia entrega legítima cuja esteira ainda
não treda essa evidência, violando AC-1.2. Observe grava o veredito soberano no envelope como
raio-X do gap; enforce liga quando a esteira tredar — mesma postura do automerge na Obra #4).
**AC-1.1:** entrega com evidência falsa (tests_run=0 + claim pass) que o certifier local aceitaria → BLOCKED pelo floor (teste por juiz).
**AC-1.2:** entrega legítima verde → veredito local INALTERADO byte a byte (teste por juiz).
**AC-1.3:** modo observe grava o veredito soberano no receipt sem alterar o local.
**AC-1.4:** throw do gate = fail-closed (blocked), nunca fail-open.

### S2 — Motor único de state-certification (B_STATE)
`Support/StateCertificationEngine`: roda uma lista de checks (callables/definições), agrega
status (passed/degraded/blocked), monta summary — o esqueleto que hoje está copiado ~35×.
Migração **incremental por pilotos** nesta obra: ContextIntelligence, ConversationOps (já usam o
scaffold F3-f), + Aemor, AgenticWorkcell, PersistentContext (5 pilotos). Os 30 restantes viram
**tasks semeadas na esteira** (uma por certifier, com o gate F0 + RefactorProofGate vigiando) —
a spec desta migração é o próprio padrão dos pilotos.
**AC-2.1:** cada piloto migrado emite relatório byte-compatível (mesmas chaves; teste snapshot por piloto).
**AC-2.2:** motor tem teste próprio (status rollup: all-pass/partial/none).
**AC-2.3:** linhas dos 5 pilotos caem ≥25% no agregado (medido, não declarado).

### S3 — Métricas honestas da meta "81→<20"
A meta re-expressa com régua real (a original assumia categoria errada):
- vereditos de ENTREGA fora do piso soberano: **3 → 0** (S1)
- motores de state-certification: **~35 → 1** (S2 + esteira)
- classes B_STATE viram definições finas; contagem de CLASSES cai conforme a esteira migra
  (meta de classes <20 só fecha quando C_PARKED for decidido + esteira concluir — reportar
  progresso, nunca declarar).

## 5. Verificação da obra
- Testes por AC nomeado; wiper-safe (SQLite :memory:).
- Suites existentes dos 3 juízes + dos 5 pilotos byte-iguais ao baseline nos casos verdes.
- Commit por slice na main, pathspec explícito (lição da limpeza), push só com OK.
- Placar PT-BR por slice.

## 6. Passe de spec-adversary

| Risco | Mitigação |
|---|---|
| Gate soberano REFUSA entregas legítimas dos juízes (evidência incompleta) | Bundle montado só do que existe; floor waiva dimensão ausente (semântica Obra #3); AC-1.2 prova pass-inalterado; modo observe disponível para rollback operacional |
| Erro de categoria (auditor no gate) | Classificação S0 é código + teste; B_STATE explicitamente fora do gate |
| Quebra de caller dos 3 juízes | Contrato público intocado; gate entra por dentro; AC-1.2 |
| Motor S2 achata semântica específica de área | Pilotos com snapshot byte-compatível; check-definitions ficam NA classe da área |
| Esteira migra B_STATE com regressão | Cada task herda o padrão-piloto + RefactorProofGate exige delta + F0 impede duplicação nova |
| Drift futuro (certifier novo fora da lei) | AC-0.2: teste de cobertura do ledger quebra |
