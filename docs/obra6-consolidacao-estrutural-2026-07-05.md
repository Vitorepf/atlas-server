# OBRA #6 — Consolidação Estrutural do Núcleo (AAEOS + ACOS + memória/contexto/inteligência)

**Data:** 2026-07-05 · **Status:** spec da campanha (referência congelada do /goal)
**Antecedentes:** limpeza-bruta (−86k, 05/07), Obras #1-#5 entregues, campanha Documentação Canônica Verdadeira (05/07). Este é o passo seguinte: não é cortar massa morta (veia exaurida) — é **abstrair, simplificar e solidificar a lógica viva** com qualidade garantida por construção.

## Missão
Consolidar o núcleo (app/Services/Ai/* + app/Services/Engineering/, hoje **4.941 arquivos / 1,45M linhas**) em 6 frentes medidas, na ordem que impede regressão (produtor primeiro), com prova de comportamento preservado a cada lote e re-medição final. Trabalho 100% completo = todas as frentes fechadas OU declaradas honestamente com a prova de por que não fecham.

## Baseline medido em 05/07/2026 (re-medir ao final, mesmos params)
- jscpd `app/Services/Ai` (min-tokens 100): **4,18%** (53.318 linhas dup / 3.966 clones).
- Certifiers B_STATE no `CertifierClassificationLedger`: **35** no padrão antigo; motor único `Support/StateCertificationEngine` + 3 pilotos migrados (relatório byte-compatível, −25%+ linhas).
- `Aaeos/Generated/`: **309 arquivos / 133.069 linhas** — projeção regenerável, 100% consumida; dedup SÓ no gerador.
- Micro-classes atômicas (`*Scorer|Classifier|Detector|Evaluator|Decider|Verdict|Validator|Gate|Checker|Resolver`): **505 arquivos**, ~105 linhas/classe; amostra 0-ref SelfConstruction: **12%**.
- Gate F0 (`AtlasTaskDuplicateReuseGate`, enforce): bloqueia CLASSE duplicada; NÃO bloqueia lógica quase-duplicada nem organ sem caller.
- Padrão policy-única provado: `MemoryQualityStatusPolicy` / `CompactionLossPolicy` / `LocalPrereasoningPolicy` (wrapper fino por superfície).

## Frentes (nesta ordem — V0 primeiro, senão é Sísifo)

**V0 — Gate de admissão v2 (o multiplicador; consertar o produtor).** Estender a admissão das entregas autônomas (`SelfConstruction/TaskQuality/`, caminho do `AtlasTaskServingService::report()` — NUNCA no EngineeringKernel):
1. *Reuse-first de lógica:* censo de métodos por hash normalizado (padrão do censo da limpeza F3) no gate — método novo ≥30 linhas byte-igual/quase-igual a existente → blocker nomeado `duplicate_logic_blocked` exigindo reuso/extração.
2. *Wired-or-tagged:* classe nova sem caller real no mesmo diff → só entra com marcador explícito `unwired_until=<data>` no docblock; gate registra e re-verifica expiração (observação → blocker após data).
3. Modo `observe` default (colher 1 semana de dados), flip para `enforce` é decisão do operador — MAS o mecanismo nasce completo e testado (wiper-safe, SQLite :memory:).
**AC-V0:** teste prova blocker em clone sintético ≥30 linhas; teste prova aceitação de classe wired e de unwired tagueada; teste prova observação registrada; zero falso-positivo nos últimos 20 packets reais (replay).

**V1 — B_STATE → motor único (fechar Obra #5 S2).** Migrar os 35 auditores B_STATE para definições finas de checks no `StateCertificationEngine`, no padrão exato dos 3 pilotos (snapshot byte-compatível POR migração; chaves e ordem preservadas — certification_hash cobre payload).
**AC-V1:** 35/35 consomem o motor (grep prova); LOC agregado dos B_STATE cai ≥30% (medido); suíte de cada área verde antes/depois; ledger continua 100% coberto (teste anti-drift já existe).

**V2 — Estoque de clones 4,18% → <2%.** Censo jscpd → famílias ordenadas por linhas; uma família por commit (extração trait/policy, hash-verify antes, classe-vence-trait nas variantes); famílias já semeadas na esteira contam se o worker entregar — senão moer à mão como F3.
**AC-V2:** jscpd re-medido <2% OU relatório honesto família-a-família do estoque restante com a razão pétrea (variante legítima ≠ clone; áreas vetadas).

**V3 — Dedup no GERADOR de `Aaeos/Generated/`.** Slice de descoberta primeiro: localizar o(s) gerador(es) e o template; medir duplicação interna dos 309 emitidos; abstrair NO EMISSOR (helpers/base no template) e RE-GERAR a projeção inteira; provar consumo intacto (suíte + grep dos consumidores).
**AC-V3:** projeção re-gerada com −≥15k linhas OU prova de que o gerador não comporta (com números); ZERO edição manual em Generated/.

**V4 — Wire-or-retire das micro-classes (anti-Goodhart explícito).** NÃO fundir classes puras para reduzir contagem — isso é proxy. O trabalho: censo 0-ref das 505 (determinístico, com preflight completo por candidata — bindings, class_alias, Finder/reflection, FQCN-string, schedule, artisan, git log, testes); cada unwired → (a) religar com caller real + teste de integração, (b) aposentar com preflight, ou (c) tag `unwired_until` (V0 passa a vigiar). Famílias do MESMO domínio com política sobreposta (N scorers de memória, N policies de contexto) → policy module compartilhado no padrão provado, wrapper fino por superfície, snapshot por migração.
**AC-V4:** amostra 0-ref (200 aleatórios SelfConstruction, mesma metodologia) 12% → ≤5%; cada retire com preflight colado no commit.

**V5 — Camada cognitiva (Memory/Context/Aucri/ACOS).** Aplicar V4 na camada de memória/contexto: mapear famílias de política duplicada (recall/staleness/compaction/quality), consolidar em policies compartilhadas; NUNCA tocar semântica de hash/receipt (veto SORT_STRING permanece).
**AC-V5:** ≥3 famílias cognitivas consolidadas com snapshot; zero mudança de hash em receipt/replay (testes de replay verdes).

**V6 — Prova final + docs + sync.** Re-medir TUDO do baseline (mesmos params); atualizar os docs canônicos afetados (gap-matrix row de certifiers, obra5 spec, implementation-reality) no MESMO commit da mudança de código correspondente (código e doc movem juntos); `atlas engineering knowledge sync --prune` + `index-code --prune`; placar antes/depois completo.

## Regras pétreas (violar qualquer uma = parar e reportar)
1. **Vetos com refutação registrada:** NÃO re-propor fusão dos executores Dev/Forge/Autonomos (o que é único é o JUIZ — já existe); NÃO tocar `EngineeringKernel/` core (só adapters, e só se obra exigir); NÃO convergir variantes de sort que alimentam hashes (SORT_STRING); NÃO editar `Generated/` à mão; NÃO tocar código/docs da esteira de Medição.
2. **Comportamento preservado = provado:** snapshot byte-compatível por migração; suíte da área verde ANTES e DEPOIS de cada lote; wiper-safe (SQLite :memory:, jamais RefreshDatabase em pgsql).
3. **Deletion-preflight completo** antes de QUALQUER retire (a lição: organ 0-ref pode estar esperando wiring).
4. **Anti-Goodhart pareado:** métrica nunca é só linhas/arquivos — sempre PAREADA com (suíte verde + zero caller quebrado + jscpd caindo + zero mudança de hash). Fundir classe pura só para reduzir contagem = PARE.
5. **Todo claim novo com prova na hora** (git/ls/rg); números re-contados, nunca copiados (lição: "80" era 66; "158k" era 156k).
6. **Escritores concorrentes:** antes de cada commit re-checar `git status`/mtime do arquivo; edição alheia não-commitada → verify-then-absorb (verificar adversarialmente, absorver com atribuição, rejeitar o provadamente falso); NUNCA reverter trabalho alheio às cegas; NUNCA commitar sem pathspec explícito; NUNCA `git add -A`.
7. **Commits:** lotes pequenos na main, 1 migração/família por commit; push só com OK do operador.
8. **Verificação adversarial obrigatória:** ao fim de cada frente, re-leitura/re-execução adversarial de amostra (agente independente tentando REFUTAR) antes de declarar a frente fechada.
9. **Placar PT-BR a cada lote:** frente, feitas/faltam, delta medido, próximo lote.

## Metas quantitativas (medir, não declarar — banda honesta)
- B_STATE no padrão antigo: 35 → **0** (LOC agregado −≥30%).
- jscpd: 4,18% → **<2%** (ou relatório família-a-família do restante).
- `Generated/`: **−≥15k linhas** re-geradas (ou prova de impossibilidade).
- 0-ref SelfConstruction: 12% → **≤5%**.
- Gate v2: mecanismos completos + testados + observe colhendo (enforce = decisão do operador).
- Suíte: zero caller vivo quebrado pela obra (falha de sessão concorrente = atribuir com bisect próprio, nunca "consertar" história alheia).

## Prova final
Re-rodar todas as medições do baseline com os mesmos parâmetros; publicar tabela antes/depois; re-leitura adversarial de 3 entregas-amostra; delta não comprovado = meta não atingida (reportar honesto, nunca ajustar régua).

## Governança pré-início
`php artisan atlas:ai:session-bootstrap --task="obra 6 consolidacao estrutural" --json`; recall de memória sobre vetos (eng-kernel-unification-map, SORT_STRING, organs-unwired) antes da V0; place-feature para o gate v2.

## Progresso e achados (preenchido durante a obra, 05/07 ~18h)

**V0 — ENTREGUE (`059efab25e` + ajuste `e8f3a0e999`).** Gate de admissão v2: reuse-first de lógica
(`evaluateLogicReuse`, janela de 30 linhas) + wired-or-tagged (`AtlasTaskWiringAdmissionGate`,
`@unwired-until`), observe default. Verificação adversarial (3 lentes) deu V0_PRECISA_AJUSTE com 2
furos REAIS que foram consertados: (1) path `./` furava a auto-exclusão → falso-positivo/negativo;
(2) repetição estrutural (array/match/tabela) era tratada como clone. Re-verificado: 14 testes verdes,
4 casos do adversário corrigidos, replay 20+19 packets = 0 falso-positivo. Tetos residuais documentados.

**V1 — ACHADO HONESTO que refuta a régua (medido, não declarado).** Os 35 B_STATE somam 17.413 LOC
(média 498). Medição: só 3 usam o trait; **7** têm helpers locais byte-idênticos ao trait (~120 linhas
recuperáveis, hash-safe); 18 têm montagem de payload própria cuja ORDEM alimenta o `certification_hash`
(migrar viola o veto de hash — NÃO tocar). **Os 17.413 linhas são ~98% lógica de check ESPECÍFICA por
área** (cada certifier lê seus docs e verifica sua realidade) — trabalho genuíno, não duplicação. A meta
"−30% LOC" e "35 → <20 classes" repousa no MESMO erro de categoria que a spec da Obra #5 já flagou
(auditor de estado ≠ juiz clonado). Ação: migrados os 7 helper-carriers para o trait (dedup real,
fonte única do rollup); o resto fica como está — reduzir seria Goodhart. Delta real medido no commit.

**V2 — RELATÓRIO FAMÍLIA-A-FAMÍLIA (a spec permite "OU relatório honesto"; <2% NÃO atingível esta sessão, medido).**
jscpd atual: **4,10%** (51.537 dup / 3.854 clones) — baseline limpeza 4,18%. Para <2% seria preciso remover ~26k linhas. Distribuição:
- **SelfConstruction 20.374 (40% do total) — NÃO TOCAR.** Família `AgentAutomaticDispatchSchedulerOneShotTick*Invoker` = **62 arquivos, 20 commits nas últimas 3h** (workers codex ativos). Os invokers NÃO são byte-idênticos (cada um embrulha um gate diferente com chaves de payload distintas — jscpd flaga a estrutura, não o conteúdo) e os workers JÁ consolidam incrementalmente (`Support/OneShotTickInputNormalizer` em adoção). Extrair base aqui = colisão com 20 commits/3h + Goodhart num alvo auto-consolidante. **O freio estrutural desta família é o V0 (shipado): impede NOVOS clones; o estoque cai via workers + gate.**
- **Aaeos ~5.2k = Generated/ → território do V3** (dedup no gerador, não aqui).
- **Frio extraível fora da zona quente: ~1,1k linhas em 22 pares**, majoritariamente: variante-legítima (estratégias de trading, já com `SharedIndicatorMath`), VETADO (Rivals/Medição: `*RivalsReadiness`, `VoxV5/V6`; drivers `Claude/HermesCliProvider` = veredito keep-separate registrado), ou test-seam intencional (`AtlasLoopFrozenTestContentBuilder` = gêmeo public-method do `SourceRenderer` para caracterização — retire exige reescrita de teste, ~295 linhas, baixa prioridade → semente da esteira gate-guarded).
Conclusão honesta: o problema de clone do núcleo é resolvido por CONSTRUÇÃO pelo V0 (produtor freado), não por extração manual num estoque hot/generated/vetado. Delta de extração manual disponível sem colisão/Goodhart/veto ≈ algumas centenas de linhas — não move a régua; semeado à esteira.

**V3 — PROVA DE IMPOSSIBILIDADE (a spec permite "OU prova de que o gerador não comporta, com números").**
Investigado: **NÃO existe gerador** para `app/Services/Ai/Aaeos/Generated/`. Prova: zero writers (`file_put_contents`/`File::put`/stub-render) apontando para esse diretório em todo app/; os 309 arquivos têm docblock em PROSA escrita à mão; os comandos os IMPORTAM como input (`use ...\Generated\...Service`), não os emitem; git = commits "save" humanos, não bulk-regeneração. **"Generated/" é nome enganoso** — são 309 doc-runtime services hand-authored (média ~430 linhas, um por doc canônico). Portanto a meta "−15k via regeneração" é **inexecutável — não há template a abstrair**. Isto CORRIGE uma crença falsa herdada (a regra "dedup no gerador, nunca editar à mão" assumia um gerador que nunca existiu). Duplicação interna ENTRE os 309 = **4.679 linhas (3,52% de 133k)** — modesta; seria extração manual V2-style entre serviços distintos, sujeita à mesma honestidade variante-vs-clone; não move régua e não vale o risco de conflatar doc-runtimes distintos. Ressalva conservadora: não descarto um gerador FORA do repo (script externo) — por segurança não liberei edição à mão do diretório, só registrei que nenhum writer in-repo existe.

**V4 — MAPA MEDIDO + achado que refuta "sprawl removível" (0-ref 12%→5% NÃO é alvo de consolidação).**
Censo determinístico (padrão wiring-gate: caller em app/routes/config/db, Console Command wired): das **301
micro-classes** puras de decisão (`Scorer|Classifier|Detector|Evaluator|Decider|Verdict|Validator|...`),
**99 são 0-ref (32,9%)**. Triagem crítica: **98 têm teste pareado** = slices pending-wiring dos leap-backlogs
(cognitive-plane/deep-cores/final-convergence — as mesmas rows S126-S291 que a campanha de docs consertou):
lógica pura testada esperando wiring no runtime, **NÃO dead-code**. Só **1 sem teste** (`VentureSuccessEvaluator`),
e mesmo essa é peça de um cluster de feature inteiro unwired (`VentureFoundry/Success/`: 2 evaluators + 1 gate,
todos 0-ref) — retirar quebraria a feature quando ligada. Conclusão: os 33% de 0-ref são **backlog de WIRING**
(feature semântica, fora do escopo de uma obra de CONSOLIDAÇÃO), não sprawl removível; retirar destruiria
capability testada que os leap-backlogs rastreiam. Nenhum retire executado (preflight de todos leva a
"pending-wiring", não "morto"). O freio contra NOVO organ unwired é o V0 (shipado, `@unwired-until` + blocker).

**SÍNTESE das frentes V1-V4 (achado transversal, medido):** a premissa da Obra #6 — "há muita lógica removível/
duplicada/morta no núcleo" — é **largamente refutada por medição**. O núcleo não está inchado de duplicação
ou dead-code; é lógica distinta legítima: V1 = auditores distintos por área (não clones), V2 = família hot
auto-consolidante + generated + vetado, V3 = doc-runtimes hand-authored (sem gerador), V4 = capability testada
pending-wiring (não morta). O ÚNICO ganho estrutural grande e correto foi o **V0**: consertar o PRODUTOR para
não entrar MAIS sprawl/duplicação/unwired — shipado e verificado adversarialmente. Forçar consolidação em
V1-V5 seria Goodhart contra um codebase majoritariamente legítimo.

## Follow-ups
- Enforce do `admission_v2_mode` (hoje observe) é decisão do operador — flip quando a janela de dados
  mostrar sinal limpo. O replay já provou 6 organs unwired reais entrando pela esteira.
- V1: as 18 certifiers com payload-assembly próprio poderiam usar `stateCertificationPayload` SE a ordem
  de chaves for provada idêntica por-área (snapshot de hash antes/depois) — deixado para quem tiver o
  orçamento de provar hash-safe uma a uma; ganho ~180 linhas, risco de hash não vale sem a prova.
