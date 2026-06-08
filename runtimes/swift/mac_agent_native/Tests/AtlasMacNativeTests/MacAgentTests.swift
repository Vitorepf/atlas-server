// Stdlib-only tests for environments without XCTest (system swift).
// When Xcode toolchain is installed, this can be migrated to XCTest.
//
// Build: swift build
// Run:   swift run AtlasMacNativeTestRunner  (when manifest is set up for executable)
//
// For now this file documents the test contracts; the canonical Swift
// behaviour is exercised via the library API, which `swift build` verifies
// compiles correctly.

import AtlasMacNative
import Foundation

// Provide trivial test helpers so file compiles standalone.
fileprivate enum TestFailure: Error { case assertion(String) }

fileprivate func assertEqual<T: Equatable>(_ a: T?, _ b: T, _ label: String) throws {
    if a != b {
        throw TestFailure.assertion("\(label): expected \(b), got \(a ?? a as! T)")
    }
}

fileprivate func validEnvelope(payload: [String: Any] = [:]) -> [String: Any] {
    return [
        "schema_version": "atlas.runtime_invocation_contract.v1",
        "block_id": "mac_agent_native",
        "target_runtime": "swift_native_mac",
        "invocation_id": "inv-test",
        "contract_status": "invocation_contract_ready",
        "runtime_invocation_allowed": true,
        "evidence_refs": [
            "decision_receipt": "rcpt:t",
            "evidence_sink": "evidence:t",
            "rollback_plan": "rollback:t"
        ],
        "payload": payload
    ]
}

@discardableResult
public func runMacAgentTests() -> Int {
    var passed = 0
    var failed = 0

    let cases: [(String, () throws -> Void)] = [
        ("valid envelope returns pending", {
            let r = MacAgent.run(envelope: validEnvelope(payload: [
                "capability": "power_helper",
                "governed_by_decision_receipt": true
            ]))
            try assertEqual(r["outcome"] as? String, "runtime_unavailable_pending_provider", "outcome")
        }),
        ("invalid capability refused", {
            let r = MacAgent.run(envelope: validEnvelope(payload: [
                "capability": "rogue",
                "governed_by_decision_receipt": true
            ]))
            try assertEqual(r["outcome"] as? String, "refused_by_swift_side_canon", "outcome")
        }),
        ("missing governed refused", {
            let r = MacAgent.run(envelope: validEnvelope(payload: [
                "capability": "power_helper"
            ]))
            try assertEqual(r["outcome"] as? String, "refused_by_swift_side_canon", "outcome")
        }),
        ("voice first surface refused", {
            let r = MacAgent.run(envelope: validEnvelope(payload: [
                "capability": "voice_edge",
                "governed_by_decision_receipt": true,
                "voice_runtime_first_surface": true
            ]))
            try assertEqual(r["outcome"] as? String, "refused_by_swift_side_canon", "outcome")
        }),
    ]

    for (name, test) in cases {
        do {
            try test()
            print("ok   - \(name)")
            passed += 1
        } catch {
            print("FAIL - \(name): \(error)")
            failed += 1
        }
    }

    print("\nresult: \(passed) passed, \(failed) failed")
    return failed
}
