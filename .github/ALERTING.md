# Alerting Configuration

Atlas Server has built-in alerting hooks that can be activated by environment
variables.

## Channels

| Channel | Env var | Used by |
|---------|---------|---------|
| Slack | `ALERT_SLACK_WEBHOOK_URL` | Self-diagnostic, mobile push alerts |
| PagerDuty | `ALERT_PAGERDUTY_ROUTING_KEY` | Critical health failures |
| Discord | `ALERT_DISCORD_WEBHOOK_URL` | Development / staging notifications |

## Alert triggers

- Health check failures (`/health` endpoint returns non-200)
- Mobile push circuit stuck (>30 min silent)
- Scheduler silent for >6 hours
- Queue worker crash or stale job
- AI provider budget breach (if `ATLAS_AI_BUDGET_ENABLED=true`)

## GitHub Actions monitoring

- Build duration tracked via `actions/upload-artifact` in `atlas-cli.yml`.
- Security alerts (gitleaks, Semgrep, CodeQL) are pushed to the GitHub Security tab.
- Coverage reports are uploaded to Codecov (if `CODECOV_TOKEN` is set).

## Disabling alerts

Set `ALERT_*` variables to empty or unset them. The alert code is fail-open:
if a channel is not configured, the alert is logged but not dispatched.
