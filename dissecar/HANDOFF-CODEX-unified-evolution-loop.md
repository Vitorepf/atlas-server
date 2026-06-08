# HANDOFF → Codex: Atlas Unified Evolution Loop

> Cole isto inteiro no Codex. É autossuficiente: Codex não tem memória da sessão onde isto foi construído.
> Repo root: `/Users/vitorepf/develop/Atlas/atlas-server` (Laravel/PHP 8.5, PostgreSQL na porta 5433, phpstan/larastan nível 5, nikic/php-parser v5 vendado).

---

## 0. Quem você é e a regra-mãe

Você é um engenheiro continuando um trabalho já em produção-local do operador (Vitor). **Antes de qualquer mudança de código não-trivial neste repo, consulte o Atlas** (é regra do `CLAUDE.md`, não opcional):
```
php artisan atlas:ai:session-bootstrap --task="<sua tarefa>" --json
php artisan atlas:ai:place-feature "<feature nova>" --json
```
(Hoje esses 2 comandos podem dar OOM a 128M em `AtlasAaeosImplementationEvidenceResolver->buildIndex()` — se der, rode com `php -d memory_limit=1G artisan ...`. Não conserte isso agora.)

Depois de mudar docs/código, sincronize os read-models:
```
atlas engineering knowledge sync --prune
php artisan atlas:engineering:knowledge index-code --prune --workspace "$(pwd)"   # precisa do --workspace
```

**Vocabulário PROIBIDO em código/doc (tolerância zero):** Jarvis, Rivals, benchmark, superiority, concurrent. **Nunca** diga que o Atlas "concorre" com Claude Code/Codex — ele **substitui esses produtos como produto e usa eles como engine**.

---

## 1. A missão (o que o operador pediu)

Um **loop autônomo do próprio Atlas**, propose-only, capaz de rodar 24h+ fazendo 4 coisas:
1. **Varredura de código** atrás de bugs/erros/código morto.
2. **Varredura de documentação** atrás de problemas, duplicação, doc-vs-código.
3. **Varredura código-vs-doc**: o que foi implementado, o que é "falso-implementado", duplicações, encanamentos desnecessários, código velho.
4. **Implementações** de pequenas a enormes.

O operador exigiu **qualidade extrema** ("garantir como no loop de finance/trading"), **relatório visível** (aproveitamento/yield), e que o loop fosse **rodado de verdade com resultados reais**. Ele queria nota mínima 9.5 nos 4 pontos — e foi dito a ele, com honestidade, que **no escopo completo dos 4 isso não chega a 9.5** (ver §8). NÃO fabrique nota. Honestidade acima de verde-falso é inegociável.

---

## 2. HARD CONSTRAINTS (quebrar qualquer uma invalida o trabalho)

- **PROPOSE-ONLY. NUNCA mergeia pra main.** Três camadas proíbem (pgsql CHECK + plpgsql trigger + Eloquent saving guard nas tabelas `atlas_loop_*`). Toda saída é "certified_for_review" = hipótese pra humano, nunca um merge/trade.
- **Provider-agnóstico.** Default `hermes_cli` (free, "always strongest mode"). Nunca hardcode um provider; resolva de config/task. O Hermes é músculo; o Atlas é cérebro.
- **(Trading, contexto irmão)** ZERO dinheiro real, sem broker, sem ordem, sem API key. Win-rate PROIBIDO como objetivo. Custos (fee/slippage) congelados pelo harness.
- **Honestidade:** rejeição do gate / null / "no winner" é o sistema FUNCIONANDO, não falha. Nunca maquie.

---

## 3. Arquitetura — o que foi construído (todos os caminhos reais)

Reusa o motor de busca já existente e provado — **NÃO reconstrua a camada de swarm**:
- `app/Services/Ai/AutonomousEvolution/AtlasEvolutionScenarioExplorer.php` — explora N cenários por task.
- `app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php` — o JUIZ FROZEN. 4 guardas Goodhart: TAMPER (frozen paths), SCOPE (allowed paths), RE-PROOF (re-roda os comandos), DIFF-EARNED (`revert_recheck`). Contrato de aceitação: `{commands[], allowed_globs[], frozen_globs[], metric_kind(gate|minimize|maximize), metric_pattern(regex grupo1=número), timeout_seconds, strict_untracked, revert_recheck}`.
- `app/Services/Ai/AutonomousEvolution/AtlasEvolutionLoopRunner.php` — roda fila de tasks → proposals[]. Retorna `diff_text` por proposta (não o conteúdo).
- `app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php` — monta workspace self-contained de um payload durável.
- `app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php` — supervisor durável 24h (modo P1/P4, teste-gerado).

