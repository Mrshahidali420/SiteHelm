# Phase 3a — Change Engine: real-site demonstration

Every request and response below was issued over HTTP against a running WordPress install and is recorded verbatim. Plan tokens and the Application Password appear as `<redacted>` because they are bearer credentials; the rollback reference is recorded in full, because it is a non-secret handle the audit log publishes anyway and its exact value is part of the evidence for steps 11 and 12.

## Environment

| Component | Version |
|---|---|
| WordPress | 7.0.2 |
| PHP | 8.2.29 |
| Database | MySQL 8.4.0 |
| Environment type | `local` |
| Site | http://emcp-license-test.local |
| Endpoint | `POST http://emcp-license-test.local/wp-json/sitehelm/v1/mcp` |
| Actor | `admin` (administrator), HTTP Basic with an Application Password |
| Fixture post | `13` |

The plugin is installed at `wp-content/plugins/sitehelm` with its `vendor/` directory present, because the autoloader is loaded from there. Requests are issued from a PHP script using the curl extension; the Bash `curl` binary is unavailable in this environment.

Application Passwords work over plain HTTP here only because `wp_get_environment_type()` returns `local`.

### Forced clean activation

The three tables are created by the activation hook, which does not run on an already-active plugin, so activation was forced. This output is the evidence that `CoreModule::health()` reports `active` for the right reason rather than by accident:

```
== table existence ==
wp_sitehelm_plans            => wp_sitehelm_plans
wp_sitehelm_audit            => wp_sitehelm_audit
wp_sitehelm_snapshots        => wp_sitehelm_snapshots

== options ==
sitehelm_db_status: 'ready'
sitehelm_db_version: '1'
cron sitehelm_prune_records: 1785151684
```

## Transcript

### 1. system-read catalog

