# Atlas CLI 5x Claude Code Plan

Este documento define o plano especifico para o Atlas CLI superar o Claude Code
CLI com uma diferenca brutal em programacao media/dificil, mantendo uma
comparacao justa:

```text
Atlas CLI + claude_cli + Claude Opus
vs
Claude Code CLI + Claude Opus
```

Nesta fase, a vitoria nao pode vir de Codex, Gemini, Atlas Decide, council,
fallback, roteamento multi-modelo ou revisao externa. A vitoria precisa vir do
Atlas como produto de engenharia em volta do mesmo Claude.

Importante: essa restricao vale para o modo de benchmark justo, nao para o Atlas
normal. O Atlas normal deve continuar inteligente, respeitando Default AI do
app, provider strategy, Atlas Decide, Codex, Gemini, budgets e overrides
manuais. Fair Claude deve ser opt-in por flags/comandos especificos; nao deve
virar default global nem reduzir capacidades existentes.

Documento de protocolo experimental:

- `docs/atlas-cli-fair-claude-benchmark.md`

Este documento e o plano de implementacao para fazer esse protocolo virar uma
vantagem real de produto.

## Tese Ultra Robusta

O Atlas nao deve tentar ser "um Claude Code com outro prompt". Isso seria
fragil. O Atlas precisa ser um sistema operacional de engenharia em volta dos
providers:

```text
intencao -> contrato -> contexto -> execucao -> gates -> repair -> memoria -> benchmark
```

A meta 5x so e defensavel quando o ganho vem de reduzir desperdicio
operacional que o Claude Code direto ainda deixa para o humano:

- escolher e carregar contexto correto;
- evitar contexto irrelevante;
- preservar decisoes canonicas;
- proteger dirty files;
- validar por ferramentas externas;
- transformar falhas em repair prompts excelentes;
- medir provider/modelo por evidencia;
- aprender com execucoes reais.

Portanto existem duas trilhas que nao podem ser misturadas:

| Trilha | Objetivo | Providers Permitidos | Declaracao Permitida |
|---|---|---|---|
| Fair Claude | provar que o Atlas e melhor harness usando o mesmo Claude | somente `claude_cli` + Opus | "Atlas reduz intervencao humana em X contra Claude Code com o mesmo Claude" |
| Atlas Supercharged | maximizar produtividade real do Vitor | Claude, Codex, Gemini, Atlas Decide e fallback medido | "Atlas e mais eficiente como sistema multi-provider" |

Fair Claude e o teste cientifico. Atlas Supercharged e o produto diario.

## Principios Nao Negociaveis

1. **Provider nao e identidade.** Claude, Codex e Gemini sao motores; Atlas e a
   memoria, decisao, contexto, gates e continuidade.
2. **Tudo que conta precisa ser auditavel.** Prompt efetivo, context pack,
   provider, modelo, args, diff, gates, repair e final packet devem ter hash ou
   artifact.
3. **Sem gate, sem vitoria.** Resultado nao verificado e `unverified`, nunca
   `passed`.
4. **Sem comparacao pareada, sem 5x.** A meta 5x exige baseline e scorecard.
5. **Gemini economiza contexto caro.** No Atlas normal, Gemini deve ser scout,
   triagem, multimodal e contexto longo antes de gastar Claude/Codex premium.
6. **Repair e produto central.** A maior diferenca contra Claude Code deve vir
   de fechar o loop automaticamente, nao de uma primeira resposta perfeita.
7. **Config do app manda no Atlas inteiro.** Default AI, modelos permitidos,
   auto/manual, budgets e limites precisam afetar app, CLI, jobs, workers,
   provider strategy e Atlas Decide.
8. **Benchmark nao pode quebrar o produto normal.** Qualquer modo fair precisa
   ser opt-in e isolado.

## Objetivo

O objetivo nao e provar que o Claude dentro do Atlas raciocina melhor que o
Claude Code. O modelo e o mesmo. O objetivo e provar que o Atlas entrega muito
mais trabalho pronto porque adiciona:

- contexto de engenharia melhor;
- contrato de tarefa antes da chamada;
- isolamento e checkpoint;
- gates completos;
- repair loop automatico;
- telemetria e replay;
- final packet verificavel.

Em tarefas simples de um arquivo, o Atlas pode empatar ou perder em tempo. A
meta 5x se aplica a programacao media/dificil, onde falhas, testes,
regressoes, contexto e reparo importam.

Ordem correta: primeiro construir o Atlas 5x melhor como produto e harness.
Benchmark pareado e scorecard oficial sao a ultima fase, usados para provar o
ganho, nao para guiar hacks de implementacao.

## Definicao De 5x

Existem duas formas aceitaveis de declarar 5x.

### 5x Em Sucesso Autonomo

```text
pass_without_human_rate_atlas_medium_hard
/
pass_without_human_rate_claude_code_medium_hard
>= 5.0
```

Essa declaracao so e matematicamente possivel quando o baseline do Claude Code
for baixo. Exemplos:

| Claude Code | Atlas | Razao |
|---|---:|---:|
| 10% | 50% | 5.0x |
| 12% | 60% | 5.0x |
| 20% | 80% | 4.0x |
| 40% | 90% | 2.25x |

Se Claude Code ja resolve 40% dos casos medios/dificeis sem humano, a promessa
"5x mais sucesso" deixa de ser possivel. Nesse caso, a metrica correta deve ser
5x menos intervencao humana.

### 5x Em Menos Intervencao Humana

```text
human_interventions_per_green_case_claude_code
/
human_interventions_per_green_case_atlas
>= 5.0
```

Esta e a definicao mais robusta para produto. O Atlas vence porque fecha o loop
automaticamente: detecta falha, resume erro, chama o mesmo Claude novamente,
valida e entrega.

### Metrica Executiva Recomendada

Para release, usar as duas:

```text
autonomous_success_lift = pass_without_human_rate_atlas - pass_without_human_rate_claude_code
intervention_reduction = interventions_per_case_claude_code / interventions_per_case_atlas
```

Meta minima:

- `autonomous_success_lift >= 30pp` em tarefas medias/dificeis;
- `intervention_reduction >= 5.0x`;
- `protocol_validity_rate = 100%`;
- `fallback_violation_count = 0`;
- `provider_violation_count = 0`.

## Estado Atual

O Atlas ja possui componentes importantes:

- `atlas dev --complete` com loop de repair;
- modelo/provider manual via `--provider` e `--model`;
- provider Claude CLI com passagem de modelo;
- quality gate basico;
- engineering harness com worktree, controles, patch artifact, testes e score;
- benchmark runner interno;
- docs de benchmark justo;
- telemetria de provider/modelo/custo/latencia.

