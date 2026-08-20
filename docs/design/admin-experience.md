# The Nimbus Admin Experience

*Design specification for the NimbusCMS admin — design language, theme system, and build plan.*

**Status:** proposed · **Audience:** the engineer implementing it · **Scope:** `src/View/themes/nimbus/*` (theme.css, layout.php, templates), `src/Admin/Controller.php` wiring, one small settings/profile write path. No public-site changes.

This spec is grounded in the shipped admin: the `nb-` component vocabulary in
`src/View/themes/nimbus/theme.css` (~16 KB, inlined via `file_get_contents` in
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
| **Sidebar** | The Sky (S1): `--nb-sky` gradient, star layer, horizon glow, twinkle. 236px fixed. | `.nb-side`, `::before/::after` | token-only + ~10 lines |
| **Brand** | Gold broom `logo.svg` + app name, weight 800. Unchanged. | `.nb-brand` | none |
| **Nav item** | Rest: `--nb-sky-text`. Hover: `--nb-sky-hover` bg, text → `--nb-sky-text-hi`. Active: `--nb-sky-active` bg + inset 1px `--nb-sky-active-rg` ring, icon → `--nb-gold` (today's treatment, tokenized). **New:** `:focus-visible` gets `outline: var(--nb-focus-ring); outline-offset: 2px` — currently keyboard users get nothing. | `.nb-nav a`, `.active`, `.nb-ic` | token-only + focus rule |
| **Top bar** | 64px, `--nb-surface`, hairline bottom. `.nb-top-l` (empty today) gains a muted breadcrumb slot later — reserved, not built now. Avatar keeps `--nb-brand-bright` gradient. | `.nb-top`, `.nb-user`, `.nb-avatar` | token-only |
| **Page head** | h1 at `--nb-fs-xl` + Charm Line (S2). Actions right-aligned as today. | `.nb-page-head`, `.nb-head-actions` | +6 lines CSS |
| **Cards** | Surface, `--nb-radius`, `--nb-shadow-1`; hover = broom lift. Count in display type. | `.nb-cards`, `.nb-card`, `.nb-card-ic/-count/-label` | token-only |
| **Panels** | Surface + `--nb-shadow-1`. | `.nb-panel` | token-only |
| **Tables** | Wrap in `.nb-table-wrap` (rounded, shadowed, `overflow:auto`). Header row: `--nb-surface-2` bg, `--nb-fs-s` uppercase `--nb-muted`. Row hover: `--nb-surface-2`. Row borders `--nb-line`. **Fix:** `tokens/index.php` renders `.nb-table` bare — wrap it in `.nb-table-wrap` like collections does. | `.nb-table`, `.nb-table-wrap`, `.nb-actions-col`, `.nb-row-actions` | token-only + 1 template line |
| **Forms/fields** | Label 600 @ 13px; input 11×13px padding, `--nb-radius-m`, focus = brand border + `--nb-focus` halo (today's pattern, tokenized). Help text `--nb-help` in `--nb-muted`. | `.nb-field`, `.nb-help`, `.nb-form-card`, `.nb-grid-2`, `.nb-section-title` | token-only |
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
- **Responsive.** Keep the single existing breakpoint at 760px (sidebar becomes
  a horizontal scrollable rail, footer hidden). Add one tablet nicety at
  1024px: content padding drops to `--nb-sp-5`, `.nb-grid-2` collapses to one
  column. Tables rely on `.nb-table-wrap`'s `overflow:auto` — which is why the
  bare table on the tokens page must be wrapped.
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
  rules, reduced-motion block, `.nb-btn-danger`, `.nb-link`, `.nb-alert-warn`.
- **Small template tweaks:** `layout.php` — `data-theme` from shared data,
  `aria-current` on active nav, Whisper in `.nb-side-foot`;
  `tokens/index.php` — wrap the table in `.nb-table-wrap`; `stub.php` →
  real `settings.php` with the theme picker; `public/index.php` — `NB_START`
  constant (if absent).
- **PHP wiring (small):** one shared-data line in `Admin\Controller::__construct`,
  one `POST /admin/settings/theme` action + route (allowlist + CSRF + one
  UPDATE), `UserRepository` already hydrates `theme`.
- **Vanilla JS (optional, ~8 lines):** the picker's instant preview (§2.3).
  Nothing else in this spec needs JS. The theme choice itself round-trips
  server-side and needs **no** JS to persist or apply.

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
- `.nb-table` on the tokens page is missing its `.nb-table-wrap` (no rounded
  panel, no overflow protection).
- CSS declares `"Inter"`/`"Sora"` that never load — replace with the honest
  system stack (§1.2).

### 4.4 Weight budget & performance guardrails

- **Budget:** base component layer ≤ 18 KB raw (today: 16.2 KB — the token
  refactor is roughly weight-neutral; signatures add ~1.5 KB), each theme
  block ≤ 1.3 KB, **total inlined CSS ≤ 24 KB raw** (≈ 5 KB over the wire if
  the server gzips; it's inlined into HTML so it rides the page's encoding).
  A comment at the top of `theme.css` states the budget; PR review enforces it.
- **No webfonts, no images, no external requests.** Every visual in this spec
  is gradients, borders, shadows, and unicode glyphs. The logo stays an
  inlined SVG.
- **No hydration, no framework, no build step.** Total JS added by this spec:
  8 optional lines on one settings page.
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
