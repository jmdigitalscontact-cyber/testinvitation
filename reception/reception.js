(function () {
  "use strict";

  const API_BASE = "../rsvp/api.php";
  const params = new URLSearchParams(window.location.search);
  const RECEPTION_KEY_STORAGE = "reception_access_key";
  const RECEPTION_KEY_PARAM = params.get("key") || "";
  const RECEPTION_KEY = RECEPTION_KEY_PARAM || localStorage.getItem(RECEPTION_KEY_STORAGE) || "";
  const MIN_SEARCH_CHARS = 2;

  if (RECEPTION_KEY_PARAM) {
    localStorage.setItem(RECEPTION_KEY_STORAGE, RECEPTION_KEY_PARAM);
  }

  const state = {
    guests: [],
    guestsLoaded: false,
    photos: [],
    floorPlanMeta: null,
    highlightTable: null,
    activeTab: "search",
    floorTransform: { scale: 1, x: 0, y: 0 },
  };

  const els = {
    panels: document.querySelectorAll(".reception-panel"),
    tabButtons: document.querySelectorAll(".reception-tabs__btn"),
    searchInput: document.getElementById("guest-search-input"),
    searchStatus: document.getElementById("guest-search-status"),
    searchResults: document.getElementById("guest-search-results"),
    floorViewport: document.getElementById("floor-plan-viewport"),
    floorStage: document.getElementById("floor-plan-stage"),
    floorHotspots: document.getElementById("floor-plan-hotspots"),
    floorLegend: document.getElementById("floor-legend"),
    floorHint: document.getElementById("floor-highlight-hint"),
    menuRoot: document.getElementById("menu-root"),
    menuLegend: document.getElementById("menu-tag-legend"),
    photoGalleryWrap: document.getElementById("photo-gallery-wrap"),
    photoGallery: document.getElementById("photo-gallery"),
    photoStatus: document.getElementById("photo-gallery-status"),
    photoUploadBtn: document.getElementById("photo-upload-btn"),
    photoUploadInput: document.getElementById("photo-upload-input"),
    photoLightbox: document.getElementById("photo-lightbox"),
    photoLightboxImg: document.querySelector(".reception-photo-lightbox__img"),
    photoLightboxCount: document.getElementById("photo-lightbox-count"),
    photoLightboxPrev: document.querySelector("[data-photo-lightbox-prev]"),
    photoLightboxNext: document.querySelector("[data-photo-lightbox-next]"),
    toast: document.getElementById("reception-toast"),
  };

  let photoLightboxIndex = 0;
  let photoLightboxLastFocus = null;

  function apiHeaders(isJson) {
    const headers = {};
    if (isJson) headers["Content-Type"] = "application/json";
    if (RECEPTION_KEY) headers["X-Reception-Key"] = RECEPTION_KEY;
    return headers;
  }

  function showToast(message, durationMs) {
    if (!els.toast || !message) return;
    els.toast.textContent = message;
    els.toast.hidden = false;
    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(() => {
      els.toast.hidden = true;
    }, durationMs || 2800);
  }

  async function apiGet(action) {
    const url = `${API_BASE}?action=${encodeURIComponent(action)}`;
    const res = await fetch(url, { headers: apiHeaders(false) });
    return res.json();
  }

  function hasReceptionAccessKey() {
    return RECEPTION_KEY.trim().length > 0;
  }

  function applyAccessLock() {
    const lockOverlay = document.getElementById("rec-lock-overlay");
    const appEl = document.getElementById("reception-app");

    if (!hasReceptionAccessKey()) {
      // Show lock overlay, hide app content
      if (lockOverlay) lockOverlay.hidden = false;
      if (appEl) appEl.hidden = true;
      document.title = "Reception (Access Required) | Jason & Rhona Mae";
      return;
    }

    // Key is present — hide lock, show app
    if (lockOverlay) lockOverlay.hidden = true;
    if (appEl) appEl.hidden = false;
  }

  function renderAccessError(message) {
    if (els.searchStatus) {
      els.searchStatus.textContent = message || "Reception access is unavailable.";
    }
    if (els.searchInput) {
      els.searchInput.disabled = true;
      els.searchInput.placeholder = "Access required";
    }
    clearSearchResults();
  }

  async function loadGuests() {
    els.searchStatus.textContent = "Loading guest list…";
    try {
      const result = await apiGet("get-reception-guests");
      if (result.success && Array.isArray(result.data)) {
        state.guests = result.data;
        state.guestsLoaded = true;
        showSearchIdleStatus();
        clearSearchResults();
        return;
      }
      throw new Error(result.error || "Could not load guests");
    } catch (err) {
      const msg = String(err?.message || "").toLowerCase();
      if (msg.includes("unauthorized")) {
        renderAccessError("Invalid or expired reception QR link. Please scan the official reception QR code again.");
      } else if (msg.includes("not configured")) {
        renderAccessError("Reception access is not configured yet. Please contact the hosts.");
      } else {
        els.searchStatus.textContent = "Could not load guest list.";
      }
      showToast(err?.message || "Guest list unavailable");
    }
  }

  function normalizeQuery(q) {
    return String(q || "")
      .trim()
      .toLowerCase()
      .normalize("NFD")
      .replace(/\p{M}/gu, "");
  }

  function getSearchQuery() {
    return normalizeQuery(els.searchInput?.value || "");
  }

  function isSearchActive(query) {
    return query.length >= MIN_SEARCH_CHARS;
  }

  function showSearchIdleStatus() {
    if (!els.searchStatus) return;
    els.searchStatus.textContent = "Type your name above to find your seat.";
  }

  function clearSearchResults() {
    if (els.searchResults) els.searchResults.innerHTML = "";
  }

  function guestMatches(guest, query) {
    if (!isSearchActive(query)) return false;
    const name = normalizeQuery(guest.name);
    const parts = query.split(/\s+/).filter(Boolean);
    return parts.every((part) => name.includes(part));
  }

  function escapeHtml(value) {
    const div = document.createElement("div");
    div.textContent = String(value || "");
    return div.innerHTML;
  }

  function formatTableLabel(guest) {
    if (guest.tableNumber == null || guest.tableNumber < 1) {
      return { text: "Table not assigned yet — please ask the hosts", unassigned: true };
    }
    let label = `Table ${guest.tableNumber}`;
    if (guest.seatNumber != null && guest.seatNumber > 0) {
      label += ` · Seat ${guest.seatNumber}`;
    }
    return { text: label, unassigned: false };
  }

  function renderSearchResults(list) {
    if (!els.searchResults) return;
    els.searchResults.innerHTML = "";

    const query = getSearchQuery();
    if (!isSearchActive(query)) {
      return;
    }

    if (!list.length) {
      const empty = document.createElement("li");
      empty.className = "reception-status";
      empty.textContent =
        state.guests.length === 0
          ? "No confirmed guests yet — please ask the hosts."
          : "No matching names found — check your spelling or ask the hosts.";
      els.searchResults.appendChild(empty);
      return;
    }

    list.forEach((guest, index) => {
      const li = document.createElement("li");
      const table = formatTableLabel(guest);
      li.innerHTML = `
        <article class="reception-result-card">
          <p class="reception-result-card__name">${escapeHtml(guest.name)}</p>
          <p class="reception-result-card__table ${table.unassigned ? "is-unassigned" : ""}">${escapeHtml(table.text)}</p>
          ${
            guest.tableNumber
              ? `<div class="reception-result-card__actions">
                  <button type="button" class="reception-btn reception-btn--secondary" data-view-table="${guest.tableNumber}">
                    View on floor plan
                  </button>
                </div>`
              : ""
          }
        </article>
      `;
      const card = li.querySelector(".reception-result-card");
      if (card) {
        card.style.animationDelay = `${Math.min(index, 8) * 45}ms`;
      }
      els.searchResults.appendChild(li);
    });

    els.searchResults.querySelectorAll("[data-view-table]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const tableNum = parseInt(btn.getAttribute("data-view-table"), 10);
        switchTab("floor");
        highlightTable(tableNum);
      });
    });
  }

  function onSearchInput() {
    if (!state.guestsLoaded) {
      clearSearchResults();
      return;
    }

    const query = getSearchQuery();

    if (!query.length) {
      showSearchIdleStatus();
      clearSearchResults();
      return;
    }

    if (!isSearchActive(query)) {
      els.searchStatus.textContent = `Type at least ${MIN_SEARCH_CHARS} letters of your name.`;
      clearSearchResults();
      return;
    }

    const filtered = state.guests.filter((g) => guestMatches(g, query));
    renderSearchResults(filtered);

    if (filtered.length === 0) {
      els.searchStatus.textContent = "No matches — try another spelling";
    } else if (filtered.length === 1) {
      els.searchStatus.textContent = "1 match";
    } else {
      els.searchStatus.textContent = `${filtered.length} matches — select yours if listed`;
    }
  }

  function switchTab(tabId) {
    state.activeTab = tabId;
    const hash = tabId === "search" ? "" : tabId;
    if (hash) {
      history.replaceState(null, "", `#${hash}`);
    } else {
      history.replaceState(null, "", window.location.pathname + window.location.search);
    }

    els.panels.forEach((panel) => {
      const isActive = panel.dataset.panel === tabId;
      panel.classList.toggle("is-active", isActive);
      panel.hidden = !isActive;
    });

    els.tabButtons.forEach((btn) => {
      const isActive = btn.dataset.tab === tabId;
      btn.classList.toggle("is-active", isActive);
      if (isActive) {
        btn.setAttribute("aria-current", "page");
      } else {
        btn.removeAttribute("aria-current");
      }
    });

    if (tabId === "photos" && !state.photos.length) {
      loadPhotos();
    }
  }

  function initTabs() {
    els.tabButtons.forEach((btn) => {
      btn.addEventListener("click", () => switchTab(btn.dataset.tab));
    });

    const hash = (window.location.hash || "").replace("#", "");
    if (["search", "floor", "menu", "photos", "gifts"].includes(hash)) {
      switchTab(hash);
    }
  }

  async function loadFloorPlanMeta() {
    try {
      const res = await fetch("./data/floor-plan.json");
      if (res.ok) {
        state.floorPlanMeta = await res.json();
        renderFloorLegend();
        renderFloorHotspots();
      }
    } catch {
      /* optional metadata */
    }
  }

  function renderFloorLegend() {
    if (!els.floorLegend || !state.floorPlanMeta?.legend) return;
    els.floorLegend.innerHTML = state.floorPlanMeta.legend
      .map((item) => `<span class="reception-legend__chip">${escapeHtml(item.label)}</span>`)
      .join("");
  }

  function renderFloorHotspots() {
    if (!els.floorHotspots || !state.floorPlanMeta?.tables) return;
    els.floorHotspots.innerHTML = "";
    state.floorPlanMeta.tables.forEach((t) => {
      const el = document.createElement("div");
      el.className = "reception-hotspot";
      el.dataset.table = String(t.number);
      el.style.left = `${t.left}%`;
      el.style.top = `${t.top}%`;
      el.style.width = `${t.width}%`;
      el.style.height = `${t.height}%`;
      el.title = `Table ${t.number}`;
      els.floorHotspots.appendChild(el);
    });
  }

  function highlightTable(tableNumber) {
    state.highlightTable = tableNumber;
    els.floorHotspots?.querySelectorAll(".reception-hotspot").forEach((el) => {
      const num = parseInt(el.dataset.table, 10);
      el.classList.toggle("is-highlighted", num === tableNumber);
    });
    if (els.floorHint) {
      els.floorHint.hidden = false;
      els.floorHint.textContent = `Showing Table ${tableNumber}`;
    }
    resetFloorView();
  }

  function resetFloorView() {
    state.floorTransform = { scale: 1, x: 0, y: 0 };
    applyFloorTransform();
  }

  function applyFloorTransform() {
    const { scale, x, y } = state.floorTransform;
    if (els.floorStage) {
      els.floorStage.style.transform = `translate(calc(-50% + ${x}px), calc(-50% + ${y}px)) scale(${scale})`;
    }
  }

  function initFloorPlanGestures() {
    const viewport = els.floorViewport;
    const stage = els.floorStage;
    if (!viewport || !stage) return;

    let pointers = new Map();
    let lastPinchDist = 0;
    let isDragging = false;
    let dragStart = { x: 0, y: 0 };
    let transformStart = { x: 0, y: 0 };

    function getPinchDistance() {
      const pts = [...pointers.values()];
      if (pts.length < 2) return 0;
      const dx = pts[1].x - pts[0].x;
      const dy = pts[1].y - pts[0].y;
      return Math.hypot(dx, dy);
    }

    viewport.addEventListener(
      "pointerdown",
      (e) => {
        viewport.setPointerCapture(e.pointerId);
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (pointers.size === 1) {
          isDragging = true;
          dragStart = { x: e.clientX, y: e.clientY };
          transformStart = { x: state.floorTransform.x, y: state.floorTransform.y };
          viewport.classList.add("is-dragging");
        } else if (pointers.size === 2) {
          lastPinchDist = getPinchDistance();
        }
      },
      { passive: true }
    );

    viewport.addEventListener(
      "pointermove",
      (e) => {
        if (!pointers.has(e.pointerId)) return;
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

        if (pointers.size >= 2) {
          const dist = getPinchDistance();
          if (lastPinchDist > 0) {
            const ratio = dist / lastPinchDist;
            state.floorTransform.scale = Math.min(4, Math.max(0.5, state.floorTransform.scale * ratio));
            applyFloorTransform();
          }
          lastPinchDist = dist;
          isDragging = false;
        } else if (isDragging && pointers.size === 1) {
          state.floorTransform.x = transformStart.x + (e.clientX - dragStart.x);
          state.floorTransform.y = transformStart.y + (e.clientY - dragStart.y);
          applyFloorTransform();
        }
      },
      { passive: true }
    );

    function endPointer(e) {
      pointers.delete(e.pointerId);
      if (pointers.size < 2) lastPinchDist = 0;
      if (pointers.size === 0) {
        isDragging = false;
        viewport.classList.remove("is-dragging");
      }
    }

    viewport.addEventListener("pointerup", endPointer);
    viewport.addEventListener("pointercancel", endPointer);

    viewport.addEventListener(
      "wheel",
      (e) => {
        e.preventDefault();
        const delta = e.deltaY > 0 ? 0.92 : 1.08;
        state.floorTransform.scale = Math.min(4, Math.max(0.5, state.floorTransform.scale * delta));
        applyFloorTransform();
      },
      { passive: false }
    );

    stage.style.position = "absolute";
    stage.style.top = "50%";
    stage.style.left = "50%";
    applyFloorTransform();
  }

  async function loadMenu() {
    try {
      const res = await fetch("./data/menu.json");
      if (!res.ok) throw new Error("Menu not found");
      const data = await res.json();
      renderMenu(data);
    } catch {
      els.menuRoot.innerHTML = "<p class=\"reception-status\">Menu unavailable.</p>";
    }
  }

  function renderMenu(data) {
    if (!els.menuRoot) return;

    if (els.menuLegend && data.tagLegend) {
      els.menuLegend.innerHTML = Object.entries(data.tagLegend)
        .map(([code, label]) => `<span class="reception-tag">${escapeHtml(code)}: ${escapeHtml(label)}</span>`)
        .join("");
    }

    els.menuRoot.innerHTML = (data.sections || [])
      .map((section) => {
        const items = (section.items || [])
          .map((item) => {
            const tags = (item.tags || [])
              .map((t) => `<span class="reception-tag">${escapeHtml(t)}</span>`)
              .join("");
            return `
              <article class="reception-menu-item">
                <p class="reception-menu-item__name">${escapeHtml(item.name)}</p>
                <p class="reception-menu-item__desc">${escapeHtml(item.description || "")}</p>
                ${tags ? `<div class="reception-tags">${tags}</div>` : ""}
              </article>
            `;
          })
          .join("");
        return `
          <section class="reception-menu-section">
            <h3 class="reception-menu-section__title">${escapeHtml(section.title)}</h3>
            ${items}
          </section>
        `;
      })
      .join("");
  }

  async function loadPhotos() {
    els.photoStatus.textContent = "Loading gallery…";
    try {
      const result = await apiGet("get-reception-photos");
      if (result.success && Array.isArray(result.data)) {
        state.photos = result.data;
        renderPhotos();
        els.photoStatus.textContent =
          state.photos.length === 0 ? "Be the first to share a photo!" : `${state.photos.length} photo(s)`;
        return;
      }
      throw new Error(result.error || "Failed");
    } catch {
      els.photoStatus.textContent = "Gallery unavailable.";
    }
  }

  function photoItemHtml(photo, index) {
    return `
      <button type="button" class="reception-gallery__item" data-photo-index="${index}" aria-label="View photo ${index + 1}">
        <img src="${escapeHtml(photo.url)}" alt="" loading="lazy" decoding="async" />
      </button>
    `;
  }

  function buildPhotoGridHtml() {
    return state.photos.map((p, i) => photoItemHtml(p, i)).join("");
  }

  function renderPhotos() {
    if (!els.photoGallery) return;

    if (!state.photos.length) {
      els.photoGallery.innerHTML = "";
      if (els.photoGalleryWrap) els.photoGalleryWrap.hidden = true;
      return;
    }

    const grid = buildPhotoGridHtml();
    els.photoGallery.innerHTML = `
      <div class="reception-gallery-set">${grid}</div>
      <div class="reception-gallery-set" aria-hidden="true">${grid}</div>
    `;

    const cols = 3;
    const rows = Math.ceil(state.photos.length / cols);
    const duration = Math.max(28, rows * state.photos.length * 2.5);
    els.photoGallery.style.setProperty("--gallery-scroll-duration", `${duration}s`);

    if (els.photoGalleryWrap) els.photoGalleryWrap.hidden = false;
  }

  function updatePhotoLightboxView() {
    const photo = state.photos[photoLightboxIndex];
    if (!photo || !els.photoLightboxImg) return;

    els.photoLightboxImg.src = photo.url;
    els.photoLightboxImg.alt = `Guest photo ${photoLightboxIndex + 1} of ${state.photos.length}`;

    if (els.photoLightboxCount) {
      els.photoLightboxCount.textContent = `${photoLightboxIndex + 1} / ${state.photos.length}`;
    }

    const single = state.photos.length <= 1;
    if (els.photoLightboxPrev) els.photoLightboxPrev.disabled = single;
    if (els.photoLightboxNext) els.photoLightboxNext.disabled = single;
  }

  function openPhotoLightbox(index) {
    if (!els.photoLightbox || !state.photos.length) return;

    photoLightboxIndex = ((index % state.photos.length) + state.photos.length) % state.photos.length;
    photoLightboxLastFocus = document.activeElement;

    updatePhotoLightboxView();
    els.photoLightbox.hidden = false;
    requestAnimationFrame(() => {
      els.photoLightbox.classList.add("is-open");
      document.body.classList.add("reception-lightbox-open");
      els.photoLightbox.querySelector("[data-photo-lightbox-close]")?.focus();
    });
  }

  function closePhotoLightbox() {
    if (!els.photoLightbox) return;

    els.photoLightbox.classList.remove("is-open");
    document.body.classList.remove("reception-lightbox-open");

    const onEnd = () => {
      if (!els.photoLightbox.classList.contains("is-open")) {
        els.photoLightbox.hidden = true;
        if (els.photoLightboxImg) els.photoLightboxImg.removeAttribute("src");
      }
      photoLightboxLastFocus?.focus({ preventScroll: true });
      photoLightboxLastFocus = null;
    };

    setTimeout(onEnd, 200);
  }

  function stepPhotoLightbox(delta) {
    if (state.photos.length <= 1) return;
    photoLightboxIndex =
      (photoLightboxIndex + delta + state.photos.length) % state.photos.length;
    updatePhotoLightboxView();
  }

  function initPhotoGallery() {
    els.photoGalleryWrap?.addEventListener("click", (event) => {
      const item = event.target.closest("[data-photo-index]");
      if (!item) return;
      const index = parseInt(item.getAttribute("data-photo-index"), 10);
      if (Number.isNaN(index)) return;
      openPhotoLightbox(index);
    });

    if (!els.photoLightbox) return;

    els.photoLightbox.addEventListener("click", (event) => {
      if (event.target.closest("[data-photo-lightbox-close]")) {
        closePhotoLightbox();
      }
    });

    els.photoLightboxPrev?.addEventListener("click", () => stepPhotoLightbox(-1));
    els.photoLightboxNext?.addEventListener("click", () => stepPhotoLightbox(1));

    els.photoLightbox.addEventListener("keydown", (event) => {
      if (!els.photoLightbox.classList.contains("is-open")) return;
      if (event.key === "Escape") {
        event.preventDefault();
        closePhotoLightbox();
      } else if (event.key === "ArrowLeft") {
        event.preventDefault();
        stepPhotoLightbox(-1);
      } else if (event.key === "ArrowRight") {
        event.preventDefault();
        stepPhotoLightbox(1);
      }
    });
  }

  async function uploadPhoto(file) {
    const form = new FormData();
    form.append("action", "upload-reception-photo");
    form.append("photo", file, file.name || "photo.jpg");

    const headers = {};
    if (RECEPTION_KEY) headers["X-Reception-Key"] = RECEPTION_KEY;

    const res = await fetch(API_BASE, {
      method: "POST",
      headers,
      body: form,
    });

    const raw = await res.text();
    let result = null;
    try {
      result = raw ? JSON.parse(raw) : null;
    } catch {
      throw new Error(res.ok ? "Invalid server response" : `Upload failed (${res.status})`);
    }

    if (!result || typeof result !== "object") {
      throw new Error(`Upload failed (${res.status})`);
    }

    if (!res.ok || result.success === false) {
      const message =
        result.error ||
        (res.status === 401
          ? "Upload not authorized — check the venue link includes ?key= if required"
          : res.status === 429
            ? "Too many uploads — please wait a few minutes"
            : `Upload failed (${res.status})`);
      throw new Error(message);
    }

    return result;
  }

  function isAllowedPhotoFile(file) {
    const maxBytes = 5 * 1024 * 1024;
    const allowedTypes = ["image/jpeg", "image/jpg", "image/pjpeg", "image/png", "image/webp"];
    const ext = (file.name || "").split(".").pop()?.toLowerCase() || "";
    const allowedExt = ["jpg", "jpeg", "png", "webp"];
    const typeOk = !file.type || allowedTypes.includes(file.type);
    const extOk = allowedExt.includes(ext);

    if (!typeOk && !extOk) {
      return { ok: false, error: `${file.name}: use JPEG, PNG, or WebP` };
    }
    if (file.size > maxBytes) {
      return { ok: false, error: `${file.name}: must be under 5MB` };
    }
    return { ok: true };
  }

  function initPhotoUpload() {
    els.photoUploadBtn?.addEventListener("click", () => {
      els.photoUploadInput?.click();
    });

    els.photoUploadInput?.addEventListener("change", async () => {
      const files = [...(els.photoUploadInput.files || [])];
      if (!files.length) return;

      const valid = [];
      const errors = [];
      files.forEach((file) => {
        const check = isAllowedPhotoFile(file);
        if (check.ok) valid.push(file);
        else errors.push(check.error);
      });

      if (errors.length) {
        showToast(errors[0]);
      }
      if (!valid.length) {
        els.photoUploadInput.value = "";
        return;
      }

      els.photoUploadBtn.disabled = true;
      let uploaded = 0;
      let failed = 0;

      for (let i = 0; i < valid.length; i++) {
        els.photoStatus.textContent =
          valid.length > 1
            ? `Uploading ${i + 1} of ${valid.length}…`
            : "Uploading…";

        try {
          const result = await uploadPhoto(valid[i]);
          if (result.success && result.data) {
            state.photos.unshift(result.data);
            uploaded++;
          } else {
            failed++;
            if (i === 0) throw new Error(result.error || "Upload failed");
          }
        } catch (err) {
          failed++;
          if (uploaded === 0 && i === 0) {
            showToast(err.message || "Upload failed");
            els.photoStatus.textContent = "Upload failed — try again";
            els.photoUploadBtn.disabled = false;
            els.photoUploadInput.value = "";
            return;
          }
        }
      }

      if (uploaded > 0) {
        renderPhotos();
        els.photoStatus.textContent = `${state.photos.length} photo(s)`;
        if (uploaded === 1) {
          showToast("Photo shared — thank you!");
        } else {
          showToast(`${uploaded} photos shared — thank you!`);
        }
      }
      if (failed > 0 && uploaded > 0) {
        showToast(`${failed} photo(s) could not be uploaded`);
      }

      els.photoUploadBtn.disabled = false;
      els.photoUploadInput.value = "";
    });
  }

  function init() {
    applyAccessLock();

    if (!hasReceptionAccessKey()) {
      return;
    }

    initTabs();
    initFloorPlanGestures();
    initPhotoGallery();
    initPhotoUpload();
    loadGuests();
    loadFloorPlanMeta();
    loadMenu();

    els.searchInput?.addEventListener("input", onSearchInput);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
