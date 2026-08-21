# The Nimbus Admin Experience

*Design specification for the NimbusCMS admin — design language, theme system, and build plan.*

**Status:** increments 1–2 shipped (token layer + signatures); increments 3–4
(themes) and M1–M4 (responsive, §1.6) proposed · **Audience:** the engineer
implementing it · **Scope:** `src/View/themes/nimbus/*` (theme.css, layout.php,
templates), `src/Admin/Controller.php` wiring, one small settings/profile write
path. No public-site changes.

This spec is grounded in the shipped admin: the `nb-` component vocabulary in
`src/View/themes/nimbus/theme.css` (~21.4 KB as shipped, inlined via `file_get_contents` in
`layout.php`/`login.php`), the shell assembly in `src/Admin/Controller.php`
(`nav()` / `shell()` / `page()`), and the real templates (dashboard, tokens,
collections, entries form, users, media, login, stub). Everything below is a
**re-skin via tokens plus light structural tweaks** — not a rewrite. No bundler,
no CSS framework, no JS framework, no webfonts by default.

---

## 1. The Nimbus design language

### 1.1 Identity statement

**Nimbus is a quiet observatory: a warm, well-lit desk under a night sky.**

The magic lives at the edges — the sidebar is the sky, gold is starlight — and
the middle of the screen is calm, bright, and fast, because that's where work
happens. Every generic CMS ships a gray dashboard; Nimbus ships *weather*. The
three rules that keep it tasteful:

1. **The sky frames the work; it never covers it.** Atmosphere (gradients,
   stars, glow) is confined to the sidebar, the auth screen, and empty states.
   Content surfaces stay high-contrast and plain.
2. **Gold is earned, never decoration.** Gold appears only at *moments of
   magic*: the active nav item, the brand broom, empty-state sigils, the
   show-once token reveal, the "rendered in N ms" whisper. If gold is
   everywhere, it is nowhere.
3. **Fast is part of the brand.** The whole admin is one inlined CSS file, zero
   webfont downloads, zero hydration. The speed signal ("summoned in N ms ✦")
   is worn like a badge.
4. **The pocket is a first-class desk.** Most internet traffic is a phone, and
   a CMS's moments of urgency — publish the post, pause the leaked token, fix
   the typo — happen away from the desk. Every admin task must be genuinely
   comfortable one-handed at 375px wide: no page-level horizontal scroll,
   ever; touch targets ≥ 44×44px; nothing revealed only on hover. "Works on
   mobile" is a launch requirement of every screen, not a follow-up. The full
   responsive design is §1.6.

Voice: spell-flavored microcopy, lightly. "Nothing conjured yet", "is being
conjured", "✦". One wink per screen, maximum. Trademark-safe: sky, brooms,
stars, spells as *generic folklore* — never named characters, houses, schools,
or any Potter mark.

### 1.2 Design tokens (default theme: **Nimbus**)

This is the complete `:root` block. It supersedes the current 17-variable set;
every hardcoded hex in `theme.css` (badge greens, `#fbfbfe` table headers,
alert tints, sidebar text colors, star colors…) moves into these tokens so that
**a theme is nothing but a re-declaration of this block** (§2).

```css
:root {
  color-scheme: light;                     /* Nocturne sets `dark` */

  /* -- Brand ------------------------------------------------------------ */
  --nb-brand:        #5b4ee6;  /* interactive indigo — AA on white (5.7:1) */
  --nb-brand-dark:   #4a3ec8;  /* hover / pressed                          */
  --nb-brand-bright: #6d5efc;  /* decorative only: gradients, avatar       */
  --nb-brand-tint:   #efedff;  /* focus halo, icon chips, tinted bg        */
  --nb-on-brand:     #ffffff;  /* text on brand fills                      */
  --nb-link:         #4f43cf;  /* anchors — AA on --nb-surface             */

  /* -- Gold (starlight) -------------------------------------------------- */
  --nb-gold:       #f0c24b;    /* icons/accents on dark surfaces only      */
  --nb-gold-soft:  #f6d888;    /* star glints, gradients                   */
  --nb-gold-ink:   #8a6420;    /* "gilt ink": gold-as-text on light, AA    */
  --nb-gold-tint:  #fdf6e3;    /* gold wash background (the Reveal)        */

  /* -- Surfaces & text --------------------------------------------------- */
  --nb-bg:         #f5f5fb;    /* page background                          */
  --nb-surface:    #ffffff;    /* cards, panels, tables, inputs            */
  --nb-surface-2:  #fbfbfe;    /* table header, field-builder rows, hover  */
  --nb-line:       #e7e7f1;    /* hairline borders                         */
  --nb-line-strong:#d5d5e6;    /* hovered borders, emphasized rules        */
  --nb-ink:        #1f2233;    /* primary text — 14.9:1 on surface         */
  --nb-muted:      #5d647d;    /* secondary text — 5.4:1 on surface (AA)   */
  --nb-faint:      #8b90a8;    /* placeholders, ≥18px or decorative only   */

  /* -- Semantic ----------------------------------------------------------- */
  --nb-ok:         #2a8c5c;    --nb-ok-bg:     #eafaf1;  --nb-ok-text:     #1f7a4d;
  --nb-warn:       #b97a0a;    --nb-warn-bg:   #fff4e0;  --nb-warn-text:   #8a5a00;
  --nb-danger:     #c92f35;    --nb-danger-bg: #fdecec;  --nb-danger-text: #b4262b;
  --nb-on-danger:  #ffffff;

  /* -- The Sky (sidebar + auth backdrop) ---------------------------------- */
  --nb-sky:           linear-gradient(180deg, #1b1547 0%, #241a5c 55%, #2c1e63 100%);
  --nb-sky-text:      #cdcbe8;
  --nb-sky-text-hi:   #ffffff;
  --nb-sky-hover:     rgba(255,255,255,.07);
  --nb-sky-active:    rgba(240,194,75,.16);
  --nb-sky-active-rg: rgba(240,194,75,.35); /* inset ring on active item    */
  --nb-star-a:        #ffffff;              /* main stars                   */
  --nb-star-b:        var(--nb-gold-soft);  /* the one gold star            */
  --nb-star-opacity:  .8;
  --nb-sky-foot:      rgba(205,203,232,.5);

  /* -- Elevation ----------------------------------------------------------- */
  --nb-shadow-1:   0 1px 2px rgba(28,24,64,.05), 0 10px 30px rgba(28,24,64,.07);
  --nb-shadow-2:   0 8px 26px rgba(28,24,64,.12);       /* lifted cards     */
  --nb-shadow-pop: 0 24px 60px rgba(10,6,40,.5);        /* auth card        */

  /* -- Shape --------------------------------------------------------------- */
  --nb-radius-s:   8px;      /* code chips, secret box, small controls      */
  --nb-radius-m:   10px;     /* buttons, inputs, nav items, alerts          */
  --nb-radius:     14px;     /* cards, panels, tables (today's --nb-radius) */
  --nb-radius-l:   18px;     /* auth card                                   */
  --nb-radius-pill:999px;    /* badges                                      */

  /* -- Space (4px base unit) ------------------------------------------------ */
  --nb-sp-1: 4px;  --nb-sp-2: 8px;  --nb-sp-3: 12px; --nb-sp-4: 16px;
  --nb-sp-5: 20px; --nb-sp-6: 24px; --nb-sp-7: 28px;

  /* -- Type ----------------------------------------------------------------- */
  --nb-font:    system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  --nb-display: var(--nb-font);  /* same stack, heavier weight + tight tracking */
  --nb-mono:    ui-monospace, SFMono-Regular, Menlo, monospace;
  --nb-fs-xs: 11px; --nb-fs-s: 12px; --nb-fs-base: 14px; --nb-fs-m: 16px;
  --nb-fs-l: 18px;  --nb-fs-xl: 24px; --nb-fs-display: 30px;

  /* -- Motion ---------------------------------------------------------------- */
  --nb-t-fast: 120ms;  --nb-t-base: 180ms;  --nb-t-slow: 360ms;
  --nb-ease:   cubic-bezier(.2,.7,.3,1);

  /* -- Focus ------------------------------------------------------------------ */
  --nb-focus: 0 0 0 3px var(--nb-brand-tint);          /* inputs (as today) */
  --nb-focus-ring: 2px solid var(--nb-brand);           /* :focus-visible    */
}
```

Notes and deliberate decisions:

