# PROMPT DE IMPLEMENTAÇÃO — Obra "ACOS Excellence 10/10" (v1)

> Este arquivo é um PROMPT operacional, pronto para ser colado numa sessão executora (Claude Code, Codex, Hermes ou outro provider engine). Ele carrega todo o contexto necessário. O plano canônico que ele executa é `docs/engineering-knowledge-base/atlas-acos-excellence-10-10-plan-v1.md`.

---

## 1. MISSÃO

Você é o executor da obra **ACOS Excellence 10/10** no repositório `/Users/vitorepf/develop/Atlas/atlas-server` (branch `main`). O objetivo é levar TODAS as dimensões do cérebro cognitivo do Atlas (ACOS) a **10/10 honesto** — medido por comando, nunca declarado — executando o plano de 98 slices em 6 ondas descrito no documento canônico:

**`docs/engineering-knowledge-base/atlas-acos-excellence-10-10-plan-v1.md`**

Sua PRIMEIRA ação obrigatória é ler esse documento INTEIRO (seções i–vii). Ele contém: o corte carimbado, o contrato pétreo, as 6 ondas, o catálogo completo dos 98 slices (cada um com goal, mecanismo, arquivos, comando de aceite, effort, deps e risco), o caminho crítico, os riscos sistêmicos e o critério de conclusão global. Este prompt NÃO substitui o plano — ele te dá o contexto para executá-lo sem desviar.

"10/10 honesto" significa: capacidade real que os comandos de medição confirmam. Se em algum momento a única forma de fechar um número for relaxar um medidor, fabricar um receipt, re-rotular dado ou esperar que ninguém audite — **o caminho certo é NÃO fechar o número** e registrar o bloqueio. A nota pode CAIR durante a obra (ex.: ao corrigir um medidor generoso); isso é comportamento correto e esperado.

## 2. AMBIENTE E BOOTSTRAP

- **Máquina:** MacBook do operador (Vitor), local-first. Nada sensível sai da máquina.
- **Runtime:** PHP 8.4+ (Homebrew). Postgres local em `127.0.0.1:5433`, database `atlas` (roda no OrbStack — se o banco não responder: `open -a OrbStack` e aguardar; runbook em memória `aobg-down-orbstack-postgres-runbook`).
- **Branch:** trabalhar direto na `main`. NUNCA criar branch, NUNCA merge. Push SÓ com OK explícito do operador.
- **Bootstrap de sessão (obrigatório antes de programar):**
  ```bash
  cd /Users/vitorepf/develop/Atlas/atlas-server
  bin/atlas aobg workspace activate --json
  php artisan atlas:ai:session-bootstrap --task="ACOS Excellence 10/10 — onda N, slice XXX-NN" --json
  ```
  e para features novas: `php artisan atlas:ai:place-feature "<descrição>" --json`.
- **Contexto vivo:** antes de decisão arquitetural ou refactor multi-módulo, chamar MCP `atlas_context_pack` (server `atlas-open-brain`) ou o fallback CLI:
  ```bash
  php artisan atlas:context-pack "<tarefa>" --workspace="$PWD" --json
  ```
- **Governança de conhecimento:** docs canônicos do repo em `docs/engineering-knowledge-base/` são a fonte autoral; Postgres KB e Code Intelligence são read models; Evidence Ledger prova eventos de runtime. Depois de mudar docs ou código estrutural, sincronizar:
  ```bash
  bin/atlas engineering knowledge sync --prune
  bin/atlas engineering knowledge index-code --prune --workspace="$PWD"
  ```

## 3. ESTADO MEDIDO NO CORTE (baseline auditada — commit `8e47c0082d`, 2026-07-09/10)

Estes números foram medidos por comando numa auditoria adversarial (46 agentes: ground-truth por dimensão → design → 3 lentes de verificação → crítica global). Re-meça no início da sua sessão; se algo driftou, registre a leitura nova no Evidence Ledger e siga — o plano só é re-aberto se uma PREMISSA de slice quebrou.

