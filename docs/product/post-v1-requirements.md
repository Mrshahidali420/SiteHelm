# Post-V1 requirements

`v1-requirements-matrix.csv` is closed. All 51 of its V1 rows are implemented and verified, and its
13 roadmap rows record what V1 deliberately excluded. Nothing is appended to that file, because
adding rows to it would make "51 of 51 complete" unreadable.

`post-v1-requirements-matrix.csv` carries everything decided after V1 closed. It uses the identical
sixteen-column schema so the two files can be concatenated for a whole-product view.

## Identifiers

Requirement IDs are globally unique across both files and continue without a gap. V1 ends at
REQ-0064; this file begins at REQ-0065. An ID is never reused, and a requirement that moves band
keeps its number.

## Release bands

| Band | Meaning | Granularity |
| --- | --- | --- |
| `V1.1` | Accepted for the next release. Scoped, dependencies known, ready to plan. | One row per operation, matching V1 house style. |
| `roadmap` | Recognised as real work, not yet scheduled. | One coarse row per area, matching REQ-0057..0064. |

A roadmap row is expanded into per-operation `V1.1` rows at the point it is scheduled, replacing the
coarse row rather than sitting alongside it.

## Column notes

`mode` describes how the requirement reaches the site. Most rows are a dispatcher operation and are
`read` or `write` in the ordinary sense. Three rows are not operations and are mapped as follows:

- REQ-0073 (subprocess transport bridge) and REQ-0074 (published client configurations) are `read`.
  Neither can change site state; the bridge is a transport that relays whatever the client sends, and
  the configurations are shipped files.
- REQ-0076 (request host validation) is `write`, because it is a guard that runs on the write path
  and its acceptance evidence is a refusal.

The three policy columns follow the existing convention. Reads take
`not-applicable,not-applicable,not-applicable`. Non-destructive writes take
`required,required,supported`. Roadmap rows that are not yet designed take
`unsupported,unsupported,unsupported` unless the shape of the work is already settled, in which case
they carry the policies they will need.

## Justification

Every row states an independent user outcome — what an agency operator can do that they could not do
before — and cites `source-register.csv` identifiers. Rows citing `SRC-0009` through `SRC-0012` rest
on publicly published capability claims, and the `required_capability` column may additionally carry
an `EMCP-CAP-0xx` marker tying the row to
`docs/research/emcp-public-feature-inventory.md`. That marker is provenance, never justification: a
row exists because of the outcome in its `user_outcome` column, and any row that could not state one
was rejected rather than recorded. The behavioural design of these requirements is our own; only the
observation that a capability is worth having is external.

## Not in this file

Defects in shipped V1 behaviour are not requirements. They are fixed against the requirement they
already belong to. The rendered-element cache surviving the Elementor fallback write path, for
instance, is a defect against REQ-0043 and is tracked as a bug, not as REQ-0081.
