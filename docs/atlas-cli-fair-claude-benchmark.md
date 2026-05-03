# Atlas CLI Fair Claude Benchmark

Este documento define o produto final necessario para provar, de forma justa,
que o Atlas CLI pode superar o Claude Code CLI usando o mesmo provider e o
mesmo modelo.

O objetivo desta fase nao e vencer usando Atlas Decide, Codex, Gemini, conselho
ou roteamento multi-modelo. O objetivo e isolar a qualidade do Atlas como
harness de engenharia em volta do Claude.

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

## Produto Alvo

O produto final desta fase e um modo explicito do Atlas:

```bash
atlas dev "..." --provider=claude_cli --model=opus --single-provider --no-decide --complete --auto-test
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

- `atlas dev --plan-only --single-provider --model=opus` mostra esse contrato;
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
- `--single-provider` exige provider resolvido antes do envio;
- `--no-decide` impede `decision_mode=atlas_decide`;
- em modo `dev`, se provider for omitido com `--claude-only`, usar `claude_cli`;
- se provider for omitido com `--single-provider`, usar default do app, mas registrar
  que a execucao e single-provider.

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

### Fase 3 - Benchmark Runner

Criar comando:

```bash
atlas benchmark claude-fair prepare --suite=<suite>
atlas benchmark claude-fair run-atlas --case=<id>
atlas benchmark claude-fair record-claude-code --case=<id>
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