Mas ainda nao e suficiente para declarar 5x.

## Atlas Supercharged Fora Do Benchmark

Fora do Fair Claude, o Atlas deve usar todos os motores permitidos pelo app para
ser maximamente eficiente. Esse fluxo e diferente do benchmark justo.

Pipeline recomendado para tarefas medias/dificeis:

```text
raw task
  -> Atlas Decide
  -> Context Compiler
  -> Gemini Scout quando contexto amplo/multimodal/triagem for util
  -> Executor principal Claude ou Codex conforme settings, tarefa e historico
  -> Gate Matrix
  -> Repair Taxonomy
  -> fallback medido quando quota/capacidade/provider falhar
  -> Memory Delta / Atlas-Bench
```

### Gemini Scout

Gemini Scout nao e executor padrao de codigo. Ele e a camada barata/ampla para
gastar contexto onde Claude/Codex seriam mais caros:

- varrer arquitetura ampla;
- resumir dezenas de arquivos;
- analisar screenshots, imagens e contexto multimodal;
- preparar mapa de arquivos provaveis;
- listar riscos e perguntas de esclarecimento;
- produzir `scout_brief` compacto para Claude/Codex;
- descartar ruido antes do provider caro.

Contrato do `scout_brief`:

```json
{
  "task_type": "refactor",
  "risk": "medium",
  "likely_files": [],
  "related_tests": [],
  "architecture_notes": [],
  "risk_notes": [],
  "validation_plan": [],
  "open_questions": [],
  "confidence": 0.0
}
```

Regras:

- Gemini Scout deve respeitar `allow_auto` e budget do app;
- se Gemini bater quota/capacidade, Atlas cai para Claude sem trocar o modelo
  Gemini para outro Gemini menor;
- scout falho nao deve bloquear tarefa quando o executor principal consegue
  operar sem ele;
- scout usado precisa aparecer no trace, custo, latencia e scorecard;
- scout nao pode escrever patch no fluxo de programacao pesada, salvo quando o
  operador pedir explicitamente.

### Context Compiler 5x

O Context Compiler e a camada que deve fazer o Claude/Codex performar acima do
uso direto:

```text
repo profile
+ code intelligence
+ task contract
+ prior decisions
+ memory provider-safe
+ recent diffs
+ related tests
+ validation commands
+ Gemini scout brief
-> ranked context pack
```

Saida minima:

```json
{
  "context_pack_hash": "...",
  "context_budget": {"max_chars": 90000, "used_chars": 0},
  "included_files": [],
  "summarized_files": [],
  "excluded_files": [],
  "memory_refs": [],
  "code_refs": [],
  "scout_refs": [],
  "validation_commands": []
}
```

Regra central: o Atlas deve gastar tokens com decisao e execucao, nao com o
provider redescobrindo o repositorio do zero.

### Strategy Recipes

Atlas Decide precisa selecionar uma receita operacional por tipo de tarefa:

| Receita | Scout | Executor | Gates Obrigatorios | Repair Principal |
|---|---|---|---|---|
| `bugfix_tested` | opcional | Codex ou Claude | teste focado + diff check | `test_failed` |
| `refactor_multi_file` | Gemini recomendado | Claude/Codex conforme historico | suite relevante + scope + typecheck | `scope_violation`/`test_failed` |
| `ui_visual` | Gemini multimodal recomendado | Codex quando imagem/Playwright importar | visual smoke + screenshot evidence | `visual_regression` |
| `db_migration` | opcional | Claude se design importa, Codex se patch direto | migration/schema + postgres review | `migration_risk` |
| `research_architecture` | Gemini recomendado | Claude para decisao final | evidence refs + decision packet | `insufficient_evidence` |
| `security_sensitive` | Gemini opcional | Claude/Codex com gates altos | secret scan + security scan + scope | `security_policy_failed` |

Cada receita precisa registrar:

- motivo da escolha;
- providers considerados;
- provider escolhido;
- scout usado ou pulado;
- gates selecionados;
- stop rules;
- fallback permitido;
- custo estimado e real quando disponivel.

### Config Propagation Audit

O app configura o Atlas. Portanto cada mudanca de setting precisa atravessar
todas as superficies:

```text
Atlas App Settings
  -> runtime settings service
  -> CLI commands
  -> gateway
  -> jobs/workers
  -> provider manager
  -> Atlas Decide
  -> provider strategy
  -> trace/scorecard
```

Gates obrigatorios:

- mudar Default AI no app muda `atlas ask`, `atlas chat`, app AI, worker e
  provider strategy;
- mudar modelo Claude/Codex no app muda invocacao CLI efetiva;
- Gemini continua fixo em `gemini-3.1-pro-preview`;
- `allow_auto=false` bloqueia roteamento automatico;
- `allow_manual=false` bloqueia override manual;
- budget bloqueia ou degrada conforme policy configurada;
- trace registra `settings_version` ou hash equivalente.

Sem esse audit, o Atlas vira duas coisas diferentes: app bonito e CLI real. Isso
e inaceitavel para a meta 5x.

### Evidence-Based Router

O roteador nao deve ser uma tabela fixa de preferencias. Ele deve usar historico
real:

```text
task_type + stack + difficulty + context_size + provider_health + budget
-> provider/recipe recommendation
```

Sinais minimos:

- taxa de sucesso por provider/modelo;
- custo por caso verde;
- latencia por caso verde;
- repair conversion rate;
- final gate pass rate;
- override humano;
- quota/capacity incidents;
- tool failure rate;
- context pack size.

Sem volume suficiente, o roteador deve declarar baixa confianca e usar regra
conservadora.

## Bloqueios Criticos

### B1 - Fair Claude Mode Ainda Nao E Contrato Executavel

O documento de benchmark exige:

```bash
atlas dev "..." --claude-only --model=opus --complete --auto-test
atlas dev "..." --provider=claude_cli --model=opus --single-provider --no-decide --fallback-disabled --complete --auto-test
```

O produto precisa implementar e validar essas flags.

Essa implementacao deve ser aditiva. Ela nao pode:

- mudar `atlas dev "..."` para Claude-only;
- alterar o Default AI global;
- remover suporte a `--provider=codex_cli`;
- remover suporte a `--provider=gemini_cli`;
- quebrar `--model` para outros providers;
- desativar Atlas Decide fora do fair mode;
- degradar fluxos inteligentes existentes.

Contrato obrigatorio:

```json
{
  "fair_mode": "claude_code_comparison",
  "single_provider": true,
  "provider_lock": "claude_cli",
  "model_lock": "opus",
  "fallback_disabled": true,
  "allowed_providers": ["claude_cli"],
  "atlas_decide_disabled": true,
  "council_disabled": true
}
```

