#!/usr/bin/env python3
"""
hermes_synthesis.py — the root synthesis node (Increment 4 of the tree).

The deterministic checks (branches) produce a manifest-enriched findings graph.
This root reads that graph (data/hermes_findings.json) + the manifest trunk and
assembles a CONTEXT-AWARE verdict: findings ranked by node criticality and
blast radius, grouped by domain, each carrying the related nodes a failure
threatens, the docs that explain it and the tests that guard it — plus coverage
gaps (granular checks still folded into broad ones, untested nodes). Facts,
assembled with context.

It also writes a ready-to-send LLM prompt so the LLM synthesis node (the
`/hermes-synthesis` Claude session) can produce the narrative verdict without
re-deriving any context. Branches = facts; root = the prioritized story.

Outputs:
  stdout                          — concise brief (what hermes.sh prints)
  data/hermes_synthesis.md        — the full context brief
  data/hermes_synthesis_prompt.md — the prompt for the LLM node
Always exit 0 — synthesis is advisory, it never changes the verdict.
"""
import json
import os
import sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(REPO)

F = "data/hermes_findings.json"
if not os.path.exists(F) or not os.path.exists("docs/hermes/manifest.json"):
    print("hermes_synthesis: no findings graph yet (run hermes first)")
    sys.exit(0)

with open(F) as fh:
    data = json.load(fh)
with open("docs/hermes/manifest.json") as fh:
    M = json.load(fh)
nodes = M["subsystems"]
graph = data.get("graph", {})
node_roll = graph.get("nodes", {})
domain_roll = graph.get("domains", {})
findings = data.get("findings", [])

CRIT_W = {"high": 3, "medium": 2, "low": 1}
RANK = {"PASS": 0, "WARN": 1, "FAIL": 2}


def risk_key(f):
    crit = max((CRIT_W.get(nodes.get(n, {}).get("criticality"), 0) for n in f.get("nodes", [])), default=0)
    blast = len(f.get("related", []))
    return (-RANK.get(f["status"], 0), -crit, -blast)


ranked = sorted(findings, key=risk_key)
problems = [f for f in ranked if f["status"] != "PASS"]

lines = []
def w(s=""):
    lines.append(s)

overall = data.get("overall", "?")
w(f"# Hermes synthesis — {overall}")
w(f"{data.get('pass', 0)} PASS · {data.get('fail', 0)} FAIL · {data.get('warn', 0)} WARN · {data.get('ts', '')}")
w()

w("## Domain health")
for d, r in sorted(domain_roll.items()):
    w(f"- `{r['status']:4s}` {d}  ({r['nodes']} nodes)")
w()

if problems:
    w("## Problems — ranked by criticality + blast radius")
    for f in problems:
        w(f"### {f['status']}  `{f['check']}`")
        w(f"- detail: {f.get('detail', '')}")
        w(f"- nodes: {', '.join(f.get('nodes', [])) or f.get('scope', 'repo')}")
        if f.get("domains"):
            w(f"- domains: {', '.join(f['domains'])}")
        if f.get("related"):
            w(f"- blast radius (related nodes): {', '.join(f['related'])}")
        if f.get("refs"):
            w(f"- where to look (docs): {', '.join(f['refs'])}")
        w()
else:
    w("## Problems\nNone — every node green across all domains.\n")

# coverage gaps — the honest TODO the synthesis surfaces even on a green run
pending = {}
for k, r in node_roll.items():
    for c in r.get("checks_pending", []):
        pending.setdefault(c, []).append(k)
untested = sorted(k for k, n in nodes.items() if not n.get("tests") and "waived" not in n)
if pending or untested:
    w("## Coverage gaps")
    for c, owners in sorted(pending.items()):
        w(f"- granular check `{c}` is declared by {', '.join(owners)} but folded into a broad check — make it standalone (split TODO)")
    if untested:
        w(f"- nodes with no tests listed in the manifest: {', '.join(untested)}")
    w()

brief = "\n".join(lines)
os.makedirs("data", exist_ok=True)
with open("data/hermes_synthesis.md", "w") as fh:
    fh.write(brief)

# ── the LLM prompt (the narrative the root produces when an LLM runs) ───────
prompt = f"""You are the Hermes synthesis node — the root of a tree of deterministic checks
over this codebase. Below is the manifest-enriched findings graph from the latest
run. Produce a SHORT, prioritised, context-aware verdict for an engineer:

1. Bottom line: ship / investigate / blocked — and why, in one line.
2. If there are problems, order them by REAL risk: weight node criticality and
   blast radius (a FAIL on a high-criticality node whose edges fan into other
   domains matters more than an isolated low one). For each: what it threatens
   (walk its related nodes) and where to look first (its docs/tests).
3. Note the coverage gaps worth closing (the pending granular checks / untested
   nodes) — but don't dwell if the run is green.
4. Be terse. The facts are below; your job is the priority and the story, not to
   re-list every finding.

=== FINDINGS GRAPH (manifest-enriched) ===
{brief}
=== MANIFEST DOMAINS ===
{json.dumps(M['domains'], indent=2)}
"""
with open("data/hermes_synthesis_prompt.md", "w") as fh:
    fh.write(prompt)

# ── concise stdout (what shows in a hermes run) ────────────────────────────
gaps = sum(len(v) for v in pending.values())
print(f"Synthesis: {overall} — {len(problems)} problem(s) · {len(domain_roll)} domains · {gaps} pending granular checks")
for f in problems[:5]:
    rad = f"  ⇒ {', '.join(f['related'])}" if f.get("related") else ""
    print(f"  {f['status']} {f['check']} → {', '.join(f.get('nodes', [])) or f.get('scope', 'repo')}{rad}")
print("  brief → data/hermes_synthesis.md · LLM prompt → data/hermes_synthesis_prompt.md (run /hermes-synthesis)")
sys.exit(0)
