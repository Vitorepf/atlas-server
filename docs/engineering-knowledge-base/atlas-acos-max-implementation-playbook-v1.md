# ACOS Max — Playbook de Implementação v1 (roteiro executável para QUALQUER IA)

> **O que este documento é:** a PROJEÇÃO EXECUTÁVEL de `atlas-acos-max-frontier-plan-v1.md` (o plano, 3.100+ linhas, 255 slices, seções i–xv). O plano é A LEI — este playbook só organiza a ordem, os gates e o protocolo. **Em qualquer divergência entre este roteiro e o plano, o plano vence e a divergência vira issue no ledger de gaps.** Este playbook NÃO re-propõe, NÃO resume mecanismos e NÃO substitui a leitura do slice: cada slice é implementado lendo o texto integral dele no plano.
>
> **O que "100%" significa:** todos os 255 slices em estado terminal {`landed` | `suspended_pending_evidence` (ELEV-28) | `refutado-com-registro`} + os critérios de conclusão (§vii itens 1-10, §viii itens 11-17, critérios pétreos da §xiii, §xv itens 18-21: mission_e2e ≥ alvo, N-Capture Drill executado, ≥1 volta proven_real fora de engenharia, Trajectory Vault com privacy provada) verdes NO MESMO CORTE + MARCO ESP-V1 completo + M>1 e R>0 medidos. Slice suspenso por evidência CONTA como estado terminal honesto — verde vácuo não.

---

## 0. Identidade e regras de leitura

1. **Documentos, em ordem de autoridade:** (1º) o plano `atlas-acos-max-frontier-plan-v1.md`; (2º) este playbook; (3º) o doc de RAG `atlas-acos-rag-pipeline-and-frontier.md` e o mapa `atlas-acos-areas-map.md` (contexto). O plano v1 (`atlas-acos-excellence-10-10-plan-v1.md`) pertence a OUTRO executor — você não toca nos slices dele (regra §ii).
2. **Nunca edite um slice do plano para caber na sua implementação.** Se o slice está errado contra o código real: pare, registre a divergência no ledger de gaps (`atlas-open-gaps-regressions-ledger.md`) com evidência file:linha, e implemente o que o código real exige mantendo o ESPÍRITO do aceite (denominador, caso negativo, limiar pinado). Nunca afrouxe o aceite.
3. **Todo número citado no plano é foto de 11-12/07/2026.** Antes de depender de um número, re-meça (o plano exige isso via ESP-00 para os divergentes; para os demais, `rg`/`psql` read-only antes de citar).
4. **Orçamento de contexto (crítico para modelos com janela pequena):** NUNCA leia o plano inteiro (3.000+ linhas). Por sessão, leia APENAS: (a) o slice atual via `grep -n "<SLICE-ID>" <plano>` + ~60 linhas a partir da PRIMEIRA ocorrência que define o slice (atenção: os MAXF-01..11 são linhas de TABELA, não blocos — a definição inteira cabe na linha); (b) o bloco "Colisões com v1" da MESMA área (grep por "Colisões" após a linha do slice); (c) as seções deste playbook que o protocolo mandar. O scoreboard diz ONDE você está; o playbook diz COMO; o plano diz O QUÊ — nessa ordem, sob demanda.

---

## 0.5 QUICK-START — sua primeira sessão em 10 passos (siga literalmente; zero inferência)

1. `cd /Users/vitorepf/develop/Atlas/atlas-server`
2. Confirme o ambiente (seção 0.7). Se algo falhar, conserte o ambiente ANTES de tocar código.
3. Abra `docs/engineering-knowledge-base/atlas-acos-max-execution-scoreboard-v1.md` e ache o PRIMEIRO item `pending` do primeiro lote com itens não-terminais.
4. `grep -n "<SLICE-ID>" docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md` → abra o plano nessa linha e leia o slice INTEIRO (Goal, Mecanismo, Aceite, Risco, deps) + o bloco "Colisões" da área.
5. Verifique cada dep do slice: se é dep Max, procure no scoreboard (precisa estar `landed`); se é dep v1 (tabela da seção 11), verifique no código/doc de progresso do v1. Dep pendente ⇒ marque `blocked_by:<dep>` no scoreboard e volte ao passo 3 com o próximo item.
6. Releia no código real TODO arquivo:linha citado pelo slice (`rg`/leitura direta). Divergência plano×código ⇒ regra 0.2.
7. Execute o sub-protocolo do tipo do slice (seção 4, passo 4). Se `[MEDIDOR]`: faça o FREEZE primeiro (receita na seção 0.6).
8. Implemente o menor diff que satisfaz o aceite; rode o aceite COMPLETO, incluindo o caso negativo (`php artisan test --filter=<Suite>` + os comandos `--json` citados no aceite).
9. `git add -- <somente os arquivos deste slice> docs/engineering-knowledge-base/atlas-acos-max-execution-scoreboard-v1.md` → commit `feat(acos-max): <SLICE-ID> <título>` (o scoreboard atualizado VAI no mesmo commit).
10. Publique o placar (seção 9) e volte ao passo 3. NUNCA: `git add -A`, `git push`, flip de master, editar aceite.

## 0.6 Receita concreta de FREEZE (para todo slice `[MEDIDOR]` — leis ELEV-03/18)

Um freeze é UM registro no Evidence Ledger com este payload mínimo:
```json
{ "kind": "measure_freeze", "measure_id": "<serie/medidor v2>", "formula": "<texto exato ou ref>",
  "thresholds": { "<nome>": <valor> }, "denominator_min": <n>, "ttl_days": <n>,
  "author_engine_id": "<engine que implementa>", "judge_engine_id": "<engine DIFERENTE que valida>",
  "content_hash": "<sha256 do bloco acima>" }
```
Como gravar: use o serviço `AtlasEvidenceLedger::record()` (`app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php:34` — verificado 12/07). Se não existir comando CLI de escrita, criar UMA vez no Lote 0 um comando pequeno `atlas:acos:freeze --json='<payload>'` (parte do MAXG-01-mínimo; reusado por todos os freezes seguintes). Regras: `judge_engine_id != author_engine_id` sempre; registrar a série no registry do ELEV-20s no mesmo passo; NUNCA alterar um freeze — mudança = freeze novo versionado.

## 0.7 Pré-requisitos de ambiente (verificar antes da primeira sessão)

