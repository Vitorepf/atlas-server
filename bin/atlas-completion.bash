# Atlas CLI shell completion (bash + zsh).
#
# bash:
#   echo "source $(atlas completion path)" >> ~/.bashrc
#
# zsh (one-time):
#   autoload -Uz compinit && compinit
#   autoload -Uz bashcompinit && bashcompinit
#   source "$(atlas completion path)"
#
# This file is intentionally hand-rolled so it works without artisan introspection.

_atlas_complete() {
  local cur prev cmd words
  COMPREPLY=()
  cur="${COMP_WORDS[COMP_CWORD]}"
  prev="${COMP_WORDS[COMP_CWORD-1]}"
  cmd="${COMP_WORDS[1]}"

  local top_level="ask chat dev fix plan review compare debug research \
    threads sessions status dashboard tui state steer compact handoff switch \
    interrupt stop cancel continue resume start focus work worker daemon \
    checkpoint checkpoints quality finish test runtime tool search trace \
    permissions memory mobile inbox insight insight-watch watch-insights \
    proposal proposal-scan proposals-scan scan-proposals self-diagnostic diagnostic \
    initiatives initiative schedule cron skills profile health providers \
    setup init bootstrap configure version update rollback install \
    provider-health doctor final readiness product dogfood release \
    ai-doctor bootstrap-skills help completion"

  if [ "$COMP_CWORD" -eq 1 ]; then
    COMPREPLY=( $(compgen -W "${top_level}" -- "${cur}") )
    return 0
  fi

  case "$cmd" in
    handoff|switch)
      COMPREPLY=( $(compgen -W "claude codex" -- "${cur}") )
      return 0
      ;;
    schedule|cron)
      COMPREPLY=( $(compgen -W "add list show run-now pause resume remove" -- "${cur}") )
      return 0
      ;;
    skills)
      COMPREPLY=( $(compgen -W "list show doctor" -- "${cur}") )
      return 0
      ;;
    state)
      COMPREPLY=( $(compgen -W "show set objective phase topic note next decision open-loop pending-steer compact handoff" -- "${cur}") )
      return 0
      ;;
    inbox)
      COMPREPLY=( $(compgen -W "list show capture promote dismiss" -- "${cur}") )
      return 0
      ;;
    runtime|tool)
      COMPREPLY=( $(compgen -W "git.status git.diff git.log fs.read fs.list shell.read test.run session.search workspace.profile" -- "${cur}") )
      return 0
      ;;
    completion)
      COMPREPLY=( $(compgen -W "path bash zsh install" -- "${cur}") )
      return 0
      ;;
  esac

  case "$prev" in
    --workspace)
      COMPREPLY=( $(compgen -d -- "${cur}") )
      return 0
      ;;
    --provider)
      COMPREPLY=( $(compgen -W "claude codex conselho claude_codex claude_cli codex_cli" -- "${cur}") )
      return 0
      ;;
    --mode)
      COMPREPLY=( $(compgen -W "direct plan review dev debug research" -- "${cur}") )
      return 0
      ;;
    --permission)
      COMPREPLY=( $(compgen -W "auto read write danger" -- "${cur}") )
      return 0
      ;;
    --skill)
      COMPREPLY=( $(compgen -W "comunicador-claro decision-advisor dev-quality-gate provider-handoff researcher-quick session-compaction code-reviewer" -- "${cur}") )
      return 0
      ;;
  esac

  if [[ "$cur" == --* ]]; then
    local opts="--workspace --provider --mode --permission --skill --json --stream --no-stream --new-thread --thread \
      --allow-write --dangerously-allow-all --allow-unsandboxed --operator \
      --compact --no-intent --no-progress --no-notify --plan-only --complete \
      --max-iterations --resume --auto-test --critical --timeout"
    COMPREPLY=( $(compgen -W "${opts}" -- "${cur}") )
    return 0
  fi
}

complete -F _atlas_complete atlas
