# Phase 10 — REQ-0052 media import from a URL

**Status:** design approved. Supersedes the roadmap block recorded in
`MediaUpload.php:57-59` and in the Phase 9 PR body.

## 1. What this builds and why the block is lifted

REQ-0052 lets an operator name a URL and have the asset at that URL become an
attachment in the media library, instead of base64-encoding the file and sending
it inline. It has been deliberately unbuilt since Phase 4 because it is the first
and only thing in this plugin that makes the site issue an **outbound HTTP
request to an address the caller chooses** — the classic server-side request
forgery surface. The site's own network position is the asset being attacked: a
WordPress install can usually reach a cloud metadata endpoint, an internal
admin panel, a database port, and every other host on its VPC, none of which the
caller can reach directly.

The block is lifted by explicit decision. It is replaced by a policy, stated
here, that is the centrepiece of the design rather than a wrapper around it.

**Network policy: hardened public fetch.** Any public host is reachable. No
allowlist, no site configuration, no filter. Everything else is refused by a
single guard that every fetch must pass through.

## 2. The determinism problem, and why the existing engine already solves it

`planChange()` is required to be deterministic: the engine runs it at preview,
fingerprints its payload, and runs it **again at apply**, where
`PlanAdmission::assertPayloadMatches()` compares the two digests. A remote fetch
is the least deterministic thing this codebase does — the bytes at a URL can
change between preview and apply, and an operator who reviewed one image would
apply another.

No new machinery is needed. The fetch happens inside `planChange()`, and the
payload carries `contentSha256` of the fetched bytes exactly as `media-upload`'s
payload does. Therefore:

- Preview fetches, validates, and fingerprints the bytes.
- Apply fetches again, and if the remote content changed by so much as one byte,
  the digests differ and `PlanAdmission` refuses the plan with `StalePlan`.
- The operator is told the source changed and asked to preview again. Nothing is
  written.

This is the correct behaviour, not a workaround, and it falls out of the
two-phase contract for free. **The cost is honest and must be stated in the
operation's own description: an import performs two GET requests, one per
phase.** That cost buys the guarantee that what was reviewed is what lands.

## 3. Components

Five source files. Three are new, two are extractions from `MediaUpload` so that
the two operations share one implementation of the parts that are identical.

```
MediaImport (WriteOperation)
  ├── MediaUrlGuard      — is this URL allowed to be fetched at all?   (new)
  │     └── HostResolver — DNS seam, so the policy is testable          (new)
  ├── MediaFetch         — perform the fetch, pinned and bounded        (new)
  ├── MediaMimeGuard     — are these bytes allowed to become a file?  (extended)
  ├── MediaAssetPlan     — build the promise and the payload      (extracted)
  └── MediaSideload      — write the bytes, create the attachment (extracted)
```

`MediaUpload` is rewired onto `MediaAssetPlan` and `MediaSideload` and otherwise
unchanged. **`MediaUploadTest` and `MediaMimeGuardTest` must stay green with no
edits at all** — that is the proof the extraction preserved behaviour, and it is
a hard requirement of Task 1, not a hope.

### 3.1 `MediaUrlGuard` — the single choke point

Pure policy plus DNS. No HTTP. Every refusal is `ErrorCode::InvalidInput` — a
URL this site will not fetch is a bad request, never an execution failure.

`validate( string $url ): array{url: string, scheme: string, host: string, port: int, ip: string}`

Checks, in this order:

1. **`wp_http_validate_url( $url )` must pass.** Core's own baseline, kept as the
   first gate so this plugin is never *weaker* than the platform. It is not kept
   as the *only* gate, because it misses link-local (169.254.0.0/16, the cloud
   metadata range on AWS, GCP and Azure) and does not consider IPv6 at all.
2. **Scheme allowlist: `http` or `https`.** Everything else refused, by
   allowlist rather than deny list, so `file://`, `gopher://`, `dict://`, and
   whatever the next protocol is are refused without being enumerated.
