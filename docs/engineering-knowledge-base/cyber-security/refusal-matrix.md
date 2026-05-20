---
id: atlas-ai-cyber-refusal-matrix
type: engineering_knowledge
title: Atlas AI Cyber Refusal Matrix
status: building
category: policy
priority: 91
implementation_state: cyber_security_policy_matrix_building_not_kernel_enforced
summary: Matriz canonica de acoes que skills cyber-* NUNCA executam, integrada com Policy do kernel via Refusal-with-Receipt; nao bypassa policy nem cria policy paralela.
tags:
  - atlas-ai
  - cyber-security
  - refusal-matrix
  - policy
  - safety
capabilities:
  - cyber_refusal_matrix
decisions:
  - Refusal Matrix Cyber e EXTENSAO de policy do kernel; skills cyber-* consultam matriz antes de propor acao ao Atlas Decide.
  - Matriz e hard-coded por skill; bypass exige edit de codigo + revisao + ADR no doc dono.
  - Match conservador. Em duvida, recusa. False positive > false negative.
  - Recusa emite Refusal-with-Receipt (Decision Receipt + Evidence Ledger event); nunca silencia.
  - Programa BB que pede acao em regra da matriz exige clausula extra explicita do operador humano.
maintenance:
  - Atualize quando adicionar/remover regra ou exception clause.
  - Cada regra tem rule_id imutavel; mudanca semantica = nova regra com rule_id novo.
related_paths:
  - atlas-server/docs/engineering-knowledge-base/cyber-security-extension.md
  - atlas-server/docs/engineering-knowledge-base/atlas-ai-policy-engine.md
  - AtlasVault/_skills/cyber-bb-runner/SKILL.md
owner: atlas-ai
layer: extension
line_limit: 240
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cyber-refusal-matrix

graph_title: Atlas AI Cyber Refusal Matrix

graph_world: atlas

graph_layer: module

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: building

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/cyber-security/refusal-matrix.md

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
  - cyber-security

evidence:
  - docs/engineering-knowledge-base/cyber-security/refusal-matrix.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - policy
  - cyber-security

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
# Atlas AI Cyber Refusal Matrix

Skills cyber-* consultam esta matriz antes de propor acao ao Atlas Decide. Match em regra sem exception clause satisfeita resulta em Refusal-with-Receipt.

## Princípios

| # | Principio |
|---|---|
| R1 | Refusal-with-Receipt sempre. Recusa silenciosa proibida. |
| R2 | Matriz hard-coded em skill code. Bypass = edit + ADR. |
| R3 | Programa BB nao pode contradizer matriz. Se programa pede acao banida, skill recusa e notifica operador. |
| R4 | Match conservador. Em duvida, recusa. |
| R5 | Toda skill cyber-* referencia matriz; nao inventa regras proprias. |

## Schema de regra

```yaml
- rule_id: cyber-ref-NNN
  category: <categoria>
  pattern:
    technique: [<lista>]
    targets: [<lista, opcional>]
  rationale: "<por que recusa>"
  exception_clause: |
    Permitido SOMENTE com:
      - <clausula 1>
      - <clausula 2>
  on_match:
    action: refusal-with-receipt
    notify: [operador]
    abort_run_if: <condicao opcional>
```

## Regras canonicas

### Categoria: DoS / Resource Exhaustion

| rule_id | Tecnica | Exception |
|---|---|---|
| cyber-ref-001 | DoS, DDoS, slowloris, billion laughs, ZIP bomb, regex bomb | clausula extra do operador + janela < 1h + ambiente nao-prod dedicado |
| cyber-ref-002 | Bandwidth exhaustion sustentada > 10Mbps por > 60s | mesma clausula |
| cyber-ref-003 | Memory exhaustion attacks (XML XXE expansion, etc.) | mesma clausula |
| cyber-ref-004 | Account/lockout enumeration que pode bloquear contas reais | clausula + lista de usernames test-only |

### Categoria: Mass-Targeting / Out-of-Scope

| rule_id | Tecnica | Exception |
|---|---|---|
| cyber-ref-010 | Acao contra target NAO listado em programa BB / scope.in | sem excecao — sempre recusa |
| cyber-ref-011 | Scan a partir de input externo nao validado | sem excecao |
| cyber-ref-012 | Wildcard scan que extrapola escopo (`*.com` em vez de `*.uber.com`) | sem excecao |

### Categoria: Supply-Chain Ofensivo

| rule_id | Tecnica | Exception |
|---|---|---|
| cyber-ref-020 | Publicar pacote em registry publico com nome similar (typosquatting) | sem excecao |
| cyber-ref-021 | Dependency confusion contra alvo real | sem excecao |
| cyber-ref-022 | Comprometer pipeline CI do alvo via PR malicioso | sem excecao |
| cyber-ref-023 | Submeter PR com payload escondido a repos externos | sem excecao |

### Categoria: Evasao de Defensores

| rule_id | Tecnica | Exception |
|---|---|---|
| cyber-ref-030 | Bypass de EDR/AV/WAF/IDS contra defensor nao notificado | apenas com clausula `red-team-c2` + adversary emulation declarada |
| cyber-ref-031 | Tampering com logs do alvo apos exploitation | sem excecao |
| cyber-ref-032 | Spoofing de identidade de outro pentester / time interno | sem excecao |

### Categoria: PII / Dados Sensiveis

