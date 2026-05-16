# Mutation survivors decision log (template)

Use one section per survivor mutant. Decision must be one of:

- `kill_in_next_pr` — write a stronger test in the next PR
- `accepted` — the mutation is semantically equivalent, no action
- `false_positive` — Infection mis-detected; document why

## Example

### survivor-001
- mutator: `LogicalAnd`
- file: `app/Domain/Captures/CaptureMarkdownParser.php:42`
- decision: kill_in_next_pr
- justification: edge condition not covered by current tests
