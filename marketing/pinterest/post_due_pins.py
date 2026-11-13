#!/usr/bin/env python3
"""Post the pins from calendar.csv that are due now, through the Pinterest API (v5).

Run on a timer (see .github/workflows/pinterest-schedule.yml). Each run:
  1. picks the calendar rows scheduled within the last `lookback_minutes`,
  2. skips any that are already on Pinterest (every pin carries a private
     note `ccq:<slot_id>` and a link with `utm_content=<slot_id>`, so re-runs
     and overlapping runs never double-post),
  3. creates the rest, capped at `max_pins_per_run`.

The script keeps no state of its own: Pinterest is the record of what was posted.

Credentials (environment variables):
  PINTEREST_ACCESS_TOKEN            use this token directly, or
  PINTEREST_APP_ID + PINTEREST_APP_SECRET + PINTEREST_REFRESH_TOKEN
                                    exchange the refresh token for an access token each run
  GH_SECRETS_PAT (optional)         if Pinterest returns a new refresh token, save it back
                                    to the repo secret PINTEREST_REFRESH_TOKEN (needs PyNaCl)
  PINTEREST_API_BASE (optional)     default https://api.pinterest.com/v5

Usage:
  python3 post_due_pins.py              post what's due now
  python3 post_due_pins.py --dry-run    show what would be posted; needs no credentials
  python3 post_due_pins.py --list-boards
  python3 post_due_pins.py --slot 2026-11-14-0730   post one specific pin now (for testing)
"""

from __future__ import annotations

import argparse
import base64
import csv
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

HERE = Path(__file__).resolve().parent
API = os.environ.get("PINTEREST_API_BASE", "https://api.pinterest.com/v5").rstrip("/")
NOTE_PREFIX = "ccq:"


class PinterestError(RuntimeError):
    pass


def api(method: str, path: str, token: str, body: dict | None = None, query: dict | None = None) -> dict:
    url = f"{API}{path}"
    if query:
        url += "?" + urllib.parse.urlencode(query)
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method, headers={
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json",
    })
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read() or b"{}")
    except urllib.error.HTTPError as exc:
        raise PinterestError(f"{method} {path} -> HTTP {exc.code}: {exc.read().decode(errors='replace')[:500]}") from None


