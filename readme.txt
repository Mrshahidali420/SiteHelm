=== SiteHelm ===
Contributors: mrshahidali
Tags: mcp, ai agent, automation, elementor, rest api
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.11.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let an AI agent run your WordPress site safely: every write is previewed, snapshotted, verified and reversible — over the Model Context Protocol.

== Description ==

SiteHelm connects your WordPress site to any AI agent that speaks MCP (the Model Context Protocol) — Claude Desktop, Claude Code, and a growing number of editors and agent frameworks. The agent gets a catalogue of named operations covering content, media, menus, Elementor, custom fields, SEO, forms, comments, users, plugins and themes, and site settings. It does not get PHP, SQL or a shell.

**Five gates on every write, none of them skippable by the caller:**

1. **Preview** — the write is planned as an exact diff before anything changes.
2. **Capability** — the WordPress user's capability is re-checked inside every handler; the agent can never do what the user could not do by hand.
3. **Snapshot** — the prior state is captured before the write lands.
4. **Verification** — the result is read back and compared against what the preview promised. A mismatch is reported as a failure, not a success.
5. **Rollback** — anything recorded can be rolled back from the admin console.

**What the plugin deliberately excludes:** there is no eval, no snippet store, no theme-file editor, no SQL passthrough and no WP-CLI passthrough. Installing a plugin or theme is a Pro operation and reaches WordPress.org and nowhere else: it takes a WordPress.org slug and nothing else, there is no argument anywhere in the plugin that accepts a URL, a zip or a file path, and what lands is stored deactivated. There is no permanent delete in the operation surface — a trash operation moves an item to the bin and declares that rollback is required.

**You stay in control from wp-admin:**

* One switch pauses every write on the site immediately.
* Individual operations and whole modules can be switched off.
* Each connected application can be revoked without touching the others.
* A readable activity log shows what happened, and rollback is one click.
* None of these controls is reachable by an agent.

**Integrations** — where the plugin is active, SiteHelm speaks its API in-process: Elementor, ACF, Meta Box, Contact Form 7, and SEO metadata across seven providers in a defined precedence order. If an integration is missing, the operation refuses with a message naming what to activate.

**Privacy** — SiteHelm contains no AI model and sends no content to any AI service. Authentication is a WordPress Application Password, so nothing about your site is stored anywhere else. The plugin makes no outbound call except the update check against its GitHub releases, the licence check for the optional Pro add-on, the WordPress.org lookup and download the optional Pro add-on makes when you ask it to install a plugin or theme, and the media-import fetch you explicitly request — and that fetch is guarded against reaching your internal network. No content, no URLs, nothing about your site travels with any of them.

The free plugin is the whole safety model, permanently. An optional Pro add-on (sold separately via Freemius) adds operations for surfaces the free plugin does not reach — WooCommerce, the SEO plugin's own settings and schema, Elementor Pro's popups and dynamic tags, plugin and theme management, and a code module. Pro never takes a free feature away, and the plugin is open source, so that is checkable rather than promised.

== Installation ==

1. Upload the plugin through Plugins → Add Plugin → Upload, or install it from the directory.
2. Activate it. A SiteHelm menu appears in wp-admin.
3. Create an Application Password for the WordPress user your agent should act as (Users → Profile → Application Passwords). Choose the user deliberately — the agent inherits exactly that user's capabilities.
4. Point your MCP client at `https://your-site.example/wp-json/sitehelm/v1/mcp` with the Application Password as HTTP Basic auth. If your host strips the Authorization header, the install guide on the plugin site shows the two-line `.htaccess` fix.
5. Start with reads on a staging site and watch a preview or two before granting writes. That is the advice we would give about any tool that can write to a live site, including ours.

== Frequently Asked Questions ==

= Is this an AI writing plugin? =

No. SiteHelm contains no model, sends nothing to any AI service, and writes no content of its own. It is the safe hands the agent you already use operates the site with.

= What stops the agent breaking my site? =

Five gates on every write, and none of them can be skipped by the caller: preview as an exact diff, capability re-check, snapshot, read-back verification, and rollback from the admin.

= Can the agent run PHP or SQL? =

No. Neither has a route through the plugin — not held back for a paid tier, just not built.

= Can the agent give itself more permission? =