Falhas obrigatorias:

- `--claude-only --provider=codex_cli`;
- `--claude-only --provider=gemini_cli`;
- `--claude-only --model=gpt-5.5`;
- `--claude-only --critical` se isso ativar council;
- qualquer fallback automatico;
- qualquer downgrade para Sonnet/Haiku;
- qualquer job com provider diferente de `claude_cli`.

### B2 - Fallback E Provider Choice Podem Invalidar O Teste

Em fair mode, rate limit, auth expirada ou provider offline nao podem oferecer
troca de provider ou downgrade de modelo.

Comportamento correto:

```text
Claude indisponivel -> wait/retry same provider ou fail
Claude rate limited -> wait/retry same provider ou fail
Claude auth expired -> fail com instrucao de login
Modelo Opus indisponivel -> fail
```

Comportamento proibido:

```text
trocar para Codex
trocar para Gemini
trocar para claude_codex
downgrade para Sonnet
downgrade para Haiku
ativar Atlas Decide
ativar council
```

### B3 - Benchmark Atual Nao Tem Braco Claude Code Pareado

O benchmark interno mede o Atlas contra historico ou suite interna. Para a
promessa 5x, cada caso precisa ter dois bracos:

```text
atlas_fair_claude
claude_code_cli_baseline
```

Cada braco deve registrar:

- repo;
- commit inicial;
- workspace/worktree;
- prompt;
- provider;
- modelo real;
- setup command;
- test commands;
- timeout;
- diff hash;
- arquivos alterados;
- stdout/stderr relevantes;
- intervencoes humanas;
- resultado final;
- validade do protocolo.

Sem isso, qualquer comparacao e opinativa.

### B4 - Quality Gate Atual E Estreito

Para tarefas medias/dificeis, um gate com apenas `git diff`, `git status` e um
teste detectado e insuficiente.

O gate 5x precisa rodar, quando aplicavel:

- `git diff --check`;
- captura de arquivos untracked;
- testes focados;
- suite relevante;
- lint/formatter em modo check;
- typecheck;
- build;
- migration/schema check;
- secret scan minimo;
- scope check;
- dirty file overlap check;
- security scan minimo;
- final packet validation.

Se nenhuma validacao executavel for encontrada, o status deve ser:

```text
unverified
```

Nunca `passed`.

### B5 - Repair Loop Ainda E Pouco Informativo

O repair loop e a principal alavanca contra Claude Code. Ele precisa transformar
falha em feedback excelente para o mesmo Claude.

Cada tentativa deve salvar:

- `attempt_id`;
- provider/modelo;
- prompt hash;
- diff hash antes/depois;
- arquivos alterados;
- comando que falhou;
- stdout/stderr compacto;
- erro principal;
- categoria de falha;
- arquivos provaveis;
- restricoes de escopo;
- human intervention false;
- fair mode metadata.

O prompt de repair deve conter:

```text
1. Objetivo original
2. Contrato de tarefa
3. Criterios de aceite
4. Resultado da tentativa anterior
5. Diff relevante
6. Comando que falhou
7. Erro principal
8. Hipotese de causa
9. Arquivos alterados
10. Arquivos fora de escopo
11. Instrucao: menor correcao possivel
12. Restricao: usar somente Claude Opus neste modo fair
```

### B6 - Context Pack Precisa Chegar Ao Prompt Real

O Atlas so ganha de forma justa se preparar melhor o Claude. O context pack nao
pode ser apenas artefato persistido; ele precisa entrar no prompt efetivo.

Context pack minimo:

- objetivo;
- tipo da tarefa;
- risco;
- arquivos provaveis;
- arquivos proibidos;
- padroes locais;
- testes relacionados;
- comandos de validacao;
- decisions historicas relevantes;
- memory entries provider-safe;
- acceptance criteria;
- definition of done;
- dirty files protegidos.

### B7 - Worktree Isolado Precisa Ser Default No Benchmark

Para o benchmark 5x:

- cada caso roda em worktree descartavel;
- Atlas e Claude Code partem do mesmo commit;
- ordem dos bracos alterna por caso;
- patch e capturado com tracked e untracked;
- apply-back so ocorre depois de gate verde;
- dirty overlap invalida ou bloqueia o caso.

### B8 - Permissoes Precisam Bloquear, Nao So Observar

Para um produto superior, policy de permissao nao pode ser apenas warning.

Bloquear por padrao:

- workspace fora de raiz permitida;
- escrita sem permissao;
- `danger` sem flag explicita;
- provider unsandboxed sem confirmacao;
- comando destrutivo sem approval;
- alteracao fora do escopo em benchmark.

### B9 - Scorecard Precisa Medir Pronto Sem Humano

O Atlas nao vence por "parecer melhor". Ele vence se entregar mais casos
prontos sem o operador fechar o loop.

Metrica primaria por caso:

```json
{
  "case_id": "medium-api-refactor-001",
  "difficulty": "medium",
  "arm": "atlas_fair_claude",
  "passed": true,
  "pass_without_human": true,
  "human_intervention_count": 0,
  "repair_attempts": 2,
  "repair_conversion": true,
  "final_gate_passed": true,
  "protocol_valid": true
}
```

Agregacao:

```text
pass_without_human_rate_medium
pass_without_human_rate_hard
repair_conversion_rate
final_gate_pass_rate
interventions_per_case
time_to_green
cost_per_green_case
protocol_validity_rate
```

### B10 - Invocacao Do Claude Precisa Ser Auditavel

Para a comparacao ser justa, nao basta dizer "usou Claude". O Atlas precisa
registrar exatamente como chamou o Claude CLI.

Fingerprint obrigatorio por tentativa:

```json
{
  "claude_binary": "claude",
  "claude_version": "...",
  "claude_args": ["-p", "--output-format", "stream-json", "--verbose"],
  "model_argument": "claude-opus-...",
  "model_alias_requested": "opus",
  "model_identity_resolved": "claude-opus-...",
  "permission_mode": "write",
  "add_dirs": ["/repo"],
  "session_policy": "atlas_reconstructed_context",
  "output_format": "stream-json"
}
```

Esse fingerprint precisa aparecer tambem no braco Claude Code baseline, no que
for observavel. Se o baseline usar uma configuracao diferente de modelo,
permissao, cwd ou contexto inicial, o caso deve ser marcado como invalido ou
comparado com ressalva explicita.

### B11 - Estrategia De Sessao Do Claude Precisa Ser Decidida

O provider Claude do Atlas pode operar de duas formas:

```text
stateless_per_attempt
atlas_reconstructed_context
sticky_claude_session
```

Para benchmark oficial, a estrategia recomendada e:

```text
atlas_reconstructed_context
```

