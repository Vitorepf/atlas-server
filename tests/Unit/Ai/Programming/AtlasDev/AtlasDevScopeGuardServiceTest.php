<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\AtlasDevScopeGuardService;
use PHPUnit\Framework\TestCase;

final class AtlasDevScopeGuardServiceTest extends TestCase
{
    public function test_allows_when_all_writes_within_allowed_set(): void
    {
        $guard = new AtlasDevScopeGuardService();

        $result = $guard->evaluate(
            proposedWrites: ['app/Foo.php', 'app/Sub/Bar.php'],
            allowedFiles: ['app/**'],
            forbiddenFiles: [],
        );

        self::assertSame(AtlasDevScopeGuardService::DECISION_ALLOW, $result['decision']);
        self::assertSame(['app/Foo.php', 'app/Sub/Bar.php'], $result['allowed_writes']);
        self::assertSame([], $result['denied_writes']);
        self::assertStringStartsWith('sha256:', $result['evaluation_hash']);
    }

    public function test_denies_when_any_write_outside_allowed_set(): void
    {
        $guard = new AtlasDevScopeGuardService();

        $result = $guard->evaluate(
            proposedWrites: ['app/Foo.php', 'database/migrations/x.php'],
            allowedFiles: ['app/**'],
            forbiddenFiles: [],
        );

        self::assertSame(AtlasDevScopeGuardService::DECISION_DENY, $result['decision']);
        self::assertSame(['app/Foo.php'], $result['allowed_writes']);
        self::assertSame(['database/migrations/x.php'], $result['denied_writes']);
        self::assertSame(
            AtlasDevScopeGuardService::REASON_NOT_IN_ALLOWED,
            $result['reason_per_denied']['database/migrations/x.php'],
        );
    }

    public function test_denies_when_path_matches_forbidden_set(): void
    {
        $guard = new AtlasDevScopeGuardService();

        $result = $guard->evaluate(
            proposedWrites: ['app/Foo.php', 'app/secrets/SecretKey.php'],
            allowedFiles: ['app/**'],
            forbiddenFiles: ['app/secrets/**'],
        );

        self::assertSame(AtlasDevScopeGuardService::DECISION_DENY, $result['decision']);
        self::assertSame(
            AtlasDevScopeGuardService::REASON_EXPLICITLY_FORBIDDEN,
            $result['reason_per_denied']['app/secrets/SecretKey.php'],
        );
    }

    public function test_denies_all_when_allowed_set_is_empty(): void
    {
        $guard = new AtlasDevScopeGuardService();

        $result = $guard->evaluate(
            proposedWrites: ['app/Foo.php'],
            allowedFiles: [],
            forbiddenFiles: [],
        );

        self::assertSame(AtlasDevScopeGuardService::DECISION_DENY, $result['decision']);
        self::assertSame(
            AtlasDevScopeGuardService::REASON_ALLOWED_SET_EMPTY,
            $result['reason_per_denied']['app/Foo.php'],
        );
    }

    public function test_allows_empty_proposed_writes(): void
    {
        $guard = new AtlasDevScopeGuardService();

        $result = $guard->evaluate(
            proposedWrites: [],
            allowedFiles: [],
            forbiddenFiles: [],
        );

        self::assertSame(AtlasDevScopeGuardService::DECISION_ALLOW, $result['decision']);
        self::assertSame([], $result['allowed_writes']);
        self::assertSame([], $result['denied_writes']);
    }

    public function test_supports_exact_path_match(): void
    {
        $guard = new AtlasDevScopeGuardService();

        $result = $guard->evaluate(
            proposedWrites: ['composer.json'],
            allowedFiles: ['composer.json'],
            forbiddenFiles: [],
        );

        self::assertSame(AtlasDevScopeGuardService::DECISION_ALLOW, $result['decision']);
    }

    public function test_forbidden_takes_precedence_over_allowed(): void
    {
        $guard = new AtlasDevScopeGuardService();

        $result = $guard->evaluate(
            proposedWrites: ['app/secret.env'],
            allowedFiles: ['app/**'],
            forbiddenFiles: ['app/secret.env'],
        );

        self::assertSame(AtlasDevScopeGuardService::DECISION_DENY, $result['decision']);
        self::assertSame(
            AtlasDevScopeGuardService::REASON_EXPLICITLY_FORBIDDEN,
            $result['reason_per_denied']['app/secret.env'],
        );
    }

    public function test_evaluation_hash_is_deterministic(): void
    {
        $guard = new AtlasDevScopeGuardService();

        $a = $guard->evaluate(['app/Foo.php'], ['app/**'], [])['evaluation_hash'];
        $b = $guard->evaluate(['app/Foo.php'], ['app/**'], [])['evaluation_hash'];

        self::assertSame($a, $b);
    }

    public function test_evaluation_hash_changes_when_inputs_change(): void
    {
        $guard = new AtlasDevScopeGuardService();

        $a = $guard->evaluate(['app/Foo.php'], ['app/**'], [])['evaluation_hash'];
        $b = $guard->evaluate(['app/Bar.php'], ['app/**'], [])['evaluation_hash'];

        self::assertNotSame($a, $b);
    }
}
