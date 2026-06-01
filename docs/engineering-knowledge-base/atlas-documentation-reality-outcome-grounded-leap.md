---
id: atlas-documentation-reality-outcome-grounded-leap
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Documentation Reality Outcome Grounded Leap
slug: atlas-documentation-reality-outcome-grounded-leap
status: planned
category: documentation-governance
priority: 98
summary: Nivel L2 do ADRS. A verdade-mae deixa de ser interna (codigo bate com doc) e passa a ser externa (resultado bate com intencao, no mundo real). O substrato passa a co-formar a intencao e a compor aprendizado entre todos os projetos do operador. So abre depois do L1 provado; nao e runtime, e o alvo canonico do L2.
human_summary: Hoje o ADRS pergunta "o codigo corresponde ao doc?". No L2 ele pergunta "isso funcionou de verdade — para o usuario, no faturamento, em producao?" — e passa a opinar se o que voce mandou construir era a coisa certa, aprendendo entre todas as suas empresas.
human_what: Nivel L2 do ADRS — verdade ancorada em resultado real, co-formacao de intencao e compounding entre todo o patrimonio do operador.
human_purpose: Fazer o Atlas parar de so acertar a execucao e passar a acertar o alvo — construir a coisa certa, medida pelo mundo, nao so pelo doc.
human_input: Recebe o ADRS ja no L1 (gerativo/antifragil), o Evidence Ledger, sinais de outcome reais (uso, producao, financeiro) e os objetivos do operador.
human_output: Entrega verdade ancorada em resultado, julgamento sobre o proprio spec e anticorpos que se propagam entre projetos.
human_change_when: Mexa quando o L1 estiver provado, ou quando novos sinais de outcome real ficarem disponiveis.
human_block_when: Bloqueie se tentarem abrir o L2 antes do L1 provado, ou se a co-formacao de intencao passar por cima da soberania do operador.
canonical_name: Atlas Documentation Reality Outcome Grounded Leap
technical_name: AtlasDocumentationRealityOutcomeGroundedService
cartography_type: roadmap
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - outcome-grounded
  - intent-forming
  - multi-estate
capabilities:
  - outcome_grounded_truth
  - intent_co_formation
  - multi_estate_compounding
  - cross_domain_antibody_propagation
  - real_world_validity_scoring
decisions:
  - A verdade-mae do L2 e externa: resultado-no-mundo, nao so consistencia codigo<->doc.
  - O ADRS passa de guardiao da verdade para socio de julgamento: opina se o spec e a coisa certa.
  - Aprendizado e imunidade compoem entre todos os projetos do operador (multi-estate), respeitando soberania local-first.
  - Co-formacao de intencao e sempre advisory + human-gated; nunca sobrescreve a decisao do operador.
  - L2 so abre depois do L1 provado (drift zero); abrir antes e proibido.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando o L1, o Evidence Ledger ou os sinais de outcome mudarem.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
graph_id: atlas-documentation-reality-outcome-grounded-leap
graph_title: Atlas Documentation Reality Outcome Grounded Leap
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-reality-system
graph_status: planned
graph_source: repo
owner: documentation-governance
implementation_state: north_star_no_runtime_yet
depends_on:
  - atlas-documentation-reality-system
  - atlas-documentation-reality-generative-leap
  - atlas-ai-knowledge-governance-system
flows_to:
  - atlas-documentation-reality-reflective-self-model
  - atlas-self-improvement-governance-ladder
unlocks:
  - real_world_grounded_documentation
  - architecture_as_judged_not_just_verified
  - cross_project_immune_compounding
governs:
  - documentation-governance-outcome-truth
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-leap.md
allowed_changes:
  - Refinar as tres capabilities (outcome-grounded, intent co-forming, multi-estate) e a ordem.
  - Ligar cada capability a sua fonte de sinal real (Evidence Ledger, producao, financeiro).
forbidden_changes:
  - Abrir o L2 antes do L1 provado.
  - Permitir co-formacao de intencao que passe por cima da soberania do operador.
  - Vazar verdade canonica sensivel entre projetos; classes sensitive/secret/cyber nao saem da maquina.
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-leap.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-evolution-ladder.md
requires_evidence: false
risk_level: critical
line_limit: 520
ai_entrypoints:
  - Leia este doc para entender o L2 depois de dominar o L1; nunca implemente L2 antes do L1 provado.
ai_usage_notes:
  - North-star; nenhuma capability aqui esta implementada sem evidencia que resolve no indice.
  - Outcome-grounded exige sinal real; sem sinal, o L2 nao pontua verdade externa e nao pode fingir que pontua.
next_actions:
  - Provar o L1 (P1->P2->P3) antes de abrir qualquer capability do L2.
  - Mapear as fontes de sinal de outcome real disponiveis (uso, producao, financeiro) por dominio.
---

# Atlas Documentation Reality Outcome Grounded Leap

