<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\MultiProvider;

use Illuminate\Support\Str;

final class AtlasMaestroPacketClassifier
{
    public const ARCHITECTURE = 'architecture';
    public const MULTI_FILE = 'multi-file';
    public const GRIND = 'grind';
    public const DOC = 'doc';
    public const QUEUE_REPAIR = 'queue-repair';
    public const LEARNING_LOOP = 'learning-loop';
    public const TASK_FABRIC = 'task-fabric';
    public const MODEL_AMPLIFIER = 'model-amplifier';
    public const REFACTOR_OS = 'refactor-os';

    public const REASON_DOC = 'doc-paths-only';
    public const REASON_MULTI_FILE = '3plus-paths-2plus-subtrees';
    public const REASON_ARCHITECTURE = 'architecture-keyword-with-small-surface';
    public const REASON_GRIND = 'grind-default';
    public const REASON_QUEUE_REPAIR = 'queue-repair-signal';
    public const REASON_LEARNING_LOOP = 'learning-loop-signal';
    public const REASON_TASK_FABRIC = 'task-fabric-signal';
    public const REASON_MODEL_AMPLIFIER = 'model-amplifier-signal';
    public const REASON_REFACTOR_OS = 'refactor-os-signal';

    public function classify(array $packet): string
    {
        return match ($this->reasonFor($packet)) {
            self::REASON_DOC => self::DOC,
            self::REASON_MULTI_FILE => self::MULTI_FILE,
            self::REASON_TASK_FABRIC => self::TASK_FABRIC,
            self::REASON_QUEUE_REPAIR => self::QUEUE_REPAIR,
            self::REASON_MODEL_AMPLIFIER => self::MODEL_AMPLIFIER,
            self::REASON_LEARNING_LOOP => self::LEARNING_LOOP,
            self::REASON_REFACTOR_OS => self::REFACTOR_OS,
            self::REASON_ARCHITECTURE => self::ARCHITECTURE,
            default => self::GRIND,
        };
    }

    /**
     * Full routing contract: task_family, complexity_tier, risk_tier, required_provider_capabilities, classification_evidence.
     *
     * @return array<string, mixed>
     */
    public function classifyWithContract(array $packet): array
    {
        $taskFamily = $this->classify($packet);
        $allowedFiles = $this->paths($packet['allowed_files'] ?? []);
        $objective = (string) ($packet['objective'] ?? '');
        $acceptanceCriteria = (array) ($packet['acceptance_criteria'] ?? []);

        $complexityTier = $this->computeComplexityTier($taskFamily, $allowedFiles, $acceptanceCriteria);
        $riskTier = $this->computeRiskTier($packet, $allowedFiles, $acceptanceCriteria);
        $requiredProviderCapabilities = $this->computeRequiredProviderCapabilities($taskFamily, $complexityTier);
        $classificationEvidence = $this->computeClassificationEvidence($packet, $taskFamily, $complexityTier, $riskTier);

        return [
            'task_family' => $taskFamily,
            'complexity_tier' => $complexityTier,
            'risk_tier' => $riskTier,
            'required_provider_capabilities' => $requiredProviderCapabilities,
            'classification_evidence' => $classificationEvidence,
        ];
    }

