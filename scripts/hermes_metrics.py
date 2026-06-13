#!/usr/bin/env python3
"""
hermes_metrics.py — does the audit system actually move the needle?

Mines git history (git IS the ledger) for direction-aware quality KPIs at daily
snapshots, charts the trajectory, and FLAGS REGRESSIONS. Writes the report
docs/hermes/effectiveness.md and the structured data/hermes_metrics.json the
local /hermes-metrics page renders. Periodic by design (2026-06-14 decision):
run at milestones, not per-run.

KPIs (good direction):
  - phpstan baseline  (suppressed issues)        ↓
  - debt density      (baseline / app php files) ↓  — the headline
  - escape rate       (reactive prod bugfixes %)  ↓
  - catch ratio       (catches / (catches+escapes) %) ↑  — is the audit catching bugs?
  - TODO/FIXME        (markers in app/)            ↓  — inline tech-debt
  - test files        (tests/**/*Test.php)         ↑
  - docs              (docs/**/*.md)                ↑
  - untested nodes    (manifest nodes w/ no tests)  ↓  — current badge (forward-only)

Escape vs catch (heuristic on commit subjects):
  escape = a fix-type commit touching app/|resources/ NOT attributed to the audit.
  catch  = a fix attributed to the audit ("audit"/"surfaced"/"hermes") — the system
           working, counted positively (so finding bugs never hurts the score).
Until releases are tagged, escape rate is a firefighting proxy, not a true
production-escape rate (noted in the report).

Usage: python3 scripts/hermes_metrics.py   (composer hermes-metrics)
"""
import json
import os
import re
import subprocess
import sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(REPO)


def git(*a):
    return subprocess.run(["git", *a], capture_output=True, text=True).stdout


def count(blob, pattern):
    return sum(1 for ln in blob.splitlines() if pattern in ln)


# ── classify every commit as escape / catch / neither ──────────────────────
FIX = re.compile(r"(?i)^\s*(fix|hotfix|bugfix|patch)\b|^\s*fix\(")
AUDIT = re.compile(r"(?i)\baudit\b|\bsurfaced\b|\bhermes\b")

commits = []
cur = None
for ln in git("log", "--reverse", "--date=short", "--name-only", "--pretty=format:@@@|%h|%ad|%s").splitlines():
    if ln.startswith("@@@|"):
        if cur:
            commits.append(cur)
        _, sha, date, subj = ln.split("|", 3)
        cur = {"date": date, "subj": subj, "files": []}
    elif ln.strip() and cur is not None:
        cur["files"].append(ln.strip())
if cur:
    commits.append(cur)

for c in commits:
    prod = any(f.startswith("app/") or f.startswith("resources/") for f in c["files"])
    is_fix = bool(FIX.search(c["subj"]))
    is_audit = bool(AUDIT.search(c["subj"]))
    c["escape"] = is_fix and prod and not is_audit
    c["catch"] = is_fix and is_audit


def cum_at(date):
    n = e = k = 0
    for c in commits:
        if c["date"] <= date:
            n += 1
            e += c["escape"]
            k += c["catch"]
    return n, e, k


# ── daily snapshots ─────────────────────────────────────────────────────────
rows = [ln.split("|") for ln in git("log", "--reverse", "--pretty=%h|%ad", "--date=short").splitlines() if "|" in ln]
last_of_day = {}
for sha, date in rows:
    last_of_day[date] = sha

snaps = []
for date in sorted(last_of_day):
    sha = last_of_day[date]
    tree = git("ls-tree", "-r", "--name-only", sha)
    base = count(git("show", f"{sha}:phpstan-baseline.neon"), "message:")
    tests = sum(1 for f in tree.splitlines() if f.startswith("tests/") and f.endswith("Test.php"))
    docs = sum(1 for f in tree.splitlines() if f.startswith("docs/") and f.endswith(".md"))
    php = sum(1 for f in tree.splitlines() if f.startswith("app/") and f.endswith(".php"))
    todos = sum(1 for ln in git("grep", "-I", "-E", "TODO|FIXME|HACK|TBD", sha, "--", "app").splitlines() if ln.strip())
    n, e, k = cum_at(date)
    snaps.append({
        "date": date, "base": base, "tests": tests, "docs": docs, "php": php, "todos": todos,
        "density": round(base / php, 2) if php else 0.0,
        "escapes": e, "catches": k, "commits": n,
        "esc_rate": round(e / n * 100, 1) if n else 0.0,
        "catch_ratio": round(k / (k + e) * 100, 1) if (k + e) else 0.0,
    })

