---
name: web-frontend
description: >-
  Builds accessible, responsive web UIs with HTML, CSS, and JavaScript or modern
  frameworks. Use when creating pages, components, forms, client-side state, styling,
  mobile layouts, image optimization, or progressive enhancement.
paths: "**/*.{html,css,js,jsx,tsx,vue,svelte}"
---

# Web Frontend Development

## Core Principles

1. **Progressive enhancement** — HTML works without JS; JS enhances UX.
2. **Mobile-first** — Design for small screens, scale up with media queries.
3. **Accessibility** — Semantic HTML, keyboard nav, ARIA only when needed, color contrast.
4. **Performance** — Lazy load images, compress uploads client-side, minimize bundle size.

## Component Checklist

```
- [ ] Semantic HTML (button not div, label linked to input)
- [ ] Responsive at 320px, 768px, 1024px+
- [ ] Focus states visible
- [ ] Loading, empty, and error states
- [ ] Form validation (client hint + server truth)
- [ ] No layout shift on load
```

## Vanilla JS Pattern (this project)

```javascript
// Module pattern — one file per feature area
const App = (() => {
  const state = { photos: [], polling: false };

  async function fetchPhotos(sinceId = 0) {
    const res = await fetch(`/rsvp/api.php?action=get-reception-photos&key=${KEY}&since_id=${sinceId}`);
    const json = await res.json();
    if (!json.success) throw new Error(json.error);
    return json.data;
  }

  function render() { /* update DOM from state */ }

  return { init() { /* bind events, start poll */ } };
})();

document.addEventListener('DOMContentLoaded', () => App.init());
```

## React / Next.js Pattern

```tsx
// Server Component for data fetch (Next.js App Router)
export default async function Page() {
  const data = await fetch(`${process.env.API_URL}/items`, { next: { revalidate: 60 } });
  return <ItemList items={await data.json()} />;
}

// Client Component for interactivity
'use client';
export function LikeButton({ id }: { id: string }) {
  const [count, setCount] = useState(0);
  return <button onClick={() => like(id).then(setCount)}>{count}</button>;
}
```

## CSS Conventions

- Use CSS custom properties for theme tokens (`--color-primary`, `--space-md`).
- Prefer `rem` over `px` for typography and spacing.
- Use `clamp()` for fluid typography.
- Avoid `!important`; increase specificity instead.

## Forms

```html
<form id="rsvp-form" novalidate>
  <label for="guest-name">Name</label>
  <input id="guest-name" name="guest_name" required autocomplete="name" />
  <button type="submit">Submit</button>
  <p role="alert" aria-live="polite" hidden class="error"></p>
</form>
```

- Disable submit during request; show spinner.
- Display server errors in `role="alert"` region.
- Preserve user input on validation failure.

## Client-Side Image Compression

For upload-heavy apps, compress before upload (see `reception/reception.js` in this repo):

- Max dimension: 1920px
- JPEG quality: 0.80–0.85
- Target: under 500 KB per photo on mobile networks

## PWA / Offline (optional)

- `sw.js` service worker for asset caching
- `manifest.json` for installability
- Cache static assets; never cache authenticated API responses

## Verification

1. Test in Chrome DevTools mobile emulation.
2. Tab through all interactive elements.
3. Run Lighthouse (Performance, Accessibility ≥ 90).
4. Use Playwright MCP or browser tools for visual regression on critical pages.

## Related Skills

- [web-backend-api](../web-backend-api/SKILL.md) — API integration
- [web-testing](../web-testing/SKILL.md) — E2E and visual tests
- [web-auth-security](../web-auth-security/SKILL.md) — CSRF tokens, XSS prevention
