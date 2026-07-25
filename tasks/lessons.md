# Lessons

## 2026-07-23 — Preserve the full competitive ambition

- **Pattern:** I narrowed a broad product ambition into a complementary custom-content tool before confirming whether the user wanted coexistence or direct competition.
- **Why:** The user wants a standalone, extensible competitor that can eventually cover EMCP’s feature set and add integrations through a modular plugin architecture.
- **How to apply:** During product discovery, explicitly establish competitive posture and long-term platform scope before recommending a narrow wedge. Separate the long-term product vision from the deliberately constrained first release rather than replacing the vision with the wedge.

## 2026-07-23 — Separate internal modularity from product packaging

- **Pattern:** I translated an extensible architecture into optional connector add-ons even though architectural modules do not require separate customer-facing products.
- **Why:** The user wants one comprehensive plugin with all supported integrations included for now; fragmented installation would undermine the intended simplicity.
- **How to apply:** Discuss code boundaries and commercial packaging separately. Keep adapters internally isolated and replaceable while delivering them through one plugin, unless the user explicitly requests add-ons.

## 2026-07-25 — When a guard collides with a value, ask what else reaches it

- **Pattern:** `OperationError`'s leak guard threw on any text matching `/password/i`. I hit that collision once (the `authentication_failed` remediation), judged that single instance, softened the text, and moved on. I never asked what *else* flows into guarded text. Client-supplied strings did: `Dispatcher` interpolated the caller's raw `operation` value, so `{"operation":"password"}` produced an uncaught fatal — a remote denial of service that also leaked paths and a stack trace under `WP_DEBUG_DISPLAY`. It survived fourteen task reviews and only fell to the whole-branch review.
- **Why:** A construction-time assertion was doing the job of a runtime sanitizer. Assertions may abort on developer mistakes; anything on an untrusted input path must degrade instead (here: redact and log). The narrow fix treated a symptom and left the mechanism armed.
- **How to apply:** When a validator/guard rejects a legitimate value, treat it as a signal about the guard, not just that value. Enumerate every source that reaches the guarded text and classify each as trusted or untrusted. If any is untrusted, the guard must not throw. Also: an exception thrown inside `catch (A)` is NOT caught by a sibling `catch (Throwable)` on the same `try` — recovery code needs its own containment.

## 2026-07-25 — Scope lint gates to the repo, not to the file you touched

- **Pattern:** Every task review ran `phpcs` scoped to the files that task changed, and each came back clean. The whole-branch review ran it repo-wide: 203 errors across 10 files. Early tasks never adopted the file-docblock and scoped-suppression conventions that later tasks established, and no per-task gate could see the divergence.
- **Why:** A per-unit gate cannot detect drift *between* units. The plan's own "no lint errors" gate was therefore unmet for the whole run while every individual check reported success.
- **How to apply:** Run whole-repo checks (lint, types, full suite) at least at phase boundaries, and state explicitly in review prompts whether a command is file-scoped or repo-wide. Treat "clean on my files" as evidence about the file, never about the repository.

## 2026-07-25 — Verify a subagent's factual claim before overruling it

- **Pattern:** An implementer said WPCS's `IncorrectTypeHint` sniff fires on `@param list<Foo>` against an `array` signature and kept a suppression. I pushed back twice citing another file that used `list<string>` without one. Running the check myself took thirty seconds and proved the implementer right — my counter-example was a different construct. Two round trips wasted on a docblock.
- **Why:** I had cheap, direct access to ground truth and argued from analogy instead. The analogy was superficially similar and materially different.
- **How to apply:** When a disagreement turns on a verifiable fact and the check is cheap, run the check before sending the correction. Also: my own request for `list<Foo>` over `Foo[]` created the need for that suppression — do not convert a correct Minor into a code change that costs a permanent lint directive.

## 2026-07-25 — Chained shell checks hide their own failures

- **Pattern:** I probed the environment with `echo ... && php -m | grep ... && echo ... && for p in <paths>; do ... done`. The `grep` matched nothing, returned non-zero, and short-circuited every later check in the chain — including the one that would have found the user's existing LocalWP installation. I concluded no local WordPress existed and built a throwaway one.
- **Why:** `&&` makes a detection command silently skip detections when an earlier probe legitimately finds nothing. Absence of output read as absence of the thing.
- **How to apply:** Separate independent probes with `;`, never `&&`. Label each probe's output so a missing section is visibly missing rather than silently absent. Before concluding a tool or install is unavailable, confirm the check that would have found it actually ran.
