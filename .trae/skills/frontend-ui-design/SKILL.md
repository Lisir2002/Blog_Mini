---
name: "frontend-ui-design"
description: "Modern responsive UI design for blog frontends: fluid layout, CSS Grid/Flexbox, container queries, typography, accessibility. Invoke when building or reviewing HTML/CSS frontend pages and components."
---

# Frontend UI Design (Blog)

Guidance for designing modern, responsive blog frontends with plain HTML/CSS (works with PHP templates).

## Layout strategy

- Use a **fluid grid**, not fixed pixel widths: percentages, `fr`, `minmax()`, `auto-fit`.
- **Flexbox** for one-dimensional flows (nav bars, card rows); **CSS Grid** for two-dimensional page layout (post grid, sidebar).
- **Container queries** for component-level responsiveness; reserve **media queries** for page-level layout only.
- Use CSS `:has()` to style parents based on content, reducing JavaScript dependencies.
- Use `subgrid` for consistent alignment in nested grids (e.g., card titles/images aligned across cards).

## Typography & spacing

- Fluid typography with `clamp()`: `font-size: clamp(1rem, 2.5vw, 1.5rem)` — eliminates per-breakpoint font rules.
- Body text: 16px+ base, line-height 1.5–1.75, measure ~65–75ch for readability.
- Define design tokens as CSS custom properties (`--space-*`, `--color-*`, `--radius`), plus a light/dark scheme via `prefers-color-scheme`.

## Responsive essentials

- `<meta name="viewport" content="width=device-width, initial-scale=1">` always.
- Images: `max-width:100%; height:auto`, use `srcset`/`sizes`, set `width`/`height` attributes to avoid layout shift; `loading="lazy"` below the fold.
- Handle viewport height with `min-height: 100dvh` (not `100vh`) for mobile browsers.
- Tables/code blocks: wrap in an overflow container (`overflow-x:auto`) instead of breaking layout.
- Test breakpoints at 360 / 768 / 1024 / 1440 px.

## Blog page patterns

- Home: post list as cards (title, excerpt, meta, tags) in `grid-template-columns: repeat(auto-fill, minmax(320px, 1fr))`.
- Article page: single column ~70ch, sticky-ish header, prev/next nav, tag chips.
- Accessible nav: skip link, visible `:focus-visible` outlines, semantic landmarks (`header/nav/main/article/aside/footer`).
- Forms (comment/login): label per input, inline validation messages, adequate tap targets (≥44px).

## Accessibility checklist

- Color contrast ≥ 4.5:1 body text, ≥ 3:1 large text.
- Keyboard-only operable; logical tab order; ARIA only when semantics are insufficient.
- `alt` text for meaningful images; decorative images `alt=""`.

## Performance

- No framework unless needed; one small CSS file with critical styles inlined if desired.
- `font-display: swap` for webfonts; prefer system font stack.
- Minimize JS; progressive-enhance interactions.
