---
id: atlas-domain-runtime-contract
type: engineering_knowledge
title: Atlas Domain Runtime Contract
status: active
category: atlas-ai
priority: 100
summary: Contrato canonico (Meta 2 entregue) executavel de Domain Runtime, DomainManifest, Domain Registry, Capability Catalog, Handoff cross-domain e Maturity Assessment, sem criar Kernel, Tool Runtime, Policy Engine ou Evidence Ledger paralelos.
tags:
  - atlas-ai
  - domain-runtime
  - domain-manifest
  - capability-catalog
  - handoff
  - maturity-assessment
  - meta-2
capabilities:
  - domain_runtime_contract
  - domain_manifest_schema
  - domain_registry
  - capability_catalog
  - cross_domain_handoff
  - domain_maturity_assessment
  - domain_boundary_governance
decisions:
  - Domain Runtime Contract e a camada Meta 2 que permite cada dominio operar como empresa digital plugavel sob o Kernel comum.
  - DomainRuntime e interface conceitual com canHandle/plan/execute/validate/certify/handoff/describeCapabilities; nao substitui Mission Foundation (Meta 1), nao cria Kernel paralelo, Tool Runtime paralelo nem Evidence Ledger paralelo.
  - DomainManifest e o cadastro canonico de cada dominio; Domain Registry e o indice consultavel; Capability Catalog detalha capabilities por dominio.
  - Handoff cross-domain so vale com mission_id, objective_id, source_domain, target_domain, reason, context_pack, evidence_refs, expected_output, blockers e receipt_hash.
  - Maturity Assessment de cinco estagios (Assistant, Specialist, Department, Operating Unit, Autonomous Enterprise Unit) governa promocao; Stage 4/5 exige evidencia, metrics e certification.
  - Dominios iniciais cobertos pelo design pack: Software Company, Research, Corporate Strategy/Venture Studio, Finance/Investment, Marketing/Growth, Cyber Security, Personal Development/Learning, Automation/Tool Factory e Operations.
maintenance:
  - Atualize este design pack antes de implementar registry, manifest loader, capability catalog, handoff protocol ou maturity assessment.
  - Nao declarar este contrato implementado sem migrations, models, services, comandos e testes equivalentes em paths verificaveis.
  - Quando Meta 1 (Mission Foundation) avancar contratos, sincronize os campos compartilhados sem fork de schema.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-mission-mode.md
  - docs/engineering-knowledge-base/atlas-objective-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/domains/README.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-domain-runtime-contract
graph_title: Atlas Domain Runtime Contract
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-domain-company-runtimes
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
allowed_changes:
  - Refinar campos do DomainManifest, Capability Catalog e Handoff sem quebrar invariantes.
  - Adicionar dominios iniciais com manifest completo conforme amadurecem.
  - Atualizar mapeamento para Meta 1 (Mission), Meta 3 (Policy) e Meta 4 (Evidence) quando os contratos vizinhos avancarem.
forbidden_changes:
  - Declarar runtime implementado sem migrations, models, services, comandos e testes verificaveis.
  - Criar Kernel, Tool Runtime, Policy Engine ou Evidence Ledger paralelos dentro de um dominio.
  - Permitir handoff sem mission_id, context_pack, evidence_refs e receipt_hash.
  - Promover dominio para Stage 4/5 sem maturity assessment com evidence pack auditavel.
depends_on:
  - atlas-autonomous-intelligence-operating-system
  - atlas-domain-company-runtimes
  - atlas-mission-mode
  - atlas-objective-intelligence
  - atlas-ai-kernel-architecture
flows_to:
  - atlas-autonomous-software-company-runtime
  - atlas-autonomous-control-plane
  - atlas-tool-economy
unlocks:
  - multi-domain-plugin-architecture
  - cross-domain-handoff-with-evidence
governs:
  - atlas_ai.domain_runtime_contract
  - atlas_ai.domain_registry
  - atlas_ai.capability_catalog
  - atlas_ai.cross_domain_handoff
  - atlas_ai.domain_maturity_assessment
evidence:
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - system
  - contract
  - domain-runtime
