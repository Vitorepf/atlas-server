---
id: atlas-ai-multi-domain-implementation-sequence
type: engineering_knowledge
title: Atlas AI Multi-Domain Implementation Sequence
status: active
category: atlas-ai
priority: 100
summary: Ordem canonica e mapa de paralelismo para construir o Atlas AI como Autonomous Intelligence OS multi-dominio sem duplicar Kernel, sem inverter dependencias e sem colidir entre Claudes/Codex executando em paralelo.
tags:
  - atlas-ai
  - autonomous-intelligence
  - domain-company-runtimes
  - implementation-sequence
  - multi-agent-coordination
capabilities:
  - implementation_sequence
  - parallel_work_planning
  - cross_agent_coordination
  - kernel_first_governance
  - domain_runtime_order
decisions:
  - Mission Foundation e a base unica; nenhum dominio entra antes de Kernel comum existir.
  - Software (Programming) e o primeiro dominio piloto porque ja existe musculatura no Atlas Dev/Forge; Research e Strategy entram em seguida porque alimentam todos os outros dominios.
  - Cyber, Finance e Marketing exigem maturidade de Policy/Permission/Budget e Evidence antes de virar Domain Company Runtime; nao podem ser construidos como prompts soltos.
  - Tool Registry e Tool Runtime sao pre-requisitos de Automation/Tool Factory e de qualquer dominio que dependa de ferramentas externas.
  - Personal Development entra depois que Policy nao-clinica esta clara; nunca antes.
  - Desktop/API Control Plane UX entra apos os runtimes estarem governados; ele nao pode preceder o backend que ele visualiza.
  - Esta doc nao declara qualquer meta como implementada; declara apenas ordem, dependencias e fronteiras.
maintenance:
  - Atualize quando adicionar nova meta, mudar a ordem, ou mudar fronteira entre metas paralelas.
  - Nao mova meta para "paralelizavel" sem listar arquivos que ela nao pode tocar.
  - Nao avance status sem evidencia: este doc e plano, nao prova de prontidao.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-mission-mode.md
  - docs/engineering-knowledge-base/atlas-objective-intelligence.md
  - docs/engineering-knowledge-base/atlas-tool-economy.md
  - docs/engineering-knowledge-base/atlas-autonomous-control-plane.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-multi-domain-implementation-sequence
graph_title: Atlas AI Multi-Domain Implementation Sequence
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas AI Multi-Domain Implementation Sequence
canonical_name: Atlas AI Multi-Domain Implementation Sequence
technical_name: atlas-ai-multi-domain-implementation-sequence
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
allowed_changes:
  - Refinar ordem, paralelismo e fronteiras de cada meta.
  - Adicionar nova meta listando dependencias e fronteira de arquivos.
forbidden_changes:
  - Declarar dominio "implementado" sem evidencia validada por gates proprios.
  - Inverter dependencia: nenhum dominio precede Mission Foundation, Policy, Evidence ou Tool Runtime de que dependa.
  - Apagar regra de anti-colisao entre Claudes/Codex sem fechar substituto equivalente.
depends_on:
  - atlas-autonomous-intelligence-operating-system
  - atlas-domain-company-runtimes
flows_to:
  - atlas-autonomous-software-company-runtime
  - atlas-tool-economy
  - atlas-autonomous-control-plane
unlocks:
  - governed-multi-domain-build-order
governs:
  - atlas_ai.implementation_sequence
evidence:
  - docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
evidence_refs:
  - symbol: AtlasAiMultiDomainImplementationSequenceService
  - command: atlas:aaeos:atlas-ai-multi-domain-implementation-sequence
  - test: AtlasAiMultiDomainImplementationSequenceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Ordem Canonica, Paralelismo, Fronteiras de Arquivos e Coordenacao Multi-Agente antes de comecar trabalho de runtime multi-dominio.
quality_gates:
  - mission-foundation-ready
  - domain-runtime-contract-ready
  - policy-permission-budget-ready
  - evidence-certification-ready
  - tool-registry-ready
  - router-dispatch-ready
failure_modes:
  - Comecar dominio sem Kernel comum.
  - Implementar Cyber/Finance/Marketing antes de Policy/Permission/Budget.
  - Construir Control Plane UX antes do runtime que ele governa.
  - Dois agentes editando o mesmo modulo do Kernel simultaneamente.
observability_signals:
  - meta_id
  - meta_status
  - depends_on_ready
  - blockers
  - touched_files_overlap
next_actions:
  - Usar esta sequencia ao planejar APs, Obras ou Forge runs envolvendo mais de um dominio.
