<?php

namespace Tests\Concerns;

trait CreatesFinanceDomainTables
{
    use CreatesDomainRuntimeTables;
    use CreatesMissionFoundationTables;

    protected function createFinanceDomainTables(): void
    {
        $this->createMissionFoundationTables();
        $this->createDomainRuntimeTables();
    }

    protected function dropFinanceDomainTables(): void
    {
        $this->dropDomainRuntimeTables();
        $this->dropMissionFoundationTables();
    }
}
