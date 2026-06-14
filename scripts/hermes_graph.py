#!/usr/bin/env python3
"""
hermes_graph.py — visualize the Hermes project manifest (the shared "trunk").

Reads docs/hermes/manifest.json and prints the domain tree, each subsystem's
context (docs / tests / checks / edges / criticality), and validates that every
edge resolves to a real node. With `--node app/Billing` it prints one node's
full context plus its immediate neighbours (the connection view).

Increment 1 of the tree-structured Hermes design (see docs/hermes/README.md):
this makes the trunk visible. Later increments thread live findings through the
same nodes so each Hermes reads/writes its slice with full context.

Usage:
  python3 scripts/hermes_graph.py                 # the whole tree + validation
  python3 scripts/hermes_graph.py --node app/Billing
"""
import json
import os
import sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(REPO)

with open("docs/hermes/manifest.json") as f:
    M = json.load(f)
domains = M["domains"]
nodes = M["subsystems"]

CRIT = {"high": "●", "medium": "◐", "low": "○"}


def docs_of(n):
    if n.get("docs"):
        return n["docs"]
    if "waived" in n:
        return [f"(waived: {n['waived']})"]
    return ["—"]


def print_node(key, n, indent="    "):
    print(f"{indent}{CRIT.get(n.get('criticality', 'medium'), '·')} {key}")
    print(f"{indent}    docs  : {', '.join(docs_of(n))}")
    if n.get("tests"):
        print(f"{indent}    tests : {', '.join(n['tests'])}")
    print(f"{indent}    checks: {', '.join(n.get('checks', []))}")
    if n.get("edges"):
        print(f"{indent}    edges→: {', '.join(n['edges'])}")


# ── single-node connection view ────────────────────────────────────────────
if len(sys.argv) >= 3 and sys.argv[1] == "--node":
    key = sys.argv[2]
    if key not in nodes:
        print(f"unknown node: {key}")
        sys.exit(2)
    n = nodes[key]
    print(f"== {key}   [domain: {n.get('domain')}]   criticality: {n.get('criticality')} ==\n")
    print_node(key, n, indent="")
    print("\n  Connections (context for accurate findings):")
    for e in n.get("edges", []):
        print(f"    →  {e}   [{nodes.get(e, {}).get('domain', '?')}]")
    inbound = [k for k, v in nodes.items() if key in v.get("edges", [])]
    for k in inbound:
        print(f"    ←  {k}   [{nodes[k].get('domain', '?')}]")
    sys.exit(0)

# ── full tree ──────────────────────────────────────────────────────────────
print("Hermes manifest — project graph (the trunk)\n")
by_domain = {}
for k, n in nodes.items():
    by_domain.setdefault(n.get("domain", "?"), []).append(k)

for d, meta in domains.items():
    keys = sorted(by_domain.get(d, []))
    print(f"┌─ {d}  —  {meta['title']}  ({len(keys)} nodes)")
    for k in keys:
        print_node(k, nodes[k])
    print()

# ── validation ─────────────────────────────────────────────────────────────
problems = []
for d in (set(by_domain) - set(domains)):
    problems.append(f"nodes use undeclared domain '{d}': {sorted(by_domain[d])}")
dangling = [(k, e) for k, n in nodes.items() for e in n.get("edges", []) if e not in nodes]

crit_counts = {}
for n in nodes.values():
    crit_counts[n.get("criticality", "?")] = crit_counts.get(n.get("criticality", "?"), 0) + 1

print(f"Summary: {len(nodes)} nodes · {len(domains)} domains · {len(M.get('documents', []))} canonical docs")
print("Criticality: " + ", ".join(f"{k}={v}" for k, v in sorted(crit_counts.items())))

if dangling:
    problems.append(f"{len(dangling)} dangling edge(s): " + "; ".join(f"{s}→{d}" for s, d in dangling))

if problems:
    print("\nFAILED:")
    for p in problems:
        print("  " + p)
    sys.exit(1)

print("Edges: all resolve to real nodes ✓")
sys.exit(0)
