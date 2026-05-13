---
id: atlas-programming-governance-system
type: engineering_knowledge
title: Atlas Programming Governance System
status: future
category: programming-governance
priority: 100
summary: Contrato canonico que transforma programacao por IA em fluxo governado por placement, spec antes do codigo, contratos de tarefa, Code Intelligence, evidence, learning e cartografia.
tags:
  - atlas
  - programming
  - governance
  - spec-before-code
  - code-intelligence
  - forge
capabilities:
  - programming_governance_system
  - feature_placement_gate
  - spec_before_code
  - task_contracts
  - evidence_required
  - code_intelligence_links
  - programming_learning_loop
  - programming_cartography
decisions:
  - Atlas Programming Governance System e o nome canonico do conjunto de gates que governa programacao feita por IA.
  - Programar no Atlas nao e escrever codigo direto; e passar por placement, contexto, spec, contrato, execucao, evidence, learning e cartografia.
  - Spec antes do codigo e lei para qualquer alteracao estrutural, arriscada, multiarquivo, multiagente ou de arquitetura.
  - Code Intelligence e parte obrigatoria do fluxo; ele informa onde mexer, o que existe, quais simbolos/docs/testes se relacionam e onde ha risco.
  - Evidence obrigatorio separa implementacao real de opiniao do agente.
  - Cartografia da programacao deve permitir que humano e IA vejam onde cada engrenagem de software fica, o que faz, quais docs a governam e qual evidence prova seu estado.
maintenance:
  - Atualize este documento quando os gates de programacao, SDD, Engineering Blueprint, Code Intelligence, Forge Workspace, Self-Construction OS ou cartografia de codigo mudarem.
  - Leia junto de Programming Domain, Spec Operating System, Engineering Blueprint, Code Intelligence e Forge Operating System antes de alterar fluxos de programacao.
related_paths:
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/code-intelligence/README.md
  - docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-epistemic-operating-system.md
  - docs/engineering-knowledge-base/atlas-sovereign-operating-system.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-programming-governance-system

graph_title: Atlas Programming Governance System

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-programming-domain

graph_status: future

graph_source: repo

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-programming-domain
  - atlas-ai-spec-operating-system
  - code-intelligence
  - atlas-ai-knowledge-governance-system

flows_to:
  - atlas-forge-operating-system
  - atlas-ai-self-construction-os
  - atlas-cartographic-knowledge-os

unlocks:
  - ai-safe-programming-flow
  - forge-operating-system
  - programming-cartography

governs:
  - programming
  - programming.forge
  - code-generation
  - code-review
  - repair
  - refactor

evidence:
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:engineering:knowledge sync --prune --json"
  - "php artisan atlas:engineering:knowledge index-code --prune --json --summary-only"

requires_evidence: true

risk_level: high

visual_tags:
  - programming
  - governance
  - forge
  - code-intelligence

ai_entrypoints:
  - Leia Resumo, Contratos, Fluxo, Regras para IA e Evidencias antes de implementar qualquer fluxo de programacao.

ai_usage_notes:
  - Se uma tarefa pedir codigo estrutural, use este doc como checklist de governanca antes de escrever.
  - Se algum gate nao existir ainda, registre como gap; nao finja que o Atlas ja possui automacao completa.

quality_gates:
  - docs-health
  - spec-before-code
  - code-intelligence-context
  - evidence-required
  - cartography-update

failure_modes:
  - IA escreve codigo sem saber onde a feature pertence.
  - Spec e plano aparecem depois do codigo como justificativa retroativa.
  - Task contract nao limita arquivos, risco, testes e rollback.
  - Code Intelligence fica desatualizado e a IA altera simbolos errados.
  - Evidence vira resumo textual sem comando, log, diff, teste ou receipt.
  - Learning nao volta para docs, specs, prompts, gates ou cartografia.

observability_signals:
  - docs-health status ok
  - code intelligence index atualizado
  - receipts com evidence verificavel
  - links doc-codigo presentes

next_actions:
  - Implementar gates obrigatorios em CLI/API/UI para impedir programacao estrutural sem placement, spec, Code Intelligence e evidence.
---
# Atlas Programming Governance System

## Resumo

Atlas Programming Governance System e o sistema que governa programacao por IA
no Atlas. Ele nao e um editor de codigo, um prompt ou uma ferramenta isolada.
Ele e o contrato que diz como uma IA deve transformar uma intencao em codigo
seguro: descobrir onde a feature pertence, escrever spec antes do codigo,
criar contratos de tarefa, usar Code Intelligence, executar com evidence,
aprender com o resultado e atualizar a cartografia.

Este sistema existe porque o Atlas e construido, corrigido, gerenciado,
organizado e evoluido por IA. Sem governanca de programacao, cada agente pode
programar com contexto parcial. Com governanca, qualquer IA entende a ordem,
os limites, as provas exigidas e as conexoes entre docs, codigo e runtime.

## Papel no Atlas