Ou seja:

- cada tentativa pode ser independente no Claude CLI;
- o Atlas reconstrui o contexto necessario;
- o repair prompt inclui historico compacto;
- nao depende de memoria invisivel do provider;
- replay fica mais confiavel.

Se for usada sessao persistente do Claude, isso precisa ser registrado como uma
variante separada. Nao misturar resultados stateless e sticky no mesmo score.

### B12 - Contrato De Saida Para Claude

O Atlas precisa pedir ao Claude uma saida que ajude o harness, sem depender da
autoavaliacao do modelo.

Contrato recomendado no prompt:

```text
Ao terminar, responda com:
- resumo curto do que mudou;
- arquivos alterados esperados;
- comandos de teste que devem passar;
- riscos restantes se houver.
Nao declare sucesso se nao souber se os testes passaram.
O Atlas validara tudo externamente.
```

O Atlas nao deve confiar nessa resposta como gate. Ela serve para melhorar
telemetria, final packet e repair. O resultado real vem de testes, diff, lint,
scope e sensores deterministas.

### B13 - Claude Repair Capsule

O maior ganho contra Claude Code vem de transformar logs ruins em feedback
excelente para o mesmo Claude.

Em vez de reenviar stdout/stderr bruto, o Atlas deve gerar uma capsula:

```json
{
  "failure_type": "test_failed",
  "command": "php artisan test --filter=CheckoutTest",
  "exit_code": 1,
  "primary_error": "...",
  "failing_tests": [],
  "files_likely_related": [],
  "diff_summary": "...",
  "scope_warnings": [],
  "previous_attempt_summary": "...",
  "repair_instruction": "Corrija a menor causa provavel sem ampliar escopo."
}
```

Essa capsula deve ser a entrada principal do repair prompt. Logs completos
continuam salvos como artifact, mas o Claude recebe o resumo acionavel.

### B14 - Biblioteca Claude-Only De Falhas Recorrentes

O Atlas pode usar memoria propria desde que nao use outro modelo. Para esta
fase, criar uma biblioteca de aprendizado apenas sobre execucoes Claude:

```text
atlas_claude_failure_patterns
```

Conteudo permitido:

- falhas recorrentes de testes;
- comandos de validacao por stack;
- padroes de erro do proprio repo;
- correcoes que funcionaram antes;
- arquivos que normalmente quebram juntos;
- armadilhas de escopo.

Conteudo proibido:

- revisoes geradas por Codex;
- analises geradas por Gemini;
- decisoes de Atlas Decide;
- qualquer output de outro modelo.

No artifact, registrar:

```json
{
  "memory_scope": "claude_only",
  "memory_entries_used": [],
  "non_claude_memory_used": false
}
```

### B15 - Paridade E Otimizacao Do Claude Code Context

O Atlas pode vencer preparando melhor contexto, mas precisa deixar claro o que
foi dado ao Claude Code baseline.

Regras:

- se o repo tem `CLAUDE.md`, ambos os bracos podem usar;
- se o Atlas gerar instrucao temporaria de benchmark, ela deve ser registrada;
- se o Claude Code baseline nao recebeu um contexto equivalente por limitacao
  operacional, isso deve aparecer como vantagem de produto do Atlas, nao como
  vantagem de modelo;
- nenhum contexto invisivel pode entrar no Atlas sem artifact.

Melhoria Atlas permitida:

```text
Atlas gera um run brief compacto e auditavel para Claude.
```

Isso e justo porque e parte do harness. Mas precisa ser salvo e reproduzivel.

### B16 - Perfil De Ferramentas Do Claude

O Atlas deve detectar quais controles o Claude CLI instalado suporta, sem
assumir flags que podem nao existir.

Capability detection:

```text
claude --version
claude --help
suporte a --model
suporte a --output-format
suporte a --permission-mode
suporte a --add-dir
suporte a configuracao MCP/hook, se existir
```

O benchmark deve registrar capacidades detectadas. Se uma capacidade existir,
o Atlas pode usa-la para restringir melhor a execucao do Claude. Se nao existir,
o Atlas precisa compensar com worktree, gate e apply-back.

Nunca assumir uma flag sem teste local. Isso evita benchmark quebrado por versao
do Claude CLI.

## Arquitetura Alvo

```text
atlas dev --claude-only --model=opus --complete
  |
  v
FairClaudePolicy
  - provider lock
  - model lock
  - fallback disabled
  - decide disabled
  - claude invocation fingerprint
  |
  v
Preflight Contract
  - workspace
  - dirty files
  - acceptance criteria
  - test matrix
  - context pack
  - claude-only memory
  |
  v
Checkpoint / Worktree
  - baseline hash
  - protected files
  - isolated execution
  |
  v
Claude Opus Attempt 1
  |
  v
Gate Matrix
  - tests
  - lint
  - diff
  - scope
  - security
  |
  +--> passed -> Final Packet
  |
  +--> failed -> Structured Repair Prompt
                    - Claude Repair Capsule
                    - diff summary
                    - failing command
                    |
                    v
                 Claude Opus Attempt 2/3
```

## Implementacao Necessaria

### P0 - Baseline Verde

Objetivo: nenhum benchmark serio roda sobre base inconsistente.

Implementar/corrigir:

- alinhar versao PHP entre `composer.json`, README e CI;
- fazer `composer validate --strict` passar;
- fazer `php artisan test` passar;
- fazer `git diff --check` passar;
- decidir se Pint e gate obrigatorio;
- se Pint for obrigatorio, corrigir `./vendor/bin/pint --test`;
- criar `composer quality`;
- documentar que benchmark exige worktree limpo.

Comando alvo:

```bash
composer quality
```

Deve incluir, no minimo:

```bash
composer validate --strict
php artisan test
git diff --check
```

Se lint virar obrigatorio:

```bash
./vendor/bin/pint --test
```

Definition of done:

- todos os gates locais passam;
- CI usa a mesma versao de PHP do Composer;
- benchmark bloqueia quando baseline esta sujo.

### P1 - Fair Claude Mode

Objetivo: impedir contaminacao por outro provider/modelo.

Adicionar no CLI:

```bash
--claude-only
--single-provider
--no-decide
--fallback-disabled
```

Regras:

- `--claude-only` implica `--provider=claude_cli`;
- `--claude-only` implica `--single-provider`;
- `--claude-only` implica `--no-decide`;
- `--claude-only` implica `--fallback-disabled`;
- `--claude-only` sem `--model` usa Opus configurado no app;
- `--model=opus` resolve para o modelo premium Claude configurado;
- `allow_manual=false` bloqueia;
- `allow_auto=false` nao bloqueia uso manual.

Persistir metadata:

