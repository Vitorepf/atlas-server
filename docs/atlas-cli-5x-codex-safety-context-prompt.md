# Prompt De Contexto E Seguranca Para Codex - Atlas Fair Claude 5x

Use este prompt junto com `docs/atlas-cli-5x-codex-implementation-prompt.md`
quando for pedir ao Codex para implementar o Atlas Fair Claude 5x. Este prompt
tem uma funcao especifica: dar contexto suficiente para o Codex nao quebrar o
produto, nao criar regressao, nao apagar trabalho existente e nao contaminar o
benchmark justo com outros providers/modelos.

## Prompt

```text
Voce esta trabalhando no repositorio Atlas em:
/Users/vitorepf/Develop/atlas/atlas-server

Contexto estrategico obrigatorio:
Estamos construindo o Atlas para competir de forma justa contra o Claude Code CLI.
Isso significa que, nesta etapa, o Atlas nao deve vencer por usar outro modelo,
outro provider, outro agente ou uma revisao externa. A comparacao que importa e:

Claude Code CLI usando Claude Opus
vs
Atlas CLI usando claude_cli + o mesmo Claude Opus

O Codex esta sendo usado apenas como engenheiro que implementa o produto Atlas.
O Codex nao faz parte do produto avaliado no benchmark. Portanto, ao implementar,
nao crie caminhos onde o Atlas use Codex, Gemini, Atlas Decide, council,
claude_codex ou fallback para melhorar o resultado. Isso destruiria a validade
da comparacao.

Tese do produto:
O Atlas deve superar o Claude Code nao porque tem um modelo mais inteligente,
mas porque e um harness de engenharia melhor ao redor do mesmo Claude:
- prepara contexto melhor;
- cria contrato de tarefa;
- controla permissao e worktree;
- valida com gates deterministas;
- transforma falhas em repair prompts melhores;
- chama o mesmo Claude novamente;
- registra tudo para replay e para o benchmark final futuro;
- reduz intervencao humana.

Ordem correta:
Primeiro deixe o Atlas realmente melhor como produto: fair mode enforceado,
contexto, gate matrix, repair capsule, worktree/checkpoint, final packet e
replay. Benchmark pareado contra Claude Code vem por ultimo, apenas para provar
o ganho. Nao pule direto para benchmark runner/scorecard enquanto o produto
ainda nao estiver pronto.

Interpretacao correta de "vencer":
Vencer nao significa "o Claude dentro do Atlas e mais inteligente". O modelo e
o mesmo. Vencer significa que o Atlas entrega mais tarefas medias/dificeis
prontas sem humano no loop, porque o sistema em volta do Claude e melhor.

Interpretacao correta de "5x":
Se a taxa de sucesso autonoma do Claude Code for baixa, podemos medir 5x em
pass_without_human_rate. Se o baseline do Claude Code ja for alto, a metrica
mais honesta e 5x menos intervencoes humanas por caso verde. Nao force uma
metrica matematicamente impossivel.

Regra critica de produto:
O Atlas normal deve continuar inteligente. Nao transforme o produto inteiro em
"Claude-only" para ganhar um teste especifico. Fair Claude e um modo explicito,
acionado por flags/comandos de benchmark. Fora desse modo, o Atlas deve
continuar respeitando o app, o Default AI, Atlas Decide, Codex, Gemini, budgets,
provider strategy, provider health e fluxos inteligentes existentes.

Comandos normais devem continuar normais:
- `atlas dev "..."` usa a inteligencia padrao do Atlas;
- `atlas dev "..." --provider=codex_cli --model=...` continua funcionando quando permitido;
- `atlas dev "..." --provider=gemini_cli --model=...` continua funcionando quando permitido;
- `atlas dev "..." --model=...` resolve modelo conforme provider/config existente;
- `atlas ask`, `atlas debug`, `atlas review`, `atlas compare` nao devem virar Claude-only.

Comandos de benchmark justo devem ser explicitos:
- `atlas dev "..." --claude-only --model=opus --complete --auto-test`;
- `atlas dev "..." --provider=claude_cli --model=opus --single-provider --no-decide --fallback-disabled --complete --auto-test`;
- `atlas benchmark claude-fair run-atlas --case=<id> --model=opus`;
- `atlas benchmark claude-fair run-claude-code --case=<id> --model=opus`.

Se uma mudanca melhora o harness, ela deve melhorar o Atlas como produto geral
quando aplicavel: gate matrix, repair capsule, patch artifact, worktree,
telemetria, final packet e seguranca devem ser componentes reutilizaveis. A
restricao Claude-only deve ficar isolada na policy de fair mode.

Objetivo desta sessao:
Implementar com seguranca partes do plano Atlas Fair Claude 5x, preservando o produto existente e evitando regressao. O objetivo imediato e permitir que o Atlas CLI fique muito mais forte em programacao media/dificil usando o mesmo Claude Opus, por meio de harness, contexto, gates, repair loop, worktree, telemetria, final packet e replay. Benchmark pareado contra Claude Code vem por ultimo, para provar o ganho.

Restricao absoluta do produto:
O benchmark justo e somente:
Atlas CLI + claude_cli + Claude Opus
vs
Claude Code CLI + Claude Opus

Nao implemente melhoria que dependa de Codex, Gemini, Atlas Decide, council, claude_codex, fallback multi-provider, subagentes de outros modelos ou revisao externa para vencer este benchmark. Codex esta sendo usado apenas como ferramenta de implementacao do software. O software implementado deve executar e medir o benchmark usando somente Claude/Opus.

Documentos obrigatorios para ler antes de editar:
- docs/atlas-cli-fair-claude-benchmark.md
- docs/atlas-cli-5x-claude-code-plan.md
- docs/atlas-cli-5x-codex-implementation-prompt.md
- docs/atlas-cli-final-product.md
- docs/atlas-cli-release-checklist.md

Arquivos principais para entender antes de editar:
- bin/atlas
- bin/atlas-completion.bash
- app/Console/Commands/AtlasCliDevCommand.php
- app/Console/Commands/AiChatCommand.php
- app/Services/Ai/ClaudeCliProvider.php
- app/Services/Ai/Cli/AtlasCliDevWorkflowService.php
- app/Services/Ai/Cli/AtlasCliModelCatalogService.php
- app/Services/Ai/Cli/AtlasCliQualityService.php
- app/Services/Ai/Cli/AtlasCliProviderStrategyService.php
- app/Services/Ai/AtlasAiRuntimeSettings.php
- app/Services/Ai/AiProviderModelResolver.php
- app/Services/Ai/AiGatewayService.php
- app/Services/Ai/AiWorker.php
- app/Services/Ai/AiProviderChoiceBuilder.php
- app/Services/Ai/AiProviderChoiceResolver.php
- app/Services/Ai/AiProviderManager.php
- app/Services/Engineering/EngineeringHarnessRunnerService.php
- app/Services/Engineering/EngineeringBenchmarkService.php
- app/Services/Engineering/EngineeringWorkspaceService.php
- app/Services/Engineering/EngineeringPatchArtifactService.php
- app/Services/Engineering/EngineeringRunScoringService.php
- app/Services/Engineering/EngineeringTestMatrixService.php
- config/atlas.php
- tests/Unit/Ai/AiProviderModelResolverTest.php
- tests/Unit/Ai/AiProviderChoiceResolverTest.php
- tests/Feature/Console/AiChatProviderChoiceTest.php
- tests/Unit/AtlasCliProviderStrategyServiceTest.php

Antes de qualquer alteracao:
1. Rode ou inspecione:
   - pwd
   - git status --short
   - git diff --stat
2. Identifique arquivos ja modificados.
3. Presuma que mudancas existentes pertencem ao usuario ou a outro agente.
4. Nao reverta, nao formate em massa e nao apague mudancas que voce nao entende.
5. Se precisar editar arquivo ja modificado, leia o diff primeiro e preserve o trabalho existente.
6. Nao use git reset, git checkout --, git clean ou comandos destrutivos.

Como pensar sobre a arquitetura:
O Atlas ja tem varias camadas. Nao duplique se ja existir servico equivalente.
Procure primeiro por padroes existentes com rg:
- provider
- model
- manual_override
- allow_manual
- allow_auto
- fallback
- provider_choice
- quality
- benchmark
- repair
- trace
- dev_execution_plan
- metadata

Principios de implementacao:
1. Mudanca pequena, verificavel e reversivel.
2. Preserve compatibilidade dos comandos existentes.
3. O fair mode deve ser aditivo: nao quebre nem simplifique o fluxo normal fora de fair mode.
4. Toda regra critica precisa de teste.
5. Toda metadata nova precisa ser consistente entre CLI, job, trace e benchmark quando aplicavel.
6. Toda violacao de fair mode deve ser explicita, nao fallback silencioso.
7. Nunca conte autoavaliacao do Claude como gate.
8. Nunca conte unverified como passed.
9. Nunca permita outro provider/modelo em fair mode.
10. Nao implemente atalhos que parecam funcionar mas nao sejam auditaveis.
11. Nao remova inteligencia padrao do Atlas para favorecer o benchmark.
12. Nao altere defaults globais para Claude-only.

Escopo correto desta fase de produto:
- FairClaudePolicy central.
- Flags CLI reais.
- Validacao de provider/modelo.
- Metadata fair mode.
- Enforcement contra fallback/downgrade/provider handoff.
- Claude invocation fingerprint.
- Prompt contract.
- Gate matrix.
- Repair capsule.
- Claude-only failure memory.
- Worktree/checkpoint/rollback.
- Final packet.
- Replay.

Escopo final, somente depois do produto pronto:
- Benchmark pareado.
- Scorecard.
- Suite 60 casos.

Fora de escopo nesta fase:
- Atlas Decide.
- Codex como executor/revisor/scout.
- Gemini como contexto longo.
- Council.
- Subagentes multi-modelo.
- Otimizacao baseada em outro modelo.
- Reescrita total do AI Gateway.
- Refactor amplo sem teste.

Importante:
Esses itens estao fora de escopo apenas para o fair benchmark. Eles nao devem
ser removidos nem degradados no Atlas normal. Fora de fair mode, o Atlas deve
continuar podendo usar essas capacidades conforme configuracao do app e
estrategia existente.

Regras de fair mode:
Se fair mode estiver ativo:
- provider permitido: claude_cli.
- modelo permitido: Claude Opus resolvido pelo catalog/config.
- allowed_providers: ["claude_cli"].
- fallback_disabled: true.
- single_provider: true.
- atlas_decide_disabled: true.
- council_disabled: true.
- provider_handoff_disabled: true.
- downgrade_model_disabled: true.

Qualquer divergencia deve virar:
fair_mode_violation

E o run/caso deve ser:
invalid

Nunca:
- trocar para codex_cli;
- trocar para gemini_cli;
- usar claude_codex;
- downgrade para Sonnet/Haiku;
- continuar silenciosamente;
- esconder a violacao.

Politica de configuracao do app:
O que esta configurado no app e lei.
- Default AI do app continua valendo para modo normal.
- Modelos configurados no app continuam sendo a fonte para aliases.
- allow_manual=false bloqueia uso manual.
- allow_auto=false nao bloqueia uso manual.
- Budget pode reportar/bloquear conforme politica existente, mas nao pode trocar provider em fair mode.
- Em fair mode, se Claude/Opus nao puder rodar, falhe ou espere; nao use outro provider.
- Nunca mude o default global do app para passar no benchmark.
- Nunca force `claude_cli` quando o usuario nao pediu fair mode ou provider manual.
- Nunca remova suporte a `--model` de outros providers.

Cuidados especificos com provider/model:
- --model=opus deve resolver para o premium_model configurado do Claude.
- Nao hardcode modelo se ja existe catalog/resolver.
- Preserve aliases existentes.
- Nao quebre Codex/Gemini fora do fair mode.
- Em testes, simule configs do app explicitamente.
- Teste tambem que modelos de Codex/Gemini continuam funcionando fora do fair mode quando configurados.

Cuidados com AiChatCommand:
- Preserve comportamento normal de chat.
- Nao force fair mode quando usuario nao pediu.
- Ao adicionar flags, garanta que payload inclui metadata.
- decision_mode deve ser manual_override em fair mode.
- requested_provider/operator_requested_provider devem refletir claude_cli.
- requested_model/model devem refletir Opus resolvido.

Cuidados com AtlasCliDevCommand:
- --claude-only deve implicar provider/model/policy corretos.
- --plan-only --json deve mostrar contrato fair sem chamar provider.
- --complete deve manter provider/model em todas as tentativas.
- repair prompt nao pode remover fair constraints.
- max iterations deve continuar respeitado.
- auto-test/complete devem continuar funcionando.
- sem --claude-only/--single-provider, o comando nao deve mudar para comportamento Claude-only.
- `--provider` e `--model` devem continuar sendo overrides manuais validos fora do fair mode.

Cuidados com AiGatewayService/AiWorker:
- Eles sao pontos de enforcement, nao apenas passagem.
- Se job fair chegar inconsistente, falhe explicitamente.
- Bloqueie provider choice, fallback e handoff.
- Nao tente ser "prestativo" trocando de provider.
- Registre metadata de violacao.

Cuidados com AiProviderChoiceBuilder:
- Fora do fair mode, preserve comportamento existente.
- Em fair mode, remova opcoes de switch_provider e downgrade_model.
- Permita apenas retry_same, wait_for_reset, login_required ou fail/cancel.

Cuidados com ClaudeCliProvider:
- Registre fingerprint sem vazar segredo.
- Nao assuma flags sem detectar capacidade.
- Preserve args configurados em config/atlas.php.
- Quando passar --model, garanta que e o modelo resolvido.
- permission mode e add-dir devem ser auditaveis.

Cuidados com quality gate:
- Nao transforme warning em pass.
- Sem teste/evidencia suficiente em strict => unverified.
- git diff --check falhando bloqueia.
- forbidden file touched bloqueia.
- untracked precisa ser capturado.
- Falhas devem ser estruturadas para repair.

Cuidados com repair loop:
- Repair usa o mesmo Claude/Opus.
- Repair prompt deve incluir failure capsule.
- Repair prompt deve incluir constraints fair mode.
- Nao mande logs enormes sem compactar.
- Pare se o diff piorar ou tocar arquivo proibido.
- Registre repair_conversion.

Cuidados com benchmark final:
- Nao implemente benchmark antes de o produto estar pronto.
- Benchmark e prova final, nao substituto para melhorar o Atlas.
- Benchmark justo precisa de dois bracos pareados:
  - atlas_fair_claude
  - claude_code_cli_baseline
- Mesmo commit inicial.
- Mesmo prompt.
- Mesmo modelo.
- Mesmo timeout.
- Mesmo gate externo.
- Worktree isolado por braco.
- Intervencao humana precisa ser medida.

Regra de pass_without_human:
pass_without_human so pode ser true se:
- final_gate_passed = true;
- protocol_valid = true;
- human_intervention_count = 0;
- provider_violation_count = 0;
- fallback_violation_count = 0;
- status nao e unverified;
- status nao e invalid.

Testes obrigatorios por tipo de mudanca:
Se editar policy/service:
- unit tests.

Se editar comando CLI:
- feature/console tests com --json quando possivel.

Se editar provider/gateway/worker:
- tests cobrindo happy path, violation e fallback bloqueado.

Se editar quality gate:
- tests para passed, failed, unverified e invalid.

Se editar benchmark:
- tests com suite/case fake e dois bracos.

Comandos de validacao recomendados:
- git diff --check
- php artisan test --filter=<TesteRelevante>
- php artisan test --stop-on-failure quando a mudanca for ampla
- composer validate --strict se mexer em composer/config

Nao rode formatador global sem necessidade.
Nao atualize snapshots amplos sem inspecionar.
Nao altere docs como substituto de implementacao.

Padrao de resposta final esperado:
Ao terminar, responda com:
- arquivos alterados;
- comportamento implementado;
- testes executados;
- resultado dos testes;
- riscos/lacunas restantes;
- proxima etapa recomendada.

Nunca diga que esta pronto se:
- nao rodou nenhum teste relevante;
- fair mode ainda permite fallback;
- provider/modelo divergente nao falha;
- unverified conta como passed;
- docs prometem comportamento que o codigo nao implementa.
- fluxo normal do Atlas foi simplificado, travado em Claude ou perdeu suporte a providers/modelos existentes.

Primeiro patch seguro recomendado:
1. Criar FairClaudePolicy central com testes unitarios.
2. Integrar policy apenas no plan-only de AtlasCliDevCommand.
3. Adicionar flags sem alterar fluxo normal fora do fair mode.
4. Fazer --claude-only --model=opus --plan-only --json emitir metadata correta.
5. Fazer tentativas obvias de provider/model invalido falharem.
6. Rodar testes focados e git diff --check.

Depois disso, avance para enforcement em AiChatCommand/gateway/worker.

Lembrete final:
Voce esta implementando um produto de engenharia. O objetivo nao e ganhar por truque, prompt bonito ou outro modelo. O objetivo e fazer o Atlas usar o mesmo Claude melhor que o Claude Code direto: mais contexto, mais controle, mais validacao, mais repair, mais replay e menos intervencao humana.
```

## Como Usar

Use este prompt antes do prompt de implementacao quando o Codex estiver prestes
a fazer mudancas amplas. Ele estabelece as regras de seguranca e contexto. Em
seguida, use:

- `docs/atlas-cli-5x-codex-implementation-prompt.md`

para guiar a sequencia de implementacao.

## Checklist De Aceite Do Codex

Antes de aceitar qualquer patch gerado com este prompt:

- fair mode continua restrito a Claude/Opus;
- fluxo normal fora do fair mode nao quebrou;
- defaults globais e provider strategy normal foram preservados;
- Codex/Gemini/Atlas Decide nao foram degradados fora do fair mode;
- mudancas existentes do usuario foram preservadas;
- testes focados foram executados;
- `git diff --check` passou;
- metadata nova e auditavel;
- violacoes viram erro explicito;
- docs nao prometem mais que o codigo entrega.