if len(snaps) < 2:
    print("hermes_metrics: not enough history")
    sys.exit(0)

first, last = snaps[0], snaps[-1]
base_start = next((s for s in snaps if s["base"] > 0), first)
peak_esc = max(s["esc_rate"] for s in snaps)
# catch ratio's baseline is the first snapshot where any bug-fix existed (day-1's
# 0% — no fixes yet — isn't a meaningful starting point).
cr_start = next((s for s in snaps if (s["catches"] + s["escapes"]) > 0), first)

# ── untested nodes (current, from the manifest) ────────────────────────────
with open("docs/hermes/manifest.json") as fh:
    mnodes = json.load(fh)["subsystems"]
untested = sorted(k for k, nd in mnodes.items() if not nd.get("tests") and "waived" not in nd)


def pct(a, b):
    # relative % when there's a non-zero baseline; absolute "+N" when growing from 0
    if a == 0:
        return f"+{b:g}" if b else "0"
    return f"{(b - a) / a * 100:+.0f}%"


def pp(a, b):
    # point delta for percentage KPIs (catch ratio) — +Xpp reads better than relative %
    return f"{b - a:+.1f}pp"


# ── regression check: latest vs previous snapshot ──────────────────────────
prev, cur = snaps[-2], snaps[-1]
regressions = []
if cur["base"] > prev["base"]:
    regressions.append(f"phpstan baseline grew {prev['base']}→{cur['base']} (+{cur['base']-prev['base']} suppressed issues)")
if cur["density"] > prev["density"]:
    regressions.append(f"debt density rose {prev['density']}→{cur['density']} (more suppressed issues per file)")
if cur["tests"] < prev["tests"] and cur["php"] >= prev["php"]:
    regressions.append(f"test files dropped {prev['tests']}→{cur['tests']} while app code did NOT shrink — coverage loss")
if cur["docs"] < prev["docs"]:
    regressions.append(f"docs dropped {prev['docs']}→{cur['docs']}")
if cur["esc_rate"] > prev["esc_rate"]:
    regressions.append(f"escape rate rose {prev['esc_rate']}%→{cur['esc_rate']}% (more reactive prod bugfixes)")
if cur["todos"] > prev["todos"]:
    regressions.append(f"TODO/FIXME markers rose {prev['todos']}→{cur['todos']}")
if cur["catch_ratio"] < prev["catch_ratio"] and (cur["catches"] + cur["escapes"]) > (prev["catches"] + prev["escapes"]):
    regressions.append(f"catch ratio fell {prev['catch_ratio']}%→{cur['catch_ratio']}% (audit caught a smaller share of new bugs)")


def chart(title, key, hint):
    labels = ", ".join(f'"{s["date"][5:]}"' for s in snaps)
    vals = [s[key] for s in snaps]
    ymax = max(vals) + max(1, int(max(vals) * 0.1)) if max(vals) else 1
    return (f"```mermaid\nxychart-beta\n"
            f'    title "{title}  ({hint})"\n'
            f"    x-axis [{labels}]\n"
            f'    y-axis "{key}" 0 --> {ymax}\n'
            f"    line [{', '.join(str(v) for v in vals)}]\n```")


L = []
def w(s=""):
    L.append(s)

