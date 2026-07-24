# EMCP Public Feature Inventory

## Scope and Clean-Room Disclaimer

This inventory records behavior-level claims from EMCP’s public repository metadata, README, product site, tool overview, pricing page, and releases as observed on 2026-07-23. It does not inspect or describe EMCP implementation source, classes, functions, schemas, algorithms, or private information.

`Availability` describes the public source and is limited to `free`, `paid`, `unclear`, or `historical`. `Our decision` is an independent product-scope decision supported by an agency outcome; it is not an instruction to reproduce EMCP’s implementation.

## Public Capability Inventory

| Capability ID | Domain | Publicly observed outcome | Availability | Evidence | Independent agency need | Our decision |
|---|---|---|---|---|---|---|
| EMCP-CAP-001 | Connection | Connects multiple MCP-compatible clients over documented HTTP or STDIO options | free | SRC-0009, SRC-0010 | Agencies use different AI clients and need one supportable connection path | V1 |
| EMCP-CAP-002 | Discovery | Exposes a large tool catalog and documents category-level discovery | free | SRC-0010, SRC-0011 | Agents need to discover supported actions without memorized prompts | V1 |
| EMCP-CAP-003 | Elementor discovery | Finds pages, templates, elements, widgets, and relevant schemas | free | SRC-0011 | Agencies must inspect an unfamiliar client site before changing it | V1 |
| EMCP-CAP-004 | Elementor page lifecycle | Creates and manages Elementor pages | free | SRC-0009, SRC-0011 | Building and updating client pages is the primary Elementor workflow | V1 |
| EMCP-CAP-005 | Elementor structure | Adds, updates, moves, and removes layout structures and elements | free | SRC-0009, SRC-0011 | Reliable structural editing is necessary for real page work | V1 |
| EMCP-CAP-006 | Elementor widget catalog | Uses catalog-backed widget discovery and action instead of one tool per widget | free | SRC-0011 | A compact surface reduces context cost while preserving widget reach | V1 |
| EMCP-CAP-007 | Elementor templates | Reads and manages templates and theme-builder artifacts | unclear | SRC-0011 | Agencies reuse templates, but expanded authoring can follow core page reliability | roadmap |
| EMCP-CAP-008 | Elementor global settings | Reads and updates global page-builder settings | free | SRC-0011 | Brand consistency depends on shared colors, typography, and site settings | roadmap |
| EMCP-CAP-009 | Stock and media placement | Searches or places stock/media assets in page workflows | free | SRC-0011 | Media placement is common, but remote fetching needs separate SSRF review | roadmap |
| EMCP-CAP-010 | Gutenberg blocks | Operates Gutenberg block content independently of Elementor | free | SRC-0009, SRC-0011 | Agencies maintain mixed-builder sites | roadmap |
| EMCP-CAP-011 | Builder-agnostic theme building | Creates headers, footers, and archives outside Elementor-specific workflows | free | SRC-0009, SRC-0011 | Theme-wide templates are valuable but expand V1 risk and compatibility scope | roadmap |
| EMCP-CAP-012 | WordPress content | Operates posts, pages, custom content, and taxonomies | free | SRC-0009, SRC-0011 | Core content operations are required even when Elementor is absent | V1 |
| EMCP-CAP-013 | WordPress settings | Reads and updates selected site settings | free | SRC-0009, SRC-0011 | Site configuration is useful but should follow a strict allowlist | roadmap |
| EMCP-CAP-014 | Plugins and themes | Inspects and performs selected plugin/theme operations, with writes disabled by default | free | SRC-0009, SRC-0011 | Agencies need diagnostics first; installation and activation are higher-risk operations | roadmap |
| EMCP-CAP-015 | Media library | Reads, updates, and performs selected media operations | free | SRC-0009, SRC-0011 | Content and page workflows require local media discovery and safe uploads | V1 |
| EMCP-CAP-016 | Users | Reads and performs selected user operations, with writes disabled by default | free | SRC-0009, SRC-0011 | User administration is useful but security-sensitive and not required for V1 proof | roadmap |
| EMCP-CAP-017 | Navigation menus | Reads and manages WordPress navigation menus | free | SRC-0009, SRC-0011 | Agencies routinely update navigation alongside pages and content | V1 |
| EMCP-CAP-018 | Performance scan | Returns a read-only performance report | free | SRC-0009, SRC-0011 | Diagnostics can support agency reporting after operational foundations stabilize | roadmap |
| EMCP-CAP-019 | Security scan | Returns a read-only security or malware-oriented report | free | SRC-0009, SRC-0011 | Security reporting needs specialized validation and must not distract from V1 writes | roadmap |
| EMCP-CAP-020 | Filesystem operations | Exposes selected reads and opt-in writes | free | SRC-0009, SRC-0011 | Unrestricted filesystem access creates unacceptable V1 risk | exclude |
| EMCP-CAP-021 | Database operations | Exposes selected reads and opt-in writes | free | SRC-0009, SRC-0011 | Unrestricted SQL creates unacceptable V1 risk; constrained maintenance may be designed later | exclude |
| EMCP-CAP-022 | Snapshots and rollback | Publicly claims page snapshots, a change ledger, and rollback | unclear | SRC-0009, SRC-0010 | Recoverable history is central to agency trust | V1 |
| EMCP-CAP-023 | Capability enforcement | Public docs state that every tool applies WordPress capability checks and target ownership where relevant | free | SRC-0011 | AI operations must never exceed the authenticated WordPress user | V1 |
| EMCP-CAP-024 | Risky-operation defaults | Public docs state that write, delete, and site-wide operations commonly begin disabled | free | SRC-0011 | Safe defaults reduce accidental changes during setup | V1 |
| EMCP-CAP-025 | ACF integration | Discovers and operates ACF-related content through plugin-conditional tools | free | SRC-0009, SRC-0011 | ACF is common in agency-built structured-content sites | V1 |
| EMCP-CAP-026 | Meta Box integration | Public release and repository materials claim Meta Box support | unclear | SRC-0009 | Meta Box is a major custom-field alternative and proves provider-neutral design | V1 |
| EMCP-CAP-027 | WooCommerce integration | Provides plugin-conditional commerce read and write domains | free | SRC-0009, SRC-0011 | Commerce is valuable but requires a later domain-specific security plan | roadmap |
| EMCP-CAP-028 | Form integrations | Public materials claim support for eight form builders | paid | SRC-0009, SRC-0010, SRC-0012 | Lead and form operations are useful after foundational permission and PII policies exist | roadmap |
| EMCP-CAP-029 | SEO integrations | Public materials claim support for seven SEO plugins | paid | SRC-0009, SRC-0010, SRC-0012 | SEO metadata interoperability is valuable but not needed to validate V1 architecture | roadmap |
| EMCP-CAP-030 | Elementor addon discovery | Public docs claim conditional support for selected Elementor addon packs | unclear | SRC-0009, SRC-0011 | Runtime widget discovery should eventually cover installed addon widgets | roadmap |
| EMCP-CAP-031 | Custom widget building | Public product materials advertise an opt-in AI widget builder | paid | SRC-0010, SRC-0011, SRC-0012 | Generating executable widget code exceeds the V1 safety boundary | exclude |
| EMCP-CAP-032 | Premium prompts and assets | Sells prompts, templates, skills, brand kits, and support alongside additional tools | paid | SRC-0012 | Commercial value can include workflows and support, but connector access stays equal across our plans | roadmap |
| EMCP-CAP-033 | Client authentication evolution | Public releases include an OAuth sign-in milestone | unclear | SRC-0009 | Easier secure onboarding is important; V1 begins with standard WordPress authentication | roadmap |
| EMCP-CAP-034 | Module-conditional registration | Registers plugin-specific tools only when the corresponding plugin is active | free | SRC-0009, SRC-0011 | One bundled plugin needs a small active surface and graceful missing-dependency behavior | V1 |
| EMCP-CAP-035 | Per-tool enablement | Provides a tools interface and defaults many risky capabilities off | free | SRC-0011 | Administrators need control over which operations an AI client can invoke | V1 |

## Interpretation

The inventory shows that EMCP’s public strengths are breadth, Elementor depth, fast integration expansion, conditional registration, and a functioning free-to-Pro funnel. It also confirms that snapshots, rollback, permissions, and safe defaults are visible buying criteria rather than purely internal engineering concerns.

Our V1 decisions remain independently justified by agency operations: core WordPress, essential Elementor, ACF, Meta Box, menus, media, diagnostics, safe writes, and recoverable history. Database, filesystem, executable code generation, and broad administration remain excluded or deferred because their risk is not necessary to validate the platform.
