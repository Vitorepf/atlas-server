<?php

namespace Tests\Unit\Ai\Finance;

use App\Services\Ai\Finance\AtlasFinanceReviewRequest;
use Tests\TestCase;

class AtlasFinanceReviewRequestTest extends TestCase
{
    public function test_it_normalizes_operator_options(): void
    {
        $request = AtlasFinanceReviewRequest::fromOptions([
            'subject' => '  AAPL  ',
            'time_horizon' => ' quarter ',
            'jurisdiction' => ' US ',
            'portfolio_ref' => ' core ',
            'requested_action' => ' buy 10 shares ',
            'human_approval_required' => true,
        ]);

        $this->assertSame([
            'subject' => 'AAPL',
            'time_horizon' => 'quarter',
            'jurisdiction' => 'US',
            'portfolio_ref' => 'core',
            'requested_action' => 'buy 10 shares',
        ], $request->operatorOptions());
        $this->assertTrue($request->humanApprovalRequired);
    }

    public function test_it_defaults_empty_jurisdiction_and_discards_non_strings(): void
    {
        $request = AtlasFinanceReviewRequest::fromOptions([
            'subject' => 123,
            'time_horizon' => '',
            'jurisdiction' => ' ',
            'portfolio_ref' => null,
            'requested_action' => ['buy'],
        ]);

        $this->assertSame([
            'subject' => null,
            'time_horizon' => null,
            'jurisdiction' => 'unspecified',
            'portfolio_ref' => null,
            'requested_action' => null,
        ], $request->operatorOptions());
    }

    public function test_scope_payload_includes_jurisdiction_for_audit_hashing(): void
    {
        $request = AtlasFinanceReviewRequest::fromOptions(['jurisdiction' => 'BR']);

        $this->assertSame([
            'flow' => 'finance.risk_review',
            'subject' => null,
            'time_horizon' => null,
            'jurisdiction' => 'BR',
            'portfolio_ref' => null,
            'requested_action' => null,
        ], $request->scopePayload('finance.risk_review'));
    }
}