```json
{
  "fair_mode": true,
  "fair_mode_name": "claude_code_comparison",
  "single_provider": true,
  "provider_lock": "claude_cli",
  "model_lock": "opus",
  "fallback_disabled": true,
  "atlas_decide_disabled": true,
  "allowed_providers": ["claude_cli"]
}
```

Definition of done:

- `atlas dev --claude-only --model=opus --plan-only --json` mostra lock;
- tentativa com Codex falha;
- tentativa com Gemini falha;
- tentativa com modelo de Codex falha;
- rate limit nao oferece troca;
- trace registra fair mode.

### P2 - Enforcement Central

Objetivo: garantir que fair mode sobreviva ao CLI, gateway, worker e replay.

Implementar policy central:

```text
FairClaudePolicy
```

Responsabilidades:

- validar provider;
- validar modelo;
- validar fallback;
- validar decision mode;
- validar provider choice;
- validar retry;
- validar replay;
- registrar violacao.

Pontos de aplicacao:

- `atlas:cli:dev`;
- `atlas:ai:chat`;
- gateway;
- worker;
- provider choice builder;
- provider handoff;
- benchmark runner;
- replay;
- trace metric aggregator.

Definition of done:

- qualquer divergencia vira `fair_mode_violation`;
- casos invalidos nao contam como vitoria nem derrota;
- violacao aparece no scorecard.

### P2.5 - Claude Invocation Fingerprint

Objetivo: provar que cada tentativa usou o Claude correto, com argumentos
corretos e contexto auditavel.

Registrar por tentativa:

- path do binario;
- versao detectada;
- args efetivos;
- cwd;
- modelo solicitado;
- modelo resolvido;
- output format;
- permission mode;
- add dirs;
- timeout;
- session policy;
- config dir relevante, sem segredos;
- hash do prompt enviado;
- hash do context pack.

Definition of done:

- artifact mostra a invocacao exata;
- scorecard invalida modelo divergente;
- replay consegue reconstruir a chamada;
- baseline Claude Code registra dados equivalentes quando observaveis.

### P3 - Preflight De Engenharia

Objetivo: antes de chamar Claude, o Atlas precisa saber o que esta tentando
entregar.

Preflight deve produzir:

```json
{
  "objective": "...",
  "difficulty": "medium",
  "risk": "high",
  "acceptance_criteria": [],
  "likely_files": [],
  "forbidden_files": [],
  "dirty_files": [],
  "protected_files": [],
  "validation_commands": [],
  "context_pack_hash": "...",
  "fair_mode": true
}
```

Fontes:

- task contract;
- engineering blueprint;
- git status;
- repo profile;
- memory provider-safe;
- test resolver;
- changed-file heuristics;
- package manager detection.

Definition of done:

- `--plan-only --json` mostra contrato completo;
- dirty files sao classificados;
- validacoes sao listadas;
- prompt efetivo inclui o contrato.

### P4 - Context Pack Efetivo

Objetivo: fazer o mesmo Claude receber uma entrada melhor que a entrada manual
normal no Claude Code.

O prompt inicial deve incluir:

- contrato;
- mapa de arquivos;
- contexto relevante;
- padroes locais;
- comandos de validacao;
- restricoes;
- definition of done;
- instrucao para menor diff;
- politica fair mode.

Limites:

- nao despejar contexto longo sem ranking;
- nao incluir memoria nao provider-safe;
- nao incluir segredos;
- nao incluir arquivos enormes sem resumo.

Definition of done:

- prompt hash e context pack hash persistidos;
- artifact salva o prompt efetivo redigido;
- contexto usado e reproduzivel em replay.

### P4.5 - Claude Prompt Contract

Objetivo: padronizar o modo como o Atlas instrui o Claude, sem trocar de modelo
e sem depender de outro avaliador.

O prompt deve conter secoes fixas:

```text
# Atlas Fair Claude Mode
# Task Contract
# Repository Context
# Files And Scope
# Validation Commands
# Constraints
# Expected Response Contract
```

Regras:

- pedir menor diff possivel;
- pedir para nao tocar arquivos fora do escopo;
- pedir para nao declarar sucesso sem validacao externa;
- pedir resumo final compacto;
- pedir lista de comandos de teste esperados;
- lembrar que Atlas validara externamente;
- lembrar que fair mode usa somente Claude Opus.

Definition of done:

- prompt contract tem teste snapshot;
- prompt final e salvo como artifact redigido;
- repair prompt usa a mesma estrutura.

### P5 - Gate Matrix

Objetivo: substituir "rode um teste" por conclusao verificavel.

Gate minimo:

```text
git status --short
git diff --check
git diff --stat
tracked diff hash
untracked file capture
test command focused/full
lint check
scope check
secret scan simple
final packet check
```

Perfis:

```text
smoke    rapido, para iteracao local
standard default para atlas dev --complete
strict   benchmark e release
release  CI/release
```

Status:

```text
passed
failed
unverified
invalid
```

Regra:

```text
sem evidência executavel suficiente => unverified
```

Definition of done:

- gate retorna falhas estruturadas;
- repair prompt consome essas falhas;
- benchmark usa perfil `strict`;
- `pass_without_human` exige `passed`.

### P6 - Repair Loop 5x

Objetivo: converter falhas em sucesso sem humano.

Maximo default:

```text
3 tentativas
```

Parar antes quando:

- provider/modelo violar fair mode;
- diff piorar de forma clara;
- arquivos proibidos forem alterados;
- teste piorar em duas tentativas seguidas;
- erro for irreparavel sem decisao humana.

Repair taxonomy:

```text
test_failed
lint_failed
typecheck_failed
build_failed
scope_violation
dirty_overlap
secret_detected
untracked_missing
provider_failed
protocol_violation
unverified
```

Prompt de repair deve ser gerado por estrutura, nao por texto solto.

Claude Repair Capsule obrigatoria:

```json
{
  "failure_type": "test_failed",
  "command": "...",
  "exit_code": 1,
  "primary_error": "...",
  "failing_tests": [],
  "diff_summary": "...",
  "scope_warnings": [],
  "next_repair_focus": []
}
```

Definition of done:

- `repair_conversion_rate` medido;
- cada tentativa tem artifact;
- final report mostra primeira tentativa vs final;
- nenhum repair troca provider/modelo.

### P6.5 - Claude-Only Failure Memory

Objetivo: usar aprendizado do Atlas sem contaminar a comparacao com outros
modelos.

Criar memoria separada:

```text
claude_only_failure_memory
```

Somente entram eventos em que:

- provider foi `claude_cli`;
- modelo foi Opus ou modelo Claude permitido no experimento;
- nao houve Codex/Gemini/council;
- gate final registrou causa e resolucao.

