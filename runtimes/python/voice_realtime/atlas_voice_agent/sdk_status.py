from __future__ import annotations

import importlib.util
import json
import sys
from importlib import metadata
from pathlib import Path
from typing import Any, Mapping

from .contract import AtlasVoiceRuntimeContract


def load_dependency_manifest(path: Path | None = None) -> Mapping[str, Any]:
    manifest_path = path or Path(__file__).resolve().parents[1] / "runtime-dependencies.json"
    with manifest_path.open("r", encoding="utf-8") as handle:
        payload = json.load(handle)

    if not isinstance(payload, Mapping):
        raise ValueError("runtime dependency manifest must be a JSON object")

    return payload


def inspect_livekit_sdk(contract: AtlasVoiceRuntimeContract) -> Mapping[str, Any]:
    """Report optional LiveKit SDK availability without importing SDK code."""

    dependency_manifest = load_dependency_manifest()
    python_contract = dependency_manifest.get("python", {})
    minimum_python = None
    if isinstance(python_contract, Mapping):
        minimum_python = str(python_contract.get("minimum_version") or "").strip() or None
    python_satisfies_minimum = _version_satisfies_minimum(sys.version.split()[0], minimum_python)
    packages = dependency_manifest.get("optional_livekit", {}).get("packages", [])
    if not isinstance(packages, list):
        packages = []
    package_checks = [
        _package_check(package)
        for package in packages
        if isinstance(package, Mapping)
    ]
    livekit_available = _safe_find_spec("livekit") is not None
    agents_available = _safe_find_spec("livekit.agents") is not None if livekit_available else False
    missing_imports = [
        str(check["import"])
        for check in package_checks
        if not bool(check["available"])
    ]
    outdated_imports = [
        str(check["import"])
        for check in package_checks
        if bool(check["available"]) and check.get("version_satisfies_minimum") is False
    ]
    ready = python_satisfies_minimum is True and missing_imports == [] and outdated_imports == [] and packages != []

    return {
        "schema_version": "atlas.voice_realtime.sdk_check.v1",
        "status": "ready" if ready else "missing_optional_dependency",
        "runtime_id": "livekit_agents_sdk",
        "runtime_family": "python_ai_data",
        "kernel_only": True,
        "surface_id": "voice_realtime",
        "python_version": sys.version.split()[0],
        "python_minimum_version": minimum_python,
        "python_satisfies_minimum": python_satisfies_minimum,
        "sdk_imported": False,
        "import_probe_only": True,
        "dependency_manifest": {
            "schema_version": dependency_manifest.get("schema_version"),
            "status": dependency_manifest.get("status"),
            "python": dependency_manifest.get("python"),
            "core_third_party_dependencies": dependency_manifest.get("core", {}).get("third_party_dependencies", []),
            "optional_livekit_packages": dependency_manifest.get("optional_livekit", {}).get("packages", []),
            "requirements_file": dependency_manifest.get("optional_livekit", {}).get("requirements_file"),
            "install_command": dependency_manifest.get("optional_livekit", {}).get("install_command"),
            "verify_command": dependency_manifest.get("optional_livekit", {}).get("verify_command"),
            "activation_gate": dependency_manifest.get("optional_livekit", {}).get("activation_gate"),
            "probe_policy": dependency_manifest.get("optional_livekit", {}).get("probe_policy"),
            "install_policy": dependency_manifest.get("optional_livekit", {}).get("install_policy"),
        },
        "package_checks": package_checks,
        "missing_imports": missing_imports,
        "outdated_imports": outdated_imports,
        "packages": {
            "livekit": livekit_available,
            "livekit.agents": agents_available,
        },
        "contract": {
            "session_start_url": contract.session_start_url,
            "turn_url": contract.turn_url,
            "wake_word_url": contract.wake_word_url,
            "runtime_requires_decision_receipt": True,
            "raw_audio_persistence_allowed": False,
        },
        "next_action": _sdk_next_action(python_satisfies_minimum, missing_imports, outdated_imports),
    }


