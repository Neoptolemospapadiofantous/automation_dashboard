#!/usr/bin/env python3
"""
Doc-coverage gate (Hermes check).

Rule: every directory under app/ that directly contains PHP files must be
registered in docs/coverage.json — either pointing at a doc that explains it
(`"doc"`) or explicitly waived with a reason (`"waived"`). This keeps the
docs honest as the app grows: add a new subsystem and CI fails until you
either document it or consciously waive it.

Fails (exit 1) on:
  - UNREGISTERED: an app/ php subsystem not in the registry (new, undocumented)
  - STALE:        a registry entry whose dir is gone / no longer has php
  - MISSING DOC:  a registered doc file that doesn't exist on disk
  - INVALID:      an entry with neither "doc" nor "waived"
"""
import json
import os
import sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(REPO)

with open("docs/coverage.json") as f:
    registry = json.load(f)["subsystems"]

# A "subsystem" is any directory under app/ that directly holds ≥1 .php file.
actual = set()
for root, _dirs, files in os.walk("app"):
    if any(fn.endswith(".php") for fn in files):
        actual.add(root.replace(os.sep, "/"))

problems = []

for path in sorted(actual):
    if path not in registry:
        problems.append(
            f"UNREGISTERED  {path} — add to docs/coverage.json (a \"doc\" path, or \"waived\" with a reason)"
        )

for path, entry in sorted(registry.items()):
    if path not in actual:
        problems.append(f"STALE         {path} — no longer a php subsystem; remove from docs/coverage.json")
        continue
    doc = entry.get("doc")
    if doc:
        docfile = doc.split("#")[0]
        if not os.path.exists(docfile):
            problems.append(f"MISSING DOC   {path} -> {docfile} (registered doc does not exist)")
    elif "waived" not in entry:
        problems.append(f"INVALID       {path} — entry needs either \"doc\" or \"waived\"")

if problems:
    print("Doc-coverage gate FAILED:")
    for p in problems:
        print("  " + p)
    print(f"\n{len(actual)} php subsystems · {len(problems)} problem(s).")
    sys.exit(1)

print(f"Doc-coverage OK — {len(actual)} app/ subsystems all registered (documented or waived).")
sys.exit(0)
