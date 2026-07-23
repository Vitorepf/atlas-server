# AAEOS = Org Control Plane — STABLE / OPERATE

**Status:** construction of the thin control plane is **complete**.  
New work should **use** AAEOS daily (`atlas:aaeos:run`), not grow this tree.

## Allowed here
- `Control/` — intent → difficulty → mode → admission → live dispatch gateway
- `Control/Dispatch/` — mode live dispatchers (call existing muscle; never reimplement Brain/Task/Forge)
- `Spine/` — N9 delivery + N11 evidence seam

## Forbidden
- Maturity / docs-as-law / scorers / Generated / Quarantine under this tree
- Provider-burn by default (use `--execute-provider` only when intentional)

## Daily port (operator)
```bash
php artisan atlas:aaeos:run "<intent>"              # default path
php artisan atlas:aaeos:run --autonomos --live --max-seeds=3
php artisan atlas:aaeos:scorecard --json
php artisan atlas:aaeos:certify --json              # structural regression
php artisan atlas:cli:cockpit                       # review / what needs me
```

## Muscle (not reimplemented here)
- Autônomos: `atlas:brain:*` + `atlas:task`
- Dev: `atlas:cli:dev` / senior-loop / ask
- Forge: code forge intake / obra / fast-path

See `docs/engineering-knowledge-base/atlas-aaeos-vocabulary.md` and
`docs/evidence/2026-07-23-aaeos-operate/DAY-IN-THE-LIFE.md`.
