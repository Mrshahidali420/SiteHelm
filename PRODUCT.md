# Product

<!-- impeccable:product-schema 1 -->

## Register

product

## Platform

web

## Stack

Two codebases, one product.

- **Plugin (free + Pro):** PHP 8.1+, WordPress 6.6+, MCP revision 2025-06-18. Free plugin in this repo (v0.6.0); SiteHelm Pro (v0.4.0) is a separate private repo at `Desktop/SiteHelm-Pro`, distributed through Freemius. Console screens are server-rendered WordPress admin pages under `src/Admin`.
- **Marketing site (wpsitehelm.com):** **Astro**, chosen by the user 2026-08-27. Static output; content collections suit the operation catalogue, changelog, and Free/Pro comparison. The previous `site/` build in `Desktop/SiteHelm-marketing` was deleted 2026-08-27 — this is a fresh build, not a refactor.

## Users

**Primary: the solo WordPress site owner.** Reordered by the user on 2026-08-27; this record previously named the agency operator first. One person responsible for their own live site, who wants to point an AI client at it without risking it. They have no staging site, no colleague reviewing changes, and no appetite for restoring from a backup. Their fear is not "the tool will error" but "the tool will succeed at the wrong thing and I won't notice for a week." They must get connected without understanding MCP, JSON-RPC, or Application Passwords — the Connect screen carries them completely or the plugin fails for them. Where their needs conflict with the agency operator's, they win.

**Secondary: the agency operator** managing several client WordPress sites. They install SiteHelm on a client's site, connect their AI client once, and afterwards return mainly to see what the agent actually did and whether anything was refused. Their context is a browser with several client sites open, switching between them through the day. Their job to be done is answering, quickly and with evidence, *what changed on this client's site and can I undo it*. This is the same question the solo owner asks, at higher volume — which is why one console serves both.

**Tertiary: the developer or implementer** who reads the operations list to learn the surface and uses Status to work out why a module is version-blocked. Their needs are met by those screens carrying real detail, not by a separate expert mode.

## Product Purpose

SiteHelm lets an AI agent operate a live WordPress site through MCP without the site owner having to trust the agent. Every write is previewed, approved, applied, verified, and recorded with a rollback reference. The admin console is the human end of that contract: the place a person connects an AI client, sees what was done, and confirms the site is in a state SiteHelm can work on. Success is one person, responsible for their own live site and with no staging copy to fall back on, connecting in under a minute and never afterwards having to wonder what an agent did to it. An agency operator gets the same guarantee at higher volume, across several client sites.

## Positioning

The only WordPress MCP server that shows a human exactly what an AI agent changed, and lets them put it back.

The mechanism a competitor cannot truthfully copy without rebuilding is the **two-phase write**: preview returns the exact field-level before/after plus a single-use plan token; apply resends the same arguments with that token (changed arguments are a `StalePlan` refusal, not a surprise write); the engine snapshots, writes, reads the site back, and compares what persisted against what preview promised; rollback restores from that snapshot.

Supporting differentiators, all verifiable in the codebase: read-back verification that reports what *persisted* rather than what was *sent*; permission taken from the authenticating WordPress user's real capabilities, re-checked inside every handler, never a single god-mode key; a deliberately small surface of 80 operations behind 11 dispatchers with schemas that reject unknown properties, against the typical 200+ loosely-specified tools; eleven typed error codes with operator-facing remedies and never a stack trace, path, or SQL string; and four permanent exclusions recorded as requirements so nobody re-proposes them — no arbitrary PHP execution, no unrestricted SQL, no unrestricted filesystem access, no irreversible deletion.

**The competitive wedge, decided 2026-08-25.** The two closest WordPress MCP products sell different things and neither one sells safety. One sells breadth — a tool count, a full toolkit, hundreds of loosely specified tools. The other sells raw power, advertising PHP execution, WP-CLI, database queries, and file edits on a live site as the feature. SiteHelm sells the opposite: a closed set of named operations, no code execution, preview before every write, an activity log, one-click rollback, a capability allowlist, and per-operation and per-module switches. The positioning line is **the one you can let near a client's site**. Two rules bind marketing copy: never name either competitor in public copy — describe the second as "the open-shell approach" — and do not compete on tool count, because out-counting is the other product's game and losing it is the point.

## Operating Context

