---
id: atlas-dev-final-operating-model
type: engineering_knowledge
title: Atlas Dev Final Operating Model
status: active
category: programming
priority: 100
summary: Forma final canonica do Atlas Dev como runtime de engenharia operador-presente dentro do Atlas Autonomous Engineering Government v3 - missao, limites, fronteira com Forge e Autonomos, fluxo fim-a-fim, criterios de qualidade, uso de musculos externos, papel do operador, kernel compartilhado e roadmap por patamares A0-A7.
tags:
  - atlas-dev
  - operating-model
  - autonomous-engineering
  - engineering-kernel
capabilities:
  - atlas_dev_final_operating_model
  - operator_present_engineering_runtime
decisions:
  - Atlas Dev e o runtime user-space operador-presente do Atlas Autonomous Engineering Government; Forge e obra longa/pesada; Autonomos e 24/7 sem operador. Os tres NUNCA se fundem e NUNCA duplicam engenharia; compartilham mecanismos exclusivamente via Engineering Kernel.
  - A hierarquia de autoridade do Dev e AAEG -> AAEOS/Mission Control -> Policy Plane -> Engineering Kernel -> Atlas Dev. Dev nao possui verified=true, main/release entry, provider secrets nem promocao de memoria canonica.
  - Operator Rebate e canonico - trabalho de baixo risco com operador presente exige menos cerimonia para nao gerar bypass; a prova final continua em receipts e gates.
  - Musculos/modelos externos (Claude Code, Codex, Cursor, Hermes) sao aceleradores substituiveis do Dev; a arquitetura final nao depende de nenhum deles.
  - Qualidade do Dev e definida por entrega verificada (teste real, diff minimo, escopo respeitado, zero proxy), nunca por velocidade bruta, contagem de linhas ou UI.
  - O roadmap do Dev final segue os patamares A0-A7 de atlas-dev-patamares.md; este doc nao cria escada paralela.
maintenance:
  - Atualizar quando a fronteira Dev/Forge/Autonomos, o Engineering Kernel ou os patamares A0-A7 mudarem de contrato.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-autonomous-work-execution-os.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-final-operating-model
graph_title: Atlas Dev Final Operating Model
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-dev-index
graph_status: active
graph_source: repo
human_name: Atlas Dev Final Operating Model
canonical_name: Atlas Dev Final Operating Model
technical_name: atlas-dev-final-operating-model
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-dev-final-operating-model.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-final-operating-model.md
allowed_changes:
  - Refinar contratos, fronteiras e criterios quando prova de runtime mudar.
forbidden_changes:
  - Fundir Dev com Forge ou Autonomos, ou criar engine de engenharia paralelo fora do Engineering Kernel.
  - Declarar patamar ou prontidao sem evidencia verificavel e gates verdes.
  - Inserir benchmark, medicao competitiva ou framing de marketing.
depends_on:
  - atlas-dev-index
  - atlas-autonomous-engineering-government
  - atlas-dual-core-engineering-system
flows_to:
  - atlas-dev-policy
  - atlas-dev-patamares
  - atlas-dev-efficient-programming-flow-v1
unlocks:
  - atlas_dev_final_target_alignment
governs:
  - atlas_dev.final_operating_model
evidence:
  - docs/engineering-knowledge-base/atlas-dev-final-operating-model.md
evidence_refs:
  - symbol: AtlasDevPolicyService
  - command: atlas:aaeos:atlas-dev-policy
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - system
  - module
  - atlas-dev
ai_entrypoints:
  - Leia atlas-dev-index.md primeiro; este doc define a FORMA FINAL do Dev, nao o patamar atual.
ai_usage_notes:
  - O patamar atual e o escopo de implementacao vivem em atlas-dev-patamares.md e no trio efficient-flow; este doc governa o alvo.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - IA implementar capability de patamar futuro citando este doc como licenca; o doc e alvo, o patamar e o limite.
observability_signals:
  - docs-health status ok
next_actions:
  - Manter alinhado com o Engineering Kernel conforme os adapters aterrissarem.
line_limit: 320
---
# Atlas Dev Final Operating Model

## Resumo

Este doc define a forma final do Atlas Dev: o runtime de engenharia
**operador-presente** do Atlas. A tese de qualidade e uma so: o Dev entrega
melhor que Cursor/Codex/Factory nao por ter modelo melhor, mas porque cada
entrega passa por compreensao profunda do projeto (contexto governado),
execucao com escopo travado e prova real - e porque cada resultado vira
aprendizado que melhora a proxima entrega. Velocidade vem do Operator Rebate,
nunca do corte de prova.

## Missao E Limites

Missao: transformar intencao do operador em software entregue, verificado e
aprendido, com o minimo de idas-e-vindas e zero microgerencia.

Dentro do escopo: bugfix com causa raiz, feature slice, refactor e
simplificacao, QA e bug hunt, review, explicacao workspace-bound, multi-file
edit em escopo declarado.

