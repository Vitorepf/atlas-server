# Pre-agreed context for ADR-0001

The Inbox capture pipeline needs a persistence story before we go GA.
The team has already agreed on the following non-negotiables — do NOT
relitigate them inside the ADR:

- Replay-from-cold must be possible in ≤ 30 min for 50k captures.
- Audit log retention is 7 years.
- We will run a single relational region (us-east-1) for at least one
  full year before introducing geo-replication.
- A capture's tag list will not exceed 32 tags; full-text search lives
  in a separate service.

The ADR must pick between:

1. **RDBMS-as-source-of-truth** — captures live in Postgres, audit log
   is a derived table.
2. **Append-only event log** — captures live in an event log; Postgres
   becomes a read projection.

Frame the decision through the lens of the four constraints above.
