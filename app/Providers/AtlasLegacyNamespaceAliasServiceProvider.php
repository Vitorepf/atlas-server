<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\AcosMaxNamespaceAlias;
use App\Services\Ai\CognitiveNamespaceAlias;
use Illuminate\Support\ServiceProvider;

/**
 * Boot-time FQCN aliases for emptied AcosMax/Cognitive package trees.
 *
 * Full-pass architecture: peeled from AppServiceProvider::register.
 */
final class AtlasLegacyNamespaceAliasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        AcosMaxNamespaceAlias::register();
        CognitiveNamespaceAlias::register();
    }
}
