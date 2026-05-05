<?php

namespace App\Services\Ai\Kernel\Provider;

enum ProviderDriverExecutionStatus: string
{
    case DelegatesToLegacyProvider = 'delegates_to_legacy_provider';
    case DryRun = 'dry_run';
    case NotExecuted = 'not_executed';
}
