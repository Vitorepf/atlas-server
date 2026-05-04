# Atlas CLI Fair Claude Benchmark

Este documento define o produto final necessario para provar, de forma justa,
que o Atlas CLI pode superar o Claude Code CLI usando o mesmo provider e o
mesmo modelo.

O objetivo desta fase nao e vencer usando Atlas Decide, Codex, Gemini, conselho
ou roteamento multi-modelo. O objetivo e isolar a qualidade do Atlas como
harness de engenharia em volta do Claude.

Importante: esta restricao vale apenas para o modo de benchmark justo. O Atlas
normal deve continuar inteligente e multi-provider, respeitando Default AI do
app, Atlas Decide, Codex, Gemini, budgets, provider strategy e overrides
manuais. Fair Claude e opt-in por flags/comandos especificos; nao deve virar
default global nem reduzir capacidades existentes.

## Tese

Comparacao justa:

```text
Atlas CLI + Claude CLI + Claude Opus
vs
Claude Code CLI + Claude Opus
```

Se o Atlas vencer nessa condicao, a vantagem veio do produto Atlas:

- contexto melhor;
- contrato de tarefa melhor;
- execucao mais governada;
- validacao mais confiavel;
- memoria e rastreabilidade;
- recuperacao de erro;
- resumo final mais verificavel.

Depois dessa fase, o Atlas Decide multi-modelo pode entrar como etapa superior.
Mas ele nao deve ser usado para provar esta etapa.

## Hipotese De Produto

A hipotese a ser provada e estreita e mensuravel:

```text
Com o mesmo Claude Opus, o Atlas CLI entrega mais tarefas prontas sem
intervencao humana porque adiciona harness, contexto, gates e repair loop.
```

O Atlas nao precisa provar que o modelo raciocina melhor. O modelo e o mesmo.
O Atlas precisa provar que reduz falhas operacionais:

- menos respostas finais com teste quebrado;
- menos necessidade de copiar erro de volta para a IA;
- menos diffs fora de escopo;
- melhor recuperacao quando a primeira tentativa falha;
- melhor auditoria do que aconteceu.

Esta fase nao deve prometer vitoria em tarefas triviais. Em edicoes pontuais de
um arquivo, o overhead do Atlas pode empatar ou perder em tempo. A vitoria
esperada aparece em tarefas onde validacao, repair e rastreabilidade importam.

## Separacao De Trilhas

Fair Claude e uma prova controlada. Ele nao e o modo mais poderoso do Atlas.

| Trilha | Uso | Regra |
|---|---|---|
| Fair Claude | provar vantagem do harness usando o mesmo Claude | sem Codex, sem Gemini, sem Atlas Decide, sem fallback |
| Atlas Supercharged | uso real diario | Atlas Decide pode usar Gemini Scout, Codex, Claude, fallback e historico medido |

Um relatorio que misture essas trilhas e invalido. O Atlas pode ser
supercharged no dia a dia e estritamente Claude-only no benchmark, mas os
artifacts precisam declarar qual trilha foi usada em cada run.

## Posicionamento Contra Claude Code

Claude Code CLI e excelente como executor direto. O Atlas deve ser avaliado como
um produto de engenharia em volta do executor.

| Dimensao | Claude Code CLI | Atlas Fair Claude |
|---|---|---|
| Modelo | Claude Opus | Claude Opus |
| Provider | Claude | Claude |
| Execucao inicial | direta | preflight + contrato |
| Contexto | contexto da sessao/provider | context pack governado |
| Repair | usuario normalmente fecha o loop | `--complete` fecha o loop |
| Validacao | depende do uso e prompts | gate explicito |
| Auditoria | historico do terminal/provider | trace, jobs, artifacts |
| Fallback | pode ser comportamento do usuario | proibido nesta fase |
| Vitoria esperada | velocidade em simples | taxa de pronto sem humano |

O benchmark deve separar essas classes de tarefa. Se misturar tarefas triviais e
complexas sem segmentacao, o resultado fica pouco acionavel.

## Fora De Escopo Nesta Fase

Esta fase proibe:

- Codex como executor, revisor ou fallback;
- Gemini como scout, contexto longo ou fallback;
- `claude_codex`;
- council/dual review;
- Atlas Decide automatico;
- fallback automatico para outro provider;
- benchmark onde Atlas usa mais contexto externo do que o Claude Code consegue
  receber, sem registrar isso como vantagem explicita do produto;
- comparar resultados sem mesma tarefa, mesmo repo, mesmo modelo e mesmo estado
  inicial.

## Modos De Comparacao