| Item | Como verificar | Nota |
|---|---|---|
| PHP 8.4+ (Homebrew) | `php -v` | requisito pétreo do atlas-server |
| Postgres local do Atlas | `psql -p 5433 -d atlas -c 'select 1'` | container na porta 5433; NUNCA usar em teste (regra A5) |
| Suíte de testes | `php artisan test --filter=NomeDeUmTesteExistente` roda | RefreshDatabase jamais no @5433 |
| Runtime Python semantic_rag | `.venv` em `runtimes/python/semantic_rag/` existe | embeddings/graph_rank dependem dele |
| rg em storage/ | usar `rg --no-ignore` | storage é gitignored (gotcha 6/A6) |
| Hooks/MCP do Atlas | `bin/atlas open-brain mcp --describe` responde | se MCP cair, fallback CLI (CLAUDE.md) |

## 0.8 Mapa de tradução ONDA/FASE (vocabulário do plano) ↔ LOTE (vocabulário deste playbook)

| Plano diz | Playbook executa em |
|---|---|
| M0 (higiene) | L0 |
| F0 (freios) | L1 (com ESP-00/MAXG-01-mín/MAXE-02/03/MAXF-01 antecipados em L0) |
| M1 (medidores v2) | L2 (inclui `[MEDIDOR]` de qualquer família, pela lei de intercalação da §ix) |
| M2 (eficiência) | L3 |
| F1 (ligar o fluxo) | L4 (+ L6 = MARCO ESP-V1, o gate de saída de F1) |
| M3 (corpus) | L5 |
| M4 (inteligência/atuadores) | L7 |
| F2 (eixo reflexivo) | L8 |
| M5 (certificação) | L9 |
| M6 (fronteira pós-Max) | L10 |
| F3 (substrato 10-100×) | L11 |
| Certificação final / Marco Zero v2 | L12 |

Se um slice declara onda diferente do lote do inventário 5.5: o inventário vence (a diferença é a lei de intercalação — medidor pousa cedo).

## 0.9 Procedimento de desambiguação (quando um termo/ID/arquivo não for encontrado)

1. `grep -n "<termo>" docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md` (o plano define quase tudo inline).
2. `rg --no-ignore -n "<termo>" app/ config/ docs/` (o código é a segunda fonte).
3. Glossário (seção 12) e tabela de deps v1 (seção 11).
4. Ainda ambíguo ⇒ NÃO invente: registre o gap no ledger de gaps com o que você encontrou, escolha a leitura MAIS CONSERVADORA (a que não relaxa nenhum gate/aceite) e siga. Ambiguidade jamais justifica afrouxar aceite ou pular caso negativo.

## 0.10 Auto-auditoria de cobertura (rodar ao fechar cada lote)

```bash
cd docs/engineering-knowledge-base
# (a) todo ID do scoreboard existe no plano — saída deve ser VAZIA:
grep -oE '(MAX[A-N]|ASI|RAGX|ELEV|MULT[JKHVX]|MULTN1[57]|ESP|REC|TETO)-[0-9]{2}[a-z]?' atlas-acos-max-execution-scoreboard-v1.md | sort -u | while read id; do grep -q "$id" atlas-acos-max-frontier-plan-v1.md || echo "AUSENTE NO PLANO: $id"; done
# (b) todo slice definido no plano está no scoreboard — saída deve ser VAZIA:
grep -oE '^\*\*(MAX[A-N]|ASI|RAGX|ELEV|MULTJ|MULTK|MULTH|MULTV|MULTX|MULTN15|MULTN17|ESP|REC|TETO)-[0-9]{2}[a-z]?' atlas-acos-max-frontier-plan-v1.md | sed 's/^\*\*//' | sort -u | while read id; do grep -q "$id" atlas-acos-max-execution-scoreboard-v1.md || echo "FALTA NO SCOREBOARD: $id"; done
```
(Verificado em 12/07/2026: as duas saídas vazias.) Qualquer linha de saída = gap de cobertura ⇒ corrigir antes de fechar o lote.

## 1. Bootstrap de TODA sessão de implementação (obrigatório, na ordem)

```bash
cd /Users/vitorepf/develop/Atlas/atlas-server
php artisan atlas:ai:session-bootstrap --task="ACOS Max: <lote/slice atual>" --json
bin/atlas open-brain context "ACOS Max <slice-id>" --json   # ou MCP atlas_context_pack
git status && git log --oneline -5                           # estado real da main
grep -n "<slice-id>" docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md  # localizar e LER o slice integral
```

4. Ler o placar de execução (seção 9) do último turno; retomar do primeiro slice não-terminal do lote corrente.
5. Se for slice novo de feature/superfície: `php artisan atlas:ai:place-feature "<descrição>" --json` antes de criar arquivo.
6. **Reler do disco qualquer arquivo compartilhado antes de editar** (routes/console.php, settings.json, config/atlas.php, docs de obra, o próprio plano) — o executor v1 está ativo; anchors de edit JÁ falharam por drift nesta obra. `grep` o trecho exato antes de todo Edit.

## 2. Regras pétreas do AMBIENTE (violação = parar imediatamente)

| # | Regra | Fonte |
|---|---|---|
| A1 | **Só branch local `main`:** `git branch --show-current` = `main` antes de todo commit; commits ESCOPADOS (`git add -- <só os arquivos do slice>`); NUNCA `git add -A`; NUNCA criar branch de obra; NUNCA merge/Pull-merge; se `MERGE_IN_PROGRESS` → `git merge --abort`; divergência `origin/main` ⇒ main local vence + pedir OK para `--force-with-lease` (nunca “resolver” com merge). Regra: `.cursor/rules/local-main-only.mdc` | memória pétrea + §ii.4 + operador 12/07 |
| A2 | `git push` SÓ com OK explícito do operador | tabela de gatilhos §viii |
| A3 | Família `atlas:loop:*` está MORTA — jamais operar/religar; os comandos vivos são `atlas:brain:*` / `atlas:task:*`; as 26 classes `AtlasLoop*` da keep-list não se deletam por prefixo (`rg --no-ignore -w <Classe>` antes de qualquer remoção) | CLAUDE.md pétreo |
| A4 | Flips de master (`ATLAS_AUTONOMOS_MASTER_ENABLED`, `ATLAS_AUTONOMOUS_AUTO_APPLY`, cadência, escala ≥3 workers, REC-04 shadow→atuar) são EXCLUSIVOS do operador — a IA entrega o checklist verde e NUNCA vira a chave | tabela de gatilhos §viii + §xiv |
| A5 | Testes NUNCA tocam o pgsql canônico @5433 (classe do incidente db-wiper); RefreshDatabase com guard de connection; migration destrutiva exige snapshot SUB-01 VERIFICADO antes | MAXN-01, ELEV-17 |
| A6 | `rg` em paths gitignored (storage/) exige `--no-ignore` | memória pétrea |
| A7 | Sondas/A-Bs/replays de retrieval SEMPRE em peek (`record_usage=false`) — nunca inflar RAG-01/MEM-03 | §ii.3 |
| A8 | Editar alvo compartilhado exige claim de blackboard quando disponível (ELEV-22); sem MCP, anunciar no placar | ELEV-22 |
| A9 | Reportar em PT-BR com placar a cada task concluída | memória do operador |
| A10 | phpunit JAMAIS escreve em ledger/série viva (guard ASI-05); se você criar JSONL novo, o guard nasce junto | ASI-05 |

