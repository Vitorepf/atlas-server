"""Shared Python runtime entrypoint contracts for Atlas governed runtimes."""

from .entrypoint import run_json_manifest_entrypoint, run_package_manifest_entrypoint

__all__ = ["run_json_manifest_entrypoint", "run_package_manifest_entrypoint"]
