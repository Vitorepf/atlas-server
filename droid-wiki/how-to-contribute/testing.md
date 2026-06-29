# Testing

Atlas Server has a large test suite: ~5,039 test files and ~1.0M lines of test code. Tests are the mechanical proof of implementation state. The autonomous loop's frozen judge re-runs frozen acceptance contracts out-of-process, so tests are not just for human confidence. They are the gate the loop must clear to merge.

## PHPUnit 12

The project uses PHPUnit 12 with two suites: Unit and Feature. The configuration lives in `phpunit.xml`.

```bash
php artisan test              # runs both suites via artisan
vendor/bin/phpunit            # runs PHPUnit directly
vendor/bin/paratest           # runs in parallel across CPU cores
```

### SQLite in-memory

Tests run against SQLite `:memory:` for isolation and speed. The `phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. Migrations run fresh for each test process. This means tests do not touch PostgreSQL and do not persist state between runs.

### Suite organization

Feature tests are organized by subsystem, mirroring `app/Services/Ai/`:

```
tests/Feature/Ai/             # AI Gateway, Open Brain, memory, loop, self-construction
tests/Feature/Engineering/    # blueprint, harness, code intelligence, benchmark
tests/Feature/Marketing/      # Conversion OS, Search/Keyword OS
tests/Feature/CodeGraph/      # code graph and symbol index
tests/Unit/                   # unit tests for individual classes and services
```

## Parallel testing with paratest

`paratest` (`brianium/paratest ^7.20`) runs the suite across multiple PHP processes for faster feedback:

```bash
vendor/bin/paratest
vendor/bin/paratest --processes=8
```

## Mutation testing with infection

`infection` (`infection/infection ^0.33.2`) measures test quality by mutating production code and checking whether tests catch the mutation. A mutation that survives means a test gap.

```bash
vendor/bin/infection
```

The Loop's certify phase also uses mutation testing. The mutation adequacy gate (`app/Services/Ai/AutonomousEvolution/AtlasLoopMutationAdequacyGateService.php`, 48KB) verifies that the test suite kills mutations in the changed code. This is part of the anti-Goodhart defense: a change that passes tests but does not kill mutations is not proven.

## Frozen acceptance contracts

Frozen acceptance contracts are tests that the frozen judge re-runs out-of-process during certification. These are the contracts the loop must clear to merge. They are frozen in a manifest (`Frozen/contracts.manifest.json`) and audited for tampering (Guard 1 TAMPER). The loop cannot edit them.

The frozen contract registry (`app/Services/Ai/AutonomousEvolution/Frozen/AtlasLoopFrozenContractRegistry.php`) manages the frozen set. The drift detector checks that frozen contracts have not changed between cycles.

## Mocking

The project uses Mockery (`mockery/mockery ^1.6`) for test doubles. Faker (`fakerphp/faker ^1.23`) generates test data. The test configuration uses array drivers for cache, session, and mail, with sync queue connection, so tests do not touch external services.

## Common test patterns

```bash
# Run a single test file
php artisan test tests/Feature/Ai/AutonomousEvolution/Brain/AtlasBrainContractTest.php

# Run a single test method
php artisan test --filter=testItCertifiesOnlyWithMergedSha

# Run a specific suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Stop on first failure
php artisan test --stop-on-failure
```

## Test count and LOC

The suite has ~5,039 test files and ~1.0M lines of test code, written over roughly two months. The test-to-code ratio is high because the autonomous loop writes frozen acceptance contracts for every change it certifies. These contracts accumulate.

## Related pages

- [Development workflow](development-workflow.md) — the branch, commit, test cycle
- [Tooling](tooling.md) — Pint, Larastan, Scribe, CI workflows
- [Quality gates and certification](../systems/evolution-loop/quality-gates-and-certification.md) — how the frozen judge uses tests
- [Anti-Goodhart and no-proxy](../concepts/anti-goodhart.md) — mutation testing as an anti-Goodhart gate
- [Patterns and conventions](patterns-and-conventions.md) — testing conventions overview