### Fair Claude Strict

Modo para benchmark oficial.

```bash
atlas dev "..." --claude-only --model=opus --complete --auto-test
```

Regras:

- somente Claude;
- somente Opus;
- fallback proibido;
- outros providers bloqueados;
- repair loop permitido, desde que use o mesmo Claude Opus;
- testes e gates permitidos;
- worktree permitido;
- memoria/context pack permitidos se forem registrados no artifact.
- `--allow-unverified-fair-pass` deve falhar com `fair_mode_violation` quando
  qualquer flag Fair Claude estiver ativa.

### Fair Claude One Shot

Modo para medir primeira tentativa.

```bash
atlas dev "..." --claude-only --model=opus --no-decide --single-provider --auto-test --max-iterations=1
```

Regras:

- uma chamada ao Claude;
- sem repair loop;
- validação ainda roda;
- usado para medir se o Atlas melhora contexto antes do repair.

### Claude Code Baseline

Execucao externa com Claude Code CLI usando Opus.

Regras:

- mesma tarefa;
- mesmo repo;
- mesmo commit inicial;
- mesmo comando de teste;
- mesmo limite de tempo;
- usuario nao pode iterar manualmente sem registrar intervencao;
- se houver segunda tentativa manual, ela conta como intervencao humana.

## Matriz De Vitoria Esperada

| Classe De Tarefa | Expectativa Honesta |
|---|---|
| 1 arquivo simples | Atlas empata ou perde em tempo |
| bug com teste claro | Atlas deve ganhar em taxa de verde |
| refactor multi-arquivo | Atlas deve ganhar em controle e gates |
| tarefa com primeiro patch quebrado | Atlas deve ganhar por repair loop |
| tarefa com contexto grande | Atlas deve ganhar se context pack for bom |
| tarefa exploratoria sem edicao | Claude Code pode empatar |
| mudanca de risco com dirty files | Atlas deve ganhar em seguranca |

O relatorio deve mostrar resultado por classe. Uma media unica esconde onde o
produto realmente vence.

## Produto Alvo

O produto final desta fase e um modo explicito do Atlas:

```bash
atlas dev "..." --provider=claude_cli --model=opus --single-provider --no-decide --fallback-disabled --complete --auto-test
```

Aliases aceitaveis, desde que sejam equivalentes:

```bash
atlas dev "..." --claude-only --model=opus --complete --auto-test
atlas claude dev "..." --model=opus --complete --auto-test
```

Contrato obrigatorio desse modo:

- provider fixo: `claude_cli`;
- modelo fixo: Opus, vindo do app ou de `--model=opus`;
- `decision_mode=manual_override`;
- `operator_requested_provider=claude_cli`;
- nenhum outro provider pode ser chamado;
- nenhum fallback automatico;
- falha de Claude deve ser falha explicita;
- budget deve ser reportado, mas nao trocar provider;
- `allow_manual=false` deve bloquear no preflight;
- `allow_auto=false` nao deve bloquear uso manual;
- todos os artefatos devem registrar que a execucao foi `single_provider`.

Contrato de nao-regressao:

- `atlas dev "..."` continua usando a inteligencia normal do Atlas;
- Default AI do app nao muda para ganhar benchmark;
- Codex/Gemini/Atlas Decide continuam disponiveis fora de fair mode quando
  configurados e permitidos;
- `--provider` e `--model` continuam funcionando fora de fair mode;
- qualquer componente geral novo, como gate matrix, patch artifact e repair
  capsule, deve beneficiar o Atlas normal quando aplicavel.

## Integridade Do Protocolo

Cada caso precisa receber um status de protocolo independente do resultado
tecnico:

```text
valid
invalid_provider
invalid_model
invalid_fallback
invalid_context
invalid_human_intervention
invalid_missing_artifact
invalid_dirty_baseline
invalid_timeout_policy
```

Casos invalidos:

- nao contam como vitoria;
- nao contam como derrota;
- aparecem no relatorio;
- precisam explicar a causa;
- precisam preservar artifacts para auditoria.

Campos obrigatorios por braco:

```json
{
  "protocol_status": "valid",
  "provider": "claude_cli",
  "model_requested": "opus",
  "model_resolved": "claude-opus-...",
  "fallback_used": false,
  "atlas_decide_used": false,
  "other_provider_used": false,
  "human_intervention_count": 0,
  "initial_commit": "...",
  "prompt_hash": "...",
  "context_hash": "...",
  "diff_hash": "...",
  "gate_status": "passed"
}
```

## Paridade De Contexto