- **Fonts.** Today's CSS names `"Inter"` and `"Sora"` but nothing loads them —
  users actually see system-ui. Make that official: the token stack is
  system-first and **no `@font-face`/Google Fonts by default** (charter: fast,
  lightweight, zero requests). Display voice comes from weight 800 +
  `letter-spacing:-.02em`, which system-ui carries fine. A theme *may* point
  `--nb-display` at a locally-hosted font later; that's a theme's choice, not
  the base's.
- **`--nb-brand` darkened** from `#6d5efc` to `#5b4ee6`: white-on-`#6d5efc` is
  4.49:1 — a hair under AA for normal text. `#6d5efc` survives as
  `--nb-brand-bright` for gradients and the avatar where it isn't carrying
  text.
- **`--nb-muted` darkened** from `#6b7189` (≈4.4:1 on white, borderline) to
  `#5d647d` (≈5.4:1). Placeholder-grade gray moved to `--nb-faint`, which is
  never used for information-carrying text at body size.
- **Gold never carries text on light surfaces** — `#f0c24b` on white is ~1.9:1.
  When gold must *read* on light (e.g. a gilt label), use `--nb-gold-ink`.
- Existing aliases to keep working during migration: `--nb-radius` keeps its
  name/value; `--nb-night` becomes `--nb-sky` (keep `--nb-night: var(--nb-sky)`
  for one release); the undefined `var(--nb-border)` references in
  `.nb-missing-type__value`, `.nb-token-secret`, `.nb-scope-list` are **bugs**
  — repoint them to `--nb-line` (§4.6, cleanup list).

### 1.3 Signature elements — "unmistakably Nimbus"

Five things, each cheap in vanilla CSS, that make a screenshot identifiable at
thumbnail size. Everything else stays deliberately quiet.

#### S1. The Sky — a themed heaven in the sidebar

The existing night-sky sidebar (`.nb-side` + radial-gradient stars in
`::before`) is the single strongest asset. Elevate it from "a style" to "a
mechanism": every theme defines *its own sky* purely through the `--nb-sky*`
and `--nb-star-*` tokens — Nocturne gets a deeper void, Daybreak gets a dawn
gradient with faint day-stars, Grimoire gets candlelit bottle-green. Same CSS,
four skies. Two refinements to the base:

- **Horizon glow.** A whisper of brand light rising from the sidebar's foot, as
  if a city (your content) glows below the sky. One extra background layer, no
  new elements:

```css
.nb-side::after {            /* sits under ::before's stars */
  content: ""; position: absolute; inset: auto 0 0 0; height: 180px;
  background: linear-gradient(0deg, var(--nb-sky-glow, rgba(109,94,252,.18)), transparent);
  pointer-events: none;
}
```

- **One twinkling star.** Exactly one — the gold one — twinkles on an 7s cycle.
  A single `opacity` keyframe on the existing `::before` layer costs nothing
  and reads as alive without being a carnival. Guarded by reduced-motion (§1.5).

```css
@keyframes nb-twinkle { 0%,92%,100% { opacity: var(--nb-star-opacity); }
                        96%          { opacity: .45; } }
.nb-side::before { animation: nb-twinkle 7s ease-in-out infinite; }
```

The login page (`body.nb-night`, `.nb-auth`) uses the same sky tokens, so
signing in already tells you which theme you chose.

#### S2. The Charm Line — gold underlining the page's name

Each page's `h1` (inside the existing `.nb-page-head`) gets a short gold
gradient stroke beneath it — a wand-flick underline. It is the *only* gold on a
typical working screen, which is exactly why it registers.

```css
.nb-page-head h1 { position: relative; padding-bottom: 6px; }
.nb-page-head h1::after {
  content: ""; position: absolute; left: 0; bottom: 0; height: 3px; width: 44px;
  border-radius: 999px;
  background: linear-gradient(90deg, var(--nb-gold), var(--nb-gold-soft) 70%, transparent);
}
```

No template change — the hook (`.nb-page-head h1`) exists on every page today.

#### S3. Constellation empty states

`.nb-empty-panel` already has the right bones (dashed border, gold icon,
friendly copy). Give it its own patch of sky: a faint constellation drawn with
the same radial-gradient star trick, plus a hairline "connecting line" via one
diagonal linear-gradient. Zero images, ~10 lines:

```css
.nb-empty-panel { position: relative; overflow: hidden; }
.nb-empty-panel::before {
  content: ""; position: absolute; inset: 0; pointer-events: none; opacity: .5;
  background-image:
    radial-gradient(2px 2px at 18% 26%, var(--nb-const, #c9c4ee), transparent),
    radial-gradient(1.5px 1.5px at 30% 18%, var(--nb-const, #c9c4ee), transparent),
    radial-gradient(2px 2px at 76% 30%, var(--nb-const, #c9c4ee), transparent),
    radial-gradient(1.5px 1.5px at 86% 62%, var(--nb-const, #c9c4ee), transparent),
    linear-gradient(24deg, transparent 49.7%, var(--nb-const-line, rgba(109,94,252,.10)) 50%, transparent 50.3%);
}
```

Microcopy convention for empty states (already half-adopted in `stub.php`'s
"is being conjured"): **headline = plain fact, subline = one wink**. e.g.
"No collections yet" / "A collection is a content type… Conjure one to start."
Keep the existing gold `.nb-empty-ic` glyph.

#### S4. The Reveal — the show-once secret as a small ceremony

The tokens page (`tokens/index.php`, `.nb-token-secret`) shows a secret exactly
once. Lean into that: it should feel like something *materializing*. Style: a
gold-washed scroll — `--nb-gold-tint` background, `--nb-gold` left rule, mono
type, `user-select: all` (already there) — plus a one-time shimmer sweep when
it appears (CSS only, plays once, reduced-motion-safe):

```css
.nb-token-secret {
  display: block; margin-top: 10px; padding: 12px 14px;
  font-family: var(--nb-mono); font-size: var(--nb-fs-base);
  word-break: break-all; user-select: all;
  background: var(--nb-gold-tint); color: var(--nb-ink);
  border: 1px solid var(--nb-line); border-left: 3px solid var(--nb-gold);
  border-radius: var(--nb-radius-s);
  position: relative; overflow: hidden;
}
.nb-token-secret::after {          /* one shimmer pass, then done */
  content: ""; position: absolute; inset: 0; transform: translateX(-100%);
  background: linear-gradient(105deg, transparent 40%, rgba(240,194,75,.35) 50%, transparent 60%);
  animation: nb-shimmer .9s var(--nb-ease) .15s 1 forwards;
}
@keyframes nb-shimmer { to { transform: translateX(100%); } }
```

The surrounding "Token created" alert keeps its `nb-alert-ok` body but the
instruction line reads: *"Copy it now — it will never be shown again. ✦"* This
treatment is the house style for **any** show-once secret a future page adds.

#### S5. The Whisper — "summoned in N ms"

The speed signal from the roadmap ("rendered in X ms · powered by NimbusCMS"),
worn inside the admin itself. The sidebar footer (`.nb-side-foot`, currently
static "Nimbus ✦ CMS") becomes:

```
Nimbus ✦ summoned in 14 ms
```

Server-side only: `layout.php` computes
`round((microtime(true) - NB_START) * 1000)` from the existing front-controller
start time (define `NB_START` in `public/index.php` if not present — one line).
Style: existing footer treatment, with the number in `--nb-gold-soft`. It's a
brag, a debugging aid, and a promise the charter makes anyway. Costs ~0 ms to
produce.

*(Sixth, already shipped, now codified: the "broom lift" — `.nb-card:hover`
`translateY(-3px)` + `--nb-shadow-2`. Cards levitate; nothing else does.
Hover motion is reserved for elements that navigate.)*

### 1.4 Component treatments

Each entry: how it looks → which existing `.nb-` class it maps to. "Token-only"
means restyling happens purely by the variables in §1.2.

