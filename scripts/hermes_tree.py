#!/usr/bin/env python3
"""
hermes_tree.py — the tree runner (Increment 3 of the tree-structured Hermes).

Runs the manifest's granular, node-scoped checks (docs/hermes/manifest.json
"checks") for a single node, a domain, or the whole tree, and prints a
node-attached rollup so a failure localizes to the exact subsystem and shows
its blast radius (related nodes). This is the primitive the split is built on:
a future `hermes:billing` is just `hermes_tree.py --domain billing`.

Usage:
  python3 scripts/hermes_tree.py                      # all granular checks
  python3 scripts/hermes_tree.py --domain billing
  python3 scripts/hermes_tree.py --node app/Runtime/LLM
  python3 scripts/hermes_tree.py --emit               # TSV for hermes.sh to record

Exit 1 if any in-scope granular check fails.
"""
import json
import os
import subprocess
import sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(REPO)

with open("docs/hermes/manifest.json") as f:
    M = json.load(f)
nodes = M["subsystems"]
checkdefs = M.get("checks", {})

args = sys.argv[1:]
emit = "--emit" in args


def opt(name):
    return args[args.index(name) + 1] if name in args and args.index(name) + 1 < len(args) else None


sel_node, sel_domain = opt("--node"), opt("--domain")

if sel_node:
    if sel_node not in nodes:
        print(f"unknown node: {sel_node}", file=sys.stderr)
        sys.exit(2)
    scope = {sel_node}
elif sel_domain:
    scope = {k for k, n in nodes.items() if n.get("domain") == sel_domain}
    if not scope:
        print(f"unknown domain: {sel_domain}", file=sys.stderr)
        sys.exit(2)
else:
    scope = set(nodes)

# check -> the in-scope nodes that declare it (only checks with a runnable definition)
to_run = {}
for k in scope:
    for c in nodes[k].get("checks", []):
        if c in checkdefs:
            to_run.setdefault(c, []).append(k)


def log(*a):
    if not emit:
        print(*a)


if not to_run:
    log("No granular (defined) checks in scope — broad checks run via `composer hermes`.")
    sys.exit(0)

RANK = {"PASS": 0, "WARN": 1, "FAIL": 2}
INV = {0: "PASS", 1: "WARN", 2: "FAIL"}
results = {}

for check, owners in sorted(to_run.items()):
    d = checkdefs[check]
    target = d["target"]
    log(f"▶ {check}  ({d.get('desc', '')})  → {target}")
    proc = subprocess.run(
        ["php", "artisan", "test", target],
        capture_output=True, text=True,
    )
    ok = proc.returncode == 0
    results[check] = "PASS" if ok else "FAIL"
    detail = "ok" if ok else (proc.stdout.strip().splitlines()[-1] if proc.stdout.strip() else "failed")
    log(f"  {'✅' if ok else '❌'} {check}")
    if emit:
        # TSV: status<TAB>check<TAB>detail — hermes.sh records each line.
        print(f"{results[check]}\t{check}\t{detail}")

# ── node rollup within scope ───────────────────────────────────────────────
fails = []
log("\nScoped rollup (failures localize to the node):")
for k in sorted(scope):
    declared = [c for c in nodes[k].get("checks", []) if c in checkdefs]
    if not declared:
        continue
    worst = INV[max((RANK[results[c]] for c in declared if c in results), default=0)]
    log(f"  {'✅' if worst == 'PASS' else '❌'} {k}  [{nodes[k].get('domain')}/{nodes[k].get('criticality')}]"
        f"  checks: {', '.join(declared)}")
    if worst != "PASS":
        fails.append(k)

if fails:
    log("\nFAIL blast radius (related nodes the failure may threaten):")
    for k in fails:
        log(f"  ❌ {k} → {', '.join(nodes[k].get('edges', [])) or '—'}")
    sys.exit(1)

log("\nAll in-scope granular checks green.")
sys.exit(0)