ai_entrypoints:
  - Leia Resumo, Interface Conceitual, DomainManifest, Registry, Capability Catalog, Handoff, Maturity e Plano de Implementacao Futuro antes de propor codigo.
ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.
  - Meta 2 entregue (2026-05-18): tabelas ai_domain_*, models AiDomain*, services em app/Services/Ai/DomainRuntime/, comando atlas:ai:domain-runtime --action=readiness|seed-defaults|list|select|handoff|control-plane.
quality_gates:
  - manifest-loaded
  - registry-resolved
  - capability-resolved
  - handoff-receipt-emitted
  - maturity-assessed
failure_modes:
  - Dominio sem manifest vira prompt solto.
  - Handoff sem context_pack perde objetivo.
  - Capability mal mapeada chama tool errada.
  - Promocao prematura para Stage 4/5 sem evidencia real.
observability_signals:
  - domain_id
  - manifest_version
  - capability_id
  - handoff_receipt_hash
  - maturity_stage
next_actions:
  - Ligar DomainRuntimeSelectionService ao Atlas Router/Intent para selection automatica por mission/objetivo.
  - Meta 3 Policy/Budget: avaliar policy_profile e quality_gates do manifest antes de transition para runtime running.
  - Meta 4 Evidence Ledger: persistir handoff_receipt, maturity_assessment_hash e runtime_record receipt_hash como eventos imutaveis.
line_limit: 520
---
# Atlas Domain Runtime Contract

## Resumo

Design pack da Meta 2 do Autonomous Intelligence OS: contrato executavel
para o Atlas operar cada dominio como empresa digital plugavel. Consolida
DomainRuntime, DomainManifest, Domain Registry, Capability Catalog, Handoff
cross-domain e Maturity Assessment, sem criar Kernel, Tool Runtime, Policy
Engine ou Evidence Ledger paralelos.

Meta 1 (Mission Foundation) entrega mission/objetivo/DoD e contratos
`atlas.ai.mission.*`. Esta Meta 2 entra logo depois: dado o objetivo, qual
dominio executa, com que capabilities, sob qual manifest, com qual handoff
e em que estagio de maturidade.

## Papel no Atlas

Define **como** um dominio existe e opera. Responsabilidades:

- declarar contrato executavel de cada DomainRuntime;
- padronizar DomainManifest como cadastro unico do dominio;
- governar Domain Registry como indice consultavel e anti-duplicacao;
- catalogar Capabilities com schemas, gates e maturidade;
- formalizar Handoff cross-domain auditavel;
- aplicar Maturity Assessment com criterios verificaveis.

Fora do escopo: substituir Mission Foundation (Meta 1), duplicar Kernel,
criar Tool Runtime/Policy Engine/Evidence Ledger paralelos por dominio.

## Onde Se Encaixa

```text
Surface
-> Mission Foundation (Meta 1)        : mission_id / objective_id / DoD
-> Domain Runtime Contract (Meta 2)   : Registry / Manifest / Runtime /
                                        Capability Catalog / Maturity
-> Policy / Permission / Budget / Safety (Meta 3)
-> Evidence Ledger (Meta 4)
-> Tool Runtime / Control Plane / Certification
```

Meta 1 diz **o que/por que**. Esta meta diz **quem/como**. Meta 3/4 cobrem
**safety/prova**.

## Interface Conceitual

Todo DomainRuntime implementa o contrato logico:

```text
DomainRuntime
- canHandle(objective)    -> score, reason, blockers
- plan(mission)           -> ordered steps, capabilities, gates
- execute(step)           -> step_receipt, evidence_refs
- validate(output)        -> validation_result, blockers
- certify(delivery)       -> certification_record
- handoff(toDomain)       -> handoff_receipt
- describeCapabilities()  -> capability_summary
```

- `canHandle` e advisory; decisao final fica com Domain Registry.
- `plan` consulta Capability Catalog; nao escolhe tool, provider, modelo
  ou credencial.
- `execute` opera via Tool Runtime/Kernel; emite step_receipt e
  evidence_refs no Evidence Ledger.
- `validate` e `certify` aplicam quality_gates do manifest sem silenciar
  blockers nem reescrever DoD.
