<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use RuntimeException;

/**
 * Deterministic library of NATIVE PATCH TEMPLATES for common SAFE task classes. Pure — never reads
 * or writes project files; renders skeletons from string templates + variables.
 *
 * Supported templates (template_id → task_shape):
 *   pure_service            → kind=service, side_effects=none
 *   value_object            → kind=value_object
 *   facts_only_gate         → kind=gate, returns=facts_only
 *   cli_wrapper             → kind=cli_wrapper
 *   unit_test_scaffold      → kind=unit_test
 *
 * INVARIANTS:
 *   - templates() returns templates sorted by template_id.
 *   - describe(id) throws RuntimeException for unknown id.
 *   - supports(task_shape) — matches the shape against the registered support contract.
 *   - renderSkeleton(id, vars) is BYTE-STABLE: identical args ⇒ identical string.
 *   - Skeleton placeholders use {{ var_name }} syntax. Missing variables throw.
 */
final class AtlasSelfConstructionNativePatchTemplateLibrary
{
    public const SCHEMA = 'atlas.native_implementation.patch_template.v1';

    public const PROOF_STRENGTH_PRODUCTION    = 'production';
    public const PROOF_STRENGTH_THIN_DELEGATION = 'thin_delegation';
    public const PROOF_STRENGTH_BEHAVIORAL_TEST = 'behavioral_test';

    /** @var array<string, array{template_id:string, summary:string, proof_strength:string, supports:array<string,mixed>, required_variables:list<string>, skeleton:string}> */
    private array $registry;

    public function __construct()
    {
        $this->registry = [
            'pure_service' => [
                'template_id'      => 'pure_service',
                'summary'          => 'Pure service with deterministic single-method API and no side effects.',
                'proof_strength'   => self::PROOF_STRENGTH_PRODUCTION,
                'supports'         => ['kind' => 'service', 'side_effects' => 'none'],
                'required_variables' => ['namespace', 'class_name', 'method_name'],
                'skeleton'         => "<?php\n\ndeclare(strict_types=1);\n\nnamespace {{ namespace }};\n\nfinal class {{ class_name }}\n{\n    public function {{ method_name }}(array \$facts): array\n    {\n        // pure: no I/O\n        return [];\n    }\n}\n",
            ],
            'value_object' => [
                'template_id'      => 'value_object',
                'summary'          => 'Immutable readonly value object.',
                'proof_strength'   => self::PROOF_STRENGTH_PRODUCTION,
                'supports'         => ['kind' => 'value_object'],
                'required_variables' => ['namespace', 'class_name'],
                'skeleton'         => "<?php\n\ndeclare(strict_types=1);\n\nnamespace {{ namespace }};\n\nfinal readonly class {{ class_name }}\n{\n    public function __construct(public readonly string \$id) {}\n}\n",
            ],
            'facts_only_gate' => [
                'template_id'      => 'facts_only_gate',
                'summary'          => 'Facts-only gate returning {allowed, blockers}.',
                'proof_strength'   => self::PROOF_STRENGTH_PRODUCTION,
                'supports'         => ['kind' => 'gate', 'returns' => 'facts_only'],
                'required_variables' => ['namespace', 'class_name'],
                'skeleton'         => "<?php\n\ndeclare(strict_types=1);\n\nnamespace {{ namespace }};\n\nfinal class {{ class_name }}\n{\n    public function evaluate(array \$facts): array\n    {\n        \$blockers = [];\n        return ['allowed' => \$blockers === [], 'blockers' => \$blockers];\n    }\n}\n",
            ],
            'cli_wrapper' => [
                'template_id'      => 'cli_wrapper',
                'summary'          => 'Thin Artisan CLI wrapper that delegates to a service.',
                'proof_strength'   => self::PROOF_STRENGTH_THIN_DELEGATION,
                'supports'         => ['kind' => 'cli_wrapper'],
                'required_variables' => ['namespace', 'class_name', 'signature', 'description', 'service_fqn', 'service_class_short'],
                'skeleton'         => "<?php\n\ndeclare(strict_types=1);\n\nnamespace {{ namespace }};\n\nuse Illuminate\\Console\\Command;\nuse {{ service_fqn }};\n\nfinal class {{ class_name }} extends Command\n{\n    protected \$signature = '{{ signature }}';\n    protected \$description = '{{ description }}';\n\n    public function handle(): int\n    {\n        \$result = (new {{ service_class_short }})->handle(\$this->options(), \$this->arguments());\n        \$this->line(json_encode(\$result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));\n        return self::SUCCESS;\n    }\n}\n",
            ],
            'unit_test_scaffold' => [
                'template_id'      => 'unit_test_scaffold',
                'summary'          => 'PHPUnit unit test scaffold with one happy-path assertion.',
                'proof_strength'   => self::PROOF_STRENGTH_BEHAVIORAL_TEST,
                'supports'         => ['kind' => 'unit_test'],
                'required_variables' => ['namespace', 'class_name', 'target_fqn', 'target_class_short'],
                'skeleton'         => "<?php\n\ndeclare(strict_types=1);\n\nnamespace {{ namespace }};\n\nuse {{ target_fqn }};\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class {{ class_name }} extends TestCase\n{\n    public function test_happy_path(): void\n    {\n        \$subject = new {{ target_class_short }}();\n        \$this->assertInstanceOf({{ target_class_short }}::class, \$subject);\n    }\n}\n",
            ],
        ];
    }

