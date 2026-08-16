---
name: web-app-foundation
description: >-
  Guides architecture decisions, project scaffolding, folder structure, environment
  configuration, and stack selection for new web applications. Use when starting a
  web app, choosing frontend/backend stacks, organizing a repo, setting up env vars,
  or planning system architecture.
---

# Web App Foundation

## When to Apply

Use this skill when:
- Starting a new web application or major feature area
- Choosing or validating a tech stack
- Defining repo layout, env strategy, or API conventions
- Onboarding to an unfamiliar web project

## Architecture Decision Checklist

```
- [ ] Define user roles and primary flows
- [ ] Choose rendering model (SPA, MPA, SSR, hybrid)
- [ ] Choose backend style (monolith API, serverless, BaaS)
- [ ] Choose database and hosting targets
- [ ] Define auth model (session, JWT, OAuth, magic link)
- [ ] Define API contract (REST, GraphQL, RPC)
- [ ] Plan env separation (local, staging, production)
- [ ] Plan observability (logs, errors, metrics)
```

## Recommended Folder Layout

```
project-root/
├── .cursor/
│   ├── mcp.json              # MCP servers for this project
│   └── skills/               # Team agent skills
├── .github/workflows/        # CI/CD
├── public/ or static/        # Public assets, PWA files
├── src/ or app/              # Application source
│   ├── components/           # UI components
│   ├── pages/ or routes/     # Route-level views
│   ├── lib/ or utils/        # Shared helpers
│   ├── api/ or services/     # Client-side API layer
│   └── styles/               # Global styles
├── server/ or api/           # Backend (if separate)
├── migrations/ or sql/       # Database migrations
├── tests/                    # Unit, integration, E2E
├── .env.example              # Documented env template (no secrets)
└── README.md
```

For PHP/vanilla-JS projects (like this repo), adapt:

```
project-root/
├── index.html                # Entry pages
├── js/ css/                  # Client assets
├── rsvp/ or api/             # PHP backend modules
│   ├── api.php               # Router
│   ├── config.php            # Env + DB bootstrap
│   └── *.sql                 # Schema files
└── .htaccess                 # Apache routing
```

## Stack Selection Guide

| Need | Default choice | When to diverge |
|------|----------------|-----------------|
| Interactive UI | React + TypeScript | Simple static site → vanilla HTML/JS |
| Full-stack JS | Next.js or Remix | PHP hosting only → PHP backend |
| API backend | Node (Express/Fastify) or PHP | Team expertise, hosting constraints |
| Database | PostgreSQL | Shared hosting → MySQL |
| Auth | Session + httpOnly cookie or Auth.js | BaaS → Supabase Auth |
| Styling | Tailwind CSS | Design system → CSS modules |
| Deployment | Vercel / Cloudflare / GitHub Actions | cPanel → FTP + `.cpanel.yml` |

## Environment Variables

1. Copy `.env.example` → `.env` locally; never commit `.env`.
2. Document every variable: purpose, example value, required/optional.
3. Use `${env:VAR}` in `.cursor/mcp.json`; never hardcode secrets.
4. Separate config by environment: `DATABASE_URL`, `API_BASE_URL`, `APP_ENV`.

## API Conventions

Standard JSON envelope:

```json
{ "success": true, "message": "...", "data": {} }
{ "success": false, "error": "Human-readable message" }
```

- Version APIs when breaking changes are expected (`/api/v1/`).
- Use consistent HTTP status codes: 200 success, 400 validation, 401 auth, 403 forbidden, 404 not found, 500 server error.
- Validate all inputs server-side; never trust client data.

## New Feature Workflow

1. **Spec** — Define user story, acceptance criteria, API contract.
2. **Schema** — Add migration if data model changes.
3. **Backend** — Implement endpoint with validation and auth checks.
4. **Frontend** — Build UI consuming the API.
5. **Test** — Unit tests for logic; E2E for critical flows.
6. **Deploy** — CI passes → staging → production.

## Related Skills

- [web-frontend](../web-frontend/SKILL.md) — UI implementation
- [web-backend-api](../web-backend-api/SKILL.md) — Server endpoints
- [web-database](../web-database/SKILL.md) — Schema and queries
- [web-auth-security](../web-auth-security/SKILL.md) — Auth and hardening
- [web-mcp-servers](../web-mcp-servers/SKILL.md) — MCP tool integration