No. Every operation re-checks the WordPress capability of the authenticated user in the handler itself. The permission switches live in wp-admin and are not exposed over MCP.

= What does it need? =

WordPress 6.6 or newer and PHP 8.1 or newer. It does no work on a front-end page view — operations run only when an agent calls one, over an authenticated endpoint.

= Will features that are free today move into Pro later? =

No. Pro adds operations; it never takes one away. The plugin is open source, so this is checkable.

= What happens if an operation fails halfway? =

The refusal names which error code applies and what to do about it, and says how far the run got. If a write landed but the read-back disagreed with the preview, that is reported as a verification failure rather than a success.

== Screenshots ==

1. The Home screen: what changed this week, what could not be done, and what can be undone.
2. The History log: every operation in plain words, with its outcome, timing and one-click rollback.
3. Permissions: one choice per area of the site — Off, Read only, Read & edit, or Full.
4. The per-operation switches: every single operation an app can ask for, individually controllable.
5. Connect an app: the endpoint, and the readiness checks a connection needs.

== Changelog ==

The full changelog for every release is maintained at
https://github.com/Mrshahidali420/SiteHelm/blob/main/CHANGELOG.md

= 0.11.0 =
* Apps can sign in to the site themselves instead of being handed a password: an app registers, sends you to a page in your own dashboard that names it and asks whether to allow it, and holds its own credential. Application passwords keep working; this is a second way in.
* Connect asks how your app signs in before it shows you anything to paste, then lists every app that has signed in, when it last connected, how many live tokens it holds, and a Sign out or Remove button that names the app before it acts.
* A Test discovery button asks this site for its own sign-in documents over the network and reports, per address, whether the answer came from this site or from something sitting in front of it.
* Home opens with five steps that tick themselves off - connect a client, choose what it may touch, make a test call, make a first change, undo it - each read back off the site rather than remembered.
* A client that asks for an older MCP protocol revision now gets the one it asked for; some clients read the disagreement as the end of the conversation and never asked for the tool list.
* Two schema shapes strict clients refused to load are fixed, two sites no longer collide in one client's config, and one unreadable Elementor global class no longer hides all the others.

= 0.10.0 =
* Bulk SEO metadata and audit fixes are free: content-seo-bulk-set and content-seo-audit-fix move out of Pro, because the free plugin already has the single write and a batch of it is not a reason to pay.
* Connecting now returns short instructions on how a write is previewed and applied, and the four Elementor mistakes that produce a page which reports success and still looks wrong.
* Elementor pages written without element ids lost every style on them; every element now gets an id as it is written, and a page left in that state repairs itself on the next write. Repeater rows get ids on the same terms, so a single row can be styled.
* Elementor style settings that would be stored but never rendered are refused, naming the companion switch they need; an image set by URL alone is flagged, because it is served without srcset.
* Setting an Elementor page layout actually changes the page now, and elementor-document-get returns the page's own settings.

= 0.9.0 =
* Two new reads: system-plugin-list and system-theme-list report every installed plugin and theme, versions, which are active, and which have an update waiting — read from WordPress's own last check, never triggering a new one.
* Acting on them — activate, deactivate, switch theme, update, install — is SiteHelm Pro 0.7.0. Installs take a WordPress.org slug and nothing else, and what lands is stored deactivated.

= 0.8.0 =
* Updates now come straight from the plugin's GitHub releases: new versions appear on the Plugins screen and install with one click.
* A console notice says when the installed version is behind the latest release.
* Calling a Pro operation without a licence now refuses with a message naming SiteHelm Pro, instead of a bare unavailable.
* Community link in the console.

= 0.7.0 =
* A dedicated risk tier for code-adjacent operations, refused by default at every permission level below full control.
* Previews never reproduce an executable payload — code fields render as a byte count and digest, so a stored plan can never hold a live credential.
* Site-wide content search across posts, pages and custom types.
* Forms module: list, read and embed Contact Form 7 forms.
* WooCommerce module scaffolding in the console.

= 0.6.0 =
* Admin console batch: rollback controls, write-pause, per-application revocation, per-operation and per-module switches.

= 0.5.0 =
* Console foundation: five screens over the audit store.

Earlier releases are recorded in the changelog linked above.

== Upgrade Notice ==

= 0.8.0 =
Adds one-click updates from GitHub releases and clearer Pro refusals. No breaking changes; update normally.