| Dimensão | Baseline | Comando de medição |
|---|---:|---|
| Estrutural (scorecard ACOS) | 9.92 overall / 9.77 pipeline (674/690) | `php artisan atlas:cognition:scorecard --json` (strict: exit 3) |
| Corpus de memória | 63/100 needs_review | `php artisan atlas:memory:quality --json` |
| Feedback/aprendizado | 8.408 feedbacks, positive=0, negative=0 | idem (counts.feedback) + `atlas:ai:learning-recall-lift` |
| RAG/recall | retrieval_eval=55; pack entrega 1 memória | `atlas:context-pack "<q>" --json` + memory:quality |
| Composição de contexto | 20/20 eventos low-ROI; 59 missed sources; 94 noise | provenance/ARFL no context-pack |
| Evidência longitudinal | NOT certified — 7 dias/30; receipt pipeline 8.79 com blocker ativo | `php artisan atlas:cognition:acos-long-horizon-gate --json` |
| AEMOR | 9/9 pass (já verde — manter) | `atlas:aemor:readiness --json` + `atlas:aemor:certify --json` |
| Compactação | testes 7/24 OK; truncamento heurístico dos últimos 6 turnos | `vendor/bin/phpunit tests/Feature/Console/AtlasEliteCompactionCommandTest.php` |
| Evolution score | 9.77 / 10 / 10 | `php artisan atlas:cognition:evolution-score --json` |

Fatos estruturais que você DEVE assumir como verdadeiros (verificados no corte):
- `ai_memory_deltas` existe (reparada por migration 2026_07_09_153500). O recall vivo funciona (bug PostgreSQL do demotion já corrigido em `AtlasMemoryRecallConcentrationDemotion`). O AOBG distingue `retrieval_error` de vazio.
- 58 entradas de memória (44 ativas), **0 relações**, 2 com rationale, 22 com título==resumo, scope só `global`. As decisões pétreas do operador NÃO estão no registry recuperável — vivem em projeções CLAUDE.md.
- 45.813 usages de memória com 8.408 feedbacks TODOS neutros — volume sem sinal ranqueável.
- AURG: 586 nós / 637 arestas, ~1 aresta de linker cross-layer.
- A série longitudinal vive em `storage/app/atlas/evidence/acos-delta-series.jsonl` (ex `fable-delta-series.jsonl` — o slice EVI-09 faz o rename provider-neutral ANTES de EVI-05/06; doc canônico: `docs/engineering-knowledge-base/atlas-acos-delta-series.md`). Floors: 30 dias, overall≥9.5, pipeline≥9.5, staleness≤2d. Nomes históricos PRESERVADOS (nunca renomear): `marco-zero-fable-2026-06-11.json` e receipts `fable-l4-*`/`fable-l5-*`.

## 4. CONTRATO PÉTREO (violar qualquer item invalida o slice, nunca a regra)

1. **Anti-Goodhart.** Meta é capacidade, nunca proxy. Nenhum medidor é relaxado para subir nota. Correção de medidor exige justificativa escrita commitada + leitura dupla (regra MED-01). Nenhum backfill, nenhum dado sintético contado como real, nenhum receipt sem execução real.
2. **Floor pétreo (alinhado ao charter de autonomia 06/07 — `docs/obra-linha-acos-carta-de-autonomia-2026-07-06.md`).** Memória/lições/aprendizados são aplicados AUTONOMAMENTE pelo Atlas quando reversíveis e aprovados pelos checks automáticos de qualidade (proveniência, evidência real, provider-safety) — SEM aprovação humana no meio. Toda aplicação gera entrada etiquetada no Diário de Evolução/digest semanal (`atlas:ai:weekly-memory-digest`) com handle de reversão (`atlas:ai:memory-forget`/journal-first). O operador revisa periodicamente (dia da semana/mês que ele escolher) e REMOVE o que estiver errado — revisão-depois, NUNCA aprovação-antes. É PROIBIDO re-impor os gates revogados ("G0 nunca auto-promove", "requires_human_approval=true", filas de aprovação). O que falha nos checks fica em `held` no digest com a evidência faltante nomeada. Telemetria é fail-open (nunca derruba a esteira viva); gates de landing são fail-closed. Provider-safety sempre (nada de segredo/prompt interno em output provider-bound).
3. **Author≠judge.** Evidência é CAPTURADA pelo harness (parse de saída real, exit codes), nunca declarada pelo executor. Golden sets congelados por juiz externo. Os certificadores que o plano constrói passam por re-prova externa (ADV-01) antes de qualquer 10.
4. **Reversibilidade.** Todo flip/enforce/default-ON nasce com gatilho objetivo de rollback pré-declarado (ROL-01) ANTES do flip. Seams que mudam comportamento entregue landam default-OFF e flipam em commit separado pós-soak. Kill-switch barato para todo agente/watchdog novo.
5. **Medidor≠produtor.** Slice `[MEDIDOR]` landa em onda ANTERIOR ao produtor que ele mede, com leitura dupla (valor sob o medidor antigo E o novo) registrada no Evidence Ledger no land.
6. **Relógios.** Time-gated nunca é espera passiva: cadência construída + watchdog que acusa produtor parado no D+1. Nenhum relógio liga antes do medidor congelar. Dia perdido é dia perdido — NUNCA retro-datar.