def build_dependency_install_plan(path: Path | None = None) -> Mapping[str, Any]:
    """Describe the operator-managed optional SDK install without running pip."""

    dependency_manifest = load_dependency_manifest(path)
    optional_livekit = dependency_manifest.get("optional_livekit", {})
    packages = optional_livekit.get("packages", [])
    if not isinstance(packages, list):
        packages = []

    repo_root = Path(__file__).resolve().parents[4]
    requirements_ref = str(optional_livekit.get("requirements_file") or "").strip()
    requirements_path = _requirements_path(repo_root, requirements_ref)
    requirements_lines = _read_requirements_lines(requirements_path)
    expected_requirements = [
        _expected_requirement(package)
        for package in packages
        if isinstance(package, Mapping) and _expected_requirement(package) != ""
    ]
    expected_packages = [
        str(package.get("pip") or "").strip()
        for package in packages
        if isinstance(package, Mapping) and str(package.get("pip") or "").strip() != ""
    ]
    missing_requirements = [
        requirement for requirement in expected_requirements
        if requirement not in requirements_lines
    ]
    unsafe_requirements = [
        line for line in requirements_lines
        if _unsafe_requirement_line(line)
    ]
    requirements_ready = (
        requirements_ref != ""
        and requirements_path is not None
        and requirements_path.is_file()
        and missing_requirements == []
        and unsafe_requirements == []
    )

    return {
        "schema_version": "atlas.voice_realtime.dependency_install_plan.v1",
        "status": "ready_to_install_optional_dependency" if requirements_ready else "blocked",
        "runtime_id": "livekit_agents_sdk",
        "runtime_family": "python_ai_data",
        "surface_id": "voice_realtime",
        "operator_managed": True,
        "pip_execution_attempted": False,
        "sdk_imported": False,
        "daemon_started": False,
        "kernel_only": True,
        "mobile_first": True,
        "requirements_file": requirements_ref,
        "requirements_path": str(requirements_path) if requirements_path is not None else None,
        "requirements_sha256": _file_sha256(requirements_path) if requirements_ready else None,
        "expected_packages": expected_packages,
        "expected_requirements": expected_requirements,
        "requirements_packages": requirements_lines,
        "missing_requirements": missing_requirements,
        "unsafe_requirements": unsafe_requirements,
        "install_command": optional_livekit.get("install_command"),
        "verify_command": optional_livekit.get("verify_command"),
        "activation_gate": optional_livekit.get("activation_gate"),
        "install_policy": optional_livekit.get("install_policy"),
        "gates": {
            "manifest_available": dependency_manifest.get("schema_version") == "atlas.voice_realtime.runtime_dependencies.v1",
            "requirements_file_declared": requirements_ref != "",
            "requirements_file_exists": requirements_path is not None and requirements_path.is_file(),
            "requirements_match_manifest": missing_requirements == [],
            "requirements_safe": unsafe_requirements == [],
            "pip_not_executed": True,
            "sdk_not_imported": True,
            "daemon_not_started": True,
        },
        "forbidden_shortcuts": [
            "run_pip_from_sdk_check",
            "install_dependency_without_operator_review",
            "import_livekit_during_install_plan",
            "start_daemon_after_dependency_install",
            "change_kernel_policy_from_dependency_install",
        ],
        "next_action": (
            "run_install_command_then_sdk_check"
            if requirements_ready
            else "fix_dependency_install_plan"
        ),
    }


