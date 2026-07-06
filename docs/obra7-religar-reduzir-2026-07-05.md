# OBRA #7 — Religar & Reduzir (Wire-or-Retire + Enforce + Consolidação Fria + Dedup de Testes)

**Data:** 2026-07-05 · **Status:** spec da campanha (referência congelada do /goal)
**Antecedentes:** limpeza-bruta (−86k), Obras #1-#6. A Obra #6 provou por medição que o núcleo NÃO está
inchado de duplicação removível — está parcialmente DESLIGADO (98 capability-slices testadas com 0-ref)
e com o freio do produtor em modo observação. Esta obra entrega o objetivo do operador — **menos linhas,
mais sólido, manutenção mais fácil** — pelos canais que a medição provou legítimos. Linhas caem como
CONSEQUÊNCIA de decisões provadas (retire com preflight, dedup byte-idêntico), nunca como alvo cego.

## Missão
(1) RELIGAR ou APOSENTAR, um a um com prova, os 98 slices testados 0-ref; (2) LIGAR o enforce do gate de
admissão v2 (com a prova de zero falso-positivo); (3) consolidar o estoque FRIO de clones byte-idênticos
do app; (4) atacar a maior veia de redução honesta que restou: 60k linhas duplicadas em tests/. Trabalho
100% completo = cada frente fechada OU declarada com prova de por que não fecha.

## Baseline medido em 05/07/2026 (re-medir ao final, mesmos params)
- Micro-classes puras de decisão: 301; **0-ref = 99 (32,9%)**; 98 com teste pareado (lista determinística
  pelo censo do wiring-gate); 1 sem teste (`VentureSuccessEvaluator`, cluster VentureFoundry/Success unwired).
