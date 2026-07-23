# AAEOS OPERATE — Day in the life

## Manhã (Dev — você presente)

```bash
php artisan atlas:aaeos:run "fix flaky login validation with proof" --json
# mode=dev → session pack + next commands
# trabalhe no harness; depois:
php artisan atlas:cli:cockpit
```

## Obra longa (Forge)

```bash
php artisan atlas:aaeos:run "multi-packet obra auth subsystem SDD" --mode=forge --json
# intake stamped + dualcore route + next forge commands
```

## Noite (Autônomos)

```bash
php artisan atlas:aaeos:run --autonomos --live --max-seeds=3 --json
# worker:
php artisan atlas:task next
php artisan atlas:cli:cockpit
```

## Semanal (regressão estrutural)

```bash
php artisan atlas:aaeos:certify --json
php artisan atlas:aaeos:scorecard --json
```

Memorize: **run · cockpit · task next · certify**. O resto é advanced.