O Atlas pode vencer por preparar contexto melhor. Isso e parte do produto. Mas
o benchmark precisa registrar exatamente o que cada braco recebeu.

Registrar para Atlas:

- task contract;
- context pack hash;
- memory refs usadas;
- code refs usadas;
- prompt efetivo redigido;
- arquivos incluidos ou resumidos;
- validation plan.

Registrar para Claude Code:

- prompt fornecido;
- `CLAUDE.md` disponivel;
- arquivos de instrucao existentes;
- cwd;
- modelo e versao observavel;
- contexto adicional fornecido manualmente;
- limitacoes que impediram contexto equivalente.

Regra:

```text
Se Atlas recebeu contexto extra por capacidade propria auditavel, isso e
vantagem de produto. Se recebeu contexto invisivel nao registrado, o caso e
invalid_context.
```

## Criterio Para Declarar 5x

Nao declarar 5x com amostra pequena ou media misturada. Requisitos minimos:

- pelo menos 40 casos validos medios/dificeis;
- `protocol_validity_rate = 100%` nos casos contados;
- `provider_violation_count = 0`;
- `fallback_violation_count = 0`;
- `atlas_decide_usage_count = 0`;
- `intervention_reduction >= 5.0x`;
- `autonomous_success_lift >= 30pp`;
- `final_gate_pass_rate_atlas >= 90%`;
- perdas e invalidos publicados no relatorio.

Declaracao correta:

```text
Atlas Fair Claude reduziu intervencoes humanas em 5x em tarefas
medias/dificeis contra Claude Code CLI usando o mesmo Claude Opus.
```

Declaracao incorreta:

```text
Atlas e 5x mais inteligente que Claude.
```

O Atlas vence por governanca, contexto, gates e repair, nao por mudar a
inteligencia do modelo nesta fase.

## Nivel De Maturidade

### M0 - Documento

- benchmark definido;
- regras anti-vies documentadas;
- scorecard definido.

### M1 - CLI Enforced

- `--claude-only`, `--single-provider` e `--no-decide` implementados;
- preflight bloqueia providers/modelos invalidos;
- payload registra fair-mode;
- nenhum fallback e oferecido.

### M2 - Traceable

- trace, job, dev plan e quality gate registram fair-mode;
- artifacts guardam prompt/context pack/test result;
- provider choice respeita fallback disabled;
- replay preserva single-provider.

### M3 - Benchmarkable

- suite de casos em arquivo versionado;
- runner ou comando de report existe;
- scorecard compara Atlas e Claude Code;
- resultado invalido e detectado automaticamente.

### M4 - Product-Ready

- 20+ casos executados;
- relatorio mostra vitoria/empate/derrota por classe;
- Atlas vence na metrica primaria;
- docs, tests e release checklist exigem esse gate para declarar substituicao
  do Claude Code.

## Regra De Ouro

Nesta fase, o Atlas so pode vencer por ser melhor software em volta do Claude.

Ele pode usar:

- context pack proprio;
- memory do Atlas;
- code intelligence;
- task contract;
- engineering blueprint;
- preflight;
- checkpoints;
- quality gates;
- testes;
- diff summary;
- replay;
- traces;
- release packet.

Ele nao pode usar:

- outro modelo;
- outro provider;
- revisor externo;
- resposta sintetizada por outro motor;
- fallback silencioso.

## Experiencia Esperada

### Comando principal

```bash
atlas dev "corrija o bug no checkout" \
  --provider=claude_cli \
  --model=opus \
  --single-provider \
  --no-decide \
  --complete \
  --auto-test
```

### Preflight esperado

Antes de chamar Claude, o Atlas deve mostrar ou registrar:

```text
Mode: fair_claude
Provider: claude_cli
Model: Claude Opus
Decision: manual_override
Fallback: disabled
Other providers: blocked by fair benchmark mode
Workspace: <repo>
Permission: <read/write/danger>
Tests: detected
Context pack: ready
```

### Falha esperada quando tenta quebrar a regra

```bash
atlas dev "..." --single-provider --provider=codex_cli
```

Deve falhar com mensagem objetiva:

```text
Fair Claude mode exige provider claude_cli.
```

```bash
atlas dev "..." --single-provider --provider=claude_cli --model=gpt-5.5
```

Deve falhar:

```text
Modelo gpt-5.5 nao combina com provider claude_cli.
```

```bash
atlas dev "..." --single-provider --provider=claude_cli
```

Se `allow_manual=false` para Claude:

```text
Provider claude_cli esta bloqueado para uso manual pelas configuracoes do Atlas app.
```

