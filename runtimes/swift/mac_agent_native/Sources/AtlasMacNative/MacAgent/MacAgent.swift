// Atlas Mac Agent — Apple native power helper, wake detection, background checks.
//
// Consumes `atlas.runtime_invocation_contract.v1` from the Laravel kernel.
// Apple-only capabilities (IOKit, NSWorkspace, etc.). Returns receipts.

import Foundation

public enum MacAgentCapability: String {
    case powerHelper = "power_helper"
    case wakeDetection = "wake_detection"
    case backgroundCheck = "background_check"
    case voiceEdge = "voice_edge"
    case appleContext = "apple_context"
}

public enum MacAgent {
    public static let blockId = "mac_agent_native"
    public static let expectedRuntime = "swift_native_mac"

    public static func run(envelope: [String: Any]) -> [String: Any] {
        let contract: RuntimeInvocationContract
        do {
            contract = try BoundaryContract.validate(envelope: envelope)
        } catch {
            return [
                "schema_version": "atlas.runtime_invocation_receipt.v1",
                "outcome": "refused_by_swift_side_canon",
                "detail": "\(error)",
                "provider_safe": true
            ]
        }

        guard contract.blockId == blockId else {
            return refuse(contract: contract, detail: "block_id mismatch")
        }
        guard contract.targetRuntime == expectedRuntime else {
            return refuse(contract: contract, detail: "target_runtime mismatch")
        }

        guard let capabilityRaw = contract.payload["capability"] as? String,
              MacAgentCapability(rawValue: capabilityRaw) != nil else {
            return refuse(contract: contract, detail: "invalid capability")
        }

        guard let governed = contract.payload["governed_by_decision_receipt"] as? Bool, governed else {
            return refuse(contract: contract, detail: "governed_by_decision_receipt must be true")
        }

        if let voiceFirst = contract.payload["voice_runtime_first_surface"] as? Bool, voiceFirst {
            return refuse(contract: contract, detail: "mac agent must NOT be the first voice surface (mobile-first canon)")
        }

        // Apple framework wiring is opt-in — when the operator approves
        // and the AP for the capability ships, this returns a real receipt.
        return receipt(
            contract: contract,
            outcome: "runtime_unavailable_pending_provider",
            detail: "Apple framework wiring pending operator AP for capability '\(capabilityRaw)'."
        )
    }

    private static func refuse(contract: RuntimeInvocationContract, detail: String) -> [String: Any] {
        return receipt(contract: contract, outcome: "refused_by_swift_side_canon", detail: detail)
    }

    private static func receipt(
        contract: RuntimeInvocationContract,
        outcome: String,
        detail: String
    ) -> [String: Any] {
        return [
            "schema_version": "atlas.runtime_invocation_receipt.v1",
            "block_id": contract.blockId,
            "target_runtime": contract.targetRuntime,
            "invocation_id": contract.invocationId,
            "outcome": outcome,
            "issued_at": ISO8601DateFormatter().string(from: Date()),
            "evidence_refs": [
                "decision_receipt": contract.decisionReceiptRef,
                "evidence_sink": contract.evidenceSinkRef,
                "rollback_plan": contract.rollbackPlanRef
            ],
            "detail": detail,
            "provider_safe": true
        ]
    }
}
