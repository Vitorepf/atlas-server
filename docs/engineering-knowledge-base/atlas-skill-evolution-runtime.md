---
id: atlas-skill-evolution-runtime
type: engineering_knowledge
title: Atlas Skill Evolution Runtime
status: active
category: learning-evolution
priority: 91
summary: Runtime governado que transforma outcomes verificados em propostas de skill ou planos de refatoracao, sem instalar ou promover skill automaticamente.
tags:
  - atlas-ai
  - skills
  - aemor
  - intelligence-factory
  - self-improvement
capabilities:
  - skill_evolution_proposal
  - skill_refactor_plan
  - outcome_to_skill_candidate
decisions:
  - Outcome verificado pode propor skill; nao pode instalar skill ativa sozinho.
  - Skill candidate precisa evidence_refs e revisao humana antes de ativacao.
  - Intelligence Factory registra a capability candidate; Skill System governa descoberta e confianca.
maintenance:
  - Atualizar quando schema, comando, policy de trust ou lifecycle de skills mudar.
related_paths:
  - app/Services/Ai/Skills/AtlasSkillEvolutionRuntimeService.php
  - app/Console/Commands/AtlasSkillEvolutionCommand.php
  - tests/Feature/Ai/Skills/AtlasSkillEvolutionRuntimeServiceTest.php
  - docs/engineering-knowledge-base/atlas-ai-skill-system.md
  - docs/engineering-knowledge-base/atlas-intelligence-factory-os.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-skill-evolution-runtime
graph_title: Atlas Skill Evolution Runtime
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-skill-system
graph_status: active
graph_source: repo
human_name: Atlas Skill Evolution Runtime
canonical_name: Atlas Skill Evolution Runtime
technical_name: AtlasSkillEvolutionRuntimeService
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-skill-evolution-runtime.md

owner: learning-evolution

repo_paths:
  - app/Services/Ai/Skills/AtlasSkillEvolutionRuntimeService.php
  - app/Console/Commands/AtlasSkillEvolutionCommand.php
  - tests/Feature/Ai/Skills/AtlasSkillEvolutionRuntimeServiceTest.php
  - docs/engineering-knowledge-base/atlas-skill-evolution-runtime.md

allowed_changes:
  - Ajustar proposta, refactor-plan, certificacao e integracao com Intelligence Factory.

forbidden_changes:
  - Instalar skill ativa sem revisao humana.
  - Promover skill para default sem evidence refs, testes e aprovacao.
  - Usar provider externo para gerar ou validar skill neste runtime.

depends_on:
  - atlas-ai-skill-system
  - atlas-intelligence-factory-os
  - atlas-execution-memory-outcome-runtime

flows_to:
  - atlas-intelligence-factory-os
  - atlas-cartography
  - atlas-code

unlocks:
  - outcome-driven-skill-proposals
  - governed-skill-refactoring

governs:
  - skill-evolution

evidence:
  - tests/Feature/Ai/Skills/AtlasSkillEvolutionRuntimeServiceTest.php
  - php artisan test tests/Feature/Ai/Skills
evidence_refs:
  - symbol: AtlasSkillEvolutionRuntimeService
  - command: atlas:skills:evolve

required_tests:
  - "php artisan test tests/Feature/Ai/Skills"
  - "php artisan atlas:skills:evolve propose --objective='...' --evidence=test:... --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true
risk_level: high

visual_tags:
  - runtime
  - learning
  - skills

ai_entrypoints:
  - Leia Resumo, Contratos, Fluxo, Regras para IA e Evidencias antes de alterar skills.

ai_usage_notes:
  - Use este runtime para propor/refatorar skills a partir de outcomes; nao escreva em skills/ diretamente sem gate humano.

quality_gates:
  - "php artisan test tests/Feature/Ai/Skills"
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Candidate sem evidencia vira prompt solto.
  - Skill nova duplica skill builtin existente.
  - Skill vira default sem eval e piora flows.

observability_signals:
  - atlas.skill_evolution.proposal.v1
  - atlas.skill_evolution.refactor_plan.v1
  - atlas.skill_evolution.certification.v1

next_actions:
  - Conectar AEMOR distillation e AAEL cycle ao comando quando houver policy de ativacao explicita.
---
# Atlas Skill Evolution Runtime

## Resumo

O Atlas Skill Evolution Runtime pega aprendizado verificado e transforma em uma
proposta governada de skill ou em um plano de refatoracao de skill existente.
Ele nao instala, nao ativa e nao promove skill automaticamente.

## O Que É

Uma ponte entre:

- AEMOR: outcomes, falhas, sucesso e evidencia.
- Intelligence Factory: capability candidate e simulacao.
- Skill System: descoberta, trust, validacao e ativacao.