_Proves:_ system-environment and audit-list are both listed and available.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "system-read",
    "arguments": []
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"dispatcher\":\"system-read\",\"operations\":[{\"operation\":\"system-environment\",\"description\":\"Report WordPress, PHP, theme, SiteHelm, and integration module versions for this site.\",\"inputSchema\":{\"type\":\"object\",\"properties\":{},\"additionalProperties\":false},\"outputSchema\":{\"type\":\"object\",\"properties\":{\"wordpress\":{\"type\":\"string\"},\"php\":{\"type\":\"string\"},\"sitehelm\":{\"type\":\"string\"},\"theme\":{\"type\":\"object\",\"properties\":{\"name\":{\"type\":\"string\"},\"version\":{\"type\":\"string\"}}},\"permissionMode\":{\"type\":\"string\"},\"modules\":{\"type\":\"object\"}},\"additionalProperties\":false},\"schemaVersion\":1,\"requiredCapabilities\":[\"manage_options\"],\"risk\":\"low\",\"previewPolicy\":\"not-applicable\",\"snapshotPolicy\":\"not-applicable\",\"rollbackPolicy\":\"not-applicable\",\"available\":true,\"blockedReason\":null,\"example\":{\"operation\":\"system-environment\",\"arguments\":{}}},{\"operation\":\"audit-list\",\"description\":\"List recorded change events with actor, MCP client, operation, target, plan fingerprint, timestamp, and outcome.\",\"inputSchema\":{\"type\":\"object\",\"properties\":{\"operationId\":{\"type\":\"string\",\"maxLength\":64,\"description\":\"Return only events for this operation identifier.\"},\"correlationId\":{\"type\":\"string\",\"maxLength\":64,\"description\":\"Return only events for this request correlation identifier.\"},\"actorId\":{\"type\":\"integer\",\"minimum\":1,\"description\":\"Return only events performed by this WordPress user.\"},\"since\":{\"type\":\"integer\",\"minimum\":0,\"description\":\"Return only events recorded at or after this UTC instant.\"},\"until\":{\"type\":\"integer\",\"minimum\":0,\"description\":\"Return only events recorded at or before this UTC instant.\"},\"limit\":{\"type\":\"integer\",\"minimum\":1,\"description\":\"Page size, clamped to 100.\"},\"offset\":{\"type\":\"integer\",\"minimum\":0,\"description\":\"Events to skip before the page begins.\"}},\"additionalProperties\":false},\"outputSchema\":{\"type\":\"object\",\"properties\":{\"entries\":{\"type\":\"array\",\"items\":{\"type\":\"object\"}},\"total\":{\"type\":\"integer\"},\"limit\":{\"type\":\"integer\"},\"offset\":{\"type\":\"integer\"}},\"additionalProperties\":false},\"schemaVersion\":1,\"requiredCapabilities\":[\"manage_options\"],\"risk\":\"low\",\"previewPolicy\":\"not-applicable\",\"snapshotPolicy\":\"not-applicable\",\"rollbackPolicy\":\"not-applicable\",\"available\":true,\"blockedReason\":null,\"example\":{\"operation\":\"audit-list\",\"arguments\":{\"limit\":20}}}]}"
      }
    ],
    "isError": false
  }
}
```

### 2. content-write catalog

_Proves:_ content-update is present despite declaring only the meta-capability edit_post (the Task 1 fix).

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": []
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"dispatcher\":\"content-write\",\"operations\":[{\"operation\":\"content-update\",\"description\":\"Revise the title, body, or excerpt of one existing content item, keeping the prior revision available.\",\"inputSchema\":{\"type\":\"object\",\"properties\":{\"id\":{\"type\":\"integer\",\"minimum\":1,\"description\":\"Identifier of the content item to revise.\"},\"title\":{\"type\":\"string\",\"maxLength\":255,\"description\":\"Replacement title.\"},\"content\":{\"type\":\"string\",\"maxLength\":500000,\"description\":\"Replacement body.\"},\"excerpt\":{\"type\":\"string\",\"maxLength\":5000,\"description\":\"Replacement excerpt.\"}},\"required\":[\"id\"],\"additionalProperties\":false},\"outputSchema\":{\"type\":\"object\",\"oneOf\":[{\"title\":\"Plan phase\",\"type\":\"object\",\"properties\":{\"plan\":{\"type\":\"object\",\"description\":\"The change plan to approve, including its plan token.\"}},\"required\":[\"plan\"],\"additionalProperties\":false},{\"title\":\"Apply phase\",\"type\":\"object\",\"properties\":{\"target\":{\"type\":\"string\",\"description\":\"The concrete target that was written.\"},\"changed\":{\"type\":\"array\",\"items\":{\"type\":\"string\"},\"description\":\"The fields the approved plan changed.\"},\"state\":{\"type\":\"object\",\"description\":\"The verified persisted state of the target.\"}},\"required\":[\"target\",\"changed\",\"state\"],\"additionalProperties\":false}]},\"schemaVersion\":1,\"requiredCapabilities\":[\"edit_post\"],\"risk\":\"medium\",\"previewPolicy\":\"required\",\"snapshotPolicy\":\"required\",\"rollbackPolicy\":\"supported\",\"available\":true,\"blockedReason\":null,\"example\":{\"operation\":\"content-update\",\"arguments\":{\"id\":42,\"title\":\"Revised heading\"}}},{\"operation\":\"content-create\",\"description\":\"Create one new content item with a title, body, excerpt, and initial status.\",\"inputSchema\":{\"type\":\"object\",\"properties\":{\"type\":{\"type\":\"string\",\"maxLength\":32,\"description\":\"A public content type this site registers, for example post or page.\"},\"title\":{\"type\":\"string\",\"maxLength\":255,\"description\":\"Title of the new content item.\"},\"content\":{\"type\":\"string\",\"maxLength\":500000,\"description\":\"Body of the new content item.\"},\"excerpt\":{\"type\":\"string\",\"maxLength\":5000,\"description\":\"Excerpt of the new content item.\"},\"status\":{\"type\":\"string\",\"enum\":[\"draft\",\"pending\",\"private\",\"publish\"],\"description\":\"Initial status. Requesting publish additionally requires the publish capability.\"}},\"required\":[\"type\",\"title\",\"status\"],\"additionalProperties\":false},\"outputSchema\":{\"type\":\"object\",\"oneOf\":[{\"title\":\"Plan phase\",\"type\":\"object\",\"properties\":{\"plan\":{\"type\":\"object\",\"description\":\"The change plan to approve, including its plan token.\"}},\"required\":[\"plan\"],\"additionalProperties\":false},{\"title\":\"Apply phase\",\"type\":\"object\",\"properties\":{\"target\":{\"type\":\"string\",\"description\":\"The concrete target that was written.\"},\"changed\":{\"type\":\"array\",\"items\":{\"type\":\"string\"},\"description\":\"The fields the approved plan changed.\"},\"state\":{\"type\":\"object\",\"description\":\"The verified persisted state of the target.\"}},\"required\":[\"target\",\"changed\",\"state\"],\"additionalProperties\":false}]},\"schemaVersion\":1,\"requiredCapabilities\":[\"edit_posts\"],\"risk\":\"medium\",\"previewPolicy\":\"required\",\"snapshotPolicy\":\"supported\",\"rollbackPolicy\":\"supported\",\"available\":true,\"blockedReason\":null,\"example\":{\"operation\":\"content-create\",\"arguments\":{\"type\":\"post\",\"title\":\"Launch announcement\",\"status\":\"draft\"}}},{\"operation\":\"content-rollback-apply\",\"description\":\"Restore a recorded snapshot for a previously executed content write, re-checking the original permission at restore time.\",\"inputSchema\":{\"type\":\"object\",\"properties\":{\"rollbackRef\":{\"type\":\"string\",\"maxLength\":64,\"description\":\"Rollback reference offered on a previous write result or audit entry.\"}},\"required\":[\"rollbackRef\"],\"additionalProperties\":false},\"outputSchema\":{\"type\":\"object\",\"oneOf\":[{\"title\":\"Plan phase\",\"type\":\"object\",\"properties\":{\"plan\":{\"type\":\"object\",\"description\":\"The change plan to approve, including its plan token.\"}},\"required\":[\"plan\"],\"additionalProperties\":false},{\"title\":\"Apply phase\",\"type\":\"object\",\"properties\":{\"target\":{\"type\":\"string\",\"description\":\"The concrete target that was written.\"},\"changed\":{\"type\":\"array\",\"items\":{\"type\":\"string\"},\"description\":\"The fields the approved plan changed.\"},\"state\":{\"type\":\"object\",\"description\":\"The verified persisted state of the target.\"}},\"required\":[\"target\",\"changed\",\"state\"],\"additionalProperties\":false}]},\"schemaVersion\":1,\"requiredCapabilities\":[\"edit_post\"],\"risk\":\"medium\",\"previewPolicy\":\"required\",\"snapshotPolicy\":\"required\",\"rollbackPolicy\":\"supported\",\"available\":true,\"blockedReason\":null,\"example\":{\"operation\":\"content-rollback-apply\",\"arguments\":{\"rollbackRef\":\"rb-0123456789abcdef01234567\"}}}]}"
      }
    ],
    "isError": false
  }
}
```

