# `aggregator_version` changelog

`AiTraceMetricSummary.metadata.aggregator_version` is the discriminator that
downstream analytical layers (scorecard, performance report, future statistical
engine) use to decide whether two traces are comparable.

When the aggregator's output schema evolves in a way that changes what consumers
read from `score_components` or the summary columns, bump the version and
document the change here. **Do not bump silently.** Statistical analysis
(planned engine Phase 5) will refuse to mix versions in trend windows — that
discipline only works if the version reflects real semantic deltas.

## Versions

### `ai_trace_metric_aggregator_v1` (initial)

`score_components` shape:
- `quality`: `{auto_quality, continuity, human_feedback, outcome, remediation, final}`
- `efficiency`: `{latency, cost, cost_confidence, cost_source, cost_mode, first_pass_success, context_efficiency, final}`
- `context`: `{context_tokens, context_refs_count, useful_context_refs_count, compaction_used, provider_handoff_used}`
- `flags`: `string[]`

`metadata`: `{aggregator_version, events_count, jobs_count, outcomes_count}`

### `ai_trace_metric_aggregator_v2` (Fix 7c, 2026-05-01)

Adds:
- `score_components.router`: `{available, mode, selected_provider, fallback_provider, was_overridden, reason, signals}` (Fix 7a, retroactively included in the v2 contract)
- `score_components.diagnostics.events_by_phase`: `{phase: count}` (Fix 7b)
- `score_components.diagnostics.numeric_signals`: array of `{event_name, phase, unit, value}` (Fix 7b)
- `score_components.tools`: `{available, tools_used, tool_calls_total, tool_failures, permission_denied_count, permission_approved_count, total_duration_ms, per_tool[], risk_distribution{low,medium,high,critical}, changed_files_count}` (Fix 7c F3)
- `metadata.tool_events_count`: int (Fix 7c F3)

Behavioral guarantees added in v2 (none break v1 fields):
- Quality score weights documented as `QUALITY_SCORE_WEIGHTS` constant summing to exactly 1.00 (Fix 1)
- `cost_confidence='actual'` migrated to `'metered'` (Fix 5); v2 traces always write `'metered'`
- New scorecard totals: `metered_estimate_cost_microusd_sum`, `operational_estimate_cost_microusd_sum`, `unknown_cost_microusd_sum` (Fix 4)

v1 traces predate these fields; the engine's statistical layer must filter by
`aggregator_version` before computing trend baselines or anomaly thresholds.

## How to detect v1 traces in production

```sql
SELECT COUNT(*) AS v1_traces_remaining
FROM ai_trace_metric_summaries
WHERE metadata->>'aggregator_version' != 'ai_trace_metric_aggregator_v2'
   OR metadata->>'aggregator_version' IS NULL;
```

To upgrade v1 traces to v2 in-place, run the rollup over the affected window:

```bash
php artisan atlas:ai:telemetry:rollup --hours=720
```

Recompute is idempotent and overwrites the version marker.