## 2.1 TETO-06 — Protocolo multi-engine (execução paralela da obra)

Extensão de PROTOCOLO (playbook + scoreboard + blackboard). Não paraleliza dentro do mesmo slice nem dentro de família com deps encadeadas.

1. **Claim por FAMÍLIA×lote** via `AcosMaxParallelExecutionProtocol::claimFamily(engine, lote, family)` → target `acos-max:lote-N:family:FAMILY` no blackboard (`kind=task`).
2. **Scoreboard:** anote `claimed_by:<engine>` no item/família enquanto o claim estiver ativo; limpe no release após o landing da fatia/família.
3. **Arquivos-quentes** (lista ELEV-22: `routes/console.php`, `config/atlas.php`, `settings.json`, docs de obra) continuam serializados por claim de arquivo — paralelismo de família NÃO autoriza editar hot-file sem claim ELEV-22.
4. **Commits** continuam atômicos por slice (`git add -- <só os arquivos do slice> + scoreboard`).
5. **Gate de lote** só fecha com TODAS as famílias do lote em estado terminal.
6. **Caso negativo:** claim ativo de outro engine na mesma FAMÍLIA×lote ⇒ `action=skip` + `skip_reason=family_claimed_by_other_engine:<engine>` — o segundo engine pula e registra; NÃO rouba o claim.
7. **Proibido:** 2 engines no mesmo slice; 1 flip por família por janela continua valendo POR FAMÍLIA.

## 3. As LEIS do contrato (checklist aplicado a TODO slice — decorar)

| Lei | Enunciado operacional |
|---|---|
| **anti-Goodhart** | Nenhum aceite satisfazível por inatividade, n≤2, re-rotulagem ou amostra vazia; denominador mínimo REAL sempre exposto; sem massa ⇒ `insufficient_signal`, nunca verde |
| **MED-01** | Medidor congela ANTES do produtor; medidor v1/congelado NUNCA é editado — versiona-se (v2 ao lado, dual-read registrado) |
| **ROL-01** | Rollback/gatilho de reversão pré-declarado ANTES de qualquer flip |
| **ELEV-03** | Todo limiar numérico de aceite é carimbado no ledger no FREEZE, antes da 1ª linha do produtor |
| **ELEV-18** | Todo congelamento grava `{judge_engine_id, author_engine_id}` com `judge != author` assertado por teste |
| **ELEV-20** | Slice só fecha com ≥1 produtor E ≥1 consumidor com tráfego REAL provado no ledger |
| **ELEV-26** | Toda promoção default-OFF→shadow→live passa pelo PromotionProtocol único (ELEV-26s); 1 flip por família por janela |
| **ELEV-28** | A/B raiz negativo ⇒ família inteira `suspended_pending_evidence` (nunca deletada, nunca prossegue no vácuo) |
| **ELEV-31** | Toda evolução compete com {não fazer nada, simplificar, remover uma camada} — comparativo no receipt |
| **author≠judge** | Sinal DERIVADO da fonte, nunca setado pelo caller (lição SEV-1); quem constrói não certifica; LLM (mesmo local) só gerador/advisory default-OFF, nunca juiz/gate |
| **charter 06/07** | ZERO fila de aprovação humana no meio do fluxo; auto-aplicação reversível + etiqueta no Diário + digest; freios de máquina só apertam (safety-increasing), re-arm é do operador |
| **local-first / provider-neutro** | Espinha nunca depende de nuvem; modelos nomeados são implementação substituível (spec de capacidades ELEV-29); classes sensitive/secret/cyber nunca saem da máquina |
| **reuso** | Órgão existente (mesmo 0-callers) ⇒ o slice é WIRING, nunca reconstrução; fusão de órgãos é PROIBIDA (cerca anti-unificação ESP-06) |

## 4. Protocolo de execução de UM slice (o algoritmo)

1. **Localizar e ler o slice INTEIRO no plano** (Goal/Mecanismo/Aceite/Risco/deps/colisões da área).
2. **Verificar deps:** toda dep listada está `landed` (ou o slice declara degrade explícito)? Não ⇒ pular para o próximo slice elegível do lote e registrar `blocked_by:<dep>` no placar.
3. **Reler o código real** de todo file:linha citado (`rg`/Read). Divergência do plano ⇒ regra 0.2.
4. **Classificar o slice e aplicar o sub-protocolo:**
   - `[MEDIDOR]` → FREEZE primeiro: fórmula/limiar/denominador/ttl carimbados no Evidence Ledger com hash + judge≠author (ELEV-03/18); registrar a série no registry ELEV-20s; SÓ então implementar o leitor. Baseline dual-read se substitui leitura antiga.
   - `ATUADOR/INTELIGÊNCIA` → confirmar que a régua da família já está congelada; implementar atrás de flag default-OFF; shadow com denominador; promoção só via ELEV-26s.
   - `WIRING` → provar produtor+consumidor com tráfego real no aceite; zero órgão novo.
   - `FLIP` → você NUNCA flipa master (A4); prepara checklist verde + rollback ROL-01 e entrega ao operador no placar.
5. **Implementar** com o menor diff que satisfaz o aceite; comentários só para invariantes.
6. **Rodar o ACEITE COMPLETO**, incluindo o caso negativo (aceite sem caso negativo executado = slice não fechado). `php artisan test --filter=<suite>` + comandos `--json` do aceite.
7. **Evidência:** registrar no Evidence Ledger o que o aceite exigir (dual-read, hash, receipt); atualizar o ledger de gaps se abriu/fechou gap.
8. **Commit escopado** (A1) com mensagem `feat(acos-max): <SLICE-ID> <título curto>`; sync de conhecimento quando tocar docs/código público: `atlas engineering knowledge sync --prune` e `index-code --prune`.
9. **Placar** (seção 9). Estado terminal: `landed` | `suspended (ELEV-28)` | `refutado` (com o porquê e o registro — resultado negativo é entrega válida).

