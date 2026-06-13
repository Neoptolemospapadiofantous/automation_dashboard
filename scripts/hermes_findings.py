#!/usr/bin/env python3
"""
hermes_findings.py — enrich raw Hermes findings with manifest context.

Reads data/hermes_findings.json (the flat {check,status,detail} list hermes.sh
writes) and docs/hermes/manifest.json (the trunk), then rewrites the findings
file as a node-aware graph:

  - every finding is tagged with the manifest nodes its check covers, their
    domains, their edges (`related` — the blast radius), and their doc `refs`;
  - a per-node and per-domain status rollup is added under "graph".

This is how a finding stays accurate in cross-domain context: a failing check
fans out to the nodes it touches and their neighbours, so you see what a
failure actually threatens — not just that "tests failed".

Increment 2 of the tree-structured Hermes design (see docs/hermes/README.md).
Runs after hermes.sh writes the raw findings; never fails the build (pure
annotation).
"""
import json
import os
import sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(REPO)

FINDINGS = "data/hermes_findings.json"
RANK = {"PASS": 0, "WARN": 1, "FAIL": 2}
INV = {v: k for k, v in RANK.items()}
ICON = {"PASS": "✅", "WARN": "⚠️", "FAIL": "❌"}

if not os.path.exists(FINDINGS) or not os.path.exists("docs/hermes/manifest.json"):
    print("hermes_findings: nothing to enrich (missing findings or manifest)")
    sys.exit(0)

with open(FINDINGS) as f:
    data = json.load(f)
with open("docs/hermes/manifest.json") as f:
    nodes = json.load(f)["subsystems"]

# check name -> the manifest nodes that declare it
status_by_check = {}
for fnd in data.get("findings", []):
    status_by_check.setdefault(fnd["check"], []).append(fnd["status"])


def covered_by(check):
    return [k for k, n in nodes.items() if check in n.get("checks", [])]


# ── enrich each finding ────────────────────────────────────────────────────
for fnd in data.get("findings", []):
    covered = covered_by(fnd["check"])
    if not covered:
        fnd["scope"] = "repo"  # global infra check (pint, build, composer-audit, knip…)
        continue
    fnd["scope"] = "nodes"
    fnd["nodes"] = covered
    fnd["domains"] = sorted({nodes[k].get("domain", "?") for k in covered})
    related, refs = set(), set()
    for k in covered:
        related.update(nodes[k].get("edges", []))
        refs.update(nodes[k].get("docs", []))
    fnd["related"] = sorted(related - set(covered))
    fnd["refs"] = sorted(refs)

# ── per-node rollup: worst status among findings whose check the node declares ─
node_roll = {}
for k, n in nodes.items():
    declared = n.get("checks", [])
    statuses = [s for c in declared for s in status_by_check.get(c, [])]
    node_roll[k] = {
        "status": INV[max((RANK[s] for s in statuses), default=0)],
        "domain": n.get("domain"),
        "criticality": n.get("criticality"),
        "checks_run": [c for c in declared if c in status_by_check],
        "checks_pending": [c for c in declared if c not in status_by_check],
        "related": sorted(set(n.get("edges", []))),
    }

# ── per-domain rollup ──────────────────────────────────────────────────────
domain_roll = {}
for r in node_roll.values():
    cur = domain_roll.setdefault(r["domain"], {"status": "PASS", "nodes": 0})
    cur["nodes"] += 1
    if RANK[r["status"]] > RANK[cur["status"]]:
        cur["status"] = r["status"]

data["graph"] = {"domains": domain_roll, "nodes": node_roll}
with open(FINDINGS, "w") as f:
    json.dump(data, f, indent=2)

# ── operator-facing rollup ─────────────────────────────────────────────────
print("Findings graph (manifest-enriched):")
for d, r in sorted(domain_roll.items()):
    print(f"  {ICON.get(r['status'], '·')} {d:9s} {r['status']:4s} ({r['nodes']} nodes)")

bad = {k: r for k, r in node_roll.items() if r["status"] != "PASS"}
if bad:
    print("  affected nodes + blast radius (related nodes):")
    for k, r in sorted(bad.items(), key=lambda kv: -RANK[kv[1]["status"]]):
        print(f"    {ICON[r['status']]} {k}  [{r['domain']}/{r['criticality']}]  → {', '.join(r['related']) or '—'}")
else:
    print(f"  all {len(domain_roll)} domains green; {len(node_roll)} nodes attached to findings.")
sys.exit(0)
