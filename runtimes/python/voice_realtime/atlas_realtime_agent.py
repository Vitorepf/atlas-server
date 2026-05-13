from __future__ import annotations

from dotenv import load_dotenv

from livekit import agents
from livekit.agents import Agent, AgentServer, AgentSession
from livekit.plugins import openai

try:
    from openai.types.beta.realtime.session import TurnDetection
except Exception:  # pragma: no cover - import shape depends on OpenAI SDK minor version
    TurnDetection = None  # type: ignore[assignment]


load_dotenv(".env")
load_dotenv(".env.local")


class AtlasAssistant(Agent):
    def __init__(self) -> None:
        super().__init__(
            instructions=(
                "Voce e o Atlas, um assistente de voz em tempo real. "
                "Responda em portugues do Brasil, com frases curtas, naturais e audiveis. "
                "Nao cite formatacao, markdown ou detalhes internos. "
                "Se o usuario falar por cima, pare e acompanhe a nova intencao."
            )
        )


server = AgentServer()


@server.rtc_session(agent_name="atlas-voice-agent")
async def atlas_voice_agent(ctx: agents.JobContext) -> None:
    turn_detection = None
    if TurnDetection is not None:
        turn_detection = TurnDetection(
            type="semantic_vad",
            eagerness="medium",
            create_response=True,
            interrupt_response=True,
        )

    session = AgentSession(
        llm=openai.realtime.RealtimeModel(
            model="gpt-realtime",
            voice="marin",
            turn_detection=turn_detection,
        )
    )

    await session.start(
        room=ctx.room,
        agent=AtlasAssistant(),
    )

    await session.generate_reply(
        instructions="Cumprimente brevemente em portugues e diga que esta ao vivo."
    )


if __name__ == "__main__":
    agents.cli.run_app(server)