Fora do escopo: obra longa multiagente (Forge), operacao 24/7 sem operador
(Autonomos), decisao de politica de merge/release (Governor), verdade final de
verificacao (Verification Court), promocao de memoria canonica (Learning
controller).

## Dev x Forge x Autonomos

| Runtime | Modo | Operador | Horizonte |
|---|---|---|---|
| Atlas Dev | operador-presente, fast-lane governada | presente, aprova intencao e recebe evidencia | minutos-horas |
| Atlas Forge | obra pesada, SDD, multiagente/multiprovider | patrocina a obra, revisa marcos | dias-meses |
| Autonomos / Self-Construction | 24/7 Atlas construindo Atlas | ausente do fluxo normal (visibilidade e emergencia) | continuo |

Os tres sao user-space runtimes completos por si. Dev NAO e "Forge mini";
Forge NAO e "gerente do Dev"; Autonomos NAO e o Loop legado. Roteamento entre
eles pertence a Mission Control/Policy Plane
(`atlas-dual-core-engineering-system.md`), e escalada Dev->Forge usa o
escalation packet canonico daquele doc.

## Hierarquia

```text
Atlas Autonomous Engineering Government (AAEG)
-> Mission Control / AWEOS        (compila intencao, rota, risco, outcome)
-> Policy Plane                   (gates, budget, isolamento, merge como dados)
-> Engineering Kernel             (ProviderPort, WorkcellExecutor, ReceiptLedger,
                                   MergeActuator, BudgetMeter)
-> Atlas Dev                      (este runtime)
```

O Dev consome mecanismos do Kernel; nunca os reimplementa. O que o Dev nao
pode possuir: `verified=true` (Verification Court), main/release entry
(Governor), provider secrets (Kernel/Policy), promocao de memoria canonica
(Learning-Application Controller).

## Fluxo Fim-A-Fim

```text
1. Intencao      operador pede em linguagem natural; Dev compila em objetivo,
                 escopo candidato e risco (Mission Control shape)
2. Contexto      Context Cortex + Domain Map + Code Intelligence montam o pack
                 minimo suficiente (compreensao antes de qualquer edicao)
3. Plano         mini-spec-before-code: design path escolhido, allowed scope
                 declarado, criterio de aceite runnavel; operador ve e aprova
                 quando o risco pede
4. Implementacao edicao somente no allowed scope, diff minimo, sem WIP alheio
5. Prova         testes reais do aceite + gates do risco (syntax/boot/testes),
                 nunca self-report como verdade final
6. Review        simplification check + adversarial review proporcional ao
                 risco (Quality Gates do Programming Governance)
7. Receipts      Decision Receipt + evidencia no ReceiptLedger do Kernel
8. Knowledge     docs/memoria/code-index sincronizados; outcome vira
                 aprendizado (Outcome Learning) para a proxima entrega
```

Cada passo e proporcional ao risco (Operator Rebate): um typo-fix percorre o
mesmo fluxo em segundos com gates minimos; uma mudanca de contrato percorre
com prova completa. Pular passo nunca e o mecanismo de velocidade.

## Compreensao Do Projeto

O diferencial de qualidade vem da esquerda do fluxo: Context Cortex (read
model do workspace), Domain Map (onde cada capacidade vive), Design Paths
(alternativas de desenho com custo/risco explicitos) e Code Intelligence
(simbolos e consumidores reais). O Dev decide COM o cerebro do Atlas, nao com
grep cego. Contexto insuficiente e razao para perguntar UMA vez certo, nao
para chutar.

## Tipos De Entrega E Anti-Overengineering

- Bugfix: causa raiz, nunca sintoma; o fix cobre todos os callers.
- Feature: a fatia minima que entrega o valor pedido, com teste do aceite.
- Refactor/simplificacao: preserva comportamento com prova (testes antes e
  depois); refactor sem objetivo de capacidade ou remocao de duplicacao real
  e recusado.
- QA/bug hunt: achado vem com reproducao e evidencia, nunca so opiniao.
- Arquitetura: o desenho mais simples que aguenta o requisito real; camada
  especulativa, abstracao de uma implementacao e config para valor fixo sao
  anti-padroes bloqueados no review.

## Musculos Externos

Enquanto existirem, Claude Code/Codex/Cursor/Hermes sao aceleradores atras do
ProviderPort do Kernel: o Dev decide, o musculo executa, a prova e do Atlas.
Nenhum musculo escolhe escopo, aprova o proprio trabalho ou vira dependencia
estrutural. O Dev final funciona com qualquer musculo trocado - e, no alvo
Atlas-native, sem nenhum deles.

## Papel Do Operador

O operador: expressa intencao, aprova direcao em pontos de risco, recebe
evidencia (diff, testes, receipts) e pode interromper sempre. O operador NAO:
microgerencia passos, carrega contexto de memoria, repete instrucao ja
canonica, nem serve de gate rotineiro. Se o Dev precisa do operador para
progresso ordinario alem de intencao e aprovacao de risco, o Dev esta abaixo
do alvo.

