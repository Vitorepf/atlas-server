---
id: atlas-code-interactive-observed-provider-workflow-v1
type: engineering_knowledge
title: Atlas Code Interactive Observed Provider Workflow v1
status: active
category: programming-forge
priority: 101
summary: Contrato canonico do fluxo diario para usar Claude Code, Codex, Gemini ou outro provider interativo dentro do Atlas Code sem virar backend headless: Atlas prepara Obra, workspace, packet, prompt, terminal, watchers, importacao, gates, review e evidence; o humano executa a interacao exigida pelo provider.
tags:
  - atlas-code
  - claude-code
  - interactive-observed
  - programming-obras
  - provider-workflow
capabilities:
  - interactive_observed_provider_session
  - claude_code_interactive_observed
  - provider_packet_builder
  - terminal_workspace_launcher
  - evidence_import_flow
  - subscription_safe_provider_use
decisions:
  - Claude Code, Codex, Gemini e futuros providers interativos aparecem no Atlas Code como sessoes observadas da Obra, nao como telas paralelas nem cerebros principais.
  - Atlas deve automatizar preparacao, contexto, packet, prompt, workspace, terminal, watchers, importacao, diff review, gates, evidence e learning.
  - O humano faz apenas os gestos interativos exigidos pelo provider: abrir/enviar/confirmar no terminal ou app do provider.
  - Em modo subscription-only, Claude Code permitido e `claude` interativo observado; `claude -p`, Agent SDK, worker headless, API/PAYG e fallback silencioso continuam bloqueados.
  - Uma sessao observada nunca declara completion; completion so nasce de gates, evidence, review e aceite humano no Atlas.
  - Para tarefa pesada, usar worktree/workspace isolado por packet; workspace vivo direto so para intervencao pequena com scope guard explicito.
maintenance:
  - Atualize antes de alterar UX do botao de provider interativo, terminal adapter, packet builder, prompt export, importacao de relatorio, watchers, review ou gates.
  - Mantenha alinhado com Claude subscription governance e Adaptive Provider Operating Room.
related_paths:
  - docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
  - docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
  - docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md
  - ../atlas-desktop/apps/desktop/src/surfaces/code/
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-interactive-observed-provider-workflow-v1
graph_title: Atlas Code Interactive Observed Provider Workflow v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-code-adaptive-provider-operating-room-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
allowed_changes:
  - Adicionar providers interativos, estados de sessao, templates de prompt, eventos de watcher e acoes de importacao quando houver implementacao e evidence.
forbidden_changes:
  - Tratar sessao observada como automacao headless.
  - Chamar `claude -p`, Agent SDK, API/PAYG ou worker background em modo subscription-only.
  - Fazer Atlas colar/enviar comandos em nome do humano para mascarar uso nao interativo.
  - Deixar provider decidir escopo, merge, completion, aceite, fallback ou autoridade da Obra.
  - Exigir que o humano monitore varias sessoes simultaneamente.
depends_on:
  - atlas-claude-code-subscription-governance-v1
  - atlas-code-adaptive-provider-operating-room-v1
  - atlas-code-programming-obras-operating-system
  - atlas-forge-governed-provider-invocation-v1
flows_to:
  - atlas-code
  - provider-operating-room
  - attention-control-plane
  - evidence-ledger
unlocks:
  - subscription-safe-claude-code-use
  - interactive-provider-productivity
  - governed-human-in-the-loop-provider-sessions
governs:
  - atlas_code.interactive_observed_provider_session
  - atlas_code.provider_packet_export
  - atlas_code.provider_result_import
evidence:
  - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - atlas-code
  - claude-code
  - interactive-observed
ai_entrypoints:
  - Leia este doc antes de implementar botao Abrir Claude Code, prompt export, terminal adapter, observed sessions, provider result import ou workflow subscription-only no Atlas Code.
ai_usage_notes:
  - Sessao observada e ponte operacional para providers interativos; a fonte da verdade continua Obra, packet, workspace, gates, review e Evidence Ledger do Atlas.
quality_gates:
  - operator-presence-required
  - no-headless-provider-call
  - packet-exported-before-provider-use
  - workspace-scope-guarded
  - result-imported-before-review
  - gates-run-before-completion
  - human-acceptance-required
failure_modes:
  - Botao "Abrir Claude" virar chamada `claude -p` escondida.
  - Prompt solto substituir packet canonico.
  - Terminal virar fonte da verdade em vez de Obra/Evidence Ledger.
  - Claude editar fora do escopo e Atlas aceitar sem scope guard.
  - Humano ter que acompanhar cinco terminais ao mesmo tempo.
observability_signals:
  - obra_id
  - project_id
  - work_packet_id
  - provider_session_id
  - provider_identity
  - invocation_mode
  - workspace_path
  - packet_path
  - prompt_hash
  - operator_opened_terminal_at
  - result_imported_at
  - diff_hash
  - gate_run_ids
  - evidence_refs
next_actions:
  - Implementar read-model de Observed Sessions no Operating Room.
  - Implementar botao Abrir Provider Interativo com terminal na pasta correta e prompt copy-safe.
  - Implementar importacao de relatorio + diff + gates antes de review.
line_limit: 520
---
# Atlas Code Interactive Observed Provider Workflow v1

## Resumo

Este doc define como usar Claude Code, Codex, Gemini ou outro provider
interativo no dia a dia da programacao pesada do Atlas Code.

A regra e simples:

```text
Atlas governa a Obra.
Provider interativo executa um packet observado.
Humano faz o gesto interativo.
Atlas importa, valida, revisa, registra evidencia e decide proximo passo.
```

## Papel no Atlas

O fluxo existe para preservar a potencia do Claude Code interativo sem fazer o
Atlas depender de API, Agent SDK, `claude -p`, worker headless ou credito extra.

Atlas automatiza tudo que e governanca: escolher Obra e work packet, preparar
contexto, gerar packet canonico, montar prompt copy-safe, abrir terminal no
workspace correto, observar arquivos/git/testes/logs, importar relatorio, rodar
scope guard/gates/review e registrar evidence/learning.

O humano fica responsavel pelo minimo inevitavel: iniciar/confirmar a sessao
interativa, colar/enviar o prompt quando necessario, responder prompts do
provider e aceitar/rejeitar a entrega final dentro do Atlas.

## Onde Se Encaixa

```text
Project/Workspace
-> Programming Obra
-> Work Packet
-> Interactive Observed Provider Session
-> Terminal / Provider App
-> Result Import
-> Diff + Gates + Review
-> Evidence Ledger
-> Human Acceptance
```

No Atlas Code, isso aparece dentro do Provider Operating Room e do Attention
Control Plane, nao como tela independente.

## Contratos

Contrato principal de UX:

```text
Abrir Claude Code observado
Abrir Codex observado
Abrir Gemini observado
```

Cada acao deve exigir Obra ativa, gerar ou usar work packet pronto, escrever
packet local versionado, abrir terminal na pasta correta, fornecer prompt
copy-safe, marcar sessao como `waiting_operator` ou `running`, observar mudancas,
permitir importacao do relatorio, rodar gates e bloquear completion sem aceite
humano.

## Fluxo

```text
1. Humano escolhe Obra.
2. Atlas mostra proximo passo seguro.
3. Humano clica "Abrir Claude Code observado".
4. Atlas gera `.atlas/packets/<packet_id>.md`.
5. Atlas abre terminal no workspace correto.
6. Atlas copia ou exibe prompt canonico.
7. Humano roda `claude` e envia o prompt.
8. Claude trabalha interativamente.
9. Atlas observa diff, arquivos tocados, logs e blockers.
10. Humano clica "Importar resultado".
11. Atlas importa relatorio, diff e artefatos.
12. Atlas roda scope guard, testes, lint/build quando aplicavel.
13. Atlas pede cross-review se risco ou divergencia exigirem.
14. Humano aprova, rejeita, pede repair ou reroute.
15. Atlas registra evidence, learning e proximo packet.
```

## Regras para IA

- Nunca automatize Claude Code como `claude -p` no modo subscription-only.
- Nunca use Agent SDK, API/PAYG ou worker headless como substituto silencioso.
- Nunca trate texto do provider como completion.
- Sempre produza packet antes de abrir provider.
- Sempre importe resultado antes de review.
- Sempre rode gates antes de aceite.
- Sempre mantenha Atlas como fonte da verdade.
- Sempre serialize decisoes humanas pela Attention Control Plane.

## Escopo de Implementacao

Inclui botao `Abrir Provider Interativo`, terminal launcher por Project/Obra,
packet export em `.atlas/packets/<packet_id>.md`, prompt copy-safe, estado de
sessao observada, file/git/test watcher, importacao de relatorio, diff review,
gates, Evidence Ledger e provider performance signal advisory.

Nao inclui chamada headless, Agent SDK, API/PAYG, automacao de conta pessoal,
multiusuario via assinatura pessoal, completion automatica ou tela paralela do
Claude fora do Atlas Code.

## Dependencias

- `atlas-claude-code-subscription-governance-v1.md`
- `atlas-code-adaptive-provider-operating-room-v1.md`
- `atlas-code-programming-obras-operating-system.md`
- `atlas-forge-governed-provider-invocation-v1.md`
- `self-construction/ai-implementation-packet-contract.md`

## Evidencias

Evidencia minima de uma sessao observada:

- `provider_session_id`
- `obra_id`
- `work_packet_id`
- `packet_path`
- `prompt_hash`
- `workspace_path`
- diff/hash dos arquivos alterados
- relatorio final importado
- comandos de validacao rodados
- resultado dos gates
- decisao humana final

## Riscos

- Confundir "abrir terminal interativo" com automacao headless.
- Fazer prompt solto sem packet e perder escopo.
- Claude editar fora do allowed scope.
- Atlas aceitar resultado sem teste ou review.
- Operador virar supervisor de varias sessoes ao mesmo tempo.
- Provider externo virar fonte da verdade.

## Exemplos

Correto:

```text
Botao: Abrir Claude Code observado.
Atlas: gera packet, abre terminal na Obra, copia prompt.
Humano: roda `claude`, cola prompt, acompanha quando necessario.
Claude: edita workspace isolado.
Atlas: importa diff, roda gates, pede review e registra evidence.
```

Proibido:

```text
Botao: Rodar Claude.
Atlas: executa `claude -p` em background e marca completed pelo texto retornado.
```

## Proximas Acoes

1. Adicionar `Observed Sessions` ao Operating Room como bloco visual.
2. Criar `Open Interactive Provider` no Atlas Code com terminal na pasta correta.
3. Gerar packet + prompt copy-safe por work packet.
4. Importar resultado e diff antes de review/completion.
5. Conectar sinais ao Provider Performance Ledger como evidencia advisory.