### 3. content-get the fixture

_Proves:_ The normalized record includes the permitted subtitle metadata.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "method": "tools/call",
  "params": {
    "name": "content-read",
    "arguments": {
      "operation": "content-get",
      "arguments": {
        "id": 13
      }
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-get\",\"data\":{\"id\":13,\"type\":\"post\",\"status\":\"publish\",\"title\":\"Phase 3a fixture post\",\"slug\":\"phase-3a-fixture-post-2\",\"content\":\"Original body for the Phase 3a demonstration.\",\"excerpt\":\"\",\"parent\":0,\"modifiedGmt\":\"2026-07-26 11:26:08\",\"featuredMedia\":0,\"terms\":{\"category\":[1],\"post_format\":[],\"post_tag\":[]},\"meta\":{\"subtitle\":\"A permitted custom field\"}},\"verification\":\"not-applicable\",\"warnings\":[],\"correlationId\":\"121e0a8f-5c7c-4628-a1d1-53b1977dcb4d\"}"
      }
    ],
    "isError": false
  }
}
```

### 4. content-update preview

_Proves:_ A preview returns a plan token, both renderings, a fingerprint and snapshot eligibility, and mutates nothing.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 4,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": {
      "operation": "content-update",
      "arguments": {
        "id": 13,
        "title": "Revised heading"
      }
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 4,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-update\",\"data\":{\"plan\":{\"planToken\":\"<redacted>\",\"bindings\":{\"user\":1,\"site\":\"emcp-license-test.local\",\"operation\":\"content-update\",\"schemaVersion\":1,\"target\":\"post:13\",\"payloadHash\":\"ef1feff6c646b61c35ce2edda604d0d78fc2fd320e3c5b3b4b7debbffdaefa19\"},\"stateFingerprint\":\"b058426444d64c3e3379190dd0f456077faad63ecfb8bf514f06ed4a4af752ea\",\"previewSummary\":{\"human\":\"content-update on post:13 (existing target).\\n  post_title: \\\"Phase 3a fixture post\\\" -> \\\"Revised heading\\\"\",\"machine\":{\"target\":\"post:13\",\"exists\":true,\"changes\":[{\"field\":\"post_title\",\"before\":\"Phase 3a fixture post\",\"after\":\"Revised heading\"}]}},\"expiresAt\":1785066072,\"snapshotEligibility\":{\"snapshot\":\"will-capture\",\"rollback\":\"will-offer\"}}},\"verification\":\"not-applicable\",\"warnings\":[],\"correlationId\":\"5d2ab4f7-0d6f-4398-9d4b-5258f6ee8bc2\"}"
      }
    ],
    "isError": false
  }
}
```

### 4b. content-get after preview