### 3.1 Verificadores NOVOS (cada um é discovery + aceitação frozen) — `app/Services/Ai/AutonomousEvolution/Verify/`
- **`AtlasDeadCodeAnalyzer.php`** — código morto via AST. **Só sinaliza membros `private`** (escopo private ⇒ varredura de 1 arquivo é COMPLETA/sound — não precisa call-graph). Pula: métodos mágicos, props promovidas no construtor (contrato público), membros com atributos. **Desliga uma KIND inteira** se houver sinal de dispatch dinâmico (`__call`/`__get`, `$this->$x()`, `call_user_func`, `method_exists`, `property_exists`, `compact`/`extract`, `constant()`). **Cap de 512KB** por arquivo (um arquivo de 8MB gerado estourava php-parser a 128M e derrubava o scan) → arquivo grande = inconclusivo (fail-closed).
- **`AtlasDocClaimAnalyzer.php`** — "falso-implementado". Extrai 2 tipos de claim decisivos de um doc: FQCN `App\...` (classe) e `php artisan <cmd>` (comando). Classe existe se: arquivo PSR-4 declara o leaf, **OU** existe diretório de namespace, **OU** um arquivo irmão no diretório do namespace declara o leaf (tipos secundários). Comando existe via **registro autoritativo do console** (`array_keys($app->all())`) — NÃO grep (grep perdia 252 comandos reais registrados via base class). Regra de prefixo: `atlas:x:y:z` passa se qualquer prefixo-colon `atlas:x:y` está registrado (shorthand de subcomando).
- **`AtlasDocStructureAnalyzer.php`** — 12 seções canônicas (Resumo, Papel no Atlas, Onde Se Encaixa, Contratos, Fluxo, Regras para IA, Escopo de Implementacao, Dependencias, Evidencias, Riscos, Exemplos, Proximas Acoes) + `graph_layer` válido (system/module/flow/world/gear) + `doc_schema: atlas_canonical_module_doc.v1`. **Fonte única** — `AtlasDocsLintFileCommand` foi refatorado pra usar este.
- **`AtlasP3FindingDispatcher.php`** — a PONTE que interliga os 4 pontos. `scan($repo, $opts)` roda os 3 oráculos repo-wide e emite achados TIPADOS com disposição:
  - `auto_loop` (deadcode, docs_structure) = behavior-free/checável → o loop fecha sozinho.
  - `flag` (fake_implemented/phantom) = JULGAMENTO → backlog pra humano/Forge (NUNCA auto-edita; deletar a claim apagaria um plano real). Roteia `implement_or_mark_planned` vs `reconcile_doc_or_implement` por nome do doc.
  - `toDeadCodeTask()` / `toDocStructureTask()` montam a task métrica com base_workspace + acceptance = `php {base_path}/artisan atlas:code:deadcode-check --path=target.php` (ou lint-file). O verificador roda via o artisan do REPO com `--path` no workspace; doc-reality resolve claims contra `--workspace=REPO` (frozen).
- **`AtlasEngineeringHonestyGate.php`** — **O GATE DE QUALIDADE. Leia §4 INTEIRA antes de tocar.**

### 3.2 Orquestrador NOVO
- **`app/Services/Ai/AutonomousEvolution/AtlasUnifiedLoopOrchestrator.php`** — o loop unificado durável 24h. `run()` → loop de ciclos até budget/kill-switch/drenado; `runCycle()` escaneia + mói até `max_per_cycle` achados não-vistos; `grindAndGate()` roda runner → reconstrói o conteúdo proposto via `applyDiff()` (git apply do diff) → passa pelo honesty gate. Persiste tudo em JSON (file-based — sobreviveu a um blip de Postgres que matou a campanha). `ini_set('memory_limit','1536M')` no início + `gc_collect_cycles()` por grind. Reaper de /tmp via `AtlasLoopResourceGate::sweepOrphans` (boot + idle).

