// Atlas Mac Native — Canonical contract validator (Swift mirror of AP-201).
//
// Mirrors PHP `FutureRuntimeInvocationContract` and Python `boundary/contract.py`.
// Every Swift runtime entry point MUST call `validate(envelope:)` before
// executing — defense in depth.

import Foundation

public enum ContractError: Error, CustomStringConvertible {
    case missingField(String)
    case schemaMismatch(expected: String, got: String)
    case invalidTargetRuntime(String)
    case missingEvidenceRef(String)
    case statusNotReady(String)
    case runtimeNotAllowed
    case invalidPayload(String)

    public var description: String {
        switch self {
        case .missingField(let f): return "missing required field '\(f)'"
        case .schemaMismatch(let e, let g): return "schema_version mismatch: expected '\(e)', got '\(g)'"
        case .invalidTargetRuntime(let t): return "target_runtime '\(t)' not in allowed list"
        case .missingEvidenceRef(let r): return "evidence_refs.\(r) required (AP-201)"
        case .statusNotReady(let s): return "contract_status must be 'invocation_contract_ready', got '\(s)'"
        case .runtimeNotAllowed: return "runtime_invocation_allowed must be true"
        case .invalidPayload(let r): return "invalid payload: \(r)"
        }
    }
}

public struct RuntimeInvocationContract {
    public let blockId: String
    public let targetRuntime: String
    public let invocationId: String
    public let decisionReceiptRef: String
    public let evidenceSinkRef: String
    public let rollbackPlanRef: String
    public let payload: [String: Any]
}

public enum BoundaryContract {
    public static let schemaVersion = "atlas.runtime_invocation_contract.v1"

    public static let allowedTargetRuntimes: Set<String> = [
        "livekit_agents_python",
        "python_ai_data",
        "go_edge",
        "swift_native_mac",
        "ux_app_mobile",
        "ux_app_desktop",
        "external_crawler",
        "external_browser_automation"
    ]

    public static func validate(envelope: [String: Any]) throws -> RuntimeInvocationContract {
        // schema version
        guard let schema = envelope["schema_version"] as? String else {
            throw ContractError.missingField("schema_version")
        }
        guard schema == schemaVersion else {
            throw ContractError.schemaMismatch(expected: schemaVersion, got: schema)
        }

        // required fields
        guard let blockId = envelope["block_id"] as? String, !blockId.isEmpty else {
            throw ContractError.missingField("block_id")
        }
        guard let targetRuntime = envelope["target_runtime"] as? String else {
            throw ContractError.missingField("target_runtime")
        }
        guard allowedTargetRuntimes.contains(targetRuntime) else {
            throw ContractError.invalidTargetRuntime(targetRuntime)
        }
        guard let invocationId = envelope["invocation_id"] as? String, !invocationId.isEmpty else {
            throw ContractError.missingField("invocation_id")
        }

        // contract status
        let contractStatus = envelope["contract_status"] as? String ?? ""
        guard contractStatus == "invocation_contract_ready" else {
            throw ContractError.statusNotReady(contractStatus)
        }

        // runtime allowed
        guard let allowed = envelope["runtime_invocation_allowed"] as? Bool, allowed else {
            throw ContractError.runtimeNotAllowed
        }

        // evidence refs (AP-201 canonical invariants)
        guard let evidence = envelope["evidence_refs"] as? [String: Any] else {
            throw ContractError.missingField("evidence_refs")
        }
        guard let decisionRef = evidence["decision_receipt"] as? String, !decisionRef.isEmpty else {
            throw ContractError.missingEvidenceRef("decision_receipt")
        }
        guard let sinkRef = evidence["evidence_sink"] as? String, !sinkRef.isEmpty else {
            throw ContractError.missingEvidenceRef("evidence_sink")
        }
        guard let rollbackRef = evidence["rollback_plan"] as? String, !rollbackRef.isEmpty else {
            throw ContractError.missingEvidenceRef("rollback_plan")
        }

        let payload = envelope["payload"] as? [String: Any] ?? [:]

        return RuntimeInvocationContract(
            blockId: blockId,
            targetRuntime: targetRuntime,
            invocationId: invocationId,
            decisionReceiptRef: decisionRef,
            evidenceSinkRef: sinkRef,
            rollbackPlanRef: rollbackRef,
            payload: payload
        )
    }
}