_Proves:_ The post is unchanged after a preview.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 5,
  "method": "tools/call",
  "params": {
    "name": "content-read",
    "arguments": {
      "operation": "content-get",
      "arguments": {
        "id": 13
      }
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 5,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-get\",\"data\":{\"id\":13,\"type\":\"post\",\"status\":\"publish\",\"title\":\"Phase 3a fixture post\",\"slug\":\"phase-3a-fixture-post-2\",\"content\":\"Original body for the Phase 3a demonstration.\",\"excerpt\":\"\",\"parent\":0,\"modifiedGmt\":\"2026-07-26 11:26:08\",\"featuredMedia\":0,\"terms\":{\"category\":[1],\"post_format\":[],\"post_tag\":[]},\"meta\":{\"subtitle\":\"A permitted custom field\"}},\"verification\":\"not-applicable\",\"warnings\":[],\"correlationId\":\"2581ec3e-5028-4fdc-9601-c0fdb8bbf8a2\"}"
      }
    ],
    "isError": false
  }
}
```

### 4c. a second, different preview

_Proves:_ Issues a second token bound to a different payload, for the rejection below.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 6,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": {
      "operation": "content-update",
      "arguments": {
        "id": 13,
        "title": "A different heading"
      }
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 6,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-update\",\"data\":{\"plan\":{\"planToken\":\"<redacted>\",\"bindings\":{\"user\":1,\"site\":\"emcp-license-test.local\",\"operation\":\"content-update\",\"schemaVersion\":1,\"target\":\"post:13\",\"payloadHash\":\"403750b8ef111e71cc25392d1360f1aff2eec2cc909be15466d7c318aa1e0f53\"},\"stateFingerprint\":\"b058426444d64c3e3379190dd0f456077faad63ecfb8bf514f06ed4a4af752ea\",\"previewSummary\":{\"human\":\"content-update on post:13 (existing target).\\n  post_title: \\\"Phase 3a fixture post\\\" -> \\\"A different heading\\\"\",\"machine\":{\"target\":\"post:13\",\"exists\":true,\"changes\":[{\"field\":\"post_title\",\"before\":\"Phase 3a fixture post\",\"after\":\"A different heading\"}]}},\"expiresAt\":1785066072,\"snapshotEligibility\":{\"snapshot\":\"will-capture\",\"rollback\":\"will-offer\"}}},\"verification\":\"not-applicable\",\"warnings\":[],\"correlationId\":\"f18c6cf3-d350-4875-8b86-f38522b33ad1\"}"
      }
    ],
    "isError": false
  }
}
```

### 5. apply with another plan token

_Proves:_ A token bound to a different payload is refused as stale_plan.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": {
      "operation": "content-update",
      "arguments": {
        "id": 13,
        "title": "Revised heading"
      },
      "planToken": "<redacted>"
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"code\":\"stale_plan\",\"message\":\"This plan token is expired, already used, or bound to a different request.\",\"retryable\":true,\"correlationId\":\"5d175c72-1f34-428a-8794-da248295d564\",\"remediation\":\"Generate a fresh preview and approve that plan token instead.\"}"
      }
    ],
    "isError": true
  }
}
```

### 5b. content-get after refusal

_Proves:_ The post is still unchanged after a refused approval.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 8,
  "method": "tools/call",
  "params": {
    "name": "content-read",
    "arguments": {
      "operation": "content-get",
      "arguments": {
        "id": 13
      }
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 8,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-get\",\"data\":{\"id\":13,\"type\":\"post\",\"status\":\"publish\",\"title\":\"Phase 3a fixture post\",\"slug\":\"phase-3a-fixture-post-2\",\"content\":\"Original body for the Phase 3a demonstration.\",\"excerpt\":\"\",\"parent\":0,\"modifiedGmt\":\"2026-07-26 11:26:08\",\"featuredMedia\":0,\"terms\":{\"category\":[1],\"post_format\":[],\"post_tag\":[]},\"meta\":{\"subtitle\":\"A permitted custom field\"}},\"verification\":\"not-applicable\",\"warnings\":[],\"correlationId\":\"734ceba6-e5c4-4759-b75f-534e5e0a5201\"}"
      }
    ],
    "isError": false
  }
}
```

### 6. approve the plan

_Proves:_ The approved apply returns verified with an auditRef and a rollbackRef.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 9,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": {
      "operation": "content-update",
      "arguments": {
        "id": 13,
        "title": "Revised heading"
      },
      "planToken": "<redacted>"
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 9,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-update\",\"data\":{\"target\":\"post:13\",\"changed\":[\"post_title\"],\"state\":{\"post_type\":\"post\",\"post_status\":\"publish\",\"post_title\":\"Revised heading\",\"post_name\":\"phase-3a-fixture-post-2\",\"post_content\":\"Original body for the Phase 3a demonstration.\",\"post_excerpt\":\"\",\"post_parent\":0,\"post_modified_gmt\":\"2026-07-26 11:26:12\",\"featured_media\":0,\"terms\":{\"category\":[1],\"post_format\":[],\"post_tag\":[]},\"meta\":{\"subtitle\":\"A permitted custom field\"}}},\"verification\":\"verified\",\"warnings\":[],\"correlationId\":\"006352e8-3e5e-473c-aae8-93ccaa08314e\",\"auditRef\":\"audit-3\",\"rollbackRef\":\"rb-82aca0fa792bd506e82801ce\"}"
      }
    ],
    "isError": false
  }
}
```

### 6b. content-get after apply

_Proves:_ The title changed to the approved value.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 10,
  "method": "tools/call",
  "params": {
    "name": "content-read",
    "arguments": {
      "operation": "content-get",
      "arguments": {
        "id": 13
      }
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 10,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-get\",\"data\":{\"id\":13,\"type\":\"post\",\"status\":\"publish\",\"title\":\"Revised heading\",\"slug\":\"phase-3a-fixture-post-2\",\"content\":\"Original body for the Phase 3a demonstration.\",\"excerpt\":\"\",\"parent\":0,\"modifiedGmt\":\"2026-07-26 11:26:12\",\"featuredMedia\":0,\"terms\":{\"category\":[1],\"post_format\":[],\"post_tag\":[]},\"meta\":{\"subtitle\":\"A permitted custom field\"}},\"verification\":\"not-applicable\",\"warnings\":[],\"correlationId\":\"eab6a1f6-82f1-4816-844a-cdf04cf5ac1c\"}"
      }
    ],
    "isError": false
  }
}
```

