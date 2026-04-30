# Default template — Design System

This is the design contract for the Firefly default template. Every page
in this template follows this system so the frontend and Gutenberg
editor render the same thing, and a forker can rebrand by editing one
tokens file rather than rewriting components.

If you're forking: **start with [Tokens](#tokens)**. Change those, and
the whole site rebrands. If you change anything else, you're modifying
the system, not just theming it.

---

## Philosophy

- **One design system, every page.** Home, contact, signup, blog,
  pricing — all share the same palette, type, spacing, component CSS,
  and layout primitives. Pages differ only in *content* (snippets) and
  occasional *page-specific layout* CSS, never in design language.
- **WYSIWYG parity is mandatory.** What a no-coder sees in the
  Gutenberg editor canvas must match what the visitor sees on the
  rendered page. This is enforced by the editor-preview model and the
  4-file Firefly block pattern.
- **Tokens are the only theme surface.** Hex codes, font families,
  spacing scales — they live in `_core_custom-properties.css`. No
  component file should hard-code a color or font name.
- **Dark by default, opinionated.** Near-black background, off-white
  text, amber accents, phosphor green for "live" indicators. A forker
  who wants light mode flips tokens, not files.

---

## Body class contract

Every page in this template gets `firefly-page` on `<body>`. All shared
design CSS scopes under `.firefly-page` so it applies template-wide.

Pages may *additionally* get a page-specific class — e.g. home gets
`page-home` (and the legacy alias `home-page` until it's retired) — for
layout that's truly unique to that page (the triple-panel hero demo,
the CLI demo terminal). Page-specific CSS scopes under that page class.

**Selector hierarchy:**

```
body.firefly-page .container          ← shared design
body.firefly-page.page-home .triple   ← home-only
```

Never write a global selector (e.g. plain `h1`) without a body-class
scope. Forks live or die by this — an unscoped rule leaks across the
whole WP install and contaminates wp-admin.

---

## Tokens

Defined in `assets/css/_core_custom-properties.css` under `:root` so
they're available to every CSS file, including ones loaded into the
Gutenberg editor iframe.

### Color

| Token | Value | Use |
| --- | --- | --- |
| `--ff-bg` | `#0a0a0b` | Page background |
| `--ff-surface` | `#141416` | Cards, panels |
| `--ff-surface-2` | `#1a1a1d` | Nested surfaces, code blocks |
| `--ff-fg` | `#fafaf7` | Primary text |
| `--ff-fg-muted` | `rgba(250,250,247,0.56)` | Secondary text, descriptions |
| `--ff-fg-dim` | `rgba(250,250,247,0.38)` | Tertiary, captions |
| `--ff-amber` | `#f5b544` | Brand accent, primary CTA, links |
| `--ff-amber-soft` | `rgba(245,181,68,0.12)` | Amber tint for backgrounds |
| `--ff-amber-ring` | `rgba(245,181,68,0.28)` | Amber border / outline |
| `--ff-green` | `#9fe870` | "Live" / "OK" status |
| `--ff-green-soft` | `rgba(159,232,112,0.12)` | Green tint |
| `--ff-slate` | `#2a3440` | Quiet UI accent (dots, dividers) |
| `--ff-border` | `rgba(255,255,255,0.08)` | Default subtle border |
| `--ff-border-strong` | `rgba(255,255,255,0.14)` | Stronger divider |

A forker rebrands the whole site by editing this table. Component CSS
must always reference these tokens — never raw hex.

### Typography

| Token | Stack |
| --- | --- |
| `--ff-font-sans` | `Geist, system-ui, -apple-system, sans-serif` |
| `--ff-font-serif` | `Instrument Serif, Georgia, serif` |
| `--ff-font-mono` | `Geist Mono, ui-monospace, SFMono-Regular, Menlo, monospace` |

Geist + Geist Mono + Instrument Serif italic load from Google Fonts
(`@import` in the design CSS). If a fork swaps fonts, swap them here
*and* update the `@import`.

### Radii

`--ff-radius-sm: 6px` · `--ff-radius: 10px` · `--ff-radius-lg: 16px`

### Spacing & layout

| Token | Value | Use |
| --- | --- | --- |
| `--ff-section-y` | `clamp(5rem, 8vw, 8rem)` | Vertical padding on `<section>` |
| `--ff-gutter` | `clamp(1.25rem, 5vw, 3.5rem)` | Horizontal page padding |
| `--ff-container` | `1240px` | Max content width |

