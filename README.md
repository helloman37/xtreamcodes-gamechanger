[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-%24tysonworlds-ffdd00?style=for-the-badge&logo=buy-me-a-coffee&logoColor=black)](https://cash.app/$tysonworlds)
😁

# Simple IPTV Admin Panel (PHP 7.4–8.x) — Gamechanger Edition

Pure PHP + MySQL IPTV panel + companion Android app. No frameworks. No Composer. Shared-hosting friendly.

**Xtream-style Player API is working/fixed** and the Android client consumes it cleanly.

---

## Android App Download (Released)

The Android TV / Android phone app is **released for download**.

I’ve put a lot into this project. While the APK is public, I’m keeping the **full app source private**.

---

## 🚀 Gamechanger: Ordered Multi‑M3U Imports

This panel supports **multi M3U upload** with a **drag & drop “import order” list** — and that order is **persisted everywhere**:

- ✅ **Panel category + channel lists**
- ✅ **User M3U downloads** (`get.php`)
- ✅ **Xtream-style API output** (`player_api.php`)

So if an admin imports files in the order:

1. Sports.m3u  
2. Movies.m3u  
3. Kids.m3u  

…then the panel + exported playlists return **Sports → Movies → Kids**, matching that exact order (server-side).

> Note: some IPTV apps still sort locally (A→Z) no matter what. The server output is ordered, but the client may override it.

---

## ✅ What’s New (Recent Work)

### ✅ Today’s Work (Dec 16, 2025)

#### ✅ Fixed Fatal: “There is no active transaction” (Reseller)
- Guarded `rollBack()` so it only runs when a transaction is actually active (prevents reseller dashboard crashes).

#### ✅ Installer Cleanup
- Removed the “CLI installer” block from the installer UI.
- Removed the hardcoded demo base URL (`test.iptvnetworking.com`) from installer output and replaced it with a neutral placeholder.

#### ✅ Global Favicon (Every Page)
- Added a custom favicon and linked it globally across the panel (admin, reseller, installer, public).


### ✅ Today’s Work (Dec 15, 2025)

#### ✅ Fixed “Forbidden (CSRF)” on EPG XML Uploads
- The CSRF error was *usually* caused by the upload body being rejected/truncated (PHP/web‑server limits), which makes the CSRF token disappear.
- Updated the upload flow so oversized/rejected uploads don’t get mislabeled as CSRF, and return a clearer error.
- Reminder: server limits still must be set correctly (`post_max_size` >= `upload_max_filesize`, plus Nginx/Apache body limits if used).

#### ✅ Max Streams + Max Devices Enforcement (Channel‑Switch Safe)
- Reworked the limiter so **changing channels on the same device does NOT stack sessions** and instantly trigger “limit reached”.
- **Max Streams** now behaves like **concurrent streaming devices/sessions**, not “number of channel changes”.
- **Max Devices** is enforced separately when **Device Lock** is enabled.

#### ✅ Near Real‑Time “Active Stream” Tracking
- Sessions stay “alive” only while the device is actively streaming (updates `last_seen` during stream activity).
- Reduced reliance on long timeouts so slots free quickly after a stream stops.
- Prevents false “limit reached” when a user flips channels fast.

#### ✅ Always Show Current Password + Clickable M3U (Even After Reinstall)
- Fixed the “Not available (was created before password storage)” warning by making the encryption secret **persist across reinstalls/overwrites**.
- Admin can always view the current password (encrypted copy) and the full clickable M3U link for existing users.
- Legacy users are auto‑backfilled where possible so the warning disappears going forward.

### ✅ Web Installer (Styled Wizard)
- **Auto-redirects to `/install/`** if not installed.
- **Step-by-step wizard** with Next/Back (server-rendered; doesn’t break if JS fails).
- Writes directly to **`config.php`** (no manual edits required).
- Sets:
  - DB host/name/user/pass
  - `base_url`
  - Admin username/password (shown on Finish)
  - Optional **PayPal** + **CashApp** fields (not required)
- Runs schema + migrations cleanly and creates an `installed.lock`.

### ✅ Endpoint / URL Fixes
- Dashboard endpoint references fixed:
  - `/get.php` (not `/panel/get.php`)
  - `/xmltv.php` (not `/panel/xmltv.php`)

### ✅ Reseller Credits Display (Header)
Resellers can now always see exactly how many credits they have:

- The top header “Credits” label is now a **live badge**:
  - `Credits: 123`
  - Indicator dot is **green** when credits > 0
  - Indicator dot is **red** when credits = 0
- Implemented by injecting the reseller’s credit balance into the existing HTML topbar template.

> Note: Admins don’t “have” credits — this display is intended for reseller-facing pages.

### ✅ Admin/Reseller Dropdown → Change Password (NEW)
In the top header, clicking **ADMIN** or **RESELLER** opens a dropdown that allows the logged-in user to:

- Change password securely (requires current password)
- Enforces basic validation (min length + confirm match)

### ✅ Fail Videos (System → Fail Videos)
Admins can now set custom videos that play when a user fails authentication or is blocked — instead of returning plain text like **“Invalid credentials”**.

- Admin page: **System → Fail Videos**
- Supported formats (any URL ending with):
  - `.mp4`
  - `.m3u8`
  - `.ts`
- Enforced across common entrypoints:
  - `get.php`
  - Stream endpoints (e.g. `/live/...`, `/movie/...`, `/series/...`)
  - Segment-based streaming can use **`.ts`** fail videos for best compatibility

How it works:
- When a request fails (invalid login / expired / banned / limit reached), the server returns a **302 redirect** to your configured fail video URL.
- If no fail video is configured, the system falls back to the original behavior (plain error response).

### ✅ Category Manager + Channel Manager (NEW)
A dedicated **Categories** page (under **Content**) that lets admins:

- Create / rename / delete categories (shows channel counts)
- Manage channels **inside the selected category**
- Keeps `channels.category_id` and `channels.group_title` aligned for clean M3U `group-title` output

### ✅ Cascade Delete Categories (NEW)
When deleting a category, the system now also deletes **all channels inside that category** (safe “cascade delete” behavior).

> “Uncategorized” (or protected base category) is protected from deletion.

### ✅ Multi M3U Upload + Drag & Drop Order (NEW)
- Upload **multiple M3U files at once**
- Drag & drop to arrange import order **before** importing
- Import order persists across the panel + exports

### ✅ Persistent Ordering Everywhere (NEW)
To make admin-chosen ordering consistent, the DB now tracks ordering:

- `categories.sort_order`
- `channels.sort_order`

After import, the system updates sort orders so:
- categories appear in the same order as imported files
- channels appear in consistent order inside each category

### ✅ Import Upsert + Re-Order (NEW)
To support re-importing without duplicates:
- Channels are **upserted** (matched by stream URL) when possible
- Re-importing can update ordering and metadata instead of creating duplicates

---

## ✅ Admin + Reseller Account Management (Added)

### ✅ User Profile Fields (Admin + Reseller)
When creating/editing users you can now store:
- **Subscriber Name**
- **Subscriber Email**

These fields appear on:
- **Admin → Users → Add / Edit**
- **Reseller → Users → Add / Edit**

### ✅ Auto-Generated Numeric Credentials (Admin + Reseller)
When adding a new user, the panel can auto-generate:
- **Numeric username**
- **Numeric password**

Includes a **Regenerate** option so the admin/reseller can quickly roll new credentials.

### ✅ Admin View: Password + Clickable M3U Link
On **Admin → Users → Edit**, the admin can view:
- Username
- Password (admin view)
- Clickable **M3U link** (full credentials embedded)

Example format:
```
http://your-domain.com/get.php?username=USERNAME&password=PASSWORD&type=m3u
```

> Security note: the system stores a second, **encrypted** copy of the password (not a hash) so the admin can view it later. See **Database / Security** below.

### ✅ Subscriber Details (Tracking Abuse)
New admin page:
- **Admin → Users → Subscriber Details**

What it does:
- Shows users who have a subscription (active/all filter)
- Clicking a username shows their **device / activity log**:
  - What was watched
  - When it was watched
  - IP address
  - Device details (UA / device_id, when available)

This makes it much easier to track multi-IP abuse, account sharing, and suspicious activity patterns.

### ✅ Reseller Details: View Reseller’s Users (Admin)
On the reseller management screen, the admin can now see:
- **How many users a reseller has**
- A dedicated **Reseller Details** view listing all subscribers tied to that reseller (`users.reseller_id`)
- Quick jump from reseller’s user list → user edit page

### ✅ Reseller Permissions (Hardened)
Resellers can:
- Add users
- Edit user profile fields (name/email)
- Reset credentials (generate new numeric password)

Resellers cannot:
- Ban users / ban IPs
- Delete users
- Override subscriptions / expiry / entitlements

Those are **admin-only responsibilities** to keep abuse enforcement and billing control centralized.

### ✅ Reseller Warning Banner
A warning banner appears in the reseller panel:

> **Your Clients — do NOT give free access.**  
> If you are found giving free accounts away without prior approval from the website owner, your reseller account & your website account will be terminated.

---

## 🧰 Full Backup & Restore (System → Backup & Restore)

New admin page:
- **Admin → System → Backup & Restore**

Supports:
- **Full backup**: zips the **entire panel directory** + includes `database.sql`
- **Restore**: restores **panel files** (overwrite) and/or **database** from a backup

Important notes:
- Backups are stored in: `storage/backups/`
- Backups exclude `storage/backups/` itself to avoid infinite recursion
- Requires PHP **ZipArchive** (zip extension)
- Ensure web user can write to `storage/backups/`

---

## 🔐 Anti‑Bruteforce & Abuse Handling (How it Works)

Bruteforce tools generate username/password combos until they get a “success” response.
To stop that, the panel relies on:

1) **Rate limiting**
- Limits repeated failures by **IP** and by **username**
- Returns a generic failure/limit response so bots can’t learn “user exists”