- **The agent side:** an MCP client — Claude, Claude Code, Cursor, VS Code, or any other — connected to the site. Agents discover the surface at runtime by calling a dispatcher with no `operation` argument rather than memorising it.
- **The human side:** the WordPress admin console. Five screens — Connect, Activity, Status, Operations, Modules — plus rollback, write-pause, and credential-revoke controls. This is where the owner watches, pauses, and undoes.
- **The evaluation side:** the marketing site and the GitHub README, where a wary buyer decides whether this is safe enough to install at all.
- **Free/Pro split:** the free plugin is the platform and the safety model. Pro adds WooCommerce, deep SEO for Yoast and Rank Math, bulk metadata, schema, audit-driven fixes, and forms across seven more plugins. Every Pro operation inherits the free safety model unchanged, and degrades softly rather than erroring when unlicensed.

## Capabilities and Constraints

**Free (v0.6.0):** 80 operations across nine modules behind 11 dispatchers — content read/write, media read/write, menus read/write, Elementor read/write including global colour and typography tokens and theme-builder display conditions, ACF and Meta Box fields, SEO metadata across seven providers, comment moderation, user administration, site settings, Contact Form 7 forms, and site-wide content search.

**Pro (v0.4.0):** WooCommerce (eight operations; products writable, orders and customers read-only), SEO settings read/write, bulk metadata, Rank Math 404 and redirection tables, per-post schema, audit-driven fixes, forms across seven more plugins.

**Constraints:** PHP 8.1+, WordPress 6.6+. WooCommerce operations require WooCommerce 8.0+. Pro requires free 0.6.0+ and a Freemius licence.

**Terminology that must stay identical across console, site, and docs:** operation, dispatcher, module, plan token, preview, apply, rollback, write pause, activity log, capability check.

**Open:** the marketing site's information architecture, and whether the operation catalogue ships as a browsable page or stays in `docs/OPERATIONS.md`.

## Direction

The v1.1 band is closed; nothing is queued as committed work. `ROADMAP.md` is the authority and is structured in four bands — **Planned next** (REQ-0092 site-wide content search and bulk change, REQ-0085 plugins and themes, REQ-0086 site check-up, REQ-0087 per-app permissions, REQ-0088 scheduled write windows and an approval queue), **Shipped**, **Considering**, and **Permanently excluded**. Anything genuinely new must be chosen from Considering: more page builders (Bricks, Beaver Builder, Divi), popular theme settings, Elementor add-on packs, block-library plugins, project memory, an in-admin AI chat, saved prompts, stock images, page export/import, multisite, CRM connections.

Two structural rules govern what direction is even allowed. Every roadmap row is scoped with its preview, snapshot, and rollback policy decided *before* implementation. And the **Permanently excluded** band — arbitrary PHP, unrestricted SQL, unrestricted filesystem access, irreversible deletion, plus WP-CLI passthrough, raw option writes, snippet stores, and theme-file editing — is a design decision that will not be revisited, not a backlog. Marketing must present exclusions as the product, never apologise for them.

## Brand Commitments

- **Name:** SiteHelm, selected 2026-07-23 after a twelve-candidate collision screen. Domain **wpsitehelm.com**, decided 2026-08-25.
- **Binding naming constraints:** never imply official WordPress affiliation; do not use `WP` in a way that suggests endorsement; do not use another plugin's trademark.
- **Real brand assets exist and must be sampled, never re-invented or redrawn** — `Desktop/SiteHelm-Pro/brand/`: `logo-1024.png`, `mark-transparent-1024.png`, `icon-256x256.png`, `icon-128x128.png`, `freemius-icon-300.png`. Palette: navy `#0F172A`, sky `#38BDF8`, off-white `#F8FAFC`.
- **Voice:** technically credible, direct, calm, operational. The product never describes itself as magical, autonomous, error-proof, or risk-free, and claims always distinguish tested behaviour from roadmap intent. Binding on marketing copy, not a preference.

## Evidence on Hand

**Real and usable:** 2,814 tests at ≥80% coverage, both badge-verified. The typical-MCP-tool-vs-SiteHelm comparison table in `README.md` — the strongest persuasion material already written. The permanent-exclusions list, credible precisely because it is a list of refusals. `docs/OPERATIONS.md`, `ROADMAP.md`, `CHANGELOG.md`, `SECURITY.md`. The brand assets above, and the release-card generator at `Desktop/SiteHelm-marketing/release-card.py`. Freemius listings: free 37703, Pro 37704.

**Does not exist — must never be fabricated:** no customers, testimonials, case studies, user or install counts, reviews, press, awards, third-party endorsements, uptime or performance statistics, or named agency logos.

**Settled since first written:** the operation count is **80**, taken from the `CapabilityRegistry` and enforced by `tests/Unit/Docs/DocumentationClaimsTest.php`. Two stale numbers used to survive in `README.md` — the badge and the phrase "typed operations" — because the CI gate matched only `/(\d+) operations/` and both evaded it on wording. Both were corrected on 2026-08-28 and the gate widened to read the badge and the bold-and-qualified phrasing too, so a stale README number now fails CI rather than sitting there.

