#!/usr/bin/env python3
"""
Shared status helper for agent shell scripts.

Each agent writes its progress to data/agents/<name>/:
  - last_run.json    quick status (ts, state, summary)
  - findings.json    structured findings (written by the agent itself)
  - last_run.log     full stdout/stderr of the last invocation

Usage from bash:
  python3 scripts/agents/agent_status.py <data_dir> <agent_name> <state> <ts> <summary>

Example:
  python3 scripts/agents/agent_status.py data/agents audit-sentinel running "$TS" "started"
  python3 scripts/agents/agent_status.py data/agents audit-sentinel idle    "$TS" "PASS"
"""
import json
import sys
from pathlib import Path


def main() -> int:
    if len(sys.argv) != 6:
        print(f"usage: {sys.argv[0]} <data_dir> <agent_name> <state> <ts> <summary>", file=sys.stderr)
        return 2

    data_dir, name, state, ts, summary = sys.argv[1:6]
    agent_dir = Path(data_dir) / name
    agent_dir.mkdir(parents=True, exist_ok=True)

    payload = {
        "agent": name,
        "state": state,
        "ts": ts,
        "summary": summary,
    }
    (agent_dir / "last_run.json").write_text(json.dumps(payload, indent=2) + "\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