---

## Typography scale

```
h1   clamp(2.5rem,  5.8vw, 4.75rem)   weight 500, line 1.05, tracking -0.03em
h2   clamp(1.875rem, 3.6vw, 2.875rem) weight 500, line 1.05, tracking -0.03em
h3   clamp(1.25rem, 1.8vw, 1.5rem)    weight 500
h4   1.0625rem                         weight 500
p    1rem, line 1.65, color --ff-fg-muted
p.lead  clamp(1.0625rem, 1.3vw, 1.25rem), --ff-fg, max-width 48ch
```

### Utility classes

- **`.serif`** — wraps display text in Instrument Serif italic, amber.
  Used inside headlines for emphasis: `<h1>One codebase. <span class="serif">Three contributors.</span></h1>`
- **`.mono`** — Geist Mono with stylistic-set OpenType features.
- **`.overline`** — small mono uppercase eyebrow (`0.6875rem`,
  letter-spacing `0.12em`, color `--ff-fg-muted`). Use above section
  headlines.
- **`.eyebrow`** — pill-shaped amber-tinted eyebrow with a glowing dot
  before it. More prominent than `.overline`. Use for hero meta.

---

## Layout primitives

- **`.container`** — `max-width: var(--ff-container)`, centered, with
  `--ff-gutter` left/right padding. Apply to `<div class="wp-block-group container">`. Always wrap section
  content in a container.
- **`<section>`** — gets `padding-block: var(--ff-section-y)`
  automatically. Tag every page section with a Gutenberg group set to
  `tagName: section`.
- **`.section-head`** — vertical stack (overline + heading + lead)
  with `max-width: 60ch` and `margin-bottom: 3rem`. Add `.center` to
  center it.
- **`.reveal`** — element starts hidden + offset; `motion-helpers.js`
  reveals it on scroll. Add to any chunk you want to fade in.
- **`.reveal-stagger`** — apply to a container; its children get
  staggered reveal timing.

---

## Component / block library

All components are implemented as Firefly Gutenberg blocks under
`themes/firefly-collective/blocks/` (registered by `blocks/register.php`).
Each block is the 4-file bundle:

```
block.json          ← metadata + attributes
index.js            ← editor edit() + save: () => null
index.asset.php     ← editor script dependencies
render.php          ← server-side HTML
```

`render.php` and `edit()` must produce **structurally identical HTML**
(same classes, same nesting). This is what makes editor and frontend
match.

| Block | Purpose | Reusable? |
| --- | --- | --- |
| `firefly/overline` | Mono uppercase eyebrow above a heading | Any page |
| `firefly/section-head` | Overline + heading (with optional `<span class="serif">` accent) + lead, in a `.section-head` stack | Any page |
| `firefly/hero-meta` | Status dot + small mono tech-stack line | Any hero |
| `firefly/contributor-card` | Number + audience + title + description + chips, in a card | Any page |
| `firefly/pillar` | Icon + title + description + meta, in a pillar grid | Any page |
| `firefly/tier` | Pricing tier card (title, price, description, feature list, CTA) | Any page |
| `firefly/template-card` | Template showcase card | Any page |
| `firefly/trust-bar` | Logo + stat strip | Any page |
| `firefly/substrate-logos` | Substrate logo grid | Home (hardcoded) |
| `firefly/triple-panel` | The animated 3-panel hero demo | Home only |
| `firefly/cli-terminal` | The animated CLI demo terminal | Home only |

Blocks marked "Home only" hard-code their content / animation and are
not meant for reuse. Everything else takes attributes and works on
any page.

**Block attribute rule:** never put HTML inside an attribute string.
The cross-environment sync pipeline can lose backslash escapes
(`<` becomes literal `u003c`). Split into multiple text-only
attributes and assemble in `render.php`. See `section-head` for the
canonical example (`heading` + `accent` + `lead` instead of one
`heading` containing `<span class="serif">`).

---

## Buttons

Buttons use Gutenberg's `wp-block-button` wrapper. Two variants:

- **Primary** (`.wp-block-button.btn-primary`) — amber fill, dark text,
  auto-prefixed arrow `→` that translates on hover.
