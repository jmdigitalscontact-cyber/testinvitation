# Wedding RSVP System - Quick Start Guide

## Getting started (PostgreSQL + port 3000)

### Step 1: PostgreSQL (2 minutes)

1. Ensure PostgreSQL is running on `localhost:5432`.
2. Create database if needed: `CREATE DATABASE wedding_rsvp;`
3. Copy `.env.example` to `.env` in the project root and set `PG_PASS` if required.

### Step 2: Apply schema (1 minute)

From project root:

```powershell
php rsvp/apply-schema.php
php rsvp/create-admin.php
```

### Step 3: Start dev server (1 minute)

```powershell
.\start-dev.ps1
```

Or: `npm run dev`

### Step 4: Verify and use

1. Visit: http://localhost:3000/rsvp/setup.php — all checks green
2. **Admin:** http://localhost:3000/rsvp/admin.php (`admin` / `password` by default)
3. **Guests:** http://localhost:3000/rsvp.html or http://localhost:3000/rsvp/index.php

See [POSTGRES_SETUP.md](POSTGRES_SETUP.md) for full details.

---

## File structure

```
Invitation/
├── start-dev.ps1          # Dev server on :3000
├── .env                   # PG_* credentials (not in git)
└── rsvp/
    ├── index.php          # Guest RSVP page
    ├── admin.php          # Admin dashboard
    ├── setup.php          # Installation wizard
    ├── api.php            # API endpoints
    ├── apply-schema.php   # Run SQL migrations
    └── database-schema.sql
```
