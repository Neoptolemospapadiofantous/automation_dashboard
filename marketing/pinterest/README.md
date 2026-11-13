# Pinterest — Clean Counter Kit, yearly schedule

Posts pins for <https://crispfolk.com/shop/clean-counter-kit> **10 times a day for a
year** (2026-11-14 → 2027-11-13, 3,650 pins) at high-traffic times, through the
Pinterest API, from a scheduled GitHub Action.

| File | What it is |
| ---- | ---------- |
| `config.json` | Dates, daily time slots, timezone, link + UTM tags, **board IDs, image URLs** |
| `copy.json` | Copy pools (hooks, seasonal hooks per month, bodies, CTAs, keywords) |
| `generate_calendar.py` | Mixes the copy into `calendar.csv` (deterministic) |
| `calendar.csv` | The full year, one row per pin. Edit any row by hand. |
| `post_due_pins.py` | Posts whatever is due now, and skips anything already posted |
| `../../.github/workflows/pinterest-schedule.yml` | Runs the poster at every slot |
| `APP_DESCRIPTION.md` | Wording for the Pinterest developer app (description, scopes) |
| `site/privacy-policy.html` | Privacy policy page for crispfolk.com/privacy |

## Posting times (America/New_York)

07:30 · 09:30 · 12:00 · 13:30 · 15:00 · 17:30 · 19:30 · 20:30 · 21:30 · 22:30

Weighted toward evenings (8–11 pm), when Pinterest traffic peaks, with
morning-commute, lunch and mid-afternoon slots in between. Daylight saving is
handled. GitHub's scheduler often starts runs 5–30 minutes late, so pins go out
close to these times, not to the minute.

## One-time setup

1. **Pinterest business account + developer app.** At
   <https://developers.pinterest.com/apps/>, create an app. New apps start on
   *Trial* access, which can't publish real pins, so apply for **Standard access**
   (approval usually takes a few days). Add a redirect URI such as
   `https://localhost/`.

   Pinterest reviews the app's description and requires a privacy policy link.
   Paste the wording from **`APP_DESCRIPTION.md`**, which matches exactly what the
   script does. Publish **`site/privacy-policy.html`** at `https://crispfolk.com/privacy`
   (fill in the highlighted placeholders first) and put that URL in the form.

2. **Get a refresh token** (scopes `boards:read,pins:read,pins:write`). Open this
   URL, approve, and copy the `code=` value from the address you're redirected to:

   ```
   https://www.pinterest.com/oauth/?client_id=APP_ID&redirect_uri=https://localhost/&response_type=code&scope=boards:read,pins:read,pins:write
   ```

   Then exchange the code:

   ```bash
   curl -s -X POST https://api.pinterest.com/v5/oauth/token -u "APP_ID:APP_SECRET" -d grant_type=authorization_code -d code=CODE -d redirect_uri=https://localhost/
   ```

   Keep the `refresh_token` from the response.

3. **Add repo secrets** (Settings → Secrets and variables → Actions):
   `PINTEREST_APP_ID`, `PINTEREST_APP_SECRET`, `PINTEREST_REFRESH_TOKEN`.
   Optional: `GH_SECRETS_PAT`, a fine-grained token with *Secrets: read/write* on
   this repo. If Pinterest rotates the refresh token, the job saves the new one
   itself. Without it, the job prints a warning and you update the secret by hand.

4. **Fill in `config.json`:**
   - `boards`: run the workflow manually with mode **list-boards** to see your board
     IDs. List several to rotate across them, which spreads 10 pins a day over boards.
   - `images`: public URLs of product photos (vertical 2:3, e.g. 1000×1500, works
     best). Pins rotate through them. **Add as many as you can.**

5. **Merge to the default branch.** GitHub only runs scheduled workflows from it.
   Then run the workflow manually with mode **dry-run** to check it.

## Day to day

- **Change copy or dates:** edit `copy.json` / `config.json`, then run
  `python3 marketing/pinterest/generate_calendar.py` and commit `calendar.csv`.
  Regenerating overwrites hand edits to `calendar.csv`.
- **Change one pin:** edit its row in `calendar.csv`. Set `board_id` or `image_url`
  on a row to override the rotation for that pin.
- **Change posting times:** edit `slots` in `config.json`, regenerate, **and**
  update the cron lines in the workflow (they're written to match the slots).
- **Pause:** Actions → Pinterest schedule → ⋯ → Disable workflow.
- **Test locally:** `python3 marketing/pinterest/post_due_pins.py --dry-run --now 2026-12-01T20:35:00-05:00`

## How it avoids double posts and bursts

Each run only looks at pins due in the last 4 hours, posts at most 3, and skips
any slot already on the board. Every pin carries a private note `ccq:<slot_id>`
and a link with `utm_content=<slot_id>`. Pins older than the 4-hour window are
never back-filled, so an outage doesn't cause a flood. The `utm_content` tag also
lets you see in analytics which time slot and pin drove each visit.

## Things to watch

- **Spam filters.** 10 pins a day pointing at one URL is aggressive. Pinterest
  rewards *fresh* pins (new images) and may limit reach or flag accounts that
  repeat the same image and link. The copy gives 3,650 unique title/description
  pairs, but variety in images matters most. Use many images and several boards.
  If impressions drop, reduce `slots` (e.g. to 5).
- **Review the copy.** It was written without access to the product page, so it
  makes no claims about materials or ingredients. Check `copy.json` reads true for
  the kit, and add specifics (what's in it, price, scent) to strengthen it.
- **Token expiry.** If the refresh token expires, runs fail with a clear message.
  Repeat setup step 2 and update the secret.