- `handoff` produz handoff_receipt auditavel; nao perde contexto nem burla
  policy do dominio destino.
- `describeCapabilities` projeta subset publico do manifest para Registry e
  Cartografia.

## DomainManifest

Cadastro canonico de um dominio. Campos minimos:

| Campo | Papel |
|---|---|
| `domain_id` | id unico, kebab-case, estavel |
| `name` | nome legivel |
| `charter` | missao, fronteira, usuarios, outcomes, proibicoes |
| `ontology` | objetos centrais (Finding, Portfolio, Campaign, Opportunity) |
| `departments` | mesas/funcoes internas |
| `capabilities` | capability_id permitidos |
| `flow_profiles` | flows oficiais (entrada, saida, gates, artifacts) |
| `tools_allowed` | capabilities de Tool Runtime liberadas |
| `policy_profile` | autonomia, risco, custo, legal, privacidade |
| `memory_scope` | o que e por quanto tempo memoriza |
| `evidence_schema` | tipos de evidencia aceitos |
| `quality_gates` | gates bloqueantes para validate/certify |
| `handoff_rules` | dominios destino permitidos/proibidos |
| `delivery_types` | artifacts versionados aceitos como entrega final |
| `metrics` | qualidade, impacto, custo, tempo, risco, falha, aprendizado |
| `forbidden_actions` | acoes proibidas mesmo com permissao tecnica |
| `maturity_stage` | Assistant/Specialist/Department/Operating Unit/Autonomous Enterprise Unit |
| `owner` | responsavel humano por aprovacao/auditoria |
| `status` | planned/scaffold/implemented/building/available/deprecated |

Schema: `atlas.ai.domain_manifest.v1`. Cada manifest gera `manifest_hash`
para deteccao de drift; mudanca estrutural exige aprovacao do owner e
registro no Evidence Ledger.

## Domain Registry

Indice operacional dos manifests carregados:

- **Registrar:** carregar manifest, validar schema, computar hash, checar
  duplicacao, emitir receipt.
- **Consultar:** retornar manifest por `domain_id`; listar/filtrar por status,
  maturity_stage, owner ou capability.
- **Selecionar candidato:** dado objective, ranquear dominios por
  `canHandle` + manifest fit + policy fit + maturity; retornar
  `domain_decision` com primario e secundarios.
- **Evitar duplicacao:** rejeitar manifest cujo `domain_id`, capability ou
  ontology colida; oferecer caminho de flow/profile no dominio existente.

Fronteira canonica:

| Conceito | Definicao |
|---|---|
| Domain | empresa digital com charter, ontology, departments, gates, artifacts, metrics |
| Flow | caminho executavel **dentro** de um dominio |
| Profile | variante de autonomia/risco/policy de flow ou dominio |
| Capability | habilidade declarada e schema-driven do dominio |
| Tool | execucao concreta via Tool Runtime |

Problema que cabe como flow/profile ou capability nova de dominio existente
**nao** justifica dominio novo. Dominio novo entra apenas quando charter,
ontology, gates, artifacts e metrics sao genuinamente distintos.

## Capability Catalog

Detalha cada habilidade oferecida por um dominio. Campos minimos:

| Campo | Papel |
|---|---|
| `capability_id` | id unico kebab-case |
| `domain_id` | dominio dono |
| `description` | descricao operacional curta |
| `input_schema` | shape do envelope de entrada |
| `output_schema` | shape do envelope de saida |
| `allowed_tools` | capabilities de Tool Runtime consumidas |
| `risk_level` | low / medium / high / critical |
| `required_gates` | quality_gates obrigatorios antes de certify |
| `evidence_required` | tipos de evidencia obrigatorios |
| `maturity_level` | Stage minimo para operar essa capability |

Schema canonico: `atlas.ai.domain_capability.v1`. Capability `critical` exige
`maturity_level` >= Department e policy_profile com aprovacao humana.
Capability nunca opera sem manifest do dominio ativo nem acessa Tool fora de
`allowed_tools`. Capability e habilidade do **dominio**; Tool e execucao
concreta no Tool Runtime.

## Handoff Cross-Domain