## 5. PROIBIÇÕES ABSOLUTAS (histórico real de incidentes — leia como cicatrizes, não burocracia)

- **NUNCA** rodar/religar a superfície `atlas:loop:*` (ACDE = MVP morto). O sistema vivo de autoevolução é `atlas:brain:*` + `atlas:task:*` (Autônomos). As ~26 classes `AtlasLoop*` da keep-list são VIVAS sob nome legado — **não deletar pelo prefixo** (`rg --no-ignore -w <Classe>` sempre > 0 antes de qualquer remoção; canon: `docs/engineering-knowledge-base/atlas-autonomos-live-system.md`).
- **NUNCA** `git add -A` / `git add .`. Commit escopado: `git add -- <apenas os arquivos do slice>`. NUNCA merge (o único merge real é de obra, pelo operador). NUNCA push sem OK explícito.
- **NUNCA** rodar a esteira de Autônomos (`atlas:task next` etc.) para "validar" — a obra é **implement-only até mandato explícito do operador**.
- **NUNCA** symlink de vendor, suites com `RefreshDatabase` apontando para o pgsql vivo, ou `.env.testing` apontando para o banco de produção — o incidente wiper de 15/06→02/07 destruiu 102 tabelas exatamente assim. Todo teste que toca banco usa o guard/sandbox existente.
- **NUNCA** cunhar green-run receipt sem execução real (o comando `atlas:cognition:mint-pipeline-receipts` só roda quando o slice pedir, e cada mint tem que ser lastreado — selo PIP-02).
- **NUNCA** usar as palavras "Jarvis", "Rivals", "benchmark", "superiority", "concurrent" em código/doc NOVO (exceção única: referenciar artefatos existentes que já carregam o nome, ex.: `atlas:ai:local-rag-benchmark`).
- **NUNCA** propor casca/UI própria — foco terminal-first (doc `atlas-terminal-first-focus.md`).
- Migration marcada "Ran" pode MENTIR (histórico real): confie em `Schema::hasTable()`/inspeção, não no status da migration.

## 6. ORDEM DE EXECUÇÃO

Execute em ondas, na ordem do plano (seção iii do doc). Dentro da onda, os `deps` de cada slice definem a ordem fina; slices sem dependência entre si e com arquivos disjuntos podem ser feitos em qualquer ordem.

- **Onda 0 — Fundação e higiene (14):** `EVI-01, EVI-02, EVI-04, FEE-02, TAXO-01, PIP-01, PIP-03, ENG-02, RAG-09, COM-09, FEE-12, OPE-02, MED-01, VOL-01`. O scheduler confiável (EVI-01/02) vem ANTES de tudo — sem ele, todo relógio time-gated é castelo na areia (o launchd já morreu silenciosamente antes; por isso EVI-01 é agente EXTERNO ao `schedule:run`).
- **Onda 1 — Seams + medidores congelados (18):** `COM-01, ENG-04, EVI-05, EVI-06, EVI-03, PIP-02, MEM-04, RAG-01, RAG-04, CPT-01, FEE-06, ENG-01, ENG-10, OPE-05, OPE-03, OPE-06, SUB-01, ROL-01`. COM-01 é o dono ÚNICO do namespace de refs (ninguém cria formato próprio). ENG-04→ENG-05 é a ordem obrigatória da autoridade de landing. SUB-01 (snapshot verificado do substrato) é pré-condição da onda 2 — a tabela de memória JÁ foi perdida uma vez.
- **Onda 2 — Produtores reais (22):** `OUTC-01, ENG-05, ENG-06, COM-02, COM-03, COM-04, COM-06, MEM-05, MEM-02, RAG-02, RAG-11, RAG-07, RAG-08, CPT-02, CPT-05, CPT-08, FEE-03, PIP-04, PIP-05, PIP-06, OPE-04, EVI-07`. **AO FINAL desta onda os relógios ligam** (série 30d, janelas ARFL, soaks 7d) — tudo que invalida série tem que estar landado antes.
- **Onda 3 — Consumidores do sinal (24):** `FEE-04, FEE-05, FEE-07, FEE-10, FEE-11, RAG-03, RAG-05, CORP-01, MEM-03, MEM-06, MEM-07, MEM-08, COM-05, COM-07, COM-08, COM-11, CPT-03, CPT-04, CPT-06, CPT-07, ENG-07, ENG-08, ENG-09, OPE-07`. Nada aqui pode reiniciar séries em curso. Golden set (RAG-05) só agora — corpus já re-hidratado e ranking já com feedback.
- **Onda 4 — Watchdog unificado + agregadores (13):** `WDG-01, MEM-09, FEE-13, RAG-10, RAG-12, COM-10, CPT-09, PIP-08, OPE-08, OPE-10, ENG-11, ENG-12, EVI-08`. UM framework de checks-plugin ancorado no EVI-01 — nunca 10 jobs soltos (routes/console.php já tem ~65 entradas; UMA entrada nova, não dez).
- **Onda 5 — Flips + certificação + re-prova externa (6):** `ENG-13, ENG-14, ENG-15, PIP-07, CPT-10, ADV-01`. Só depois dos relógios fecharem. Cada flip usa o gatilho de rollback já escrito no ROL-01. PIP-07 (re-cunhagem 690/690) só com green-run real por mint. ADV-01 = juiz que não participou da implementação re-prova os 6 certificadores em checkout limpo.