### 3.3 Comandos NOVOS — `app/Console/Commands/`
- `atlas:code:deadcode-check --path=<file>` (aceitação frozen, imprime `ATLAS_DEADCODE=n`) / `--scan=<dir>` (discovery).
- `atlas:docs:reality-check-file --path=<doc> --workspace=<repo>` (imprime `ATLAS_DOC_PHANTOM=n`) / `--scan=<dir>`.
- `atlas:loop:unified` — entry do loop unificado. Flags: `--max-seconds --modes=deadcode,docs_structure --code-roots --docs-roots --provider --scenarios --max-per-cycle --idle-seconds --once --json`.
- `atlas:loop:unified:report` — o PAINEL VISÍVEL (utilização/yield/aproveitamento, por-modo, backlog flagueado, status da campanha P1/P4 dobrado). Lê `report.json` (read-only).

### 3.4 Doc canônico + Testes
- `docs/engineering-knowledge-base/atlas-unified-evolution-loop.md` (passa nos próprios verificadores: lint + reality).
- `tests/Unit/Ai/AutonomousEvolution/Verify/` — `AtlasDeadCodeAnalyzerTest` (8), `AtlasEngineeringHonestyGateTest` (11), `AtlasDocClaimAnalyzerTest` (8). **26+ testes, phpstan nível 5 limpo em tudo.**

---

## 4. O HONESTY GATE — 7 camadas + 6 furos que NÃO PODEM REABRIR

`AtlasEngineeringHonestyGate::evaluateDeadCodeRemoval($repoRoot,$originRel,$originalContent,$proposedContent,$deadMembers)` é o holdout que transforma um vencedor do juiz-frozen em proposta honesta. **Foi quebrado 6 vezes por DUAS auditorias adversariais e endurecido. Se você mexer no gate, re-rode as exploits (estão em `storage/atlas/tmp/exploit*.php`) e os testes — uma suíte verde NÃO garante o gate (as 6 primeiras passavam enquanto o gate era furado).**

As **7 camadas** que uma remoção precisa passar:
1. **PARSES** — `php -l` limpo no proposto.
2. **FLAGGED-ACTUALLY-DEAD** — re-deriva deadness do ORIGINAL fresco (não confia na lista `$deadMembers` do caller); cada flagged tem que estar no `dead[]` do analyzer. Razão: `flagged_member_not_actually_dead`.
3. **RE-PROVED** — re-analisa o proposto: 0 membros mortos.
4. **REMOVED-ONLY** — nenhuma declaração nova, nenhum membro não-flagueado removido (via AST, não regex).
5. **SURVIVORS-UNCHANGED** — todo membro que sobrevive é **byte-idêntico** (não pode gutar valor de const, flipar `true→false`, mudar corpo). Via `memberSources()` (getStartFilePos/getEndFilePos).
6. **PURE-DELETION** — o proposto é uma **subsequência de linhas** do original (só deleções; pega injeção top-level tipo `eval()` fora de qualquer membro). Razão: `not_a_pure_deletion`.
7. **NO-DANGLING-REFERENCE + REPO-CLEAN** — nenhum código sobrevivente referencia um membro removido (`removed_member_still_referenced_in_file`); e pra membros **não-private**, grep no repo (`-rlw`, só paths) confirma zero referências externas. **Private é PULADO no repo-clean** (private é escopo de classe; nome igual em outro arquivo é COLISÃO, não referência — grepar private causava rejeição falsa e matava o yield).

Tudo declarado/lido do **MESMO AST que o verificador usa** (nunca regex) — senão um splice `/**/` esconde membro.

### Os 6 furos REAIS achados + corrigidos (NÃO regrida):
1. **regex vs AST**: `public function/**/backdoor(){ shell_exec(...) }` passava por `removed_only` (regex cego ao `/**/`). → `declarations()` usa AST.
2. **nomes não corpos**: gutar `private const MAX=999999` / `ENFORCE_TLS=false` mantendo o nome certificava. → byte-equality `memberSources()`.
3. **injeção top-level**: `eval($_GET[...])` fora de membro passava. → pure-deletion subsequência.
4. **confiar no caller**: deletar `guardLimit()` private ainda chamado por método PÚBLICO sobrevivente — 6 holdouts passavam (re-proof é private-only) mas quebrava em runtime. → re-derivar deadness + scan de dangling-ref.
5. **falso-positivo de tipo secundário** em `AtlasDocClaimAnalyzer`: classe real declarada em arquivo nomeado pelo tipo primário era flagueada phantom. → scan do diretório de namespace.
6. **repo-clean conservador demais**: rejeitava remover campo private com nome comum (colisão). → visibility-aware (pula private).

Mais: OOM (cap 512KB + memory_limit + grep `-rlw` + gc), taxonomia `reconstruction_failed` (falha de aplicar diff ≠ veto honesto), prefixos do reaper (`atlas-loop-p3-`/`-docstruct-`/`atlas-apply-`).

