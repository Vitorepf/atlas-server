"""
Atlas Minimax M27 Python Adapter
Called as subprocess from AtlasMinimaxM27CliRuntimeExecutor:
  python3 adapter.py <manifest_json_path>
"""
import json
import os
import re
import sys


def redact_api_keys(text: str) -> str:
    """Redact any sk- prefixed strings from text."""
    return re.sub(r'sk-[A-Za-z0-9\-_]+', '[REDACTED]', text)


def output_result(data: dict) -> None:
    print(json.dumps(data), flush=True)


def output_error(error_class: str, error_msg: str) -> None:
    output_result({
        "status": "failed",
        "error_class": error_class,
        "error": redact_api_keys(error_msg),
    })


def classify_api_status_error(exc) -> tuple[str, str]:
    """Classify an APIStatusError into (error_class, message)."""
    status_code = getattr(exc, 'status_code', None)
    body = str(exc)
    if status_code == 429:
        lower = body.lower()
        if 'quota' in lower or 'credit' in lower:
            return 'quota_exhausted', body
        return 'rate_limit', body
    return 'provider_error', body


def build_task_text(manifest: dict) -> str:
    task_contract = manifest.get('task_contract', {})
    description = task_contract.get('task_description', '')
    context = task_contract.get('context', '')
    parts = []
    if description:
        parts.append(description)
    if context:
        parts.append(context)
    return '\n\n'.join(parts)


def main() -> int:
    if len(sys.argv) < 2:
        output_error('provider_error', 'Missing manifest_json_path argument')
        return 1

    manifest_path = sys.argv[1]

    try:
        with open(manifest_path, 'r', encoding='utf-8') as f:
            manifest = json.load(f)
    except FileNotFoundError:
        output_error('provider_error', f'Manifest file not found: {manifest_path}')
        return 1
    except json.JSONDecodeError as e:
        output_error('provider_error', f'Invalid JSON in manifest: {e}')
        return 1

    api_key = os.environ.get('ANTHROPIC_API_KEY', '')
    if not api_key:
        output_error('auth_failed', 'ANTHROPIC_API_KEY environment variable is missing or empty')
        return 1

    base_url = os.environ.get('ANTHROPIC_BASE_URL', 'https://api.minimax.io/anthropic')

    model = manifest.get('model', 'minimax-m27')
    metadata = manifest.get('metadata', {})
    role = metadata.get('role', 'worker') if isinstance(metadata, dict) else 'worker'
    timeout_seconds = manifest.get('timeout_seconds', 120)
    max_output_chars = manifest.get('max_output_chars', None)

    task_text = build_task_text(manifest)
    system_prompt = (
        f"You are a governed Atlas Forge worker. "
        f"Role: {role}. "
        f"Completion claims are NEVER allowed."
    )

    try:
        import anthropic
    except ImportError:
        output_error('provider_error', 'anthropic SDK not installed')
        return 1

    try:
        client = anthropic.Anthropic(
            base_url=base_url,
            api_key=api_key,
        )

        response = client.messages.create(
            model=model,
            max_tokens=8192,
            system=system_prompt,
            messages=[
                {"role": "user", "content": task_text}
            ],
        )
    except anthropic.AuthenticationError as e:
        output_error('auth_failed', redact_api_keys(str(e)))
        return 1
    except anthropic.RateLimitError as e:
        error_body = str(e).lower()
        if 'quota' in error_body or 'credit' in error_body:
            output_error('quota_exhausted', redact_api_keys(str(e)))
        else:
            output_error('rate_limit', redact_api_keys(str(e)))
        return 1
    except anthropic.APIStatusError as e:
        error_class, msg = classify_api_status_error(e)
        output_error(error_class, redact_api_keys(msg))
        return 1
    except Exception as e:
        msg = str(e)
        if 'timeout' in msg.lower() or 'timed out' in msg.lower():
            output_error('timeout', redact_api_keys(msg))
        else:
            output_error('provider_error', redact_api_keys(msg))
        return 1

    text_parts = []
    thinking_parts = []
    for block in response.content:
        block_type = getattr(block, 'type', None)
        if block_type == 'text':
            text_parts.append(getattr(block, 'text', ''))
        elif block_type == 'thinking':
            thinking_parts.append(getattr(block, 'thinking', ''))

    text = ''.join(text_parts)
    thinking = ''.join(thinking_parts) if thinking_parts else None

    if max_output_chars is not None and isinstance(max_output_chars, int) and len(text) > max_output_chars:
        text = text[:max_output_chars]

    usage = getattr(response, 'usage', None)
    input_tokens = getattr(usage, 'input_tokens', None) if usage else None
    output_tokens = getattr(usage, 'output_tokens', None) if usage else None

    request_id = None
    if hasattr(response, '_request_id'):
        request_id = response._request_id
    elif hasattr(response, 'id'):
        request_id = response.id

    result = {
        "status": "completed",
        "model_observed": response.model,
        "text": text,
        "thinking": thinking,
        "stop_reason": response.stop_reason,
        "input_tokens": input_tokens,
        "output_tokens": output_tokens,
        "request_id": request_id,
    }

    output_result(result)
    return 0


if __name__ == '__main__':
    sys.exit(main())
