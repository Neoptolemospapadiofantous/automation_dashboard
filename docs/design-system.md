# Design system — Flowstack

> The brand, its tokens, and the rules that keep the dashboard, the embed
> widget, and the [marketing site](../../automation-landing) looking like
> one product. Companion to [theme-unification.md](./theme-unification.md)
> (the build story) — this doc is the **reference + the rules**.
>
> Audited 2026-06-13 (4 parallel agents: accessibility, coverage,
> brand-drift, interaction). Findings are folded into the rules below.

## 1. The idea: "two sheets, one ink"

One editorial-blueprint identity, printed on two sheets:

| | Sheet | `--bg` | `--ink` | Where |
|---|---|---|---|---|
| Marketing | **black** | `#000000` | `#FFFFFF` | automation-landing (`:root` / `.sheet-black`) |
| App (light) | **white** | `#FFFFFF` | `#000000` | dashboard, `.sheet-white` |
| App (dark) | **black** | `#000000` | `#FFFFFF` | dashboard, `.sheet-black` — the marketing palette |
| Embed | **white** | `#FFFFFF` | `#000000` | customer iframe (inlined; not toggled) |

Same gray ramps, same hard edges, same Inter + JetBrains Mono, same
blueprint motifs — front and back of one drawing. A visitor crossing
landing → signup → dashboard → installs the widget never sees the brand
change register, only flip sheets.

**Dark mode falls out of this for free.** The dashboard ships *both*
sheets and toggles between them — light = `.sheet-white`, dark =
`.sheet-black` (literally the marketing palette). One class on `<html>`
re-themes every token-driven component. The toggle lives in the top bar;
a no-FOUC inline script in `app.blade.php` sets the sheet before paint
(saved choice → else OS `prefers-color-scheme`); `resources/js/composables/useTheme.js`
persists it (`localStorage` `fs-theme`). **Everything you build must read
on both sheets** — that's the core constraint, and it's why the accent is
tuned per sheet (below).

## 2. Tokens (the only edit point)

Canonical file: **`automation-landing/branding/tokens.css`** — vendored
**byte-identical** into this repo at **`resources/css/tokens.css`**
(verified identical, 1787 bytes; a `diff` between them is a bug). Tailwind
maps them to utilities in `tailwind.config.js` with names mirroring the
landing's v4 `@theme` block, so classes are portable between repos.

### White-sheet palette + contrast (WCAG 2.1, measured)

| Token | Hex | Utility | on `#FFF` | on `#FAFAFA` | Use for |
|---|---|---|---|---|---|
| `--ink` | `#000000` | `text-ink` `bg-ink` | 21.0 ✅AAA | 20.0 ✅AAA | Primary text, ink blocks |
| `--ink-dim` | `#525252` | `text-ink-dim` | 7.69 ✅AAA | 7.34 ✅AAA | **Any readable secondary copy** |
| `--ink-mute` | `#8A8A8A` | `text-ink-mute` | 3.45 ❌AA-normal | 3.30 ❌ | **Decorative/large ONLY** (see rule) |
| `--bg` | `#FFFFFF` | `bg-bg` | — | — | Page / card ground |
| `--bg-elev` `--surface` | `#FAFAFA` | `bg-bg-elev` `bg-surface` | — | — | Raised / inset ground |
| `--surface-hi` | `#F0F0F0` | `bg-surface-hi` | — | — | Hover / active / selected fill |
| `--border-line` | `#E5E5E5` | `border-border-line` | — | — | Hairline dividers, card edges |
| `--border-hi` | `#D4D4D4` | `border-border-hi` | — | — | Input borders |

`--violet` / `--cyan` are accent aliases. In the **shared** `tokens.css`
they resolve to ink (so the marketing site stays pure mono). The
**dashboard** layers a real accent over them in `app.css` — see §2a.
(`violet`/`cyan` are `DEFAULT`-keyed in Tailwind so the built-in numbered
scales survive for chart data.)

## 2a. The accent (dashboard only)

The dashboard reintroduces **one** brand hue — an indigo-violet — for
emphasis, layered over the mono tokens in `resources/css/app.css` (NOT in
the shared `tokens.css`, so the marketing site is untouched). It's tuned
per sheet so it reads on both grounds:

| | `--violet` | Pairs with |
|---|---|---|
| Light (`.sheet-white`) | `#5145E5` (deep) | white text |
| Dark (`.sheet-black`) | `#9A8FFF` (light) | black text |