**Gate de docs** (`evaluateDocEdit`): rejeita esvaziar / dropar frontmatter / encolher >40%.

---

## 5. Estado atual no disco (fatos)

- **Último run: `storage/atlas/loop/unified/run-20260608-133531-fe7dda/`** — `proposals.jsonl` (73), `rejected.jsonl` (1), `backlog.json`, `report.json`, `state.json`.
- **73 propostas propose-only**, yield 98,6%: 37 remoção de código morto + 36 completar estrutura de doc. 1 rejeição honesta (`HermesAdapterReceipt::hashValue` — o gate pegou o Hermes removendo um método a mais). `merged_to_main:false`.
- **Backlog flagueado: 48 phantoms em 27 docs** (fake-implemented; ex.: `durable-reservation-repository-blueprint-contract.md` tem 7 classes nunca construídas; `atlas:continuity:*` e `atlas:ai:self-construction:*` claims sem comando). Disposição já roteada no `backlog.json`.
- **O loop MORREU em ~6.6h** (não fechou 24h). Sem erro de PHP no log → foi terminado por fora (sleep da máquina ou cleanup de processo de background), não crash de código. Tinha drenado todo o backlog (73/73) antes — então as ~17h restantes seriam só vigia.
- **Bug conhecido do watchdog**: `storage/atlas/tmp/watchdog.sh` monitorava o pid do wrapper `zsh`, não o `php` worker — não alertou a morte.
- **Campanha P1/P4** (`atlas:loop:campaign`, id `019ea671-005b-72a7-9977-a19159e181ae`): 72 certificadas, parada (caiu num blip de Postgres porta 5433 — `SQLSTATE[08006] connection refused` em `AtlasLoopStore::reclaimExpiredTasks`; PG voltou). Resumível com `--campaign-id`.

---

## 6. Como rodar / monitorar / parar

```bash
# rodar o loop unificado 24h (full app/ + docs canônicos)
php artisan atlas:loop:unified --max-seconds=86400 --provider=hermes_cli \
  --modes=deadcode,docs_structure --scenarios=2 --max-per-cycle=5 --idle-seconds=600 --json

# um sweep só (prova rápida)
php artisan atlas:loop:unified --once --modes=deadcode --code-roots=app/Services/Ai/Finance

# painel visível
php artisan atlas:loop:unified:report

# kill-switch
touch storage/atlas/loop/unified/STOP

# resumir a campanha P1/P4
php artisan atlas:loop:campaign --campaign-id=019ea671-005b-72a7-9977-a19159e181ae --provider=hermes_cli --no-shadow --json

# testes + phpstan (verificar que nada quebrou)
php artisan test tests/Unit/Ai/AutonomousEvolution/Verify/
php -d memory_limit=3G vendor/bin/phpstan analyse --no-progress --level=5 app/Services/Ai/AutonomousEvolution/Verify/
```
**Lição de OOM:** rode SEMPRE no escopo cheio (`--scan=app` = 4926 arquivos) antes de confiar — `--once` num escopo pequeno escondia o OOM. Scan cheio: ~7s, 38 achados de código morto.

---

## 7. PRÓXIMOS PASSOS (priorizados) — o que fazer

1. **Verificar/aprovar as 73 propostas** — elas são CANDIDATAS certificadas pelo gate, NÃO verdades. Re-aplique cada uma em checkout limpo, re-rode a aceitação frozen + a suíte ampla, cace gaming. (Existe a skill `loop-proposal-adversarial-verify` pra isso.) Só então viram merge-ready (e o merge é decisão do humano — o loop nunca mergeia).
2. **Salto real: materialização para P4-pequeno** — destrava o 4º ponto de verdade. Leia o anexo `dissecar/OBRA-CODEX-materializacao-P4.md` antes de codar: o gargalo real não é só workspace, é gerar/selecionar um teste RED→GREEN frozen para alvo framework-reaching. Use um materializer P4 separado (`git worktree` + `vendor/` symlink + DB de teste hermético), gate P4 próprio (`evaluateImplementation`), holdout alvo por cenário e suíte ampla só no vencedor. Isso move bug-fix/feature pequena para dentro do loop propose-only; implementação grande e julgamento semântico continuam humano/Forge.
3. **Re-prova adversarial out-of-process como gate final** — cablear a rotina hoje manual (`loop-proposal-adversarial-verify`) antes de `certified_for_review`: checkout limpo, aplica diff, re-roda aceitação frozen + testes relevantes, confirma pure propose-only. Motivo: a suíte verde já escondeu furos do gate; self-verification in-process é útil, mas correlacionada.
4. **Modos novos de verificador para pontos 2+3** — começar pelos behavior-free/checáveis:
   - clone estrutural/duplicação de código via AST normalizado → flag/humano consolida;
   - hotspots de complexidade e encanamento desnecessário → flag com evidência;
   - gaps de cobertura de teste → gerar teste de caracterização e rodar RED→GREEN em workspace materializado;
   - doc-drift semântico e duplicação de docs → flag, nunca auto-resolver quando houver julgamento.
