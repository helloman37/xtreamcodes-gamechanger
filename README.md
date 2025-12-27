# GameChanger Panel (Admin + Subscriber Portal)

A clean, modern PHP panel for managing licensed Live/VOD catalogs and delivering a fast subscriber portal — with **built-in modules**

> **Legal note:** This software is intended for managing and distributing **content you have the rights to stream** (your own channels, licensed feeds, private networks, or authorized catalogs). Do not use it to distribute pirated content.

---

## What this panel is

- **Admin panel** for managing content, tools, and system utilities
- **Subscriber portal** for browsing, watching, and managing personal features
- **Plugin-free**: features are integrated directly into the core panel layout & routing

---

## Highlights

### Admin: Content tools
- **NGINX Panel** (Content → NGINX Panel)  
  Built-in tool for generating an **M3U playlist** from an API source (server/username/password) with proper groups and logos.  
  *Clean admin styling, no “NGINX Panel → M3U Export” branding text on-page.*

- **M3U Builder** (Content → M3U Builder)  
  Build and export curated playlists (your own structure, your own rules) directly inside the panel.

- **VOD Enabler** (Content → VOD Enabler)  
  Enables clean VOD routes and browsing/playback wiring where applicable.

### Admin: System utilities
- **Dead Stream Hunter** (Replaces Stream Probe entirely)  
  Stream health checking / detection workflow built directly into the existing Stream Probe location.

- **Support Desk** (System → Support Desk)  
  Admin-side ticket management with the option to expose subscriber-facing support pages.

- **Watchlist (Admin view)** (System → Watchlist)  
  Admin visibility into watchlist usage + maintenance actions.

### Subscriber Portal features
- **EPG / Guide**  
  Portal page for a TV guide experience.

- **Support Desk (Subscriber view)**  
  Portal support page (when enabled in settings).

- **Watchlist**  
  Subscribers can save Live/VOD items and manage favorites from inside the portal.

---

## “No Plugins” architecture

This build intentionally avoids:
- `/plugins` directory
- plugin installers
- plugin managers
- “Plugins” admin menu pages

Everything is integrated into:
- the existing admin layout
- the existing portal layout
- the main routing/navigation

---

## Requirements (typical)
- PHP 8.x recommended
- MySQL/MariaDB
- cURL enabled
- JSON enabled
- Apache + `.htaccess` (or NGINX equivalent rewrites)
- `allow_url_fopen` not required (cURL used)

> If you run behind strict hosts/CDNs, make sure outbound requests to your upstream APIs are allowed.

---

## Install / Update (high level)
1. Upload the panel to your web root (or desired directory).
2. Set your database credentials (via your panel’s config/installer flow).
3. Ensure permissions allow the panel to write config/cache where needed.
4. Confirm rewrites are enabled (pretty URLs / portal routes).
5. Log in to admin and set your base URL + system settings.
6. Configure your catalogs and portal options.

> Because hosting setups vary, this README stays high level. If you want, I can write a “step-by-step” install section specifically for **Apache** or **NGINX** once you tell me which you’re using.

---

## Security notes (practical)
- Use HTTPS
- Restrict admin access (IP allowlist, VPN, or reverse proxy auth)
- Use strong admin passwords
- Keep PHP updated
- Disable directory listing
- Don’t expose backups/config files publicly

---

## Included pages / menu map

### Admin
- **Content**
  - NGINX Panel
  - M3U Builder
  - VOD Enabler
- **System**
  - Dead Stream Hunter (Stream Probe replacement)
  - Support Desk
  - Watchlist

### Portal
- Guide (EPG)
- Support
- Watchlist

---

## Screenshots / Demo
Add your screenshots here (recommended for forum posts):
- Admin dashboard
- Content → NGINX Panel
- Content → M3U Builder
- Portal → Guide
- Portal → Watchlist
- Admin → Dead Stream Hunter
- Support Desk (admin + portal)

---

## Support / Feedback
If you found a bug, include:
- what page you were on
- exact error message (or screenshot)
- PHP version + server (Apache/NGINX)
- steps to reproduce

---

## License
Use whatever license you distribute under (MIT/Proprietary/etc).  
If you want, tell me your preferred license text and I’ll drop it in clean.

---
**Forum snippet (short blurb):**

GameChanger Panel is a plugin-free PHP admin + subscriber portal build with integrated M3U tooling, EPG/Guide, Dead Stream Hunter (Stream Probe replacement), Support Desk (admin + portal), VOD Enabler routes, and Watchlist. Clean navigation, native layout, and no plugin installer/pages.
