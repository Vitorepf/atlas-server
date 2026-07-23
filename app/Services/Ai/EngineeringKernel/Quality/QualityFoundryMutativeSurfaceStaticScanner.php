<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Quality;

/**
 * Source-level guard for irreversible or externally visible mutations.
 *
 * Mutation is intentionally narrow here: the scanner catches the dangerous
 * primitives, while the governed actuator paths remain the explicit escape
 * hatch for code that has already crossed the kernel proof boundary.
 */
final class QualityFoundryMutativeSurfaceStaticScanner
{
    public const SCHEMA = 'atlas.quality_foundry.mutative_surface_static_scan.v1';

    /** @var list<string> */
    private const ALLOWLIST_MARKERS = [
        '/EngineeringKernel/',
        '/SelfConstruction/Governance/',
        '/RealExecution/',
        '/SelfConstruction/AtlasTaskScopedCommitter.php',
    ];

    /** @var list<array{pattern:string,reason:string}> */
    private const MUTATION_RULES = [
        ['pattern' => '/\b(?:exec|shell_exec|passthru|system)\s*\(/i', 'reason' => 'process_execution'],
        ['pattern' => '/\bProcess::\s*run\s*\(/i', 'reason' => 'process_execution'],
        ['pattern' => '/\b(?:File|Storage)::\s*(?:put|append|delete|move|copy)\s*\(/i', 'reason' => 'filesystem_mutation'],
        ['pattern' => '/\b(?:unlink|rename|copy|mkdir|rmdir)\s*\(/i', 'reason' => 'filesystem_mutation'],
        ['pattern' => '/\bgit\s+(?:commit|revert|push|merge)\b/i', 'reason' => 'git_mutation'],
        ['pattern' => '/->\s*(?:commit|push|release|deploy)\s*\(/i', 'reason' => 'release_or_deploy_mutation'],
    ];

    /** @param array<string,string> $files @return array<string,mixed> */
    public function scan(array $files): array
    {
        $violations = [];

        foreach ($files as $path => $source) {
            if ($this->isAllowlisted($path)) {
                continue;
            }

            foreach ($this->codeLines($source) as $lineNumber => $code) {
                foreach (self::MUTATION_RULES as $rule) {
                    if (preg_match($rule['pattern'], $code) === 1) {
                        $violations[] = $this->violation($path, $lineNumber, $rule['reason']);
                    }
                }
            }
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $violations === [] ? 'pass' : 'blocked',
            'violations' => $violations,
            'allowlist' => self::ALLOWLIST_MARKERS,
        ];
    }

    private function isAllowlisted(string $path): bool
    {
        $normalized = '/'.trim(str_replace('\\', '/', $path), '/').'/';

        foreach (self::ALLOWLIST_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    private function codeLines(string $source): array
    {
        $lines = [];
        $currentLine = 1;
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                [$id, $text, $line] = $token;
                $currentLine = $line;
                if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }

                $lines[$line] = ($lines[$line] ?? '').$text;
                continue;
            }

            $lines[$currentLine] = ($lines[$currentLine] ?? '').$token;
        }

        return $lines;
    }

    /** @return array{file:string,line:int,reason:string} */
    private function violation(string $path, int $line, string $reason): array
    {
        return ['file' => $path, 'line' => $line, 'reason' => $reason];
    }
}
