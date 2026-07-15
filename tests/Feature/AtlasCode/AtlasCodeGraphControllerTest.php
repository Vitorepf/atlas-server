<?php

declare(strict_types=1);

namespace Tests\Feature\AtlasCode;

use App\Services\AtlasCode\AtlasCodeGraphService;
use Tests\TestCase;

final class AtlasCodeGraphControllerTest extends TestCase
{
    public function test_returns_real_topology_for_a_registered_repository(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ])->getJson('/api/code/graph?repo=atlas-server&limit=3');

        $response->assertOk()
            ->assertJsonPath('schema_version', AtlasCodeGraphService::SCHEMA_VERSION)
            ->assertJsonPath('repo', 'atlas-server')
            ->assertJsonPath('pagination.limit', 3)
            ->assertJsonStructure([
                'schema_version', 'repo', 'generated_at', 'head', 'default_branch',
                'nodes' => [['hash', 'parents', 'author_name', 'author_email', 'authored_at', 'refs']],
                'worktrees' => [['path', 'branch', 'head']],
                'pagination' => ['limit', 'before', 'has_more'],
                'cache' => ['strategy', 'refs_fingerprint', 'invalidated'],
            ]);

        self::assertLessThanOrEqual(3, count((array) $response->json('nodes')));
        self::assertTrue((bool) $response->json('pagination.has_more'));
        self::assertNotSame('', (string) $response->json('head'));
    }

    public function test_rejects_unknown_repository_without_touching_the_worktree(): void
    {
        $this->withHeaders([
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ])->getJson('/api/code/graph?repo=not-registered')
            ->assertNotFound()
            ->assertJsonPath('error', 'repository_profile_not_found');
    }
}