3. **No credentials.** A URL carrying a `user` or `pass` component is refused
   outright. It is never needed for a public asset, and it is how a caller gets
   the site to replay credentials at a host of their choosing.
4. **Port must be absent, 80, or 443.** Refuses every non-web internal service
   in one line.
5. **Host must be non-empty and must not be a bare `localhost`.**
6. **Resolve every A and AAAA record.** If the host is already an IP literal, it
   "resolves" to itself. An empty resolution is a refusal.
7. **Every resolved address must be public.** Each address must satisfy
   `filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )`
   **and** must not fall in the explicit blocked list below, which covers what
   those flags miss.

   **If ANY record fails, the whole URL is refused.** A host that resolves to one
   public address and one loopback address is an attack, not a misconfiguration:
   accepting it means the connect is decided by resolver ordering.

   Explicit blocked ranges, on top of the filter flags:

   | Range | Why |
   |---|---|
   | `169.254.0.0/16` | link-local; `169.254.169.254` is the cloud metadata endpoint |
   | `100.64.0.0/10` | CGNAT; carrier-internal, not public |
   | `0.0.0.0/8` | "this network"; `0.0.0.0` connects to localhost on Linux |
   | `::1/128` | IPv6 loopback |
   | `fc00::/7` | IPv6 unique-local |
   | `fe80::/10` | IPv6 link-local |
   | `::ffff:0:0/96` | IPv4-mapped IPv6 — **unmapped and re-checked as IPv4**, never merely blocked, so `::ffff:127.0.0.1` cannot smuggle a loopback address past an IPv6 check |
   | `64:ff9b::/96` | NAT64 — same treatment: extract the embedded IPv4 and re-check it |

8. The **first** public address is returned as the pin (§3.3).

`HostResolver` is a one-method interface with a `SystemHostResolver`
implementation calling `dns_get_record()`. The seam exists because
`dns_get_record` is a PHP internal function and Brain Monkey cannot redefine it,
so without the seam the entire address policy would be untestable. Tests inject a
fake resolver and drive every branch. `SystemHostResolver`'s body is the only
uncovered code this component contributes and is declared as such.

### 3.2 `MediaFetch` — the bounded, pinned request

`fetch( array $validated, string $correlationId ): string` returns raw bytes.

- Uses `wp_safe_remote_get()`, never `wp_remote_get()`.
- Forces, via a scoped `http_request_args` filter: `reject_unsafe_urls => true`,
  `redirection => 2`, `timeout => 15`, `httpversion => '1.1'`,
  `limit_response_size => MediaMimeGuard::MAX_DECODED_BYTES + 1`, and a
  `user-agent` naming the plugin. `limit_response_size` is the response-size cap
  and is enforced by `WP_Http` during the transfer, so an endless response is cut
  off rather than buffered.
- **Re-validates every redirect hop through `MediaUrlGuard`.** The
  `http_request_args` filter receives the URL of each hop, including redirect
  targets, so a 302 to `http://127.0.0.1:8080/` is refused at the hop rather than
  followed. This is why redirects are capped at 2 rather than disabled: the CDN
  redirects that make imports work in practice remain possible, and each one
  passes the same guard the original URL passed.
- **Pins the connection to the validated IP** via `CURLOPT_RESOLVE`, set through
  WordPress's `http_api_curl` action, re-pinned per hop. This is what closes DNS
  rebinding: without it, an attacker's resolver can answer the guard's lookup
  with a public address and answer the transport's lookup, milliseconds later,
  with `127.0.0.1`, and every check above validates an address the site never
  connects to. With the pin, the address that was validated is the address that
  is dialled. On a non-curl transport the pin is not available and the residual
  TOCTOU window remains; that is documented in the class rather than papered
  over.
- **Both hooks are removed in a `finally` block.** A leaked `CURLOPT_RESOLVE`
  would silently re-point unrelated HTTP requests made later in the same process.
  This is the single most dangerous failure mode in the class and gets its own
  test.
