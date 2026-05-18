<?php

namespace App\Services\Ai\Cyber;

use App\Models\AiGrcMapping;
use Illuminate\Support\Str;

class GRCMappingService
{
    public const FRAMEWORK_NIST_CSF = 'nist_csf';

    public const FRAMEWORK_NIST_800_53 = 'nist_800_53';

    public const FRAMEWORK_ISO_27001 = 'iso_27001';

    public const FRAMEWORK_SOC2 = 'soc2';

    public const FRAMEWORK_PCI_DSS = 'pci_dss';

    public const FRAMEWORK_HIPAA = 'hipaa';

    public const FRAMEWORK_LGPD = 'lgpd';

    public const FRAMEWORK_GDPR = 'gdpr';

    public const FRAMEWORK_CIS = 'cis_v8';

    public const ALLOWED_FRAMEWORKS = [
        self::FRAMEWORK_NIST_CSF,
        self::FRAMEWORK_NIST_800_53,
        self::FRAMEWORK_ISO_27001,
        self::FRAMEWORK_SOC2,
        self::FRAMEWORK_PCI_DSS,
        self::FRAMEWORK_HIPAA,
        self::FRAMEWORK_LGPD,
        self::FRAMEWORK_GDPR,
        self::FRAMEWORK_CIS,
    ];

    public const STATUS_UNASSESSED = 'unassessed';

    public const STATUS_COMPLIANT = 'compliant';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_NONCOMPLIANT = 'noncompliant';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const ALLOWED_STATUSES = [
        self::STATUS_UNASSESSED,
        self::STATUS_COMPLIANT,
        self::STATUS_PARTIAL,
        self::STATUS_NONCOMPLIANT,
        self::STATUS_NOT_APPLICABLE,
    ];

    /**
     * @param  array<string,mixed>  $args
     */
    public function map(array $args): AiGrcMapping
    {
        $framework = (string) ($args['framework'] ?? '');
        if (! in_array($framework, self::ALLOWED_FRAMEWORKS, true)) {
            throw CyberDomainException::invalidValue('grc_mapping', 'framework',
                'must be one of ['.implode(',', self::ALLOWED_FRAMEWORKS).']');
        }
        $controlId = (string) ($args['control_id'] ?? '');
        if ($controlId === '') {
            throw CyberDomainException::missingField('grc_mapping', 'control_id');
        }
        $controlTitle = (string) ($args['control_title'] ?? '');
        if ($controlTitle === '') {
            throw CyberDomainException::missingField('grc_mapping', 'control_title');
        }
        $complianceStatus = (string) ($args['compliance_status'] ?? self::STATUS_UNASSESSED);
        if (! in_array($complianceStatus, self::ALLOWED_STATUSES, true)) {
            throw CyberDomainException::invalidValue('grc_mapping', 'compliance_status',
                'must be one of ['.implode(',', self::ALLOWED_STATUSES).']');
        }

        $mappingId = (string) ($args['mapping_id'] ?? "{$framework}:{$controlId}");

        $hashInput = [
            'mapping_id' => $mappingId,
            'engagement_id' => $args['engagement_id'] ?? null,
            'framework' => $framework,
            'control_id' => $controlId,
            'control_title' => $controlTitle,
            'evidence_refs' => $args['evidence_refs'] ?? [],
            'gaps' => $args['gaps'] ?? [],
            'compliance_status' => $complianceStatus,
        ];

        return AiGrcMapping::query()->create([
            'uuid' => (string) Str::uuid(),
            'engagement_id' => $args['engagement_id'] ?? null,
            'mapping_id' => $mappingId,
            'framework' => $framework,
            'control_id' => $controlId,
            'control_title' => $controlTitle,
            'evidence_refs' => $args['evidence_refs'] ?? null,
            'gaps' => $args['gaps'] ?? null,
            'compliance_status' => $complianceStatus,
            'remediation_refs' => $args['remediation_refs'] ?? null,
            'mapping_hash' => CyberCanonicalHash::sha256($hashInput),
        ]);
    }
}
