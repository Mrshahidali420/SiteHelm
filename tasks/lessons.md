# Lessons

## 2026-07-23 — Preserve the full competitive ambition

- **Pattern:** I narrowed a broad product ambition into a complementary custom-content tool before confirming whether the user wanted coexistence or direct competition.
- **Why:** The user wants a standalone, extensible competitor that can eventually cover EMCP’s feature set and add integrations through a modular plugin architecture.
- **How to apply:** During product discovery, explicitly establish competitive posture and long-term platform scope before recommending a narrow wedge. Separate the long-term product vision from the deliberately constrained first release rather than replacing the vision with the wedge.

## 2026-07-23 — Separate internal modularity from product packaging

- **Pattern:** I translated an extensible architecture into optional connector add-ons even though architectural modules do not require separate customer-facing products.
- **Why:** The user wants one comprehensive plugin with all supported integrations included for now; fragmented installation would undermine the intended simplicity.
- **How to apply:** Discuss code boundaries and commercial packaging separately. Keep adapters internally isolated and replaceable while delivering them through one plugin, unless the user explicitly requests add-ons.
