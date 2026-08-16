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

## 2026-07-26 — "Verified live" is worthless until you check what the live site loads

- **Pattern:** A fix round reported the title-trim defect verified against live WordPress. The live install's plugin directory turned out to be a *copy* made hours earlier, from before the fix commits — `grep` for the new constant returned zero. What had genuinely been probed live was WordPress core's own filter registrations (the right source of truth for what to model); the plugin fix itself had only ever run against Brain Monkey stubs. Syncing `src/` and re-probing then proved it properly: the exact scenario that returned `verification_failed` with no `rollbackRef` returned `verified` with a real reference.
- **Why:** A deployed copy silently diverges from the worktree. The probe still runs, still prints plausible output, and reports on code nobody is about to merge. Nothing fails loudly.
- **How to apply:** Before trusting any live probe of a fix, confirm the deployment target actually contains the change — `grep` for an identifier the fix introduced, or compare mtimes against the commit. Symlinked plugin directories avoid the whole class of problem; a copied one needs an explicit sync step in the probe itself. Distinguish "probed the platform's behaviour" from "probed our code running on the platform" — the first does not imply the second.

## 2026-07-26 — A promise-then-verify design must model the platform's own writes

- **Pattern:** The change engine promised an after-state, wrote it, re-read it, and compared. WordPress trims post titles unconditionally (`title_save_pre`, outside `kses_init_filters()`), so any title with trailing whitespace stored differently than promised. A perfectly correct write reported `verification_failed`, carried no `rollbackRef`, and told the operator to restore a snapshot — turning a success into a false alarm that invites destructive remediation. Trailing whitespace is routine in language-model output, i.e. the primary client.
- **Why:** I first classified this Minor and guessed the trigger was third-party filters. It was core, unconditional, on every title. Verifying a promise against a platform that transforms on write makes every unmodelled transformation a false failure — and the failure path was the dangerous one.
- **How to apply:** Whenever a design compares "what we asked for" against "what the system stored", enumerate the platform's own write-path transformations first and model them in the promise. Ask which direction an unmodelled difference fails in: a false *failure* that recommends rollback is far worse than a missed check. Where the chain is too large to mirror faithfully (WordPress's `content_save_pre` is five callbacks, three capability-gated), say so as a scoped limitation rather than half-modelling it.

## 2026-08-16 — One search returning nothing is not evidence of absence

- **Pattern:** Investigating whether Elementor stores a rendered-element cache, I searched the whole installed plugin for `_elementor_element_cache`. The search returned two hits, both in the `element-cache` module, both about a site *option* and an option-update hook — no post meta anywhere. On that basis I nearly recorded the finding as unreproducible and the fix as unnecessary. A second search with a different tool and a slightly different pattern found `Document::CACHE_META_KEY = '_elementor_element_cache'` in `core/base/document.php` — the definition the entire finding depended on.
- **Why:** A search returning *some* plausible hits is far more convincing than one returning zero, and it suppresses the instinct to search again. The partial result read as a complete answer, and the two hits it did return actively supported the wrong conclusion — they made the string look like an option name rather than a meta key. That is the same shape as the defect class the finding was about: a check that reports success because it inspected a different artefact from the one that mattered.
- **How to apply:** Before concluding a symbol, key, or call site does not exist, search a second time with a *different tool* and a *different pattern shape* (add or drop a delimiter, search the constant name rather than its value, search the consumers rather than the definition). Treat a negative result as load-bearing only when two independent searches agree. When a finding's whole premise rests on one string, confirm it from its definition site, not from its uses.

## 2026-08-16 — Verify the version floor you quote, or do not quote one

- **Pattern:** I wrote that a defect affected "Elementor 4.2+". 4.2.0 was simply the version installed on the local test site; I had no evidence about when the feature was introduced. The number was on its way into a requirement and a commit message as though it had been checked.
- **Why:** The installed version is the most available number and it reads exactly like a researched floor. Nothing in the output distinguishes "the version where this appeared" from "the version I happened to look at".
- **How to apply:** A version floor is a claim needing its own evidence — a changelog entry, a tag diff, the commit that introduced the symbol. If that evidence has not been gathered, state the behaviour without a floor and say why the floor does not change the decision. Here, deleting an absent meta row is harmless on every release, so the floor was never load-bearing and quoting one would have added risk for nothing.