def validate_dependency_install_plan(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    """Validate the Kernel-published dependency install plan without executing it."""

    errors: list[str] = []
    if payload.get("schema_version") != "atlas.voice_realtime.dependency_install_plan.v1":
        errors.append("invalid_schema_version")
    if payload.get("surface_id") != "voice_realtime":
        errors.append("invalid_surface_id")
    if payload.get("runtime_id") != "livekit_agents_sdk":
        errors.append("invalid_runtime_id")
    if payload.get("runtime_family") != "python_ai_data":
        errors.append("invalid_runtime_family")
    if payload.get("operator_managed") is not True:
        errors.append("operator_managed_required")
    if payload.get("pip_execution_attempted") is not False:
        errors.append("pip_execution_must_be_false")
    if payload.get("sdk_imported") is not False:
        errors.append("sdk_imported_must_be_false")
    if payload.get("daemon_started") is not False:
        errors.append("daemon_started_must_be_false")
    if payload.get("kernel_only") is not True:
        errors.append("kernel_only_required")
    if payload.get("mobile_first") is not True:
        errors.append("mobile_first_required")

    gates = payload.get("gates")
    if not isinstance(gates, Mapping):
        errors.append("gates_required")
    else:
        for gate in ["pip_not_executed", "sdk_not_imported", "daemon_not_started"]:
            if gates.get(gate) is not True:
                errors.append(f"{gate}_required")

    forbidden = set(payload.get("forbidden_shortcuts") or [])
    for shortcut in [
        "run_pip_from_sdk_check",
        "install_dependency_without_operator_review",
        "import_livekit_during_install_plan",
        "start_daemon_after_dependency_install",
        "change_kernel_policy_from_dependency_install",
    ]:
        if shortcut not in forbidden:
            errors.append(f"missing_forbidden_shortcut:{shortcut}")

    if errors:
        return {
            "schema_version": "atlas.voice_realtime.dependency_install_plan_validation.v1",
            "status": "invalid",
            "errors": errors,
            "trusted": False,
        }

    return payload


def _package_check(package: Mapping[str, Any]) -> Mapping[str, Any]:
    import_name = str(package.get("import") or "").strip()
    pip_name = str(package.get("pip") or import_name).strip()
    minimum_version = str(package.get("minimum_version") or "").strip() or None
    version_specifier = str(package.get("version_specifier") or "").strip() or None
    spec = _safe_find_spec(import_name) if import_name else None
    version = _installed_version(pip_name)
    version_satisfies_minimum = _version_satisfies_minimum(version, minimum_version)

    return {
        "pip": pip_name,
        "import": import_name,
        "available": spec is not None,
        "version": version,
        "minimum_version": minimum_version,
        "version_specifier": version_specifier,
        "version_satisfies_minimum": version_satisfies_minimum,
        "version_policy": "version_specifier_required" if version_specifier else "minimum_version_required",
        "required_for": package.get("required_for") or "product_loop_daemon",
        "purpose": package.get("purpose"),
    }


def _sdk_next_action(
    python_satisfies_minimum: bool | None,
    missing_imports: list[str],
    outdated_imports: list[str],
) -> str:
    if python_satisfies_minimum is not True:
        return "upgrade_python_runtime_for_livekit_agents_sdk"
    if missing_imports:
        return "install_livekit_agents_sdk"
    if outdated_imports:
        return "upgrade_livekit_agents_sdk"

    return "wire_real_sdk_callbacks"


def _installed_version(pip_name: str) -> str | None:
    if pip_name == "":
        return None

    try:
        return metadata.version(pip_name)
    except metadata.PackageNotFoundError:
        return None


def _safe_find_spec(import_name: str) -> Any | None:
    try:
        return importlib.util.find_spec(import_name)
    except (ImportError, AttributeError, ValueError):
        return None


def _requirements_path(repo_root: Path, requirements_ref: str) -> Path | None:
    if requirements_ref == "":
        return None

    path = Path(requirements_ref)
    if path.is_absolute():
        return path

    return repo_root / path


def _read_requirements_lines(requirements_path: Path | None) -> list[str]:
    if requirements_path is None or not requirements_path.is_file():
        return []

    return [
        line.strip()
        for line in requirements_path.read_text(encoding="utf-8").splitlines()
        if line.strip() != "" and not line.strip().startswith("#")
    ]


def _unsafe_requirement_line(line: str) -> bool:
    return (
        line.startswith("-")
        or "://" in line
        or line.startswith("git+")
        or ";" in line
    )


def _expected_requirement(package: Mapping[str, Any]) -> str:
    pip_name = str(package.get("pip") or "").strip()
    if pip_name == "":
        return ""

    version_specifier = str(package.get("version_specifier") or "").strip()
    if version_specifier != "":
        return f"{pip_name}{version_specifier}"

    return pip_name


def _version_satisfies_minimum(version: str | None, minimum_version: str | None) -> bool | None:
    if version is None:
        return None
    if minimum_version is None:
        return True

    parsed_version = _parse_version(version)
    parsed_minimum = _parse_version(minimum_version)
    if parsed_version is None or parsed_minimum is None:
        return None

    return parsed_version >= parsed_minimum


def _parse_version(value: str) -> tuple[int, ...] | None:
    parts: list[int] = []
    for raw_part in value.split("."):
        digits = ""
        for char in raw_part:
            if char.isdigit():
                digits += char
            else:
                break
        if digits == "":
            return None
        parts.append(int(digits))

    return tuple(parts)


def _file_sha256(path: Path | None) -> str | None:
    if path is None or not path.is_file():
        return None

    import hashlib

    return hashlib.sha256(path.read_bytes()).hexdigest()