## Para Que Serve

- Criar uma skill candidate quando um padrao recorrente aparece.
- Refatorar uma skill existente quando o outcome mostra lacuna.
- Evitar que IAs criem skills soltas, duplicadas ou sem evidencia.
- Manter skills pequenas, versionaveis e provider-neutral.

## Papel no Atlas

Converte aprendizado operacional em proposta pequena, revisavel e testavel. Ele
fica acima de AEMOR e Intelligence Factory e abaixo do Skill System ativo.

## Onde Se Encaixa

```text
AEMOR outcome
-> Skill Evolution Runtime
-> Intelligence Factory skill_candidate
-> revisao humana
-> Skill System
```

## Fluxo

```text
Outcome verificado
-> atlas:skills:evolve propose
-> proposta atlas.skill_evolution.proposal.v1
-> certificacao local do manifest
-> capability skill_candidate na Intelligence Factory
-> revisao humana
-> futura ativacao via Skill System
```

## Contratos

| Contrato | Regra |
|---|---|
| `atlas.skill_evolution.proposal.v1` | Proposta de criar/refatorar skill com hash, draft e evidence refs. |
| `atlas.skill_evolution.refactor_plan.v1` | Diagnostico de skill existente sem reescrever arquivo. |
| `atlas.skill_evolution.certification.v1` | Gate local: manifest parseavel, evidence refs, sem prompt injection, sem override perigoso. |

## Comandos

```bash
php artisan atlas:skills:evolve propose \
  --objective="Improve Atlas Dev repair loop" \
  --domain=programming \
  --flow-id=atlas_dev \
  --evidence=test:repair-loop \
  --json

php artisan atlas:skills:evolve refactor-plan \
  --skill=dev-quality-gate \
  --json
```

## Regras Para IA

- Nao escreva skill ativa em `skills/` como atalho.
- Primeiro gere proposta e leia a certificacao.
- Se a proposta duplicar skill builtin, trate como refactor-plan.
- Skill candidate sem `evidence_refs` fica bloqueada.
- Provider externo nao e chamado por este runtime.

## Escopo de Implementacao

Escopo permitido:

- `app/Services/Ai/Skills/AtlasSkillEvolutionRuntimeService.php`
- `app/Console/Commands/AtlasSkillEvolutionCommand.php`
- testes em `tests/Feature/Ai/Skills/`
- docs deste contrato e do Skill System

Fora de escopo:

- ativar skill como default;
- escrever direto em `skills/`;
- chamar provider para gerar skill;
- criar nova policy paralela de trust.

## Dependencias

- `atlas-ai-skill-system.md`: lifecycle e trust de skills.
- `atlas-intelligence-factory-os.md`: registro de capability candidate.
- `atlas-execution-memory-outcome-runtime.md`: origem dos outcomes.
- `SkillDiscoveryService`: descoberta e protecao contra override.
- `SkillManifestParser`: validacao local de manifest.

## Evidencias

- `app/Services/Ai/Skills/AtlasSkillEvolutionRuntimeService.php`
- `app/Console/Commands/AtlasSkillEvolutionCommand.php`
- `tests/Feature/Ai/Skills/AtlasSkillEvolutionRuntimeServiceTest.php`
- Gate: `php artisan test tests/Feature/Ai/Skills`

## Riscos

- Falso aprendizado: outcome fraco vira skill ruim.
- Duplicacao: skill nova repete builtin.
- Escalada indevida: skill candidate vira default sem eval.
- Contaminacao: texto bruto sensivel entra no corpo da skill.

## Exemplos

Criar proposta a partir de outcome:

```bash
php artisan atlas:skills:evolve propose \
  --objective="Improve Forge repair loop from verified failures" \
  --domain=programming \
  --flow-id=atlas_forge \
  --evidence=test:forge-repair \
  --json
```

Auditar refatoracao de skill existente:

```bash
php artisan atlas:skills:evolve refactor-plan \
  --skill=dev-quality-gate \
  --json
```

## Proximas Acoes

- Alimentar este runtime diretamente a partir de AEMOR distillation quando a
  policy de ativacao estiver explicitamente aprovada.
- Adicionar score de ROI de skill usando outcomes acumulados.
- Criar rollout humano para transformar candidate aprovada em skill instalada.

## Fonte Técnica Externa

Este runtime segue a linha de aprendizado por feedback verbal e bibliotecas de
skills verificadas: Reflexion, Self-Refine, Voyager e SWE-agent mostram que
feedback, ambiente, skill library e interface de execucao melhoram agentes sem
trocar pesos do modelo. No Atlas, isso fica subordinado a evidence refs e gates.