w("---")
w("type: ops")
w("tags: [hermes, metrics, effectiveness]")
w("---")
w()
w("# Hermes — effectiveness")
w()
w("> Auto-generated by `scripts/hermes_metrics.py` (`composer hermes-metrics`). Does the audit")
w("> system actually improve the codebase? Mines git history for direction-aware quality KPIs")
w("> and flags regressions. Periodic, not per-run (see the 2026-06-14 decision).")
w()
w("## Strategy — what 'improvement' means")
w()
w("| KPI | Source | Good direction | A win looks like |")
w("|---|---|---|---|")
w("| PHPStan baseline | `phpstan-baseline.neon` | **↓** | suppressed-debt shrinks as you fix it |")
w("| Debt density | baseline ÷ `app/*.php` | **↓** | **debt falls even while the code grows** — the real signal |")
w("| Escape rate | reactive prod bugfixes ÷ commits | **↓** | less firefighting per unit of work |")
w("| Catch ratio | catches ÷ (catches + escapes) | **↑** | the audit catches a bigger share of bugs pre-merge |")
w("| TODO/FIXME | markers in `app/` | **↓** | inline tech-debt paid down |")
w("| Test files | `tests/**/*Test.php` | **↑** | coverage grows with features |")
w("| Docs | `docs/**/*.md` | **↑** | the doc-coverage gate keeps it climbing |")
w()
w("Headline test = **debt density**: suppressed issues per file falling while the code grows")
w("means the gates are net-positive. **Catch ratio** answers the other half — *is the audit")
w("catching bugs before they ship?*")
w()
w("> **Escape vs catch:** a `fix`-type commit touching `app/`/`resources/` is an *escape* unless")
w("> it's attributed to the audit (`fix: audit-driven…`, 'surfaced by the audit') — those are")
w("> *catches* (a positive). Until releases are tagged, escape rate is a *firefighting* proxy,")
w("> not a true production-escape rate.")
w()
w(f"## Headline ({base_start['date']} → {last['date']})")
w()
w("| KPI | Start | Now | Δ |")
w("|---|---|---|---|")
w(f"| PHPStan baseline | {base_start['base']} | {last['base']} | **{pct(base_start['base'], last['base'])}** |")
w(f"| Debt density | {base_start['density']} | {last['density']} | **{pct(base_start['density'], last['density'])}** |")
w(f"| Escape rate (peak → now) | {peak_esc}% | {last['esc_rate']}% | **{pct(peak_esc, last['esc_rate'])}** |")
w(f"| Catch ratio | {cr_start['catch_ratio']}% | {last['catch_ratio']}% | {pp(cr_start['catch_ratio'], last['catch_ratio'])} |")
w(f"| TODO/FIXME | {first['todos']} | {last['todos']} | {pct(first['todos'], last['todos'])} |")
w(f"| Test files | {first['tests']} | {last['tests']} | {pct(first['tests'], last['tests'])} |")
w(f"| Docs | {first['docs']} | {last['docs']} | {pct(first['docs'], last['docs'])} |")
w(f"| App PHP files | {first['php']} | {last['php']} | {pct(first['php'], last['php'])} |")
w()
w(f"Escapes: **{last['escapes']}** · catches (audit-found pre-merge): **{last['catches']}** · "
  f"untested subsystems: **{len(untested)}**" + (f" ({', '.join(untested)})" if untested else ""))
w()
w("## Charts")
w()
w(chart("PHPStan baseline — suppressed issues", "base", "down = better"))
w()
w(chart("Debt density — suppressed issues per app file", "density", "down = better"))
w()
w(chart("Escape rate — reactive prod bugfixes per commit (%)", "esc_rate", "down = better"))
w()
w(chart("Catch ratio — share of bugs the audit caught (%)", "catch_ratio", "up = better"))
w()
w(chart("TODO/FIXME markers in app/", "todos", "down = better"))
w()
w(chart("Test files", "tests", "up = better"))
w()
w(chart("Docs", "docs", "up = better"))
w()
w("## Regression check (latest vs previous snapshot)")
w()
if regressions:
    w("⚠️ **Regressions detected:**")
    for r in regressions:
        w(f"- {r}")
