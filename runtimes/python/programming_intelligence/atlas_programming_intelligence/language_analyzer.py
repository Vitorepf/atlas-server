from __future__ import annotations

import ast
import hashlib
import re
from pathlib import Path
from typing import Any

TOKEN_RE = re.compile(r"[A-Za-z_][A-Za-z0-9_]{2,}")
PHP_CLASS_RE = re.compile(r"\b(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)")
PHP_FUNCTION_RE = re.compile(r"\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(")
PHP_USE_RE = re.compile(r"^\s*use\s+([^;]+);", re.MULTILINE)
JS_SYMBOL_RE = re.compile(r"\b(?:class|function|const|let|var)\s+([A-Za-z_][A-Za-z0-9_]*)")
SWIFT_SYMBOL_RE = re.compile(r"\b(?:class|struct|enum|protocol|func)\s+([A-Za-z_][A-Za-z0-9_]*)")


def analyze_file(workspace: Path, path: Path) -> dict[str, Any]:
    text = path.read_text(encoding="utf-8", errors="ignore")
    relative = path.relative_to(workspace).as_posix()
    suffix = path.suffix.lower()

    if suffix == ".py":
        language = "python"
        symbols, imports = _python_symbols(text)
    elif suffix == ".php":
        language = "php"
        symbols, imports = _php_symbols(text)
    elif suffix in {".js", ".jsx", ".ts", ".tsx", ".vue"}:
        language = "javascript_typescript"
        symbols, imports = _regex_symbols(text, JS_SYMBOL_RE), []
    elif suffix == ".swift":
        language = "swift"
        symbols, imports = _regex_symbols(text, SWIFT_SYMBOL_RE), []
    else:
        language = "text"
        symbols, imports = [], []

    token_vector = _token_vector(relative + "\n" + text)

    return {
        "path": relative,
        "language": language,
        "source_hash": _sha256(text),
        "symbols": symbols,
        "imports": imports,
        "local_embedding": {
            "provider": "local_token_hash",
            "dimensions": len(token_vector),
            "top_tokens": token_vector[:24],
        },
    }


def _python_symbols(text: str) -> tuple[list[dict[str, Any]], list[str]]:
    symbols: list[dict[str, Any]] = []
    imports: list[str] = []
    try:
        tree = ast.parse(text)
    except SyntaxError:
        return symbols, imports

    for node in ast.walk(tree):
        if isinstance(node, (ast.ClassDef, ast.FunctionDef, ast.AsyncFunctionDef)):
            symbols.append({
                "kind": "class" if isinstance(node, ast.ClassDef) else "function",
                "name": node.name,
                "line": node.lineno,
            })
        elif isinstance(node, ast.Import):
            imports.extend(alias.name for alias in node.names)
        elif isinstance(node, ast.ImportFrom) and node.module:
            imports.append(node.module)

    return symbols, sorted(set(imports))


def _php_symbols(text: str) -> tuple[list[dict[str, Any]], list[str]]:
    symbols = [
        {"kind": match.group(1), "name": match.group(2), "line": _line_for(text, match.start())}
        for match in PHP_CLASS_RE.finditer(text)
    ]
    symbols.extend(
        {"kind": "function", "name": match.group(1), "line": _line_for(text, match.start())}
        for match in PHP_FUNCTION_RE.finditer(text)
    )
    imports = [match.group(1).strip() for match in PHP_USE_RE.finditer(text)]

    return symbols, sorted(set(imports))


def _regex_symbols(text: str, regex: re.Pattern[str]) -> list[dict[str, Any]]:
    return [
        {"kind": "symbol", "name": match.group(1), "line": _line_for(text, match.start())}
        for match in regex.finditer(text)
    ]


def _token_vector(text: str) -> list[dict[str, Any]]:
    counts: dict[str, int] = {}
    for token in TOKEN_RE.findall(text.lower()):
        if token in {"the", "and", "para", "com", "que", "atlas"}:
            continue
        counts[token] = counts.get(token, 0) + 1

    return [
        {"token_hash": _sha256(token)[:16], "weight": count}
        for token, count in sorted(counts.items(), key=lambda item: (-item[1], item[0]))
    ]


def _line_for(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def _sha256(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()
