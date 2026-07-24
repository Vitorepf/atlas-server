<?php

use App\Providers\AppServiceProvider;
use App\Providers\AtlasDevServiceProvider;
use App\Providers\AtlasAcosWatchdogServiceProvider;
use App\Providers\AtlasLegacyNamespaceAliasServiceProvider;
use App\Providers\AtlasCompressionServiceProvider;
use App\Providers\AtlasCrossDomainGraphServiceProvider;
use App\Providers\AtlasMissionServiceProvider;
use App\Providers\AtlasProviderManagerWiringServiceProvider;
use App\Providers\AtlasOrganismServiceProvider;
use App\Providers\AtlasPatamar4ServiceProvider;
use App\Providers\AtlasSwarmServiceProvider;
use App\Providers\AtlasVoxServiceProvider;
use App\Providers\ProgrammingGovernanceServiceProvider;

return [
    AppServiceProvider::class,
    AtlasLegacyNamespaceAliasServiceProvider::class,
    AtlasAcosWatchdogServiceProvider::class,
    AtlasOrganismServiceProvider::class,
    AtlasVoxServiceProvider::class,
    AtlasSwarmServiceProvider::class,
    AtlasPatamar4ServiceProvider::class,
    AtlasMissionServiceProvider::class,
    AtlasCompressionServiceProvider::class,
    AtlasCrossDomainGraphServiceProvider::class,
    AtlasProviderManagerWiringServiceProvider::class,
    AtlasDevServiceProvider::class,
    ProgrammingGovernanceServiceProvider::class,
];