O papel deste sistema e transformar programacao em uma linha de producao
governada:

- impedir implementacao solta;
- impedir duplicacao de arquitetura;
- impedir que uma IA mexa em arquivos sem saber dono, risco e contrato;
- obrigar spec antes do codigo;
- ligar cada mudanca a docs canonicos, simbolos, testes e evidence;
- permitir que Forge OS use varios agentes sem perder controle;
- alimentar Cartografia para humanos e IAs navegarem pelo software real.

Ele fica abaixo do Sovereign OS e do Epistemic OS. Sovereign OS decide se a
direcao, autonomia e prioridade fazem sentido. Epistemic OS decide confianca,
verdade, maturidade e drift. Programming Governance System decide como
programar quando a mudanca ja e permitida.

## Onde Se Encaixa

Hierarquia operacional:

```text
Sovereign OS
-> Epistemic OS
-> Knowledge Governance System
-> Programming Governance System
-> Forge Operating System
-> Programming Domain / Self-Construction OS
-> Runtime / Code / Tests / Evidence
-> Cartographic Knowledge OS
```

Programming Governance System nao substitui Programming Domain. O dominio
Programming executa fluxos `programming.*`. Este sistema define os gates que
todo fluxo de programacao precisa obedecer.

Programming Governance System tambem nao substitui Forge OS. Forge OS e a
fabrica que opera trabalho pesado, longo, multiagente ou multiprovider. Este
documento e a lei que Forge OS deve aplicar.

## Contratos

### Contrato 1: Feature Placement

Antes de qualquer implementacao estrutural, a IA deve descobrir onde a feature
pertence. O placement responde:

- qual dominio governa a mudanca;
- qual modulo ou servico deve receber a mudanca;
- quais docs canonicos ja existem;
- quais arquivos provavelmente sao dono do comportamento;
- quais arquivos sao proibidos ou perigosos;
- se a mudanca pertence a Programming, Kernel, Memory, Surface, Domain,
  Cartography, Obras, Self-Construction ou outro sistema.

Comando esperado quando aplicavel:

```bash
php artisan atlas:ai:place-feature "<feature>" --json
```

### Contrato 2: Spec Antes Do Codigo

Toda mudanca estrutural deve ter spec antes de codigo. Spec nao e texto
decorativo; e contrato operacional. Ela deve declarar:

- objetivo;
- contexto canonico;
- comportamento esperado;
- arquivos e modulos provaveis;
- entradas e saidas;
- riscos;
- testes;
- evidence exigido;
- rollback ou contencao;
- criterios de conclusao.

Se a IA escreve codigo primeiro e so depois inventa a spec, o fluxo falhou.

### Contrato 3: Task Contracts

Cada pacote de trabalho deve ter contrato explicito:

- `allowed_files`;
- `forbidden_files`;
- owner;
- escopo;
- dependencias;
- comandos de validacao;
- criterios de aceite;
- riscos;
- rollback;
- evidence esperado;
- relacao com docs canonicos.

Em trabalho multiagente, os contratos tambem precisam de reservation, claim,
scope/collision map e integration queue.

### Contrato 4: Code Intelligence Obrigatorio

Code Intelligence e a ponte entre documentacao e codigo real. Ele deve informar:

- simbolos existentes;
- arquivos relacionados;
- comandos Artisan;
- rotas;
- migrations;
- testes;
- links doc-codigo;
- gaps entre documentacao e implementacao;
- candidatos de grafo externo quando usados de forma read-only.

Uma IA nao deve decidir arquitetura apenas pela memoria da conversa se Code
Intelligence pode responder onde o comportamento vive.

### Contrato 5: Evidence Obrigatorio

Toda implementacao precisa provar o que aconteceu. Evidence valido inclui:

- comandos executados;
- testes e resultado;
- docs-health;
- sync/index quando docs mudam;
- diffs relevantes;
- receipts;
- logs;
- capturas visuais quando UI/Cartografia estiver envolvida;
- risco residual e rollback.

Resumo subjetivo do agente nao e evidence suficiente.

### Contrato 6: Learning Pos-Execucao

Depois da execucao, o Atlas deve capturar aprendizado:

- o que foi corrigido;
- qual gate falhou ou faltou;
- qual doc precisa atualizar;
- qual prompt ou spec deve melhorar;
- qual teste ou check deve virar padrao;
- qual relacao doc-codigo deve ser publicada;
- qual risco virou regra.

Learning nao pode escrever codigo por conta propria sem passar de novo pelos
gates apropriados.

### Contrato 7: Cartografia Da Programacao

A cartografia deve representar a programacao como mapa visual navegavel:

- sistema;
- dominio;
- modulo;
- arquivo;
- simbolo;
- teste;
- evidence;
- spec;
- task contract;
- gate;
- status.

Ao dar zoom em uma engrenagem, o resto do mapa pode desaparecer e o fluxo
interno daquela engrenagem deve aparecer. Esse e o criterio humano: entender o
software por mapa, nao por texto solto.