## Resumo

O L2 e o salto da verdade **interna** para a verdade **externa**. No L0/L1 a
pergunta-mae e "o codigo corresponde ao doc canonico?". No L2 a pergunta-mae
vira:

```text
Isso que construimos funcionou de verdade —
para o usuario, no faturamento, em producao, no mundo?
```

O "Reality" do Documentation Reality System deixa de ser **realidade-de-codigo** e
passa a ser **realidade-de-resultado**. E o ADRS para de so **verificar** o que
foi mandado e passa a **co-formar a intencao** — opinar se o proprio spec e a
coisa certa de construir, dado o objetivo do operador.

## Papel no Atlas

Acertar a execucao (L1) nao basta se o alvo estiver errado. Uma IA pode
implementar perfeitamente, com drift zero, uma coisa que ninguem usa ou que nao
move o resultado. O L2 fecha esse buraco: a verdade documental passa a ser
graduada pelo **mundo**, e o aprendizado de um projeto **imuniza** os outros.

## Onde Se Encaixa

```text
L1 (gerativo/antifragil, provado)
  -> L2 (este doc)
     O1 Outcome-grounded truth
     O2 Intent co-formation
     O3 Multi-estate compounding
  -> L-inf (auto-modelo reflexivo)
```

## Contratos

Tres capabilities canonicas, em ordem:

| Cap | Nome | O que muda |
|---|---|---|
| O1 | Outcome-grounded truth | doc graduado por resultado real (uso, producao, financeiro), nao so por "implementado"; fecha o loop com o Evidence Ledger |
| O2 | Intent co-formation | o substrato opina se o spec e a coisa certa dado o objetivo; advisory + human-gated, nunca sobrescreve o operador |
| O3 | Multi-estate compounding | rot/aprendizado pego num projeto imuniza todos; anticorpo cruza dominios respeitando soberania local-first |

Regra de autoridade (herdada, com extensao): repo docs > codigo > **outcome real
(Evidence Ledger)** > read models > cartografia > chat. O outcome real entra como
camada de verdade acima dos read models.

## Fluxo

```text
tarefa proposta
-> L1 preve impacto tecnico (duplica? drift? quebra?)
-> O2: o substrato pergunta "isso e a coisa certa pro objetivo?" (advisory)
-> operador decide (soberania)
-> implementacao (write-bound, gerativa)
-> O1: outcome real medido e ligado ao doc (funcionou no mundo?)
-> O3: aprendizado/anticorpo propaga para os outros projetos do operador
```

## Regras para IA

- NUNCA abrir o L2 antes do L1 provado.
- O2 e sempre advisory + human-gated; a IA opina, o operador decide.
- O1 so pontua verdade externa quando ha sinal real; sem sinal, nao finge pontuar.
- O3 respeita soberania: classes sensitive/secret/cyber nao saem da maquina; o que cruza projetos e padrao/anticorpo, nao dado bruto sensivel.

## Escopo de Implementacao

North-star. Nenhum runtime e criado por este doc. Cada capability (O1, O2, O3)
sera doc filho/promocao propria quando houver sinal real e codigo que resolve no
indice, com gate de maturidade e drift zero.

## Dependencias

- atlas-documentation-reality-generative-leap (L1, pre-requisito provado).
- atlas-ai-knowledge-governance-system (fonte-da-verdade).
- Evidence Ledger e sinais de outcome real (uso, producao, financeiro).

## Evidencias

Doc de alvo (north-star). A evidencia e o contrato + o L1 + a base ADRS. Nenhuma
capability esta declarada como runtime.

## Riscos

- **Atribuicao falsa de outcome:** correlacao tratada como causa. Mitigacao: O1 exige sinal explicito e ledger, nao adivinhacao.
- **Co-formacao que invade soberania:** a IA decidir o alvo por conta. Mitigacao: O2 sempre advisory + human-gated.
- **Vazamento multi-estate:** dado sensivel de um projeto cruzar para outro. Mitigacao: so padrao/anticorpo cruza; sensitive/secret/cyber ficam locais.
- **Castelo na areia:** abrir L2 sobre L1 nao provado. Mitigacao: gate de promocao da escada.

## Exemplos

```text
L1 diz: "esse servico ficou implementado, drift zero." (verdade interna ok)
O1 pergunta: "alguem usou? moveu o resultado?" -> se ninguem usou em 90 dias,
   o doc e marcado como implementado-sem-outcome: tecnicamente vivo, mas nao validado pelo mundo.
O2 teria perguntado, antes de construir: "isso e o alvo certo pro seu objetivo,
   ou ha algo de maior alavanca?" (advisory)
```

## Proximas Acoes

- Provar o L1 inteiro antes de abrir qualquer capability do L2.
- Inventariar as fontes de sinal de outcome real por dominio (uso, producao, financeiro).
- Definir a fronteira de soberania do compounding multi-estate (o que pode cruzar).
