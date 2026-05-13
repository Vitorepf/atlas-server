---
id: atlas-ai-layer-0-glossary
type: engineering_knowledge
title: Atlas AI Layer 0 Constitution And Glossary
status: active
category: architecture-governance
priority: 98
summary: Constituicao operacional enxuta e glossario canonico de Layer 0 para impedir que docs legados, prompts ou providers disputem a identidade do Atlas AI.
tags:
  - atlas-ai
  - layer-0
  - glossary
  - constitution
capabilities:
  - canonical_architecture_index
  - documentation_governance
  - provider_safe_identity
decisions:
  - A Tese do Multiplicador / Canal Unico (Layer -1, ver atlas-ai-thesis-multiplier-channel.md) governa identidade operacional do Atlas. Atlas e canal soberano que multiplica output de qualquer provider; nao competidor.
  - Atlas precisa ser canal UNICO de interacao com IA; uso direto de provider quebra o ciclo Evidence -> Curator -> Multiplicador.
  - Atlas Rivals e o instrumento empirico que valida o multiplicador; pergunta-norte de toda decisao: multiplica ou compete? mantem gravidade ou cria fricca de escape?
  - Atlas AI e o core cognitivo persistente e modelo-agnostico do Atlas, nao um provider, chat, CLI ou app.
  - Providers sao motores substituiveis; surfaces sao pontos de contato; runtime e kernel preservam contratos, evidence e policy.
  - Conteudo constitucional legado e source material humano; somente excertos revisados e provider-safe viram autoridade operacional.
  - Este documento governa linguagem e identidade; contratos executaveis continuam no Kernel Architecture.
maintenance:
  - Atualizar junto com o Canonical Architecture Index quando um termo mudar de autoridade.
  - Nao copiar documentos mestres legados inteiros para a KB.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-vision.md
  - resolver-o-que-vale-a-pena/docs/atlas-glossary.md
  - resolver-o-que-vale-a-pena/root-md/Atlas_Documento_Mestre_v6.md
  - resolver-o-que-vale-a-pena/root-md/Atlas_AI_Documentacao_Final.md
  - resolver-o-que-vale-a-pena/root-md/Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-layer-0-glossary

graph_title: Atlas AI Layer 0 Constitution And Glossary

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: architecture-governance

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-layer-0-glossary.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - architecture-governance

evidence:
  - docs/engineering-knowledge-base/atlas-ai-layer-0-glossary.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
  - architecture-governance

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Layer 0 Constitution And Glossary

Este e o doc canonico enxuto de Layer 0. Ele nao substitui o Kernel, a Master
Architecture ou as Domain Specs; ele fixa identidade, linguagem e regras de
promocao de material humano/legado.

## Autoridade

| Assunto | Autoridade |
|---|---|
| Identidade e termos canonicos do Atlas AI | Este documento + `atlas-ai-canonical-architecture-index.md` |
| Contratos executaveis, envelopes, receipts, ledger, SLOs | `atlas-ai-kernel-architecture.md` |
| Produto, planes, dominios, surfaces e roadmap | `atlas-ai-master-architecture.md` |
| Open Brain, memoria e contexto | `atlas-ai-memory-context-core-open-brain.md` + `open-brain-context-injection.md` |
| AtlasVault/Obsidian | `obsidian-atlas-vault.md` |

## Constituicao Operacional

1. Atlas AI e continuidade, nao provider.
   Claude, Codex, GPT, Gemini, modelos locais e modelos futuros sao motores.
   Atlas AI e a camada persistente que decide contexto, policy, ferramenta,
   evidence, memoria, surface e criterio de qualidade.

2. Surface nao e core.
   App, CLI, TUI, mobile, API, MCP, automacao e voz futura podem mudar. A
   identidade operacional mora no Kernel, Master Architecture, Memory Core,
   Evidence Ledger e docs canonicos.

3. Contexto e compilado, nao despejado.
   Open Brain monta o menor contexto suficiente, provider-safe, rastreavel e
   reversivel. Nota humana, vault, prompt ou chat nunca entram crus no provider.