## 5. ORDEM MESTRA — 13 lotes com gates

> Regras da ordem: (a) dentro de cada lote, siga a "Ordem sugerida" da área no plano; (b) entre áreas do mesmo lote, qualquer ordem que respeite deps; (c) a lei de intercalação da §ix vale sempre — **todo `[MEDIDOR]` de uma área pousa e congela ANTES dos atuadores daquela área**, mesmo que a onda nominal seja tardia; (d) slice cujo aceite precisa de janela/volume que ainda não existe: implementa-se a régua/mecanismo AGORA e o aceite fica `pending_window` no placar (nunca verde antecipado).

### LOTE 0 — Higiene imediata (M0; zero colisão; começa HOJE)
`ESP-00` (reconciliação de números — pré-condição de TODO freeze de espinha) → `TETO-06` (protocolo de execução paralela — ANTES de qualquer engine começar) → `TETO-09` (veredito das áreas 2/11/14) → `MAXG-01-mínimo` (régua de latência ANTES do dedupe — ELEV-13) → `MAXE-02` → `MAXE-03` → `MAXF-01` → `MAXE-01` → `ELEV-22`.
**GATE L0:** hooks disparam 1×/evento (prova viva); baseline de latência carimbada; tabela de receipts existe no vivo com guard; refs canônicos impressos no markdown do pack; tabela ESP-00 publicada com toda divergência explicada.

### LOTE 1 — Religar os freios (F0)
`ASI-05` → `ASI-01` → `ASI-02` (+ELEV-08: 4 vias + varredura DB-level) → `MAXI-01` → `ASI-03` → `ASI-04` → `ESP-01` → `MAXK-07` (+ELEV-09 sentinelas) → `MAXL-01` → `MAXL-02` (+ELEV-11 âncora git) → `MAXN-01` → `ELEV-17` → `TETO-05` (obra-retro: liga a cadência de fecho-de-lote daqui em diante).
**GATE F0:** porta única em observe com relatório por writer; ledgers imunes a phpunit (hash antes/depois da suíte idêntico); `event_hash` gravando + cadeia verificável + âncora git; captura do operador viva (>0 sinais reais); restore drill verde; attempts com estado terminal; emendas de floor só via registry/sentinela.

### LOTE 2 — Congelar TODAS as réguas v2 (M1 + medidores antecipados)
`ELEV-02` (fórmula de M — BLOQUEIA os flips de F1) · `ELEV-12` · `ELEV-20s` · `ELEV-25` · `MAXG-01` completo · `MAXG-02` · `MAXA-03` · `MAXB-02` · `MAXG-04` (golden v2, `targets_available==cases`) · `MAXH-01` · `MAXI-02` · `MAXI-03` · `MAXJ-01` · `MAXJ-05` · `MAXK-01` · `MAXK-04` · `MAXL-06` · `MULTK-01` · `MULTN15-01` · `MULTN15-02` · `MULTN17-04` (freeze) · `MULTX-01` (freeze da definição de volta) · `MULTX-06` (freeze) · `MULTJ-01/02/03` (réguas de aprendizado — antecipadas pela lei de intercalação) · `TETO-02` (mission_e2e — a métrica-fim da tese, congela junto das réguas).
**GATE M1:** todo freeze com hash + judge_engine_id no ledger; golden v2 com alvos 100% resolvíveis; registry de séries populado; ZERO produtor de área começou antes da régua da área.

### LOTE 3 — Eficiência estrutural + protocolo (M2)
`ELEV-26s` (PromotionProtocol — antes de qualquer flag nova) → `MULTX-09` (DAG de janelas) → `MAXA-02` → `MAXA-07` → `MAXA-01` → `MAXA-10` → `MAXB-01` → `MAXE-04` → `MAXE-05` → `MAXB-09` → `MAXG-09` → `MAXG-10` (+ELEV-16 floor intermediário) → `MAXH-03` → `MAXK-05` → `MAXK-06` → `MAXM-05` → `MAXM-08` → `MAXN-03` (scaffold) → `MULTN15-06` → `ESP-02` → `ESP-03` → `ESP-05` → `ELEV-19` → `ELEV-24` → `ELEV-27` → `ELEV-29s` → `TETO-08` (cockpit read-only; fontes futuras = `unavailable` honesto).
**GATE M2:** pack p95 e hooks sob o floor intermediário (ELEV-16), medidos pelo ledger MAXG-01; toda flag nova no PromotionProtocol; assinaturas da escada wiradas na mutação real (:356); daemon de embeddings vivo com manifest sha256.

### LOTE 4 — Ligar o fluxo (F1; flips = OPERADOR)
`MAXC-01` → `MAXC-02` → `MAXC-06` → `ASI-06` (preflight 8/8 verde → **entregar ao operador**) → `ASI-07` (floor E2E provado → **entregar ao operador**) → `ASI-08` → `ASI-09` → aguardar/verificar v1 onda 5 (`ENG-13/14/15, PIP-07, CPT-10, ADV-01` — executor v1; NÃO implementar, só checar estado) → `MULTV-10` (o seam do land, default-OFF) → `ASI-10` (janela shadow + flip via ELEV-26s).
**GATE F1-fluxo:** outcomes reais ≥5/dia por 7d fluindo pela espinha; decision_id em 100% dos landings (ELEV-15); reflection/pattern-ledger com dados reais; distiller de-template em shadow com dual-read.

### LOTE 5 — Corpus e cobertura (M3)
`ELEV-21` (aceleração de corpus ≥300 via porta) · `MAXA-05` (após MEM-05 do v1) · `MAXA-06` fase 1 · `MAXD-01` · `MAXD-09` · `MAXD-06` · `MAXD-08` · `MAXD-02` · `MAXH-02` · `MAXI-04` · `MAXM-01` · `RAGX-01` · `TETO-01` (N-Capture Drill — 1ª execução nesta janela).
**GATE M3:** corpus ativo qualificado ≥300 (contagem NUNCA é aceite — a taxa de aprovação da porta é); 100% dos vetores com provenance; golden v2 medindo contra o vivo com recall > 0.

