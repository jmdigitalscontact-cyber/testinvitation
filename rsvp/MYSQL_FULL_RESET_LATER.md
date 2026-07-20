# ARCHIVED — MySQL not used for this project

This project now uses **PostgreSQL** and the PHP dev server on port 3000. See [POSTGRES_SETUP.md](POSTGRES_SETUP.md).

The notes below are kept only for reference if you ever need to repair old XAMPP MySQL data.

---

# MySQL / InnoDB — Full Reset (Try Later)

Saved after the **2026-05-27** repair session. Use this when you want to remove the temporary workaround and fix the **root** InnoDB corruption in XAMPP.

---

## Current state (as of repair)

| Item | Status |
|------|--------|
| XAMPP path | `D:\xampp` |
| MariaDB | 10.4.32 |
| Wedding app URL | http://localhost/testing/rsvp/admin.php |
| Database | `wedding_rsvp` (recreated, empty except admin user) |
| Admin login | `admin` / `password` |
| Temporary fix | `innodb_force_recovery=1` in `D:\xampp\mysql\bin\my.ini` |
| Backup folder | `D:\xampp\mysql\backup_repair_20260527` |

**What still has damage:** the shared InnoDB system file `ibdata1` (and possibly other databases: `inventory_workflow`, `water_station`, `yztrone`, etc.). `wedding_rsvp` works; other DBs may still crash on query.

**Always shut down MySQL from XAMPP before sleep/shutdown** to reduce future corruption.

---

## Before you start (full reset)

1. Stop **MySQL** in XAMPP Control Panel.
2. Export anything you care about (see step 1 below).
3. Allow **30–60 minutes** — this affects **all** databases on this XAMPP install.

---

## Option A — Full InnoDB reset (cleanest, destructive)

This recreates InnoDB from scratch. **All databases are wiped** unless you export them first.

### 1. Export databases you want to keep

If MySQL starts, from PowerShell:

```powershell
D:\xampp\mysql\bin\mysqldump.exe -u root --databases wedding_rsvp > D:\xampp\mysql\backup_repair_20260527\wedding_rsvp_export.sql
```

Repeat for other DBs you need (`inventory_workflow`, etc.).

If MySQL will not start, skip to step 2 and restore from SQL/schema files later (wedding RSVP schemas are in this folder: `database-schema.sql`, `database-schema-additional.sql`, `database-table-assignments.sql`).

### 2. Stop MySQL and back up the entire data directory

```powershell
taskkill /F /IM mysqld.exe
Copy-Item "D:\xampp\mysql\data" "D:\xampp\mysql\data_backup_before_full_reset" -Recurse
```

### 3. Remove corrupted InnoDB files only

In `D:\xampp\mysql\data`, delete (or rename to `.bak`):

- `ibdata1`
- `ib_logfile0`
- `ib_logfile1`
- `ibtmp1` (if present)
- `mysql.pid` (if present)

**Do not delete** individual database folders (`wedding_rsvp`, `mysql`, etc.) yet — see Option B if you need a harder reset.

### 4. Edit `D:\xampp\mysql\bin\my.ini`

Under `[mysqld]`:

- **Remove** the line: `innodb_force_recovery=1`
- Keep: `max_allowed_packet=64M` (helpful)

### 5. Start MySQL from XAMPP

MariaDB will recreate `ibdata1` and new log files.

Check the log:

```powershell
Get-Content "D:\xampp\mysql\data\mysql_error.log" -Tail 20
```

Look for: `ready for connections`.

### 6. Recreate `wedding_rsvp`

```powershell
D:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS wedding_rsvp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cmd /c "D:\xampp\mysql\bin\mysql.exe -u root wedding_rsvp < D:\xampp\htdocs\testing\rsvp\database-schema.sql"
cmd /c "D:\xampp\mysql\bin\mysql.exe -u root wedding_rsvp < D:\xampp\htdocs\testing\rsvp\database-schema-additional.sql"
cmd /c "D:\xampp\mysql\bin\mysql.exe -u root wedding_rsvp < D:\xampp\htdocs\testing\rsvp\database-table-assignments.sql"
D:\xampp\php\php.exe D:\xampp\htdocs\testing\rsvp\create-admin.php
```

Or restore from export:

```powershell
cmd /c "D:\xampp\mysql\bin\mysql.exe -u root < D:\xampp\mysql\backup_repair_20260527\wedding_rsvp_export.sql"
```

### 7. Verify

```powershell
D:\xampp\mysql\bin\mysql.exe -u root -e "USE wedding_rsvp; SHOW TABLES; SELECT username FROM admin_users;"
```

Open: http://localhost/testing/rsvp/setup.php

---

## Option B — Nuclear reset (if Option A still fails)

1. Stop MySQL.
2. Rename `D:\xampp\mysql\data` → `data_old_corrupt`.
3. Copy fresh data from XAMPP install backup, or run XAMPP’s MySQL reset if available.
4. Import all schemas/exports from scratch.

---

## If MySQL still crashes during full reset

1. Temporarily add back to `my.ini`: `innodb_force_recovery=2`
2. Start MySQL, export what you can.
3. Then proceed with Option A or B.

---

## Wedding RSVP quick reference after any reset

| Step | Command / URL |
|------|----------------|
| Create admin | `D:\xampp\php\php.exe D:\xampp\htdocs\testing\rsvp\create-admin.php` |
| Setup page | http://localhost/testing/rsvp/setup.php |
| Admin panel | http://localhost/testing/rsvp/admin.php |
| Default login | `admin` / `password` |

---

## Files from 2026-05-27 repair backup

Location: `D:\xampp\mysql\backup_repair_20260527`

Contains copy of old `wedding_rsvp` table files and InnoDB files (`ibdata1`, `ib_logfile0`, `ib_logfile1`) from before rebuild. Use only if you need to attempt manual recovery of old invitation data.

---

*When you’re ready, tell Cursor: “Run the full InnoDB reset from MYSQL_FULL_RESET_LATER.md”*
