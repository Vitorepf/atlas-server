<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeViolationService;
use PHPUnit\Framework\TestCase;

final class AtlasCodeViolationServiceTest extends TestCase
{
    public function test_scanner_is_pure_and_names_all_five_canonical_rules(): void
    {
        $result = (new AtlasCodeViolationService())->scan([
            'main_branch' => 'main',
            'current_branch' => 'feature/cobaia',
            'current_since' => '2026-07-01T00:00:00Z',
            'allowed_worktree_roots' => ['/repo'],
            'worktrees' => [['path' => '/tmp/foreign', 'head' => 'a']],
            'obra_return_deadline_days' => 3,
            'now' => '2026-07-15T00:00:00Z',
            'branches' => [
                ['name' => 'feature/cobaia', 'committed_at' => '2026-07-01T00:00:00Z', 'reachable_from_main' => true],
                ['name' => 'orphan', 'committed_at' => '2026-07-14T00:00:00Z', 'reachable_from_main' => false],
            ],
            'main_head' => 'main-hash',
            'mirror_head' => 'old-hash',
        ]);

        $rules = array_values(array_unique(array_map(static fn (array $item): string => $item['rule_id'], $result['violations'])));
        sort($rules);
        self::assertSame([
            'main_only', 'mirror_drift', 'obra_return_deadline', 'orphan_branch', 'worktree_allowlist',
        ], $rules);
        self::assertCount(5, $result['plan']);
    }

    public function test_every_rule_cites_the_canon_that_justifies_it(): void
    {
        // O contrato C24 promete `rule_canon_ref` desde o começo e a
        // implementação nunca o emitiu: a lei acusava sem citar a lei. Numa
        // ferramenta de governança, "está errado porque sim" é o pior silêncio.
        $result = (new AtlasCodeViolationService())->scan([
            'main_branch' => 'main',
            'current_branch' => 'feature/cobaia',
            'current_since' => '2026-07-01T00:00:00Z',
            'allowed_worktree_roots' => ['/repo'],
            'worktrees' => [['path' => '/tmp/foreign', 'head' => 'a']],
            'obra_return_deadline_days' => 3,
            'now' => '2026-07-15T00:00:00Z',
            'branches' => [
                ['name' => 'feature/cobaia', 'committed_at' => '2026-07-01T00:00:00Z', 'reachable_from_main' => true],
                ['name' => 'orphan', 'committed_at' => '2026-07-14T00:00:00Z', 'reachable_from_main' => false],
            ],
            'main_head' => 'main-hash',
            'mirror_head' => 'old-hash',
        ]);

        $base = dirname(__DIR__, 3).'/docs/engineering-knowledge-base/';
        foreach ($result['violations'] as $violation) {
            $ref = $violation['rule_canon_ref'] ?? null;
            self::assertIsString($ref, "a regra {$violation['rule_id']} acusa sem citar a lei");
            // Citação para arquivo inexistente é pior que ausência: parece
            // prova e não abre.
            self::assertFileExists($base.$ref, "a regra {$violation['rule_id']} cita um doc que não existe: {$ref}");
        }
    }

    public function test_healthy_facts_are_silent_and_do_not_invent_missing_mirror_data(): void
    {
        self::assertSame(['violations' => [], 'plan' => []], (new AtlasCodeViolationService())->scan([
            'main_branch' => 'main',
            'current_branch' => 'main',
            'allowed_worktree_roots' => ['/repo'],
            'worktrees' => [['path' => '/repo', 'head' => 'main-hash']],
            'branches' => [['name' => 'main', 'reachable_from_main' => true]],
        ]));
    }
}