line_limit: 520
---
# Atlas AI Multi-Domain Implementation Sequence

## Resumo

Esta doc define a ordem canonica para construir o Atlas AI como Autonomous
Intelligence OS multi-dominio. Ela existe para impedir que IAs e humanos
comecem dominios sem o Kernel pronto, dupliquem Policy/Evidence/Tool Runtime,
ou colidam quando varios providers (Claudes, Codex, Gemini) trabalham em
paralelo.

A doc nao implementa nada. Ela ordena, paraleliza e protege fronteiras.

## Papel no Atlas

E o plano operacional do Autonomous Intelligence OS (Layer 0.9). Define o
unico caminho aceitavel para promover dominios de scaffold para Domain Company
Runtime sem fork de Kernel, Policy, Evidence ou Tool Runtime.

## Onde Se Encaixa

```text
Autonomous Intelligence OS (tese)
-> Domain Company Runtime Contract (forma)
-> Multi-Domain Implementation Sequence (ordem) <- este doc
-> APs / Obras / Forge runs (execucao)
```

## Contratos

Esta doc nao introduz contratos novos. Ela cita os contratos canonicos ja
existentes:

- `atlas.ai.intelligence.mission.v1`
- `atlas.ai.intelligence.domain_decision.v1`
- `atlas.ai.intelligence.tool_plan.v1`
- `atlas.ai.intelligence.evidence_pack.v1`
- `atlas.ai.intelligence.certification.v1`
- `atlas.ai.domain_runtime.v1`
- `atlas.ai.domain_manifest.v1`

## Fluxo

A sequencia abaixo e o fluxo canonico de construcao multi-dominio. Saltos so
sao aceitos com evidencia de que a dependencia ja existe e foi certificada
por gates proprios. Esta secao funciona como Ordem Canonica das 14 metas.

1. **Mission Foundation.** Mission Mode + Objective Intelligence + WorkOrder
   universal. Sem isso, nenhum dominio tem como receber meta operacional.
2. **Domain Runtime Contract.** Manifest, departments, handoff, delivery,
   certification, maturity assessment. Define a forma plugavel que qualquer
   dominio futuro precisa respeitar.
3. **Policy / Permission / Budget.** Camada de safety transversal: autorizacao,
   custo, login, jurisdicao, escopo, RoE. Bloqueio antes de qualquer dominio
   tocar mundo externo.
4. **Evidence / Certification.** Evidence pack, receipts, completion audit,
   certification ledger. Sem isso, nenhum dominio pode declarar entregue.
5. **Tool Registry / Tool Runtime.** Tool Economy + Super Tool Runtime +
   Tool Builder + Tool Evolution Loop. Pre-requisito de qualquer dominio que
   dependa de browser, terminal, GitHub, API, repos ou ferramentas proprias.
6. **Router / Runtime Dispatch.** Router runtime enterprise + domain selector
   + handoff cross-domain + flow profiles. Decide qual Domain Company Runtime
   recebe a mission.
7. **Programming Adapter (Software Domain).** Adapta Atlas Dev/Forge ao
   contrato de Domain Company Runtime. Primeiro dominio porque a musculatura
   ja existe.
8. **Research / Strategy Adapter.** Deep Research Runtime + Market
   Intelligence + Venture Studio. Alimentam todos os outros dominios com
   fontes, oportunidades e tese.
9. **Finance / Investment.** Research desk, valuation, portfolio, risk,
   reporting. Trade real exige mandato, broker policy, approval, audit; sem
   isso, opera somente em modo research/simulacao.
10. **Marketing / Growth.** ICP, campanha, criativo, copy, funil, analytics,
    experimento. Nunca publica nem gasta midia sem approval/policy.
11. **Cyber Security.** AppSec, GRC, remediation, defensive security e
    pentest/bug bounty somente com autorizacao, escopo, RoE, legal/privacy
    gate. Entra apos Policy/Evidence estarem maduros.
12. **Personal Development / Learning.** Estudo, habitos, pratica deliberada,
    spaced review. Non-clinical sempre. Entra apos Policy estar clara para
    contexto humano sensivel.
13. **Automation / Tool Factory.** Browser, APIs, terminal, GitHub, repo
    evaluation, tool building e tool evolution operados como Domain Company
    Runtime, nao como prompt solto. Depende de Tool Registry e Policy.
14. **Desktop / API Control Plane UX.** Painel humano que visualiza missions,
    domain runs, evidence, blockers e certifications. Entra apos o runtime
    que ele governa existir e emitir signals reais.

## Paralelismo

Metas que podem rodar em paralelo (com fronteiras de arquivos):