5. **Supervisor real 24h** — o loop morreu em ~6.6h e o `report.json` ficou `running` sem worker PHP. O watcher temporário em `storage/atlas/tmp/watchdog.sh` deve procurar o processo PHP real (`php ... artisan atlas:loop:unified`) e sair com erro quando sumir; o salto correto é launchd/supervisor com restart, kill-switch, heartbeat e liveness por `heartbeat.json`/`report.json`.
6. **Robustez da campanha P1/P4 contra blip de DB** — verifique se `AtlasLoopDbResilience`, `AtlasLoopTransientDbException`, guards em `AtlasLoopCampaignSupervisor` e configs `atlas.loop.campaign.db_*` já estão presentes. A bateria esperada é `php artisan test tests/Unit/Loop/AtlasLoopDbResilienceTest.php tests/Feature/Loop/AtlasLoopCampaignSupervisorTest.php`. Se faltar, implemente retry-com-reconnect (`DB::reconnect()`), park de ciclo em outage curta, abort limpo só se o PG ficar fora por minutos, lock sempre liberado.
7. **Loop aprende o que vale a pena** — capturar decisão humana sobre propostas (aprovou/rejeitou/por quê) e usar isso para priorizar descoberta. Isso é o compounding real: o loop fica melhor em escolher onde gastar provider/gate, sem auto-aplicar julgamento.
8. **Provar provider breadth** — hoje o caminho vivo está provado com `hermes_cli`. Rode o mesmo gate com outros providers configurados pelo Atlas, sem hardcode, e compare apenas por evidência de aceitação/rejeição, não por narrativa.
9. **Backlog de 48 phantoms**: por doc, decidir implementar (P4) ou marcar `status: planned`/corrigir a claim (P2). Os "blueprint/contract/spec" são planos não-construídos → P4 ou marca planejado; runbooks com claim errada → corrige doc.
10. **KB sync** (deferido): `atlas engineering knowledge sync --prune` + `index-code --prune --workspace "$(pwd)"` pro doc novo + serviços novos.

---

## 8. Teto honesto (NÃO infle — o operador exige honestidade)

- O loop **fecha sozinho** só o que é behavior-free/checável: **código morto (P1/P3) + estrutura de doc (P2)** — isso está **~9/10, provado vivo** (73 propostas, 98,6% yield, gate endurecido).
- **Fake-implemented (P3) = descoberta + flag**, não auto-fecha (a resolução é julgamento).
- **Implementação grande (P4) + julgamento semântico = roteado pra humano/Forge**, nunca às cegas.
- **No escopo COMPLETO dos 4 pontos NÃO é 9.5.** O null/flag honesto é o sistema funcionando. NÃO fabrique nota.

## 9. Tese do Atlas (contexto, não esquecer)
Atlas é pessoal do operador (Vitor), local no Mac. Providers (Hermes/Codex/etc.) são MOTOR; Atlas é o cérebro (memória governada, evidence, propose-only, self-construction). Antifragilidade composta: quando o provider salta N×, o Atlas captura via wrapper governance e adiciona M× próprio. O loop unificado é um exemplo: o Hermes só gera o diff; o Atlas decide o que escanear, congela o verificador, endurece o gate, e nunca mergeia.

---
**TL;DR pro Codex:** Continue o Atlas Unified Evolution Loop. Está propose-only, file-based, com um gate de 7 camadas endurecido contra 6 exploits reais (§4 — não regrida). 73 propostas esperam verificação (§7.1). O maior salto é materialização para P4-pequeno (§7.2); o ROI rápido é re-prova out-of-process + detector de duplicação (§7.3-§7.4). Supervisor real ainda falta (§7.5). Nunca mergeie, nunca fabrique nota, sempre consulte o Atlas antes de mexer (§0).