### 7. replay the same approval

_Proves:_ A replayed token is refused, proving single use.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 11,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": {
      "operation": "content-update",
      "arguments": {
        "id": 13,
        "title": "Revised heading"
      },
      "planToken": "<redacted>"
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 11,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"code\":\"stale_plan\",\"message\":\"This plan token is expired, already used, or bound to a different request.\",\"retryable\":true,\"correlationId\":\"86710d28-ca22-43ce-adb3-d5b84d6ccea8\",\"remediation\":\"Generate a fresh preview and approve that plan token instead.\"}"
      }
    ],
    "isError": true
  }
}
```

### 8a. fresh preview for the no-arguments case

_Proves:_ Issues a valid token to approve without resending arguments.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 12,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": {
      "operation": "content-update",
      "arguments": {
        "id": 13,
        "title": "Heading for step 8"
      }
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 12,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-update\",\"data\":{\"plan\":{\"planToken\":\"<redacted>\",\"bindings\":{\"user\":1,\"site\":\"emcp-license-test.local\",\"operation\":\"content-update\",\"schemaVersion\":1,\"target\":\"post:13\",\"payloadHash\":\"fc5796a73a1f01525ed0c2423a33fff565c35466688ebb091a56c763ef41c4a1\"},\"stateFingerprint\":\"14890b5121278e6355ffb17b15764c1f3f46d61e1d530847e419f1491c9edf16\",\"previewSummary\":{\"human\":\"content-update on post:13 (existing target).\\n  post_title: \\\"Revised heading\\\" -> \\\"Heading for step 8\\\"\",\"machine\":{\"target\":\"post:13\",\"exists\":true,\"changes\":[{\"field\":\"post_title\",\"before\":\"Revised heading\",\"after\":\"Heading for step 8\"}]}},\"expiresAt\":1785066078,\"snapshotEligibility\":{\"snapshot\":\"will-capture\",\"rollback\":\"will-offer\"}}},\"verification\":\"not-applicable\",\"warnings\":[],\"correlationId\":\"314c0b6e-db68-4a64-9733-123fbc7309aa\"}"
      }
    ],
    "isError": false
  }
}
```

### 8. approve with no arguments

_Proves:_ Approving without resending arguments is invalid_input and the message says to resend them.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 13,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": {
      "operation": "content-update",
      "planToken": "<redacted>"
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 13,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"code\":\"invalid_input\",\"message\":\"Approving a plan requires the arguments the preview was generated from, resent unchanged beside the plan token.\",\"retryable\":true,\"correlationId\":\"748fc71b-d48c-4ec8-83d8-fc88cf595d15\",\"remediation\":\"Resend the original arguments together with the plan token, or omit the token to generate a fresh preview.\"}"
      }
    ],
    "isError": true
  }
}
```

### 9. approve with plan_token misspelled

_Proves:_ A mistyped token key is invalid_input, not a fresh preview inside a success envelope.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 14,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": {
      "operation": "content-update",
      "arguments": {
        "id": 13,
        "title": "Revised heading"
      },
      "plan_token": "<redacted>"
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 14,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"code\":\"invalid_input\",\"message\":\"The call carries a member that is not part of a dispatcher tool call.\",\"retryable\":true,\"correlationId\":\"a1521d95-5799-46e7-b6b4-f9576c0c707e\",\"remediation\":\"Send only operation, arguments, and planToken. Check the spelling of planToken in particular.\"}"
      }
    ],
    "isError": true
  }
}
```

### 10. audit-list

