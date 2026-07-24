# WordPress MCP Market Scan

## Method

Research was performed on 2026-07-23 under the project’s [clean-room protocol](clean-room-protocol.md).

GitHub discovery came first using these queries:

- `gh search repos "wordpress mcp"`
- `gh search repos "elementor mcp"`
- `gh search repos "woocommerce mcp"`
- `gh search code "MCP WordPress language:PHP"`

Primary project READMEs, public repository metadata, releases, product pages, pricing pages, and official platform documentation were then reviewed. No competitor implementation source was used. Repository popularity and vendor claims are observations, not proof of product quality or market demand. [SRC-0019, SRC-0020, SRC-0021]

## Inclusion Rule

A product is included when it exposes WordPress behavior through MCP or sells an adjacent WordPress AI-operations product. Infrastructure libraries are listed separately from products because they solve transport and registration rather than agency workflows.

## Direct Competitors

| Product | Category | Target user | Observed capabilities | Distribution | Monetization | Evidence | Strategic implication |
|---|---|---|---|---|---|---|---|
| EMCP Tools | Bundled WordPress MCP plugin | Site builders, agencies, and AI-assisted WordPress operators | Publicly claims 207 tools, with 162 free; Elementor, Gutenberg, theme building, core WordPress administration, snapshots/rollback, scanners, ACF, Meta Box, WooCommerce, forms, SEO, and addon-pack integrations | Free GPL plugin through GitHub Releases; Pro licenses through its website | Free/Pro; observed annual launch prices from USD 29.99 for one site to USD 99.99 unlimited, plus lifetime offers and support/assets | SRC-0009, SRC-0010, SRC-0011, SRC-0012 | The clearest direct benchmark. Breadth and fast integration releases are strengths; a competitor needs an equally clear core story rather than “more tools” alone. |
| Respira for WordPress | Commercial plugin plus public MCP wrapper | Agencies and professionals managing builder-heavy sites | Publicly claims 172 tools, 12 page builders, context-aware tool filtering, snapshots, duplicate-before-edit, admin approval, rollback, governance, bulk operations, and diagnostics | MIT MCP wrapper plus a proprietary licensed WordPress plugin and API key | Free trial; public README says plans begin at EUR 9 monthly | SRC-0014 | Safety, builder breadth, and governance are already commercial differentiators. Our one-plugin/local-first packaging can contrast with a proprietary service dependency. |
| WP MCP Ultimate | Open-source WordPress MCP plugin | Self-hosted WordPress users wanting broad core operations | Publicly documents 58 abilities, HTTP transport, API-key management, client configuration export, conflict detection, and a WordPress 6.9 polyfill | GPL GitHub plugin | No paid plan observed in reviewed source | SRC-0013 | One-click configuration and conflict detection validate onboarding as a core requirement; tool count alone is easy to imitate. |
| WordPress MCP Server (RaheesAhmed) | External MCP server | Developers wanting broad REST-based WordPress control | Publicly claims more than 190 tools and broad site control, with npm and MCP-directory distribution | MIT GitHub/npm package | No paid plan observed in reviewed source | SRC-0015 | High raw tool counts are common. A smaller discoverable surface with stronger safety and compatibility evidence can be more credible. |
| Claudeus WordPress MCP | External WordPress MCP server | Developers using AI clients with WordPress | Publicly documents Application Password and JWT authentication and general WordPress operations | GitHub project; repository reports an “Other” license | No paid plan observed in reviewed source | SRC-0016 | Supporting standard WordPress authentication is table stakes; licensing clarity matters for agency adoption. |
| Elementor MCP (aguaitech) | Elementor-specific MCP server | Elementor users seeking a narrow MCP bridge | Repository positions itself as a simple Elementor MCP server; detailed claims were not used because evidence was insufficient | Public GitHub repository | No paid plan observed; no license metadata published | SRC-0018 | Simple, narrowly positioned implementations can gain attention. Clear setup and one successful workflow may matter more than initial breadth. |

## Adjacent Alternatives and Infrastructure

