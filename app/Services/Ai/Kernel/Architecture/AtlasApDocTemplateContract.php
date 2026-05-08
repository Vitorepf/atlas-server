<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApDocTemplateContract
{
    private const SCHEMA_VERSION = 'atlas.ap_doc_template_contract.v1';

    public function __construct(
        private readonly AtlasApCreationDecisionContract $creationDecision,
    ) {}

    /**
     * @param  array<int,string>  $relatedPaths
     * @return array<string,mixed>
     */
    public function render(
        string $title,
        string $slug,
        string $owner,
        ?int $requestedNumber = null,
        ?string $docsApPath = null,
        string $status = 'foundation-contract-planned',
        int $lineLimit = 120,
        array $relatedPaths = [],
    ): array {
        $decision = $this->creationDecision->decide($docsApPath, $requestedNumber, $slug);
        $inputViolations = $this->inputViolations($title, $owner, $status, $lineLimit, $relatedPaths);
        $allowed = $decision['allowed'] === true && $inputViolations === [];
        $number = (int) $decision['selected_number'];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $allowed ? 'rendered' : 'blocked',
            'mode' => 'read_only_template_contract',
            'authority' => 'ap_template_render_only_no_file_writes',
            'allowed' => $allowed,
            'doc_path' => $allowed ? $decision['recommended_doc_path'] : null,
            'selected_number' => $number,
            'slug' => $slug,
            'title' => trim($title),
            'template' => $allowed ? $this->markdown($number, trim($title), $slug, trim($owner), trim($status), $lineLimit, $relatedPaths) : null,
            'violations' => array_merge($decision['violations'], $inputViolations),
            'creation_decision' => [
                'schema_version' => $decision['schema_version'],
                'status' => $decision['status'],
                'next_suggested_number' => $decision['next_suggested_number'],
                'recommended_doc_path' => $decision['recommended_doc_path'],
            ],
            'next_action' => $allowed
                ? 'write_template_to_recommended_doc_path_then_run_ap_governance_tests'
                : 'repair_template_inputs_or_ap_governance_before_writing_doc',
            'guardrails' => [
                'writes_files' => false,
                'creates_ap_doc' => false,
                'renumbers_files' => false,
                'bypasses_creation_decision' => false,
                'requires_post_write_governance_validation' => true,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $relatedPaths
     * @return array<int,array<string,mixed>>
     */
    private function inputViolations(string $title, string $owner, string $status, int $lineLimit, array $relatedPaths): array
    {
        $violations = [];

        foreach ([
            'title' => $title,
            'owner' => $owner,
            'status' => $status,
        ] as $field => $value) {
            if (trim($value) === '') {
                $violations[] = [
                    'reason' => 'ap_template_required_field_missing',
                    'field' => $field,
                ];
            }
        }

        if ($lineLimit < 1) {
            $violations[] = [
                'reason' => 'ap_template_line_limit_must_be_positive_integer',
                'line_limit' => $lineLimit,
            ];
        }

        foreach ($relatedPaths as $path) {
            if (! is_string($path) || trim($path) === '') {
                $violations[] = [
                    'reason' => 'ap_template_related_path_must_be_non_empty_string',
                ];
            }
        }

        return $violations;
    }

    /**
     * @param  array<int,string>  $relatedPaths
     */
    private function markdown(int $number, string $title, string $slug, string $owner, string $status, int $lineLimit, array $relatedPaths): string
    {
        $frontmatter = [
            '---',
            'title: '.$this->yamlScalar($title),
            'status: '.$this->yamlScalar($status),
            'owner: '.$this->yamlScalar($owner),
            'line_limit: '.$lineLimit,
        ];

        if ($relatedPaths !== []) {
            $frontmatter[] = 'related_paths:';
            foreach (array_values(array_unique(array_map('trim', $relatedPaths))) as $path) {
                $frontmatter[] = '  - '.$this->yamlScalar($path);
            }
        }

        $frontmatter[] = '---';

        return implode("\n", [
            ...$frontmatter,
            '',
            '# AP-'.sprintf('%03d', $number).' - '.$title,
            '',
            '## 1. Proposito',
            '',
            'Descrever a responsabilidade canonica deste AP em uma frase operacional.',
            '',
            '## 2. Escopo Implementado',
            '',
            '- listar arquivos, contratos ou comportamento implementado',
            '- manter este AP dentro do limite declarado de linhas',
            '',
            '## 3. Autoridade',
            '',
            'Schema: `atlas.'.$slug.'.v1`',
            'Modo: `read_only_or_explicit_runtime_mode`',
            'Autoridade: `declare_clear_authority_before_integration`',
            '',
            '## 4. Regras',
            '',
            '- nao duplicar fluxo existente',
            '- nao criar bypass de Kernel, Decide, Receipt ou Evidence',
            '- declarar limites antes de integrar em surfaces',
            '',
            '## 5. Nao Escopo',
            '',
            '- nao criar API sem contrato',
            '- nao alterar scanner ou scheduler sem AP dedicado',
            '- nao deixar comportamento implicito sem teste',
            '',
            '## 6. Definition of Done',
            '',
            '- testes focados passam',
            '- `php artisan atlas:ai:architecture-validate --json` retorna `ok`',
            '- `git diff --check` passa',
            '',
        ]);
    }

    private function yamlScalar(string $value): string
    {
        $value = trim($value);

        return preg_match('/^[a-zA-Z0-9_\/.,:<>= -]+$/', $value) === 1
            ? $value
            : "'".str_replace("'", "''", $value)."'";
    }
}