_Proves:_ The audit entry carries actor, operation, target, outcome applied, rollbackRef, and a summary of field names and sizes only.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 15,
  "method": "tools/call",
  "params": {
    "name": "system-read",
    "arguments": {
      "operation": "audit-list",
      "arguments": {
        "limit": 5
      }
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 15,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"audit-list\",\"data\":{\"entries\":[{\"auditRef\":\"audit-3\",\"correlationId\":\"006352e8-3e5e-473c-aae8-93ccaa08314e\",\"actor\":{\"id\":1,\"login\":\"admin\"},\"client\":\"unknown-client\",\"operation\":\"content-update\",\"target\":\"post:13\",\"planFingerprint\":\"b058426444d64c3e3379190dd0f456077faad63ecfb8bf514f06ed4a4af752ea\",\"outcome\":\"applied\",\"summary\":{\"changed\":[\"post_title\"],\"metrics\":{\"post_title\":{\"before\":21,\"after\":15}}},\"rollbackRef\":\"rb-82aca0fa792bd506e82801ce\",\"timestamp\":1785065172},{\"auditRef\":\"audit-2\",\"correlationId\":\"8e690e42-d704-4a28-a12c-2ca07b171ac6\",\"actor\":{\"id\":1,\"login\":\"admin\"},\"client\":\"unknown-client\",\"operation\":\"content-create\",\"target\":\"post:12\",\"planFingerprint\":\"738d402c0203a51b3cc26c6b9e9734009a41db6de53e2e68265aee841a11f1b5\",\"outcome\":\"applied\",\"summary\":{\"changed\":[\"post_content\",\"post_excerpt\",\"post_status\",\"post_title\",\"post_type\"],\"metrics\":{\"post_content\":{\"before\":0,\"after\":0},\"post_excerpt\":{\"before\":0,\"after\":0},\"post_status\":{\"before\":0,\"after\":5},\"post_title\":{\"before\":0,\"after\":22},\"post_type\":{\"before\":0,\"after\":4}}},\"rollbackRef\":null,\"timestamp\":1785064820},{\"auditRef\":\"audit-1\",\"correlationId\":\"a4ab85d5-1a91-413d-884f-0f349495b0d7\",\"actor\":{\"id\":1,\"login\":\"admin\"},\"client\":\"unknown-client\",\"operation\":\"content-update\",\"target\":\"post:10\",\"planFingerprint\":\"48579abd2e4a399b37aa28258857bb1021a1f491a4a2e06f4e5f83da7c8c9cbb\",\"outcome\":\"applied\",\"summary\":{\"changed\":[\"post_title\"],\"metrics\":{\"post_title\":{\"before\":21,\"after\":15}}},\"rollbackRef\":\"rb-bdffcf416984501185bcb6c0\",\"timestamp\":1785064818}],\"total\":3,\"limit\":5,\"offset\":0},\"verification\":\"not-applicable\",\"warnings\":[],\"correlationId\":\"20a032ce-cbb5-4227-b90c-a6604cb4bc58\"}"
      }
    ],
    "isError": false
  }
}
```

### 11. rollback preview

_Proves:_ A rollback is itself preview-required; the preview shows the title reverting.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 16,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": {
      "operation": "content-rollback-apply",
      "arguments": {
        "rollbackRef": "rb-82aca0fa792bd506e82801ce"
      }
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 16,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-rollback-apply\",\"data\":{\"plan\":{\"planToken\":\"<redacted>\",\"bindings\":{\"user\":1,\"site\":\"emcp-license-test.local\",\"operation\":\"content-rollback-apply\",\"schemaVersion\":1,\"target\":\"post:13\",\"payloadHash\":\"99702a8c259f40407d52abd37757b02f7f8941e48a41095a60bc0f9d3aab972b\"},\"stateFingerprint\":\"14890b5121278e6355ffb17b15764c1f3f46d61e1d530847e419f1491c9edf16\",\"previewSummary\":{\"human\":\"content-rollback-apply on post:13 (existing target).\\n  post_title: \\\"Revised heading\\\" -> \\\"Phase 3a fixture post\\\"\",\"machine\":{\"target\":\"post:13\",\"exists\":true,\"changes\":[{\"field\":\"post_title\",\"before\":\"Revised heading\",\"after\":\"Phase 3a fixture post\"}]}},\"expiresAt\":1785066079,\"snapshotEligibility\":{\"snapshot\":\"will-capture\",\"rollback\":\"will-offer\"}}},\"verification\":\"not-applicable\",\"warnings\":[],\"correlationId\":\"ffe7e677-d8a6-4682-9f9a-5ce93a5d84c7\"}"
      }
    ],
    "isError": false
  }
}
```

### 12. approve the rollback

