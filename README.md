# VectorIT — vectorsolution.it

Company website for **VectorIT** (custom software development & IT consulting, Austin TX).
Built as a fully static site — no build step, no framework, no database. Open `index.html` in a browser and it works.

## What's in this folder

| File / folder | Purpose |
|---|---|
| `index.html` | Homepage (hero, services, process, work, CTA) |
| `services.html` | Detailed service catalog (6 services) |
| `about.html` | Studio story + founder profile |
| `contact.html` | Contact info + inquiry form |
| `404.html` | Not-found page |
| `css/main.css` | Entire design system (colors, type, layout) |
| `js/main.js` | Vector-field hero animation, scroll reveals, nav, form |
| `assets/` | Logo SVGs + favicon |
| `brand/brand-guide.html` | Full brand guidelines (logo, color, type, voice) |
| `robots.txt`, `sitemap.xml` | SEO plumbing |

## Subscriptions & add-ons — what you actually need

**Nothing is required to run this site except the domain you already own.** Recommended setup:

| Item | Service | Cost |
|---|---|---|
| Domain (vectorsolution.it) | Your registrar | ~$10–30/yr (already owned) |
| Hosting + HTTPS + CDN | **Cloudflare Pages** (or GitHub Pages / Netlify / Vercel) | **$0** |
| Code repository | **GitHub Free** (private repo, no paid add-ons needed) | **$0** |
| Business email (shadab@vectorsolution.it) | **Zoho Mail Free** (up to 5 users) or Google Workspace ($7/user/mo) | $0–7/mo |
| Contact form backend (optional) | **Formspree Free** (50 submissions/mo) — form currently uses mailto:, which costs nothing | $0 |
| Analytics (optional) | **Cloudflare Web Analytics** — no cookie banner needed | $0 |

**No paid GitHub add-ons, no paid plugins, and no monthly website subscription are required.**
Total mandatory recurring cost: the domain renewal only.

## Deploying (Cloudflare Pages, recommended)

1. Push this folder to a GitHub repository.
2. In Cloudflare: **Workers & Pages → Create → Pages → Connect to Git**, pick the repo.
3. Framework preset: **None**. Build command: *(empty)*. Output directory: `/`.
4. Add custom domain `vectorsolution.it` (Cloudflare walks you through the DNS records).
5. Done — HTTPS and global CDN are automatic. Every `git push` redeploys.

## Editing content

- **Text**: edit the HTML files directly — all copy is plain HTML.
- **Colors/fonts**: everything is a CSS variable at the top of `css/main.css`.
- **Contact email/phone/address**: appears in the footer of every page + `contact.html` + the JSON-LD block in `index.html` — search and replace.
- **Form**: to switch from mailto: to a real backend, sign up at formspree.io, then change the form to `<form action="https://formspree.io/f/YOUR_ID" method="POST">` and remove the mailto handler in `js/main.js`.

## Brand quick reference

- Vector Blue `#2E5BDB` · Signal Orange `#FF5A2D` (CTAs only) · Ink `#0F1B33` · Paper `#F7F9FC`
- Type: Archivo (display/body) + IBM Plex Mono (labels) — loaded from Google Fonts
- Full rules: open `brand/brand-guide.html`
