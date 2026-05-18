<?php

namespace Tests\Concerns;

trait CreatesProgrammingAdapterTables
{
    use CreatesDomainRuntimeTables;
    use CreatesMissionFoundationTables;

    protected function createProgrammingAdapterTables(): void
    {
        $this->createMissionFoundationTables();
        $this->createDomainRuntimeTables();
    }

    protected function dropProgrammingAdapterTables(): void
    {
        $this->dropDomainRuntimeTables();
        $this->dropMissionFoundationTables();
    }
}