So e valido quando carrega contexto completo, sem perda. Campos obrigatorios
do `handoff_receipt`:

| Campo | Papel |
|---|---|
| `mission_id` | id da mission (Meta 1) |
| `objective_id` | objetivo operacional (Meta 1) |
| `source_domain` | dominio origem |
| `target_domain` | dominio destino |
| `reason` | motivo auditavel |
| `context_pack` | snapshot do contexto necessario ao destino |
| `evidence_refs` | hashes de evidencias relevantes ate aqui |
| `expected_output` | o que o destino deve entregar de volta |
| `blockers` | bloqueios conhecidos no momento |
| `receipt_hash` | hash determinstico do handoff_receipt |

Handoff sem `context_pack` e invalido; nao pode burlar `policy_profile`,
`forbidden_actions` nem `handoff_rules` do manifest origem/destino. Todo
handoff e registrado no Evidence Ledger. Schema: `atlas.ai.domain_handoff.v1`.

## Maturity Assessment

Cada dominio evolui em cinco estagios. Promocao exige evidencia, metrics,
gates verdes e certification. Schema: `atlas.ai.domain_maturity_assessment.v1`.

Criterio avaliado por stage: `artifacts`, `evidence`, `tests/gates`,
`metrics`, `autonomy`, `reliability`, `human approval`, `certification`.

**Stage 1 - Assistant.** Notas/respostas/planos. Evidence:
transcricoes/hashes. Gates basicos. Metrics: uso e satisfacao. Autonomy
baixa; humano aprova cada delivery. Certification opcional.

**Stage 2 - Specialist.** Workflows com input/output definido. Receipts no
Ledger. Quality_gates por capability. Metrics de sucesso/tempo/custo.
Autonomy media; flows pre-aprovados rodam sem confirmar passo a passo.
Approval por flow critico. Delivery pack basico por flow.

**Stage 3 - Department.** Entregas multi-flow com handoffs internos.
Receipts por departamento e handoff receipts. Gates por departamento e
delivery. Metrics: SLA, retrabalho, blockers. Autonomy media-alta no escopo.
Approval por delivery final e risco alto. Certification com blockers
explicitos.

**Stage 4 - Operating Unit.** Ciclos recorrentes com dashboards. SLA
records, audit trail. Gates: release, SLA breach, drift. Metrics: SLA,
uptime, custo unitario, qualidade, learning rate. Autonomy alta no mandato.
Approval por mudanca de mandato e risco critico. Certification ciclo a
ciclo com metrics auditadas.

**Stage 5 - Autonomous Enterprise Unit.** Outcomes de negocio. Chain of
custody completa, compliance trail. Gates de compliance, legal, finance,
security, reputacao. Metrics: outcome financeiro, risco residual, learning
compounding. Autonomy governada por mandato escrito, limites, kill switch.
Approval por auditoria periodica + mudanca estrategica. Enterprise Unit
Certification com evidence pack pleno.

Promocao para Stage 4/5 sem evidencia, metrics e certification e proibida e
gera violacao no Evidence Ledger.

## Dominios Iniciais

Dominios cobertos pelo design pack, todos com status `planned` enquanto Meta
2 nao for implementada. Detalhe operacional continua em
`atlas-domain-company-runtimes.md` e nos specs em `domains/`.

| Domain | Charter resumido | Stage alvo inicial |
|---|---|---|
| Software Company Runtime | Engenharia de software (Dev, Debug, Review, QA, Security, Forge, Delivery) | Department |
| Research Company Runtime | Fontes primarias, contradiction check, citations, opportunity radar, sintese | Specialist |
| Corporate Strategy / Venture Studio | Oportunidades, modelagem de empresa, TAM/SAM/SOM, GTM, experimentos | Specialist |
| Finance / Investment | Research desk, valuation, portfolio, risk, compliance, reporting (sem trade real sem mandato) | Specialist |
| Marketing / Growth | Posicionamento, ICP, campanha, copy, funil, experimentos (sem publicar/gastar sem approval) | Specialist |
| Cyber Security | AppSec, GRC, defensive, remediation, pentest/bug bounty autorizado (sem ofensivo sem RoE) | Specialist |
| Personal Development / Learning | Metas, habitos, estudo, pratica deliberada (nao clinico) | Assistant |
| Automation / Tool Factory | Browser, terminal, GitHub, API, repo evaluation, tool builder/evolution | Specialist |
| Operations | Diagnostico, runbook, incidente, readiness (sem deploy/restart/infra mutation) | Assistant |

