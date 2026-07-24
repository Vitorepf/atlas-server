<?php

use App\Providers\AppServiceProvider;
use App\Providers\AtlasDevServiceProvider;
use App\Providers\AtlasAcosWatchdogServiceProvider;
use App\Providers\AtlasLegacyNamespaceAliasServiceProvider;
use App\Providers\AtlasOrganismServiceProvider;
use App\Providers\AtlasVoxServiceProvider;
use App\Providers\ProgrammingGovernanceServiceProvider;

return [
    AppServiceProvider::class,
    AtlasLegacyNamespaceAliasServiceProvider::class,
    AtlasAcosWatchdogServiceProvider::class,
    AtlasOrganismServiceProvider::class,
    AtlasVoxServiceProvider::class,
    AtlasDevServiceProvider::class,
    ProgrammingGovernanceServiceProvider::class,
];
