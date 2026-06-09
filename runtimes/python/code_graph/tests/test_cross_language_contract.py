"""Tests for code_graph cross-language contract edges (AP-815, block P-10).

Runnable with pytest OR directly: `python3 tests/test_cross_language_contract.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.cross_language_contract import contract_graph  # noqa: E402


def _node_index(result: dict) -> dict:
    """Map node id -> its kind for easy assertions."""
    return {n["id"]: n["kind"] for n in result["nodes"]}


def _edge_keys(result: dict) -> set:
    """Set of (from, to, edge_type) tuples for membership checks."""
    return {(e["from"], e["to"], e["edge_type"]) for e in result["edges"]}


# --- OpenAPI --------------------------------------------------------------


def test_openapi_path_with_operation_id_emits_node_and_bind_edge() -> None:
    spec = {
        "kind": "openapi",
        "content": json.dumps(
            {
                "openapi": "3.0.0",
                "paths": {
                    "/users/{id}": {
                        "get": {"operationId": "getUser"},
                    }
                },
            }
        ),
    }
    result = contract_graph([spec])

    assert result["schema_version"] == "atlas.code_graph.cross_language_contract.v1"
    nodes = _node_index(result)
    # The API surface node exists.
    assert nodes.get("api:GET /users/{id}") == "api"
    # The operation node exists.
    assert nodes.get("op:getUser") == "op"
    # And the operation_binds edge connects them (op -> api).
    assert ("op:getUser", "api:GET /users/{id}", "operation_binds") in _edge_keys(result)
    assert result["by_kind"]["openapi"] == 1


def test_openapi_path_without_operation_id_emits_node_only() -> None:
    spec = {
        "kind": "openapi",
        "content": json.dumps({"paths": {"/ping": {"get": {"summary": "ping"}}}}),
    }
    result = contract_graph([spec])
    nodes = _node_index(result)
    assert nodes.get("api:GET /ping") == "api"
    # No operationId -> no operation_binds edge.
    assert result["edges"] == []


def test_openapi_multiple_methods_per_path() -> None:
    spec = {
        "kind": "openapi",
        "content": json.dumps(
            {
                "paths": {
                    "/items": {
                        "get": {"operationId": "listItems"},
                        "post": {"operationId": "createItem"},
                    }
                }
            }
        ),
    }
    result = contract_graph([spec])
    nodes = _node_index(result)
    assert nodes.get("api:GET /items") == "api"
    assert nodes.get("api:POST /items") == "api"
    edges = _edge_keys(result)
    assert ("op:listItems", "api:GET /items", "operation_binds") in edges
    assert ("op:createItem", "api:POST /items", "operation_binds") in edges


def test_openapi_ignores_non_method_keys_under_path() -> None:
    spec = {
        "kind": "openapi",
        "content": json.dumps(
            {
                "paths": {
                    "/x": {
                        "parameters": [{"name": "q"}],
                        "$ref": "#/somewhere",
                        "summary": "not a method",
                        "get": {"operationId": "getX"},
                    }
                }
            }
        ),
    }
    result = contract_graph([spec])
    nodes = _node_index(result)
    # Only the real GET method becomes an api node.
    api_nodes = [nid for nid, kind in nodes.items() if kind == "api"]
    assert api_nodes == ["api:GET /x"]


# --- GraphQL --------------------------------------------------------------


def test_graphql_field_referencing_another_type_emits_field_edge() -> None:
    sdl = """
    type User {
      id: ID
      bestFriend: User
      profile: Profile
    }
    type Profile {
      bio: String
    }
    """
    result = contract_graph([{"kind": "graphql", "content": sdl}])
    nodes = _node_index(result)
    assert nodes.get("gql:type:User") == "gql_type"
    assert nodes.get("gql:type:Profile") == "gql_type"
    edges = _edge_keys(result)
    # User.profile references Profile.
    assert ("gql:type:User", "gql:type:Profile", "graphql_field") in edges
    # Self-reference (bestFriend: User) is a valid field edge too.
    assert ("gql:type:User", "gql:type:User", "graphql_field") in edges
    assert result["by_kind"]["graphql"] == 1


def test_graphql_strips_list_and_nonnull_wrappers() -> None:
    sdl = """
    type Blog {
      posts: [Post!]!
      author: Author!
      tags: [String]
    }
    """
    result = contract_graph([{"kind": "graphql", "content": sdl}])
    edges = _edge_keys(result)
    # [Post!]! -> Post (wrappers stripped).
    assert ("gql:type:Blog", "gql:type:Post", "graphql_field") in edges
    # Author! -> Author.
    assert ("gql:type:Blog", "gql:type:Author", "graphql_field") in edges
    # [String] -> String (scalar, but we still record the named reference).
    assert ("gql:type:Blog", "gql:type:String", "graphql_field") in edges


def test_graphql_field_with_arguments_parsed() -> None:
    sdl = """
    type Query {
      user(id: ID!): User
    }
    """
    result = contract_graph([{"kind": "graphql", "content": sdl}])
    edges = _edge_keys(result)
    # Field args are skipped; return type still links Query -> User.
    assert ("gql:type:Query", "gql:type:User", "graphql_field") in edges


# --- proto ----------------------------------------------------------------


def test_proto_service_and_rpc_emit_nodes_and_edges() -> None:
    proto = """
    syntax = "proto3";
    service UserService {
      rpc GetUser (GetUserRequest) returns (GetUserResponse);
      rpc ListUsers (ListUsersRequest) returns (ListUsersResponse);
    }
    """
    result = contract_graph([{"kind": "proto", "content": proto}])
    nodes = _node_index(result)
    assert nodes.get("svc:UserService") == "svc"
    assert nodes.get("rpc:UserService.GetUser") == "rpc"
    assert nodes.get("rpc:UserService.ListUsers") == "rpc"
    edges = _edge_keys(result)
    assert ("svc:UserService", "rpc:UserService.GetUser", "proto_rpc") in edges
    assert ("svc:UserService", "rpc:UserService.ListUsers", "proto_rpc") in edges
    assert result["by_kind"]["proto"] == 1


# --- Cross-kind / aggregation ---------------------------------------------


def test_mixed_specs_aggregate_into_one_graph() -> None:
    specs = [
        {
            "kind": "openapi",
            "content": json.dumps(
                {"paths": {"/a": {"get": {"operationId": "getA"}}}}
            ),
        },
        {"kind": "graphql", "content": "type T { other: U }\ntype U { x: ID }"},
        {"kind": "proto", "content": "service S { rpc M (Req) returns (Res); }"},
    ]
    result = contract_graph(specs)
    assert result["by_kind"] == {"openapi": 1, "graphql": 1, "proto": 1}
    nodes = _node_index(result)
    assert nodes.get("api:GET /a") == "api"
    assert nodes.get("gql:type:T") == "gql_type"
    assert nodes.get("svc:S") == "svc"


def test_nodes_and_edges_deterministic_sorted() -> None:
    sdl = "type B { z: C }\ntype A { y: B }"
    result = contract_graph([{"kind": "graphql", "content": sdl}])
    # Nodes sorted by (kind, id) -> all gql_type, so by id.
    node_ids = [n["id"] for n in result["nodes"]]
    assert node_ids == sorted(node_ids)
    # Edges sorted by (edge_type, from, to).
    edge_keys = [(e["edge_type"], e["from"], e["to"]) for e in result["edges"]]
    assert edge_keys == sorted(edge_keys)


def test_deterministic_across_runs() -> None:
    specs = [
        {
            "kind": "openapi",
            "content": json.dumps(
                {"paths": {"/p": {"post": {"operationId": "doP"}}}}
            ),
        },
        {"kind": "graphql", "content": "type X { ref: Y }"},
        {"kind": "proto", "content": "service Z { rpc Go (A) returns (B); }"},
    ]
    assert contract_graph(specs) == contract_graph(specs)


def test_duplicate_specs_dedupe_nodes_and_edges() -> None:
    spec = {"kind": "graphql", "content": "type A { b: B }"}
    result = contract_graph([spec, spec])
    # Same nodes/edges from both copies -> deduped.
    edges = [e for e in result["edges"] if e["edge_type"] == "graphql_field"]
    assert len(edges) == 1
    a_nodes = [n for n in result["nodes"] if n["id"] == "gql:type:A"]
    assert len(a_nodes) == 1
    # by_kind still counts both accepted specs.
    assert result["by_kind"]["graphql"] == 2


# --- Fail-safe / malformed ------------------------------------------------


def test_malformed_openapi_json_skipped_no_raise() -> None:
    result = contract_graph([{"kind": "openapi", "content": "{not valid json"}])
    assert result["nodes"] == []
    assert result["edges"] == []
    # Failed parse is NOT counted as accepted.
    assert result["by_kind"]["openapi"] == 0


def test_openapi_non_object_json_skipped() -> None:
    # Valid JSON but not an object (a list) -> not an OpenAPI doc.
    result = contract_graph([{"kind": "openapi", "content": "[1, 2, 3]"}])
    assert result["nodes"] == []
    assert result["by_kind"]["openapi"] == 0


def test_openapi_object_without_paths_is_accepted_but_empty() -> None:
    # A well-formed object with no `paths` parses fine: accepted, zero nodes.
    result = contract_graph([{"kind": "openapi", "content": "{}"}])
    assert result["nodes"] == []
    assert result["by_kind"]["openapi"] == 1


def test_empty_content_skipped() -> None:
    result = contract_graph(
        [
            {"kind": "openapi", "content": ""},
            {"kind": "graphql", "content": "   "},
            {"kind": "proto", "content": "\n\n"},
        ]
    )
    assert result["nodes"] == []
    assert result["edges"] == []
    assert result["by_kind"] == {"openapi": 0, "graphql": 0, "proto": 0}


def test_unknown_kind_skipped() -> None:
    result = contract_graph([{"kind": "thrift", "content": "whatever"}])
    assert result["nodes"] == []
    assert result["by_kind"] == {"openapi": 0, "graphql": 0, "proto": 0}


def test_missing_content_skipped() -> None:
    result = contract_graph([{"kind": "graphql"}])
    assert result["nodes"] == []
    assert result["by_kind"]["graphql"] == 0


def test_graphql_with_no_type_blocks_not_accepted() -> None:
    # SDL that declares only a scalar / enum (no object `type`) -> not accepted.
    result = contract_graph([{"kind": "graphql", "content": "scalar DateTime"}])
    assert result["nodes"] == []
    assert result["by_kind"]["graphql"] == 0


def test_proto_with_no_service_not_accepted() -> None:
    result = contract_graph(
        [{"kind": "proto", "content": 'syntax = "proto3";\nmessage M { }'}]
    )
    assert result["nodes"] == []
    assert result["by_kind"]["proto"] == 0


def test_empty_input_is_safe() -> None:
    result = contract_graph([])
    assert result["nodes"] == []
    assert result["edges"] == []
    assert result["by_kind"] == {"openapi": 0, "graphql": 0, "proto": 0}


def test_non_list_specs_arg_is_safe() -> None:
    assert contract_graph(None)["nodes"] == []
    assert contract_graph("garbage")["edges"] == []
    assert contract_graph(42)["by_kind"] == {"openapi": 0, "graphql": 0, "proto": 0}
    # A dict, not a list -> safe empty.
    assert contract_graph({"kind": "openapi"})["nodes"] == []


def test_garbage_specs_in_list_skipped_no_raise() -> None:
    specs = [
        None,
        "not-a-dict",
        123,
        {"content": "type A { b: B }"},  # no kind -> skip
        {"kind": "graphql", "content": 999},  # non-str content -> skip
        {"kind": "graphql", "content": "type Real { ref: Other }"},  # the one real spec
    ]
    result = contract_graph(specs)
    nodes = _node_index(result)
    assert nodes.get("gql:type:Real") == "gql_type"
    assert ("gql:type:Real", "gql:type:Other", "graphql_field") in _edge_keys(result)
    assert result["by_kind"]["graphql"] == 1


if __name__ == "__main__":
    failures = 0
    for name, fn in sorted(globals().items()):
        if name.startswith("test_") and callable(fn):
            try:
                fn()
                print(f"PASS {name}")
            except AssertionError as exc:
                failures += 1
                print(f"FAIL {name}: {exc}")
            except Exception as exc:  # noqa: BLE001 — surface unexpected raises
                failures += 1
                print(f"ERROR {name}: {exc!r}")
    print(f"\n{'OK' if failures == 0 else 'FAILED'}: {failures} failure(s)")
    raise SystemExit(1 if failures else 0)
