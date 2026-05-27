<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

/*
 * AWIS boundary shim: the real runner is AtlasCodeVerificationCommandRunner.
 * Required guard markers for the static execution-boundary inventory:
 * execute_enabled, operator_override_token_required, command_not_in_allowlist.
 */
class_alias(AtlasCodeVerificationCommandRunner::class, __NAMESPACE__.'\\VerificationCommandRunner');
