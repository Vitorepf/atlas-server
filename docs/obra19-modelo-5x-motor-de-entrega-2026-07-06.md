# Obra #19 — Modelo 5×: o motor de entrega de engenharia (prova 10× · colisão zero · sessão blindada)

Data: 2026-07-06 · Método: 3 investigadores (Etnógrafo do desperdício / Engenheiro de prova rápida / Arquiteto da sessão) com medições reais de transcripts, suite e código · Status: aprovável
Papel na linha: **#17 = o que entra · #18 = matéria/canos/delegação · #19 = o motor de entrega de QUALQUER modelo executor (sessão frontier, Codex nas ordens de trabalho, task-workers).** Todo slice implementável por modelo barato via ordem de trabalho (Kit da #18).

> **Governança:** Carta de Autonomia — o Atlas age sozinho na main local, commita e etiqueta no Diário de Evolução; **sem aprovação**. Onde este doc disser aprovação/assinatura/evento-operador/G0-não-auto-promove, vale o autônomo.

## A conta do 5× (etnografia quantificada de 7 sessões reais (as sessões do modelo frontier serviram de corpo de prova), 33.538 eventos, ~2.900 tool calls)

Cada iteração de um modelo executor neste repo = planejar → editar → **provar** → **aterrissar**. Onde ela sangra hoje:

| Ralo | Medição real | Coberto por |
|---|---|---|
| 1. Refutação tardia (spec mente 3-10×) | 10 refutações só-na-execução na #8; 2 implementações completas descartadas; 56 agentes de verificação por spec | **#17 T2 (crítica de spec) + #18 K (teste pré-escrito)** — executar, não re-planejar |
| 2. Re-derivação de contexto | 28-52% dos Reads são re-leituras; 18.320 recalls com feedback nulo | **#17 T0/T1 + #18 C/D** — idem |
| 3. Prova cara | 13-40% dos comandos Bash = test runs; suite = **329 min serial**; baseline F0.2 = meio dia | **#19 Frente P** |
| 4. Colisão multi-writer | **58 `index.lock` numa sessão**; 2 clobbers na #14; 2 slices da #8 bloqueadas; a sessão do modelo commita SEM lock enquanto workers usam committer lockado | **#19 Frente L** |
| 5. Fricção silenciosa + compactação | scheduler morto 8 dias sem alarme; rg-noop; **10 compactações em 5h**; 4 agentes travados em stall num dia | **#19 Frente S** |

**A matemática:** ralos 1-2 (≈50% do esforço) já têm spec — a #19 os assume como pré-requisito em execução. O 5× da #19 vem do empilhamento: prova mediana de minutos→segundos (×3-10 em iterações/hora) × zero retrabalho por clobber × sessão que não re-deriva após compactação × fan-out sem stall.

## Frente P — PROVA 10× (medições feitas em 06/07; cada slice reusa substrato verificado)