## Criterios De Qualidade (Nao-Negociaveis)

- Zero proxy: metrica de esforco (linhas, contagem de testes, cobertura) nunca
  conta como valor; impacto real conta.
- Teste real: o aceite roda de verdade; evidencia e output, nao narrativa.
- Diff minimo: a menor mudanca que resolve com causa raiz.
- Allowed scope: edicao fora do escopo declarado e falha, nao iniciativa.
- Sem WIP roubado: trabalho nao-commitado de outrem nunca entra no diff.
- Sem complexidade inutil: cada camada nova paga com falha real removida.

## Kernel Compartilhado x Especifico Do Dev

Compartilhado via Engineering Kernel (com Forge e Autonomos): invocacao de
provider (ProviderPort), execucao de workcell (WorkcellExecutor), receipts
(ReceiptLedger), land/canary/revert (MergeActuator, sob Governor), medicao de
custo (BudgetMeter). Politicas vem do Policy Plane como dados.

Especifico do Dev: a conversa operador-presente, o fast-lane com Operator
Rebate, o plano visivel, os 17 artefatos do efficient-flow e a UX de
aprovacao. Duplicar mecanismo do Kernel dentro do Dev e anti-padrao bloqueado;
criar "engine do Dev" paralelo e violacao constitucional.

## Roadmap Por Fases

O roadmap canonico e a escada de patamares de `atlas-dev-patamares.md` - este
doc nao cria escada paralela:

```text
A0 Conversation -> A1 Foundation (atual) -> A2 Plan Visible
-> A3 Provider Verified -> A4 Self-Healing -> A5 Surface Parity
-> A6 Adaptive Engine -> A7 Continuous Learning (forma final deste doc)
```

A forma final descrita aqui corresponde a A6/A7: contexto adaptativo, prova
default, aprendizado continuo por outcome e kernel compartilhado maduro.
Implementar capability de patamar futuro antes do patamar chegar e anti-padrao
(`atlas-dev-patamares.md` governa o presente; este doc governa o alvo).

## Anti-Erros Canonicos

- UI bonita nao e entrega; entrega e software verificado com receipt.
- Velocidade sem prova e divida, nao qualidade; o rebate reduz cerimonia, nao
  verdade.
- Engine paralelo (executor, memoria, provider driver ou fila propria do Dev)
  e proibido; o caminho e o Kernel.
- Task count, churn de linhas e green self-report nao sao valor.
- Confundir Dev com o produto inteiro: Atlas AI e o produto; Dev e um runtime.

## Papel no Atlas

Define o alvo canonico do runtime operador-presente dentro do governo v3,
dando a qualquer IA o criterio para decidir o que pertence ao Dev, o que
pertence ao Kernel e o que pertence aos irmaos Forge/Autonomos.

## Onde Se Encaixa

Filho de `atlas-dev-index` (entrypoint). Irmao de policy/patamares/glossary.
Consome contratos de `atlas-autonomous-engineering-government.md` e
`atlas-dual-core-engineering-system.md`.

## Contratos

Fronteiras da tabela Dev/Forge/Autonomos; hierarquia AAEG->AAEOS->Kernel->Dev;
criterios de qualidade nao-negociaveis; kernel compartilhado x especifico.

## Fluxo

Secao "Fluxo Fim-A-Fim" acima e o contrato operacional; o detalhe de schema
vive no trio efficient-flow.

## Regras para IA

- Ler `atlas-dev-index.md` antes deste doc; patamar atual limita implementacao.
- Nunca criar mecanismo que o Engineering Kernel ja oferece.
- Nunca dar ao Dev autoridade de Court/Governor/Learning controller.
- Tratar os criterios de qualidade como bloqueantes, nao aspiracionais.

## Escopo de Implementacao

Este doc e alvo e fronteira; implementacao acontece nos docs donos (trio
efficient-flow, runbooks) e nas tasks do governo v3.

## Dependencias

Listadas em `depends_on` e `related_paths` do frontmatter.

## Evidencias

docs-health verde; contratos citados existem nos docs donos; kernel interfaces
referenciadas no doc de governo.

## Riscos

- Doc-alvo ser lido como licenca para implementar patamar futuro.
- Fronteira Dev/Forge erodir por conveniencia de curto prazo.
- Musculo externo virar dependencia silenciosa do fluxo.

## Exemplos

Operador: "corrige o bug do lease leak". Dev compila intencao, Cortex traz os
arquivos e consumidores reais, plano de 3 linhas com escopo e aceite, patch
minimo, teste roda, receipt gravado, outcome aprendido. Zero microgerencia.

## Proximas Acoes

- Referenciar os adapters do Engineering Kernel quando aterrissarem (tasks
  fable-v3-w3-*).
- Revisar fronteiras quando A2/A3 forem promovidos.
