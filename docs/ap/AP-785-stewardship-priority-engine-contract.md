# AP-785 · Stewardship Priority Engine Contract

- **Owner:** Atlas Software Company Stewardship Stack / Area Focus Loop
- **Runtime:** `StewardshipPriorityEngineService`
- **CLI:** `php artisan atlas:software-company-stewardship:priority-engine --json`
- **Status:** active

## Intent

AP-785 is the canonical priority engine for stewardship work. It ranks findings,
specs, work orders, branches and queue items by the operator rule: implement in
the order that creates the largest real advancement and the largest possible
robustness, not in random or cosmetic order.

## Scoring

Each item receives:

- `advancement_score`: how much real autonomy or operational capability it unlocks.
- `robustness_score`: how much it reduces failure, conflict or regression risk.
- `operator_leverage_score`: how much manual operator work it removes.
- `execution_safety_score`: how safely it can be executed now.
- `evidence_score`: how auditable the work is.
- `dependency_unlock_score`: how many next steps it unlocks.
- `risk_penalty`: main dirty, conflict, provider cost, sensitive data and sandbox risk.
- `final_priority_score`.
- `lane`: `now`, `next`, `later` or `blocked`.
- `reason` and `reason_machine`.

## Canonical Expected Order

For the current stewardship phase, AP-785 seeds this priority order when no input
file is supplied:

1. AP-783 integration lane promotion.
2. Live cycle audit / truth surface.
3. Owner runtime real execution bridge.
4. 24h scheduler with budgets, locks and kill switch.
5. Product Mode controls and receipts.
6. Provider routing Opus/Sonnet/Gemini/Codex only after owner runtime boundaries are real.

## Boundaries

AP-785 is read-only. It never edits `main`, creates branches/worktrees, invokes a
provider, dispatches Dev/Forge, merges, deploys, pushes or touches secrets.

AP-785 may block an item when it requires dirty main, provider execution without
sandbox, sensitive data without gates, or missing owner runtime boundaries.

## CLI

Default canonical seed:

```bash
php artisan atlas:software-company-stewardship:priority-engine \
  --area=agentic_engineering_os --focus=dev_forge --json
```

External input:

```bash
php artisan atlas:software-company-stewardship:priority-engine \
  --input-file=/path/to/candidates.json --json
```

The input file may be either a JSON array of candidates or an object containing
`candidates`, `findings`, `specs`, `work_orders`, `branches`, `queue_items`, or a
`deep_scan_report.findings` array.

## Acceptance

- AP-783 ranks above cosmetic UI work.
- Dirty-main or provider-without-sandbox items are penalized and blocked.
- Safety/robustness unlocks are promoted.
- Sensitive items without gates are blocked.
- Output is deterministic.
- The dedicated CLI emits machine-readable JSON.
