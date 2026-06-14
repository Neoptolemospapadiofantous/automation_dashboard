#!/usr/bin/env python3
"""
hermes_domain.py — a domain-scoped Hermes (Increment 5: the split).

Runs ONE domain's slice of the audit: the tests + granular per-node checks its
manifest nodes declare, plus a doc check for those nodes — then a domain verdict
that still shows the OUTBOUND blast radius (the edges from this domain into
OTHER domains). It reads the same trunk (docs/hermes/manifest.json), so it's a
view over the shared graph, not a silo: `hermes:billing` is just this runner
scoped to the billing nodes, and it tells you what a billing failure threatens
elsewhere.

This is the split the whole tree was built toward — every domain is now an
independent, fast, focused Hermes, connected by construction.

Usage:
  python3 scripts/hermes_domain.py --list
  python3 scripts/hermes_domain.py --domain billing
Exit 1 if any in-scope check fails.
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
domains = M["domains"]
checkdefs = M.get("checks", {})

args = sys.argv[1:]

if "--list" in args or not args:
    print("Domains (each is its own hermes:<domain>):")
    for d, meta in domains.items():
        cnt = sum(1 for n in nodes.values() if n.get("domain") == d)
        print(f"  {d:9s} {cnt:2d} nodes — {meta['title']}")
    print("\nUsage: python3 scripts/hermes_domain.py --domain <name>")
    print("Note: security / doc-coverage are cross-cutting CHECKS, not domains — they span nodes.")
    sys.exit(0)

dom = args[args.index("--domain") + 1] if "--domain" in args and args.index("--domain") + 1 < len(args) else None
if dom not in domains:
    print(f"unknown domain: {dom!r}. Try --list.", file=sys.stderr)
    sys.exit(2)

scope = {k: n for k, n in nodes.items() if n.get("domain") == dom}

# Collect test targets: each granular check's target + each node's declared
# tests, deduped by path, labelled by the granular check where one maps.
targets = {}
for k, n in scope.items():
    for c in n.get("checks", []):
        if c in checkdefs:
            targets.setdefault(checkdefs[c]["target"], c)
    for t in n.get("tests", []):
        targets.setdefault(t, "test:" + os.path.basename(t).replace(".php", ""))
targets = {p: lbl for p, lbl in targets.items() if os.path.exists(p)}

print(f"== hermes:{dom} — {domains[dom]['title']} ==")
print(f"   {len(scope)} nodes: {', '.join(sorted(scope))}\n")

findings = []
for path, label in sorted(targets.items()):
    proc = subprocess.run(["php", "artisan", "test", path], capture_output=True, text=True)
    ok = proc.returncode == 0
    findings.append((label, "PASS" if ok else "FAIL"))
    print(f"  {'✅' if ok else '❌'} {label}  ({path})")

# doc check for the scope's nodes
missing = [d.split("#")[0] for k, n in scope.items() for d in n.get("docs", []) if not os.path.exists(d.split("#")[0])]
findings.append(("docs", "PASS" if not missing else "FAIL"))
print(f"  {'✅' if not missing else '❌'} docs" + (f"  (missing: {', '.join(missing)})" if missing else ""))

# outbound blast radius: edges from this domain's nodes into OTHER domains
outbound = {}
for k, n in scope.items():
    for e in n.get("edges", []):
        ed = nodes.get(e, {}).get("domain")
        if ed and ed != dom:
            outbound.setdefault(ed, set()).add(e)

fails = [lbl for lbl, st in findings if st == "FAIL"]
print()
if fails:
    print(f"hermes:{dom} → FAIL  ({len(fails)} of {len(findings)} checks)")
    for lbl in fails:
        print(f"  ❌ {lbl}")
    if outbound:
        print("  ⚠ connected — a failure here can threaten:")
        for ed, ns in sorted(outbound.items()):
            print(f"    → {ed}: {', '.join(sorted(ns))}")
    sys.exit(1)

print(f"hermes:{dom} → PASS  ({len(findings)} checks)")
print(f"  connects out to: {', '.join(sorted(outbound)) or '—'} (edges into other domains)")
sys.exit(0)
