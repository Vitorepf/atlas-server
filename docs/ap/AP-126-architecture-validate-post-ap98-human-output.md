# AP-126 — Architecture Validate Post-AP98 Human Output

## Problem

`atlas:ai:architecture-validate --json` exposes every static architecture AP, but
the human output path used explicit loops that stopped at older APs. Newer
violations could be visible in JSON while hidden from the terminal operator.

## Contract

- Human output must render static scan violations for every AP after AP-98.
- The renderer must be generic over `kernel.static_scan` instead of requiring a
  new output loop for every future AP.
- Rendered labels must preserve the full AP key:
  `[kernel.static.<ap_key>]`.
- JSON output remains unchanged.

## Implementation

- `AtlasAiArchitectureValidateCommand::renderPostAp98StaticScanViolations`
- `AtlasAiArchitectureValidateCommandTest::test_human_output_renders_post_ap98_static_scan_violations`
- Static scan key `ap126_architecture_validate_post_ap98_human_output`

## Value

The operator-facing validator now has the same practical enforcement power as
the JSON payload consumed by CI, Observability, MCP, and Curator. Future APs can
fail loudly in the terminal without adding another bespoke output branch.