Uso no prompt:

- no maximo 3 padroes relevantes;
- sempre com hash/id da memoria;
- nunca substituir acceptance criteria;
- nunca incluir segredo.

Definition of done:

- artifact registra memoria usada;
- benchmark consegue filtrar runs com memoria Claude-only;
- nenhuma memoria de outro provider entra no fair mode.

### P7 - Worktree E Checkpoint Como Produto

Objetivo: evitar dano, permitir rollback e deixar `atlas dev --complete`
profissional antes de qualquer benchmark.

O fluxo de produto deve:

- criar checkpoint antes da primeira tentativa;
- proteger dirty files existentes;
- permitir execucao em worktree isolada quando o risco pedir;
- capturar patch completo;
- capturar arquivos untracked;
- bloquear apply-back inseguro;
- oferecer rollback claro;
- registrar workspace/head/diff hash.

Definition of done:

- `atlas dev --complete` cria checkpoint;
- dirty overlap bloqueia sucesso automatico;
- untracked entra no artifact;
- patch apply-back nao ocorre antes do gate;
- falha final mostra comando de rollback.

### P8 - Final Packet E Replay

Objetivo: entregar uma conclusao mais confiavel que uma resposta solta do
Claude Code.

Final packet deve conter:

- status final;
- protocolo fair valido/invalido;
- provider/modelo;
- tentativas;
- repair conversion;
- arquivos alterados;
- diff hash;
- testes/gates executados;
- skips e motivo;
- riscos restantes;
- comando de replay;
- comando de rollback quando aplicavel.

Definition of done:

- todo run de `atlas dev --complete` termina com final packet;
- final packet distingue `passed`, `failed`, `unverified` e `invalid`;
- replay consegue reconstruir prompt/context/gates;
- operador entende o resultado sem abrir logs brutos.

### P9 - UX De Produto

Objetivo: o Atlas precisa ser melhor para usar no dia a dia, nao so melhor no
benchmark.

Comandos canonicos:

```bash
atlas dev "..."
atlas dev "..." --claude-only --model=opus --complete
atlas fix
atlas continue
atlas quality
atlas checkpoint
atlas trace last
atlas doctor
```

Saida esperada:

```text
Mode: fair_claude
Provider: claude_cli
Model: Claude Opus
Fallback: disabled
Workspace: worktree isolated
Attempt: 2/3
Gate: failed -> test_failed
Next: repairing with same Claude Opus
```

Final packet:

```text
Status: passed
Attempts: 2
Repair conversion: yes
Files changed: ...
Tests: ...
Lint: ...
Scope: ok
Protocol: valid
Replay: ...
```

Definition of done:

- operador entende o que aconteceu sem abrir logs brutos;
- falha final explica exatamente o que faltou;
- comando de replay aparece sempre.

### P10 - Product Readiness Antes Do Benchmark

Objetivo: declarar que o Atlas esta pronto para ser medido, sem ainda rodar a
suite oficial contra Claude Code.

Gates:

- fair mode enforceado no CLI/gateway/worker;
- fluxo normal fora de fair mode preservado;
- provider/model lock auditavel;
- context pack efetivo;
- gate matrix em `atlas dev --complete`;
- repair capsule em uso;
- worktree/checkpoint/rollback prontos;
- final packet e replay prontos;
- tests relevantes verdes;
- `git diff --check` verde;
- docs alinhadas ao comportamento real.

Definition of done:

- `atlas dev --claude-only --model=opus --complete --auto-test` e um produto
  utilizavel;
- `atlas dev "..."` normal continua inteligente e multi-provider;
- nenhuma melhoria depende de benchmark runner para funcionar.

## Fase Final De Prova

Somente depois que P0-P10 estiverem implementados, iniciar a prova oficial
contra Claude Code. Esta fase existe para medir e provar o ganho; nao deve
introduzir hacks no fluxo do produto.

### P11 - Worktree Obrigatorio No Benchmark

Objetivo: garantir comparacao justa.

Runner deve:

- criar worktree por caso e braco;
- aplicar setup command;
- verificar commit inicial;
- rodar Atlas ou Claude Code;
- capturar patch completo;
- rodar gate externo;
- destruir worktree ou guardar por debug.

Definition of done:

- ambos os bracos partem do mesmo commit;
- worktree sujo invalida;
- untracked entra no artifact;
- patch apply-back nao ocorre antes do gate.

### P12 - Claude Code Baseline Runner

Objetivo: medir Claude Code com o mesmo rigor.

O runner deve suportar:

```bash
atlas benchmark claude-fair run-claude-code --case=<id>
atlas benchmark claude-fair run-atlas --case=<id>
atlas benchmark claude-fair report --suite=<id>
```

Para Claude Code:

- registrar comando exato;
- registrar modelo Opus;
- registrar versao do Claude Code/Claude CLI;
- registrar cwd;
- registrar contexto inicial fornecido;
- registrar se `CLAUDE.md` foi usado;
- registrar configuracao observavel de permissao/sandbox;
- registrar timeout;
- registrar stdout/stderr;
- registrar patch;
- rodar os mesmos gates externos;
- registrar intervencoes humanas.

Se Claude Code exigir que o usuario copie erro e mande de novo, isso conta como
intervencao humana.

Definition of done:

- cada caso tem resultado pareado;
- relatorio mostra Atlas vs Claude Code por caso;
- intervencao humana e campo obrigatorio.

### P12.5 - Paridade De Contexto

Objetivo: separar vantagem justa de contexto do Atlas de benchmark mal
instrumentado.

Registrar para os dois bracos:

- arquivos de instrucao disponiveis;
- prompt final;
- contexto adicional;
- cwd;
- commit inicial;
- arquivos abertos/incluidos quando observavel;
- limitacoes do baseline.

Regra:

```text
Se Atlas recebeu contexto que Claude Code baseline nao recebeu, o artifact deve
explicar se isso foi uma capacidade do produto Atlas ou erro de protocolo.
```

Definition of done:

- nenhum contexto invisivel;
- prompt/contexto tem hash;
- relatorio diferencia "context advantage" de "model advantage".

### P13 - Scorecard 5x

Objetivo: transformar execucoes em prova.

Scorecard minimo:

```text
suite_id
case_count
valid_case_count
medium_hard_case_count
atlas_pass_without_human
claude_code_pass_without_human
autonomous_success_lift
success_ratio
intervention_reduction
repair_conversion_rate
final_gate_pass_rate
protocol_validity_rate
median_time_to_green
p90_time_to_green
cost_per_green_case
```

Segmentar por:

- dificuldade;
- tipo;
- stack;
- risco;
- primeiro patch passou/falhou;
- contexto pequeno/grande;
- refactor/bug/feature.

