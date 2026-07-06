<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Schema;

/**
 * setUp/tearDown compartilhado dos testes de Cognitive que precisam de
 * atlas_ledger_events + tabelas companheiras (Obra #12 S-01).
 *
 * A classe consumidora declara:
 *  - const LEDGER_COMPANION_MIGRATIONS: arquivos de migration rodados após a tabela do ledger;
 *  - const LEDGER_COMPANION_TABLES: tabelas dropadas (na ordem) antes de atlas_ledger_events.
 */
trait TestsWithLedgerEvents
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        foreach (static::LEDGER_COMPANION_MIGRATIONS as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    protected function tearDown(): void
    {
        foreach (static::LEDGER_COMPANION_TABLES as $table) {
            Schema::dropIfExists($table);
        }

        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }
}
