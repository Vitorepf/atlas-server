<?php

use App\Providers\AppServiceProvider;
use App\Providers\AtlasDevServiceProvider;
use App\Providers\AtlasAcosWatchdogServiceProvider;
use App\Providers\AtlasLegacyNamespaceAliasServiceProvider;
use App\Providers\ProgrammingGovernanceServiceProvider;

return [
    AppServiceProvider::class,
    AtlasLegacyNamespaceAliasServiceProvider::class,
    AtlasAcosWatchdogServiceProvider::class,
    AtlasDevServiceProvider::class,
    ProgrammingGovernanceServiceProvider::class,
];
