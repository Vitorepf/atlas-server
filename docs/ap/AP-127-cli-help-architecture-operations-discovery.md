# AP-127 — CLI Help Architecture Operations Discovery

## Problem

Architecture operations were available through dedicated commands, APIs, MCP
tools, and Observability, but the primary CLI help map did not expose the full
operator path. A command that exists but is not discoverable becomes tribal
knowledge.

## Contract

`atlas:cli:help` must expose an `arquitetura_mae` section with the core
operational commands:

- `atlas ai architecture-operations --json`
- `atlas ai architecture-validate`
- `atlas ai slo --hours=24 --json`
- `atlas ai kernel-pipeline-report --hours=24 --json`
- `atlas ai repair-report --hours=24 --json`
- `atlas ai provider-performance --hours=24 --json`
- `atlas ai self-improvement-schedule-report --hours=24 --json`
- `atlas ai inbox-action-report --hours=24 --json`

The section must be covered by tests and enforced by the architecture scanner.

## Implementation

- `AtlasCliHelpCommand`
- `AtlasCliHelpCommandTest`
- Static scan key `ap127_cli_help_architecture_operations_discovery`

## Value

The operator can discover the architecture-mother control plane from the normal
Atlas CLI entry point, instead of needing to remember hidden command names. This
keeps implementation, documentation, and day-to-day operations aligned.