**Fusões já aplicadas no plano** (não re-implemente em separado): `TAXO-01` = ex FEE-01+MEM-01; `OUTC-01` = ex FEE-08+FEE-09+OPE-01+ENG-03 (espinha única de outcome dos 3 executores); `CORP-01` = ex RAG-06+OPE-09. IDs `CPT-*` = dimensão compactação (renumerada; `COM-*` é exclusivo de composição de contexto).

## 7. PROTOCOLO POR SLICE (repetir para cada um dos 98)

1. **Ler o slice no plano** (goal, mecanismo, arquivos, aceite, deps, risco) e confirmar que TODAS as deps já landaram.
2. **Re-verificar o ground truth**: os arquivos:linhas citados podem ter driftado desde o corte. `rg`/Read ANTES de editar. Se a premissa do slice quebrou (arquivo sumiu, mecanismo mudou), NÃO improvise em silêncio: registre a divergência, ajuste o slice pelo espírito do goal com justificativa escrita no commit, e siga.
3. **Teste primeiro quando o slice tem lógica** (a maioria dos aceites já nomeia o arquivo de teste). Teste que toca banco usa sandbox/guard — jamais o pgsql vivo.
4. **Implementar o MÍNIMO que satisfaz o aceite.** Sem abstração especulativa, sem scaffolding "para depois", sem tocar arquivo fora do escopo do slice.
5. **Rodar o comando de aceite EXATO do slice** e conferir o alvo. Aceite vermelho = slice não terminou (não "quase").
6. **Se o slice é `[MEDIDOR]`**: registrar leitura dupla (valor sob medidor antigo E novo) no Evidence Ledger, conforme MED-01.
7. **Commit escopado na main:**
   ```bash
   git add -- <arquivos do slice>
   git commit -m "<tipo>(acos10/<dim>): <ID> — <título curto do slice>"
   ```
   (tipos: feat/fix/harden/test/docs; NUNCA push sem OK.)
8. **Sincronizar KB** se mudou doc/estrutura (comandos da seção 2).
9. **Placar em PT-BR** após cada slice: `ID ✅ | onda N | feitas X/98 | faltam Y | próximo: <ID>` + 1 linha do que o aceite provou. A cada 3 slices, placar expandido com riscos/bloqueios.

## 8. GOTCHAS OPERACIONAIS (conhecidos, verificados)

- `rg` em diretório gitignorado (`storage/`) é NO-OP silencioso → use `rg --no-ignore`. Sinal do problema: `find` acha, `rg` não.
- Artisan aninhado (Artisan::call dentro de comando) tem pegadinhas de buffer — os slices que tocam comandos citam isso; preferir services chamados direto.
- `source_id` em superfícies de memória é UUID — não invente ids.
- Markers `[[...]]` em texto de memória são links de grafo — preserve-os.
- O `tinker --execute` com aspas/heredoc: prefira scripts em arquivo no scratchpad para lógica com aspas complexas.
- GAP-HERMES-01 (transport perde chunk final do cérebro writer) está ABERTO — afeta o executor que mais alimenta as séries; VOL-01 o nomeia como pré-requisito.
- Nunca use `Date`/relógio para "consertar" série longitudinal: dia sem amostra fica sem amostra.

## 9. TIME-GATED — O QUE O CALENDÁRIO GOVERNA (nunca acelerar por fabricação)