## Diferenciais Que O Atlas Deve Provar

### 1. Contexto melhor

O Atlas deve montar um contexto mais util que uma sessao crua:

- objetivo da tarefa;
- escopo provavel;
- arquivos candidatos;
- rotas, comandos e testes relacionados;
- padroes locais;
- restricoes de permissao;
- dirty files;
- decisoes de arquitetura relevantes;
- memoria operacional curta;
- comandos de validacao;
- riscos de mudanca.

DoD:

- context pack aparece no trace;
- context pack tem tamanho controlado;
- arquivos relevantes aparecem antes dos irrelevantes;
- contexto inclui "nao tocar" quando ha dirty files fora do escopo;
- contexto nao despeja logs longos.

### 2. Contrato de tarefa melhor

Antes da edicao, o Atlas deve transformar pedido solto em contrato:

- goal;
- acceptance criteria;
- likely files;
- test strategy;
- permission mode;
- risk flags;
- done definition.

DoD:

- `atlas dev --claude-only --model=opus --plan-only --json` mostra esse contrato;
- o contrato e salvo em `dev_execution_plan`;
- o prompt enviado ao Claude inclui contrato compacto;
- o resumo final avalia contra acceptance criteria.

### 3. Execucao mais governada

O Atlas deve ser mais previsivel que abrir Claude direto:

- preflight antes de execucao;
- comando exato auditavel;
- trace id;
- checkpoint antes de mudancas;
- provider/model fixos;
- sem fallback silencioso;
- runtime permission claro.

DoD:

- trace registra `fair_mode=true`;
- trace registra `single_provider=true`;
- trace registra provider e model;
- trace registra que fallback esta desabilitado;
- qualquer tentativa de trocar provider falha.

### 4. Validacao mais forte

O Atlas deve rodar validacao melhor que uma resposta manual:

- `git diff --check`;
- testes relevantes;
- quality gate final;
- resumo de falhas;
- artifacts de teste;
- final packet com comandos executados.

DoD:

- `--auto-test` roda comando detectado;
- `--complete` tenta reparo ate passar ou atingir limite;
- falha de teste impede declarar sucesso;
- sucesso lista comandos, arquivos e resultado.

### 5. Recuperacao de erro

Quando Claude falhar, Atlas deve ser melhor em orientar a proxima acao:

- erro de auth;
- rate limit;
- provider offline;
- modelo invalido;
- permissao insuficiente;
- teste falhando;
- conflito de dirty files.

DoD:

- cada falha tem `error_code`;
- mensagem explica acao concreta;
- nao troca para outro provider;
- salva artifact suficiente para replay.

### 6. Repair loop sem humano

Este e o principal wedge competitivo.

Fluxo esperado:

1. Claude gera patch.
2. Atlas roda testes/gates.
3. Gate falha.
4. Atlas compacta erro, diff e contexto relevante.
5. Atlas chama o mesmo Claude Opus novamente.
6. Claude corrige.
7. Atlas repete ate passar ou atingir `max_iterations`.

DoD:

- cada tentativa tem `iteration`;
- cada repair prompt inclui erro estruturado e diff relevante;
- o mesmo provider/modelo e usado em todas as tentativas;
- `human_intervention_count` continua zero quando Atlas fecha o loop sozinho;
- quando atinge limite, o resultado e `unresolved`, nao sucesso parcial falso.

### 7. Isolamento seguro

O Atlas deve reduzir risco operacional.

DoD:

- benchmark pode rodar em worktree isolada;
- patch aplicado ao workspace original so ocorre quando gate passa e politica
  permite;
- dirty files fora do escopo sao detectados;
- diff com possivel segredo bloqueia sucesso;
- replay consegue reconstruir run sem depender de estado invisivel.

### 8. Final packet superior

O resultado final deve ser mais util que uma resposta livre.

DoD:

- arquivos alterados;
- resumo do diff;
- testes executados;
- status dos acceptance criteria;
- tentativas realizadas;
- falhas recuperadas;
- pendencias reais;
- comando para reproduzir.

## Implementacao Necessaria

### Fase 1 - Contrato CLI

Adicionar flags:

```bash
--single-provider
--no-decide
--claude-only
```

Regras:

- `--claude-only` implica `--provider=claude_cli --single-provider --no-decide`;
- qualquer flag Fair (`--claude-only`, `--single-provider`, `--no-decide` ou
  `--fallback-disabled`) ativa o contrato Fair Claude completo;
- em Fair Claude, provider omitido sempre resolve para `claude_cli`;
- em Fair Claude, `--model=opus` resolve para o modelo premium Claude Opus
  configurado e `--model-policy=fixed` impede drift;
