# Atlas CLI Release Checklist

Use este checklist antes de criar tag ou declarar uma versao final do Atlas CLI.

## Pre-flight

- Versao definida no formato `vMAJOR.MINOR.PATCH`.
- Worktree limpa ou mudancas intencionalmente aceitas por `--allow-dirty` em pre-release local.
- `.env` local configurado por `atlas bootstrap`, nao por ajustes soltos.
- `ATLAS_AI_TOOL_ALLOWED_ROOTS` inclui a raiz real de trabalho, normalmente `/Users/vitorepf`.
- Se a versao vai substituir uso direto de terminal pesado, validar `atlas bootstrap --operator-mode --operator-root=/Users/vitorepf --strict` e confirmar `ATLAS_AI_TOOL_PERMISSION_MODE=danger`.
- Claude CLI e Codex CLI resolvidos quando o objetivo for release de uso pesado.
- Crontab do Laravel Scheduler instalado por `atlas bootstrap --install-scheduler-cron --strict` quando a versao inclui P5.

## Gates Obrigatorios

```bash
composer validate --strict
php artisan test
git diff --check
./bin/atlas doctor --strict --refresh-providers --run-tests
./bin/atlas schedule list
./bin/atlas final --strict --refresh-providers
./bin/atlas dogfood run
./bin/atlas dogfood report --strict
./bin/atlas release --version=v2.0.0
```

`dogfood run` e smoke de integracao: ele pode criar thread, trace, jobs e eventos durante a execucao, mas marca esses registros como smoke e limpa os artefatos persistentes antes de retornar. O release final usa `dogfood report --strict`, que exige eventos reais, nao-smoke.

## Dogfood Minimo

Registrar pelo menos 3 dias de uso real com:

- conversa direta;
- desenvolvimento pesado;
- debug/fix;
- handoff entre providers;
- quality gate com testes;
- leitura de TUI/status;
- release check.

Eventos gerados por `atlas dogfood run` nao substituem estes registros. Eles validam que o produto roda; a liberacao final exige uso diario registrado por `atlas dogfood start/record` ou por instrumentacao real equivalente.

## Fair Claude Gate

Para releases cujo objetivo e substituir o Claude Code CLI, rode ou atualize o
Fair Claude Benchmark:

- Atlas deve usar somente `claude_cli`;
- Claude Code deve usar o mesmo modelo Opus;
- Atlas Decide, Codex, Gemini, council e fallback ficam proibidos;
- o resultado precisa registrar comandos, modelo, repo, commit inicial, testes,
  intervencoes humanas e score por caso.

Documento canonico:

- `docs/atlas-cli-fair-claude-benchmark.md`
- `docs/atlas-cli-5x-claude-code-plan.md`

Sem essa evidencia, o release pode declarar melhoria do Atlas CLI, mas nao deve
declarar vitoria justa contra Claude Code CLI.

## Tag

Criar tag somente depois dos gates:

```bash
./bin/atlas release --version=v2.0.0 --create-tag
git push origin v2.0.0
```

## Bloqueios

Nao criar release quando:

- `atlas final --strict` falhar;
- dogfood tiver falha bloqueante no periodo;
- dogfood so tiver smoke automatizado e nenhum uso real de 3 dias;
- `atlas release` depender de `--preflight`, `--skip-dogfood` ou `--no-final`;
- CI nao existir ou estiver vermelho;
- worktree estiver suja sem decisao explicita;
- provider direto ainda for necessario para fluxo diario normal.