**The contrast trick:** the accent is dark-on-light and light-on-dark,
and `--bg` flips with the sheet — so an accent fill **always** pairs with
`text-bg`:

```
✅ bg-violet text-bg      ❌ bg-violet text-white
```

Utilities: `bg-violet` `text-violet` `border-violet` `ring-violet`.

**Accent is for emphasis only — never status, never decoration.** Use it
for: the one primary/hero CTA on a page, the active nav item
(`border-l-2 border-violet`), a selected card/tab (`border-violet`), a
single key metric or trend, an important inline link. Do **not** recolor
the workhorse `PrimaryButton` (it stays ink), don't accent every button,
and don't use it where a semantic hue already carries meaning. Rule of
thumb: **≤ a handful of accent touches per page.**

## 2b. Blueprint motifs (`resources/css/motifs.css`)

A small, curated drawing vocabulary ported from the marketing site —
token-driven (adapts to both sheets), reduced-motion safe, never
tree-shaken. Use to make the app feel like the engineered back-of-sheet,
with the same restraint as the accent (~2–5 per page).

| Class | What | Where |
|---|---|---|
| `.bg-grid` / `.bg-grid-major` (+ `.bg-grid-fade`) | hairline schematic grid | header/hero/empty-state backdrop |
| `.bp-node` | outlined node box | framed stat / feature / snippet card |
| `.bp-ref` | mono accent sheet-ref label | `DASH/01`, `LEADS/PIPELINE` — card corner / section head |
| `.bp-annot` | mono ink-mute caption | annotations, empty-state subtext |
| `.bp-dot` | small hollow accent square | list marker / connection point (try `.pulse-glow` for live) |
| `.bp-dim` | dimension line w/ end ticks | annotated section divider |
| `.bp-hatch` | diagonal hatch fill | low-key emphasis / empty regions |
| `.ins-stamp` | rotated mono STATUS stamp | `LIVE`/`DRAFT`/`PAID` — ≤1 per card, tint via text color |
| `.bp-wire` | animated dashed connector | only where a flow is the point (onboarding steps) |
| `.shadow-sheet` | hard offset shadow via `--elevation` | depth that **survives dark mode** (a black shadow vanishes on black) |
| `.glass` | translucent panel | over a grid |

Naming convention for `.bp-ref` labels: `AREA/SUBJECT` in caps
(`DASH/FUNNEL`, `AGENT/RUNTIME`, `TEAM/MEMBERS`). Keep them consistent
within a page group.

### ⚠️ The `text-ink-mute` rule (the audit's #1 finding)

`#8A8A8A` is **3.06–3.45:1** on the white sheets — it **fails WCAG AA for
normal text**. It is valid ONLY for:
- short UPPERCASE mono eyebrows/section labels (`font-mono` + `tracking-wider`)
- icon glyphs / chevrons
- large (≥18.66px / ≥14px bold) decorative text

**Any sentence, helper text, data value, timestamp, or link a user must
read uses `text-ink-dim`, never `text-ink-mute`.** When in doubt: phrase
or data value → `ink-dim`; one-to-two-word uppercase mono label → `ink-mute`.

## 3. Edges, shadows, type

- **Radius 0 everywhere.** `rounded-none` on buttons, inputs, cards,
  badges, modals, dropdowns, pills. Exceptions that keep radius: avatar
  `<img rounded-full>`, tiny status dots, toggle tracks/knobs, spinners.
  (`--radius: 0` exists in `tokens.css` but the dashboard isn't wired to
  consume it — radius-0 is enforced by explicit `rounded-none`. Known
  minor: a `borderRadius: { DEFAULT: 'var(--radius)' }` map in
  `tailwind.config.js` would let the token drive edges. Deferred.)
- **No soft shadows, no gradients.** Floating/primary panels use a
  hairline border + a hard offset shadow via **`.shadow-sheet`** (driven
  by `--elevation`, so it survives dark mode — a plain
  `shadow-[8px_8px_0_rgba(0,0,0,0.06)]` is black and *vanishes* on the
  black sheet; prefer `.shadow-sheet`). The brand has no `bg-gradient-*`
  in chrome (image fade-masks excepted).
- **Type:** Inter (`font-sans`) for everything readable; JetBrains Mono
  (`font-mono`) for labels, counters, IDs, timestamps, code/embed
  snippets, status pills. **Never set paragraphs/prose in mono.**

## 4. Component registers

Reuse these — don't hand-roll their look:

