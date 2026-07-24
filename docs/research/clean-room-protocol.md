# Clean-Room Research Protocol

## Purpose

This protocol permits public behavior and market research while preventing EMCP implementation details, private information, text, code, and identity from entering the competing product.

## Permitted Evidence

- Official public product and developer documentation.
- Public product pages, pricing pages, release notes, demonstrations, issues, and reviews.
- Public repository metadata, README files, release tags, licenses, and documented APIs.
- Behavior observed through an ordinary public installation or published demonstration.
- Primary WordPress, Elementor, ACF, Meta Box, and MCP documentation.

## Prohibited Inputs

- EMCP source code as an implementation reference.
- Private messages, unpublished branches, unreleased packages, private documentation, or contributor-only knowledge.
- Copied names, descriptions, prompts, screenshots, icons, interface text, schemas, tests, or branding.
- Requirements justified only by “EMCP has it.” Every requirement needs an independent user outcome.

## Research-to-Requirement Rule

Every competitive claim and requirement cites one or more source IDs from `source-register.csv`. A requirement copied from observed behavior without an independent user outcome is rejected.

## Separation Rule

Researchers record behavior, user outcome, limitations, and public evidence. Future implementers receive the approved requirements matrix and vendor API documentation, not notes about EMCP internals.

## Source Handling

Record URLs, access dates, publisher, evidence class, claim summary, and license when relevant. Do not store copied source files or large verbatim excerpts. Quote only the minimum words needed to identify a public claim.

## Review Gate

Any disputed source, private-information concern, copied wording, or implementation-level EMCP detail blocks Phase 1 completion until removed or independently re-derived from primary vendor documentation.
