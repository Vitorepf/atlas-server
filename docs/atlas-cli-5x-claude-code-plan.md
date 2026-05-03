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

Documento de protocolo experimental:

- `docs/atlas-cli-fair-claude-benchmark.md`

Este documento e o plano de implementacao para fazer esse protocolo virar uma
vantagem real de produto.

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
- scorecard pareado;
- final packet verificavel.

Em tarefas simples de um arquivo, o Atlas pode empatar ou perder em tempo. A
meta 5x se aplica a programacao media/dificil, onde falhas, testes,
regressoes, contexto e reparo importam.

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

## Bloqueios Criticos

### B1 - Fair Claude Mode Ainda Nao E Contrato Executavel

O documento de benchmark exige:

```bash
atlas dev "..." --claude-only --model=opus --complete --auto-test
atlas dev "..." --provider=claude_cli --model=opus --single-provider --no-decide --complete --auto-test
```

O produto precisa implementar e validar essas flags.

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
  |
  v
Preflight Contract
  - workspace
  - dirty files
  - acceptance criteria
  - test matrix
  - context pack
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

Definition of done:

- `repair_conversion_rate` medido;
- cada tentativa tem artifact;
- final report mostra primeira tentativa vs final;
- nenhum repair troca provider/modelo.

### P7 - Worktree Obrigatorio No Benchmark

Objetivo: evitar dano e garantir comparacao justa.

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

### P8 - Claude Code Baseline Runner

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

### P9 - Scorecard 5x

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

### P10 - UX De Produto

Objetivo: o Atlas precisa ser melhor para usar, nao so melhor no benchmark.

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
7. Worktree obrigatorio no benchmark.
8. Claude Code baseline runner.
9. Scorecard pareado.
10. Suite 60 casos.
11. Rodada piloto com 10 casos.
12. Ajustes de repair/gates.
13. Rodada oficial.
14. Relatorio release.

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
