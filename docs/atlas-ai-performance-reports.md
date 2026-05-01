# Atlas AI Performance Reports

This is the operational reporting layer for Atlas AI performance. It turns telemetry rollups into a daily mobile inbox report and, on the 15th and 30th day of each month, a second multi-window report covering the last 3, 7, 15 and 30 days.

## Runtime

- Command: `php artisan atlas:ai:telemetry:performance-report`
- Default schedule: `07:05` in `ATLAS_AI_PERFORMANCE_REPORT_TIMEZONE`
- Default daily report date: yesterday in the report timezone
- Default multi-window cadence: automatic on local day `15` and `30`
- Delivery: `ai_inbox_items` item of type `insight`, category `atlas_ai_performance`
- Push policy: `immediate` for the first report item of each date/type
- Dedupe keys:
  - `atlas-ai-performance:daily:YYYY-MM-DD`
  - `atlas-ai-performance:multi:YYYY-MM-DD`

Re-running the same report updates the active inbox item by dedupe key instead of creating notification spam.

## Window Semantics

Reports use `ai_traces.created_at` as the audit basis, not `ai_trace_metric_summaries.computed_at`. This matters because summaries can be recomputed later; recomputation must not move yesterday's traces into today's report.

Daily window:

- Start: `report_date 00:00:00` in the report timezone
- End: next local midnight
- Bounds: inclusive start, exclusive end

Multi-window report:

- Ends at next local midnight after `report_date`
- Windows: last 3, 7, 15 and 30 closed local days by default
- Each window is compared to the immediately previous window of the same length

## Metrics Included

Each report includes:

- Quality: final quality, auto quality, continuity, human feedback, outcome and remediation scores
- Efficiency: final efficiency, context efficiency, token volume, context-token averages and useful context reference rate
- Reliability: status counts, first-pass success, remediation, re-ask, provider switch, backgrounding and pending recovery
- Latency: average latency plus p50/p95 for app-visible latency, provider latency, total latency and queue wait
- Cost: estimated USD, actual/estimated/unknown counts, operational estimate counts and missing cost-rate evidence
- Breakdowns: surface, provider, model, agent and task type
- Provider health: latest provider status and daily degraded/offline rollup when provider snapshots exist
- Data quality: coverage for cost, app visibility, provider/model attribution, feedback, outcome and context signals
- Notable traces: slowest traces, highest-cost traces and traces that needed remediation

## Configuration

```dotenv
ATLAS_AI_PERFORMANCE_REPORT_ENABLED=true
ATLAS_AI_PERFORMANCE_REPORT_EMIT=true
ATLAS_AI_PERFORMANCE_REPORT_TIME=07:05
ATLAS_AI_PERFORMANCE_REPORT_TIMEZONE=America/Sao_Paulo
ATLAS_AI_PERFORMANCE_REPORT_WINDOWS=3,7,15,30
```

## Manual Runs

Preview yesterday without writing inbox:

```bash
php artisan atlas:ai:telemetry:performance-report --json
```

Emit the previous day's report:

```bash
php artisan atlas:ai:telemetry:performance-report --emit --recompute
```

Force daily plus multi-window for a specific date:

```bash
php artisan atlas:ai:telemetry:performance-report --date=2026-04-30 --type=both --emit --json
```

## Operational Invariants

- No report is based on `computed_at` for daily attribution.
- Same date/type never creates duplicate active inbox items.
- Low sample size is reported as `watch`, not as a false critical failure.
- CLI provider cost remains explicitly labeled as estimate/operational estimate unless actual cost data exists.
- The app can render the full report from `payload.report` and the context bundle can start a discussion with Atlas.