### LOTE 6 — VERTICAL 1 (o subset mínimo M4/M5 + o MARCO)
Implementar SÓ o necessário para os 12 passos da §xiii: `MAXK-04✓` + `MULTK-01✓` (decisão) · `MAXN-04` + `MULTN17-03` (originação por yield) · `ESP-09` (challenger) · `ESP-04` (receipt canônico) · `ESP-07` (treatment-before-outcome) · `MAXJ-02` (caused_by) · `MULTJ-03` (contrafactual) · `MAXH-04` + `MAXH-05` (consolidação+desconto) · `ESP-08` (busca negativa) · `MULTX-01` série cheia.
**GATE F1→F2 = MARCO ESP-V1:** `atlas:flywheel:loops --json` reporta `loops_complete ≥ 1` com definição válida (outcome proven_real, ids encadeados verificáveis, zero fixture). **F2 NÃO abre sem este marco.**

### LOTE 7 — M4 completo (atuadores e inteligência, por área na ordem do plano)
*Ordem ENTRE áreas dentro do lote: `MULTX-03` primeiro (o contrato de outcome v2 — vários denominadores do lote dependem dele), depois `MAXL-03/04` (evidência); o resto em qualquer ordem que respeite deps. Lembrete vi-b: MAXA-08 fecha no landing do MAXB-03.*
MAXA: `04→08` · MAXB: `03→04→05→06→07→10` · MAXC: `03/05→04` · MAXD: `03→04→07` · MAXE: `08→06→07` · MAXF: `02→03→08→10→04→06→07→05` · MAXH: `06` · MAXI: `05→06→07→08` · MAXJ: `03→04→06` · MAXK: `02→03` · MAXL: `03→04→05→07→08` · MAXM: `02→03→04→06→07` · MAXN: `02→03(fecho)→05→06` · RAGX: `08` · MULTK: `03→04→02` · MULTN15: `03→04→07` · MULTN17: `07→01→08` · MULTH: `01→02→07→03` · MULTV: `01→07→05` · MULTX: `03→02→04→06(série)` · ESP: `06` · TETO: `03` (Trajectory Vault — o coletor liga assim que ESP-04 landar).
**GATE M4:** measured_share > 0 e subindo; funil MULTX-02 com os 3 executores em den>0; receipt de verificação MULTV-01 acumulando; nenhum atuador ligado sem shadow registrado.

### LOTE 8 — Eixo reflexivo (F2)
`ASI-11` (completo, com escala ≥50 — ELEV-10/23) → `ASI-12` → `ASI-13` → `ASI-14` → `ASI-15` → `MAXK-08`.
**GATE F2:** cascata de 50 revertida com estados formais + SLA medido; séries multi-ator vivas; decision receipt com self_model + banda calibrada.

### LOTE 9 — Certificação + espinhas completas (M5)
`MAXG-03→05→07→06` · `MAXB-08` · `MAXH-07→08→09→10` · `MAXI-09` · `MAXJ-07→08` · `MAXK-09` · `MAXL-09` · `MULTV-02→03→09` · `MULTK-05→07` · `MULTN15-05` · `MULTN17-02` · `MULTH-04→05→06` · `MULTX-05` · `ESP-10→11→12` · `REC-01→03→05→02` · `TETO-10` (digest como produto de revisão).
**GATE M5:** certificadores re-provados (ADV-Max); espinhas 1-3 com critérios pétreos da §xiii verdes; cascata MULTV-02 em advisory com ≥30 execuções.

### LOTE 10 — Fronteira condicional (M6; cada um gated pela dep de evidência)
`RAGX-07→06→03→11→05→02→10→09→04` (na ordem da §x; 04 é candidato-a-corte declarado) · `MAXA-09` · `MAXC-07` (condicional) · `MAXD-05` (gated MAXA-06) · `MAXF-09→11` (11 só pós-enforce CPT-09) · `MULTJ-04→05→06→07→08→09` (09 nasce suspenso ELEV-28) · `MULTV-04→08→06` · `MULTK-06→08` · `MULTN15-08` · `MULTN17-05→06` · `MULTH-08` · `MULTX-07→08` · `REC-04` (SHADOW estrito) · `REC-06` · `TETO-04` (2º domínio, gated MARCO ESP-V1) · `TETO-07` (model-refresh drill).
**GATE M6:** todo slice condicional com A/B registrado (positivo ⇒ live; negativo ⇒ suspenso/refutado COM REGISTRO — os dois são sucesso); REC-04 gerando hipóteses em shadow com evidência de funil.

### LOTE 11 — Substrato 10-100× (F3)
`ASI-16` → `ASI-17` → `ASI-18` → `MAXA-06` fase 2.
**GATE F3:** pack p95 ≤2s / recall ≤1s / hooks ≤5s sustentados 14d pelo ledger; bancada de 12 clientes com p95 e shed provados.

### LOTE 12 — Fecho (o mesmo corte)
Rodar TODOS os critérios: §vii 1-10 + §viii 11-17 + pétreos §xiii — verdes NO MESMO CORTE, hashes carimbados. `MAXG-08`+`MAXL-10` (Marco Zero v2 — gatilho: veredito ADV-01). Entregar ao operador o checklist do flip REC-04 (M>1, R>0, freios verdes) — **a chave é dele**.

### 5.5 INVENTÁRIO COMPLETO — os 245 slices → lote (a prova de cobertura 100%)

> Este inventário é a AUTORIDADE de atribuição slice→lote (as listas narrativas dos lotes acima derivam dele; divergência entre lista e inventário ⇒ inventário vence). **100% = toda célula desta tabela com estado terminal no scoreboard.** Slices com 2 lotes (freeze/série, scaffold/fecho, fase 1/2) contam terminal só quando as DUAS partes fecham.

