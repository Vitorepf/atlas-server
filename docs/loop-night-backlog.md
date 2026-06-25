# PART 2 — A máquina onde o ATLAS OFERECE as tasks (build order da arquitetura)

> Part 1 (cérebro onipresente) está FEITA. Esta é a **Part 2**: o sistema em que **o Atlas serve tasks** a
> qualquer IA com meta/loop, conflict-free, fila que não seca. Build order = a sequência de
> `docs/loop-brain-architecture.md` (a arquitetura que NÓS desenhamos). O CORAÇÃO é a task **A7**
> (`atlas:task next | report`) — é literalmente "o Atlas oferece as tasks". Tudo antes dela existe pra ela
> ser segura e nunca secar.
>
> Dois agentes fortes em LANES de árvores DISJUNTAS → nunca colidem (conflict-free por partição).
>
> PROTOCOLO DO AGENTE (harness-agnóstico):
> 1. Leia este arquivo. Sua LANE é fixa (A ou B, dada no seu prompt). Você SÓ toca a árvore da sua lane.
> 2. Próxima task = a de menor número da SUA lane cujo id (A3, B2...) NÃO aparece em `git log --oneline -60`.
> 3. Test-first (RED que reproduz → GREEN). Rode: `/opt/homebrew/bin/php ./vendor/bin/phpunit -d memory_limit=4096M <path>`.
> 4. Commit por task, id no início: `loop-night A3: <resumo>`. O git log é a fonte de "done"; NÃO edite este arquivo.
> 5. Pegue a próxima. NUNCA pare enquanto houver task não-commitada na sua lane. Ao fechar a lane, peça a próxima onda.
> 6. Honestidade > verde: se só passa fingindo/quebrando, PARE a task, registre no commit por que pulou, siga.
>
> COMPARTILHADOS (só a LANE A edita): `routes/console.php`, `config/atlas.php`. Commit na `main`; `index.lock`
> → espere 2s e tente. SEM `git reset --hard`. Migrations idempotentes. NÃO toque `MarketingDomain/**`. Português.
>
> RESSALVA HONESTA (não muda o build, mas registre): a originação 100%-autônoma de alta alavancagem em código
> maduro (R1) é cap model-bound; o `atlas:task next` serve a fila que EXISTIR. Construir a máquina é o trabalho
> de hoje; encher a fila com material infinito de alta alavancagem em código maduro é o limite conhecido.

---

## LANE A — A MÁQUINA DE SERVING (o Atlas oferece as tasks). Árvore: `app/Services/Ai/SelfConstruction/**` + `routes/console.php` + `config/atlas.php`

Build em ordem. A1–A5 endurecem o claim (pré-requisito de servir a N clientes sem colidir); A6 reconcilia pra
UMA fila; **A7 é o contrato que oferece as tasks**; A8 são as sentinelas das regras-mãe.

### A1 — flock fail-CLOSED no claim/lease (MF-06)
`AgentControlPlaneClaimLeaseRepository::withLock` (~786-815) é `Storage::exists/put` check-then-act, com break-no-timeout-4s que roda o callback SEM lock e `finally` que apaga `.lock` alheio. Trocar por `fopen`+`flock(LOCK_EX|LOCK_NB)` fail-CLOSED (igual `AtlasLoopMergeActuator` ~52-90): timeout ABORTA o claim; `finally` só libera o handle próprio; stale por token+timestamp. **Teste:** 2 claims concorrentes no mesmo packet → exatamente um adquire.

### A2 — select+claim atômico (anti-TOCTOU) (MF-16)
O `claimNext` do Orchestrator lista `claimable` sem lock e faz `updateStatus` fora do lease-lock. Mover seleção+reserva pra UMA seção crítica (sob o flock do A1) OU compare-and-swap `claimable`→`claimed`. **Teste:** 2 clientes pedindo juntos → o mesmo packet nunca sai 2×.

### A3 — reaper agendado + reabilitação de queue (MF-05)
`expireLeasesInternal` só muta `lease_status`; o queue record fica `claimed` pra sempre; nenhum scheduler reabilita. (i) comando `atlas:acp:reap-leases` que expira E faz `claimed`→`claimable`; (ii) `Schedule::command(...)->everyMinute()` em `routes/console.php`; (iii) `claimNext` varre expirados antes de listar. **Teste:** cliente morre após claim → após TTL+reaper a task volta claimable.

### A4 — overlap read-vs-write (MF-07)
`detectWriteOverlap` só faz write-vs-write (`array_intersect`); o `readSet` é capturado e ignorado. Considerar `write(A)` vs `read(B)` prefix-aware; serializar tasks que compartilham prefixo de subsistema. **Teste:** A escreve arquivo que B lê → conflito detectado.

### A5 — centralizar o predicado de overlap (MF-12)
Extrair helper único `WriteSetOverlap::conflicts($a,$b)` (prefix-aware, dir-vs-file + test-files) e substituir os ~10 call-sites. **Teste:** guard que enumera os chokepoints e prova que todos usam o helper.