2) **Progressive lockouts**
- Temporary lockout windows after too many failures
- Escalation for repeat offenders (longer cooldowns)

3) **Bans**
- Quick **ban IP / ban account** workflows via Admin tools
- Suspicious behavior is visible via telemetry + request logs, making abuse easy to spot

4) **Telemetry**
- Tracks failure reasons (`auth_fail`, `banned_ip`, `rate_limited`, `max_connections`, etc.)
- Lets admins identify top attacking IPs and block them

> Best practice: put Cloudflare (or any WAF) in front and block datacenter bots + suspicious ASNs.

---

## 🕒 EPG System (Upgraded)

### ✅ XMLTV endpoint actually returns imported EPG
`xmltv.php` now outputs a real XMLTV feed based on the imported guide data (instead of an empty `<tv>`).

### ✅ EPG Extract / Filter (NEW)
New admin page under **EPG**:

- Upload XMLTV (`.xml` or `.gz`)
- Auto-detect “locations” (USA / Asia / etc.) using channel id + display-name matching
- Select locations → generates a **new filtered XMLTV** download (real XMLTV output)

### ✅ Upload XMLTV as Source (NEW)
New admin page under **EPG**:

- Upload **1 or 2 XMLTV files** (`.xml` or `.gz`)
- If 2 files are uploaded, they are **combined** into one XMLTV on the server
- The upload creates/updates a **local epg source** in the database (same flow as URL sources)
- Then it runs the importer automatically