## Fluxo

Fluxo canonico de programacao governada:

```text
1. Intake
2. Session Bootstrap
3. Feature Placement
4. Knowledge + Code Intelligence Context
5. Spec antes do codigo
6. Task Contract
7. Execution Plan / Decision Receipt
8. Implementacao
9. Quality Gates
10. Evidence Ledger
11. Learning Proposal
12. Docs / Code Intelligence / Cartography Update
13. Completion Gate
```

Nenhuma etapa e decorativa. Em mudancas pequenas, algumas etapas podem ser
compactas, mas a ordem conceitual permanece.

## Regras para IA

- Nao implemente feature estrutural sem placement.
- Nao implemente mudanca arriscada sem spec antes do codigo.
- Nao altere arquivos fora do task contract.
- Nao ignore Code Intelligence quando a pergunta for "onde isso vive?".
- Nao declare conclusao sem evidence.
- Nao use cartografia como desenho bonito; ela deve refletir verdade
  operacional.
- Nao confunda Programming Governance System com Forge OS. Governanca define
  regras; Forge opera a fabrica.
- Quando houver conflito entre velocidade e governanca, use o menor contrato
  suficiente, mas preserve evidence e limites.
- Quando houver conflito sobre proposito, autonomia ou prioridade, escale para
  Sovereign OS.
- Quando houver conflito sobre verdade, maturidade, drift ou confianca, escale
  para Epistemic OS.

## Escopo de Implementacao

Para considerar este sistema concluido, o Atlas precisa ter:

| Item | Estado alvo |
|---|---|
| Feature Placement Gate | Obrigatorio em CLI/API/UI para mudancas estruturais |
| Spec Before Code | Spec criada antes de diff arriscado |
| Task Contract Engine | `allowed_files`, `forbidden_files`, owner, tests, rollback e evidence |
| Code Intelligence Context | Simbolos/docs/testes/rotas/comandos ligados ao plano |
| Quality Gate Router | Gates proporcionais a risco, dominio e tipo de mudanca |
| Evidence Ledger Integration | Receipts e logs verificaveis por execucao |
| Learning Extractor | Propostas pos-execucao para docs, tests, prompts e gates |
| Cartography Publisher | Mapa visual de sistemas, modulos, simbolos, specs e evidence |
| Forge Integration | Contratos consumiveis por agentes paralelos |
| Completion Gate | Nao concluir sem spec, diff, tests/evidence e docs quando necessario |

## Dependencias

Dependencias canonicas:

- Programming Domain;
- Atlas AI Spec Operating System;
- Engineering Blueprint;
- Code Intelligence;
- Knowledge Governance System;
- Epistemic OS;
- Sovereign OS;
- Evidence Ledger;
- Obras Shared Workspace / Forge Workspace;
- Cartographic Knowledge OS;
- Self-Construction OS.

## Evidencias

Evidencias minimas para evoluir este sistema:

- `docs-health` verde;
- Knowledge sync executado apos docs novas;
- Code Intelligence index atualizado apos mudancas relevantes;
- comandos de placement funcionando;
- exemplos reais de spec antes do codigo;
- task contracts com arquivos permitidos/proibidos;
- evidence por implementacao;
- cartografia refletindo docs e codigo.

## Riscos

- Criar governanca pesada demais e travar mudancas pequenas.
- Criar governanca leve demais e permitir autoevolucao insegura por IA.
- Documentar gates que nao estao conectados a CLI/API/UI.
- Deixar Code Intelligence desatualizado e induzir agentes a erro.
- Ter evidence textual sem prova executavel.
- Atualizar codigo sem atualizar cartografia, tornando o mapa falso.
- Permitir Forge OS executar varios agentes sem contratos de escopo.

## Exemplos

Exemplo de mudanca pequena:

```text
Task: corrigir bug isolado em comando existente.
Governanca: placement rapido, contexto minimo, diff pequeno, teste/command
e evidence no final.
```

Exemplo de mudanca estrutural:

```text
Task: adicionar novo gate ao Programming Domain.
Governanca: placement, spec antes do codigo, Code Intelligence, task contract,
implementacao, tests, docs-health, sync/index, evidence e cartografia.
```

Exemplo de trabalho Forge:

```text
Task: refatorar fluxo multiagente de self-construction.
Governanca: mother spec, packets, allowed_files, reservation ledger, scope map,
agents, integration queue, gates, evidence e learning.
```

## Proximas Acoes

1. Criar enforcement automatico para impedir execucao estrutural sem placement.
2. Promover spec antes do codigo para gate verificavel em Programming.
3. Fazer Code Intelligence alimentar context packs de Programming e Forge.
4. Normalizar task contracts para CLI, API, UI e agentes.
5. Publicar cartografia de programacao com zoom sistema -> modulo -> arquivo -> simbolo -> evidence.
6. Integrar Learning pos-execucao com docs, tests, prompts e proposals.