| Slice | Entrega | Substrato (verificado) | Gate |
|---|---|---|---|
| **P1 Pre-gate de 3s** | wrapper `atlas:pregate <paths>` = `php -l` + `pint --test` + `phpstan --memory-limit=3G` sobre o diff. **Fix embutido: phpstan hoje CRASHA em invocação padrão** (128M no bootstrap do larastan) — consertar o default e conferir o caminho do `EngineeringQualityScanService` | pint 0,25s/arquivo; phpstan 1,6s/arquivo com resultCache quente | pre-gate ≤3s; toda iteração com erro de tipo/sintaxe economiza 1 rodada de teste |
| **P2 `atlas:test:impacted <paths>`** | paths → testes que os cobrem (edges `test_targets` do world model + convenção) + comando phpunit pronto; sem cobertura conhecida ⇒ fallback DECLARADO "suite do módulo" | `ProgrammingTestImpactAnalyzer` (106 LOC, recebe graph injetado — falta ligar às tabelas reais); edges em `ai_codebase_world_model_edges` (`CodeGraphEdgeResolver:120`); harness de recall pronto (`atlas:programming:test-impact-benchmark`, gate ≥0,85) | prova mediana por slice: minutos/860s → segundos-1min; **recall ≥0,85 provado ANTES de confiar** |
| **P3 Receipt-cache no gate de WO** | antes de rodar cada comando de teste da ordem: (hash do teste, hash dos impl) → `hasGreenReceipt()` fresh ⇒ skip; senão `runAndRecord()` | `AtlasAaeosTestExecutionService` (pronto, freshness-bound, anti-fake-green `tests_run>0 && assertions>0`); tabela existente | comandos não afetados pelo diff → ~0s; em obra multi-slice, 60-80% dos comandos repetem sem mudança |
| **P4 Paratest** | ligar o paralelismo que JÁ está instalado (`brianium/paratest ^7.20` no composer.json:15, zero wiring). Fix necessário: sufixar com `TEST_TOKEN` os 3 paths compartilhados fixos do phpunit.xml; quarentenar os poucos colisores | sqlite :memory: forçado por processo ⇒ risco de DB zero por construção; 87% do tempo está em 693 testes ≥5s (paralelizável) | suite 329min → **~50min** (8 workers); F0.2 deixa de custar meio dia |
| **P5 `atlas:golden:freeze/check <alvo>`** | o padrão golden-hash ad-hoc das obras #8/#12 vira órgão: executa alvo sob `setTestNow`, canonicaliza (ordenação PROFUNDA declarada — gotcha do `ReadinessHash::stable` que só ksorta top-level), grava manifest+casos, `check` diffa POR CASO | idiom vivo em 49 testes; `MissionCanonicalHash::sha256` (479 call sites) | harness golden por obra: horas de código ad-hoc → 1 comando + fixture |
| **P6 Proof-sandbox sem vetor wiper** | trocar symlink de `vendor` por **`cp -Rc` (clonefile APFS, ~1-3s, custo ~zero)** nos 2 provisioners; `.env` hermético gerado, nunca symlinkado | `GovernedBranchMaterializationService::linkRuntimeDeps` (:648-660) e `EngineeringWorkspaceService` (:634-650) — **o padrão do incidente wiper continua vivo aí** | N provas paralelas seguras; `composer dump-autoload` em worktree nunca mais reescreve o autoload vivo |

## Frente L — LANDING: colisão zero (o modelo entra nas portas que já existem)

| Slice | Entrega | Substrato (verificado) | Gate |
|---|---|---|---|
| **L1 `atlas:land <paths...> -m "msg"`** | a sessão do modelo deixa de commitar git cru: comando fino expõe o `AtlasTaskScopedCommitter` (fail-closed sob `.git/atlas-task-commit.lock`, commit por pathspec — `git add -A` impossível por construção). Regra de sessão: editar; aterrissar em janela de lock curta (nunca segurar lock durante testes). Ordem de aquisição fixa: task-commit → main-merge, nunca o inverso | committer pronto (`AtlasTaskScopedCommitter.php:27,129`); padrão provado pelo agente AUTO-1 (landing de 9 arquivos em 1 commit sob os flocks) | 100% dos commits de sessão pela porta lockada; `index.lock` por sessão: 58 → ~0; clobbers/semana: 0 |
| **L2 Claims da sessão visíveis ao serving** | (a) PreToolUse registra claim `atlas_claim_task` por arquivo editado (TTL renovado); (b) o serving consulta o blackboard no claim de lease — path com claim ativo de outro engine ⇒ packet adiado (fail-open: blackboard indisponível = comportamento atual) | `AtlasAobgBlackboardService` + MCP (:1167/:3606) existem; **hoje NENHUM worker consulta** — advisory sem efeito; seam no `AtlasTaskServingService` | simulação worker×claim ⇒ lease adiada; 0 arquivos untracked perdidos |
| **L3 Guard do reset do soak** | o hazard documentado no próprio repo (auto-merge do soak faz `git reset/checkout` na árvore viva — `.claude/workflows/loop-heavywork-design.js` FACTS) ganha guarda: recusa se `git status --porcelain` acusa edits fora do escopo dele | hazard já escrito; falta o if | reset nunca varre WIP alheio (teste adversarial) |