- `--single-provider` nesse protocolo nao significa "default AI do app em modo
  unico"; significa provider unico travado em `claude_cli`;
- `--no-decide` impede `decision_mode=atlas_decide` e qualquer council;
- fora dessas flags Fair, o Atlas normal continua usando Default AI, Atlas
  Decide, provider strategy, budgets, fallbacks e overrides manuais.

Arquivos provaveis:

- `app/Console/Commands/AtlasCliDevCommand.php`;
- `app/Console/Commands/AiChatCommand.php`;
- `app/Services/Ai/Cli/AtlasCliDevWorkflowService.php`;
- `app/Services/Ai/AiGatewayService.php`;
- `app/Services/Ai/AiWorker.php`.

### Fase 2 - Metadata e enforcement

Payload minimo:

```json
{
  "decision_mode": "manual_override",
  "fair_mode": "claude_code_comparison",
  "single_provider": true,
  "fallback_disabled": true,
  "operator_requested_provider": "claude_cli",
  "requested_provider": "claude_cli",
  "requested_model": "claude-opus-...",
  "allowed_providers": ["claude_cli"],
  "blocked_provider_reason": "fair_single_provider_mode"
}
```

Enforcement obrigatorio:

- gateway nao pode alterar provider;
- worker nao pode enfileirar fallback;
- provider choice menu nao deve oferecer trocar provider nesse modo;
- retry pode tentar o mesmo provider/modelo;
- rate limit vira falha ou espera, nao troca provider.

Testes obrigatorios:

- `atlas dev --claude-only --model=opus --plan-only --json` produz provider
  `claude_cli`;
- `atlas dev --claude-only --provider=codex_cli` falha;
- `atlas dev --claude-only --model=5.5` falha;
- `allow_manual=false` para Claude bloqueia;
- `allow_auto=false` para Claude nao bloqueia uso manual;
- provider choice nao lista Codex/Gemini;
- rate limit nao cria fallback provider.

### Fase 2.5 - Repair loop fair

O repair loop precisa carregar a restricao single-provider.

Campos obrigatorios por tentativa:

```json
{
  "iteration": 2,
  "provider": "claude_cli",
  "model": "claude-opus-...",
  "repair_reason": "test_failed",
  "input_artifacts": ["test_failure", "diff_excerpt", "acceptance_criteria"],
  "human_intervention": false
}
```

Regras:

- `max_iterations` default do fair benchmark deve ser 3;
- `max_iterations=1` vira one-shot;
- tentativa de trocar provider no repair invalida o run;
- o prompt de repair nao deve incluir logs longos sem compactacao;
- quando o teste passa, parar imediatamente.

### Fase 3 - Benchmark Runner

Criar comando:

```bash
atlas benchmark claude-fair prepare --suite=<suite>
atlas benchmark claude-fair runbook --suite=<suite> --workspace=<atlas-workspace> --claude-code-baseline-workspace=<separate-workspace>
atlas benchmark claude-fair run-atlas --case=<id>
atlas benchmark claude-fair run-claude-code --case=<id>
atlas benchmark claude-fair report --suite=<suite>
```

Se o runner completo for demais na primeira versao, criar pelo menos:

```bash
atlas fair report --suite=<path-or-dir>
```

O benchmark deve registrar:

- repo;
- branch;
- commit inicial;
- dirty state;
- tarefa;
- comando Atlas;
- comando Claude Code;
- modelo usado;
- tempo;
- arquivos alterados;
- testes rodados;
- resultado dos testes;
- numero de tentativas;
- intervencoes humanas;
- avaliacao final.

### Runbook Da Bateria Real

Antes de executar uma bateria oficial, rode o doctor/runbook:

```bash
atlas benchmark claude-fair prepare --suite=atlas-fair-claude-v1 --json

atlas benchmark claude-fair runbook \
  --suite=atlas-fair-claude-v1 \
  --workspace=/path/to/atlas-arm-workspace \
  --claude-code-baseline-workspace=/path/to/separate-claude-code-workspace \
  --claude-code-baseline-binary=claude \
  --json
```

O `runbook` deve retornar `ready_to_start_battery=true` antes de uma bateria
real. Ele valida, no minimo:

- suite preparada;
- corpus release minimo;
- workspace Atlas existente;
- workspace Claude Code baseline existente e separado;
- binario Claude Code resolvivel;
- comandos exatos para `run`, `run-atlas`, `run-claude-code`, `report`,
  `readiness` e `replay`.