    /**
     * @return list<array{template_id:string, summary:string, proof_strength:string, supports:array<string,mixed>}>
     */
    public function templates(): array
    {
        $rows = [];
        foreach ($this->registry as $entry) {
            $rows[] = [
                'template_id'   => $entry['template_id'],
                'summary'       => $entry['summary'],
                'proof_strength' => $entry['proof_strength'],
                'supports'      => $entry['supports'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['template_id'], $b['template_id']));

        return $rows;
    }

    /**
     * @return array{template_id:string, summary:string, proof_strength:string, supports:array<string,mixed>, required_variables:list<string>}
     */
    public function describe(string $templateId): array
    {
        if (! isset($this->registry[$templateId])) {
            throw new RuntimeException('unknown_template_id:'.$templateId);
        }
        $e = $this->registry[$templateId];

        return [
            'template_id'    => $e['template_id'],
            'summary'        => $e['summary'],
            'proof_strength' => $e['proof_strength'],
            'supports'       => $e['supports'],
            'required_variables' => $e['required_variables'],
        ];
    }

    /**
     * @param  array<string,mixed>  $taskShape
     * @return array{matches:list<string>, match_proof_strengths:array<string,string>}
     */
    public function supports(array $taskShape): array
    {
        $matches = [];
        $matchProofStrengths = [];
        foreach ($this->registry as $entry) {
            $support = $entry['supports'];
            $all = true;
            foreach ($support as $k => $v) {
                if (! array_key_exists($k, $taskShape) || $taskShape[$k] !== $v) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $matches[] = $entry['template_id'];
                $matchProofStrengths[$entry['template_id']] = $entry['proof_strength'];
            }
        }
        sort($matches, SORT_STRING);

        return ['matches' => $matches, 'match_proof_strengths' => $matchProofStrengths];
    }

    /**
     * @param  array<string,string>  $variables
     */
    public function renderSkeleton(string $templateId, array $variables): string
    {
        if (! isset($this->registry[$templateId])) {
            throw new RuntimeException('unknown_template_id:'.$templateId);
        }
        $entry = $this->registry[$templateId];
        foreach ($entry['required_variables'] as $var) {
            if (! isset($variables[$var])) {
                throw new RuntimeException('missing_variable:'.$var);
            }
        }
        $out = $entry['skeleton'];
        foreach ($variables as $k => $v) {
            $out = str_replace('{{ '.$k.' }}', (string) $v, $out);
        }

        return $out;
    }
}
