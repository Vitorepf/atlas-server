# AAEOS OPERATE — AFTER

## Landed
- `atlas:aaeos:run` daily port
- Live dispatch gateway + Dev/Forge/Autônomos dispatchers
- World snapshot fail-open probes
- DualCore record on dispatch
- Cockpit `aaeos` section
- Cycle `--live` / `--max-seeds`
- Control tests 18 green
- Structural certify still ok composite 9.56 tree pure
- README STABLE / OPERATE

## Use
```bash
php artisan atlas:aaeos:run "…" --mode=dev --live --json
php artisan atlas:aaeos:run --autonomos --live --max-seeds=3
php artisan atlas:cli:cockpit
```

## Residual (honest)
- Full 9.0 OPERATE needs one real overnight seed in operator env
- Task enqueue spine stamp not fully generalized
- bin/atlas `go` alias optional