| Component | Brand register |
|---|---|
| `PrimaryButton` | `.btn-grad` — solid ink block, mono uppercase label, hover **and** `active:` invert to bg |
| `SecondaryButton` | `.btn-draw` — transparent ghost, ink border, hover/`active:` fills with ink |
| `DangerButton` | red (semantic), squared, mono label |
| `TextInput` | `bg-bg`, `border-border-hi`, `rounded-none`, `focus:border-ink` + `focus:ring-2 focus:ring-ink` |
| `InputLabel` | mono, uppercase, `tracking-wider`, `text-ink-dim` |
| `AuthenticationCard` | blueprint panel: white sheet, hairline border, hard offset shadow |
| `ApplicationLogo` / `ApplicationMark` / `AuthenticationCardLogo` | the 512×512 Flowstack blueprint mark in `currentColor` |

**Interaction rule (audit #4):** any hover state that inverts colors must
have a matching `active:` (and the embed widget guards `:hover` behind
`@media (hover: hover)`), so touch taps get press feedback instead of a
stuck inverted state. Hover *fills* must be perceptible — use
`bg-surface-hi` (#F0F0F0), not `bg-surface` (#FAFAFA, invisible on white).

## 5. Semantic color — the one exception to mono

**Mono is chrome discipline, not data discipline.** In an ops dashboard,
color *is* information, so status hues stay:

- 🟢 green/emerald — success, active, paid, qualified, online
- 🟡 amber — warning, draft, low credits, past-due
- 🔴 red/rose — error, failed, destructive actions, validation
- 🔵 blue + the chart palettes (sky/violet/blue/green/rose) — data series

Their **containers are still squared** (`rounded-none`). Chrome around the
data is mono; the data keeps its meaning-colors.

## 6. The embed surface (special case)

The embed chat page (`resources/views/embed/chat.blade.php`) and widget
loader (`resources/views/embed/widget.blade.php`) render on **customers'**
sites and deliberately load **no app bundle and no external fonts**. So:

- The widget button passes `$ink`/`$bg` from `EmbedController` (was a
  hardcoded `#6366f1` — removed) as a square ink block.
- The chat page **inlines** the `.sheet-white` hex values (a third,
  hand-maintained copy — kept byte-aligned with `tokens.css`; the file
  header pins this). Drift risk acknowledged; if the palette changes,
  update all three.
- The **EU AI Act Art. 50 disclosure** ("AI assistant — not a person…")
  is rendered here by the platform, `text-ink-dim` on `bg-elev`
  (7.34:1, AAA). `tests/Security/HeadersTest.php` pins the copy. Never
  let it regress to low contrast — it once shipped white-on-white.

## 7. Doing it right — quick rules

✅ `text-ink` / `text-ink-dim` for readable text · `text-ink-mute` only for
mono labels & icons · `rounded-none` (avatars/dots/toggles excepted) ·
shared components for buttons/inputs/labels · `.shadow-sheet` for panel
depth · `bg-surface-hi` for hover · semantic hues for status/data · mono
for labels/data/code only · **accent (`bg-violet text-bg`) for emphasis
only, ≤ a handful per page** · **test every change on both sheets**.

❌ `text-gray-*` / `text-indigo-*` chrome · raw hex outside the embed ·
soft shadows · gradients in chrome · `rounded-md/lg/xl` on chrome · mono
paragraphs · `text-ink-mute` on sentences/data · hover-only invert (always
pair with `active:`) · editing one `tokens.css` without the other ·
**`bg-violet text-white` (use `text-bg`)** · **accent on status/data or as
decoration** · **`shadow-[…rgba(0,0,0,…)]` (invisible in dark — use
`.shadow-sheet`)**.

## Files

- `automation-landing/branding/tokens.css` — canonical tokens (both sheets)
- `resources/css/tokens.css` — vendored copy (must stay byte-identical)
- `resources/css/app.css` — dashboard accent + `--elevation` (over the mono tokens)
- `resources/css/motifs.css` — blueprint motif vocabulary
- `resources/js/composables/useTheme.js` — dark/light toggle
- `tailwind.config.js` — token → utility mapping + fonts
- `resources/views/app.blade.php` — no-FOUC theme bootstrap + font links
- `resources/js/Components/{PrimaryButton,SecondaryButton,DangerButton,TextInput,InputLabel,AuthenticationCard,ApplicationLogo,ApplicationMark}.vue` — registers
- `resources/views/embed/{chat,widget}.blade.php` + `app/Http/Controllers/EmbedController.php` — embed surface
- [[theme-unification]] — phased build history
- [[project-overview]] — where this fits the whole system
- [[public-surface]] — the embed surface this brand also styles
