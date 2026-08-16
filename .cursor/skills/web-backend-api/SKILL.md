---
name: web-backend-api
description: >-
  Implements REST API endpoints, request routing, input validation, error handling,
  and server-side business logic in PHP, Node.js, or similar. Use when building API
  routes, handlers, middleware, file uploads, or integrating third-party services.
paths: "**/*.{php,ts,js,py,go}"
---

# Web Backend API Development

## Endpoint Design

| Method | Use for | Example |
|--------|---------|---------|
| GET | Read, list, query | `GET /api/photos?since_id=10` |
| POST | Create, actions | `POST /api/photos` (upload) |
| PUT/PATCH | Full/partial update | `PATCH /api/guests/42` |
| DELETE | Remove | `DELETE /api/photos/7` |

Use query params for actions in legacy PHP routers (`?action=upload-photo`); prefer RESTful paths in new APIs.

## Request Lifecycle

```
Request → Router → Auth middleware → Validation → Handler → Response
                                              ↓
                                         Error handler (JSON envelope)
```

## PHP Router Pattern (this project)

```php
// api.php — thin router
$action = $_GET['action'] ?? '';
match ($action) {
    'get-reception-photos' => (new ReceptionApi())->getPhotos($_GET),
    'upload-reception-photo' => (new ReceptionApi())->uploadPhoto($_POST, $_FILES),
    default => jsonError('Unknown action', 404),
};

function jsonSuccess($data, $message = 'OK') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
}

function jsonError($error, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}
```

## Input Validation

Always validate server-side:

```php
// PHP example using project conventions
$validator = new InputValidator();
$name = $validator->string($_POST['guest_name'] ?? '', maxLength: 128);
$table = $validator->int($_POST['table_number'] ?? null, min: 1, max: 50);

if (!$validator->isValid()) {
    jsonError($validator->firstError(), 400);
}
```

Rules:
- Whitelist allowed fields; reject unknown keys on strict endpoints.
- Sanitize output with `htmlspecialchars` before storing display names.
- Validate file uploads: MIME type, extension, size limit, scan path traversal.

## File Uploads

```
- [ ] Max file size enforced (php.ini + app check)
- [ ] Allowed MIME types whitelisted
- [ ] Generate random filename (never use user filename directly)
- [ ] Store outside web root or block script execution in upload dir
- [ ] Return public URL, not filesystem path
```

## Error Handling

| Code | When |
|------|------|
| 400 | Invalid input, missing fields |
| 401 | Missing or expired auth token |
| 403 | Authenticated but not authorized |
| 404 | Resource not found |
| 409 | Conflict (duplicate, stale state) |
| 429 | Rate limit exceeded |
| 500 | Unhandled server error (log details, return generic message) |

Never expose stack traces or SQL errors to clients in production.

## Node.js / Express Pattern

```typescript
app.post('/api/photos', authenticate, upload.single('photo'), async (req, res) => {
  const parsed = uploadSchema.safeParse(req.body);
  if (!parsed.success) return res.status(400).json({ success: false, error: parsed.error.message });
  const photo = await photoService.create(parsed.data, req.file);
  res.json({ success: true, data: photo });
});
```

## Logging & Audit

- Log request ID, action, user ID, duration — not passwords or tokens.
- Use `AuditLogger` pattern for admin actions (see `rsvp/AuditLogger.php`).
- Structured JSON logs for production aggregation.

## Verification

1. Test each endpoint with valid, invalid, and unauthorized requests.
2. Confirm CORS headers if frontend is on a different origin.
3. Use Postgres/Supabase MCP to verify DB side effects after writes.
4. Use GitHub MCP to open PR with API doc updates.

## Related Skills

- [web-database](../web-database/SKILL.md) — Queries and migrations
- [web-auth-security](../web-auth-security/SKILL.md) — Auth middleware
- [web-testing](../web-testing/SKILL.md) — API integration tests