### ✅ Auto-replace EPG on import (NEW)
When importing from **URL** or **uploaded XMLTV**, the importer automatically **replaces the previous EPG** instead of stacking old guide data:

- No duplicates
- No stale programmes

Uploaded XMLTV sources are also auto-maintained:
- Uploading again updates the existing “local upload” source
- Old uploaded files are cleaned up automatically

---

## Features (Current)

### 🔐 Admin Area (`/admin`)
- Admin login/logout
- Dashboard stats:
  - Total channels
  - Online/offline/unchecked health stats
  - Active users/resellers
  - Recent stream checks

> Default admin from early SQL files may exist in older installs. **Change it immediately.**

### 🧾 Abuse Controls (Ban System)
- Ban by **IP** and/or **username/account** for abuse.
- Enforced across:
  - `player_api.php`
  - `get.php`
  - `xmltv.php`
  - streaming endpoints (`/live`, `/seg`, etc.)
- Admin UI: **Abuse Bans** page with quick add/remove.

### 🧾 Telemetry + Audit Logs (Abuse Visibility)
- Request logging table (`request_logs`) for API + stream hits.
- Logs:
  - username (when present), IP, UA, device_id
  - endpoint/action
  - result reason (`auth_fail`, `banned_ip`, `rate_limited`, `max_connections`, etc.)
  - response time