- **Ghost** (`.wp-block-button.btn-ghost`) — transparent with
  `--ff-border-strong` outline, fills slightly on hover.

Hero / CLI / pricing / closing CTA groups force `flex-wrap: nowrap` so
the pair stays on one row even when the editor sidebar is narrow.
Button labels wrap *inside* the button when needed (`overflow-wrap:
anywhere`), so the row never overflows.

---

## Motion

- **`.reveal`** — `opacity: 0; transform: translateY(0.5rem)`. Reveals
  with `opacity: 1; transform: none` when intersected.
- **`.reveal-stagger`** — children get an incrementing delay applied by
  `motion-helpers.js`.
- **Reduced motion** — `@media (prefers-reduced-motion: reduce)`
  disables all reveal transforms and animations. The reveal classes
  resolve to "already revealed" instantly.
- **Editor canvas** — `motion-helpers.js` doesn't run inside Gutenberg.
  The editor-preview CSS forces `.reveal { opacity: 1; transform: none
  !important }` so authors see the final state, not invisible blocks.

---

## Editor parity (WYSIWYG mechanism)

This is what makes the Gutenberg editor canvas match the frontend
exactly:

1. **Server-side block rendering.** Every Firefly block sets
   `save: () => null` in JS and produces output via `render.php`. The
   `edit()` function emits the same HTML structurally so the editor
   canvas previews accurately.
2. **Token + design CSS injected into the editor iframe.** A model
   file (`models/editor-preview.php`) hooks `block_editor_settings_all`
   for any page in this template and adds the design stylesheet plus
   a small set of editor-only overrides (kill aggressive
   `editor-style.css` padding, force `.reveal` visible, color the
   block selection outline amber).
3. **Body class stamped on the editor canvas.** A tiny script enqueued
   via `enqueue_block_editor_assets` polls for the editor canvas
   iframe and adds `firefly-page` (plus `page-{slug}` for the page
   being edited) to its `<body>`. From that moment, every CSS rule
   scoped under `body.firefly-page` cascades inside the editor exactly
   as on the frontend.
4. **No global Gutenberg overrides.** Everything is scoped under
   `body.firefly-page` (or `editor-styles-wrapper.firefly-page` for
   the editor-only fallback) so wp-admin chrome stays untouched.

When you add a new component or change tokens, **test both** the
frontend page *and* the Gutenberg editor canvas before declaring it
done.

---

## Forking guide

You forked the default template and want to make it yours. Here's the
order of operations:

1. **Rebrand**: edit `_core_custom-properties.css`. Change colors,
   fonts, radii, spacing. The whole site updates.
2. **Edit copy**: open each snippet in `snippets/pages/*.html` in
   Gutenberg and rewrite the content. The blocks stay; the words
   change.
3. **Add pages**: create a new page in WP, drop in existing Firefly
   blocks (`section-head`, `pillar`, `tier`, etc.). The new page
   inherits the design system because it gets the `firefly-page` body
   class automatically.
4. **Add a unique layout** (rare): write `assets/css/{slug}.css`
   scoped under `body.firefly-page.page-{slug}`. Don't unscope.
5. **Build a new block** (rare): `firefly blocks create my-block`,
   then add CSS for it under `body.firefly-page` so it works on every
   page.

Things you should *not* do unless you intend to fork the system itself:

- Add unscoped CSS rules.
- Hard-code colors or fonts in component CSS.
- Put HTML inside block attribute strings.
- Override `editor-style.css` outside the editor-preview model.
- Add `display: none` on `.reveal` (it's already invisible — it should
  fade *in*, not pop).

---

## File map

```
templates/default/
├── DESIGN.md                              ← this file
├── assets/css/
│   ├── _core_custom-properties.css        ← TOKENS — fork starts here
│   ├── _core_design.css                   ← shared design system (scoped to .firefly-page)
│   ├── home.css                           ← home-only layout (triple-panel, CLI demo)
│   ├── contact.css / signup.css / etc.    ← page-only layout (when needed)
│   └── _core_main.css / _core_nav.css     ← framework chrome
├── models/
│   └── editor-preview.php                 ← injects design CSS + body class into editor iframe
└── snippets/pages/
    ├── home.html                          ← Gutenberg blocks; edit in WP, syncs back to file
    ├── contact.html / signup.html / etc.
    └── ...

themes/firefly-collective/blocks/          ← block library (4-file bundles)
```
