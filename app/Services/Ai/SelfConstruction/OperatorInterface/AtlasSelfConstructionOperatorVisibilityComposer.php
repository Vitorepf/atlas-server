<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\OperatorInterface;

/**
 * Composes Self-Construction dashboard FACTS into concise STATUS BLOCKS for the operator to READ.
 * The operator interface is FOR READING + EMERGENCY-ONLY intervention — never an approval surface.
 * The composer preserves machine-readable fields so downstream tools (operator UI) can still parse it.
 *
 * INPUT FACTS (each section optional, missing ⇒ status='unknown'):
 *   { autonomy_mode, control_plane, strategy_council, task_fabric, worker_swarm,
 *     verification_court, merge_governor, knowledge_sync, learning_transfer,
 *     safety_stop, cortex_snapshot }
 *
 * OUTPUT:
 *   { schema, generated_at, interface_role:'read_only_with_emergency_stop',
 *     autonomy_owner:'atlas_native', blocks:list<{label, status, machine:array}> }
 *
 * INVARIANTS:
 *   - PURE — no I/O. Determinism: identical input ⇒ identical envelope.
 *   - NO scalar score / rank. Status strings are explicit categorical labels only.
 *   - NEVER produces approval prompts or action buttons; just FACT blocks for reading.
 */
final class AtlasSelfConstructionOperatorVisibilityComposer
{
    public const SCHEMA = 'atlas.operator_interface.visibility.v1';

    public const ROLE = 'read_only_with_emergency_stop';

    public const AUTONOMY_OWNER = 'atlas_native';

    public const SECTIONS = [
        'autonomy_mode',
        'control_plane',
        'strategy_council',
        'task_fabric',
        'worker_swarm',
        'verification_court',
        'merge_governor',
        'knowledge_sync',
        'learning_transfer',
        'safety_stop',
        'cortex_snapshot',
    ];

    /**
     * @param  array<string,array<string,mixed>>  $facts
     * @return array{schema:string, generated_at:string, interface_role:string, autonomy_owner:string, blocks:list<array{label:string, status:string, machine:array<string,mixed>}>}
     */
    public function compose(array $facts, ?string $generatedAt = null): array
    {
        $blocks = [];
        foreach (self::SECTIONS as $section) {
            $row = is_array($facts[$section] ?? null) ? $facts[$section] : null;
            $blocks[] = [
                'label' => $section,
                'status' => $row === null ? 'unknown' : $this->statusFor($section, $row),
                'machine' => $row ?? [],
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'generated_at' => $generatedAt ?? '',
            'interface_role' => self::ROLE,
            'autonomy_owner' => self::AUTONOMY_OWNER,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function statusFor(string $section, array $row): string
    {
        return match ($section) {
            'autonomy_mode' => (string) ($row['mode'] ?? 'unknown'),
            'control_plane' => (bool) ($row['ready'] ?? false) ? 'ready' : 'not_ready',
            'strategy_council' => (string) ($row['ambition_level'] ?? 'unknown'),
            'safety_stop' => (string) ($row['action'] ?? 'unknown'),
            'verification_court' => (string) ($row['verdict'] ?? 'unknown'),
            'merge_governor' => (string) ($row['decision'] ?? 'unknown'),
            'knowledge_sync' => (bool) ($row['conformant'] ?? false) ? 'conformant' : 'not_conformant',
            default => isset($row['status']) ? (string) $row['status'] : 'observed',
        };
    }
}