- Admin UI: **System → Telemetry**
  - Top IPs / top failures
  - Suspicious accounts (many IPs in a short window)
  - Quick actions (ban IP/user)

### 💳 Billing Reports
**Billing → Reports** page:
- Monthly revenue grid (up to last 12 months)
- “Up for renewal” sections for accounts nearing expiry / renewal windows

### 📺 Channel + Category Management
- Add / edit / delete channels
- Create / rename / delete categories
- Channel fields:
  - Name, Category, Stream URL, Logo URL, EPG ID (tvg-id), Active toggle
- Stream status + last check timestamp per channel
- Consistent ordering via `sort_order` (panel + exports)

### 📂 M3U Import
- Import from:
  - uploaded M3U (**single or multiple**)
  - remote URL
- Parses: `tvg-id`, `tvg-logo`, `group-title`, name, URL
- Multi-file import supports:
  - drag & drop file ordering before upload
  - persistent category ordering based on import order
  - per-category channel ordering
  - duplicate-friendly workflows (upsert when possible)

### ✅ Stream Checker
- Fast cURL probe (HEAD)
- Stores `status` + `last_check`

---

## Install (Web Wizard)

1) Upload files to your web root  
2) Visit your domain → it redirects to `/install/` automatically  
3) Enter DB credentials + base URL  
4) (Optional) enter PayPal/CashApp fields  
5) Finish → installer prints **admin username + password**  
6) Login at `/admin`

**After install:** delete `/install/` or block it via web server rules (recommended).

---

## Rewrites (Xtream-style URLs)

If you want `/live/...` and `/seg/...` to work, enable the provided Apache/Nginx rewrite rules.

---

## Cron (Recommended)

```bash
*/10 * * * * php /path/to/scripts/stream_probe.php --limit=400 >/dev/null 2>&1
0 */6 * * * php /path/to/scripts/epg_import.php --flush=1 >/dev/null 2>&1
```

---

## Database / Migrations (Important)

Recent versions add/expect these columns:

- `users.name` (subscriber name)
- `users.email` (subscriber email)
- `users.reseller_id` (ties users to resellers for admin reporting)
- `users.password_enc` (encrypted password so admin can view it later)

If you are upgrading:
- run the built-in **migration.php**
- or apply the SQL equivalents used in migrations.

### Password storage note (Read this)
Passwords are still stored as a **secure hash** for authentication, and also stored as an **encrypted copy** (`password_enc`) so the admin can **view** them and build clickable M3U links.

If you want higher security, the safer alternative is:
- only show credentials **once** at creation
- later, allow **Reset Password** (but never reveal existing passwords)

---

## Notes on Ordering (Important)

- The server outputs categories/channels ordered by `sort_order` (admin-defined via import order).
- Some client apps will still sort categories/channels alphabetically on-device — that’s app behavior, not the server.

---

## Notes on Fail Videos (Compatibility Tips)

- **`.m3u8`** is the best choice for most IPTV apps (especially for Live-style playback).
- **`.mp4`** works well for many apps but not all “live” players.
- **`.ts`** is the safest for **segment endpoints** because those requests often expect transport stream bytes.

---

## Legal

This project is a starting point for IPTV tools. Only load streams/EPG data you have the legal right to use (e.g., free/OTT sources like Pluto TV).

---

## Support / Updates

If this saves you time and you want to support the work (especially the Android client), use the button at the top.