| Família | Slice → Lote |
|---|---|
| **MAXA** (10) | 01→L3 · 02→L3 · 03→L2 · 04→L7 · 05→L5 · 06(f1)→L5 + 06(f2)→L11 · 07→L3 · 08→L7 · 09→L10 · 10→L3 |
| **MAXB** (10) | 01→L3 · 02→L2 · 03→L7 · 04→L7 · 05→L7 · 06→L7 · 07→L7 · 08→L9 · 09→L3 · 10→L7 (pode ser absorvido pelo 03 — decisão registrada no placar) |
| **MAXC** (7) | 01→L4 · 02→L4 · 03→L7 · 04→L7 · 05→L7 · 06→L4 · 07→L10 (condicional; corte declarado) |
| **MAXD** (9) | 01→L5 · 02→L5 · 03→L7 · 04→L7 · 05→L10 (gated MAXA-06) · 06→L5 · 07→L7 · 08→L5 · 09→L5 |
| **MAXE** (8) | 01→L0 · 02→L0 · 03→L0 · 04→L3 · 05→L3 · 06→L7 · 07→L7 · 08→L7 |
| **MAXF** (11) | 01→L0 · 02→L7 · 03→L7 · 04→L7 · 05→L7 · 06→L7 · 07→L7 · 08→L7 · 09→L10 · 10→L7 · 11→L10 (SÓ pós-enforce CPT-09) |
| **MAXG** (10) | 01→L0(mín)+L2(completo) · 02→L2 · 03→L9 · 04→L2 · 05→L9 · 06→L9 · 07→L9 · 08→L12 · 09→L3 · 10→L3 |
| **ASI** (18) | 01→L1 · 02→L1 · 03→L1 · 04→L1 · 05→L1 · 06→L4 · 07→L4 · 08→L4 · 09→L4 · 10→L4 (flip pós-MULTV-10, janela própria) · 11→L8 (movimento 1 = linhagem JÁ em L4 via ELEV-15) · 12→L8 · 13→L8 · 14→L8 · 15→L8 · 16→L11 · 17→L11 · 18→L11 |
| **MAXH** (10) | 01→L2 · 02→L5 · 03→L3 · 04→L6 · 05→L6 · 06→L7 · 07→L9 · 08→L9 · 09→L9 · 10→L9 |
| **MAXI** (9) | 01→L1 · 02→L2 · 03→L2 · 04→L5 · 05→L7 · 06→L7 · 07→L7 · 08→L7 · 09→L9 |
| **MAXJ** (8) | 01→L2 · 02→L6 · 03→L7 · 04→L7 · 05→L2 · 06→L7 · 07→L9 · 08→L9 |
| **MAXK** (9) | 01→L2 · 02→L7 · 03→L7 · 04→L2 · 05→L3 · 06→L3 · 07→L1 · 08→L8 · 09→L9 |
| **MAXL** (10) | 01→L1 · 02→L1 · 03→L7 · 04→L7 · 05→L7 · 06→L2 · 07→L7 · 08→L7 · 09→L9 · 10→L12 |
| **MAXM** (8) | 01→L5 · 02→L7 · 03→L7 · 04→L7 · 05→L3 · 06→L7 · 07→L7 · 08→L3 |
| **MAXN** (6) | 01→L1 · 02→L7 · 03→L3(scaffold)+L7(fecho) · 04→L6 · 05→L7 · 06→L7 |
| **RAGX** (11) | 01→L5 · 02→L10 · 03→L10 · 04→L10 (candidato-a-corte declarado) · 05→L10 · 06→L10 · 07→L10 · 08→L7 · 09→L10 · 10→L10 · 11→L10 |
| **ELEV novos** (12) | 02→L2 · 12→L2 · 17→L1 · 19→L3 · 20s→L2 · 21→L5 · 22→L0 · 24→L3 · 25→L2 · 26s→L3 · 27→L3 · 29s→L3 — (ELEV-16 não é slice: é o floor intermediário DENTRO do MAXG-10) |
| **MULTJ** (9) | 01→L2 · 02→L2 · 03→L2 (réguas antecipadas pela lei de intercalação) · 04→L10 · 05→L10 · 06→L10 · 07→L10 · 08→L10 · 09→L10 (nasce `suspended` ELEV-28) |
| **MULTK** (8) | 01→L2 · 02→L7 · 03→L7 · 04→L7 · 05→L9 · 06→L10 · 07→L9 · 08→L10 |
| **MULTH** (8) | 01→L7 · 02→L7 · 03→L7 · 04→L9 · 05→L9 · 06→L9 · 07→L7 · 08→L10 |
| **MULTN17** (8) | 01→L7 · 02→L9 · 03→L6 · 04→L2(freeze)+L7(curva) · 05→L10 · 06→L10 · 07→L7 · 08→L7 |
| **MULTN15** (8) | 01→L2 · 02→L2 · 03→L7 · 04→L7 · 05→L9 · 06→L3 · 07→L7 · 08→L10 |
| **MULTV** (10) | 01→L7 · 02→L9 · 03→L9 · 04→L10 · 05→L7 · 06→L10 · 07→L7 · 08→L10 · 09→L9 · 10→L4(seam; o flip é do ASI-10) |
| **MULTX** (9) | 01→L2(freeze)+L6(série cheia) · 02→L7 · 03→L7 · 04→L7 · 05→L9 · 06→L2(freeze)+L7(série) · 07→L10 · 08→L10 · 09→L3 |
| **ESP** (13 + marco) | 00→L0 · 01→L1 · 02→L3 · 03→L3 · 04→L6 · 05→L3 · 06→L7 · 07→L6 · 08→L6 · 09→L6 · 10→L9 · 11→L9 · 12→L9 · **MARCO ESP-V1→L6 (gate F1→F2)** |
| **REC** (6) | 01→L9 · 02→L9 · 03→L9 · 04→L10(shadow)+L12(flip = OPERADOR) · 05→L9 · 06→L10 |
| **TETO** (10) | 01→L5 · 02→L2 · 03→L7 · 04→L10 (gated MARCO ESP-V1) · 05→L1 (cadência por fecho de lote) · 06→L0 · 07→L10 · 08→L3 · 09→L0 · 10→L9 |

**Soma de verificação:** 10+10+7+9+8+11+10 (MAXA-G=65) + 18 (ASI) + 10+9+8+9+10+8+6 (MAXH-N=60) + 11 (RAGX) + 12 (ELEV) + 9+8+8+8+8+10+9 (MULT=60) + 13 (ESP) + 6 (REC) + 10 (TETO) = **255**. Se você (IA executora) contar diferente ao auditar: pare, registre o gap, reconcilie antes de prosseguir.

## 6. Janelas e relógio (o recurso escasso)

- Toda janela (observe/shadow/sustain/soak) nasce declarada no PromotionProtocol (ELEV-26s) e aparece no DAG do MULTX-09 — janela fora do protocolo é gap.
- **1 flip por família por janela** (atribuição limpa). Janelas de famílias independentes RODAM EM PARALELO — verificar o DAG antes de esperar em série.
- Janela correndo sem dado ⇒ o watchdog de janela-morta alerta; não deixe semanas expirarem vazias.
- Enquanto uma janela corre, implemente slices de OUTRO lote/família que não a contaminem. O playbook nunca manda "esperar parado".

## 7. O que a IA NUNCA faz (gatilhos exclusivos do operador — §viii + §xiv)