Definition of done:

- relatorio JSON;
- relatorio humano;
- casos invalidos separados;
- perdas do Atlas aparecem no relatorio.

## Camadas Claude Code-Like Que O Atlas Precisa Superar

Esta secao explicita as camadas de produto que precisam existir para o Atlas
ser superior ao Claude Code usando o mesmo Claude. A lista nao inclui outros
modelos, subagentes de outro provider ou roteamento multi-modelo.

### C1 - Tool Runtime Deterministico

O Atlas nao deve tratar ferramentas como chamadas soltas do provider. Cada
ferramenta precisa ter contrato operacional.

Requisitos:

- schema de input/output;
- timeout por ferramenta;
- cwd explicito;
- permissao requerida;
- stdout/stderr truncado para prompt e completo em artifact;
- codigo de saida;
- hash do comando;
- redacao de segredos;
- retry permitido ou proibido;
- classificacao de erro;
- replay possivel.

Ferramentas minimas:

- listar arquivos;
- ler arquivo;
- escrever/aplicar patch;
- executar comando;
- rodar teste;
- obter git status;
- obter diff;
- criar checkpoint;
- restaurar checkpoint;
- buscar texto;
- capturar artifact.

Definition of done:

- toda ferramenta usada no fair mode aparece no trace;
- nenhum comando destrutivo roda sem policy explicita;
- output enviado ao Claude e compacto;
- output completo fica salvo fora do prompt.

### C2 - Patch And Diff Engine

Programacao pesada e decidida pelo diff. O Atlas precisa ter uma engine de
patch mais forte que "o provider alterou arquivos".

Requisitos:

- snapshot antes da tentativa;
- snapshot depois da tentativa;
- diff tracked;
- captura de untracked;
- diff stat;
- diff hash;
- arquivos adicionados/removidos/modificados;
- ownership/scope check;
- forbidden files check;
- dirty overlap check;
- whitespace check;
- secret check;
- patch artifact;
- apply/revert seguro;
- rollback por checkpoint.

Campos obrigatorios:

```json
{
  "baseline_head": "...",
  "pre_attempt_dirty_hash": "...",
  "post_attempt_diff_hash": "...",
  "tracked_files_changed": [],
  "untracked_files_added": [],
  "forbidden_files_touched": [],
  "dirty_overlap": false,
  "patch_apply_safe": true
}
```

Definition of done:

- arquivos untracked nunca ficam invisiveis;
- patch fora de escopo bloqueia `pass_without_human`;
- rollback e testado;
- diff hash entra no scorecard.

### C3 - Code Context Builder

Memoria generica nao basta. Para programacao media/dificil, o Atlas precisa
montar contexto de codigo melhor que o operador faria manualmente.

Entradas:

- mapa do repo;
- linguagem/framework;
- arquivos provaveis;
- simbolos relacionados;
- testes relacionados;
- comandos de validacao;
- padroes locais;
- migrations/schema;
- endpoints/rotas;
- configs relevantes;
- historico de falhas Claude-only;
- task contract.

Saida:

```json
{
  "repo_profile": {},
  "likely_files": [],
  "related_tests": [],
  "validation_commands": [],
  "local_patterns": [],
  "risk_notes": [],
  "context_budget": {
    "max_chars": 60000,
    "used_chars": 0
  }
}
```

Regras:

- rankear contexto antes de incluir;
- resumir arquivos grandes;
- incluir trechos, nao repos inteiros;
- salvar hash do contexto;
- permitir replay.

Definition of done:

- `--plan-only --json` mostra o context plan;
- prompt efetivo inclui contexto ranqueado;
- benchmark registra context advantage.

### C4 - Query Engine Claude-Only

Antes do prompt chegar ao Claude, o Atlas precisa transformar o pedido bruto em
uma query operacional.

Pipeline:

```text
raw task
-> intent/dev classification
-> difficulty/risk estimate
-> task contract
-> context plan
-> validation plan
-> permission plan
-> Claude prompt contract
```

Regras:

- nenhuma etapa chama outro modelo;
- heuristicas e memoria local sao permitidas;
- quando algo for incerto, registrar incerteza;
- o prompt final precisa ser auditavel.

Definition of done:

- raw prompt e prompt final ficam salvos;
- transformacoes sao explicaveis;
- replay reusa o mesmo prompt final.

### C5 - Compaction And Context Reconstruction

Em runs longos, o Atlas precisa reconstruir contexto para o Claude sem depender
de memoria invisivel da sessao do provider.

Estrategia recomendada:

```text
atlas_reconstructed_context
```

O que preservar:

- objetivo;
- acceptance criteria;
- decisoes tomadas;
- arquivos alterados;
- diff summary;
- gate results;
- erros principais;
- tentativas anteriores;
- restricoes fair mode.

O que descartar do prompt:

- logs longos;
- outputs repetidos;
- arquivos irrelevantes;
- conversa social;
- tokens de baixo valor.

Definition of done:

- cada repair tem contexto reconstruido;
- compactacao tem artifact;
- replay nao depende da memoria do Claude.

### C6 - Prompt Cache Strategy

Prompt caching pode ser vantagem operacional, desde que nao altere o modelo nem
adicione outro provider.

Objetivo:

- reduzir custo;
- reduzir latencia;
- manter contexto estavel entre tentativas;
- separar parte fixa e parte variavel do prompt.

Partes candidatas a cache:

- system/prompt contract;
- repo profile;
- task contract;
- selected code context;
- validation plan.

Partes nao cacheaveis:

- erro da tentativa atual;
- diff atual;
- stdout/stderr atual;
- repair capsule atual.

Definition of done:

- artifact registra cache hit/miss quando observavel;
- cache nao muda protocolo fair;
- resultado continua replayable sem depender do cache.

### C7 - Permission And Security Model

O Atlas precisa ser mais governado que o Claude Code direto.

Estados:

```text
read
write
danger
operator
```

Regras:

- `read` nao escreve;
- `write` so escreve dentro de roots permitidas;
- `danger` exige flag explicita;
- benchmark usa worktree isolado;
- comando destrutivo exige policy;
- segredo detectado bloqueia pass;
- arquivo fora do escopo bloqueia pass.

Definition of done:

- policy fail-closed no fair benchmark;
- violacao vira `security_policy_failed`;
- override humano conta como intervencao.

### C8 - Gate Matrix Deterministica

O gate nao pode depender do Claude dizendo que esta certo.

Camadas:

```text
syntax/diff
tests
lint
typecheck
build
security
scope
artifact
```

Resultado final:

```text
passed
failed
unverified
invalid
```

Regra de ouro:

```text
unverified nunca conta como pass_without_human
```

