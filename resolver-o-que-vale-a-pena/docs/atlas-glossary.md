# Atlas CLI - Glossario Canonico

**Status:** fonte operacional para implementacao do Atlas CLI
**Autoridade:** `Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md`

Este glossario impede que documentos, codigo e agentes tratem conceitos arquiteturais como se fossem classes especificas.

## Termos Canonicos

| Termo canonico | Significado | Implementacao atual |
|---|---|---|
| Atlas | Sistema inteiro: app, backend, Vault, CLI, automacoes, dados e experiencia. | Workspace Atlas completo |
| Atlas AI | Core cognitivo persistente: identidade, memoria, leis, contexto, modelos e ferramentas. | Backend AI + Vault + surfaces |
| Atlas AI Mobile | Superficie mobile do Atlas AI para conversa geral, conversa contextual, operacional, projeto, revisao e programacao com execucao remota. | Atlas App + Mobile Gateway + Atlas AI Threads |
| Atlas AI Harness | Infraestrutura operacional do Atlas AI: roteamento, contexto, runtime, permissoes, gates, traces e aprendizado. | Conjunto `App\Services\Ai\*`, `App\Services\Ai\Cli\*`, `App\Services\Ai\Runtime\*` |
| Foco do Atlas AI | Direcionamento de contexto, memoria, ferramentas, permissoes e objetivo. Nao e outra IA. | `Geral`, `Pesquisa`, `Programacao`, `Operacional`, `Projeto`, `Revisao` |
| Atlas AI Contextual | Mesmo Atlas AI aberto com contexto de um item, projeto, arquivo, relatorio, captura ou execucao. | `ai_threads` + `context_bundle_id` |
| Task Orchestrator | Subcomponente que transforma intencao em tarefa, risco, plano e fluxo. | `AiGatewayService`, `AiPromptBuilder`, `AiSessionManager`, `AiThreadResolver`, `AiQualityActionService` |
| Context Pack Builder | Monta contexto de alto sinal para o provider. | `AiContextPackBuilder`, `AiConversationContextBuilder`, `AiContextSnapshotRecorder` |
| Skill/Intent Router | Escolhe skill/lente por intencao. | `AiIntentRouter`, `AiSkillStore` |
| Provider/Model Router | Escolhe motor por tarefa, risco, saude e evidencia. | `AtlasCliProviderStrategyService`, `AiProviderManager`, `AiProviderHealthService`, `AiCouncilCoordinator` |
| Atlas Tool Runtime | Executa tools, shell, Git, testes e arquivos com permissao. | `AiToolRuntime`, `ToolInvocation`, `ToolResult`, `RuntimeSession`, `RuntimeEvent` |
| Permission Engine | Controla leitura, escrita e danger por job/runtime/sessao. | `AiPermissionEngine`, `AiToolPermissionEngine` |
| Trace Store | Persistencia auditavel de prompt, contexto, decisao, eventos e gates. | `ai_traces`, `ai_tool_events`, `ai_router_decisions` |
| Memory Delta | Proposta tipada de memoria nova com evidencia e validade. | `ai_memory_deltas` |
| Atlas CLI | Interface por comandos no terminal. | `atlas-server/bin/atlas` + Laravel Console |
| Atlas TUI | Interface visual dentro do terminal. | V1 dashboard console; V2 Bubble Tea |

## Regras

1. `AiGatewayService` nao e sinonimo de Atlas AI Harness.
2. Documentos constitucionais usam termos canonicos; docs tecnicos podem citar classes como implementacao atual.
3. Provider nunca e arquitetura. Claude, Codex e futuros modelos sao motores substituiveis.
4. Comando citado em roadmap precisa ter status: implementado, alias compativel, planejado ou legado.
5. `atlas threads` e `atlas handoff` sao canonicos; `atlas sessions` e `atlas switch` permanecem aliases compativeis.

## Documentos Core

| Documento | Papel |
|---|---|
| `docs/atlas-ai-mobile-operating-model.md` | Fonte canonica para produto, telas, focos, fluxos, permissoes e experiencia mobile do Atlas AI. |
| `docs/atlas-ai-telemetry-quality-efficiency-implementation.md` | Fonte operacional para implementar metricas, qualidade, eficiencia, custo, continuidade, evals e scorecards do Atlas AI no app, CLI e server. |

## Termos Legados

| Termo legado | Status | Substituto canonico |
|---|---|---|
| Atlas Harness | Alias curto aceitavel em conversa | Atlas AI Harness |
| Atlas AI Orchestrator | Evitar em docs novos | Task Orchestrator dentro do Atlas AI Harness |
| Context Compiler | Legado | Context Pack Builder |
| Skill Router | Aceito como abreviacao | Skill/Intent Router |
| Model Router | Aceito como abreviacao | Provider/Model Router |

## Comandos

| Comando | Status | Observacao |
|---|---|---|
| `atlas ask`, `atlas chat`, `atlas dev`, `atlas plan`, `atlas review` | Implementado | Superficie principal |
| `atlas threads`, `atlas handoff` | Implementado | Continuidade canonica |
| `atlas sessions`, `atlas switch` | Alias compativel | Manter por ergonomia |
| `atlas debug`, `atlas research` | Implementado no B1 | Modos leves de workflow |
| `atlas fix` | Implementado no B2 | Wrapper do repair loop |
| `atlas trace` | Implementado no B3 | Auditoria de traces |
| `atlas permissions` | Implementado no B4 | Permission sessions |
| `atlas memory` | Implementado no B5 | Revisao de memory deltas |
| `atlas compare` | Implementado no B6 | Dual-review explicito |
| `atlas version`, `atlas update`, `atlas rollback` | Implementado no B8 | Distribuicao local |
| `atlas code`, `atlas inspect`, `atlas council`, `atlas new`, `atlas close`, `atlas remember`, `atlas ingest` | Legado/planejado | Nao promover como fluxo principal |