Ligar músculo Autônomos · flip do auto-apply · cadência hourly/event-driven · escala ≥3 workers · `git push` · flip REC-04 shadow→atuar · re-alargar qualquer envelope/ceiling apertado por máquina · revogar a memória implement-only. **Entregar sempre o checklist verde + rollback pré-declarado, e PARAR.**

## 8. Quando algo dá errado

- **Aceite não passa:** não afrouxar; diagnosticar; se o mecanismo do plano está errado contra o código, regra 0.2 (gap + espírito do aceite).
- **Dep de outro executor (v1) pendente:** registrar `blocked_by`, seguir para o próximo elegível.
- **Colisão de arquivo com o executor v1:** reler do disco, edit pontual, commit imediato; se conflito real, parar e reportar.
- **Resultado negativo (A/B sem ganho):** REGISTRAR e suspender a família (ELEV-28) — isso é entrega, não falha.
- **Freio dispara (breaker, gate, quarentena):** nunca contornar; o disparo é sinal — vai para o digest.

## 9. Placar e cadência de report (PT-BR, a cada task)

Formato por slice concluído:
```
🏁 <SLICE-ID> — <título curto>
Estado: landed | suspended(ELEV-28) | refutado | blocked_by:<dep> | pending_window:<janela>
Aceite: <comando(s) rodados> ✅/❌ (incl. caso negativo)
Evidência: <hash/receipt/dual-read no ledger>
Commit: <sha curto> (escopado: <arquivos>)
Próximo: <slice-id>
Lote: <n> — <x/y slices terminais> | Gates verdes: <lista>
```
A cada 3 slices: mini-resumo do lote + qualquer flip pendente do operador. Ao fechar um LOTE: checklist do gate, item a item, com evidência.

**SCOREBOARD DURÁVEL (obrigatório — a memória entre sessões e entre IAs):** JÁ EXISTE em `docs/engineering-knowledge-base/atlas-acos-max-execution-scoreboard-v1.md` (245/245 em `pending`, organizado por lote com os gates), derivado do inventário 5.5, com estado por slice (`pending | in_progress | landed(<sha>) | suspended(ELEV-28) | refutado | blocked_by:<dep> | pending_window:<janela>`). TODA mudança de estado atualiza o scoreboard NO MESMO commit do slice. Uma IA nova retoma a execução lendo APENAS: plano + playbook + scoreboard — zero dependência de conversa anterior. O scoreboard nunca reordena lotes nem edita aceites: é estado, não spec.

## 10. Armadilhas conhecidas (gotchas — decorar antes do Lote 0)

1. **Golden v1 mede ZERO** (25/25 alvos ausentes) — "≥ baseline" contra ele é 0≥0; toda régua de A/B é o golden v2 (ELEV-01).
2. **`verified` defaulta true quando passed** no recorder (`AtlasEngineeringOutcomeRecorder.php:255`) — não confiar em `verified` pré-MULTX-03.
3. **`eventIntegrityValid()` só checa formato hex** — integridade real = recompute + `hash_equals` (MAXL-01).
4. **O delivered-pack ledger faz rewrite O(N) por append** — não pendurar volume nele antes de MAXE-04/05.
5. **`AtlasDeliveredPackLedger`/COM-01 é o ÚNICO namespace de refs** — nunca criar formato paralelo.
6. **Anchors de Edit no plano falham por drift** (executor v1 ativo) — grep o trecho exato imediatamente antes de todo Edit.
7. **9/169 notes têm metadata de provider fake** (`local-hash-v1`) — stamp por linha não é confiável até MAXA-03.
8. **O EWMA de yield é accept-cru** (`PathYieldEwma.php:40`) — proven_real só após o re-anchor do MAXN-04.
9. **Migrations "Ran" podem apontar para tabelas INEXISTENTES** (pós-wiper) — `Schema::hasTable` antes de confiar no ledger de migrations.
10. **`context_sufficiency` é campo APOSENTADO** (o "92") — nenhum writer novo, jamais.
11. **Compactação de conversa é net-negativa em threads pequenas** (5/6 expandiram) — MAXF-10 antes de confiar em ratio.
12. **O braço `semantic_notes` entra no recall SEM imunidade** até ASI-02+ELEV-08 — não tratar notes como confiáveis antes disso.
13. **SLICES GÊMEOS (o plano tem duplicações cross-área resolvidas na tabela vi-b do plano):** MAXB-01 = MAXA-01+02 (daemon/memo); MAXB-02 = MAXG-04 (golden v2); MAXA-08 = MAXB-03 (tsvector+RRF); MAXE-04 harmonizado com MAXG-01 (latência); o seam de rerank L3-6 tem UM orquestrador (RAGX-05) com MAXB-06/MAXA-09 como estágios. **UM landing fecha os gêmeos (aceites de ambos rodam nele; mesmo sha no scoreboard). Implementar um gêmeo separado = violação de reuso.** Antes de criar {daemon, cache, coluna de índice, série de latência, golden, orquestrador, watchdog}: consultar vi-b + registry ELEV-20s.

## 11. Dependências EXTERNAS — o que pertence ao executor v1 (você NÃO implementa; você CHECA)

> O plano v1 (`atlas-acos-excellence-10-10-plan-v1.md`, 98 slices) tem executor próprio rodando na main. Slices Max citam deps v1 pelo ID. Regra: **nunca implementar um slice v1**; checar o estado no doc de progresso do v1 (`rg "<ID>" docs/engineering-knowledge-base/ -l` + progress doc da obra v1) e no código; pendente ⇒ `blocked_by:<ID>` no scoreboard e seguir para o próximo elegível.