Definition of done:

- falhas viram objetos estruturados;
- cada gate tem comando/artifact;
- scorecard mostra skips;
- tool skip rate entra no relatorio.

### C9 - Repair Taxonomy And Planner

Retry sem taxonomia e fraco. O Atlas precisa escolher a proxima tentativa com
base no tipo de falha.

Taxonomia:

```text
test_failed
lint_failed
typecheck_failed
build_failed
diff_check_failed
scope_violation
dirty_overlap
secret_detected
untracked_not_captured
permission_denied
provider_error
protocol_violation
unverified
```

Cada tipo define:

- dados necessarios;
- prompt de repair;
- stop rule;
- se conta como intervencao humana;
- se invalida o caso.

Definition of done:

- repair planner e deterministico;
- prompt muda conforme falha;
- stop rules sao registradas.

### C10 - Evaluation Harness Pareado

Para provar 5x, Atlas e Claude Code precisam ser medidos pelo mesmo juiz.

Harness deve:

- preparar caso;
- criar worktree Atlas;
- criar worktree Claude Code;
- alternar ordem;
- rodar comandos;
- capturar patch;
- rodar gate externo;
- registrar intervencao;
- produzir resultado pareado.

Resultado por caso:

```json
{
  "case_id": "...",
  "atlas": {},
  "claude_code": {},
  "winner": "atlas",
  "reason": "atlas_passed_without_human_claude_required_manual_repair"
}
```

Definition of done:

- comparacao nao depende de narrativa manual;
- cada caso tem dois artifacts;
- perdas e invalidos aparecem.

### C11 - Operator UX

O Atlas so substitui Claude Code se a experiencia for clara e rapida.

Saida durante execucao:

```text
Mode: fair_claude
Provider: claude_cli
Model: Claude Opus
Attempt: 1/3
Gate: running
Fallback: disabled
Worktree: isolated
```

Saida em falha:

```text
Status: failed
Reason: test_failed
Command: php artisan test --filter=...
Next: repair attempt 2/3 with same Claude Opus
```

Saida final:

```text
Status: passed
Protocol: valid
Human intervention: 0
Repair conversion: yes
Replay: ...
```

Definition of done:

- operador entende o estado em 5 segundos;
- falha final inclui comando reproduzivel;
- nenhuma acao importante fica escondida.

## Suite De Benchmark 5x

Tamanho minimo:

```text
60 casos totais
10 simples
25 medios
25 dificeis
```

Para a promessa 5x, contar apenas medios/dificeis.

Categorias obrigatorias:

- bug com teste existente;
- bug sem teste direto;
- refactor multi-arquivo;
- endpoint/API;
- CLI command;
- migration/schema;
- validacao;
- observabilidade;
- docs + code;
- dirty workspace;
- primeiro patch quebrado;
- contexto grande;
- risco de escopo;
- regressao indireta;
- build/typecheck;
- security/scope violation.

Schema de caso:

```json
{
  "case_id": "hard-refactor-service-001",
  "title": "Refactor shared service without breaking callers",
  "difficulty": "hard",
  "class": "refactor_multi_file",
  "initial_commit": "abc123",
  "setup_command": "php artisan migrate:fresh --seed",
  "task_prompt": "...",
  "allowed_files": [],
  "expected_files": [],
  "forbidden_files": [],
  "acceptance_criteria": [],
  "test_commands": [],
  "timeout_seconds": 1800,
  "judge_notes": []
}
```

## Regras Anti-Vies

- pre-registrar suite antes de rodar;
- nao remover casos em que Atlas perde;
- alternar ordem dos bracos;
- mesmo commit inicial;
- mesmo modelo;
- mesmo timeout;
- mesmo comando de teste;
- mesmo judge/gate externo;
- invalidar divergencia de provider/modelo;
- publicar casos invalidos;
- separar simples de medios/dificeis;
- registrar latencia e custo.

## Definicao De Vitoria

Atlas so pode declarar vitoria 5x nesta fase se:

```text
protocol_validity_rate = 100%
provider_violation_count = 0
fallback_violation_count = 0
atlas_decide_usage_count = 0
medium_hard_valid_cases >= 40
autonomous_success_lift >= 30pp
intervention_reduction >= 5.0x
final_gate_pass_rate_atlas >= 90%
test_failure_after_final_rate_atlas <= 5%
```

Declaracao permitida:

```text
Atlas Fair Claude reduziu intervencoes humanas em 5x em tarefas medias/dificeis
contra Claude Code CLI usando o mesmo Claude Opus.
```

Declaracao proibida:

```text
Atlas e 5x mais inteligente que Claude Code.
```

O Atlas nao e mais inteligente nesta fase. Ele e mais governado, mais
verificavel e melhor em fechar o loop.

## Ordem De Trabalho Recomendada

1. Baseline verde.
2. Fair mode flags.
3. Enforcement central.
4. Metadata/traces de fair mode.
5. Quality gate strict.
6. Repair prompt estruturado.
7. Worktree/checkpoint/rollback como produto.
8. Final packet e replay.
9. UX de produto.
10. Product readiness antes do benchmark.
11. Fase final: benchmark runner pareado.
12. Fase final: scorecard 5x.
13. Fase final: suite 60 casos.
14. Fase final: rodada piloto com 10 casos.
15. Fase final: ajustes de protocolo, sem hacks no produto.
16. Fase final: rodada oficial.
17. Fase final: relatorio release.

## Riscos E Mitigacoes

| Risco | Mitigacao |
|---|---|
| Atlas ganha usando outro provider | fair mode enforcement central |
| Claude Code baseline mal medido | runner pareado e gate externo |
| Atlas parece passar sem teste real | status `unverified` |
| Repair loop piora diff | stop rule por regressao |
| Worktree sujo contamina caso | worktree descartavel por braco |
| Untracked fica fora do patch | captura obrigatoria de untracked |
| Lint vermelho invalida release | `composer quality` |
| 5x impossivel matematicamente | usar intervencao humana como metrica principal |

## Produto Final Esperado

O operador roda:

```bash
atlas dev "implemente esta mudanca" --claude-only --model=opus --complete --auto-test
```

O Atlas:

1. trava Claude Opus;
2. cria contrato;
3. cria/checkpointa worktree;
4. monta context pack;
5. chama Claude;
6. roda gate strict;
7. se falhar, gera repair estruturado;
8. chama o mesmo Claude;
9. repete ate 3 tentativas;
10. entrega final packet;
11. registra tudo para replay e scorecard.

Esse e o caminho real para superar Claude Code de forma justa. A vantagem nao e
um truque de provider. A vantagem e transformar Claude em um executor dentro de
um sistema de engenharia completo.