def access_token() -> str:
    direct = os.environ.get("PINTEREST_ACCESS_TOKEN")
    if direct:
        return direct
    try:
        app_id = os.environ["PINTEREST_APP_ID"]
        secret = os.environ["PINTEREST_APP_SECRET"]
        refresh = os.environ["PINTEREST_REFRESH_TOKEN"]
    except KeyError as missing:
        sys.exit(f"Missing credential {missing}. Set PINTEREST_ACCESS_TOKEN, or the app id/secret plus refresh token.")

    basic = base64.b64encode(f"{app_id}:{secret}".encode()).decode()
    req = urllib.request.Request(
        f"{API}/oauth/token",
        data=urllib.parse.urlencode({"grant_type": "refresh_token", "refresh_token": refresh}).encode(),
        method="POST",
        headers={"Authorization": f"Basic {basic}", "Content-Type": "application/x-www-form-urlencoded"},
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            payload = json.loads(resp.read())
    except urllib.error.HTTPError as exc:
        sys.exit(f"Token refresh failed (HTTP {exc.code}): {exc.read().decode(errors='replace')[:500]}\n"
                 "The refresh token may have expired. Generate a new one (see README) and update the secret.")

    new_refresh = payload.get("refresh_token")
    if new_refresh and new_refresh != refresh:
        save_refresh_token(new_refresh)
    days_left = payload.get("refresh_token_expires_in")
    if isinstance(days_left, int) and days_left < 14 * 86400:
        print(f"::warning::Pinterest refresh token expires in {days_left // 86400} days. Renew it (see README).")
    return payload["access_token"]


def save_refresh_token(value: str) -> None:
    pat, repo = os.environ.get("GH_SECRETS_PAT"), os.environ.get("GITHUB_REPOSITORY")
    if not (pat and repo):
        print("::warning::Pinterest issued a new refresh token but GH_SECRETS_PAT is not set, so it was not saved. "
              "If the old token stops working, set GH_SECRETS_PAT or update PINTEREST_REFRESH_TOKEN by hand.")
        return
    from nacl import encoding, public  # imported lazily: only needed here

    def gh(method: str, path: str, body: dict | None = None) -> dict:
        req = urllib.request.Request(f"https://api.github.com/repos/{repo}{path}", method=method,
                                     data=json.dumps(body).encode() if body else None,
                                     headers={"Authorization": f"Bearer {pat}", "Accept": "application/vnd.github+json"})
        with urllib.request.urlopen(req, timeout=30) as resp:
            raw = resp.read()
            return json.loads(raw) if raw else {}

    key = gh("GET", "/actions/secrets/public-key")
    sealed = public.SealedBox(public.PublicKey(key["key"].encode(), encoding.Base64Encoder())).encrypt(value.encode())
    gh("PUT", "/actions/secrets/PINTEREST_REFRESH_TOKEN",
       {"encrypted_value": base64.b64encode(sealed).decode(), "key_id": key["key_id"]})
    print("Saved the new Pinterest refresh token to the PINTEREST_REFRESH_TOKEN secret.")


def load_calendar() -> list[dict]:
    with (HERE / "calendar.csv").open(newline="", encoding="utf-8") as fh:
        rows = list(csv.DictReader(fh))
    for index, row in enumerate(rows):
        row["_index"] = index
        row["_when"] = datetime.fromisoformat(row["scheduled_at"])
    return rows


def resolve(row: dict, cfg: dict) -> tuple[str, str]:
    """Board and image for a row: the row's own value, else rotate through config.json."""
    board = row["board_id"] or cfg["boards"][row["_index"] % len(cfg["boards"])]
    image = row["image_url"] or cfg["images"][row["_index"] % len(cfg["images"])]
    return board, image


def check_config(cfg: dict) -> None:
    placeholders = [v for v in cfg["boards"] + cfg["images"] if v.startswith("REPLACE_")]
    if placeholders or not cfg["boards"] or not cfg["images"]:
        sys.exit("config.json still has placeholder boards/images. Fill in real board IDs "
                 "(--list-boards) and product image URLs before posting.")


def posted_slots(token: str, board_ids: set[str], slot_ids: set[str]) -> set[str]:
    """Slot ids already on Pinterest, matched by the pin's private note or its utm_content link."""
    found: set[str] = set()
    for board in board_ids:
        data = api("GET", f"/boards/{board}/pins", token, query={"page_size": 100})
        for pin in data.get("items", []):
            note, link = pin.get("note") or "", pin.get("link") or ""
            found.update(s for s in slot_ids if note == NOTE_PREFIX + s or f"utm_content={s}" in link)
    return found


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--list-boards", action="store_true")
    parser.add_argument("--slot", help="post this slot_id now, ignoring its scheduled time")
    parser.add_argument("--now", help="pretend it is this ISO time (testing)")
    args = parser.parse_args()

    cfg = json.loads((HERE / "config.json").read_text(encoding="utf-8"))

    if args.list_boards:
        token = access_token()
        for board in api("GET", "/boards", token, query={"page_size": 100}).get("items", []):
            print(f"{board['id']}  {board['name']}  ({board.get('privacy', '')})")
        return

    rows = load_calendar()
    tz = ZoneInfo(cfg["timezone"])
    now = datetime.fromisoformat(args.now).astimezone(tz) if args.now else datetime.now(tz)
    posting = cfg["posting"]

    if args.slot:
        due = [r for r in rows if r["slot_id"] == args.slot]
        if not due:
            sys.exit(f"No slot {args.slot} in calendar.csv")
    else:
        window_start = now - timedelta(minutes=posting["lookback_minutes"])
        due = [r for r in rows if window_start < r["_when"] <= now]

    print(f"Now {now.isoformat()}: {len(due)} pin(s) due in the window.")
    if not due:
        return

    if args.dry_run:
        for row in due:
            board, image = resolve(row, cfg)
            print(f"[dry-run] {row['slot_id']}  board={board}  image={image}\n          {row['title']}")
        return

    check_config(cfg)
    token = access_token()
    already = posted_slots(token, {resolve(r, cfg)[0] for r in due}, {r["slot_id"] for r in due})
    pending = [r for r in due if r["slot_id"] not in already]
    todo = pending[: posting["max_pins_per_run"]]
    print(f"{len(due) - len(pending)} already posted; posting {len(todo)} of {len(pending)} pending.")

    failures = 0
    for row in todo:
        board, image = resolve(row, cfg)
        try:
            pin = api("POST", "/pins", token, body={
                "board_id": board,
                "title": row["title"],
                "description": row["description"],
                "link": row["link"],
                "alt_text": row["alt_text"],
                "note": NOTE_PREFIX + row["slot_id"],
                "media_source": {"source_type": "image_url", "url": image},
            })
            print(f"Posted {row['slot_id']} -> pin {pin.get('id')}: {row['title']}")
        except PinterestError as exc:
            failures += 1
            print(f"::error::{row['slot_id']}: {exc}")
    if failures:
        sys.exit(1)


if __name__ == "__main__":
    main()