Nenhum dominio entra direto em Stage 4/5. Promocao depende de Maturity
Assessment com evidence.

## Regras Anti-Erro

- Nao criar dominio novo se cabe como flow/profile de dominio existente.
- Nao criar agent solto sem DomainRuntime/manifest.
- Nao criar tool especifica quando o Tool Runtime ja oferece a capability via
  `allowed_tools`.
- Nao criar policy local quando o Policy Layer (Meta 3) resolve via
  `policy_profile`.
- Nao declarar Stage 4/5 sem evidencia, metrics e certification auditados.
- Nao misturar Domain, Flow, Profile, Capability e Tool nos campos do
  manifest.
- Nao executar handoff sem `context_pack`, `evidence_refs` e `receipt_hash`.
- Nao criar Kernel, Tool Runtime, Policy Engine, Evidence Ledger ou UI shell
  paralelos por dominio.
- Nao declarar contrato implementado sem migrations, models, services,
  comandos e testes verificaveis.

## Plano de Implementacao Futuro

Pre-design. Nenhum codigo deve ser criado a partir deste pack sem aprovacao
explicita e sem Meta 1 estavel.

**Migrations sugeridas.** `atlas_ai_domain_manifests` (id, domain_id,
manifest_hash, version, status, maturity_stage, owner, payload jsonb);
`atlas_ai_domain_capabilities` (id, capability_id, domain_id, risk_level,
maturity_level, payload jsonb); `atlas_ai_domain_handoffs` (id, mission_id,
objective_id, source_domain, target_domain, reason, receipt_hash, payload
jsonb); `atlas_ai_domain_maturity_assessments` (id, domain_id, stage,
evidence_refs jsonb, metrics jsonb, certification_hash, owner).

**Models sugeridos.** `AtlasAiDomainManifest`, `AtlasAiDomainCapability`,
`AtlasAiDomainHandoff`, `AtlasAiDomainMaturityAssessment`.

**Services sugeridos.** `DomainManifestLoader` (parse/validate/hash);
`DomainRegistryService` (consulta/selecao/anti-duplicacao);
`CapabilityCatalogService` (resolve capability/gates/tools);
`DomainHandoffService` (context_pack, receipt, validacao de
policy/handoff_rules); `DomainMaturityAssessmentService` (scorecard por
stage, gates de promocao).

**Comandos sugeridos.** `atlas:ai:domain-manifest:load <path>`,
`atlas:ai:domain-registry:list`, `atlas:ai:domain-registry:select
--objective=<...>`, `atlas:ai:capability-catalog:show <capability_id>`,
`atlas:ai:domain-handoff:emit --mission=<id> --target=<domain>`,
`atlas:ai:domain-maturity:assess <domain_id>`.

**Testes obrigatorios.** Loader rejeita manifest invalido ou com colisao de
domain_id; Registry rejeita capability duplicada sem ownership; Capability
resolve `allowed_tools` apenas dentro do manifest; Handoff sem `context_pack`
ou `receipt_hash` e bloqueado; Maturity recusa Stage 4/5 sem evidence_refs.

**Relacao com Meta 1 (Mission Foundation).** Consome `mission_id`,
`objective_id`, `definition_of_done`, `mission_class` em `plan`, `execute`,
`validate`, `certify` e `handoff`, sem duplicar schema.

**Relacao com Meta 3 (Policy).** DomainManifest aponta para
`policy_profile`; nao implementa policy local. Capability declara
`required_gates` avaliados pelo Policy Layer.

**Relacao com Meta 4 (Evidence).** Evidence Ledger e unica fonte de receipts
e evidence_refs. Step/handoff/certification receipts vivem no Ledger.
`evidence_required` por capability define o que o Ledger observa antes do
certify.

## Contratos