- **1 + 2:** Mission Foundation e Domain Runtime Contract podem evoluir juntas
  se o contrato nao depender de campos ainda nao decididos da mission.
- **3 + 4:** Policy/Permission/Budget e Evidence/Certification sao
  independentes em arquivos, mas devem ser integradas no Kernel comum.
- **5 + 6:** Tool Registry e Router Dispatch podem ser construidos em
  paralelo, desde que Router nao assuma forma de tool catalog que ainda nao
  existe.
- **8 + 9 + 10:** Research, Finance e Marketing podem rodar em paralelo apos
  Mission/Policy/Evidence/Tool estarem prontos. Cada um toca sua propria
  pasta de dominio.
- **11 + 12:** Cyber e Personal Development sao paralelizaveis entre si pois
  nao compartilham domain artifacts.
- **13 + 14:** Automation e Control Plane UX so paralelizam se UX consumir o
  contrato de runtime e nao ditar mudancas nele.

Metas que NUNCA paralelizam:

- 1 com qualquer dominio (1 e pre-requisito).
- 2 com 7-13 (contrato precede adapters).
- 3 com 9-12 (sem Policy, dominios sensiveis nao podem comecar).
- 4 com 7-13 (sem Evidence, nada e "entregue").
- 5 com 13 (Automation depende de Tool Registry).
- 14 com qualquer runtime que ele ainda nao governa.

## Dependencias Estritas

```text
1 Mission Foundation
   |- governa 2..14
2 Domain Runtime Contract
   |- governa 7..13
3 Policy / Permission / Budget
   |- governa 7..13 (especialmente 9, 10, 11, 12, 13)
4 Evidence / Certification
   |- governa 7..14
5 Tool Registry / Tool Runtime
   |- governa 8, 9, 11, 13 (qualquer dominio que use ferramentas externas)
6 Router / Runtime Dispatch
   |- governa 7..13 (precisa saber para onde mandar)
7 Programming Adapter
   |- piloto; nao bloqueia 8..13 apos pronto
8 Research / Strategy
   |- alimenta 9, 10, 11
14 Desktop / API Control Plane UX
   |- consome 1..13; nunca os precede
```

## Fronteiras de Arquivos por Meta

Para evitar colisao, cada meta deve declarar antes de comecar:

- **Mission Foundation (1):** `app/Services/Atlas/Mission/`, migrations de
  `mission`, `objective`, `definition_of_done`. NAO tocar dominios.
- **Domain Runtime Contract (2):** `docs/engineering-knowledge-base/atlas-domain-company-runtimes.md`,
  futuras specs em `domains/`. NAO tocar Kernel, Policy, Evidence ou Tool Runtime.
- **Policy / Permission / Budget (3):** `app/Services/Atlas/Policy/`,
  migrations de policy/budget. NAO tocar dominios diretamente.
- **Evidence / Certification (4):** `app/Services/Atlas/Evidence/`,
  Evidence Ledger projections. NAO tocar dominios diretamente.
- **Tool Registry / Tool Runtime (5):** `app/Services/Atlas/Tool/`,
  catalogo, registry, executor. NAO criar dominio novo.
- **Router / Runtime Dispatch (6):** `app/Services/Atlas/Router/`, domain
  selector, flow profiles. NAO escrever logica de dominio.
- **Programming Adapter (7):** `app/Services/Atlas/Domains/Programming/`,
  adapters Atlas Dev/Forge. NAO mudar Kernel.
- **Research / Strategy (8):** `app/Services/Atlas/Domains/Research/`,
  `Strategy/`. NAO mudar Mission, Policy ou Evidence.
- **Finance (9):** `app/Services/Atlas/Domains/Finance/`. NAO executar
  ordens reais nem alterar Policy global.
- **Marketing (10):** `app/Services/Atlas/Domains/Marketing/`. NAO publicar
  nem gastar midia sem approval.
- **Cyber (11):** `app/Services/Atlas/Domains/Cyber/`. NAO tocar Tool
  Runtime; pedir tools via contrato.
- **Personal Development (12):** `app/Services/Atlas/Domains/PersonalDevelopment/`.
  NAO mutar calendario, tarefas humanas ou Learning Plane do Core.
- **Automation / Tool Factory (13):** `app/Services/Atlas/Domains/Automation/`.
  NAO criar Tool Registry paralelo.
- **Desktop / API Control Plane UX (14):** `atlas-desktop/`, `atlas-app/`,
  rotas read-only de API. NAO ditar formato de runtime, mission ou evidence.

## Coordenacao Multi-Agente

Quando varios providers (Claudes, Codex, Gemini) atuam em paralelo:

- **Uma meta = um owner por janela.** Duas IAs nao editam a mesma meta na
  mesma janela. Use Obras Shared Workspace ou packet locks.
- **Cada IA declara metas que NAO vai tocar.** Reduz interpretacao livre.
- **PR pequeno e atomico por meta.** Evita merges humanos enormes.
- **Antes de comecar, rodar `git status` e `git diff origin/main`.** Confirma
  que ninguem ja esta no mesmo modulo.
- **Frontmatter `repo_paths` e lei.** Se um doc canonico declara `repo_paths`,
  outro provider nao deve sobrescrever sem aviso.
- **Nao reverter trabalho alheio.** Se um arquivo foi recem-tocado por outro
  provider, pause e peca decisao humana.
- **Receipts e evidencias sao append-only.** Nao apague evidence de outra IA.

## Regras para IA

- Nao comece dominio sem checar se Mission Foundation existe e esta verde.
- Nao crie segundo Kernel, Policy Engine, Tool Runtime, Evidence Ledger ou
  UI shell. Toda meta usa o Kernel comum.
- Nao implemente Cyber, Finance ou Marketing sem Policy/Permission/Budget
  certificado.
- Nao construa Control Plane UX assumindo campos que o runtime ainda nao
  emite.
- Nao paralelize duas metas que tocam o mesmo modulo do Kernel.
- Nao declare meta "pronta" sem evidencia: este doc e plano, nao prova de
  prontidao. Prontidao vive em docs de runtime + certification + gates.
- Nao remova esta doc da reading list de START_HERE e do canonical index
  sem aprovar substituto equivalente.

## Escopo de Implementacao

Esta doc nao implementa codigo. Implementacao acontece em APs/Obras/Forge
runs que citam esta sequencia em seu plan. Mudancas aqui pedem revisao
porque alteram a ordem que governa todos os dominios futuros.

## Dependencias

- `atlas-autonomous-intelligence-operating-system.md` — tese.
- `atlas-domain-company-runtimes.md` — forma.
- `atlas-mission-mode.md` — pre-requisito da meta 1.
- `atlas-objective-intelligence.md` — pre-requisito da meta 1.
- `atlas-tool-economy.md` — pre-requisito da meta 5.
- `atlas-autonomous-control-plane.md` — pre-requisito da meta 14.
- `atlas-autonomous-software-company-runtime.md` — referencia da meta 7.

## Evidencias

Evidencia aceitavel para mover uma meta de "planned" para "ready":

- AP/Obra com receipts emitidos pelo runtime correspondente;
- gates verdes citados no doc dono da meta (nao aqui);
- testes que cobrem o contrato canonico relevante;
- registro em `atlas:engineering:knowledge docs-health --json` sem violacao
  de schema, ownership ou tamanho.

## Riscos

- Sequencia muito rigida cria gargalo se um time ficar parado em Mission
  Foundation; mitigacao: paralelismo declarado nas secoes acima.
- Duas IAs interpretarem "paralelizavel" sem checar fronteiras de arquivos
  causa merge conflicts e regressao; mitigacao: secao Fronteiras de Arquivos.
- Pular ordem sob pressao de prazo gera Kernel duplicado; mitigacao: failure
  mode listado e gates do canonical-module-doc-v1.

## Exemplos

Pedido: "construir Cyber agora".

Resposta correta: bloquear ate confirmar que 1, 2, 3, 4 e 5 estao verdes;
caso contrario, abrir as metas faltantes primeiro.

Pedido: "tres Claudes trabalhando em Research, Finance e Marketing ao mesmo
tempo".

Resposta correta: aceitavel se 1-6 estao prontos e cada Claude declara
fronteira de arquivos diferente; cada um abre PR atomico.

Pedido: "fazer Control Plane UX antes do runtime".

Resposta correta: recusar. UX consome runtime; nao o precede.

## Proximas Acoes

1. Manter esta sequencia citada em APs e Obras multi-dominio.
2. Quando uma meta ficar "ready", atualizar apenas seus docs especificos; nao
   declarar prontidao aqui.
3. Revisar paralelismo quando uma nova meta entrar no plano.

## Definition of Done

Esta doc esta pronta quando:

- a ordem canonica esta clara e citada por APs/Obras multi-dominio;
- nenhuma IA inicia dominio sem checar pre-requisitos listados aqui;
- conflitos de paralelismo entre Claudes/Codex sao resolvidos por consulta
  a Fronteiras de Arquivos e Coordenacao Multi-Agente;
- docs-health passa sem violar schema, tamanho ou ownership.