| Product | Category | Target user | Observed capabilities | Distribution | Monetization | Evidence | Strategic implication |
|---|---|---|---|---|---|---|---|
| WordPress MCP Adapter | Official infrastructure | WordPress plugin developers exposing Abilities over MCP | Converts WordPress abilities to MCP tools/resources/prompts; HTTP and STDIO transports; multiple servers; validation; custom permissions, errors, and observability | GPL WordPress plugin and Composer package | Open source | SRC-0002, SRC-0004 | This should be evaluated as the likely transport foundation, not competed with. Product value must live above protocol plumbing. |
| Automattic WordPress MCP | Historical infrastructure/product bridge | Early WordPress MCP adopters | Documented transports, JWT authentication, capability inheritance, operation controls, security-event logging, and REST CRUD experiments | Public GitHub repository moving users to the official MCP Adapter | No current commercial offer observed | SRC-0005 | Its deprecation confirms the ecosystem’s movement toward the Abilities API and MCP Adapter rather than custom transport stacks. |
| WooCommerce MCP Server (techspawn) | Commerce-specialist MCP server | WooCommerce operators and developers | Publicly documents product and product-metadata CRUD, alongside other WooCommerce API groups | MIT GitHub project | No paid plan observed in reviewed source | SRC-0017 | Specialist servers demonstrate demand for deep plugin operations; WooCommerce belongs after the core platform and safety contracts stabilize. |

## Recurring Capability Patterns

The public evidence supports these recurring categories:

1. **Core content operations:** posts, taxonomies, media, users, settings, and site administration. [SRC-0005, SRC-0011, SRC-0013, SRC-0015]
2. **Page-builder operations:** Elementor is the most visible wedge; broader builder coverage is commercially claimed by Respira. [SRC-0009, SRC-0014, SRC-0018, SRC-0020]
3. **Plugin integrations:** ACF, Meta Box, WooCommerce, forms, and SEO appear as expansion paths. [SRC-0009, SRC-0011, SRC-0017, SRC-0021]
4. **Connection and onboarding:** client-config export, setup wizards, connection tests, and conflict detection reduce support burden. [SRC-0013, SRC-0014]
5. **Safety and governance:** capability inheritance, operation toggles, snapshots, rollback, approval, and audit behavior are visible differentiators rather than optional polish. [SRC-0005, SRC-0009, SRC-0014]
6. **Tool-list management:** very large tool counts create a need for dynamic registration, catalogs, or context-aware filtering. [SRC-0004, SRC-0011, SRC-0014, SRC-0015]

## Underserved Agency Outcomes

The evidence does not establish market size, but it reveals gaps worth validating with agencies:

- **Predictable operation contracts across integrations.** Competitors advertise breadth, while public documentation gives less evidence of one consistent preview, authorization, result, and error model across every plugin.
- **Local-first safe writes without a hosted dependency.** Respira publicly emphasizes safety but requires a commercial plugin/API key; open projects tend to emphasize breadth or transport. [SRC-0014, SRC-0015]
- **Compatibility evidence instead of compatibility claims.** Agencies need supported-version matrices, targeted diagnostics, and graceful module isolation when one plugin changes.
- **One install with a controlled tool surface.** Dynamic module activation and searchable domain catalogs can retain one-plugin simplicity without exposing hundreds of tools at once.
- **Agency permission profiles and evidence.** Reusable profiles, client-safe restrictions, audit records, and demonstrated rollback can justify payment more clearly than connector availability.

These are hypotheses for beta interviews, not validated demand.

## Positioning Consequences

The product should not lead with “more tools than EMCP.” Tool-count competition is already crowded and encourages risky breadth. It should lead with:

> One secure WordPress MCP plugin whose integrations behave consistently, explain compatibility, preview writes, verify outcomes, and preserve recoverable history.

V1 still requires credible Elementor and WordPress operation coverage because narrow competitors prove that Elementor is an effective discovery wedge. The commercial moat should be trustworthy operations and integration discipline, not proprietary access to individual connectors.