**Pricing — confirmed in the Freemius dashboard 2026-08-27** (store 19416, add-on 37704, plan 62673 "Pro", the only plan). USD. List prices are deliberate anchors; an ongoing 20% coupon returns the effective price to the intended figure:

| Licence | Monthly list → net | Annual list → net | Lifetime list → net | Pricing ID |
|---|---|---|---|---|
| Single site | $6.24 → $4.99 | $48.75 → $39 | $123.75 → $99 | 83841 |
| 3 sites | $11.24 → $8.99 | $86.25 → $69 | $223.75 → $179 | 83842 |
| Unlimited sites | $23.74 → $18.99 | $186.25 → $149 | $436.25 → $349 | 83843 |

The discount is coupon `LAUNCH20` (id 98266): 20%, ongoing with no expiry, **new customers only**, one redemption per user, applying to all licences, all billing cycles, and to **first payment and renewals** — so a customer's renewal price never jumps. Every list price sits at or above Freemius's own recommendation except unlimited lifetime ($436.25 against a $559.99 recommendation). The plan description reads "Deep SEO, WooCommerce, and forms across every major plugin." No trial is offered.

Pricing went live to buyers on 2026-08-27 — the **Release plans to users** toggle is on. Two facts still constrain what may be published. The coupon only reaches a buyer who arrives with it in the checkout link, so every published buy link must be `https://checkout.freemius.com/plugin/37704/plan/62673/?coupon=LAUNCH20` — the bare link shows the list price as the real price. Freemius's Special Coupons tab does not help here: its four slots (cart abandonment, subscription cancellation, checkout exit-intent, renewal recovery) all fire only after a buyer is already leaving, and none sets the arrival price. And because the coupon is new-customers-only it is a genuine launch discount, not a permanent anchor — copy may say "launch pricing", and must never present the list figures as a price anyone is currently expected to pay.

## Brand Personality

*Scoped to the admin console. The marketing site's visual world is decided separately in new-work and has not been established; do not assume this direction transfers to a Persuade surface unchanged.*

A calm instrument panel. Quiet, precise, legible at a glance. State is reported plainly and never celebrated — a connected site says it is connected, it does not congratulate anyone. Low ornament and generous spacing, with real information density where the information is genuinely useful. The voice is that of a colleague who knows the system well and does not need to perform expertise: short sentences, concrete nouns, no exclamation. Three words: **composed, exact, unhurried**.

## Anti-references

- **The typical bought-plugin admin.** Coloured banners, an "Upgrade to Pro" box, review-nag notices, tabs styled as fake browser tabs, gradient buttons. SiteHelm must not read as a marketplace plugin.
- **AI-product purple.** The indigo-to-violet gradient, sparkle icons, glowing borders, "powered by AI" badges that most MCP and LLM tools ship. The whole family is out.
- **The dashboard-with-charts.** Hero metric cards, sparklines, donut charts, analytics framing. SiteHelm has no metric worth charting and pretending otherwise is decoration.
- **Native wp-admin mimicry.** Blending so completely into core WordPress styling that the plugin has no identity — default blue buttons, core table styles, no character of its own.

## Design Principles

**Practice what you preach.** The plugin's whole claim is that an agent's work is visible and reversible. The console must make that literally true on screen: if a change is rollback-eligible, the screen says so at the point the change is listed, not in documentation.

**State before explanation.** Every screen answers its one question in the first line — connected or not, what happened, what is healthy, what can be done. Explanation follows for the reader who wants it; it never comes first.

**A refusal is information, not an error.** SiteHelm refuses by design. Refusals are shown as first-class outcomes with their reason, in the same register as successes, never as red alarm.

**Density where it earns its place.** The operator scanning an activity log wants many rows visible at once. The owner on the connect screen wants one thing at a time. Density is decided per screen by the task, not applied uniformly.

**Never invent reassurance.** If the plugin does not know something — a module's version, whether a rollback will still apply — the screen says it does not know. No green tick stands in for an unverified claim.

## Accessibility & Inclusion

WCAG 2.1 AA, which is the WordPress project's own standard and what the plugin directory expects. Full keyboard operation with a visible focus ring on every interactive element, 4.5:1 contrast for body text and placeholders, 3:1 for large text and meaningful non-text elements, a screen-reader label on every control, and a `prefers-reduced-motion` alternative for every transition. Status is never carried by colour alone — a refused row is marked by its word as well as its hue.
