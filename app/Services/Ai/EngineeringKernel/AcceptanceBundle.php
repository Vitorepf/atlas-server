<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel value: the complete, self-describing evidence packet a delivery presents to
 * the AcceptanceGate for certification.
 *
 * Owns: carrying spec + frozen criteria + diff + REAL execution evidence (test runs, mutation
 * report, regression, security scan) + hashes, as an immutable snapshot the floor evaluates.
 * Must never own: deciding whether that evidence is sufficient (SovereignHonestyFloor's job) or
 * gathering it (each surface's adapter builds the bundle).
 *
 * This is a trust boundary: the floor must be able to detect fake evidence from this shape alone,
 * so the fields are explicit rather than a free-form blob.
 */
final readonly class AcceptanceBundle
{
    /**
     * @param  string  $criteriaHash          hash of the acceptance criteria this bundle claims to satisfy
     * @param  string  $frozenHash            hash of the criteria the certified suite was frozen against
     * @param  list<string>  $changedFiles    files the diff touches
     * @param  array<int,array{symbol:string,has_criterion:bool,has_test:bool}>  $changedPublicSymbols
     * @param  ExecutionEvidence  $execution   REAL test-execution evidence (never a lint-as-suite claim)
     * @param  array{kill_ratio?:float,mutants_generated?:int,decision_surface_added?:bool}  $mutationReport
     * @param  array{secret_free?:bool,critical_sast?:int,critical_cve?:int,ran?:bool}  $securityScan
     * @param  array<int,array{name:string,provider_family:string,approved:bool}>  $judges
     * @param  int  $contextSufficiency        0..100, re-proved inside the gate
     * @param  array<string,string>  $nonFunctional  reserved slots (perf/migration/architecture/property)
     */
    public function __construct(
        public string $criteriaHash,
        public string $frozenHash,
        public array $changedFiles,
        public array $changedPublicSymbols,
        public ExecutionEvidence $execution,
        public array $mutationReport,
        public array $securityScan,
        public array $judges,
        public int $contextSufficiency,
        public array $nonFunctional = [],
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            criteriaHash: (string) ($data['criteria_hash'] ?? ''),
            frozenHash: (string) ($data['frozen_hash'] ?? ''),
            changedFiles: array_values(array_map('strval', (array) ($data['changed_files'] ?? []))),
            changedPublicSymbols: array_values((array) ($data['changed_public_symbols'] ?? [])),
            execution: ExecutionEvidence::fromArray((array) ($data['execution'] ?? [])),
            mutationReport: (array) ($data['mutation_report'] ?? []),
            securityScan: (array) ($data['security_scan'] ?? []),
            judges: array_values((array) ($data['judges'] ?? [])),
            contextSufficiency: (int) ($data['context_sufficiency'] ?? 0),
            nonFunctional: (array) ($data['non_functional'] ?? []),
        );
    }
}
