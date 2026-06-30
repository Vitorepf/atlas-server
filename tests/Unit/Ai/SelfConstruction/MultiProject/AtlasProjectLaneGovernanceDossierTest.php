<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneGovernanceDossier;
use Tests\TestCase;

final class AtlasProjectLaneGovernanceDossierTest extends TestCase
{
    private function readyAutonomy(): array
    {
        return ['state' => 'ready', 'ready' => true, 'blockers' => [], 'holds' => []];
    }

    private function holdAutonomy(): array
    {
        return ['state' => 'hold', 'ready' => false, 'blockers' => [], 'holds' => ['context_freshness_stale']];
    }

    private function blockedAutonomy(): array
    {
        return ['state' => 'blocked', 'ready' => false, 'blockers' => ['admission_failed'], 'holds' => []];
    }

    private function happySections(): array
    {
        return [
            'admission' => ['admitted' => true],
            'namespace' => ['project_id' => 'p'],
            'isolation' => ['passed' => true],
            'verification_court' => ['passed' => true],
            'release_governor' => ['passed' => true],
            'receipt_policy' => ['passed' => true],
            'rollback_policy' => ['mode' => 'revert_commit'],
            'knowledge_sync' => ['ready' => true],
        ];
    }

    public function test_ready_dossier_carries_full_mandatory_sections(): void
    {
        $dossier = (new AtlasProjectLaneGovernanceDossier)->export('atlas-server', $this->happySections(), $this->readyAutonomy());

        $this->assertSame(AtlasProjectLaneGovernanceDossier::SCHEMA, $dossier['schema_version']);
        $this->assertSame('atlas-server', $dossier['project_id']);
        $this->assertSame('ready', $dossier['state']);
        $this->assertSame(AtlasProjectLaneGovernanceDossier::MANDATORY_SECTIONS, $dossier['mandatory_sections']);
        foreach (AtlasProjectLaneGovernanceDossier::MANDATORY_SECTIONS as $key) {
            $this->assertArrayHasKey($key, $dossier['sections'], "mandatory section {$key} must be present");
        }
        $this->assertSame('ready', $dossier['proof_summary']['autonomy_state']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $dossier['dossier_id']);
    }

    public function test_hold_state_is_preserved_not_converted_to_ready(): void
    {
        $dossier = (new AtlasProjectLaneGovernanceDossier)->export('p', $this->happySections(), $this->holdAutonomy());

        $this->assertSame('hold', $dossier['state']);
        $this->assertContains('context_freshness_stale', $dossier['holds']);
        $this->assertNotSame('ready', $dossier['proof_summary']['autonomy_state']);
    }

    public function test_blocked_state_is_preserved_not_converted_to_ready(): void
    {
        $dossier = (new AtlasProjectLaneGovernanceDossier)->export('p', $this->happySections(), $this->blockedAutonomy());

        $this->assertSame('blocked', $dossier['state']);
        $this->assertContains('admission_failed', $dossier['blockers']);
    }

    public function test_missing_optional_receipt_details_are_carried_as_empty_section(): void
    {
        $sections = $this->happySections();
        unset($sections['receipt_policy']);

        $dossier = (new AtlasProjectLaneGovernanceDossier)->export('p', $sections, $this->readyAutonomy());

        $this->assertSame([], $dossier['sections']['receipt_policy'], 'absent mandatory section must default to []');
        $this->assertFalse($dossier['proof_summary']['receipt_policy_passed']);
    }

    public function test_dossier_id_is_deterministic_for_identical_facts(): void
    {
        $svc = new AtlasProjectLaneGovernanceDossier;
        $a = $svc->export('atlas-server', $this->happySections(), $this->readyAutonomy());
        $b = $svc->export('atlas-server', $this->happySections(), $this->readyAutonomy());

        $this->assertSame($a['dossier_id'], $b['dossier_id']);
    }

    public function test_dossier_id_differs_when_state_changes(): void
    {
        $svc = new AtlasProjectLaneGovernanceDossier;
        $ready = $svc->export('p', $this->happySections(), $this->readyAutonomy());
        $hold = $svc->export('p', $this->happySections(), $this->holdAutonomy());

        $this->assertNotSame($ready['dossier_id'], $hold['dossier_id']);
    }

    public function test_dossier_carries_no_numeric_score_field(): void
    {
        $dossier = (new AtlasProjectLaneGovernanceDossier)->export('p', $this->happySections(), $this->readyAutonomy());
        foreach (array_keys($dossier) as $key) {
            $this->assertStringNotContainsString('score', strtolower((string) $key));
        }
        foreach (array_keys($dossier['proof_summary']) as $key) {
            $this->assertStringNotContainsString('score', strtolower((string) $key));
        }
    }

    public function test_non_empty_sections_receive_deterministic_section_hash(): void
    {
        $dossier = (new AtlasProjectLaneGovernanceDossier)->export('p', $this->happySections(), $this->readyAutonomy());
        foreach (AtlasProjectLaneGovernanceDossier::MANDATORY_SECTIONS as $key) {
            $section = $dossier['sections'][$key];
            if ($section !== []) {
                $this->assertArrayHasKey('section_hash', $section, "section {$key} must have section_hash");
                $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $section['section_hash']);
            }
        }
    }

    public function test_missing_section_is_surfaced_as_blocker(): void
    {
        $sections = $this->happySections();
        unset($sections['isolation']);
        $dossier = (new AtlasProjectLaneGovernanceDossier)->export('p', $sections, $this->readyAutonomy());

        $this->assertSame([], $dossier['sections']['isolation']);
        $this->assertContains('missing_section:isolation', $dossier['blockers']);
    }

    public function test_dossier_id_stable_across_section_key_order(): void
    {
        $svc = new AtlasProjectLaneGovernanceDossier;
        $a = $svc->export('p', $this->happySections(), $this->readyAutonomy());
        $sections = array_reverse($this->happySections(), true);
        $b = $svc->export('p', $sections, $this->readyAutonomy());

        $this->assertSame($a['dossier_id'], $b['dossier_id']);
    }

    public function test_forbidden_evidence_fields_stripped_from_sections(): void
    {
        $sections = $this->happySections();
        $sections['admission']['raw_prompt'] = 'secret prompt text';
        $sections['namespace']['provider_trace'] = ['private' => 'data'];
        $dossier = (new AtlasProjectLaneGovernanceDossier)->export('p', $sections, $this->readyAutonomy());

        $this->assertArrayNotHasKey('raw_prompt', $dossier['sections']['admission']);
        $this->assertArrayNotHasKey('provider_trace', $dossier['sections']['namespace']);
    }

    public function test_section_with_only_forbidden_fields_treated_as_missing(): void
    {
        $sections = $this->happySections();
        $sections['rollback_policy'] = ['raw_prompt' => 'only forbidden'];
        $dossier = (new AtlasProjectLaneGovernanceDossier)->export('p', $sections, $this->readyAutonomy());

        $this->assertSame([], $dossier['sections']['rollback_policy']);
        $this->assertContains('missing_section:rollback_policy', $dossier['blockers']);
    }
}
