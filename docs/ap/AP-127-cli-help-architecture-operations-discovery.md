# AP-127 — CLI Help Architecture Operations Discovery

## Problem

Architecture operations were available through dedicated commands, APIs, MCP
tools, and Observability, but the primary CLI help map did not expose the full
operator path. A command that exists but is not discoverable becomes tribal
knowledge.

## Contract

`atlas:cli:help` must expose an `arquitetura_mae` section with the core
operational commands:

- `php artisan atlas:ai:architecture-operations --json`
- `php artisan atlas:ai:architecture-validate`
- `php artisan atlas:ai:slo --hours=24 --json`
- `php artisan atlas:ai:kernel-pipeline-report --hours=24 --json`
- `php artisan atlas:ai:repair-report --hours=24 --json`
- `php artisan atlas:ai:provider-performance --hours=24 --json`
- `php artisan atlas:ai:agent-behavior-report --hours=24 --json`
- `php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json`
- `php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json`
- `php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json`
- `php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json`
- `php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json`
- `php artisan atlas:ai:self-improvement-schedule-report --hours=24 --json`
- `php artisan atlas:ai:inbox-action-report --hours=24 --json`

The section must be covered by tests and enforced by the architecture scanner.

## Implementation

- `AtlasCliHelpCommand`
- `AtlasCliHelpCommandTest`
- Static scan key `ap127_cli_help_architecture_operations_discovery`

## Value

The operator can discover the architecture-mother control plane from the normal
Atlas CLI entry point, instead of needing to remember hidden command names. This
keeps implementation, documentation, and day-to-day operations aligned.
