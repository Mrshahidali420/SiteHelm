# Product

## Register

product

## Platform

web

## Users

The primary user is an agency operator who manages several client WordPress sites. They install SiteHelm on a client's site, connect their AI client once, and afterwards return mainly to see what the agent actually did and whether anything was refused. Their context is a browser with several client sites open, switching between them through the day. The job to be done is answering, quickly and with evidence, *what changed on this client's site and can I undo it*.

Two secondary users must not be blocked. A solo, non-technical site owner has to get connected without understanding MCP, JSON-RPC, or Application Passwords — the Connect screen carries them completely or the plugin fails for them. A developer or implementer reads the operations list to learn the surface and uses status to work out why a module is version-blocked; their needs are met by those screens carrying real detail, not by a separate expert mode.

## Product Purpose

SiteHelm lets an AI agent operate a live WordPress site through MCP without the site owner having to trust the agent. Every write is previewed, approved, applied, verified, and recorded with a rollback reference. The admin console is the human end of that contract: the place a person connects a client, sees what was done, and confirms the site is in a state SiteHelm can work on. Success is an operator who connects in under a minute and never has to ask what an agent did to their client's site.

## Positioning

The only WordPress MCP server that shows a human exactly what an AI agent changed, and lets them put it back.

## Brand Personality

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