4. Qualidade vem antes de autonomia.
   Aumentar permissao exige evidence, gates, trace, reversibilidade, safety
   boundary e caminho de rollback. Workflow vem antes de autonomia aberta.

5. Skills e flows governam agentes.
   Agente e papel operacional temporario. Skill, domain, flow, policy e receipt
   definem comportamento antes de qualquer executor agir.

6. Memoria nao e historico bruto.
   Memoria operacional precisa de fonte, escopo, validade, privacy class,
   confidence, provider-safety e caminho de esquecimento.

7. Fase antiga nao e verdade presente.
   Material legado pode conter conhecimento valioso, mas deve ser marcado como
   source material, merge pending, human vault only ou archived antes de orientar
   implementacao atual.

## Glossario Canonico

| Termo | Significado canonico | Nao confundir com |
|---|---|---|
| Atlas AI | Core cognitivo persistente, modelo-agnostico e multi-surface do Atlas. | Provider, app, chat, CLI ou agente especifico |
| Provider | Motor substituivel chamado pelo Atlas. | Identidade do Atlas AI |
| Surface | Ponto de contato: app, CLI, mobile, API, MCP, automacao, voz futura. | Domain ou Kernel |
| Kernel | Contratos executaveis: envelope, receipt, ledger, manifests, SDKs, tests, SLOs. | Roadmap de produto |
| Master Architecture | Arquitetura de produto: planes, dominios, surfaces, learning, strategy e maturidade. | Contrato de DB/API especifico |
| Domain | Especializacao governada de comportamento e policy. | Tela, comando ou provider |
| Flow | Recorte operacional dentro de um domain. | Prompt solto |
| Profile | Configuracao derivada de domain/flow/surface/policy para uma operacao. | Pessoa/agente |
| Operation Envelope | Envelope tipado que delimita uma operacao do Kernel. | Trace solto |
| Decision Receipt | Registro da decisao operacional: policy, provider, budgets, evidence e fallback. | Resposta textual do modelo |
| Evidence Ledger | Stream append-only de eventos auditaveis do runtime. | Relatorio final manual |
| Context Pack | Pacote de contexto escolhido, resumido e auditado para uma tarefa. | Dump de historico |
| AtlasVault / Obsidian | Human Knowledge Surface / Personal Knowledge Workspace. | Fonte operacional primaria |
| Skill | Lente/policy/contrato de comportamento especializado. | Agente ou prompt |
| Agent | Papel operacional temporario usado por um flow. | Produto independente |
| Atlas Tool Runtime | Execucao governada de tools, shell, arquivos, git e testes. | Tool use livre do provider |

## Termos Legados

| Termo legado | Tratamento |
|---|---|
| Atlas Harness | Alias informal aceitavel; em docs canonicos prefira Atlas AI Harness ou runtime conforme contexto. |
| Atlas AI Orchestrator | Evitar como camada separada; normalmente significa Task Orchestrator ou Domain Orchestrator. |
| Documento Mestre | Source material humano/constitucional; nao substitui a KB canonica. |
| CLAUDE.md / AGENTS.md | Projection de provider/agente; nunca fonte primaria. |

## Regras De Promocao De Source Material

- Promover apenas decisoes estaveis, pequenas e provider-safe.
- Preservar o legado com redirect em vez de apagar.
- Redigir conteudo pessoal, sensivel, aspiracional ou historico antes de virar
  contrato operacional.
- Declarar source material no doc promovido.
- Atualizar `atlas-ai-canonical-architecture-index.md`, `README.md` e
  `START_HERE.md` quando a promocao mudar autoridade.

## Source Material

- `resolver-o-que-vale-a-pena/docs/atlas-glossary.md`
- `resolver-o-que-vale-a-pena/root-md/Atlas_Documento_Mestre_v6.md`
- `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Documentacao_Final.md`
- `resolver-o-que-vale-a-pena/root-md/Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md`

## Resumo

Constituicao operacional enxuta e glossario canonico de Layer 0 para impedir que docs legados, prompts ou providers disputem a identidade do Atlas AI.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
