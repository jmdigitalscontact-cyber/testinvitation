---
type: implementation-log
status: proposed-not-implemented
updated: 2026-08-28
project: The Berbers Wedding — testInvitation
tags:
  - berbers-wedding
  - google-photos
  - reception
  - gallery
  - failover
---

# Google Photos Guest Gallery — Reception Hybrid

## Objective

Give the couple a Google Photos album as the client-facing place to keep and review every guest photo afterwards, while the current reception Live gallery stays running in the background as failover.

Guests still scan the QR and use the reception app. They are not forced to sign into Google. Google Photos is the long-term album. Local `reception/uploads/` + MySQL remain the silent backup when Google is slow, blocked, or down.

This plan is adapted from the Timoteyo Cloudflare Pages log. This site is PHP on GoDaddy, not Astro + Cloudflare Pages, so the album reader belongs in `rsvp/api.php` rather than a Pages Function.

## Result

**Not implemented yet.** This file is the connection plan only.

Target result after implementation:

- Public album: [https://photos.app.goo.gl/LyebvyWMcerYSJmR6](https://photos.app.goo.gl/LyebvyWMcerYSJmR6) (Berbers Wedding)
- Reception Photos tab prefers Google album thumbnails when the album reader succeeds
- Existing Snap / Choose upload, likes, lightbox, marquee, wall, and admin ZIP keep working locally and stay hidden as failover
- If the Google album cannot be read, the page shows the local reception gallery with no extra steps for the guest

## Guest experience

1. Guest scans the QR code → `/reception/?key=...`
2. Photos tab opens as it does today (camera + gallery upload stay available)
3. **View full album** / **Add to Google Photos** opens [Berbers Wedding](https://photos.app.goo.gl/LyebvyWMcerYSJmR6) for guests who have a Google account
4. Every in-app upload still saves locally first (hidden failover)
5. The website requests the latest Google album metadata
6. When Google succeeds, the visible grid shows recent album photos
7. When Google fails or returns nothing, the local Live gallery is shown instead
8. After the event, the couple reviews the full set in Google Photos

Local likes, table tags, the venue wall, and admin moderation do not depend on Google.

## Architecture

```text
Guest device
   |
   |  A. In-app Snap / Choose (always on, hidden failover)
   |  B. Optional “Add to Google Photos” (client album)
   v
+---------------------------+     +----------------------------------+
| Local reception pipeline  |     | Collaborative Google Photos album|
| reception/uploads + MySQL |     | photos.app.goo.gl/LyebvyWMcerYSJmR6 |
+---------------------------+     +----------------------------------+
   |                                     |
   | get-reception-photos                | public shared-album HTML
   v                                     v
rsvp/api.php                             rsvp/api.php
  action=get-reception-photos              action=get-google-photos-album
   |                                     | extracts + caches ~5 min
   |                                     v
   +------------ failover ---------------+
                     |
                     v
            reception/reception.js
                     |
                     | Google items if present
                     | else local reception photos
                     v
            Photos tab / lightbox
```

Google is the client-visible album. Local storage is never turned off.

Do **not** set `GOOGLE_PHOTOS_CLIENT_ID` / `REFRESH_TOKEN` in `.env` as the current PHP is written. That path uploads to Google **instead of** disk. Failover requires the opposite: **local save first**, Google second.

## Project files

Current (keep, failover):

```text
reception/app.html
reception/reception.js
reception/reception.css
reception/wall.html
rsvp/api.php
rsvp/ReceptionApi.php
rsvp/admin.php
reception/uploads/
```

Planned (Google connection, not built yet):

```text
rsvp/ReceptionApi.php          # get-google-photos-album + optional copy-after-save
rsvp/api.php                   # route action=get-google-photos-album
reception/reception.js         # prefer Google grid, fall back to local photos
reception/app.html             # View full album + Add to Google Photos links
.env                           # GOOGLE_PHOTOS_ALBUM_URL only for the reader
```

Not used for this host:

```text
functions/api/wedding-album.js
functions/lib/google-photos.js
wrangler.jsonc
src/pages/wedding-test.astro
```

Those Cloudflare files belong to Timoteyo. This repo already has PHP + a reception API. Adding Cloudflare Pages would be a second host for one scrape.

### `rsvp/api.php` + `ReceptionApi.php` (planned album reader)

Same job as Timoteyo’s `functions/api/wedding-album.js`, on this stack:

- Album URL is server-side only: `https://photos.app.goo.gl/LyebvyWMcerYSJmR6`
- Accept only `photos.app.goo.gl` / `photos.google.com` HTTPS
- Follow at most five validated redirects
- Cap the upstream body (about 5 MB)
- Parse Google’s `AF_initDataCallback` album payload (unofficial; can break)
- Return latest items only
- Resize with `=w1200-no` on `googleusercontent.com` URLs
- Cache metadata about five minutes
- Do not store Google image bytes on GoDaddy
- On failure, return a structured error so the client uses local photos

Existing local endpoints stay as failover and must keep working:

| Endpoint | Role |
| :--- | :--- |
| `upload-reception-photo` | Always save locally first |
| `get-reception-photos` | Failover gallery + wall + likes |
| `serve-reception-photo` | Failover image bytes |
| `like-reception-photo` | Local only |
| `admin-download-photos-zip` | Failover archive |

### `reception/app.html` / `reception.js` (planned UI)

- Keep Snap your POV and Choose from gallery (failover upload)
- Add **View full album** → `https://photos.app.goo.gl/LyebvyWMcerYSJmR6`
- Add **Add to Google Photos** → same link (collaboration)
- After load: request `get-google-photos-album`
- If items exist, render those in the visible grid
- If the request fails or is empty, call existing `loadPhotos()` unchanged
- Do not hide or remove local upload; it is the path that always works without a Google account

### Album URL

```text
https://photos.app.goo.gl/LyebvyWMcerYSJmR6
```

Album title from the shared page: **Berbers Wedding**. Confirm **Link sharing** and **Collaborate — Let others add their photos and videos** stay on.

## Requirements

- This Google Photos album with link sharing + collaboration enabled
- PHP `curl` on GoDaddy (already used for Sheets / optional Photos)
- Reception QR key unchanged (`RECEPTION_API_KEY`)
- Local `reception/uploads/` writable (failover)
- Fallback gallery = current Live gallery (already built)
- Couple (or coordinator) to moderate the Google album in the Google Photos app

Optional later, not required to connect the shared album:

- OAuth `photoslibrary.appendonly` on the couple’s account for background copies into **their library**
- That API **cannot** insert into this pre-existing shared album. Photos landed via API would still need to be added to [Berbers Wedding](https://photos.app.goo.gl/LyebvyWMcerYSJmR6) by hand, or guests use **Add photos** on the album itself

## Reimplementation steps

### 1. Prepare Google Photos

1. Album already exists: [https://photos.app.goo.gl/LyebvyWMcerYSJmR6](https://photos.app.goo.gl/LyebvyWMcerYSJmR6)
2. Open sharing settings
3. Keep **Link sharing** on
4. Keep **Collaborate** on so guests can add photos
5. Test the link while signed into a **different** Google account
6. Confirm **Add photos** works on a phone
7. Do not put this URL on public invitation pages if the reception QR is meant to stay private; keep it behind `/reception/?key=...`

### 2. Add the album reader (PHP, not Cloudflare)

Add a route such as:

```text
GET /rsvp/api.php?action=get-google-photos-album&key=KEY
```

Keep the album URL on the server. Fetch the public album page, validate redirects, cap body size, parse media metadata, return only what the frontend needs.

Recommended JSON shape:

```json
{
  "success": true,
  "source": "google-photos",
  "albumUrl": "https://photos.app.goo.gl/LyebvyWMcerYSJmR6",
  "items": [
    {
      "id": "media-id",
      "src": "https://lh3.googleusercontent.com/...=w1200-no",
      "width": 3265,
      "height": 4898,
      "addedAt": 1787877375487
    }
  ]
}
```

On scrape failure:

```json
{
  "success": false,
  "source": "google-photos",
  "error": "album_unavailable"
}
```

The browser then loads `get-reception-photos`.

### 3. Cache metadata

Cache the Google response about five minutes so every guest phone does not scrape Google.

Suggested response header:

```text
Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=86400
```

Do not cache failed responses for a long period. Failed reads must fail over to local photos immediately.

### 4. Render the gallery (Google first, local failover)

```js
async function loadVisibleGallery() {
  try {
    const google = await apiGet("get-google-photos-album");
    if (google.success && Array.isArray(google.items) && google.items.length) {
      renderGooglePhotos(google.items);
      return;
    }
  } catch (_) {
    // fall through
  }
  await loadPhotos(); // existing local Live gallery
}
```

Local `loadPhotos()`, 10-second `since_id` polling, likes, and `wall.html` stay on the local API. The wall should keep using local photos so the venue screen does not wait on a five-minute Google cache.

### 5. Keep portrait frames and cover cropping

Existing reception masonry already uses cover-style tiles. Google-inserted images must use the same tile class so they do not render at natural size:

```css
.reception-gallery__item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
```

No Astro `:global(...)` issue here; this app is plain CSS.

### 6. Keep local photo-sharing hidden as failover

While Google is the client album, local upload must remain on:

1. Guest compresses in the browser (existing WebP canvas path)
2. `POST upload-reception-photo` always writes `reception/uploads/` + `reception_photos`
3. Admin ZIP, hide, delete, and the venue wall keep using that copy
4. Do not switch `receptionGooglePhotosEnabled()` to “Google only”
5. If a later background copy to the couple’s Google **library** is added, it must run **after** local save and must not fail the guest upload

Night-of failover cases:

| Failure | Guest still can |
| :--- | :--- |
| No Google account | Snap / Choose → local gallery |
| Google album scrape broken | See local Live gallery |
| Album collaboration turned off | Local upload still works |
| Venue Wi-Fi blocks googleusercontent | Local `serve-reception-photo` still works |
| After the event they want one album | Couple opens [Berbers Wedding](https://photos.app.goo.gl/LyebvyWMcerYSJmR6) and/or uploads the admin ZIP into it |

### 7. Verify on the live server

Reception Photos tab (Google Photos album):

```text
{PUBLIC_BASE_URL}/reception/?key={RECEPTION_API_KEY}
```

Admin local failover:

```text
{PUBLIC_BASE_URL}/rsvp/admin.php
```

Checks:

- [ ] Photos tab shows the shared-album layout and QR
- [ ] QR / Open album instead opens [Berbers Wedding](https://photos.app.goo.gl/LyebvyWMcerYSJmR6)
- [ ] Album **Add photos** works on a second Google account
- [ ] Reception Photos tab has no Snap / local Live gallery
- [ ] Admin → Photos can upload failover images, hide, delete, and export ZIP

## Operational limitations

- An iframe cannot be used. Google Photos returns `X-Frame-Options: SAMEORIGIN`
- After March 2025, the official Photos API cannot list a normal shared album such as [LyebvyWMcerYSJmR6](https://photos.app.goo.gl/LyebvyWMcerYSJmR6)
- The album reader is unofficial HTML parsing and can break if Google changes the page
- Google image URLs can rotate; refetch metadata rather than treating them as permanent
- The album must stay shared by link
- Anyone with the link can view the album
- Contributors need a Google account **only** to add straight into Google Photos
- Guests without Google use the hidden local Snap / Choose path
- There is no Google-side approval queue; remove bad photos in the Google Photos app
- Official API uploads can only go to albums **created by this app**, not into this existing shared album
- Filling current `.env` Photos OAuth vars would replace local storage — that breaks failover
- Video is fine in Google Photos; the reception app still accepts images only
- If the album grows past Google’s first exposed batch, paginate or keep **View full album** for the rest

## Moderation procedure

1. Assign one trusted person to watch the Google album during the event
2. Remove unwanted media in Google Photos
3. Hide or delete the matching local copy in **Admin → Guest POV gallery** if it was also uploaded in-app
4. Block a contributor in Google Photos if needed
5. After the event, turn off collaboration to stop new Google uploads; keep link sharing if family should still view
6. If the public link leaks, disable and recreate link sharing, then update this file, the reception links, and any QR that points at the album
7. Keep the local ZIP as the copy that does not depend on Google

## Fallback strategy

```text
Google album reader succeeds and has items
→ show Google Photos thumbnails in the Photos tab
→ keep local Snap / Choose available
→ keep local polling for wall / likes / admin

Google album reader fails or returns no items
→ show the current local Live gallery
→ local upload unchanged
→ keep “View full album” available when the link still works

Guest has no Google account
→ they never leave the reception app
→ photo is stored locally only
→ couple can add it to Google Photos later from the admin ZIP
```

The page must stay presentable with Google off. That is already true today; implementation must not remove it.

## Current status

- Reception Photos tab: **Google Photos shared album UI** (QR + Open album). Local Snap / Live gallery **removed from guests**.
- Local failover: **Admin → Photos** (upload, grid, ZIP, hide, delete)
- Album URL: [https://photos.app.goo.gl/LyebvyWMcerYSJmR6](https://photos.app.goo.gl/LyebvyWMcerYSJmR6)
- PHP album HTML scrape reader: **not built** (guests go to Google Photos directly)
- Existing `GOOGLE_PHOTOS_*` OAuth replace-path: **must stay disabled**

## Related project

Timoteyo log: `Google Photos Guest Gallery - Cloudflare Pages.md`  
This repo: `reception/` + `rsvp/ReceptionApi.php`  
Album: [Berbers Wedding](https://photos.app.goo.gl/LyebvyWMcerYSJmR6)
