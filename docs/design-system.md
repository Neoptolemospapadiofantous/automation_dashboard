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
| App + embed | **white** | `#FFFFFF` | `#000000` | this repo (`<html class="sheet-white">`) |

Same gray ramps, same hard edges, same Inter + JetBrains Mono, same
blueprint motifs — front and back of one drawing. A visitor crossing
landing → signup → dashboard → installs the widget never sees the brand
change register, only flip sheets.

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

`--violet` / `--cyan` are accent aliases that **resolve to ink** on both
sheets — recoloring the whole brand later is a one-block edit in
`tokens.css`. (`violet`/`cyan` are `DEFAULT`-keyed in Tailwind so the
built-in numbered scales survive for chart data.)

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
- **No soft shadows, no gradients.** Floating panels (dropdowns, modals,
  hero cards) use a hairline border + a hard offset shadow:
  `shadow-[8px_8px_0_rgba(0,0,0,0.06)]`. The brand has no `bg-gradient-*`
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
shared components for buttons/inputs/labels · hairline border + hard
offset shadow for floating panels · `bg-surface-hi` for hover · semantic
hues for status/data · mono for labels/data/code only.

❌ `text-gray-*` / `text-indigo-*` chrome · raw hex outside the embed ·
soft shadows · gradients in chrome · `rounded-md/lg/xl` on chrome · mono
paragraphs · `text-ink-mute` on sentences/data · hover-only invert (always
pair with `active:`) · editing one `tokens.css` without the other.

## Files

- `automation-landing/branding/tokens.css` — canonical tokens (both sheets)
- `resources/css/tokens.css` — vendored copy (must stay byte-identical)
- `tailwind.config.js` — token → utility mapping + fonts
- `resources/views/app.blade.php` — `sheet-white` class + font links
- `resources/js/Components/{PrimaryButton,SecondaryButton,DangerButton,TextInput,InputLabel,AuthenticationCard,ApplicationLogo,ApplicationMark}.vue` — registers
- `resources/views/embed/{chat,widget}.blade.php` + `app/Http/Controllers/EmbedController.php` — embed surface
- [theme-unification.md](./theme-unification.md) — phased build history
- [project-overview.md](./project-overview.md) — where this fits the whole system
