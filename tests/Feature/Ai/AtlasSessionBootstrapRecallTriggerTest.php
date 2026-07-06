<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Obra #7 W2 wire-observe: the session bootstrap envelope carries an
 * observe-only 'recall_trigger' field built from RecallExceptionDetector +
 * RecallTriggerClassifier over the real atlas:ai:session-bootstrap path.
 */
class AtlasSessionBootstrapRecallTriggerTest extends TestCase
{
    public function test_session_bootstrap_classifies_meaningful_task_as_advisory_recall(): void
    {
        $exit = Artisan::call('atlas:ai:session-bootstrap', [
            '--task' => 'melhorar open brain memory retrieval',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.session_bootstrap.v1', $payload['schema_version']);
        $this->assertSame('recall', data_get($payload, 'recall_trigger.action'));
        $this->assertFalse(data_get($payload, 'recall_trigger.mandatory'));
        $this->assertSame(['meaningful_description'], data_get($payload, 'recall_trigger.reasons'));
        $this->assertFalse(data_get($payload, 'recall_trigger.exception.is_exception'));
        $this->assertNull(data_get($payload, 'recall_trigger.exception.exception'));
    }

    public function test_session_bootstrap_flags_rename_task_as_legitimate_no_recall_exception(): void
    {
        $exit = Artisan::call('atlas:ai:session-bootstrap', [
            '--task' => 'rename variable in function cleanup',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('none', data_get($payload, 'recall_trigger.action'));
        $this->assertFalse(data_get($payload, 'recall_trigger.mandatory'));
        $this->assertSame([], data_get($payload, 'recall_trigger.reasons'));
        $this->assertTrue(data_get($payload, 'recall_trigger.exception.is_exception'));
        $this->assertSame('rename_in_function', data_get($payload, 'recall_trigger.exception.exception'));
        // Observe-only: the new field never touches the existing gate verdict.
        $this->assertContains($payload['gate_status'], ['attention_required', 'blocked', 'passed']);
    }
}