| Dep v1 | O que é (1 linha) | Consumida por (exemplos) |
|---|---|---|
| MED-01 | Procedimento de leitura dupla ao trocar medidor | todo `[MEDIDOR]` |
| ROL-01 | Rollback/gatilho pré-declarado antes de flip | todo flip/atuador |
| ADV-01 | Re-prova adversarial externa dos certificadores | MAXG-08, MAXL-10, L12 |
| WDG-01 | Registry único de checks (watchdog) — em voo na working tree | todos os plugins de check |
| RAG-05 | Golden set v1 CONGELADO (mede 0.0 — NUNCA é régua de A/B, só regressão de floor) | ELEV-01 |
| COM-01..11 | Ledger de pack entregue, refs canônicos, utility explícita, políticas measured-only | MAXE, MULTX-01/04, ARFL |
| MEM-02/03/05 | Curadoria, sensor de concentração 45d (congelado), re-hidratação de texto | MAXH, MAXA-04/05 |
| CORP-01 | Crescimento de corpus por admissão gated | ELEV-21, golden v2 |
| FEE-03/04/11/12/13 | Floors de feedback, flip do ranking, digest semanal, lift | MAXJ, MULTJ, ELEV-25 |
| OUTC-01 | Espinha de outcomes dos 3 executores | MULTX, ESP, MAXJ, MAXK |
| EVI-01/04/05/06/07/09 | Ledger de gaps, delta-series, anti-backfill, gate longitudinal | MAXL, MAXG-08 |
| ENG-13/14/15 + PIP-07 + CPT-09/10 | Flips de verificação/certificação do v1 (onda 5) | ASI-10, MAXF-11, L4 |
| SUB-01 | Snapshots verificáveis pré-mudança destrutiva | MAXN-01, ELEV-17, MULTV-04 |
| VOL-01 / OPE-05/07 | Volume/preflight do músculo; telemetria MCP | ASI-06, MAXM-07/08 |

## 12. Glossário mínimo (siglas que o plano usa sem re-explicar)

| Sigla | Significado |
|---|---|
| ACOS | Atlas Cognition Operating System — a camada cognitiva (18 áreas) |
| AOBG | Atlas Open Brain Gateway — hooks + MCP + context pack |
| pack / packFor | O contexto composto injetado por turno (`AtlasOpenBrainContextPackService`) |
| AURG | Grafo de realidade unificado (nós/arestas provider-safe) |
| ARFL | Retrieval Feedback Loop — eventos used/noise/missed por pack entregue |
| COM-01 ledger | `delivered-pack-ledger.jsonl` — a verdade do que foi entregue |
| AVCEL / ACQCG | Execução-verificada (shadow estrutural) / certificação de qualidade de contexto |
| AEMOR / ADML | Outcomes de execução de engenharia / feedback vivo do Atlas Decide (`live_outcomes.jsonl`) |
| AUCRI | Enforcement de contexto pré-provider (18 blocos) |
| OUTC-01 espinha | O recorder único de outcomes (`AtlasEngineeringOutcomeRecorder`) |
| G0–G8 | Gates da imunidade cognitiva na admissão de memória |
| R8 / golden set | Corpus de precisão independente / set congelado de recall com juiz |
| peek | Consulta com `record_usage=false` — não infla séries de uso |
| dual-read | Registrar valor antigo E novo ao trocar medidor (MED-01) |
| seed-gate / brain / task loop | Autônomos VIVO: `atlas:brain:next|seed` origina, `atlas:task:*` serve, commit escopado na main |
| digest | Revisão-DEPOIS semanal do operador (FEE-12) — nunca fila de aprovação |
| charter 06/07 | Autonomia com reversibilidade como única salvaguarda; masters = operador |
| pétreo | Regra que nenhum slice pode relaxar; violação refuta o slice |
| observe→shadow→live | Escada de promoção (sempre via PromotionProtocol ELEV-26s) |
| freeze | Carimbo no Evidence Ledger de fórmula/limiar/denominador ANTES do produtor (receita na §0.6) |

### 12.1 Notação dos slices (como LER um cabeçalho de slice)

| Notação | Significado |
|---|---|
| `E:S / E:M / E:L` (e combinações `S/M`, `M/L`) | Esforço estimado: Small (horas) / Medium (~1 dia) / Large (dias). NUNCA é licença para cortar aceite |
| `onda M0..M6 / F0..F3` | Posição no plano — traduzir para lote pela tabela §0.8; inventário 5.5 vence |
| `deps: [ids]` | Pré-condições. ID Max ⇒ checar scoreboard; ID v1 ⇒ tabela §11 (você só CHECA); "✅" no plano = já landado na época da escrita (re-verificar mesmo assim) |
| `[MEDIDOR]` | Slice de régua: FREEZE primeiro (§0.6), read-only, série versionada, nunca muda seleção/comportamento |
| `[CERTIFICADOR]` | Gate que passa a decidir pass/block — sempre nasce advisory/OFF e promove via ELEV-26s |
| `[ATUADOR]` | Muda comportamento vivo — flag default-OFF → shadow → live, rollback ROL-01 pré-declarado |
| `[WIRING]` | Liga órgão existente (produtor↔consumidor) — proibido reconstruir o órgão |
| `[pétreo]` | Invariante inegociável — violação refuta o slice inteiro |
| `[DECISÃO] [GOV] [CARTÓRIO] [ALAVANCA] [HIGIENE] [TRANSPARÊNCIA] [ADVISORY]` | Rótulos informativos de natureza (área 10, governança, registro-sem-decisão, maior impacto, limpeza, auditabilidade, sinal-sem-veto) — não mudam o protocolo, ajudam a priorizar |
| Sufixo `s` (ELEV-20s, ELEV-26s, ELEV-29s) | O SLICE que implementa a LEI de mesmo número (ELEV-20 = lei "produtor+consumidor"; ELEV-20s = o watchdog de série-morta que a materializa) |
| `shadow` | Modo em que o mecanismo COMPUTA e REGISTRA mas não altera nada servido/persistido no caminho vivo |
| `flag default-OFF` | Config nova nasce desligada; OFF tem que ser byte-idêntico ao comportamento anterior (e isso é TESTADO) |
| `byte-idêntico` | Saída comparável literalmente igual (diff vazio ignorando timestamps) — o teste de paridade padrão |
| `fixture` | Dado sintético de teste (phpunit) — NUNCA conta como denominador "real" de aceite vivo (guard ASI-05) |
| `seam` | Ponto de costura existente no código onde um comportamento novo se pluga sem reescrever o órgão |
| `caso negativo` | A metade do aceite que prova que o mecanismo RECUSA/detecta o input errado — sem ele o slice não fecha |
| `verde vácuo` | Aceite "passando" por ausência de dados/atividade — proibido; o estado honesto é `insufficient_signal` |
| `dual-read` | Publicar valor antigo E novo lado a lado ao trocar/versionar medidor (MED-01) |
| `receipt` | Registro estruturado e auditável de uma ação/decisão (idealmente selado no Evidence Ledger) |
| `landing` | Commit escopado que pousa na main local |

---

*Gerado em 12/07/2026 como projeção executável do plano ACOS Max (seções i–xv, 255 slices). Regenerar/atualizar este playbook sempre que o plano ganhar seção nova — e nunca o contrário. Cobertura auditável: inventário 5.5 (255/255) + scoreboard durável (seção 9).*