Para rodar a bateria pareada completa:

```bash
atlas benchmark claude-fair run \
  --suite=atlas-fair-claude-v1 \
  --workspace=/path/to/atlas-arm-workspace \
  --claude-code-baseline-workspace=/path/to/separate-claude-code-workspace \
  --claude-code-baseline-binary=claude \
  --model=opus \
  --model-policy=fixed \
  --gate-profile=strict \
  --json
```

Cada caso usa `task_contract.test_commands[0]` como comando deterministico
default quando o operador nao passa `--test-command`. Um `--test-command`
manual ainda pode sobrescrever esse default para uma bateria controlada.

Schema minimo de resultado:

```json
{
  "case_id": "bug-existing-test-001",
  "runner": "atlas_fair_claude",
  "provider": "claude_cli",
  "model": "claude-opus-...",
  "initial_commit": "abc123",
  "final_commit": null,
  "duration_seconds": 420,
  "attempt_count": 2,
  "human_intervention_count": 0,
  "tests": [
    {
      "command": "php artisan test --filter=CheckoutTest",
      "exit_code": 0
    }
  ],
  "acceptance": {
    "passed": true,
    "notes": []
  },
  "diff": {
    "files_changed": 3,
    "lines_added": 42,
    "lines_removed": 17
  },
  "protocol": {
    "fair_mode": true,
    "single_provider": true,
    "fallback_violation": false
  },
  "verdict": "passed"
}
```

Schema minimo de caso:

```json
{
  "id": "bug-existing-test-001",
  "class": "bug_existing_test",
  "title": "Corrigir bug no checkout",
  "repo": "atlas-server",
  "setup_command": "git checkout benchmark/bug-existing-test-001",
  "task_prompt": "Corrija o bug...",
  "test_commands": [
    "php artisan test --filter=CheckoutTest"
  ],
  "acceptance_criteria": [
    "Teste CheckoutTest passa",
    "Nao alterar API publica"
  ],
  "expected_files": [
    "app/Services/CheckoutService.php",
    "tests/Feature/CheckoutTest.php"
  ],
  "risk": "medium",
  "time_limit_minutes": 20
}
```

### Fase 4 - Suite De Comparacao

Criar uma suite inicial com 20 tarefas:

1. bug pequeno com teste existente;
2. bug pequeno sem teste;
3. refactor local;
4. refactor em servico compartilhado;
5. adicionar comando CLI;
6. alterar endpoint API;
7. corrigir teste quebrado;
8. melhorar mensagem de erro;
9. adicionar validacao;
10. criar migration simples;
11. lidar com dirty files;
12. investigar comportamento sem editar;
13. editar docs + codigo;
14. implementar feature pequena;
15. corrigir regressao;
16. reduzir duplicacao;
17. melhorar observabilidade;
18. ajustar runtime permission;
19. teste de recuperacao apos falha;
20. tarefa longa com `--complete`.

Cada caso deve ter:

- descricao;
- setup;
- estado inicial;
- comando de teste;
- criterio de aceite;
- risco principal;
- expected files.

### Fase 5 - Scorecard

Score por caso:

| Criterio | Peso |
|---|---:|
| Passou testes obrigatorios | 30 |
| Resolveu acceptance criteria | 25 |
| Menos intervencao humana | 15 |
| Diff menor e mais focado | 10 |
| Explicacao final verificavel | 10 |
| Tempo ate solucao | 5 |
| Nao quebrou regra fair-mode | 5 |

Resultado final:

```text
Atlas wins      >= 55% dos casos e nenhum gate critico quebrado
Claude wins     Claude Code vence em qualidade/tempo/teste
Tie             diferenca dentro da margem
Invalid         modelo/provider/protocolo diferente
```

Gate minimo para declarar vitoria:

- pelo menos 20 casos;
- mesmo modelo Opus nos dois lados;
- nenhum uso de Codex/Gemini/Atlas Decide pelo Atlas;
- `php artisan test` ou teste relevante passa nos casos resolvidos;
- logs suficientes para auditoria;
- Atlas vence em qualidade geral e nao apenas tempo.

### Fase 6 - Relatorio Executivo

O relatorio final precisa responder:

- Atlas venceu, empatou ou perdeu?
- Em quais classes de tarefa?
- Qual foi a taxa de tarefas prontas sem humano?
- Quantos repairs foram necessarios?
- Quantos repairs converteram falha em sucesso?
- Onde o Claude Code foi mais rapido?
- Onde o Atlas teve overhead injustificavel?
- Houve violacao do protocolo fair?
- Qual melhoria aumenta mais a proxima rodada?

