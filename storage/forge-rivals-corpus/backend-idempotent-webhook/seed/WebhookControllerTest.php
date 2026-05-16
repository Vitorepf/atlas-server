<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Http\Controllers\WebhookController;
use App\Models\ReceivedEvent;
use PHPUnit\Framework\TestCase;

final class WebhookControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ReceivedEvent::reset();
    }

    public function test_first_event_id_processes(): void
    {
        $controller = new WebhookController;
        $result = $controller->handle(['event_id' => 'evt-1', 'kind' => 'capture.created']);
        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['side_effect_applied']);
        $this->assertSame(['evt-1'], $controller->appliedSideEffects());
    }

    public function test_duplicate_event_id_is_ignored(): void
    {
        $controller = new WebhookController;
        $controller->handle(['event_id' => 'evt-2', 'kind' => 'capture.created']);
        $result = $controller->handle(['event_id' => 'evt-2', 'kind' => 'capture.created']);

        $this->assertSame('ok', $result['status'], 'retry continua devolvendo 200 OK ao provider');
        $this->assertFalse($result['side_effect_applied'], 'segunda execução NÃO pode aplicar side-effect de novo');
        $this->assertSame(['evt-2'], $controller->appliedSideEffects(), 'side-effect total continua igual a 1');
    }

    public function test_missing_event_id_is_rejected(): void
    {
        $controller = new WebhookController;
        $result = $controller->handle(['kind' => 'capture.created']);
        $this->assertSame('invalid_event', $result['status']);
        $this->assertFalse($result['side_effect_applied']);
    }
}