| rule_id | Tecnica | Exception |
|---|---|---|
| cyber-ref-040 | Coletar PII real alem de PoC minimo | sem excecao — limite = exfiltration_proof_max_bytes do programa BB |
| cyber-ref-041 | Acessar dados de saude / financeiros / judiciais reais | sem excecao sem clausula compliance vertical (HIPAA/PCI/etc.) |
| cyber-ref-042 | Capturar credentials de usuarios reais (nao-test) | sem excecao |
| cyber-ref-043 | Exportar evidencia fora de evidence_storage_location declarado | sem excecao |

### Categoria: Sistemas Criticos

| rule_id | Tecnica | Exception |
|---|---|---|
| cyber-ref-050 | Comprometer disponibilidade de sistema saude / vida (hospital, equipamento medico) | NUNCA, mesmo sob clausula |
| cyber-ref-051 | Sistemas SCADA / ICS de infra critica (energia, agua, transporte) | apenas aprovacao governamental + ambiente isolado |
| cyber-ref-052 | Sistemas financeiros core em producao (real-time settlement) | clausula extra + janela de baixo trafego + rollback testado |
| cyber-ref-053 | Componentes safety-critical (autonomous vehicles, aviation) | sem excecao sem aprovacao multi-stakeholder |

### Categoria: Operacao Sem Autorizacao

| rule_id | Tecnica | Exception |
|---|---|---|
| cyber-ref-060 | Iniciar acao sem programa BB publicado ou contrato | sem excecao |
| cyber-ref-061 | Continuar operacao fora de janela do programa | sem excecao |
| cyber-ref-062 | Operacao sem auth_proof valida em vigor | sem excecao |
| cyber-ref-063 | Operacao contra subsidiaria/parceiro nao nomeado no programa BB | sem excecao |

### Categoria: Persistence / Exfil Excessiva

| rule_id | Tecnica | Exception |
|---|---|---|
| cyber-ref-070 | Estabelecer persistencia alem do necessario para PoC | sem excecao sem clausula explicita |
| cyber-ref-071 | Exfil alem de exfiltration_proof_max_bytes do programa | sem excecao |
| cyber-ref-072 | Estabelecer canal C2 sem clausula `red-team-c2` | sem excecao |
| cyber-ref-073 | Pivot lateral alem de hosts em scope.in | sem excecao |

### Categoria: Targets Humanos / Engenharia Social

| rule_id | Tecnica | Exception |
|---|---|---|
| cyber-ref-080 | Phishing contra pessoas reais sem clausula `social-engineering` | sem excecao |
| cyber-ref-081 | Phishing contra pessoas externas ao escopo do programa | sem excecao mesmo com clausula |
| cyber-ref-082 | Coleta de info pessoal de funcionario do alvo via pretexting | apenas com `social-engineering` + GDPR/LGPD compliance |
| cyber-ref-083 | Acesso fisico nao autorizado | apenas com `physical` + autorizacao separada |

### Categoria: Compliance / Legal

| rule_id | Tecnica | Exception |
|---|---|---|
| cyber-ref-090 | Operacao em jurisdicao com lei diferente sem aprovacao local | sem excecao |
| cyber-ref-091 | Coleta de dados que viola GDPR Art. 9 (categorias especiais) | sem excecao |
| cyber-ref-092 | Operacao que viola sanctions (OFAC, UN) | sem excecao |
| cyber-ref-093 | Compartilhar finding com terceiro sem clausula | sem excecao |

### Categoria: Atlas Self-Protection

| rule_id | Tecnica | Exception |
|---|---|---|
| cyber-ref-100 | Modificar proprio Atlas runtime durante operacao Cyber | sem excecao |
| cyber-ref-101 | Bypassar Quality Gates a partir de skill cyber-* | sem excecao |
| cyber-ref-102 | Disable de Audit / Evidence Ledger durante operacao | sem excecao |
| cyber-ref-103 | Skill cyber-* submeter BB report autonomamente sem operador confirmar | sem excecao — Atlas observa, nao corrige |

## Refusal-with-Receipt schema

```json
{
  "receipt_kind": "cyber.refusal",
  "envelope_id": "...",
  "skill_id": "cyber-bb-runner",
  "rule_id": "cyber-ref-001",
  "matrix_hash": "sha256:...",
  "requested_action": {"technique": "dos", "target": "...", "params": {...}},
  "actor_requested": "operator | provider_tool",
  "rationale": "...",
  "exception_clause_evaluated": false,
  "exception_clause_missing": ["clausula_explicita_dos"],
  "at": "..."
}
```

## Como skill referencia matriz

Toda skill cyber-* tem secao `## Refusal` no SKILL.md:

```markdown
## Refusal

Esta skill consulta cyber-security/refusal-matrix.md antes de propor qualquer acao
ao Atlas Decide. Resposta padrao em match:

"Esta acao e bloqueada pela Refusal Matrix (regra cyber-ref-XXX, motivo: <prosa>).
Para executar: <exception_clause>. Posso desenhar caminho alternativo."
```

## Sinal de tentativa de bypass

Skill que tenta bypassar matriz N>2 vezes na mesma operacao:
1. Atlas Decide bloqueia.
2. Evento `cyber.refusal_matrix_bypass_attempt` no Evidence Ledger.
3. Pausa operacao + notifica operador humano.

## Resumo

Matriz canonica de acoes que skills cyber-* NUNCA executam, integrada com Policy do kernel via Refusal-with-Receipt; nao bypassa policy nem cria policy paralela.

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
