# Seed · architecture-l3-adr-document

Author `docs/adr/0001-persistence-strategy.md` using the MADR template
to record the decision between RDBMS-as-source-of-truth and an
append-only event log for the Inbox capture pipeline.

The provided context note (`context.md`) lists the constraints already
agreed in earlier reviews. You may NOT extend the constraints; you may
expand on the consequences.

The ADR must include:

- `Status` (Accepted / Proposed / Superseded)
- `Context`
- `Decision`
- `Consequences`
- two `Alternatives Considered`, each with pro/con bullets
- one explicit `Tradeoff` line stating what is lost in exchange for the gain

`tests/Unit/Docs/AdrLintTest.php` lints any markdown under `docs/adr/`
to enforce the template.

## Files
- `context.md` — pre-agreed constraints.
- `tests/Unit/Docs/AdrLintTest.php` — lint scaffold.

## Pass criteria
```
php artisan test --filter='AdrLintTest'
```