| Component | Treatment | Maps to | Work |
|---|---|---|---|
| **Sidebar** | The Sky (S1): `--nb-sky` gradient, star layer, horizon glow, twinkle. 236px fixed on desktop; at ≤760px it becomes the off-canvas **drawer** (§1.6.3) — same element, same sky. | `.nb-side`, `::before/::after` | token-only + ~10 lines |
| **Brand** | Gold broom `logo.svg` + app name, weight 800. Unchanged. | `.nb-brand` | none |
| **Nav item** | Rest: `--nb-sky-text`. Hover: `--nb-sky-hover` bg, text → `--nb-sky-text-hi`. Active: `--nb-sky-active` bg + inset 1px `--nb-sky-active-rg` ring, icon → `--nb-gold` (today's treatment, tokenized). **New:** `:focus-visible` gets `outline: var(--nb-focus-ring); outline-offset: 2px` — currently keyboard users get nothing. | `.nb-nav a`, `.active`, `.nb-ic` | token-only + focus rule |
| **Top bar** | 64px, `--nb-surface`, hairline bottom. `.nb-top-l` (empty today) hosts the mobile hamburger + compact brand at ≤760px (§1.6.3) and may gain a muted breadcrumb slot on desktop later — reserved, not built now. Avatar keeps `--nb-brand-bright` gradient. Mobile: 56px, role line hidden (§1.6.2). | `.nb-top`, `.nb-user`, `.nb-avatar` | token-only + §1.6 rules |
| **Page head** | h1 at `--nb-fs-xl` + Charm Line (S2). Actions right-aligned as today. | `.nb-page-head`, `.nb-head-actions` | +6 lines CSS |
| **Cards** | Surface, `--nb-radius`, `--nb-shadow-1`; hover = broom lift. Count in display type. | `.nb-cards`, `.nb-card`, `.nb-card-ic/-count/-label` | token-only |
| **Panels** | Surface + `--nb-shadow-1`. | `.nb-panel` | token-only |
| **Tables** | Wrap in `.nb-table-wrap` (rounded, shadowed, `overflow:auto`) — **mandatory, no exceptions**: a bare `.nb-table` breaks the no-page-scroll rule on phones. **Fix:** `tokens/index.php`, `roles/index.php`, and `users/index.php` all render `.nb-table` bare — wrap them (§1.6.4, increment M1). Header row: `--nb-surface-2` bg, `--nb-fs-s` uppercase `--nb-muted`. Row hover: `--nb-surface-2`. Row borders `--nb-line`. Entries + tokens additionally reflow to stacked cards on mobile via `.nb-stack` (§1.6.4). | `.nb-table`, `.nb-table-wrap`, `.nb-stack`, `.nb-actions-col`, `.nb-row-actions` | token-only + 6 template lines |
| **Forms/fields** | Label 600 @ 13px; input 11×13px padding, `--nb-radius-m`, focus = brand border + `--nb-focus` halo (today's pattern, tokenized). Help text `--nb-help` in `--nb-muted`. Mobile: all multi-column grids (`.nb-grid-2`, `.nb-fr-opts`) collapse to one column, inputs go to 16px type to defeat iOS focus-zoom, field-builder rows wrap (§1.6.5). | `.nb-field`, `.nb-help`, `.nb-form-card`, `.nb-grid-2`, `.nb-section-title` | token-only + §1.6 rules |
| **Validation** | `.nb-field.has-error`: input border `--nb-danger`, focus halo `--nb-danger-bg`; message `.nb-field-error` in `--nb-danger-text`, 600 @ 12px. Page-level `.nb-alert-error` summary stays ("Please fix the highlighted fields."). | existing classes | token-only |
| **Buttons** | Secondary (default `.nb-btn`): surface + `--nb-line`, hover `--nb-line-strong`/`--nb-surface-2`. Primary: `--nb-brand` fill, `--nb-on-brand` text, hover `--nb-brand-dark`. **New `.nb-btn-danger`**: `--nb-danger` fill, white text — for destructive *form submits* that deserve weight (collection delete). Link-style danger (`.nb-link-danger`) stays for inline row actions. All buttons: `:focus-visible` ring. | `.nb-btn`, `.nb-btn-primary`, `.nb-btn-block`, new `.nb-btn-danger` | token-only + 4 lines |
| **Badges** | Pill, 600 @ 12px. Variants map to semantic pairs: `-ok` → ok-bg/ok-text, `-danger` → danger pair, `-muted` → `--nb-surface-2`/`--nb-muted`, `-official` → brand-tint/brand-dark. Publication states: published=ok pair, scheduled=warn pair, draft=muted, archived=brand-tint/`#6b4bd6`→`--nb-brand-dark`. | `.nb-badge*`, `.nb-badge-state-*` | token-only |
| **Alerts/flashes** | 500 weight, `--nb-radius-m`; ok/error use semantic bg+text pairs. (Add `.nb-alert-warn` with warn pair for future use — 2 lines.) | `.nb-alert*` | token-only |
| **Empty states** | Constellation treatment (S3) + copy convention. | `.nb-empty-panel`, `.nb-empty-ic` | +10 lines CSS |
| **Secret reveal** | The Reveal (S4). | `.nb-token-secret` | ~15 lines CSS |
| **Confirms** | Keep native `confirm()` (used on delete/revoke). It is accessible, weightless, and honest — a styled modal is not worth JS + focus-trap complexity at this stage. Revisit only if a flow needs typed confirmation. | inline `onsubmit` | none |
| **Row actions** | Unchanged layout. **Fix:** `.nb-link` (Pause/Resume buttons on tokens) has no CSS — define it as the button-reset twin of `.nb-link-danger` in `--nb-link` color. | `.nb-row-actions`, new `.nb-link` | +3 lines |
| **Icon chips** | `--nb-brand-tint` bg, `--nb-brand-dark` glyph. | `.nb-ic-badge`, `.nb-card-ic` | token-only |
| **Auth card** | Sky backdrop (theme's own), floating card at `--nb-radius-l` + `--nb-shadow-pop`. | `body.nb-night`, `.nb-auth` | token-only |

### 1.5 Layout, rhythm, accessibility

- **Grid.** Fixed 236px sidebar + fluid main column (flex, as today). Content
  column `max-width: 1200px`, `padding: var(--nb-sp-7)`. Forms cap at 760px
  (`.nb-form-card`). Dashboard cards: `auto-fill, minmax(190px, 1fr)`.
- **Rhythm.** 4px base unit; components sit on the `--nb-sp-*` scale
  (existing values already conform: 16/20/24/28). Vertical page rhythm:
  page-head → 20px → alerts → 16px → content blocks → 22px between blocks.
- **Density.** One density: comfortable. Table rows ≈ 46px. A compact mode is
  explicitly *not* in scope (YAGNI; charter says small core). If it ever
  lands, it's a `.nb-compact` class that only shrinks paddings — the token
  architecture already permits it.
- **Responsive.** Two breakpoints — 1024px (cozy) and 760px (mobile) — with
  the full design in §1.6, a first-class section of this spec. The shipped
  horizontal-rail hack at 760px (nav items clipping to "Colle…", sideways
  swiping) is **superseded** by the drawer in §1.6.3 and its CSS block is
  deleted when M3 lands.
- **Accessibility (all mandatory):**
  - Every interactive element gets `:focus-visible { outline: var(--nb-focus-ring); outline-offset: 2px; }`
    — a single grouped rule for `a, button, [tabindex]` inside `.nb`. Inputs
    keep their existing halo-style focus.
  - All text pairs in every theme ≥ 4.5:1 (≥ 3:1 for ≥18px/bold-14px); §3
    palettes were chosen against that bar, and the two borderline base colors
    were darkened (§1.2 notes). Verify with a checker before merge.
  - `@media (prefers-reduced-motion: reduce)` kills the twinkle, the shimmer,
    and the card lift: `* { animation: none !important; transition: none !important; }`
    scoped under `.nb`.
  - Semantic HTML stays: real `<table>`, `<label for>`, `<button>` in forms
    (the codebase is already good here). `aria-current="page"` should be added
    to the active nav link in `layout.php` (one attribute).
  - **Touch targets ≥ 44×44px** on every interactive element at the mobile
    breakpoint (nav items, hamburger, row-action links, checkbox rows —
    mechanics in §1.6.5). Desktop keeps today's comfortable-but-tighter
    metrics.
  - **No hover-only affordances.** Touch has no hover. Audit result: the admin
    already passes — every action is a visible link/button; hover effects
    (broom lift, row tint, underlines) are decorative echoes of visible
    controls. This is now a rule, not a coincidence: nothing may *appear* on
    `:hover` that has no always-visible path.

### 1.6 Responsive & mobile — the admin in your pocket

This section makes principle #4 concrete. It is written against the shipped
`theme.css` (21,442 bytes) and templates; every selector named exists today
unless marked **new**.

#### 1.6.1 Strategy & breakpoints

**Mental model: mobile is a first-class rendering of the same document, not a
degraded desktop.** Mechanically, though, we do *not* rewrite the shipped CSS
into literal mobile-first (`min-width`) form: the base file is live, merged,
and desktop-default; inverting 350 lines of working CSS for ideological purity
would churn the whole file inside a 24 KB budget for zero user-visible gain.
The honest architecture is **desktop-default component CSS + two `max-width`
override blocks**, with the *design* of every screen validated phone-first
before merge.

Breakpoints (plain values — custom properties don't work in media queries):

| Query | Name | What changes |
|---|---|---|
| `@media (max-width: 1024px)` | **cozy** | Content padding steps down to `--nb-sp-5`. Nothing else — the 236px sidebar + fluid main still work fine at tablet widths. |
| `@media (max-width: 760px)` | **mobile** | The full transformation: drawer nav (§1.6.3), one-column forms (§1.6.5), stacked cards on opted-in tables (§1.6.4), compact shell (§1.6.2). |

Why 760px: it is already the shipped breakpoint (smallest diff), it equals
`.nb-form-card`'s max-width (a form that has stopped growing is the natural
moment to change shape), and it cleanly separates "sidebar fits" from "sidebar
can't". Two breakpoints, not four — every additional query costs bytes and
test surface.

**Design targets:** primary 375×667 (small iPhone); everything must also
survive 320px with no page-level horizontal scroll (content may get snug).
Verify each increment at 375 and 320 in devtools before merge.
`<meta name="viewport" content="width=device-width, initial-scale=1">` is
already in `layout.php`/`login.php` — keep it; never add
`maximum-scale`/`user-scalable=no` (WCAG 1.4.4).

**The one invariant:** the *page* never scrolls horizontally. Wide things
scroll inside their own container (`.nb-table-wrap`) or reflow. Any screen
that violates this on a 320px viewport is a release blocker, same severity as
a contrast failure.

#### 1.6.2 The mobile shell — spacing, top bar, page head

Flat 28px padding is a fifth of a 375px screen once doubled. Padding steps
down with the viewport; everything stays on the `--nb-sp-*` scale:

```css
@media (max-width: 1024px) {
  .nb-content { padding: var(--nb-sp-5); }              /* 28 → 20 */
}
@media (max-width: 760px) {
  .nb-content { padding: var(--nb-sp-4) var(--nb-sp-3); } /* 20 → 16/12 */
  .nb-panel, .nb-form-card, .nb-media-upload { padding: var(--nb-sp-4); }
  .nb-empty-panel { padding: 40px var(--nb-sp-4); }

  .nb-top { height: 56px; padding: 0 var(--nb-sp-3); }
  .nb-uname small { display: none; }              /* role line: drawer-only info */
  .nb-uname { max-width: 26vw; overflow: hidden;
              text-overflow: ellipsis; white-space: nowrap; }

  .nb-page-head { flex-wrap: wrap; }   /* actions drop below a long h1 */
  .nb-head-actions { flex-wrap: wrap; }
}
```

Top bar contents at mobile, left to right: **hamburger** (44×44, §1.6.3),
**compact brand** (the gold broom `logo.svg` alone — the wordmark lives in the
drawer; on a 375px bar the glyph is the identity), then the existing
`.nb-user` cluster right-aligned: avatar, ellipsized name, Sign out. The
avatar stays 34px *visual* but its row is 56px tall, so the Sign out button's
tap area is padded to ≥44px (`.nb-signout { padding: 12px 6px; }` at mobile).

#### 1.6.3 The navigation drawer — the centerpiece

**Decision: an off-canvas left drawer, opened by a hamburger in the top bar.
The drawer *is* `.nb-side` — the same element, the same Sky, slid off-screen.**

Alternatives weighed and rejected:

- **Bottom tab bar** — best thumb ergonomics, but `Controller::nav()` emits up
  to **8 capability-gated items** (Dashboard, Collections, Media, Users,
  Roles, API tokens, Plugins, Settings). A tab bar honestly holds 5, so this
  forces a "More…" overflow sheet — a second nav pattern to build, and which
  items surface would vary per user's capabilities. It also collides with the
  iOS home indicator and floating browser chrome, and it evicts the brand and
  the Whisper entirely. Wrong shape for this nav.
- **Wrapping/collapsible icon rail** — cheapest CSS, but either drops labels
  (eight near-abstract glyphs like ⚿ and ❖ are not self-explanatory) or wraps
  to 2–3 rows that tax every single page with dead vertical space. The current
  horizontal scroller is this pattern's failure mode already ("Colle…").
- **Off-canvas drawer** — holds any number of items at full label width, costs
  zero vertical space when closed, is the pattern every phone user already
  knows, and — decisive for Nimbus — it preserves the Sky *intact*: gradient,
  stars, gold active ring, horizon glow, footer Whisper. Opening the menu
  *summons the night sky over the page*. The signature survives mobile
  untouched.

**Mechanism: CSS-only (checkbox + labels), with a 6-line keyboard
enhancement.** No JS is required to open or close; the admin's
server-rendered nature gives us close-on-navigate for free (every nav tap is
a full page load, and the next page renders with the drawer closed).

Markup change to `layout.php` — three additions, nothing moved:

```html
<body class="nb">
<input type="checkbox" id="nb-nav-toggle" class="nb-nav-toggle">      <!-- new -->
<aside class="nb-side"> …unchanged: brand, nav, foot… </aside>
<label class="nb-scrim" for="nb-nav-toggle" aria-hidden="true"></label><!-- new -->
<div class="nb-main">
  <header class="nb-top">
    <div class="nb-top-l">                                             <!-- was empty -->
      <label class="nb-menu" for="nb-nav-toggle">
        <span aria-hidden="true">☰</span><span class="nb-sr">Menu</span>
      </label>
      <a class="nb-top-brand" href="/admin" aria-label="Dashboard"><?= $logo ?></a>
    </div>
    …existing .nb-user…
```

The CSS (**new** selectors: `.nb-nav-toggle`, `.nb-scrim`, `.nb-menu`,
`.nb-top-brand`, `.nb-sr`):

```css
/* Visually-hidden utility (also names the checkbox via its labels). */
.nb-sr { position: absolute; width: 1px; height: 1px; overflow: hidden;
         clip-path: inset(50%); white-space: nowrap; }

/* Desktop: drawer machinery doesn't exist. */
.nb-nav-toggle, .nb-scrim, .nb-menu, .nb-top-brand { display: none; }

@media (max-width: 760px) {
  body.nb { flex-direction: column; }        /* .nb-side is fixed; .nb-main flows */

  .nb-nav-toggle { display: block; position: fixed; width: 1px; height: 1px;
                   opacity: 0; }             /* focusable, invisible */

  .nb-top-l { display: flex; align-items: center; gap: 8px; }
  .nb-menu { display: inline-flex; align-items: center; justify-content: center;
             width: 44px; height: 44px; margin-left: -10px; font-size: 20px;
             color: var(--nb-ink); border-radius: var(--nb-radius-m); cursor: pointer; }
  .nb-top-brand { display: inline-flex; color: var(--nb-gold); }

  /* The drawer: the sidebar itself, off-canvas. Sky untouched. */
  .nb-side { position: fixed; inset: 0 auto 0 0; z-index: 30;
             width: min(300px, 84vw);
             transform: translateX(-100%); visibility: hidden;
             transition: transform var(--nb-t-base) var(--nb-ease),
                         visibility 0s var(--nb-t-base); }
  .nb-nav-toggle:checked ~ .nb-side { transform: none; visibility: visible;
             transition-delay: 0s; box-shadow: var(--nb-shadow-pop); }
  .nb-nav a { padding: 12px; }                       /* ≈47px rows: ≥44 target */

  /* Scrim: tap anywhere outside to close (it's a <label> for the checkbox). */
  .nb-scrim { display: block; position: fixed; inset: 0; z-index: 20;
              background: rgba(10, 6, 40, .45); opacity: 0; pointer-events: none;
              transition: opacity var(--nb-t-base) var(--nb-ease); }
  .nb-nav-toggle:checked ~ .nb-scrim { opacity: 1; pointer-events: auto; }

  /* Keyboard focus on the invisible checkbox renders on its visible proxy. */
  .nb-nav-toggle:focus-visible ~ .nb-main .nb-menu {
    outline: var(--nb-focus-ring); outline-offset: 2px; }
}
```

**Open/close & keyboard behaviour, spelled out:**

- **Open:** tap the hamburger (a `<label>`), or focus the checkbox (it is the
  page's first tab stop — conventional for a menu button; its focus ring draws
  on the hamburger via the sibling selector) and press Space.
- **Close:** tap the scrim (a second label for the same checkbox), press
  Space on the checkbox again, press **Escape** (enhancement below), or
  navigate — the next server-rendered page arrives closed.
- **Tab order** is naturally correct with zero focus-trap code: checkbox →
  drawer links (the aside is next in source) → page. When closed,
  `visibility: hidden` removes the off-canvas links from the tab order — no
  ghost tab stops. A modal focus *trap* is deliberately omitted: the scrim
  makes stray pointer input close the drawer, Tab past the last nav link
  lands in the page (mildly imperfect, fully recoverable), and a trap means
  real JS. Accepted trade.
- **Escape + focus return** — the one enhancement worth its bytes (6 lines,
  in `layout.php` next to the shell; degrades to nothing):

```html
<script>
document.addEventListener('keydown', function (e) {
  var t = document.getElementById('nb-nav-toggle');
  if (e.key === 'Escape' && t && t.checked) { t.checked = false; t.focus(); }
});
</script>
```

- **Screen readers** announce the toggle as "Menu, checkbox" rather than a
  `button` with `aria-expanded`. That is the honest cost of CSS-only; the
  checkbox's checked state does convey open/closed. If review judges this
  unacceptable, the fallback is a real `<button aria-expanded>` + ~12 lines
  of JS — the CSS above survives that swap by keying on a `data-nav-open`
  attribute instead of `:checked`. Default: ship the checkbox.
- **Reduced motion:** the slide and fade are `transition`s, already killed by
  the shipped global reduced-motion block — the drawer then simply appears.
- **Scroll behind the drawer** is not locked (CSS cannot reach `body` from a
  sibling checkbox). The scrim hides the consequence; accepted trade, noted
  so nobody "fixes" it with 30 lines of JS.
- **Identity:** active item keeps the gold ring + gold icon; the drawer keeps
  the star field, horizon glow, and twinkle; and the footer Whisper —
  suppressed by the old rail hack (`display:none`) — **comes back** on
  mobile, riding the drawer. Delete the `.nb-side-foot { display: none }`
  rule with the rest of the rail block.

#### 1.6.4 Tables on mobile — the load-bearing fix

Two tiers.

**Tier 1 — the baseline, mandatory: every `.nb-table` lives inside
`.nb-table-wrap`.** The wrapper's `overflow: auto` confines a wide table to
in-panel horizontal scroll; a bare table pushes the whole page sideways (the
tokens table is ~671px of fixed content — nearly two screens of page-scroll
on a 375px phone). Currently **`roles/index.php`, `users/index.php`, and
`tokens/index.php` render bare tables** (collections/entries/plugins are
wrapped). Fix: two template lines each — `<div class="nb-table-wrap">` /
`</div>` around the existing `<table class="nb-table">`. This is increment
M1: six lines, zero CSS, zero risk, and the whole audit finding #1 is dead.
Review rule from now on: *a bare `.nb-table` in a template is a bug.*
(No `-webkit-overflow-scrolling` needed — it's obsolete; momentum scroll is
the default in every current engine.)

**Tier 2 — stacked cards for the tables people actually work from a phone.**
In-panel scroll is *survivable*, not *good*: on a 375px screen a 7-column
token row means blind sideways digging to find "Revoke". Where the phone use
case is real, rows reflow into label-per-cell cards below 760px.

Decision: **entries and tokens get cards; collections, users, roles, and
plugins stay scroll-wrap.** Rationale: entries is *the* mobile job
("publish that post from the train") and tokens is the widest table and holds
the urgent action ("pause the leaked token"). The other four are narrow
(3–5 short columns — they barely scroll at 375px once wrapped) and are
admin-at-a-desk chores; card-ifying them buys little and costs template churn
in four more files. Revisit per-table if usage says otherwise.

Mechanics — one generic, opt-in pattern. The template opts in with a modifier
class on the wrapper and a `data-label` attribute per labelled cell:

```html
<div class="nb-table-wrap nb-stack">
  <table class="nb-table">
    …
    <td data-label="Status"><span class="nb-badge …">active</span></td>
```

```css
@media (max-width: 760px) {
  .nb-stack thead { display: none; }
  .nb-stack table, .nb-stack tbody, .nb-stack tr, .nb-stack td { display: block; }
  .nb-stack tr { padding: 8px 0 10px; border-bottom: 1px solid var(--nb-line); }
  .nb-stack tr:last-child { border-bottom: 0; }
  .nb-stack td { border: 0; padding: 4px var(--nb-sp-4); }
  .nb-stack td[data-label] { display: flex; gap: 12px; align-items: baseline; }
  .nb-stack td[data-label]::before {
    content: attr(data-label); flex: 0 0 84px;
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .04em; color: var(--nb-muted);
  }
  .nb-stack .nb-row-actions { white-space: normal; flex-wrap: wrap;
                              padding-top: 6px; }
}
```

Conventions: the **first cell carries no `data-label`** — it renders
full-width as the card's title (entries: title + slug; tokens: name). The
**actions cell carries no `data-label`** — it becomes a plain wrapping button
row at the card's foot, where §1.6.5's padded tap targets apply. Labels come
from the same strings as the `<th>`s — for entries' dynamic field columns
that's `data-label="<?= $e($lf->label) ?>"`, already escaped like the header.

Honest weighing, since this is the one place mobile costs real bytes:

- **Bytes:** ~0.55 KB of CSS for the generic pattern (shared by both tables
  and any future opt-in), plus `data-label` attrs in two templates (server
  bytes, not CSS-budget bytes). Within the §1.6.7 envelope — and this block
  is the **designated first cut** if increment 4's theme blocks squeeze the
  ceiling, because Tier 1 already guarantees a usable floor.
- **Template churn:** entries/index.php (~5 cells) and tokens/index.php
  (~5 cells). Contained.
- **Accessibility:** `display: block` on table elements strips table
  semantics at the mobile breakpoint. That is acceptable *here* because each
  row becomes a linear label→value list — exactly how a phone screen-reader
  user wants a record read — and `::before` label text is announced by
  current screen readers. Desktop keeps genuine `<table>` semantics
  untouched. Do not "fix" with `role="table"` re-plumbing; the linear reading
  is the feature.

#### 1.6.5 Forms & touch targets

All rules live in the 760px block:

```css
@media (max-width: 760px) {
  /* Grids collapse: audit finding #2. */
  .nb-grid-2, .nb-fr-opts { grid-template-columns: 1fr; }

  /* 16px input text: below 16px, iOS Safari zooms the page on focus. */
  .nb input:not([type=checkbox]):not([type=radio]), .nb select, .nb textarea {
    font-size: 16px;
  }

  /* Field builder: the flex row wraps into three comfortable lines —
     label (full width) / handle + type (half each) / Req. + remove. */
  .nb-field-row-main { flex-wrap: wrap; }
  .nb-fr-label { flex: 1 1 100%; }
  .nb-fr-handle, .nb-fr-type { flex: 1 1 40%; }
  .nb-fr-remove { width: 44px; height: 44px; }

  /* Tap targets: checkbox rows and row-action links reach 44px via padding. */
  .nb-check { padding: 8px 0; }
  .nb-row-actions { gap: 4px; }
  .nb-row-actions a, .nb-row-actions .nb-link, .nb-row-actions .nb-link-danger,
  .nb-fr-more summary { padding: 12px 8px; }
  .nb-signout { padding: 12px 6px; }
}
```

Notes:

- Inputs already clear 44px height at 16px type (11px padding ×2 + ~25px
  line ≈ 47px). Buttons (`.nb-btn`) land at ~44px the same way — no rule
  needed.
- `.nb-grid-2` collapsing means Name/Handle/Icon/Description stack in source
  order — which is also the sensible completion order, so nothing to re-order
  in the template. `.nb-fr-opts`' textarea and relation row already span
  `1 / -1`, so they're unaffected by the collapse.
- The three-line field-builder row is a deliberate shape: label first
  (what humans think of first, and it drives the auto-slug JS), the two
  identifier-ish controls paired, then the toggles. The `✕` remove button
  grows from 34 to 44px.
- `.nb-checks` (checkbox grids) and `.nb-scope-list` need nothing: they wrap
  already, and `.nb-check`'s added padding gives each row its target height.
- Form action rows (`.nb-form-actions`) keep buttons side-by-side — two
  buttons fit 375px; they wrap naturally if a third appears (`flex-wrap:
  wrap` costs 16 bytes if ever needed; not added now).

#### 1.6.6 The signatures on mobile

- **S1 The Sky** — *strengthened*: no longer flattened into a starless 40px
  rail; the full sky rides the drawer (§1.6.3). Stars, horizon glow, and the
  7s twinkle all function untouched; the glow's 180px height reads correctly
  against a full-height drawer.
- **S2 Charm Line** — unchanged. It hangs off `h1` and is 44px wide; every h1
  fits at 320px (with `.nb-page-head` wrap, §1.6.2).
- **S3 Constellations** — unchanged mechanics; the percentage-positioned
  stars compress fine. Padding drops to 40px (§1.6.2) so empty states don't
  own half a phone screen.
- **S4 The Reveal** — unchanged. `word-break: break-all` already wraps the
  secret at any width; `user-select: all` is *more* valuable on mobile (tap =
  whole secret selected). Shimmer is `transform`-only and reduced-motion-safe.
- **S5 The Whisper** — returns on mobile (drawer footer, §1.6.3). Nothing is
  added to the top bar: on a 375px bar the Whisper would fight the user
  cluster, and one wink per screen is the law. Summoning the drawer to see
  the summon-time is the right amount of ceremony.
- **The broom lift** stays as-is: on touch it simply never fires (or flashes
  briefly on tap-navigate) — decorative, never informational, per §1.5.

#### 1.6.7 Byte budget & the trade-off ledger

Measured base: **21,442 B**. Ceiling (§4.4): **24 KB total inlined**. Mobile
must be economical; estimated deltas for the increments in §4.2:

| Change | Δ bytes (est.) |
|---|---|
| M1 table wraps | 0 (templates only) |
| M2 forms / touch / spacing block | +450 |
| M3 drawer + mobile shell CSS | +1,150 |
| M3 deletes the legacy 760px rail block | −230 |
| M4 stacked-card pattern | +550 |
| **Net mobile** | **≈ +1.9 KB → ≈ 23.4 KB** |

That fits — but increment 3–4's three theme blocks (~+3.6 KB) then overshoot
the 24 KB ceiling by ~2.9 KB. The base landed ~2.4 KB over its §4.4 estimate,
and mobile inherits the squeeze. Recovery levers, pulled **in this order**
until under ceiling:

1. **Comment diet** in `theme.css` — the shipped file carries ~1.2 KB of
   prose comments; tighten to terse one-liners (the design doc is the prose
   home). Est. −0.9 KB.
2. **Drop the back-compat aliases** (`--nb-night`, `--nb-shadow`) — they were
   promised for one release. Est. −0.1 KB.
3. **Trim theme blocks** to genuinely-changed tokens only (the §3 blocks
   re-state some inherited values for readability). Est. −0.5 KB.
4. **Cut M4 (stacked cards)** — Tier 1 scroll-wrap remains the guaranteed
   floor. −0.55 KB.
5. Only then, and by explicit decision recorded here: raise the ceiling to
   26 KB (the file is inlined and rides the page's gzip; the wire delta of
   the whole mobile layer is ≈ 0.4 KB compressed). Not to be done by drift.

Rules of engagement: mobile CSS lands ≤ 2.0 KB net or gets trimmed before
merge; every mobile PR states the new `wc -c` of `theme.css` in its
description.

---

## 2. The theme system

### 2.1 Mechanism

**One component layer, N token blocks, chosen by `data-theme` on `<html>`.**

- `layout.php` already emits `<html data-theme="light">` — repurpose that
  attribute to carry the theme slug: `<html data-theme="<?= $e($theme) ?>">`.
- `src/Admin/Controller.php` injects the value into the View's shared data,
  next to `appName`:

  ```php
  'theme' => $auth->user()?->theme ?? 'nimbus',
  ```

  `nb_users.theme` (VARCHAR(40) NULL) already exists in
  `src/Database/migrations/001_core.php`, and `Nimbus\Auth\User` already
  carries `?string $theme` — the storage is *done*; only the write path and
  the attribute are missing. `login.php` (pre-auth) always renders the default
  sky.
- Validation: the theme slug must be in a hardcoded allowlist
  (`['nimbus','nocturne','daybreak','grimoire']`) at write time *and* render
  time (fall back to `nimbus`), so a stray DB value can't inject attribute
  content or select a dead theme.

### 2.2 The token-only rule

Themes may **only** re-declare custom properties. No theme may add selectors
that target components, and templates are never forked per theme. The entire
pattern:

```css
/* Base: full token set + all component CSS (this file, §1) */
:root { /* Nimbus tokens — the default, see §1.2 */ }

/* A theme = one block of variable overrides. Nothing else. */
[data-theme="nocturne"] {
  color-scheme: dark;
  --nb-bg: #13102b;
  --nb-surface: #1c1840;
  /* …only variables… */
}
```

Enforcement is social + review-time: the `docs/design` rule is *"if your theme
needs a selector, the base is missing a token — add the token."* This is what
keeps four themes from quadrupling the CSS: each block is ~35 declarations,
≈ 1.2 KB.

### 2.3 The picker

- **Where:** the Settings page — currently the `stub.php` "being conjured"
  placeholder, so this is Settings' first real feature. A fieldset of four
  radio cards ("theme swatches"): each shows the theme's sky gradient as a
  small rounded chip (pure CSS, `background: var(gradient)` hardcoded per
  radio via inline style or four tiny utility classes — an acceptable, cheap
  exception since swatches must show *other* themes' colors), name, and
  one-line mood. Save button posts the form.
- **Wire:** `POST /admin/settings/theme` → CSRF check (`requireCsrf`) →
  allowlist check → `UPDATE nb_users SET theme = ? WHERE id = ?` → redirect
  back with `?flash=theme`. Flash copy: "Theme changed. ✦"
- **JS (optional, ~8 lines, progressive enhancement):** instant preview before
  saving —

  ```html
  <script>
  document.querySelectorAll('input[name=theme]').forEach(r =>
    r.addEventListener('change', () =>
      document.documentElement.dataset.theme = r.value));
  </script>
  ```

  With JS off, the picker still works — you just see the result after the
  round-trip. No persistence in JS, no localStorage, no FOUC risk (the server
  renders the right attribute on every page).

### 2.4 Dark/light and `prefers-color-scheme`

- Each named theme is **committed** to one appearance (Nimbus/Daybreak/Grimoire
  are light-content; Nocturne is dark) and declares `color-scheme` accordingly
  so native widgets (selects, datetime pickers, scrollbars) match.
- **v1 ships explicit choice only.** An "Auto (match device)" option is a
  known follow-up, *not* in the first cut: doing it token-only means either
  duplicating Nocturne's block under
  `@media (prefers-color-scheme: dark) { [data-theme="auto"] { … } }` (+1.2 KB,
  acceptable) or adopting `light-dark()` per token. Decide when it's asked for;
  the mechanism forecloses nothing.
- The admin never auto-flips based on OS while a named theme is chosen —
  predictability beats cleverness in a tool.

---

## 3. The themes

Four skies, one soul. Shared bones: the Sky sidebar, gold-is-earned, the Charm
Line, identical layout and type. Each block below is complete — paste-ready
overrides of §1.2 (omitted variables inherit the Nimbus default; shape, space,
type, and motion tokens are **never** overridden by themes).

Contrast: every listed text-on-surface pair targets ≥ 4.5:1 (key ratios noted).
Run the final hexes through a checker in the PR; treat any <4.5 body-text pair
as a blocker.

### 3.1 Nimbus — the default *(evolution of today's night-sky/gold)*

**Mood:** a lit desk under a violet night — calm daylight surfaces, the sky in
the margin. This *is* the `:root` block in §1.2; nothing to override. Changes
vs. what ships today: brand and muted darkened for AA, star/sidebar colors
tokenized, horizon glow, twinkle, Charm Line, constellation empty states,
Reveal, Whisper.

### 3.2 Nocturne — the dark one

**Mood:** the observatory at 2 a.m. — the whole room joins the sky; gold
finally gets to glow.

```css
[data-theme="nocturne"] {
  color-scheme: dark;

  --nb-brand:        #8b7dff;   /* on dark surfaces; used for fills w/ dark text? no — see note */
  --nb-brand-dark:   #a99dff;   /* hover lightens in dark themes            */
  --nb-brand-bright: #8b7dff;
  --nb-brand-tint:   rgba(139,125,255,.16);
  --nb-on-brand:     #17133a;   /* dark ink on light-indigo fills (7.2:1)   */
  --nb-link:         #b3a8ff;   /* 8.1:1 on --nb-surface                    */

  --nb-gold:      #f0c24b;      /* reads as text here: 8.6:1 on surface     */
  --nb-gold-soft: #f6d888;
  --nb-gold-ink:  #f0c24b;      /* gilt ink = real gold in the dark         */
  --nb-gold-tint: rgba(240,194,75,.10);

  --nb-bg:          #13102b;
  --nb-surface:     #1c1840;
  --nb-surface-2:   #241f4e;
  --nb-line:        #2e2960;
  --nb-line-strong: #3d3778;
  --nb-ink:         #ecebf8;    /* 13.9:1 on surface                        */
  --nb-muted:       #a8a5cd;    /* 5.6:1 on surface                         */
  --nb-faint:       #7d79a8;

  --nb-ok:     #4cc98d;  --nb-ok-bg:     #14382a;  --nb-ok-text:     #7ee2ae;
  --nb-warn:   #e6b566;  --nb-warn-bg:   #3a2c10;  --nb-warn-text:   #f2c76e;
  --nb-danger: #ff7b80;  --nb-danger-bg: #40181c;  --nb-danger-text: #ff9a9e;
  --nb-on-danger: #2b0d0f;

  --nb-sky: linear-gradient(180deg, #0b0920 0%, #140f38 60%, #1b1547 100%);
  --nb-sky-text:      #b6b3dd;
  --nb-sky-text-hi:   #ffffff;
  --nb-sky-hover:     rgba(255,255,255,.06);
  --nb-sky-active:    rgba(240,194,75,.14);
  --nb-sky-active-rg: rgba(240,194,75,.35);
  --nb-star-a:        #ffffff;
  --nb-star-b:        var(--nb-gold-soft);
  --nb-star-opacity:  1;        /* stars brighten when the room darkens     */
  --nb-sky-foot:      rgba(182,179,221,.45);
  --nb-sky-glow:      rgba(139,125,255,.14);
  --nb-const:         #4f4890;  --nb-const-line: rgba(139,125,255,.14);

  --nb-shadow-1:   0 1px 2px rgba(0,0,0,.4), 0 10px 30px rgba(0,0,0,.35);
  --nb-shadow-2:   0 8px 26px rgba(0,0,0,.5);
  --nb-shadow-pop: 0 24px 60px rgba(0,0,0,.7);
  --nb-focus:      0 0 0 3px rgba(139,125,255,.30);
}
```

Note the dark-theme inversion rules baked in above: **primary buttons flip to
light-indigo fill with dark ink** (`--nb-on-brand: #17133a`) because white text
on a bright-enough dark-mode accent can't reach AA; hover *lightens*
(`--nb-brand-dark` is lighter than `--nb-brand`); semantic chips go
deep-bg/bright-text. The component CSS never knows — it just uses the pairs.

### 3.3 Daybreak — the bright one

**Mood:** the broom at dawn — cold clear air, high blue, sun-gold accents.
For people who find any dark chrome gloomy.

```css
[data-theme="daybreak"] {
  --nb-brand:        #2166b4;   /* white text: 5.9:1                         */
  --nb-brand-dark:   #17538f;
  --nb-brand-bright: #3f8ede;
  --nb-brand-tint:   #e3effc;
  --nb-link:         #1d5da6;   /* 6.5:1 on white                            */

  --nb-gold:      #f6b73c;      /* the sun; icons/accents only               */
  --nb-gold-soft: #ffd98a;
  --nb-gold-ink:  #8a5f10;      /* 5.5:1 on surface                          */
  --nb-gold-tint: #fff6e0;

  --nb-bg:          #f2f7fd;
  --nb-surface:     #ffffff;
  --nb-surface-2:   #f7fafe;
  --nb-line:        #dfe8f2;
  --nb-line-strong: #c8d7e8;
  --nb-ink:         #1c2b3a;    /* 14.6:1                                    */
  --nb-muted:       #52667c;    /* 5.6:1                                     */
  --nb-faint:       #8398ad;

  --nb-ok:     #23855a;  --nb-ok-bg:     #e4f7ec;  --nb-ok-text:     #1c6e4a;
  --nb-warn:   #a86e07;  --nb-warn-bg:   #fdf2da;  --nb-warn-text:   #815405;
  --nb-danger: #c22f34;  --nb-danger-bg: #fdeaea;  --nb-danger-text: #ab2429;

  /* Dawn sky: deep blue horizon warming to morning blue. White text ≥5.5:1
     across the gradient. Stars fade to faint "day stars". */
  --nb-sky: linear-gradient(180deg, #123f74 0%, #1e63b4 62%, #2d7bd0 100%);
  --nb-sky-text:      #d7e7f8;
  --nb-sky-text-hi:   #ffffff;
  --nb-sky-hover:     rgba(255,255,255,.10);
  --nb-sky-active:    rgba(255,217,138,.22);
  --nb-sky-active-rg: rgba(255,217,138,.50);
  --nb-star-a:        #ffffff;
  --nb-star-b:        #ffd98a;
  --nb-star-opacity:  .35;
  --nb-sky-foot:      rgba(215,231,248,.55);
  --nb-sky-glow:      rgba(255,217,138,.20);   /* sunrise at the horizon     */
  --nb-const:         #b9d2ec;  --nb-const-line: rgba(33,102,180,.10);

  --nb-shadow-1: 0 1px 2px rgba(18,63,116,.06), 0 10px 30px rgba(18,63,116,.08);
  --nb-shadow-2: 0 8px 26px rgba(18,63,116,.14);
  --nb-focus:    0 0 0 3px #d5e6f9;
}
```

### 3.4 Grimoire — the warm scholarly one

**Mood:** a candlelit spellbook library — parchment, walnut ink, bottle-green
shelves, brass-gold. The cozy option; excellent for long editing sessions.

```css
[data-theme="grimoire"] {
  --nb-brand:        #2e6b4f;   /* bottle green; white text: 6.3:1          */
  --nb-brand-dark:   #245640;
  --nb-brand-bright: #3f8a67;
  --nb-brand-tint:   #e6f0e9;
  --nb-link:         #266046;   /* 6.9:1 on surface                          */

  --nb-gold:      #d9a53c;      /* brass; icons/accents                      */
  --nb-gold-soft: #ecc87f;
  --nb-gold-ink:  #7c5a14;      /* 5.6:1 on surface                          */
  --nb-gold-tint: #f9efd8;

  --nb-bg:          #f4eee1;    /* parchment                                 */
  --nb-surface:     #fdfaf2;
  --nb-surface-2:   #f6f1e4;
  --nb-line:        #e4dbc6;
  --nb-line-strong: #d2c6a8;
  --nb-ink:         #33281c;    /* 13.5:1                                    */
  --nb-muted:       #6b5c46;    /* 5.9:1                                     */
  --nb-faint:       #98876c;

  --nb-ok:     #2e7a4e;  --nb-ok-bg:     #e6f2e4;  --nb-ok-text:     #276542;
  --nb-warn:   #a06413;  --nb-warn-bg:   #f9ecd2;  --nb-warn-text:   #7c4d0e;
  --nb-danger: #a83a32;  --nb-danger-bg: #f9e6e2;  --nb-danger-text: #93312b;

  /* Candlelit shelves: deep green-black, brass star-glints. */
  --nb-sky: linear-gradient(180deg, #17251d 0%, #1f3d2e 60%, #274a38 100%);
  --nb-sky-text:      #cfdccf;
  --nb-sky-text-hi:   #ffffff;
  --nb-sky-hover:     rgba(255,255,255,.07);
  --nb-sky-active:    rgba(217,165,60,.18);
  --nb-sky-active-rg: rgba(217,165,60,.40);
  --nb-star-a:        #f0e6cf;    /* dust-motes in candlelight               */
  --nb-star-b:        #ecc87f;
  --nb-star-opacity:  .7;
  --nb-sky-foot:      rgba(207,220,207,.5);
  --nb-sky-glow:      rgba(217,165,60,.16);    /* the candle                  */
  --nb-const:         #cbbfa2;  --nb-const-line: rgba(124,90,20,.12);

  --nb-shadow-1: 0 1px 2px rgba(51,40,28,.06), 0 10px 30px rgba(51,40,28,.08);
  --nb-shadow-2: 0 8px 26px rgba(51,40,28,.14);
  --nb-shadow-pop: 0 24px 60px rgba(23,37,29,.55);
  --nb-focus:    0 0 0 3px #ddebe2;
}
```

*(A fifth, high-contrast "Owl" theme — near-black on white, 2px borders,
heavier focus rings — is a natural later addition and is pure tokens; noted,
not specced.)*

---

## 4. Buildability & sequencing

### 4.1 Cost classification

- **Pure token swap (cheap):** all four palettes; every component in the §1.4
  table marked "token-only"; the dark-mode button inversion (it's just pair
  values); the auth screen retheming.
- **Small CSS additions (still cheap, no templates):** Charm Line, horizon
  glow, twinkle, constellation empty states, Reveal shimmer, `:focus-visible`
  rules, reduced-motion block, `.nb-btn-danger`, `.nb-link`, `.nb-alert-warn`;
  the M2 responsive block (forms/touch/spacing, §1.6.5) and the `.nb-stack`
  card pattern (§1.6.4).
- **Small template tweaks:** `layout.php` — `data-theme` from shared data,
  `aria-current` on active nav, Whisper in `.nb-side-foot`, and the drawer
  trio (checkbox, scrim, hamburger — §1.6.3); `tokens/index.php`,
  `roles/index.php`, `users/index.php` — wrap the tables in `.nb-table-wrap`
  (M1); `entries/index.php` + `tokens/index.php` — `data-label` attrs (M4);
  `stub.php` → real `settings.php` with the theme picker; `public/index.php`
  — `NB_START` constant (if absent).
- **PHP wiring (small):** one shared-data line in `Admin\Controller::__construct`,
  one `POST /admin/settings/theme` action + route (allowlist + CSRF + one
  UPDATE), `UserRepository` already hydrates `theme`.
- **Vanilla JS (optional, ~14 lines total):** the picker's instant preview
  (§2.3, ~8) and the drawer's Escape/focus-return (§1.6.3, ~6). Both are
  enhancements over fully-working no-JS paths: the theme choice round-trips
  server-side, and the drawer opens/closes via its checkbox.

### 4.2 Increments (each an independently shippable PR)

1. **Tokens under the same paint.** Refactor `theme.css` onto the §1.2 token
   set with *zero intended visual change* (brand/muted AA darkening is the one
   deliberate, subtle shift). Fold in the cleanup list (§4.3) and the
   accessibility rules (focus-visible, reduced-motion, `aria-current`).
   Screenshot-diff the six main pages by eye before/after.
2. **The signatures.** Charm Line, horizon glow + twinkle, constellation empty
   states, the Reveal, the Whisper, `.nb-btn-danger`/`.nb-link`, tokens-page
   table wrap. This is the "Nimbus gets a soul" PR — small, loud, demoable.
3. **The theme system + Nocturne.** `data-theme` wiring, settings page with
   the picker (two themes at first proves the mechanism), POST handler,
   allowlist, Nocturne token block. Login stays default-sky.
4. **Daybreak + Grimoire (+ preview JS).** Two more token blocks, swatch chips
   on the picker, the 8-line instant-preview enhancement. Marketing
   screenshots come from this PR.

The responsive work (§1.6) ships as four further increments, safest first.
M1 and M2 are independent of everything; M3/M4 build on nothing but the
shipped shell, so the M-track can run in parallel with increments 3–4:

5. **M1 — wrap the bare tables.** `roles/index.php`, `users/index.php`,
   `tokens/index.php`: two lines each around the existing `<table>`. Pure
   templates, zero CSS, zero JS, no security surface. Kills page-level
   horizontal scroll everywhere (audit #1).
6. **M2 — forms, touch targets, spacing.** The §1.6.5 + §1.6.2 CSS blocks
   (grid collapse, 16px inputs, 44px targets, stepped padding, page-head
   wrap). Pure CSS, no templates, no JS. The legacy rail nav stays functional
   until M3.
7. **M3 — the drawer.** `layout.php` markup (checkbox, scrim, hamburger,
   top-bar brand — §1.6.3), drawer/shell CSS, deletion of the legacy 760px
   rail block, the 6-line Escape script. The only mobile increment touching
   markup + JS; review focus: the new labels/checkbox contain no user data
   and the script takes no input, so the security surface is nil, but eyes on
   it anyway.
8. **M4 — stacked cards.** `.nb-stack` CSS + `data-label` attrs in
   `entries/index.php` and `tokens/index.php` (§1.6.4). Attribute values pass
   through the existing `$e()` escaper. Cuttable if the byte ceiling bites
   (§1.6.7).

### 4.3 Existing defects to fix in passing (increment 1)

- `var(--nb-border)` is referenced in `.nb-token-secret`,
  `.nb-missing-type__value`, `.nb-scope-list` but **never defined** → repoint
  to `--nb-line` (the fallbacks mask two of the three today; `.nb-missing-type__value`
  has none).
- `.nb-check` is **defined twice** with conflicting display values (line ~186
  `inline-flex`, line ~271 `block`) — merge into one definition (`block`
  layout with `inline-flex` content alignment) and delete the duplicate.
- `.nb-link` is used by `tokens/index.php` (Pause/Resume) but has no CSS —
  currently renders as a default browser button. Define it (§1.4).
- `.nb-table` renders **bare** (no `.nb-table-wrap`: no rounded panel, no
  overflow protection → page-level horizontal scroll on phones) on **three**
  pages: tokens, roles, users. Still true post-increment-2; fixed as M1
  (§1.6.4, §4.2).
- CSS declares `"Inter"`/`"Sora"` that never load — replace with the honest
  system stack (§1.2).

### 4.4 Weight budget & performance guardrails

- **Budget:** **total inlined CSS ≤ 24 KB raw** — the binding ceiling (≈ 5 KB
  over the wire if the server gzips; it's inlined into HTML so it rides the
  page's encoding). Shipped base after increments 1–2: 21,442 B — ~2.4 KB
  over the original ≤ 19 KB base estimate, which is why the ledger and
  recovery levers in §1.6.7 now govern: mobile ≤ 2.0 KB net, each theme block
  ≤ 1.3 KB, and the ordered cuts (comment diet → aliases → theme-block trim →
  M4) apply until the total is under ceiling. A comment at the top of
  `theme.css` states the budget; PR review enforces it, and every CSS PR
  states the new `wc -c`.
- **No webfonts, no images, no external requests.** Every visual in this spec
  is gradients, borders, shadows, and unicode glyphs. The logo stays an
  inlined SVG.
- **No hydration, no framework, no build step.** Total JS added by this spec:
  8 optional lines on one settings page (theme preview, §2.3) + 6 lines in
  the shell (drawer Escape/focus-return, §1.6.3) — both progressive
  enhancements; nothing breaks with JS off (the drawer opens and closes via
  its checkbox regardless).
- **Animation discipline:** exactly two ambient animations (twinkle: one
  `opacity` keyframe; shimmer: one `transform` keyframe, plays once), both
  compositor-friendly, both dead under reduced-motion. No `filter`, no
  `backdrop-filter` (Nocturne's depth comes from shadows, not blur — blur is
  the classic dark-mode perf trap on low-end machines).
- **Risk flagged & avoided:** per-theme CSS files would mean a request or a
  read-per-theme; instead all four blocks live in the one inlined file
  (+~4 KB total) — cheaper than any alternative and keeps `View.php`'s single
  `file_get_contents` untouched.

---

## Appendix A — key contrast pairs (verify before merge)

| Pair | Nimbus | Nocturne | Daybreak | Grimoire |
|---|---|---|---|---|
| ink / surface | 14.9 | 13.9 | 14.6 | 13.5 |
| muted / surface | 5.4 | 5.6 | 5.6 | 5.9 |
| link / surface | 6.2 | 8.1 | 6.5 | 6.9 |
| on-brand / brand (buttons) | 5.7 | 7.2 | 5.9 | 6.3 |
| ok-text / ok-bg | 5.0 | 5.4 | 5.2 | 5.1 |
| danger-text / danger-bg | 5.4 | 5.6 | 5.5 | 5.2 |
| sky-text / sky (mid-gradient) | 7.8 | 8.9 | 5.6 | 8.2 |

Ratios are design-time targets computed from the specced hexes; re-verify the
final values (esp. gradient midpoints) with a WCAG checker in the PR.

## Appendix B — microcopy register

- Empty states: *fact first, wink second.* "No API tokens yet" / "Create one
  above to let a site read your published content."
- Success flashes may carry one ✦. Errors never joke.
- The Whisper: "summoned in N ms". The public-site cache signal (roadmap)
  stays "rendered in N ms · powered by NimbusCMS" — the admin whispers,
  the marketing speaks.