- `admission_v2_mode = observe` (V0 da Obra #6, commits 059efab25e + e8f3a0e999); replay 20+19 packets = 0 FP.
- jscpd `app/Services/Ai` (min-tokens 100): **4,10%** (51.537 dup); frio extraível fora de zona quente/vetada
  ≈ 1,1k linhas em 22 pares; dup interna dos 309 doc-runtimes: 4.679 linhas.
- jscpd `tests/` (min-tokens 100): **5,13% — 60.112 linhas dup / 2.558 clones em 5.911 arquivos (1,21M linhas)**.
- Família hot `AgentAutomaticDispatchSchedulerOneShotTick*` (62 arquivos): 20 commits/3h em 05/07 — recheck de
  frieza obrigatório antes de tocar.

## Frentes (nesta ordem)

**W0 — Censo vivo (re-medir tudo acima no dia da execução).** Nada se edita com número de ontem. Gerar:
lista 0-ref atual (censo do wiring-gate), receipts do observe do admission v2 acumulados, jscpd app+tests,
coldness da família hot (commits nas últimas 6h). O censo é a work-list.

**W1 — ENFORCE do gate de admissão v2 (solidez).** Analisar TODOS os receipts `admission_gate_v2` gravados
desde o V0: se zero falso-positivo real (cada blocker auditado contra o disco), flipar
`atlas_task_governance.admission_v2_mode` observe→enforce — ESTE GOAL AUTORIZA o flip mediante a prova.
Se houver FP, consertar o gate primeiro (padrão do verify adversarial da Obra #6). Teste de regressão do
flip: entrega legítima passa, clone/unwired é recusado mantendo a lease.
**AC-W1:** enforce ligado com auditoria de FP publicada, OU relatório do FP que impediu + fix.

**W2 — Wire-or-Retire dos 98 (o coração da obra).** Por slice, decisão baseada em EVIDÊNCIA (nunca em lote):
- **WIRE** quando existe consumidor natural (a row do leap-backlog de origem diz onde ele devia plugar):
  ligar no fluxo REAL (caminho que já executa — service/command/gate existente) + teste de integração que
  prova que o consumidor USA o output (asserção de comportamento, não de existência). **Wiring fake =
  blocker**: caller sintético que só invoca por invocar é teatro e REPROVA a slice (regra pétrea 4).
- **RETIRE** quando a capability foi superseded ou o backlog de origem não a quer mais: deletion-preflight
  COMPLETO + prova de supersessão (não basta 0-ref!) + deletar classe E teste E rebaixar a row do backlog
  de origem NO MESMO commit (código e doc movem juntos). Cada retire ≈ −200 a −400 linhas HONESTAS.
- **TAG** (`@unwired-until` com prazo) só para o genuinamente indecidível — o gate W1/V0 passa a cobrar.
Trabalhar por DOMÍNIO (Aucri, Evidence, SelfImprovement, Context/Gates, WorkspaceIntelligence, Kernel/
Decision, Mission, Aaeos/Cores...), um lote por commit, suíte da área verde antes/depois.
**AC-W2:** 0-ref das micro-classes 32,9% → **≤10%**; cada wire com teste de integração real; cada retire
com preflight+supersessão+row rebaixada colados no commit; ZERO wiring fake (verify adversarial checa).

**W3 — Consolidação FRIA do app (byte-idêntico apenas).** Os ~22 pares frios (≥40 linhas) não-vetados +
a dup interna dos 309 doc-runtimes (4.679): extração hash-verify (byte-idêntico; variante legítima NÃO se
funde), uma família por commit. Família hot Agent-dispatch: SÓ se o recheck W0 provar fria (≤2 commits/6h);
senão semear na esteira e registrar. Áreas vetadas intocadas (Medição/Rivals, drivers keep-separate,
EngineeringKernel, sorts de hash).
**AC-W3:** cada extração com suíte verde + jscpd re-medido; nada fundido que divirja em 1 byte de lógica.

**W4 — Dedup de TESTES (a maior veia: 60k linhas dup).** Censo jscpd de tests/ → famílias de scaffolding
copiado (setup de fixtures, builders de payload, asserts de envelope). Extrair para helpers/TestCase base
compartilhados APENAS blocos byte-idênticos, preservando TODA asserção (teste é caller canônico — enfraquecer
cobertura é violação pétrea; contagem de asserções por arquivo ANTES==DEPOIS, provada por script). Lotes de
≤10 arquivos de teste por commit, suíte dos arquivos tocados verde antes/depois.
**AC-W4:** jscpd tests/ cai ≥1,5 ponto (5,13% → ≤3,6%) OU relatório família-a-família do porquê; asserções
totais preservadas (contagem provada); zero teste deletado/enfraquecido.

**W5 — Prova final + docs + sync.** Re-medir TUDO (mesmos params); tabela antes/depois; docs canônicos
afetados (leap-backlogs com rows rebaixadas, gap-matrix se wiring mudar estado) no MESMO commit da mudança;
`atlas engineering knowledge sync --prune` + `index-code --prune`; placar completo; memória atualizada.

## Regras pétreas (violar = parar e reportar)
1. **Anti-Goodhart pareado (a alma desta obra):** linha eliminada SÓ conta pareada com (preflight completo
   + prova de supersessão no retire | byte-identidade na extração | asserções preservadas no W4 | suíte
   verde antes/depois + zero caller quebrado). Deletar capability viva para inflar o placar = PARE.
2. **Wiring é REAL ou não é:** caller no fluxo que já executa + teste de integração assertando USO do
   output. Caller sintético/decorativo = teatro, reprovado. (O verify adversarial de W2 caça exatamente isso.)
3. **Retire de slice testada exige tripla prova:** deletion-preflight completo (bindings, class_alias,
   Finder/reflection, FQCN-string, schedule, artisan, git log, testes) + supersessão/inutilidade demonstrada
   + row do backlog de origem rebaixada no MESMO commit.
4. **Vetos vigentes:** EngineeringKernel/ core; esteira de Medição; drivers keep-separate; sorts de hash
   (SORT_STRING); fusão de executores Dev/Forge/Autonomos; Aaeos/Generated/ à mão (309 hand-authored — sem
   gerador, MAS pode haver gerador externo; qualquer edição ali exige extração para FORA do diretório com
   os consumidores repontados, nunca edição in-place).
5. **Testes:** wiper-safe sempre (SQLite :memory:, jamais RefreshDatabase em pgsql).
6. **Escritores concorrentes:** re-checar `git status`/mtime antes de cada commit; edição alheia →
   verify-then-absorb; nunca reverter às cegas; pathspec explícito sempre; nunca `git add -A`.
7. **Todo número re-contado na hora** (lição: 80→66, 158k→156k); claims com hash/prova.
8. **Verificação adversarial ao fim de CADA frente** (agente independente tentando refutar: wiring fake?
   retire de coisa viva? extração que divergiu? asserção perdida?) antes de declarar a frente fechada.
9. **Commits pequenos na main; push só com OK do operador. Placar PT-BR a cada lote.**

## Metas (medir, não declarar — bandas honestas)
- 0-ref micro-classes: 32,9% → **≤10%** (wire com integração real OU retire com tripla prova).
- Enforce do admission v2: **ligado** (com auditoria de FP publicada) — solidez por construção.
- Linhas eliminadas: **banda honesta 8k–25k** (fração retire dos 98 + consolidação fria ~2-6k + dedup de
  testes; o número REAL sai do W0 e é reportado, nunca ajustado).
- jscpd tests/: 5,13% → **≤3,6%** (ou relatório família-a-família).
- jscpd app: re-medido e reportado (sem meta cega — estoque restante é hot/vetado por natureza).
- Suíte: zero caller vivo quebrado; asserções de teste totais PRESERVADAS (contagem provada).

## Governança pré-início
`php artisan atlas:ai:session-bootstrap --task="obra 7 religar e reduzir" --json`; recall de memória sobre
[[obra6-consolidacao-estrutural-status]] (achados que fundamentam esta obra), [[loop-orphans-are-unwired-organs-not-deadcode]],
vetos; place-feature para wirings que criem fluxo novo.

## Follow-ups (preencher durante a obra)
_(vazio no início)_