### A6 — UMA fila / UMA stack canônica (reconciliação, step 3 da arquitetura)
Há 4 stacks + 3 bombas (Stack A probes / B `AtlasSelfConstructionReadinessService::claimNextPacket` / C grind vivo / D pipeline; bombas BacklogAutoFeeder/AutoReplenishment/QueueRefiller). Eleger Stack A canônica: Stack B **delega** a ela (não duplica); AutoReplenishment vira a bomba única (BacklogAutoFeeder → source provider dela); gate `AtlasLoopMasterSwitch` no caminho de claim/replenish (OFF = byte-identical no-op). Antes de migrar, **extrair `claimNextPacket`/reservation do god-file de 104k linhas** pra serviço nomeado com testes de caracterização. **Teste:** grep prova exatamente 1 stack viva + 1 bomba viva; MasterSwitch OFF = no-op.

### A7 — ⭐ CONTRATO `atlas:task next | report` (o Atlas OFERECE as tasks — step 5, o CORAÇÃO da Part 2)
Comando artisan `atlas:task {action=next|report}` (+ MCP `atlas_next_task` / `atlas_task_report`): **2 verbos, schema JSON FIXO, platform-free, `client_id` opaco** (qualquer IA chama). `next` → faz o claim atômico (A1-A2) na fila canônica (A6) e devolve UM task packet AUTO-SUFICIENTE (id, spec, allowed_files/escopo, critério de aceite, régua). `report` → ingere o resultado (sucesso/falha/diff) e libera/fecha o lease. Tudo gated no MasterSwitch (OFF = no-op). **Teste:** dois `client_id` diferentes chamando `next` recebem packets DISJUNTOS; `report` fecha o lease; schema estável.

### A8 — sentinelas das regras-mãe (step 6)
`QueueFillSentinel` (R1: alarma quando claimable < piso) + `ServingSlaSentinel` (R2: alarma quando `next` falha em entregar) — um heartbeat JSONL com `queue_depth` + `serve_success_rate`. Invariantes novas I-17 (fila nunca seca) / I-18 (serving nunca falha) declaradas em `AtlasAgentControlPlaneSafetyInvariantsService`. **Teste:** fila abaixo do piso dispara o alarme; serving vazio dispara o de R2.

---

## LANE B — SUPPLY + PERSISTÊNCIA (o que ENCHE a fila e fecha a Part 1). Árvore: `app/Services/Ai/AutonomousEvolution/**`

### B1 — P1-B: read-model persistente por snapshot
Read-model JSON ÚNICO keyed por `snapshotId` (cache store OU 1 tabela `key/value`), serializando o `toArray()` determinístico do `AtlasLoopScopeComprehensionModel`. NÃO 16 colunas. Edges re-derivam por full-grep no refresh. Wire o `AtlasLoopScopeComprehensionQuery` pra hidratar dele (cross-refill); `staceness()` continua mandando. **Teste:** round-trip byte-idêntico ao build fresco; staleness marca mudança.

### B2 — recordOutcome → CapabilityTrend (MF-18)
`recordOutcome` não toca `merged_to_main` → sinal de trend morto. Fazer o outcome gravar o sinal que `CapabilityTrendService.slope` lê (sem inventar tabela). **Teste:** outcome registrado → slope enxerga.

### B3 — entry-point de merge real (MF-03, metade AutonomousEvolution)
`completeReal(...)` que, sob `atlas-main-merge.lock`, invoca `AtlasLoopCycleGitContract`/`AtlasLoopMergeActuator` com verificação-antes-do-commit, aplicando patch entregue. Flag default-OFF. (O wiring do lado SelfConstruction é o JOIN, depois.) **Teste:** aplica patch sob lock com verificação; patch que falha a verificação NÃO commita.

### B4 — CLI de compreensão (observabilidade do cérebro)
`atlas:loop:comprehend {scope} --json` que dumpa os FATOS do `AtlasLoopScopeComprehensionQuery` (inventory/orphans/clones/doc-gaps + por unidade `level_vector` + `transitionsFor`). Read-only, nunca um score. (Registro do comando: TODO no commit pra Lane A pôr em `routes/console.php`.) **Teste:** roda na fixture e imprime fatos.

---

## JOIN (depois de A6 + B prontos — UM agente, cross-tree): ARMS — o cérebro ENCHE a fila (step 4)
Ligar `OriginationPipeline` / `LeverageScorer` / `CapabilityTrend` ao `AutoReplenishment` como `SourceProvider[]` injetável (composição, não motor paralelo) + `LeverageAdmissionGate` na boca da fila (material-or-reject, floor-POR-MARCO não por slope, overlap prefix-aware na mintagem). Isto fecha "a fila nunca seca" no limite honesto. **Não começar antes de A6 + B1.**

> O cérebro apenda mais ondas conforme estas fecham. A fila não seca esta noite: depois de A8/B4 vem o JOIN (ARMS) e a ponte de merge cross-tree.