Formato minimo:

```text
Suite: atlas-fair-claude-v1
Cases: 20
Valid cases: 20
Invalid cases: 0
Atlas pass without human: 16/20
Claude Code pass without human: 11/20
Atlas repair conversions: 5
Median time Atlas: 9m20s
Median time Claude Code: 6m10s
Winner: Atlas
Reason: +25pp pass-without-human, despite +3m10s median latency
```

## Metricas Primarias E Secundarias

### Metrica primaria

```text
pass_without_human_rate
```

Definicao:

```text
casos em que a tarefa atende acceptance criteria e testes passam sem intervencao
humana depois do comando inicial / total de casos validos
```

Esta e a metrica que decide a fase.

### Metricas secundarias

- `repair_conversion_rate`: falhas de primeira tentativa convertidas em sucesso
  pelo Atlas;
- `first_attempt_pass_rate`: qualidade da primeira resposta com mesmo modelo;
- `time_to_green`: tempo ate testes verdes;
- `diff_focus_score`: proporcao de arquivos esperados vs arquivos tocados;
- `regression_rate`: casos que passam teste alvo mas quebram suite ampliada;
- `protocol_validity_rate`: execucoes sem violacao fair-mode.

### Interpretacao

- Atlas pode perder em `time_to_green` e ainda vencer se ganhar claramente em
  `pass_without_human_rate`.
- Atlas nao vence se precisar de intervencao humana frequente.
- Atlas nao vence se burlar protocolo com outro provider.
- Atlas nao vence se gerar diffs grandes demais para tarefas simples.

## Anti-Vies E Regras De Justica

Para o resultado ser confiavel:

- alternar a ordem de execucao Atlas/Claude Code por caso;
- resetar repo para commit inicial antes de cada runner;
- usar mesmo prompt de tarefa;
- usar mesmo modelo Opus;
- usar mesmos comandos de teste;
- registrar qualquer intervencao humana;
- invalidar caso com diferenca de setup;
- invalidar caso com provider/modelo incorreto;
- nao escolher apenas tarefas onde harness tem vantagem;
- reportar tarefas simples separadamente;
- publicar casos perdidos pelo Atlas.

## Controle De Latencia

Atlas tem overhead. O benchmark deve tratar latencia com honestidade.

Classes:

- `latency_acceptable`: Atlas mais lento, mas passa sem humano;
- `latency_problem`: Atlas passa, mas demora demais para tarefa simples;
- `latency_win`: Atlas passa e Claude Code exigiu iteracao humana;
- `latency_loss`: Claude Code passa rapido e Atlas nao agrega valor.

Tolerancia inicial:

```text
Atlas pode ser ate 2x mais lento em tarefas medias/complexas se aumentar
pass_without_human_rate em pelo menos 15 pontos percentuais.
```

Em tarefas simples, tolerancia deve ser menor:

```text
Atlas nao deve ser mais de 50% mais lento em tarefa simples se ambos passam na
primeira tentativa.
```

## Protocol Violations

Qualquer item abaixo invalida o caso:

- provider diferente de `claude_cli` no Atlas;
- modelo diferente de Opus;
- fallback para Codex/Gemini;
- Atlas Decide selecionando provider;
- conselho/dual review;
- intervencao humana nao registrada;
- teste diferente entre runners;
- repo nao resetado;
- edicao manual fora da ferramenta;
- acceptance criteria alterado apos ver resultado.

Violacoes devem aparecer no relatorio, nao serem escondidas.

## Metricas

### Quantitativas

- `pass_rate`;
- `acceptance_rate`;
- `human_intervention_count`;
- `attempt_count`;
- `test_failures_after_final`;
- `files_changed_count`;
- `diff_line_count`;
- `time_to_first_patch`;
- `time_to_green`;
- `cost_estimate_tokens`;
- `provider_error_count`;
- `fallback_violation_count`.

### Qualitativas

- clareza do plano;
- foco do diff;
- preservacao de estilo local;
- qualidade da explicacao final;
- facilidade de continuar a sessao;
- qualidade da recuperacao quando falha.

## Instrumentacao Necessaria

Campos que devem existir em trace/job/metadata:

- `fair_mode`;
- `fair_mode_name`;
- `single_provider`;
- `fallback_disabled`;
- `allowed_providers`;
- `provider_lock`;
- `model_lock`;
- `attempt_count`;
- `human_intervention_count`;
- `repair_conversion`;
- `first_attempt_status`;
- `final_gate_status`;
- `protocol_violations`;
- `benchmark_case_id`;
- `benchmark_suite_id`.