_Proves:_ The rollback applies and verifies, restoring the original title.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 17,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": {
      "operation": "content-rollback-apply",
      "arguments": {
        "rollbackRef": "rb-82aca0fa792bd506e82801ce"
      },
      "planToken": "<redacted>"
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 17,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-rollback-apply\",\"data\":{\"target\":\"post:13\",\"changed\":[\"post_content\",\"post_excerpt\",\"post_title\"],\"state\":{\"post_type\":\"post\",\"post_status\":\"publish\",\"post_title\":\"Phase 3a fixture post\",\"post_name\":\"phase-3a-fixture-post-2\",\"post_content\":\"Original body for the Phase 3a demonstration.\",\"post_excerpt\":\"\",\"post_parent\":0,\"post_modified_gmt\":\"2026-07-26 11:26:19\",\"featured_media\":0,\"terms\":{\"category\":[1],\"post_format\":[],\"post_tag\":[]},\"meta\":{\"subtitle\":\"A permitted custom field\"}}},\"verification\":\"verified\",\"warnings\":[],\"correlationId\":\"cdded31b-87a5-41a2-8874-828a278f7529\",\"auditRef\":\"audit-4\",\"rollbackRef\":\"rb-221e8c2a0508ba6a72a7a1a6\"}"
      }
    ],
    "isError": false
  }
}
```

### 12b. content-get after rollback

_Proves:_ The title is back to its original value.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 18,
  "method": "tools/call",
  "params": {
    "name": "content-read",
    "arguments": {
      "operation": "content-get",
      "arguments": {
        "id": 13
      }
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 18,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-get\",\"data\":{\"id\":13,\"type\":\"post\",\"status\":\"publish\",\"title\":\"Phase 3a fixture post\",\"slug\":\"phase-3a-fixture-post-2\",\"content\":\"Original body for the Phase 3a demonstration.\",\"excerpt\":\"\",\"parent\":0,\"modifiedGmt\":\"2026-07-26 11:26:19\",\"featuredMedia\":0,\"terms\":{\"category\":[1],\"post_format\":[],\"post_tag\":[]},\"meta\":{\"subtitle\":\"A permitted custom field\"}},\"verification\":\"not-applicable\",\"warnings\":[],\"correlationId\":\"db4e0fe8-2621-4db8-9aa2-53a8591023b0\"}"
      }
    ],
    "isError": false
  }
}
```

### 13. content-create a draft

_Proves:_ Issues a creation plan.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 19,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": {
      "operation": "content-create",
      "arguments": {
        "type": "post",
        "title": "Phase 3a demonstration",
        "status": "draft"
      }
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 19,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-create\",\"data\":{\"plan\":{\"planToken\":\"<redacted>\",\"bindings\":{\"user\":1,\"site\":\"emcp-license-test.local\",\"operation\":\"content-create\",\"schemaVersion\":1,\"target\":\"post:new\",\"payloadHash\":\"de3b7eaa2dd0eabfb59ab293ecf182c2f3b7acc9894f95463e9963d71b8b5891\"},\"stateFingerprint\":\"738d402c0203a51b3cc26c6b9e9734009a41db6de53e2e68265aee841a11f1b5\",\"previewSummary\":{\"human\":\"content-create on post:new (new target).\\n  post_type: (absent) -> \\\"post\\\"\\n  post_status: (absent) -> \\\"draft\\\"\\n  post_title: (absent) -> \\\"Phase 3a demonstration\\\"\\n  post_content: (absent) -> \\\"\\\"\\n  post_excerpt: (absent) -> \\\"\\\"\",\"machine\":{\"target\":\"post:new\",\"exists\":false,\"changes\":[{\"field\":\"post_type\",\"before\":null,\"after\":\"post\"},{\"field\":\"post_status\",\"before\":null,\"after\":\"draft\"},{\"field\":\"post_title\",\"before\":null,\"after\":\"Phase 3a demonstration\"},{\"field\":\"post_content\",\"before\":null,\"after\":\"\"},{\"field\":\"post_excerpt\",\"before\":null,\"after\":\"\"}]}},\"expiresAt\":1785066082,\"snapshotEligibility\":{\"snapshot\":\"no-prior-state\",\"rollback\":\"not-offered\"}}},\"verification\":\"not-applicable\",\"warnings\":[],\"correlationId\":\"8575d5e0-38bf-4cbb-af1a-737ae63c07ee\"}"
      }
    ],
    "isError": false
  }
}
```

### 13b. approve the creation

_Proves:_ A creation verifies with an auditRef and no rollbackRef, because there is no prior state to snapshot.

HTTP 200

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 20,
  "method": "tools/call",
  "params": {
    "name": "content-write",
    "arguments": {
      "operation": "content-create",
      "arguments": {
        "type": "post",
        "title": "Phase 3a demonstration",
        "status": "draft"
      },
      "planToken": "<redacted>"
    }
  }
}
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 20,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"success\":true,\"operationId\":\"content-create\",\"data\":{\"target\":\"post:16\",\"changed\":[\"post_content\",\"post_excerpt\",\"post_status\",\"post_title\",\"post_type\"],\"state\":{\"post_type\":\"post\",\"post_status\":\"draft\",\"post_title\":\"Phase 3a demonstration\",\"post_name\":\"\",\"post_content\":\"\",\"post_excerpt\":\"\",\"post_parent\":0,\"post_modified_gmt\":\"0000-00-00 00:00:00\",\"featured_media\":0,\"terms\":{\"category\":[1],\"post_format\":[],\"post_tag\":[]},\"meta\":{\"subtitle\":\"\"}}},\"verification\":\"verified\",\"warnings\":[\"No snapshot was captured for this change, so no rollback reference is offered.\"],\"correlationId\":\"6a643ed4-d714-4bf5-819c-addefb883ac8\",\"auditRef\":\"audit-5\"}"
      }
    ],
    "isError": false
  }
}
```

