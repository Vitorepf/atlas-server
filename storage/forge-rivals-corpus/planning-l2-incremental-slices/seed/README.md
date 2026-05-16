# Seed · planning-l2-incremental-slices

A feature spec exists in `docs/planning/inbox/feature.md` (provided below).
Your job is to produce `docs/planning/inbox/feature.slices.md` decomposing
the work into **3-5 incremental slices**, each with:

- `id` (e.g. `slice-1`)
- `title`
- `depends_on` (list of slice ids, may be empty)
- `definition_of_done` (list of bullets)

Constraints:
- No slice may depend on itself.
- No cycle across slices.
- Every acceptance criterion listed in `feature.md` must land in at
  least one slice (zero coverage gaps).

A golden checker scaffold is provided in
`tests/Unit/Planning/IncrementalSlicesTest.php`. Arms may extend the
test only to wire the markdown parser they intend to ship.

## Files
- `docs/planning/inbox/feature.md` — input spec the arm must read.
- `tests/Unit/Planning/IncrementalSlicesTest.php` — golden checker scaffold.

## Pass criteria
```
php artisan test --filter='IncrementalSlicesTest'
```
