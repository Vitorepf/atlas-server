> Cleanup status: archived.
> Canonical replacement: docs/atlas-cli-5x-claude-code-plan.md; docs/atlas-cli-fair-claude-benchmark.md.
> Cleanup note: Prompt for an implementation provider, not policy. Preserve for history; benchmark authority is the Fair Claude plan/protocol.

# Prompt Para Implementacao Codex - Atlas Fair Claude 5x

Use este prompt quando for pedir ao Codex para implementar, por etapas, o plano
do Atlas CLI para superar o Claude Code CLI em programacao media/dificil usando
somente Claude/Opus como motor de IA no benchmark justo.

Este prompt e para execucao profissional longa. Ele deve orientar o Codex a
entregar produto, nao apenas patches soltos.

## Prompt

```text
Voce esta trabalhando no repositorio Atlas em /Users/vitorepf/Develop/atlas/atlas-server.

Missao:
Implementar, por etapas, o modo Atlas Fair Claude 5x: um fluxo onde o Atlas CLI usa exclusivamente claude_cli + Claude Opus quando o usuario pedir fair mode. O objetivo imediato e tornar o Atlas muito mais eficiente em tarefas medias/dificeis porque ele adiciona harness, contexto, gates, repair loop, worktree, telemetria, final packet e replay. Benchmark pareado contra Claude Code e a ultima fase, usada para provar o ganho depois que o produto estiver forte.

Restricao absoluta:
Nesta etapa, nao use Codex, Gemini, Atlas Decide, council, claude_codex, fallback multi-provider, subagentes de outros modelos ou qualquer revisao externa para melhorar o resultado do benchmark. O Codex pode implementar o software, mas o produto implementado deve medir e executar o benchmark usando apenas Claude/Opus. Toda melhoria deve ser justa: Atlas + Claude Opus vs Claude Code + Claude Opus.

Regra critica de produto:
Nao degrade o Atlas normal para ganhar o benchmark. O Atlas normal deve
continuar inteligente, respeitando Default AI do app, provider strategy, Atlas
Decide, Codex, Gemini, budgets, provider health e overrides manuais quando o
usuario pedir. Fair Claude e um modo explicito, acionado por flags/comandos de
benchmark. Fora desse modo, nao force Claude, nao bloqueie outros providers e
nao mude defaults globais.

Exemplos de comandos normais que devem continuar funcionando quando permitidos
pela configuracao:
- atlas dev "..."
- atlas dev "..." --provider=codex_cli --model=5.5
- atlas dev "..." --provider=gemini_cli --model=3.1-pro
- atlas dev "..." --model=<alias-ou-id-configurado>
- atlas ask "..."
- atlas debug "..."

Exemplos de comandos fair/benchmark que devem travar Claude Opus:
- atlas dev "..." --claude-only --model=opus --complete --auto-test
- atlas dev "..." --provider=claude_cli --model=opus --single-provider --no-decide --fallback-disabled --complete --auto-test
- atlas benchmark claude-fair run-atlas --case=<id> --model=opus

Documentos canonicos:
- docs/atlas-cli-fair-claude-benchmark.md
- docs/atlas-cli-5x-claude-code-plan.md
- docs/atlas-cli-5x-codex-safety-context-prompt.md
- docs/atlas-cli-final-product.md
- docs/atlas-cli-release-checklist.md

Antes de editar:
1. Leia os documentos canonicos acima.
2. Leia os comandos e servicos principais:
   - bin/atlas
   - app/Console/Commands/AtlasCliDevCommand.php
   - app/Console/Commands/AiChatCommand.php
   - app/Services/Ai/ClaudeCliProvider.php
   - app/Services/Ai/Cli/AtlasCliDevWorkflowService.php
   - app/Services/Ai/Cli/AtlasCliQualityService.php
   - app/Services/Ai/Cli/AtlasCliProviderStrategyService.php
   - app/Services/Ai/AiGatewayService.php
   - app/Services/Ai/AiWorker.php
   - app/Services/Ai/AiProviderChoiceBuilder.php
   - app/Services/Engineering/EngineeringHarnessRunnerService.php
   - app/Services/Engineering/EngineeringBenchmarkService.php
3. Verifique o estado do worktree com git status --short.
4. Nao reverta mudancas existentes do usuario.
5. Mantenha o escopo focado no modo Fair Claude.

Principio de arquitetura:
O Atlas nao deve ser "um wrapper fino em volta do Claude". Ele deve ser um harness de engenharia:
- preflight;
- task contract;
- code context builder;
- prompt contract;
- worktree/checkpoint;
- Claude invocation fingerprint;
- gate matrix;
- repair capsule;
- repair loop;
- final packet;
- replay;
- benchmark pareado apenas na fase final de prova.

Componentes como gate matrix, repair capsule, patch artifact, worktree,
telemetria e final packet devem ser implementados como melhorias gerais do
Atlas quando fizer sentido. A parte Claude-only deve ficar isolada em fair mode
policy. Nao copie logica nem crie hacks especificos que prejudiquem Codex,
Gemini ou Atlas Decide fora do benchmark.

Resultado final esperado:
O operador deve conseguir rodar:

atlas dev "implemente esta tarefa" --claude-only --model=opus --complete --auto-test

E o Atlas deve:
1. travar provider em claude_cli;
2. travar modelo em Claude Opus;
3. bloquear Atlas Decide/council/fallback/downgrade;
4. criar preflight com contrato de tarefa;
5. montar contexto auditavel;
6. chamar o Claude CLI com fingerprint persistido;
7. rodar gate deterministico;
8. se falhar, gerar Claude Repair Capsule;
9. chamar o mesmo Claude/Opus novamente;
10. repetir ate max iterations;
11. produzir final packet com protocolo, tentativas, testes, diff e replay;
12. registrar metricas e artifacts para replay e, no futuro, benchmark.

Definicao de sucesso do produto:
- Em fair mode, nenhum run pode usar provider diferente de claude_cli.
- Em fair mode, nenhum run pode usar modelo diferente do Opus resolvido.
- Em fair mode, fallback, downgrade, Atlas Decide, council e provider handoff sao proibidos.
- Violacao de protocolo vira fair_mode_violation e invalida o caso.
- pass_without_human so pode ser true quando gates deterministas passam e human_intervention_count = 0.
- unverified nunca conta como passed.
- Todos os artifacts devem ser reproduziveis.

Plano de implementacao por etapas:

Etapa 0 - Baseline e orientacao
- Rodar ou inspecionar:
  - git status --short
  - composer validate --strict
  - php artisan test --stop-on-failure
  - git diff --check
- Se algum gate falhar por mudanca preexistente, registre claramente e nao mascare.
- Se for mudanca apenas de codigo, rode testes focados e pelo menos git diff --check.

Etapa 1 - FairClaudePolicy central
Criar uma policy/service central, por exemplo:
- app/Services/Ai/FairClaudePolicy.php

Responsabilidades:
- detectar fair mode;
- normalizar flags;
- validar provider;
- validar modelo;
- validar decision mode;
- validar fallback disabled;
- validar allowed providers;
- gerar metadata padrao;
- gerar erro estruturado de violacao.

Metadata padrao:
{
  "fair_mode": true,
  "fair_mode_name": "claude_code_comparison",
  "single_provider": true,
  "provider_lock": "claude_cli",
  "model_lock": "opus",
  "fallback_disabled": true,
  "atlas_decide_disabled": true,
  "council_disabled": true,
  "allowed_providers": ["claude_cli"]
}

Testes:
- fair mode aceita claude_cli + opus;
- fair mode rejeita codex_cli;
- fair mode rejeita gemini_cli;
- fair mode rejeita claude_codex;
- fair mode rejeita modelo de Codex;
- fair mode bloqueia fallback/downgrade.

Etapa 2 - Flags CLI reais
Adicionar em AtlasCliDevCommand:
- --claude-only
- --single-provider
- --no-decide
- --fallback-disabled

Regras:
- --claude-only implica provider=claude_cli.
- --claude-only implica single-provider=true.
- --claude-only implica no-decide=true.
- --claude-only implica fallback-disabled=true.
- --claude-only sem --model usa alias opus/premium Claude configurado no app.
- --model=opus deve resolver para premium_model do Claude.
- allow_manual=false para Claude deve bloquear.
- allow_auto=false nao deve bloquear uso manual.
- Sem --claude-only/--single-provider, nao alterar comportamento inteligente normal.
- Nao mudar Default AI global.
- Nao remover suporte a --provider/--model para Codex/Gemini fora do fair mode.

Atualizar bin/atlas/completion se necessario.

Testes:
- atlas dev --claude-only --model=opus --plan-only --json mostra provider claude_cli.
- atlas dev --claude-only --provider=codex_cli falha.
- atlas dev --claude-only --model=5.5 falha.
- plan-only inclui fair metadata.
- atlas dev --provider=codex_cli --model=5.5 --plan-only --json continua funcionando fora do fair mode quando configurado.
- atlas dev --provider=gemini_cli --model=<alias-configurado> --plan-only --json continua funcionando fora do fair mode quando configurado.

Etapa 3 - Propagacao para AiChatCommand/gateway/worker
Garantir que fair metadata chegue no payload do job e no trace.

Implementar enforcement em:
- AiChatCommand antes de enfileirar;
- AiGatewayService antes de escolher provider;
- AiWorker antes de executar provider;
- AiProviderChoiceBuilder para nao oferecer switch/downgrade;
- provider handoff para bloquear em fair mode.

Comportamento correto:
- Claude rate limited: retry/wait/fail; nunca trocar provider.
- Claude auth expired: fail com mensagem de login; nunca trocar provider.
- Modelo Opus indisponivel: fail; nunca downgrade.

Testes:
- job fair com provider divergente vira fair_mode_violation.
- rate limit em fair mode nao lista Codex/Gemini.
- worker nao requeue com provider diferente.

Etapa 4 - Claude invocation fingerprint
Registrar por tentativa:
- binary path;
- claude --version quando possivel;
- args efetivos;
- cwd;
- modelo solicitado;
- modelo resolvido;
- permission mode;
- add_dirs;
- timeout;
- output format;
- session policy;
- prompt hash;
- context pack hash.

Onde persistir:
- job metadata;
- trace metadata;
- dev execution plan;
- benchmark result artifact quando existir.

Testes:
- invocation fingerprint aparece em plan/result JSON.
- modelo divergente invalida score.

Etapa 5 - Prompt contract e context pack efetivo
Criar/ajustar renderizacao do prompt para fair mode com secoes:
- Atlas Fair Claude Mode;
- Task Contract;
- Repository Context;
- Files And Scope;
- Validation Commands;
- Constraints;
- Expected Response Contract.

Regras:
- menor diff possivel;
- nao tocar arquivos fora do escopo;
- nao declarar sucesso sem validacao externa;
- responder com resumo, arquivos alterados, testes esperados e riscos;
- lembrar que o Atlas validara externamente;
- lembrar que o modo fair usa somente Claude Opus.

Garantir que task contract/context pack realmente chegue ao prompt enviado ao Claude, principalmente no caminho --task-id e no benchmark.

Testes:
- snapshot/unit test do prompt contract.
- prompt inclui fair constraints.
- prompt nao inclui outro provider.

Etapa 6 - Gate Matrix
Expandir AtlasCliQualityService ou integrar com EngineeringTestMatrixService para perfis:
- smoke;
- standard;
- strict;
- release.

No perfil strict/fair:
- git status --short;
- git diff --check;
- git diff --stat;
- captura de untracked;
- teste focado quando detectavel;
- teste configurado/explicito;
- lint se configurado;
- typecheck/build se configurado;
- secret scan minimo;
- scope check;
- final packet validation.

Status:
- passed;
- failed;
- unverified;
- invalid.

Regra:
Sem evidencia executavel suficiente => unverified.
unverified nunca conta como pass_without_human.

Testes:
- sem testes/comandos retorna unverified em strict.
- git diff --check falhando bloqueia.
- forbidden file touched bloqueia.
- untracked entra no artifact.

Etapa 7 - Claude Repair Capsule
Substituir repair prompt raso por repair estruturado.

Capsula minima:
{
  "failure_type": "test_failed",
  "command": "...",
  "exit_code": 1,
  "primary_error": "...",
  "failing_tests": [],
  "files_likely_related": [],
  "diff_summary": "...",
  "scope_warnings": [],
  "previous_attempt_summary": "...",
  "next_repair_focus": []
}

Taxonomia:
- test_failed;
- lint_failed;
- typecheck_failed;
- build_failed;
- diff_check_failed;
- scope_violation;
- dirty_overlap;
- secret_detected;
- permission_denied;
- provider_error;
- protocol_violation;
- unverified.

Stop rules:
- provider/modelo viola fair mode;
- diff piora claramente;
- arquivos proibidos alterados;
- duas tentativas pioram gate;
- precisa de decisao humana.

Testes:
- repair prompt inclui command/primary_error/diff_summary.
- repair preserva provider/modelo.
- stop rule registra reason_if_stopped.

Etapa 8 - Claude-only failure memory
Criar memoria/registro separado para aprendizados de falhas de runs Claude-only.

Regras:
- so entra provider=claude_cli.
- so entra fair/protocolo valido.
- nunca incluir Codex/Gemini/Atlas Decide.
- artifact registra memory_scope=claude_only.

Uso:
- no maximo 3 padroes relevantes no prompt;
- sempre com id/hash;
- nunca substituir acceptance criteria.

Testes:
- memoria de outro provider nao entra no fair mode.
- artifact mostra memoria usada.

Etapa 9 - Worktree/checkpoint como produto
Antes de pensar em benchmark, tornar `atlas dev --complete` seguro:
- criar checkpoint antes da primeira tentativa;
- proteger dirty files;
- permitir worktree isolada quando risco pedir;
- capturar tracked e untracked;
- bloquear apply-back inseguro;
- oferecer rollback claro;
- registrar workspace/head/diff hash.

Testes:
- dirty overlap bloqueia sucesso automatico.
- untracked capturado.
- rollback/final packet exibe comando de recuperacao.

Etapa 10 - Final packet e replay
Todo run de `atlas dev --complete` deve terminar com final packet:
- status;
- protocol valid;
- attempts;
- repair conversion;
- human intervention count;
- files changed;
- diff hash;
- tests/gates;
- skips e motivo;
- risks;
- replay command;
- rollback command quando aplicavel;
- trace id.

Testes:
- passed/failed/unverified/invalid aparecem corretamente.
- replay metadata existe.
- final packet nao depende de autoavaliacao do Claude.

Etapa 11 - UX final de produto
Durante execucao mostrar:
- Mode: fair_claude quando fair mode estiver ativo;
- Provider;
- Model;
- Attempt n/3;
- Gate status;
- Fallback disabled quando aplicavel;
- Worktree/checkpoint status;
- next action.

Fora do fair mode, UX deve continuar normal e inteligente.

Etapa 12 - Product readiness antes do benchmark
So declarar pronto para benchmark quando:
- fair mode estiver enforceado no CLI/gateway/worker;
- fluxo normal fora de fair mode estiver preservado;
- provider/model lock auditavel;
- context pack efetivo;
- gate matrix funcionando em `atlas dev --complete`;
- repair capsule em uso;
- worktree/checkpoint/rollback prontos;
- final packet e replay prontos;
- testes relevantes verdes;
- git diff --check verde.

Etapa 13 - Fase final: Claude Code baseline runner
Implementar apenas depois de Etapa 12.

Comandos:
- atlas benchmark claude-fair prepare --suite=<suite>
- atlas benchmark claude-fair run-atlas --case=<id>
- atlas benchmark claude-fair run-claude-code --case=<id>
- atlas benchmark claude-fair report --suite=<suite>

Para Claude Code baseline registrar:
- comando exato;
- versao;
- modelo Opus;
- cwd;
- contexto inicial;
- CLAUDE.md se usado;
- timeout;
- stdout/stderr;
- patch;
- gates;
- human_intervention_count.

Regra:
Se o usuario precisar copiar erro e pedir nova tentativa no Claude Code, conta como intervencao humana.

Etapa 14 - Fase final: scorecard 5x
Implementar metricas:
- protocol_validity_rate;
- pass_without_human_rate;
- pass_without_human_rate_medium_hard;
- repair_conversion_rate;
- final_gate_pass_rate;
- intervention_reduction;
- autonomous_success_lift;
- time_to_green;
- cost_per_green_case;
- invalid_case_count;
- provider_violation_count;
- fallback_violation_count.

Definicao de pass_without_human:
passed && final_gate_passed && protocol_valid && human_intervention_count == 0 && provider_violation_count == 0 && fallback_violation_count == 0

Relatorio:
- JSON;
- tabela humana;
- segmentacao por dificuldade/classe;
- perdas do Atlas visiveis;
- invalidos separados.

Etapa 15 - Fase final: suite 60 casos
Criar estrutura versionada de casos, nao necessariamente todos implementados no primeiro PR.

Schema:
{
  "case_id": "...",
  "title": "...",
  "difficulty": "medium|hard|simple",
  "class": "...",
  "initial_commit": "...",
  "setup_command": "...",
  "task_prompt": "...",
  "allowed_files": [],
  "expected_files": [],
  "forbidden_files": [],
  "acceptance_criteria": [],
  "test_commands": [],
  "timeout_seconds": 1800,
  "judge_notes": []
}

Categorias:
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

Etapa 16 - Testes e qualidade
Ao final de cada etapa:
- rode testes focados;
- rode git diff --check;
- se mexer em comandos CLI, adicione/atualize testes feature;
- se mexer em service puro, adicione/atualize unit tests;
- se mexer em docs, valide links/menções principais.

Nunca encerrar com:
- fair mode so documentado e nao enforceado;
- fallback ainda possivel em fair mode;
- pass_without_human baseado em autoavaliacao do Claude;
- unverified contando como passed;
- benchmark implementado antes do Atlas estar produto-ready;
- provider/modelo divergente sem invalidacao.
- fluxo normal do Atlas travado em Claude-only;
- defaults globais mudados para favorecer o teste;
- Codex/Gemini/Atlas Decide degradados fora do fair mode.

Como trabalhar:
1. Comece por uma etapa pequena e entregue completa.
2. Nao tente implementar tudo em um unico patch gigante.
3. Preserve compatibilidade dos comandos existentes.
4. Nao remova comportamento atual fora do fair mode.
5. Escreva codigo simples, testavel e auditavel.
6. Use nomes explicitos: fair_claude, provider_lock, fallback_disabled, repair_conversion.
7. Atualize docs quando o comportamento real mudar.
8. No final, entregue resumo com arquivos alterados, testes executados e lacunas restantes.

Primeira entrega recomendada:
Implementar Etapa 1 e Etapa 2 juntas:
- FairClaudePolicy central;
- flags --claude-only/--single-provider/--no-decide/--fallback-disabled no atlas dev;
- plan-only JSON com fair metadata;
- validações de provider/model;
- testes unit/feature cobrindo os casos principais.

So avance para gateway/worker quando o contrato CLI estiver verde e testado.
```

## Resultado Esperado Do Primeiro Ciclo

Depois de usar este prompt, o primeiro PR ou patch deve entregar:

- policy central de fair mode;
- flags reais no CLI;
- validacao de provider/modelo;
- metadata no plan-only;
- testes cobrindo happy path e violacoes;
- docs atualizadas se o comportamento real divergir.

Nao aceitar como completo se o modo fair continuar apenas documentado.
