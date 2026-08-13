---
name: wedding-pov-gallery
description: >-
  Procedures, architecture, API specifications, client-side pre-compression rules,
  and troubleshooting runbook for building and maintaining the Wedding POV Live Gallery system.
---

# Skill: Wedding POV Live Gallery System

This skill provides full technical instructions, API contracts, client-side image compression rules, database schemas, and verification runbooks for building and maintaining the Wedding POV Live Gallery feature in the `testInvitation` workspace.

---

## 1. System Overview & Data Flow

```
[ Mobile Phone ]                     [ Server API Router ]                     [ Admin Dashboard ]
reception/app.html                  rsvp/api.php + ReceptionApi.php           rsvp/admin.php
  ├── 1. Canvas Pre-compress          ├── 1. Query Param Auth (?key=...)        ├── 1. Photo Grid View
  ├── 2. FormData Upload              ├── 2. Local Storage (reception/uploads)  ├── 2. Moderation (Delete/Hide)
  └── 3. Delta Poll (since_id) <───────┴── 3. MySQL DB (reception_photos) <───────┴── 3. Download All (.zip)
```

---

## 2. Fixed Technical Specifications

### A. API Endpoints Contract (`rsvp/api.php`)

All reception API requests must be sent to `/rsvp/api.php` with the access key in the URL query string to avoid server-side header stripping:

| Endpoint | Method | Params | Description |
| :--- | :--- | :--- | :--- |
| `upload-reception-photo` | `POST` | `?action=upload-reception-photo&key=KEY` | Uploads compressed photo blob with optional `guest_name` and `table_number`. |
| `get-reception-photos` | `GET` | `?action=get-reception-photos&key=KEY&since_id=ID` | Returns list of photos (or deltas newer than `since_id`). |
| `like-reception-photo` | `POST` | `?action=like-reception-photo&key=KEY` | Increments/decrements photo like count. |
| `delete-reception-photo` | `POST` | `action=delete-reception-photo` + Admin Auth | Admin endpoint to delete a photo and its file. |
| `download-reception-photos-zip` | `GET` | `action=download-reception-photos-zip` + Admin Auth | Streams `.zip` archive of all full-resolution guest photos. |

---

### B. Database Schema (`reception_photos`)

```sql
CREATE TABLE IF NOT EXISTS reception_photos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(512) NOT NULL,
    mime_type VARCHAR(64) NOT NULL,
    guest_name VARCHAR(128) DEFAULT NULL,
    table_number INT DEFAULT NULL,
    like_count INT DEFAULT 0,
    is_hidden TINYINT(1) DEFAULT 0,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_reception_photos_uploaded_at ON reception_photos(uploaded_at);
CREATE INDEX idx_reception_photos_since_id ON reception_photos(id);
```

---

### C. Client-Side Image Pre-Compression (`reception/reception.js`)

To guarantee uploads take **< 1 second** on 4G/5G mobile signals and eliminate server HEIC conversion failures:

```javascript
async function compressImageForUpload(file, maxDimension = 1920, quality = 0.82) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(url);
      let { width, height } = img;
      if (width > maxDimension || height > maxDimension) {
        if (width > height) {
          height = Math.round((height * maxDimension) / width);
          width = maxDimension;
        } else {
          width = Math.round((width * maxDimension) / height);
          height = maxDimension;
        }
      }
      const canvas = document.createElement("canvas");
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext("2d");
      ctx.drawImage(img, 0, 0, width, height);
      canvas.toBlob(
        (blob) => {
          if (!blob) return reject(new Error("Compression failed"));
          resolve(new File([blob], (file.name || "photo").replace(/\.[^/.]+$/, "") + ".webp", { type: "image/webp" }));
        },
        "image/webp",
        quality
      );
    };
    img.onerror = () => reject(new Error("Could not load image"));
    img.src = url;
  });
}
```

---

## 3. Mandatory Development Rules

1. **Query String Key Parameter**: ALWAYS append `&key=${encodeURIComponent(RECEPTION_KEY)}` to `fetch()` URLs to prevent Apache header stripping.
2. **Upload Directory Path**: Storage MUST be resolved to `__DIR__ . '/../reception/uploads'` inside the project workspace.
3. **Event Bubbling Safety**: ALWAYS call `e.stopPropagation()` inside click handlers for `#photo-upload-camera-btn` and `#photo-upload-gallery-btn`.
4. **PHP Lint Verification**: ALWAYS run `php -l <filepath>` on modified PHP backend files before concluding a task.

---

## 4. Verification Checklist

- [ ] Upload test via `reception/app.html?key=...` succeeds on desktop & mobile simulator.
- [ ] Uploaded photos are stored under `reception/uploads/`.
- [ ] 10-second polling correctly fetches new photos using `since_id`.
- [ ] Photo likes persist across page reloads.
- [ ] Admin Dashboard at `/rsvp/admin.php` allows viewing, deleting, and ZIP downloading photos.