- **Série de 30 dias contíguos** do gate longitudinal: liga no fim da onda 2, fecha ~5 semanas depois SE não houver gap. O que REINICIA a série: mudança de medidor pós-início (por isso EVI-05/06 congelam na onda 1), gap de staleness>2d, wipe de tabela, retro-datação.
- **4 janelas semanais ARFL** (COM), **soak 7d** de governança (ENG) e de compactação (CPT): correm DENTRO da janela de 30d, em paralelo.
- **Volume real** (VOL-01): as janelas exigem runs REAIS dos 3 executores (Dev/Forge/Autônomos) — ≥3 runs Dev/dia útil, ≥5 ciclos Forge/semana, esteira Autônomos quando o operador mandar. Isso é compromisso do OPERADOR; o watchdog acusa "janela faminta" no D+1. Se o volume não vier, o caminho crítico estoura SEM defeito de código — reporte, não fabrique.
- Enquanto espera relógio: execute slices da onda seguinte que não invalidam séries (o plano marca o que pode).

## 10. CRITÉRIO DE CONCLUSÃO GLOBAL (seção vii do plano — resumo)

A obra só se declara concluída quando TODOS estes comandos estiverem verdes NO MESMO CORTE (mesmo commit, hashes carimbados no ledger), com janelas fechadas por série sustentada:

1. `atlas:cognition:scorecard --strict` → exit 0 (pipeline 690/690 com receipts reais selados por PIP-02)
2. `atlas:memory:quality --json` → score=100, status ok (leituras duplas MED-01 registradas)
3. `atlas:aemor:readiness` + `atlas:aemor:certify` → 9/9 com ≥20 episódios REAIS na janela
4. `atlas:cognition:acos-long-horizon-gate --json` → certified=true (≥30 dias contíguos, medidor congelado)
5. `atlas:ai:learning-recall-lift --json` → lift com case_count ≥ mínimo pinado (≥10/braço)
6. `atlas:cognition:evolution-score --json` → 10/10/10 por evidência
7. Certificador RAG (RAG-12) → recall@5 ≥ alvo no golden set congelado por juiz externo
8. Certificador de composição (COM-10) → measured_share ≥0.90 nas 4 janelas COM floor de contagem total de eventos
9. Certificador de compactação (CPT-10) → 4 mecanismos com must-keep coverage provado
10. Certificador de engenharia (ENG-12) → soak 7d com bypass_rate=0 e FP=0 SOBRE denominador mínimo de tráfego real
11. ADV-01 → 6 vereditos externos CONFIRMADOS, zero refutação
12. Re-auditoria integral → todas as dimensões ≥10 honesto, hashes carimbados

**Se qualquer item exigir relaxar um medidor para fechar, a obra NÃO está concluída — está refutada naquele ponto**, e o slice volta para a onda do defeito com justificativa registrada.

## 11. QUANDO PARAR E PERGUNTAR AO OPERADOR (lista fechada — todo o resto, execute)

- Flips de enforcement (onda 5) e qualquer default-ON que muda comportamento entregue: implementar + soak SIM; FLIPAR só com OK (o flip do master é ação operacional do operador — charter, ressalva de escopo).
- Push para remoto: sempre só com OK (a autonomia é na main LOCAL).
- Rodar a esteira de Autônomos: só com mandato explícito.
- Premissa de slice quebrada de forma que mude o DESENHO (não apenas o caminho de arquivo): propor a correção e aguardar.
- Qualquer coisa que exigiria violar a seção 4 ou 5: pare e reporte — a resposta certa nunca é a violação silenciosa.

## 12. INÍCIO DA SESSÃO (checklist de partida)

1. `bin/atlas aobg workspace activate --json` + session-bootstrap (seção 2).
2. Ler `docs/engineering-knowledge-base/atlas-acos-excellence-10-10-plan-v1.md` INTEIRO.
3. Re-medir a baseline (os 6 comandos da seção 3) e registrar as leituras de partida no Evidence Ledger.
4. Criar/atualizar `docs/engineering-knowledge-base/atlas-acos-excellence-10-10-progress.md` com o checklist dos 98 slices (onda, ID, status, commit, data do aceite) — este arquivo é o estado durável da obra.
5. Começar pela **onda 0, slice EVI-01** (o investimento de maior alavancagem do plano inteiro).
6. Placar em PT-BR após cada slice.

Boa obra. O caminho mais curto para o 10 é o honesto — qualquer atalho que engane o medidor será caçado pela re-prova externa da onda 5 e custará mais caro do que nunca ter sido tentado.