    /**
     * Compute complexity tier from task family and scope.
     */
    private function computeComplexityTier(string $taskFamily, array $allowedFiles, array $acceptanceCriteria): string
    {
        $codePaths = $this->codePaths($allowedFiles);
        $codeCount = count($codePaths);

        // Architecture and multi-file are inherently high complexity
        if ($taskFamily === self::ARCHITECTURE || $taskFamily === self::MULTI_FILE) {
            return 'high';
        }

        // Task fabric and refactor OS are medium-high
        if ($taskFamily === self::TASK_FABRIC || $taskFamily === self::REFACTOR_OS) {
            return 'medium';
        }

        // Many files or acceptance criteria = higher complexity
        if ($codeCount >= 5 || count($acceptanceCriteria) >= 4) {
            return 'high';
        }
        if ($codeCount >= 3 || count($acceptanceCriteria) >= 2) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Compute risk tier from packet signals.
     */
    private function computeRiskTier(array $packet, array $allowedFiles, array $acceptanceCriteria): string
    {
        $objective = (string) ($packet['objective'] ?? '');
        $squashed = $this->squash($objective.' '.implode(' ', $allowedFiles));

        // Forbidden hints → high risk
        if (str_contains($squashed, 'forbidden') || str_contains($squashed, 'petreo')) {
            return 'high';
        }

        // Broad write sets (many code paths across subtrees) → high risk
        $codePaths = $this->codePaths($allowedFiles);
        if (count($codePaths) >= 5 && count($this->subtrees($codePaths)) >= 3) {
            return 'high';
        }

        // Weak evidence (no tests, no acceptance criteria) → medium risk
        $hasTests = str_starts_with($allowedFiles[0] ?? '', 'tests/') || count(array_filter($allowedFiles, fn ($p) => str_starts_with($p, 'tests/'))) > 0;
        if (! $hasTests && count($acceptanceCriteria) === 0) {
            return 'medium';
        }

        // Poison signals → medium risk
        if (str_contains($squashed, 'poison') || str_contains($squashed, 'respec')) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Compute required provider capabilities from task family and complexity.
     *
     * @return list<string>
     */
    private function computeRequiredProviderCapabilities(string $taskFamily, string $complexityTier): array
    {
        $capabilities = ['code_edit'];

        if ($complexityTier === 'high') {
            $capabilities[] = 'deep_reasoning';
        }

        if ($taskFamily === self::ARCHITECTURE) {
            $capabilities[] = 'architectural_design';
        }

        if ($taskFamily === self::TASK_FABRIC || $taskFamily === self::QUEUE_REPAIR) {
            $capabilities[] = 'queue_management';
        }

        if ($taskFamily === self::LEARNING_LOOP) {
            $capabilities[] = 'closed_loop_learning';
        }

        return $capabilities;
    }

    /**
     * Compute classification evidence: the signals that drove the classification.
     *
     * @return list<string>
     */
    private function computeClassificationEvidence(array $packet, string $taskFamily, string $complexityTier, string $riskTier): array
    {
        $evidence = [
            "task_family:{$taskFamily}",
            "complexity_tier:{$complexityTier}",
            "risk_tier:{$riskTier}",
        ];

        $allowedFiles = $this->paths($packet['allowed_files'] ?? []);
        $codePaths = $this->codePaths($allowedFiles);
        $evidence[] = "code_paths:".count($codePaths);
        $evidence[] = "subtrees:".count($this->subtrees($codePaths));

        $acceptanceCriteria = (array) ($packet['acceptance_criteria'] ?? []);
        $evidence[] = "acceptance_criteria:".count($acceptanceCriteria);

        return $evidence;
    }

    public function reasonFor(array $packet): string
    {
        $allowedFiles = $this->paths($packet['allowed_files'] ?? []);
        $codePaths = $this->codePaths($allowedFiles);

        if ($allowedFiles !== [] && count($allowedFiles) === count(array_filter($allowedFiles, $this->isMaestroDocPath(...)))) {
            return self::REASON_DOC;
        }

        if (count($codePaths) >= 3 && count($this->subtrees($codePaths)) >= 2) {
            return self::REASON_MULTI_FILE;
        }

        if ($this->hasTaskFabricSignal($packet, $allowedFiles)) {
            return self::REASON_TASK_FABRIC;
        }

        if ($this->hasQueueRepairSignal($packet, $allowedFiles)) {
            return self::REASON_QUEUE_REPAIR;
        }

        if ($this->hasModelAmplifierSignal($packet, $allowedFiles)) {
            return self::REASON_MODEL_AMPLIFIER;
        }

        if ($this->hasLearningLoopSignal($packet, $allowedFiles)) {
            return self::REASON_LEARNING_LOOP;
        }

        if ($this->hasRefactorOsSignal($packet, $allowedFiles)) {
            return self::REASON_REFACTOR_OS;
        }

        if (count($codePaths) <= 2 && $this->containsArchitectureAnchor((string) ($packet['objective'] ?? ''))) {
            return self::REASON_ARCHITECTURE;
        }

        return self::REASON_GRIND;
    }

    /**
     * @return list<string>
     */
    private function paths(mixed $paths): array
    {
        $normalized = [];
        foreach ((array) $paths as $path) {
            $path = ltrim(trim((string) $path), '/');
            if ($path !== '') {
                $normalized[$path] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function codePaths(array $paths): array
    {
        return array_values(array_filter(
            $paths,
            static fn (string $path): bool => str_starts_with($path, 'app/') || str_starts_with($path, 'config/'),
        ));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function subtrees(array $paths): array
    {
        $subtrees = [];
        foreach ($paths as $path) {
            $parts = explode('/', $path);
            $subtree = $parts[0] === 'config'
                ? 'config'
                : implode('/', array_slice($parts, 0, min(2, count($parts))));
            $subtrees[$subtree] = true;
        }

        return array_keys($subtrees);
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function hasTaskFabricSignal(array $packet, array $allowedFiles): bool
    {
        $haystack = $this->squash((string) ($packet['objective'] ?? '').' '.implode(' ', $allowedFiles));

        foreach (['taskfabric', 'taskpacket', 'taskqueue', 'claimlease', 'scopelock'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase and strip word separators (spaces, underscores, hyphens) so "task_packet",
     * "task-packet", "task packet", and the camelCase "TaskPacket" all collapse to "taskpacket"
     * for substring matching against objectives and file paths alike.
     */
    private function squash(string $value): string
    {
        return str_replace([' ', '_', '-'], '', Str::lower($value));
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function hasModelAmplifierSignal(array $packet, array $allowedFiles): bool
    {
        $haystack = $this->squash((string) ($packet['objective'] ?? '').' '.implode(' ', $allowedFiles));

        if (str_contains($haystack, 'modelamplifier')) {
            return true;
        }

        return preg_match('/amplif(y|ies|ying).{0,30}model/', $haystack) === 1;
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function hasQueueRepairSignal(array $packet, array $allowedFiles): bool
    {
        $haystack = (string) ($packet['objective'] ?? '').' '.implode(' ', $allowedFiles);

        return preg_match('/\b(respec|poison|queue[_\-]?repair|queue[_\-]?health|repair[_\-]?blocked)\b/i', $haystack) === 1;
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function hasLearningLoopSignal(array $packet, array $allowedFiles): bool
    {
        $haystack = (string) ($packet['objective'] ?? '').' '.implode(' ', $allowedFiles);

        return preg_match('/\b(ledger|outcome|give[_\-]?back|closed[_\-]?loop)\b/i', $haystack) === 1;
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function hasRefactorOsSignal(array $packet, array $allowedFiles): bool
    {
        $haystack = $this->squash((string) ($packet['objective'] ?? '').' '.implode(' ', $allowedFiles));

        foreach (['refactoros', 'deletionfirst', 'simplification', 'scaffoldretirement'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isMaestroDocPath(string $path): bool
    {
        return preg_match('#^docs/(loop|cortex|maestro)-[^/]+\.md$#', $path) === 1;
    }

    private function containsArchitectureAnchor(string $objective): bool
    {
        return preg_match('/\b(design|architecture|architectural|contract|contracts)\b/i', $objective) === 1;
    }
}
