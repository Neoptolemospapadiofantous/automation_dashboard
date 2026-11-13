#!/usr/bin/env python3
"""Build a year of Pinterest pins for the Clean Counter Kit.

Reads config.json (dates, daily time slots, link) and copy.json (copy pools)
and writes calendar.csv: one row per pin with its scheduled time, title,
description, link and alt text. The output is deterministic, so re-running
with the same inputs gives the same calendar.

board_id and image_url are left blank on purpose: post_due_pins.py fills them
from config.json at posting time, so you can add boards or images later
without regenerating (and losing hand edits). Set either column on a row to
override it for that pin.

Usage:
    python3 marketing/pinterest/generate_calendar.py
"""

from __future__ import annotations

import csv
import json
import random
from collections import deque
from datetime import date, datetime, timedelta
from pathlib import Path
from urllib.parse import urlencode
from zoneinfo import ZoneInfo

HERE = Path(__file__).resolve().parent
TITLE_MAX = 100
DESCRIPTION_MAX = 500
SEASONAL_SHARE = 0.3  # share of pins that use the month's seasonal hooks
RECENT_TITLE_WINDOW = 40  # don't repeat a title within this many pins

COLUMNS = ["slot_id", "scheduled_at", "title", "description", "link", "alt_text", "board_id", "image_url"]


def load(name: str) -> dict:
    return json.loads((HERE / name).read_text(encoding="utf-8"))


def fill(text: str, cfg: dict) -> str:
    return text.format(product=cfg["product_name"], brand=cfg["brand"])


def pick_title(rng: random.Random, copy: dict, month: int, recent: deque) -> str:
    for _ in range(50):
        seasonal = copy["seasonal_hooks"].get(str(month), [])
        if seasonal and rng.random() < SEASONAL_SHARE:
            hook = rng.choice(seasonal)
        else:
            hook = rng.choice(copy["title_hooks"])
        title = hook if rng.random() < 0.4 else f"{hook} | {rng.choice(copy['title_tags'])}"
        if len(title) > TITLE_MAX:
            title = hook[:TITLE_MAX]
        if title not in recent:
            return title
    return title


def pick_description(rng: random.Random, copy: dict, cfg: dict) -> str:
    body = fill(rng.choice(copy["bodies"]), cfg)
    cta = fill(rng.choice(copy["ctas"]), cfg)
    keywords = " · ".join(rng.sample(copy["keywords"], 3))
    text = f"{body} {cta}\n\n{keywords}"
    return text[:DESCRIPTION_MAX]


def build(cfg: dict, copy: dict) -> list[dict]:
    tz = ZoneInfo(cfg["timezone"])
    start = date.fromisoformat(cfg["start_date"])
    rng = random.Random(f"{cfg['product_name']}|{cfg['start_date']}")
    recent: deque = deque(maxlen=RECENT_TITLE_WINDOW)
    seen_pairs: set = set()
    rows = []

    for day_offset in range(cfg["days"]):
        day = start + timedelta(days=day_offset)
        for slot in cfg["slots"]:
            hh, mm = (int(p) for p in slot.split(":"))
            when = datetime(day.year, day.month, day.day, hh, mm, tzinfo=tz)
            slot_id = f"{day.isoformat()}-{hh:02d}{mm:02d}"

            for _ in range(20):
                title = pick_title(rng, copy, day.month, recent)
                description = pick_description(rng, copy, cfg)
                if (title, description) not in seen_pairs:
                    break
            seen_pairs.add((title, description))
            recent.append(title)

            query = urlencode({**cfg["utm"], "utm_content": slot_id})
            rows.append({
                "slot_id": slot_id,
                "scheduled_at": when.isoformat(),
                "title": title,
                "description": description,
                "link": f"{cfg['link']}?{query}",
                "alt_text": fill(rng.choice(copy["alt_texts"]), cfg),
                "board_id": "",
                "image_url": "",
            })
    return rows


def main() -> None:
    cfg, copy = load("config.json"), load("copy.json")
    rows = build(cfg, copy)
    out = HERE / "calendar.csv"
    with out.open("w", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=COLUMNS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)
    titles = {r["title"] for r in rows}
    print(f"Wrote {len(rows)} pins to {out.relative_to(HERE.parent.parent)}")
    print(f"  {rows[0]['scheduled_at']} -> {rows[-1]['scheduled_at']}")
    print(f"  {len(titles)} distinct titles, {len(cfg['slots'])} pins/day")


if __name__ == "__main__":
    main()