Eventos desejados:

- `fair.preflight.passed`;
- `fair.preflight.failed`;
- `fair.attempt.started`;
- `fair.attempt.completed`;
- `fair.gate.failed`;
- `fair.repair.started`;
- `fair.repair.converted`;
- `fair.protocol.violation`;
- `fair.result.recorded`.

## UX Final Esperada

### Preflight humano

```text
Atlas Fair Claude
Provider lock: claude_cli
Model lock: Claude Opus
Decision: manual override
Fallback: disabled
Repair loop: enabled, max 3
Tests: php artisan test --filter=...
Worktree: isolated
Protocol: valid
```

### Resultado final

```text
Atlas Fair Claude result: passed
Attempts: 2
Repair conversion: yes
Human intervention: 0
Tests: passed
Files changed: 3
Protocol violations: 0
Reproduce: atlas benchmark claude-fair replay <id>
# ou:
atlas benchmark claude-fair replay --run-id=<id>
```

### Resultado invalido

```text
Atlas Fair Claude result: invalid
Reason: provider_changed
Expected provider: claude_cli
Actual provider: codex_cli
```

## Comandos De Uso Esperados

### Preparar provider e settings

No app:

- Default AI pode ser Claude, Codex ou Gemini;
- Claude Opus deve estar configurado como modelo premium;
- Claude manual precisa estar ON;
- automatico pode ficar como o operador quiser.

No CLI:

```bash
atlas setup --json
atlas providers --refresh
atlas doctor --strict --run-tests
```

### Rodar caso no Atlas

```bash
atlas dev "tarefa do caso" \
  --claude-only \
  --model=opus \
  --complete \
  --auto-test \
  --json
```

### Rodar caso no Claude Code

O comando exato depende da CLI instalada, mas o registro deve conter:

```text
provider=claude_code_cli
model=opus
repo=<repo>
case=<case-id>
command=<comando usado>
```

O resultado do Claude Code deve ser validado com os mesmos comandos de teste.

## Definition Of Done

Esta fase esta pronta quando:

- existe modo `--claude-only` ou equivalente;
- `atlas dev --claude-only --model=opus --plan-only --json` mostra contrato justo;
- nenhum trace fair-mode contem provider diferente de `claude_cli`;
- rate limit/falha nao aciona fallback para Codex/Gemini;
- provider choice nao oferece troca de provider em fair-mode;
- benchmark suite tem pelo menos 20 casos;
- scorecard gera relatorio comparavel;
- `atlas doctor --run-tests --strict` passa;
- `git diff --check` passa;
- existe pelo menos uma rodada completa Atlas vs Claude Code usando Opus;
- o relatorio mostra onde Atlas ganhou, perdeu ou empatou.

## Definition Of Done Por Camada

### CLI

- flags implementadas;
- aliases documentados;
- mensagens de erro claras;
- JSON machine-readable;
- plan-only mostra protocolo.

### Gateway

- manual override respeitado;
- fallback bloqueado;
- provider choice bloqueado;
- rate limit nao troca provider.

### Worker

- tenta somente provider/modelo travados;
- registra tentativa;
- registra erro;
- nao reclassifica falha como sucesso.

### Repair

- max 3 tentativas por default;
- prompt de repair compacto;
- mesmo Claude Opus em todas as tentativas;
- sucesso exige gate verde.

### Benchmark

- casos versionados;
- resultados versionados ou persistidos;
- relatorio comparativo;
- invalidacao automatica.

### Release

- checklist exige Fair Claude Gate quando a promessa for substituir Claude Code;
- docs atualizados;
- suite de benchmark anexada ao release;
- `doctor --run-tests --strict` verde.

## Resultado Esperado

O Atlas so deve ser declarado superior ao Claude Code nesta fase se ganhar por:

- menos intervencao manual;
- mais testes verdes;
- melhor preservacao de contexto;
- menos regressao;
- melhor final packet;
- melhor continuidade de sessao;
- rastreabilidade melhor.

Se o Atlas ganhar apenas por ter usado outro provider, outro modelo ou fallback,
o resultado e invalido.

## Proxima Etapa Depois Da Vitoria Justa

Somente depois de vencer ou empatar consistentemente no modo Claude-only, abrir a
fase Atlas Decide:

```text
Atlas Decide + Claude/Codex/Gemini
vs
Claude Code CLI
vs
Codex CLI
```

Essa fase mede inteligencia de roteamento. A fase atual mede qualidade do Atlas
como produto de engenharia usando o mesmo Claude.