- Refuses a response code other than 200, naming only the status number.
- Refuses an empty body, and a body over `MediaMimeGuard::MAX_DECODED_BYTES`.
- No response header, no redirect chain, no resolved IP, and no transport error
  string ever reaches the envelope. Detail goes to `error_log` correlated by
  `correlationId`, exactly as `MediaUpload`'s sideload failures do.

**The fetched bytes get no trust from having been fetched.** They go straight
into `MediaMimeGuard`, which sniffs the content and ignores every claim — the
`Content-Type` header is not consulted anywhere, for the same reason the upload
path has no `mimeType` input property: a declared type is a second source of
truth that can disagree with the bytes.

### 3.3 `MediaMimeGuard` extension

`inspect( string $filename, string $contentBase64 )` keeps steps 1 and 1b (the
base64 shape check and the strict decode) and then delegates to a new
`inspectBytes( string $filename, string $bytes ): array` carrying steps 2
through 8 unchanged. `MediaImport` calls `inspectBytes()` directly.

`inspectBytes()`'s existing refusal messages are kept **verbatim**, including the
word "uploaded". Rewording them to suit both callers would churn 28 green tests
on the plugin's highest-risk shipped path for a prose nuance. Accepted, recorded
here, and listed as a V1.1 item.

### 3.4 `MediaAssetPlan` and `MediaSideload`

Extracted from `MediaUpload` verbatim, because `media-import` and `media-upload`
differ only in where the bytes come from. Everything from "we have validated
bytes" onward is one implementation used twice.

- `MediaAssetPlan::plan( array $inspected, array $input, ?string $sourceUrl ): PlannedChange`
  — builds the promise (`mimeType` plus any supplied text fields, sanitized as
  they will be stored) and the payload (`contentSha256`, `byteLength`,
  `filename`, `extension`, `parent`, and for an import `sourceUrl`), then
  `ksort`s it. Carries `FIELD_ORDER`, `TEXT_FIELDS`, and `sanitize_field()`.
- `MediaSideload::store( string $bytes, array $payload, OperationContext $context, string $operationId ): int`
  — the temp file, `wp_handle_sideload()`, `wp_insert_attachment()`, metadata
  generation, the alt-text meta write, and the `finally` that deletes the temp
  file on every path. `$operationId` appears only in the `error_log` prefix.

The `contentSha256` re-check that `MediaUpload::applyChange()` performs before
touching disk stays in each **operation**, not in `MediaSideload` — it is the
operation asserting that the bytes it is holding are the bytes its own plan
described, and each operation holds its own.

### 3.5 `MediaImport` — the operation

| Property | Value |
|---|---|
| id | `media-import` |
| domain | `Domain::Media` |
| mode | `Mode::Write` |
| capability | `upload_files` |
| risk | `Risk::High` |
| isReadOnly / isDestructive / isIdempotent | `false` / `false` / `false` |
| previewPolicy | `Required` |
| snapshotPolicy | `Supported` |
| rollbackPolicy | `Supported` (with `restore()` throwing `RollbackUnavailable`) |
| supportedVersions | `[ 'wordpress' => '>=' . SITEHELM_MIN_WP ]` |

`upload_files` and nothing more. It is the same capability WordPress itself gates
its own "Insert from URL" sideload behind, and inventing a stricter one here
would be a capability this plugin made up, which no site's role editor knows to
grant.

Input schema, `additionalProperties: false`, `required: [ 'url' ]`:

- `url` — string, `maxLength` 2048, `format` `uri`.
- `filename` — string, `maxLength` 255, optional. When omitted, derived from the
  URL path's basename. **A URL whose path yields no usable basename with an
  extension is refused with `InvalidInput` asking for an explicit `filename`** —
  the operation never invents a name, because a guessed extension would be a
  claim competing with the content, and the deny-list check in `MediaMimeGuard`
  keys off the extension.
- `title`, `alt`, `caption`, `description` — string, `maxLength` 65535, optional.
- `parent` — integer, `minimum` 0, optional. Passed through as given, exactly as
  `media-upload` does; `media-attach` (REQ-0025) is the operation that validates
  a parent.