## Frente S — SESSÃO blindada

| Slice | Entrega | Substrato | Gate |
|---|---|---|---|
| **S1 Fan-out sem stall (infra, não prompt)** | pesquisa delegada SEMPRE em tipos sem a ferramenta Agent (`Explore`/`Plan` a excluem por construção); `general-purpose` proibido para pesquisa; agente longo = background+notificação; ≥2 fases dependentes ⇒ Workflow/pipeline (10 provados em `.claude/workflows/`); template: tarefa + FACTS pré-verificados + schema de saída | tipos e workflows existentes | re-invocações manuais por stall: 4/dia → 0 |
| **S2 Sala de operações no bootstrap** | estender `atlas:ai:session-bootstrap` com o quadro do minuto 1: obra ativa (arquivo F2 da #17 — consumir, não duplicar), WOs abertas, saúde da esteira, workers launchd vivos, locks contendidos (probe não-bloqueante), master switch, claims ativos; injetado 1× no primeiro prompt | `AtlasAiSessionBootstrapCommand` + `atlas:task:health` + `atlas:autonomy:status` + `atlas-ctx.sh` | brief ≤3k chars; sessão nova responde "o que está em voo e o que me clobberaria" sem 1 grep |
| **S3 Estado pós-compactação** | Stop hook (e PreCompact se disponível) grava `session-state.json` efêmero (decisões do turno, arquivos quentes com intenção, provas rodadas, paths staged); UserPromptSubmit re-injeta SÓ após compactação detectada. Camada da SESSÃO viva — o arquivo de obra (F2) segue dono da verdade durável | `atlas-session-capture.sh` vivo; anti-eco por proveniência já provado (H2.5a) — marcar origem | teste: forçar compactação ⇒ modelo responde "o que provei/o que falta" sem re-leitura; re-provas redundantes = 0 |
| **S4 Frontier↔barato intra-sessão + dieta do hook** | presets: greps/inventário ⇒ Explore+haiku; mecânica de WO/fixture ⇒ sonnet com ordem formato K; julgamento/arquitetura/refutação/landing ⇒ frontier. Hook `atlas-ctx.sh`: dedupe por hash da última injeção + skip em prompt operacional | parâmetro model do harness; Kit K da #18; `ATLAS_AOBG_HOOK_BUDGET` | ≥40% dos tokens de fan-out em modelo barato sem retrabalho; chars/turno caindo sem perda de recall (`atlas:aobg:semantic-lift`) |

## Medição da obra

- **Iteração mediana por slice de WO** (pre-gate + prova + landing): baseline a medir na 1ª WO real; meta ÷5.
- **`index.lock` por sessão**: 58 → ~0. **Clobbers/semana**: 2 → 0. **Stalls re-invocados**: 4/dia → 0.
- **Suite completa**: 329min → ~50min. **Prova mediana**: minutos → segundos.
- Contadores do operador (#17) continuam a manchete; o Atlas mede sozinho, declara a fase pronta e etiqueta 'evolucao-de-fase' no Diário de Evolução.

## Sequência

`P1+P2 (dias, destravam tudo)` → `L1 (1 dia — a sessão do modelo entra na porta lockada)` → `P3+P4` → `S1+S2` → `L2+L3` → `P5+P6` → `S3+S4`. Paralelo permitido onde não compartilham arquivos; toda entrega via WO do Kit.

## Pétreas
As da linha (#17 regras transversais + #18 §Pétreas). Novas desta obra: nunca segurar lock durante testes; ordem de aquisição task-commit→main-merge; recall do test:impacted provado ≥0,85 ANTES de substituir suite do módulo; paratest só entra depois do TEST_TOKEN nos paths compartilhados.