- `atlas.ai.domain_manifest.v1`
- `atlas.ai.domain_capability.v1`
- `atlas.ai.domain_runtime.v1`
- `atlas.ai.domain_handoff.v1`
- `atlas.ai.domain_decision.v1`
- `atlas.ai.domain_delivery.v1`
- `atlas.ai.domain_certification.v1`
- `atlas.ai.domain_maturity_assessment.v1`

Estes contratos sao alvo de implementacao; este design pack nao cria
artefatos executaveis.

## Fluxo

1. Mission Foundation emite mission_id, objective_id e DoD.
2. Domain Registry seleciona primario/secundarios.
3. DomainManifest do dominio escolhido e carregado.
4. DomainRuntime.plan consulta Capability Catalog e monta plano.
5. DomainRuntime.execute opera step a step emitindo step_receipts.
6. DomainRuntime.handoff emite handoff_receipt quando necessario.
7. DomainRuntime.validate aplica quality_gates.
8. DomainRuntime.certify monta delivery + certification_record.
9. Maturity Assessment atualiza scorecard com base no resultado.

## Regras para IA

- Use este doc para entender fronteiras antes de criar dominio, capability,
  handoff ou maturity.
- Nao gere migrations, models, services, comandos ou testes diretamente a
  partir deste design pack; aguarde sinalizacao explicita.
- Sempre referencie Meta 1 para mission/objective, Meta 3 para policy e Meta
  4 para evidence; nao reescreva esses contratos.
- Nao introduza dominios fora da lista inicial sem charter, ontology, gates,
  artifacts, metrics e promotion gate.

## Escopo de Implementacao

Este design pack e docs-only. Mudancas devem ficar dentro de
`docs/engineering-knowledge-base/atlas-domain-runtime-contract.md` e nos
docs explicitamente listados em `Atualizar links em` da entrega.

## Dependencias

- Autonomous Intelligence OS (camada-mae).
- Domain Company Runtimes (papai conceitual).
- Mission Mode + Objective Intelligence (Meta 1).
- Kernel Architecture (envelope, receipt, ledger).
- Permission/Budget/Safety Layer (Meta 3).
- Evidence & Truth Layer (Meta 4).

## Evidencias

Evidencias aceitas: este proprio doc, futuras migrations/models/services,
testes de loader/registry/capability/handoff/maturity, receipts no Evidence
Ledger e certification records. Hoje somente este doc esta presente.

## Riscos

- Implementar manifest/registry antes de Meta 1 estabilizar mission_id pode
  forcar refactor.
- Capability Catalog mal modelado vira disputa de tool; gate via Policy e
  obrigatorio.
- Handoff sem context_pack robusto degrada para conversa solta.
- Promocao prematura para Stage 4/5 cria falsa autonomia.

## Exemplos

- "audite seguranca do checkout": Registry seleciona Software Company
  (primario) + Cyber Security (secundario); handoff carrega escopo, RoE,
  evidence_refs e expected_output.
- "pesquise mercado e proponha empresa": Research (primario) + Corporate
  Strategy (secundario); handoff carrega source_quality, citations e
  contradictions.
- "automacao no Instagram": Automation/Tool Factory avalia API oficial,
  browser, custo, ToS, safety; Capability bloqueia se `forbidden_actions`
  do manifest se aplicar.

## Proximas Acoes

1. Sincronizar com Meta 1 (Mission Foundation) quando ela publicar contratos
   estaveis de mission/objective.
2. Especificar migrations/models apenas apos Meta 1, 3 e 4 alinhadas.
3. Construir Domain Registry como primeiro componente executavel.
4. Implementar Capability Catalog em sequencia.
5. Formalizar Handoff Service e Maturity Assessment Service.
6. Criar comandos `atlas:ai:domain-*` e suite de testes minimos.

## Definition of Done

Este design pack esta pronto como documentacao quando:

- frontmatter canonico esta presente e valido;
- todas as secoes obrigatorias estao presentes;
- `php artisan atlas:engineering:knowledge docs-health --json` passa;
- links foram adicionados nos docs canonicos relacionados;
- nenhuma migration, model, service, comando ou teste foi gerado a partir
  deste arquivo.
