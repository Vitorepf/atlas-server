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
    checkpoint checkpoints quality finish test benchmark bench engineering harness tools runtime tool search trace \
    permissions memory memory:list memory:add memory:audit memory:promote memory:govern memory:governance memory:review-queue memory:queue memory:relations memory:privacy memory:verbatim memory:projection memory:recall memory:maintain memory:maintenance memory:seed-core memory:seed open-brain open-brain:mcp brain brain:mcp mcp mobile inbox insight insight-watch watch-insights \
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
    engineering|harness)
      COMPREPLY=( $(compgen -W "run runner replay benchmark bench seed init calibrate calibration cal cleanup docker-cleanup quality-scan quality scan api-contract contract contracts security-scan security sec sbom knowledge kb knowledge-base harnessability visual-smoke visual visual-driver driver playwright visual-runtime visual-baseline baseline --spec= --strict --run-context-type= --run-context-id=" -- "${cur}") )
      return 0
      ;;
    memory)
      COMPREPLY=( $(compgen -W "review review-queue queue list show accept reject promote propose govern governance relation relations privacy verbatim projection project recall maintain maintenance seed seed-core release block redact resolve dismiss scan apply preview diff write inspect status adopt memory verbatim relations --area= --trace-id= --memory-type= --scope-type= --scope-id= --source-id= --target-id= --source-status= --target-status= --privacy= --privacy-class= --allow-external-ai --block-external-ai --redacted-text= --redacted-body= --re-redact --include-verbatim --include-unreviewed --target= --max-lines= --memory-limit= --limit= --budget= --item-chars= --workspace= --include-drift-audit --apply-projection --no-sync --no-index-code --no-prune --force --yes --dry-run --json" -- "${cur}") )
      return 0
      ;;
    open-brain|brain)
      COMPREPLY=( $(compgen -W "context context-pack export mcp serve server --workspace= --task-type= --desired-mode= --agent= --intent= --requester= --payload-json= --include-prompt --once= --describe --json" -- "${cur}") )
      return 0
      ;;
    benchmark|bench)
      COMPREPLY=( $(compgen -W "seed init calibrate calibration cal cleanup docker-cleanup --suite= --workspace= --limit= --case= --tag= --tier= --domain= --risk= --curation-status= --provider= --model= --model-policy= --sandbox= --docker-service= --docker-image= --docker-workdir= --docker-cache= --docker-network= --docker-healthcheck-service= --docker-healthcheck-timeout= --docker-artifact-path= --docker-artifact-max-files= --docker-artifact-max-bytes= --provider-runtime= --provider-docker-compose-file= --provider-docker-service= --provider-docker-app-dir= --provider-docker-workspace-dir= --gate-profile= --from-run= --from-recent-runs= --min-source-score= --expected-decision= --min-score= --cache-retention-days= --artifact-retention-days= --apply --refresh-manifest --no-provider --auto-test --visual-e2e= --harness-policy= --test-command= --json" -- "${cur}") )
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
    tools)
      COMPREPLY=( $(compgen -W "doctor list authority matrix status commands run run-recipe recipe evidence evidence-show evidence-export gate release-gate approve revoke waive-finding revoke-finding-waiver policies --workspace= --command= --recipe= --tool-env= --output-limit= --dry-run --approved --required --max-execution-tier= --sandbox-mode= --privacy-level= --task-type= --requires-provider-safe --scope= --reason= --ttl-hours= --network-allowed --finding-id= --surface= --status= --policy-decision= --context-type= --context-id= --run-id= --required-only --required-tool= --fail-status= --require-evidence --release-profile= --limit= --json" -- "${cur}") )
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
    --model)
      COMPREPLY=( $(compgen -W "sonnet opus opus-4.7 claude-opus-4-7 haiku spark mini codex-premium codex-5.5 gpt-5.5 gemini-pro default" -- "${cur}") )
      return 0
      ;;
    --model-policy)
      COMPREPLY=( $(compgen -W "fixed auto balanced best-quality fastest cheapest off" -- "${cur}") )
      return 0
      ;;
    --provider-projection)
      COMPREPLY=( $(compgen -W "skip status review apply write adopt" -- "${cur}") )
      return 0
      ;;
    --provider-projection-target)
      COMPREPLY=( $(compgen -W "all claude agents" -- "${cur}") )
      return 0
      ;;
    --sandbox)
      COMPREPLY=( $(compgen -W "workspace worktree docker" -- "${cur}") )
      return 0
      ;;
    --sandbox-mode)
      COMPREPLY=( $(compgen -W "workspace worktree docker host none" -- "${cur}") )
      return 0
      ;;
    --privacy-level)
      COMPREPLY=( $(compgen -W "standard sensitive restricted" -- "${cur}") )
      return 0
      ;;
    --max-execution-tier)
      COMPREPLY=( $(compgen -W "T0 T1 T2 T3" -- "${cur}") )
      return 0
      ;;
    --task-type)
      COMPREPLY=( $(compgen -W "manual quality_scan security_scan sbom api_contract refactor release_gate agent_execution visual_smoke code_intelligence" -- "${cur}") )
      return 0
      ;;
    --release-profile)
      COMPREPLY=( $(compgen -W "security_sbom_release" -- "${cur}") )
      return 0
      ;;
    --provider-runtime)
      COMPREPLY=( $(compgen -W "host docker auto" -- "${cur}") )
      return 0
      ;;
    --docker-cache)
      COMPREPLY=( $(compgen -W "auto off" -- "${cur}") )
      return 0
      ;;
    --docker-network)
      COMPREPLY=( $(compgen -W "profile none bridge" -- "${cur}") )
      return 0
      ;;
    --visual-e2e)
      COMPREPLY=( $(compgen -W "auto off required" -- "${cur}") )
      return 0
      ;;
    --quality-scan)
      COMPREPLY=( $(compgen -W "off auto required" -- "${cur}") )
      return 0
      ;;
    --quality-profile)
      COMPREPLY=( $(compgen -W "auto fast standard release deep" -- "${cur}") )
      return 0
      ;;
    knowledge|kb|knowledge-base)
      COMPREPLY=( $(compgen -W "status sync list show context index-code code-status modules symbols show-module --category= --status= --layer= --module= --symbol-type= --language= --docs-status= --q= --limit= --workspace= --dry-run --prune --json" -- "${cur}") )
      return 0
      ;;
    --baseline)
      COMPREPLY=( $(compgen -W "off observe strict" -- "${cur}") )
      return 0
      ;;
    --screenshot-baseline)
      COMPREPLY=( $(compgen -W "auto off observe strict" -- "${cur}") )
      return 0
      ;;
    --screenshot-driver)
      COMPREPLY=( $(compgen -W "auto workspace atlas off" -- "${cur}") )
      return 0
      ;;
    --package)
      COMPREPLY=( $(compgen -W "playwright @playwright/test" -- "${cur}") )
      return 0
      ;;
    --harness-policy)
      COMPREPLY=( $(compgen -W "auto off strict" -- "${cur}") )
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
    --gate-profile)
      COMPREPLY=( $(compgen -W "release smoke strict advisory off" -- "${cur}") )
      return 0
      ;;
    --profile)
      COMPREPLY=( $(compgen -W "auto fast standard release deep" -- "${cur}") )
      return 0
      ;;
    --tier)
      COMPREPLY=( $(compgen -W "smoke release full_regression quarantine" -- "${cur}") )
      return 0
      ;;
    --risk)
      COMPREPLY=( $(compgen -W "low medium high critical" -- "${cur}") )
      return 0
      ;;
    --curation-status)
      COMPREPLY=( $(compgen -W "candidate curated quarantined retired" -- "${cur}") )
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
      --max-iterations --resume --auto-test --critical --timeout \
      --suite --case --tag --limit --tier --domain --risk --curation-status --provider --model --gate-profile --profile --attempt --test-command --visual-e2e --quality-scan --quality-profile --quality-changed-only --harness-policy --screenshot-driver --runtime-dir --package --skip-browser-install --force --changed-only --no-provider --dry-run --sandbox --max-attempts --no-apply-isolated-patch --refresh-manifest --spec --strict --run-context-type --run-context-id \
      --provider-projection --provider-projection-target --provider-projection-max-lines --provider-projection-memory-limit --provider-projection-force --provider-projection-yes"
    COMPREPLY=( $(compgen -W "${opts}" -- "${cur}") )
    return 0
  fi
}

complete -F _atlas_complete atlas
