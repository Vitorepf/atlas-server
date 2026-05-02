---
id: engineering-knowledge-maintenance-playbook
type: engineering_knowledge
title: Playbook De Manutencao Do Harness
status: active
category: maintenance
priority: 95
summary: Processo operacional para revisar, manter e aprimorar o Atlas Engineering Harness Runner sem perder contexto.
tags:
  - maintenance
  - review
  - harness
capabilities:
  - maintenance_review
  - release_readiness
  - context_rehydration
decisions:
  - Toda manutencao relevante deve comecar carregando docs canonicos e registry.
  - Validacao deve cobrir backend, app e docs quando a mudanca atravessa camadas.
maintenance:
  - Usar este playbook antes de alterar Harness, Atlas-Bench, visual harness ou quality scan.
  - Registrar novas decisoes como ADR.
related_paths:
  - app/Console/Commands/AtlasEngineeringKnowledgeCommand.php
  - app/Services/Engineering/EngineeringContextPackService.php
---

# Playbook De Manutencao

## Antes De Editar

1. Rode `atlas engineering knowledge status`.
2. Se os docs mudaram, rode `atlas engineering knowledge sync --prune`.
3. Leia a matriz de capacidades.
4. Identifique qual camada sera tocada: CLI, API, service, migration, app, docs ou tests.
5. Verifique se a mudanca precisa alterar context pack, Atlas-Bench ou release gate.

## Durante A Implementacao

- manter mudancas pequenas e auditaveis;
- preferir services existentes a novas abstracoes;
- preservar path-safety e limites de payload;
- nao colocar logs longos no registry;
- registrar decisao nova em ADR quando afetar politica ou arquitetura;
- manter comandos gratuitos/local-first quando possivel.

## Depois De Editar

Executar, no minimo, validacoes focadas:

```bash
/opt/homebrew/bin/php artisan test tests/Feature/AtlasEngineeringKnowledgeBaseTest.php
/opt/homebrew/bin/php artisan test tests/Feature/EngineeringHarnessRunnerTest.php --filter=context
```

Quando app/API forem alterados:

```bash
npm run test:front
npm run typecheck
```

Quando docs mudarem:

```bash
atlas engineering knowledge sync --prune
atlas engineering knowledge list --json
```

## Quando Criar ADR

Crie ADR quando a decisao:

- muda fonte de verdade;
- muda politica de autonomia;
- muda criterio de release;
- adiciona dependencia operacional;
- afeta privacidade, seguranca, custo ou reprodutibilidade;
- altera como o Atlas monta contexto para providers.

## Definicao De Pronto

Uma melhoria do Harness so esta pronta quando:

- codigo passa em teste focado;
- docs canonicos explicam como manter;
- registry foi sincronizado;
- app/CLI/API refletem o fluxo quando necessario;
- o context pack consegue recuperar a referencia relevante.
