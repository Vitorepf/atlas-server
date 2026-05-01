---
name: dev-quality-gate
description: Quality gates obrigatorios antes de declarar implementacao concluida. Plan -> Validate -> Execute para mudancas destrutivas, batch ou que tocam 2+ arquivos.
license: proprietary
compatibility: Requires git and PHP >= 8.4. Designed for Atlas CLI dev mode.
metadata:
  version: 1.1.0
  atlas:
    trust_level: builtin
    platforms: [macos, linux]
    requires_tools: [file.write, file.patch, git.diff, git.status, test.run]
---

# Dev Quality Gate

Use esta skill quando o Atlas esta implementando codigo, corrigindo bug, revisando alteracoes, rodando `atlas dev --complete`, operando com `--max-iterations > 1`, ou quando a mudanca toca 2+ arquivos. O objetivo e impedir conclusao sem plano, validacao, diff revisado e evidencia de teste.

## When to use

- Antes de iniciar fase `edit` que toca multiplos arquivos.
- Antes de operacao destrutiva, batch ou mecanica.
- Antes de declarar `completion_packet.status: passed`.
- Em repair loop, antes de cada nova iteracao que altera arquivos.

## Procedure

### 1. Plan

Antes de qualquer write:

- Liste arquivos a modificar com motivo.
- Crie um plano JSON intencional, semelhante a um `field_values.json`, com `files_to_modify`, `intent`, `tests` e `side_effects`.
- Declare em 1 paragrafo o que vai mudar e por que isso fecha o objetivo.
- Se existir mudanca previa no workspace, separe `pre_existing_changes` de `planned_changes`.

Plano minimo:

```json
{
  "intent": "corrigir validacao de login sem tocar fluxo de billing",
  "files_to_modify": ["app/Auth/LoginService.php", "tests/Feature/LoginTest.php"],
  "tests": ["php artisan test tests/Feature/LoginTest.php"],
  "side_effects": ["cache de teste pode ser recriado em storage/framework"],
  "pre_existing_changes": []
}
```

### 2. Validate

Rode:

```bash
skills/dev-quality-gate/scripts/validate-plan.sh plan.json
```

Se o shell tiver mais de um PHP instalado, o script respeita `ATLAS_PHP_BIN` e valida que o binario atende PHP >= 8.4 antes de chamar o Atlas.

O validador precisa passar antes de editar. Ele verifica:

- JSON valido.
- `files_to_modify` existe e nao esta vazio.
- Cada path fica dentro do workspace autorizado.
- Plano declara testes.
- Gate nativo `atlas:cli:quality` roda e retorna JSON.

Leia `references/quality-gates.md` se precisar interpretar `git_status`, `git_diff`, `workspace_changes` ou `tests`.

### 3. Execute

Depois de validar:

- Aplique mudancas via ferramentas do Atlas runtime (`file.write`, `file.patch`, `git.apply_patch`) para preservar checkpoint e auditoria.
- Nao use `shell.run` para escrita quando uma ferramenta estruturada resolver.
- A cada checkpoint relevante, revise o diff acumulado.
- Se o plano mudou, atualize o JSON e valide novamente.

### 4. Verify

Antes de finalizar:

- Rode teste focado para os arquivos tocados.
- Rode `git.diff` e confirme que o diff corresponde ao plano.
- Rode `atlas quality --run-tests --yes` quando estiver em modo `--complete`.
- Gere `completion_packet` curto com arquivos, comandos, resultado e risco residual.

## Gotchas

- `file.write` em path fora de `allowed_roots` falha imediatamente; capture isso no plano.
- Testes que escrevem em `/tmp`, cache ou snapshots precisam declarar `side_effects`.
- Workspace sujo nao e erro automatico, mas precisa separar mudancas preexistentes de mudancas do Atlas.
- `workspace_changes = needs_review` nao e suficiente para `--complete`; nesse modo o trabalho so retorna sucesso com `completion_packet.status = passed`.
- Se testes nao puderem rodar, nao declare final. Entregue `needs_review` com motivo operacional.

## Verification

- `git diff --stat` mostra exatamente arquivos planejados ou diferenca explicitada.
- Testes relevantes passam.
- `completion_packet.quality_gates[].status` nao contem `failed`.
- Em `atlas dev --complete`, status final precisa ser `passed`.
