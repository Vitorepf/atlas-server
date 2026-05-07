"""Atlas Voice Realtime runtime scaffold.

The package intentionally has no third-party dependencies at scaffold stage.
LiveKit Agents SDK will plug into this boundary later, after the Kernel
manifest, auth and fail-closed behavior are proven.
"""

from .agent_runtime import AtlasVoiceAgentRuntime, AtlasVoiceRuntimeError, AtlasVoiceTurnResult
from .callback_payload import (
    AtlasVoiceFailurePayload,
    AtlasVoiceInterruptedPayload,
    AtlasVoicePlayedPayload,
    AtlasVoiceProviderHealthPayload,
    AtlasVoiceSynthesizedPayload,
)
from .contract import AtlasVoiceRuntimeContract, ContractViolation
from .livekit_boundary import LiveKitAgentBoundary, LiveKitTurnContext
from .settings import AtlasVoiceRuntimeSettings, SettingsError
from .turn_payload import AtlasVoiceTurnPayload, UnsafeVoicePayload

__all__ = [
    "AtlasVoiceFailurePayload",
    "AtlasVoiceAgentRuntime",
    "AtlasVoiceInterruptedPayload",
    "AtlasVoicePlayedPayload",
    "AtlasVoiceProviderHealthPayload",
    "AtlasVoiceRuntimeContract",
    "AtlasVoiceRuntimeSettings",
    "AtlasVoiceSynthesizedPayload",
    "AtlasVoiceTurnPayload",
    "AtlasVoiceTurnResult",
    "AtlasVoiceRuntimeError",
    "ContractViolation",
    "LiveKitAgentBoundary",
    "LiveKitTurnContext",
    "SettingsError",
    "UnsafeVoicePayload",
]