### Post revisions after the rollback

Revisions of the fixture post `post:13`, whose live title after the rollback is
`Phase 3a fixture post`. These are the post's only two revisions.

| Revision ID | Date (GMT) | Title |
|---|---|---|
| 15 | 2026-07-26 11:26:19 | `Phase 3a fixture post` |
| 14 | 2026-07-26 11:26:12 | `Revised heading` |

**What this table shows, and what it does not.** An earlier version of this
section presented the table as evidence for REQ-0014's "the prior revision
remained available" clause. It is not. Revision 14 holds the **new** title, not
the prior one:

- Revision 14 was created by the **update** and records the state the update
  wrote (`Revised heading`).
- Revision 15 was created by the **rollback** and records the state the rollback
  wrote (`Phase 3a fixture post`).

At the moment of the update, the prior title existed in **no** revision at all.
WordPress's `wp_save_post_revision()` records the post's state *after* each save,
and the fixture post had no revision history when the update ran.

Probed directly against this install (WordPress 7.0.2), reading `wp_posts` rather
than `get_post()`, because a CLI process's object cache does not see what a
separate HTTP request wrote:

| Step | Live title | Revisions |
|---|---|---|
| after create | `State A` | *(none)* |
| after update 1 (A → B) | `State B` | `25: State B` |
| after update 2 (B → C) | `State C` | `25: State B`, `26: State C` |

So the pre-update state reaches a revision only from the *second* save onward,
and even then it is the revision the *previous* save created, not one the update
creates. For a freshly created item — exactly this fixture — the first update
leaves the prior version in no revision.

**What actually satisfies the clause.** Recovery of the prior version is provided
by SiteHelm's own snapshot, not by WordPress revisions. That is what steps 6, 11
and 12 above evidence end to end: the applied write returned a `rollbackRef`, the
rollback previewed the recorded prior state, and approving it restored
`Phase 3a fixture post` with `verification: verified`. The snapshot is the
recovery mechanism precisely because WordPress revisions did not hold that state.

**Residual gap.** This session does not demonstrate the narrower reading of the
clause — that WordPress's own revision history retains the pre-update version
across a SiteHelm update. The probe above shows that holding for an item with
existing revision history and *not* holding for a freshly created one, so the
clause is true only conditionally, and no recorded MCP session in this document
exercises the conditional case. Recorded as an open item in `tasks/todo.md` rather
than claimed here. No unit test can close it either: revisions are created inside
`wp_update_post()`, which Brain Monkey stubs, so a unit assertion would be
testing the stub.

## Checklist

- [x] **1.** `system-read` lists `system-environment` and `audit-list`, both available.
- [x] **2.** `content-write` lists `content-update`, `content-create` and `content-rollback-apply`. **`content-update` declares only the meta-capability `edit_post`; before Task 1's mapping it was absent from this catalog for every user, administrators included.**
- [x] **3.** `content-get` returns the normalized record including the permitted `subtitle` metadata.
- [x] **4.** A `content-update` call with no `planToken` returns a plan with a token, both renderings, a state fingerprint and snapshot eligibility — and a follow-up read shows the post unchanged.
- [x] **5.** A token bound to a different payload is refused with `stale_plan`, and the post remains unchanged.
- [x] **6.** The correct token with the same arguments returns `verification: verified`, an `auditRef`, and a `rollbackRef`; a follow-up read shows the new title.
- [x] **7.** Replaying that same approval returns `stale_plan`, proving single use.
- [x] **8.** Approving with no `arguments` returns `invalid_input` whose message says they must be resent unchanged beside the plan token.
- [x] **9.** Approving with `plan_token` misspelled returns `invalid_input`, not a fresh preview inside a success envelope.
- [x] **10.** `audit-list` returns the entry for the applied write with actor, client, operation, target, plan fingerprint, timestamp, outcome `applied`, and `rollbackRef`. Its summary carries field names and byte counts only — no fragment of either title appears anywhere in the response.
- [x] **11.** `content-rollback-apply` with a `rollbackRef` and no token returns a plan: a rollback is itself preview-required, per interpretation I3.
- [x] **12.** Approving the rollback returns `verified`, and a follow-up read shows the original title restored — from SiteHelm's recorded snapshot, which is what made the prior version recoverable. The revision table above does **not** evidence the prior version being retained by WordPress; see the note under it.
- [x] **13.** `content-create` verifies with an `auditRef` and **no** `rollbackRef`, because a creation has no prior state to snapshot.