Method behaviour mirrors `media-upload`:

- `resolveTarget()` → `$this->targets->pending()`.
- `planChange()` → validate URL, fetch, `inspectBytes()`, hold bytes on the
  private `$pending_bytes` property, return `MediaAssetPlan::plan(...)`.
- `captureSnapshot()` → `null`.
- `applyChange()` → re-hash the held bytes against `contentSha256`, refuse with
  `ExecutionFailed` on mismatch, then `MediaSideload::store()`.
- `readBack()` → `$this->targets->verifyRead(...)`, which discloses the stored
  (possibly uniquified) filename.
- `restore()` → always throws `RollbackUnavailable`, same wording rationale as
  `media-upload`: the reversal that would exist is deleting an attachment and its
  files from disk, which is destruction wearing a rollback's clothes.

`sourceUrl` is in the **payload**, not the promise: it binds the plan to the
source that was reviewed, and it is not an attachment field that could be read
back and verified. Nothing promises it.

## 4. What does not change

- **No new dispatcher and no new error code.** Every refusal here is
  `InvalidInput`, `ExecutionFailed`, `RollbackUnavailable`, or the engine's own
  `StalePlan` — all four already exist.
- `ChangeEngine`, `WriteVerifier`, `PayloadNormalizer` and `PlanAdmission` are
  untouched frozen contracts.
- `MediaModule::register()` gains one `registerWrite()` line in the shape the
  other three writes already use.
- The `MediaUpload.php:57-59` marker comment is replaced by a pointer to
  `media-import` and to `MediaUrlGuard` as the reason it is now safe to have.

## 5. Envelope discipline

The rules already in force apply with one addition specific to this operation:
**no refusal message may disclose a resolved IP address, a redirect target, a
response header, or a transport error string.** Those are the four things an
attacker learns from a blind SSRF probe, and leaking them turns a refused fetch
into an internal port scanner. The URL the caller supplied may be echoed back
quoted and length-bounded, because the caller already knows it. Everything else
goes to `error_log` under the correlation id.

## 6. Testing

Every guard in `MediaUrlGuard` and `MediaFetch` must be **proven by deletion**:
comment the guard out, watch a test fail, restore it. A test that passes with the
guard removed is not a test of the guard.

Named cases that must exist, at minimum:

- Each blocked range in §3.1's table, refused, as its own test.
- A host resolving to one public and one private address, refused.
- `::ffff:127.0.0.1` refused via the unmap path, not via a range match.
- A `file://`, a `gopher://`, and a port-3306 URL, each refused.
- A URL with `user:pass@`, refused.
- A 302 to a private address, refused at the hop, with the outer request
  reporting a refusal and not a success.
- The hooks are removed after a **failed** fetch, not only a successful one.
- A response over the size cap, refused.
- A 404, a 500, and a 204, each refused, with the message naming only the status.
- A PHP script served with `Content-Type: image/png`, refused by
  `MediaMimeGuard` — proving the header is not consulted.
- Preview-then-apply with changed remote bytes produces `StalePlan` and writes
  nothing.
- No refusal message anywhere contains an IP address, a header name, or the
  substring `curl`.

Every refusal assertion names its specific `ErrorCode` in a try/catch. A bare
`expectException( OperationException::class )` proves nothing in this codebase,
because every refusal is that one class.

## 7. Accepted costs

1. **Two GET requests per import**, one per phase. The price of a preview that
   means something. Stated in the operation description.
2. **`inspectBytes()`'s refusal messages say "uploaded"** on the import path.
   §3.3.
3. **The pin is curl-only.** A site whose HTTP transport is not curl gets every
   other guard and keeps a narrow DNS-rebinding window. Documented in
   `MediaFetch`, not hidden.
4. **`SystemHostResolver`'s body is uncovered** by unit tests, by construction.
   Declared, one or two statements.
5. **No allowlist.** A site that wants to restrict imports to named hosts has no
   supported way to do so in this release. Recorded as a V1.1 candidate.
