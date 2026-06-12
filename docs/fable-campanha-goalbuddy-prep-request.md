# GoalBuddy Prep Request — Campanha Fable

Prepare a execucao da Campanha Fable do Atlas como uma campanha enorme, multi-dia, com board duravel, receipts, prova por slice e retomada fria.

## Fonte de execucao obrigatoria

Leia e siga integralmente:

`/Users/vitorepf/develop/Atlas/atlas-server/docs/fable-campanha-execution-prompt.md`

## Plano canonico governante

Leia completamente:

`/Users/vitorepf/develop/Atlas/atlas-server/docs/fable-campanha-11-dias-nxm.md`

## Workspace principal

`/Users/vitorepf/develop/Atlas/atlas-server`

## Objetivo

Criar um GoalBuddy board para executar a proxima obra pendente da Campanha Fable, sem pular ordem, respeitando o ledger canonico, os DoDs, as politicas de captura e as decisoes `[OPERADOR]`.

## Estrategia de modelos

- Fable 5 deve ser usado para PM, arquitetura, Judge, decomposicao dificil, decisoes de governanca, refutacao adversarial e auditoria final.
- OpenAI Codex `gpt-5.5` deve ser usado para Worker implementation slices: edicao de codigo, testes, refactors, correcoes mecanicas e execucao tecnica pesada.
- MiniMax M3 deve ser usado apenas para tarefas baratas: Scout simples, resumo, manutencao do board, titulos, receipts, triagem leve e limpeza de notas.
- Nao gastar Fable 5 com boilerplate, refactor mecanico ou tarefas repetitivas.

## Regras obrigatorias

1. A Campanha Fable e grande demais para um unico `/goal` bruto; use GoalBuddy como camada de campanha e Hermes `/goal` como motor de execucao.
2. Durante este `/goal-prep`, nao implemente codigo do Atlas ainda. Apenas prepare o board, `goal.md`, `state.yaml`, `notes/` e o comando de continuacao.
3. A primeira obra deve ser descoberta lendo o ledger em `docs/fable-campanha-11-dias-nxm.md`: primeira obra em andamento ou pendente.
4. Nunca pular a ordem O-1 -> O-2 -> O-3 etc.
5. Cada obra deve ser quebrada em slices com DoD verificavel.
6. Cada slice precisa ter prova: testes, artefatos, leitura direta, output de comando, ledger ou evidencia concreta.
7. Quem cria nao mede: toda entrega precisa de verificacao adversarial independente.
8. Antes de qualquer implementacao futura, a sessao executora deve rodar:

```bash
/opt/homebrew/bin/php artisan atlas:ai:session-bootstrap --task="<obra>" --json
bin/atlas open-brain context "<obra>" --json
```

9. Se precisar criar peca nova de runtime, dominio ou surface, exigir:

```bash
/opt/homebrew/bin/php artisan atlas:ai:place-feature "<peca>" --json
```

10. Ao completar obra ou slice, exigir captura:
    - testes verdes;
    - docs canonicos atualizados quando aplicavel;
    - `bin/atlas engineering knowledge sync --prune`;
    - `bin/atlas engineering knowledge index-code --prune --workspace "$(pwd)"`;
    - memoria Atlas provider-safe registrada;
    - ledger atualizado em `docs/fable-campanha-11-dias-nxm.md`;
    - checklist de DoD relido item por item.

## Board esperado

Crie `docs/goals/<slug>/goal.md`, `docs/goals/<slug>/state.yaml` e `docs/goals/<slug>/notes/`.

O board deve conter:

- PM/Fable: carregar plano, ledger, bootstrap e AOBG.
- Judge/Fable: validar proxima obra, riscos, DoD e ordem.
- Scout/MiniMax: mapear evidencias e arquivos sem gastar Fable.
- Worker/Codex: slices de implementacao com `allowed_files`, `verify` e `stop_if`.
- Judge/Fable: refutacao adversarial por slice.
- PM/Fable: atualizacao de ledger e captura final.
- MiniMax: manutencao de receipts e resumo do estado.

## Primeira tarefa ativa

Deve ser segura e preparatoria: ler o plano canonico, identificar a proxima obra no ledger, registrar a obra alvo e montar os primeiros slices. Nao modificar codigo ainda nesta preparacao.

## Criterio de sucesso do GoalBuddy prep

O prep so termina quando existir um board coerente, com `goal.md` e `state.yaml` prontos, primeira tarefa ativa segura, DoD claro, prova definida, politica de modelos registrada e comando final para iniciar o Hermes `/goal`.

Ao final, imprimir exatamente o comando:

```text
/goal Follow docs/goals/<slug>/goal.md.
```

Depois perguntar se deve iniciar agora, refinar o board ou parar.
