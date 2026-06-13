# Theme unification — landing ↔ dashboard ↔ embed

> **Status: Phases 1 + 2 BUILT** (2026-06-12). Tokens shared; embed
> widget + chat iframe + auth surface + the full dashboard interior
> (~67 Vue files) on-brand. Phase 3 (Tailwind v4 unification) remains
> planned — see below.
>
> Related: [[design-system]] (the living brand reference + rules) ·
> [[project-overview]] (where this fits the whole system).

## The problem

One funnel, three visual identities:

| Surface | Today |
|---|---|
| Landing (`/home/theone/automation-landing`) | Black blueprint mono — Inter + JetBrains Mono, radius-0, CSS-variable tokens |
| Dashboard (this repo) | Stock Jetstream — light, indigo-500, Figtree, rounded-xl. No brand connection |
| Embed widget (runs on **customers'** sites) | `#6366f1` hardcoded as `$primaryColor` in `EmbedController::widget` — indigo nobody chose |

A visitor flows landing → register → dashboard → installs widget, and
the brand changes at every step.

## What the landing theme is (source of truth)

`automation-landing/src/app/globals.css` — Tailwind **v4** CSS-first
config, shadcn (`style: "base-nova"`, cssVariables), **three token layers**:

```
brand tokens   --bg --bg-elev --surface --surface-hi --border-line --border-hi
               --ink --ink-dim --ink-mute --violet --cyan --success --warn
               --danger --line --line-strong --draw          (≈15 lines, the
                                                              only edit point)
      ↓ mapped to
shadcn tokens  --primary --card --border --muted --ring …
      ↓ mapped to
Tailwind v4    @theme inline → utilities bg-bg, text-ink, border-line …
```

Current palette: **pure mono** — `#000` sheet, `#FFF` ink, gray ramps
`#B8B8B8` / `#6B6B6B`, `--radius: 0`. The accent names `--violet`/`--cyan`
deliberately *resolve to white*, so a future recolor is a one-block edit.

Motif utilities worth reusing: `.bp-node`, `.bp-wire` / `.bp-wire-v`
(marching-ants connectors), `.bp-dim`, `.bp-hatch`, `.ins-stamp`,
`.bg-grid`, `.glass`, `.legal-prose` (ready for the legal pages),
all `prefers-reduced-motion`-safe.

Fonts: `Inter` (`--font-sans`) + `JetBrains Mono` (`--font-mono`) via
`next/font` in `src/app/layout.tsx`. Brand assets: `branding/source/*.svg`
masters + `render.sh`.

## The concept: two sheets, one ink

The dashboard should **not** copy the black theme — mono-black is great
editorially, punishing for 8-hour ops sessions. Instead, invert the
token block:

> **Landing = black sheet. App = white sheet. Same ink.**
> `--bg: #fff; --ink: #000`, same gray ramps, same radius-0, same
> Inter + JetBrains Mono, same bp-motifs. Front and back of one
> printed drawing.

Because every landing style keys off the variables, the inversion is a
~15-line second `:root` block, not a redesign.

## Phased plan

### Phase 1 — token bridge + brand-critical surfaces ✅ BUILT

What shipped (2026-06-12):

- `automation-landing/branding/tokens.css` — canonical, **both** palettes
  (`:root`/`.sheet-black` + `.sheet-white`); landing `globals.css` now
  imports it (brand block deduplicated; landing build verified).
- `resources/css/tokens.css` — byte-identical vendored copy; imported by
  `app.css`; `<html class="sheet-white">` in `app.blade.php`.
- `tailwind.config.js` — token-backed colors mirroring the landing's
  utility names (`bg-bg`, `text-ink`, `border-border-line`, …);
  `violet`/`cyan` as `DEFAULT` keys so built-in scales survive;
  fonts → Inter + JetBrains Mono (bunny.net link updated).
- Embed widget — square ink-block button, hover-invert, hard offset
  shadow; `EmbedController` hardcoded `#6366f1` removed (now `$ink`/`$bg`).
- Embed chat page — white sheet, radius-0, mono labels; **fixed the
  invisible Art. 50 disclosure** (was `rgba(255,255,255,.75)` on a
  white header).
- Auth surface — `AuthenticationCard(+Logo)`, `PrimaryButton`
  (.btn-grad), `SecondaryButton` (.btn-draw), `TextInput`, `InputLabel`,
  `Checkbox` re-tokened; Jetstream logo blob → real Flowstack mark
  (currentColor). These components are app-wide, so all dashboard forms
  partially inherit the brand ahead of Phase 2.

Original scope (kept for reference):

1. **Canonical `tokens.css`** — extract the brand block from the
   landing's `globals.css` into a single file holding both palettes
   (`.sheet-black` / `.sheet-white` or `:root` + override class). Lives
   in the landing repo (`branding/` is the natural home); dashboard
   vendors a copy with a "source of truth" header until the repos share
   a package.
2. **Dashboard Tailwind v3 mapping** — `tailwind.config.js`
   `theme.extend.colors: { bg: 'var(--bg)', ink: 'var(--ink)', … }` so
   `bg-bg` / `text-ink` utilities exist in Vue without touching v4.
   Fonts → Inter + JetBrains Mono (replace Figtree).
3. **Embed widget + chat iframe** — the highest-value surface (renders
   on customers' sites next to the landing-driven brand):
   - `app/Http/Controllers/EmbedController.php` — replace hardcoded
     `#6366f1` `$primaryColor` with token values (white-on-black
     blueprint button).
   - `resources/views/embed/chat.blade.php` — inline styles → token
     variables; keep the Art. 50 disclosure header styling intact.
   - Update the snapshot fixtures in `tests/Snapshots/` if widget HTML
     is pinned.
4. **Auth pages** (login / register / verify) — the landing→app
   handoff moment. Jetstream blade/Vue auth layouts → white-sheet
   tokens, radius-0, mono labels.

### Phase 2 — white-sheet interior ✅ BUILT

Shipped 2026-06-12 via three parallel agents with disjoint file sets
(AppLayout + shared components / core pages / settings + billing +
auth leftovers) — 67 Vue files swept, class-only diffs.

**Doctrine decision recorded here: mono is chrome discipline, not data
discipline.** Semantic status hues stay — green (success/active/paid),
amber (warning/draft/low credits), red (errors/destructive), blue
(info), chart series palettes — because in an ops dashboard color *is*
data. Their containers are still squared (`rounded-none`). Radius
survives only on avatars, tiny status dots, toggle switches, and
spinners.

Other notables: `ApplicationLogo`/`ApplicationMark` now render the
Flowstack mark in `currentColor`; dropdowns/modals use hairline border
+ hard offset shadow (`8px 8px 0 rgba(0,0,0,.06)`); table heads and
micro-labels are mono uppercase; the Banner default variant is an ink
block (danger stays red).

### Phase 3 — Tailwind v4 migration (defer)

Dashboard is on Tailwind 3.4.x; landing on v4. `update_inspector`
already flags the major. After bumping, both repos can consume one
identical `@theme` file verbatim and Phase 1's v3 mapping shim is
deleted. Do **not** couple this to Phases 1–2.

## Guardrails

- The widget's `frame-ancestors *` / X-Frame-Options ALLOWALL behavior
  (`SecurityHeaders` only-if-absent rule) must survive any blade edits —
  `tests/Security/HeadersTest.php` pins it.
- Per-tenant widget theming (customers picking their own color) is a
  separate future feature; this work sets the *default* brand, it must
  not hardcode in a way that blocks a later `agents.widget_color`
  column.
- Don't restyle `embed/chat.blade.php` in a way that breaks the
  `DialogPathTest` snapshot fixtures without regenerating them
  (`REGENERATE_SNAPSHOTS=1`).

## Files

- `automation-landing/src/app/globals.css` — token source of truth
- `automation-landing/components.json` — shadcn config (base-nova, cssVariables)
- `automation-landing/branding/` — SVG masters + render pipeline
- `tailwind.config.js` (dashboard) — v3 config to extend
- `app/Http/Controllers/EmbedController.php` — hardcoded `#6366f1`
- `resources/views/embed/chat.blade.php` — widget chat page
- `resources/js/Components/CreditMeter.vue` + `resources/js/Pages/**` — Phase 2 sweep targets
