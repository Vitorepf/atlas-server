# Atlas Quality Gates

Esta referencia descreve os gates emitidos por `App\Services\Ai\Cli\AtlasCliQualityService`.

## git_status

Executa `git.status` via Atlas runtime. O gate passa quando o comando roda sem erro. Ele nao exige workspace limpo; limpeza e interpretada pelo gate `workspace_changes`.

Falha significa que o Atlas nao conseguiu inspecionar o estado Git. Nao declare trabalho concluido sem resolver isso ou declarar claramente por que o workspace nao e um repositório Git.

## git_diff

Executa `git.diff` via Atlas runtime e calcula `diff_hash` quando existe diff. O gate passa quando o diff pode ser lido.

Use este gate para revisar se as alteracoes correspondem ao plano. O hash serve como evidencia compacta para trace e completion packet.

## workspace_changes

Interpreta o output de `git status --short`.

- `passed`: nenhum arquivo pendente, ou existem arquivos pendentes e um teste aprovado cobriu o diff nesta execução.
- `needs_review`: existem arquivos alterados, adicionados ou removidos sem teste aprovado neste gate.

Em uma tarefa de desenvolvimento normal, `needs_review` pode ser aceitavel como evidencia de diff pendente. Em `atlas dev --complete`, nao basta: a conclusao final precisa de `status = passed`, entao o agent deve fechar testes, revisar diff e explicar por que as alteracoes sao intencionais.

## tests

Só aparece quando o quality gate roda com testes (`--run-tests` ou fluxo `--complete`).

- `passed`: comando de teste terminou com exit code 0.
- `failed`: teste falhou, foi bloqueado por permissao ou nao havia comando de teste detectado.
- `needs_review`: ha mudancas pendentes, mas testes nao foram executados neste gate.

Em `--complete`, qualquer `failed` bloqueia sucesso. Se o teste correto nao for detectado automaticamente, passe o comando focado no plano e rode-o por `test.run` ou `atlas quality --run-tests --yes --command="..."`.

## Completion packet

O pacote final deve conter:

- `status`: `passed`, `needs_review` ou `failed`.
- `summary`: resumo operacional curto.
- `files_changed`: lista de arquivos tocados.
- `tests`: comandos, exit code, duracao e erro se houver.
- `quality_gates`: lista completa dos gates.
- `risks`: motivos para nao declarar final.

Regra operacional: se houver `failed`, a tarefa nao esta pronta. Se houver `needs_review`, a tarefa pode estar util para revisao humana, mas nao deve ser vendida como final em modo `--complete`.