else:
    w("✅ No regressions — no KPI moved against its good direction in the latest snapshot.")
w()
w("## Data")
w()
w("| date | baseline | density | tests | docs | todos | escapes | esc% | catches | catch% | app php |")
w("|---|---|---|---|---|---|---|---|---|---|---|")
for s in snaps:
    w(f"| {s['date']} | {s['base']} | {s['density']} | {s['tests']} | {s['docs']} | {s['todos']} | "
      f"{s['escapes']} | {s['esc_rate']}% | {s['catches']} | {s['catch_ratio']}% | {s['php']} |")
w()

os.makedirs("docs/hermes", exist_ok=True)
with open("docs/hermes/effectiveness.md", "w") as fh:
    fh.write("\n".join(L) + "\n")

# ── structured data for /hermes-metrics ────────────────────────────────────
metrics = {
    "range": {"start": base_start["date"], "end": last["date"]},
    "escapes": last["escapes"],
    "catches": last["catches"],
    "untested": untested,
    "regressions": regressions,
    "headline": [
        {"kpi": "PHPStan baseline", "key": "base", "start": base_start["base"], "now": last["base"], "delta": pct(base_start["base"], last["base"]), "dir": "down"},
        {"kpi": "Debt density", "key": "density", "start": base_start["density"], "now": last["density"], "delta": pct(base_start["density"], last["density"]), "dir": "down"},
        {"kpi": "Escape rate", "key": "esc_rate", "start": peak_esc, "now": last["esc_rate"], "delta": pct(peak_esc, last["esc_rate"]), "dir": "down", "unit": "%"},
        {"kpi": "Catch ratio", "key": "catch_ratio", "start": cr_start["catch_ratio"], "now": last["catch_ratio"], "delta": pct(cr_start["catch_ratio"], last["catch_ratio"]), "dir": "up", "unit": "%"},
        {"kpi": "TODO / FIXME", "key": "todos", "start": first["todos"], "now": last["todos"], "delta": pct(first["todos"], last["todos"]), "dir": "down"},
        {"kpi": "Test files", "key": "tests", "start": first["tests"], "now": last["tests"], "delta": pct(first["tests"], last["tests"]), "dir": "up"},
        {"kpi": "Docs", "key": "docs", "start": first["docs"], "now": last["docs"], "delta": pct(first["docs"], last["docs"]), "dir": "up"},
    ],
    "series": snaps,
}
os.makedirs("data", exist_ok=True)
with open("data/hermes_metrics.json", "w") as fh:
    json.dump(metrics, fh, indent=2)

print(f"Hermes effectiveness ({base_start['date']} → {last['date']}):")
print(f"  PHPStan baseline : {base_start['base']} → {last['base']}  ({pct(base_start['base'], last['base'])})  [down=better]")
print(f"  Debt density     : {base_start['density']} → {last['density']}  ({pct(base_start['density'], last['density'])})  [down=better]")
print(f"  Escape rate      : peak {peak_esc}% → {last['esc_rate']}%  ({pct(peak_esc, last['esc_rate'])})  [down=better]")
print(f"  Catch ratio      : {cr_start['catch_ratio']}% → {last['catch_ratio']}%  ({pp(cr_start['catch_ratio'], last['catch_ratio'])})  [up=better]")
print(f"  TODO/FIXME       : {first['todos']} → {last['todos']}  ({pct(first['todos'], last['todos'])})  [down=better]")
print(f"  Test files       : {first['tests']} → {last['tests']}  ({pct(first['tests'], last['tests'])})")
print(f"  Docs             : {first['docs']} → {last['docs']}  ({pct(first['docs'], last['docs'])})")
print(f"  Escapes {last['escapes']} · catches {last['catches']} · untested nodes {len(untested)}")
if regressions:
    print("  ⚠️ regressions:")
    for r in regressions:
        print(f"    - {r}")
else:
    print("  ✅ no regressions in the latest snapshot")
print("  report → docs/hermes/effectiveness.md · data → data/hermes_metrics.json")
sys.exit(0)
