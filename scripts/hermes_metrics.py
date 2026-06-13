#!/usr/bin/env python3
"""
hermes_metrics.py — does the audit system actually move the needle?

Mines the git history (git IS the ledger) for direction-aware quality KPIs at
daily snapshots, charts the trajectory, and FLAGS REGRESSIONS (a KPI moving the
wrong way). Writes docs/hermes/effectiveness.md — the "is Hermes working?"
report. Periodic by design (see the 2026-06-14 learning-cadence decision): run
it at milestones, not on every commit.

KPIs (with their "good" direction):
  - phpstan baseline  (suppressed issues)        ↓  debt paid down
  - debt density      (baseline / app php files) ↓  debt per file — the headline
  - test files        (tests/**/*Test.php)        ↑  coverage breadth
  - docs              (docs/**/*.md)               ↑  documentation
  - escape rate       (reactive prod bugfixes %)   ↓  firefighting share
  - (context) catches — bugs the audit FOUND pre-merge — a positive, shown alongside

Escape vs catch (heuristic on commit subjects, honest about its limits):
  - escape = subject is a fix (fix/hotfix/bugfix/patch…) that changed app/ or
    resources/ AND is NOT attributed to the audit ("audit"/"surfaced"/"hermes").
    Until releases are tagged this is the *reactive-fix / firefighting* rate, not
    a true production-escape rate — see the report's note.
  - catch  = a fix attributed to the audit → the system working, counted positively.

Usage: python3 scripts/hermes_metrics.py   (composer hermes-metrics)
"""
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
AUDIT = re.compile(r"(?i)\baudit|surfaced|hermes\b")

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
    """cumulative (commits, escapes, catches) up to & including a date."""
    n = e = k = 0
    for c in commits:
        if c["date"] <= date:
            n += 1
            e += c["escape"]
            k += c["catch"]
    return n, e, k


# ── daily snapshots of the file-tree KPIs ──────────────────────────────────
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
    n, e, k = cum_at(date)
    snaps.append({
        "date": date, "base": base, "tests": tests, "docs": docs, "php": php,
        "density": round(base / php, 2) if php else 0.0,
        "escapes": e, "catches": k, "commits": n,
        "esc_rate": round(e / n * 100, 1) if n else 0.0,
    })

if len(snaps) < 2:
    print("hermes_metrics: not enough history")
    sys.exit(0)

first, last = snaps[0], snaps[-1]
base_start = next((s for s in snaps if s["base"] > 0), first)
peak_esc = max(s["esc_rate"] for s in snaps)  # escape rate's honest baseline is its peak, not day-1's 0%


def pct(a, b):
    return f"{(b - a) / a * 100:+.0f}%" if a else "n/a"


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
w("| Test files | `tests/**/*Test.php` | **↑** | coverage grows with features |")
w("| Docs | `docs/**/*.md` | **↑** | the doc-coverage gate keeps it climbing |")
w("| Escape rate | reactive prod bugfixes ÷ commits | **↓** | less firefighting per unit of work |")
w()
w("Headline test = **debt density**: suppressed issues per file falling while the code grows")
w("means the gates are net-positive. **Escape rate** is the lagging signal — the share of work")
w("that is fixing bugs in shipped code the audit *didn't* catch.")
w()
w("> **On escape rate:** detected heuristically — a `fix`-type commit touching `app/`/`resources/`")
w("> that isn't attributed to the audit. Bugs the audit *found* pre-merge are counted as")
w("> **catches** (a positive), not escapes. Until releases are tagged this is really a")
w("> *reactive-fix / firefighting* rate; it becomes a true production-escape rate once there's a")
w("> release boundary. To force classification, mention the audit in a fix subject (→ catch).")
w()
w(f"## Headline ({base_start['date']} → {last['date']})")
w()
w("| KPI | Start | Now | Δ |")
w("|---|---|---|---|")
w(f"| PHPStan baseline | {base_start['base']} | {last['base']} | **{pct(base_start['base'], last['base'])}** |")
w(f"| Debt density | {base_start['density']} | {last['density']} | **{pct(base_start['density'], last['density'])}** |")
w(f"| Test files | {first['tests']} | {last['tests']} | {pct(first['tests'], last['tests'])} |")
w(f"| Docs | {first['docs']} | {last['docs']} | {pct(first['docs'], last['docs'])} |")
w(f"| Escape rate (peak → now) | {peak_esc}% | {last['esc_rate']}% | **{pct(peak_esc, last['esc_rate'])}** |")
w(f"| App PHP files | {first['php']} | {last['php']} | {pct(first['php'], last['php'])} |")
w()
w(f"Reactive prod bugfixes (escapes): **{last['escapes']}** across {last['commits']} commits. "
  f"Bugs the audit caught pre-merge (catches): **{last['catches']}**.")
w()
w("## Charts")
w()
w(chart("PHPStan baseline — suppressed issues", "base", "down = better"))
w()
w(chart("Debt density — suppressed issues per app file", "density", "down = better"))
w()
w(chart("Escape rate — reactive prod bugfixes per commit (%)", "esc_rate", "down = better"))
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
w("| date | baseline | density | tests | docs | escapes | esc% | catches | app php |")
w("|---|---|---|---|---|---|---|---|---|")
for s in snaps:
    w(f"| {s['date']} | {s['base']} | {s['density']} | {s['tests']} | {s['docs']} | "
      f"{s['escapes']} | {s['esc_rate']}% | {s['catches']} | {s['php']} |")
w()

os.makedirs("docs/hermes", exist_ok=True)
with open("docs/hermes/effectiveness.md", "w") as fh:
    fh.write("\n".join(L) + "\n")

print(f"Hermes effectiveness ({base_start['date']} → {last['date']}):")
print(f"  PHPStan baseline : {base_start['base']} → {last['base']}  ({pct(base_start['base'], last['base'])})  [down=better]")
print(f"  Debt density     : {base_start['density']} → {last['density']}  ({pct(base_start['density'], last['density'])})  [down=better]")
print(f"  Test files       : {first['tests']} → {last['tests']}  ({pct(first['tests'], last['tests'])})")
print(f"  Docs             : {first['docs']} → {last['docs']}  ({pct(first['docs'], last['docs'])})")
print(f"  Escape rate      : peak {peak_esc}% → {last['esc_rate']}%  ({pct(peak_esc, last['esc_rate'])})  [down=better]")
print(f"  Escapes {last['escapes']} · catches {last['catches']} (audit-found pre-merge)")
if regressions:
    print("  ⚠️ regressions:")
    for r in regressions:
        print(f"    - {r}")
else:
    print("  ✅ no regressions in the latest snapshot")
print("  report → docs/hermes/effectiveness.md")
sys.exit(0)
