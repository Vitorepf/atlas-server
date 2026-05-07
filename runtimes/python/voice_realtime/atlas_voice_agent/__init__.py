"""Atlas Voice Realtime runtime scaffold.

The package intentionally has no third-party dependencies at scaffold stage.
LiveKit Agents SDK will plug into this boundary later, after the Kernel
manifest, auth and fail-closed behavior are proven.
"""

from .agent_runtime import AtlasVoiceAgentRuntime, AtlasVoiceRuntimeError, AtlasVoiceTurnResult
from .activation_contract import build_activation_contract
from .callback_payload import (
    AtlasVoiceFailurePayload,
    AtlasVoiceInterruptedPayload,
    AtlasVoicePlayedPayload,
    AtlasVoiceProviderHealthPayload,
    AtlasVoiceSynthesizedPayload,
)
from .callback_contract import REQUIRED_CALLBACK_METHODS, REQUIRED_CALLBACK_PAYLOAD_SCHEMAS
from .contract import AtlasVoiceRuntimeContract, ContractViolation
from .livekit_boundary import LiveKitAgentBoundary, LiveKitTurnContext
from .livekit_callback_loop import inspect_callback_loop_contract
from .livekit_callback_router import LiveKitCallbackRouter
from .livekit_production_loop import build_production_loop_plan
from .livekit_production_loop_runner import LiveKitProductionLoopRunner
from .livekit_runtime_entrypoint import start_livekit_agents_worker
from .livekit_sdk_adapter import LiveKitSdkAdapter
from .livekit_sdk_event_bridge import LiveKitSdkEventBridge
from .livekit_sdk_wiring_contract import build_livekit_sdk_wiring_contract
from .livekit_session import LiveKitVoiceSession
from .livekit_worker import AtlasLiveKitWorker, LiveKitWorkerError, LiveKitWorkerResult
from .mock_kernel import MockKernelTransport
from .preflight import run_runtime_preflight
from .sdk_status import inspect_livekit_sdk
from .session_lease import AtlasVoiceSessionLease, UnsafeSessionLease
from .session_payload import AtlasVoiceSessionPayload
from .settings import AtlasVoiceRuntimeSettings, SettingsError
from .turn_payload import AtlasVoiceTurnPayload, UnsafeVoicePayload
from .wake_word_payload import AtlasVoiceWakeWordPayload
from .worker_plan import build_livekit_worker_plan

__all__ = [
    "AtlasVoiceFailurePayload",
    "AtlasVoiceAgentRuntime",
    "AtlasVoiceInterruptedPayload",
    "AtlasVoicePlayedPayload",
    "AtlasVoiceProviderHealthPayload",
    "AtlasVoiceRuntimeContract",
    "AtlasVoiceRuntimeSettings",
    "AtlasVoiceSessionLease",
    "AtlasVoiceSessionPayload",
    "AtlasVoiceSynthesizedPayload",
    "AtlasVoiceTurnPayload",
    "AtlasVoiceTurnResult",
    "AtlasVoiceRuntimeError",
    "AtlasVoiceWakeWordPayload",
    "AtlasLiveKitWorker",
    "ContractViolation",
    "LiveKitAgentBoundary",
    "LiveKitCallbackRouter",
    "LiveKitProductionLoopRunner",
    "LiveKitSdkAdapter",
    "LiveKitSdkEventBridge",
    "LiveKitTurnContext",
    "LiveKitWorkerError",
    "LiveKitWorkerResult",
    "LiveKitVoiceSession",
    "MockKernelTransport",
    "REQUIRED_CALLBACK_METHODS",
    "REQUIRED_CALLBACK_PAYLOAD_SCHEMAS",
    "SettingsError",
    "UnsafeSessionLease",
    "UnsafeVoicePayload",
    "build_livekit_worker_plan",
    "build_activation_contract",
    "build_livekit_sdk_wiring_contract",
    "build_production_loop_plan",
    "inspect_callback_loop_contract",
    "inspect_livekit_sdk",
    "run_runtime_preflight",
    "start_livekit_agents_worker",
]
