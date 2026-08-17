(function () {
  "use strict";

  /* ───────────────────────────────────────────
     CONFIG
     ─────────────────────────────────────────── */
  const API_BASE = new URL('../rsvp/api.php', window.location.href).toString();
  const params = new URLSearchParams(window.location.search);
  const RECEPTION_KEY_STORAGE = "reception_access_key";
  const RECEPTION_KEY_PARAM = params.get("key") || "";
  const RECEPTION_KEY = RECEPTION_KEY_PARAM || localStorage.getItem(RECEPTION_KEY_STORAGE) || "";
  const MIN_SEARCH_CHARS = 2;
  const THEME_STORAGE = "reception_theme";
  const LIKED_PHOTOS_STORAGE = "reception_liked_photo_ids";
  const VOTER_TOKEN_STORAGE = "reception_voter_token";
  const TEAM_VOTE_STORAGE = "reception_team_vote";
  const PHOTO_POLL_MS = 10000;

  if (RECEPTION_KEY_PARAM) {
    localStorage.setItem(RECEPTION_KEY_STORAGE, RECEPTION_KEY_PARAM);
  }

  /* ───────────────────────────────────────────
     STATE
     ─────────────────────────────────────────── */
  const state = {
    guests: [],
    guestsLoaded: false,
    photos: [],
    activeTab: "search",
    floorTransform: { scale: 1, x: 0, y: 0 },
    theme: localStorage.getItem(THEME_STORAGE) || "dark",
    likedPhotos: loadLikedPhotoIds(),
    giftBoxOpened: false,
  };

  let maxPhotoId = 0;

  function loadLikedPhotoIds() {
    try {
      const raw = localStorage.getItem(LIKED_PHOTOS_STORAGE);
      const parsed = raw ? JSON.parse(raw) : [];
      return new Set(Array.isArray(parsed) ? parsed.map((id) => Number(id)).filter((id) => id > 0) : []);
    } catch {
      return new Set();
    }
  }

  function saveLikedPhotoIds() {
    localStorage.setItem(LIKED_PHOTOS_STORAGE, JSON.stringify([...state.likedPhotos]));
  }

  function isPhotoLiked(photo) {
    return photo && state.likedPhotos.has(Number(photo.id));
  }

  function updateMaxPhotoId(photos) {
    (photos || []).forEach((photo) => {
      const id = Number(photo.id || 0);
      if (id > maxPhotoId) maxPhotoId = id;
    });
  }

  /* ───────────────────────────────────────────
     DOM REFS
     ─────────────────────────────────────────── */
  const els = {};
  function cacheEls() {
    els.panels = document.querySelectorAll(".reception-panel");
    els.tabButtons = document.querySelectorAll(".reception-tabs__btn");
    els.tabIndicator = document.getElementById("tab-indicator");
    els.searchInput = document.getElementById("guest-search-input");
    els.searchStatus = document.getElementById("guest-search-status");
    els.searchResults = document.getElementById("guest-search-results");
    els.suggestionPills = document.getElementById("suggestion-pills");
    els.floorViewport = document.getElementById("floor-plan-viewport");
    els.floorStage = document.getElementById("floor-plan-stage");
    els.floorLegend = document.getElementById("floor-legend");
    els.floorHint = document.getElementById("floor-highlight-hint");
    els.floorTables = document.getElementById("floor-tables");
    els.floorContainer = document.getElementById("floor-3d-container");
    els.floorResetBtn = document.getElementById("floor-reset-btn");
    els.tablePopup = document.getElementById("table-popup");
    els.tablePopupTitle = document.getElementById("table-popup-title");
    els.tablePopupList = document.getElementById("table-popup-list");
    els.menuRoot = document.getElementById("menu-root");
    els.menuLegend = document.getElementById("menu-tag-legend");
    els.menuFilters = document.getElementById("menu-filters");
    els.photoGalleryWrap = document.getElementById("photo-gallery-wrap");
    els.photoGallery = document.getElementById("photo-gallery");
    els.photoStatus = document.getElementById("photo-gallery-status");
    els.photoMarqueeWrap = document.getElementById("photo-marquee-wrap");
    els.photoMarqueeTrack = document.getElementById("photo-marquee-track");
    els.photoUploadCameraBtn = document.getElementById("photo-upload-camera-btn");
    els.photoUploadGalleryBtn = document.getElementById("photo-upload-gallery-btn");
    els.photoUploadCameraInput = document.getElementById("photo-upload-camera-input");
    els.photoUploadGalleryInput = document.getElementById("photo-upload-gallery-input");
    els.photoUploadActions = document.getElementById("photo-upload-actions");
    els.uploadZone = document.getElementById("upload-zone");
    els.photoLightbox = document.getElementById("photo-lightbox");
    els.photoLightboxImg = document.querySelector(".reception-photo-lightbox__img");
    els.photoLightboxCount = document.getElementById("photo-lightbox-count");
    els.photoLightboxPrev = document.querySelector("[data-photo-lightbox-prev]");
    els.photoLightboxNext = document.querySelector("[data-photo-lightbox-next]");
    els.photoLikeBtn = document.getElementById("photo-like-btn");
    els.toast = document.getElementById("reception-toast");
    els.particleCanvas = document.getElementById("particle-canvas");
    els.confettiCanvas = document.getElementById("confetti-canvas");
    els.themeToggle = document.getElementById("theme-toggle");
    els.themeToggleIcon = document.querySelector(".reception-theme-toggle__icon");
    els.giftBox = document.getElementById("gift-box");
    els.giftBoxLid = document.getElementById("gift-box-lid");
    els.giftBoxCta = document.querySelector(".rec-gift-box__cta");
    els.giftDetails = document.getElementById("gifts-details");
    els.lockOverlay = document.getElementById("rec-lock-overlay");
    els.lockCard = document.getElementById("rec-lock-card");
    els.lockKeyInput = document.getElementById("rec-lock-key-input");
    els.lockEnterBtn = document.getElementById("rec-lock-enter-btn");
    els.lockError = document.getElementById("rec-lock-error");
    els.app = document.getElementById("reception-app");
    els.receptionMain = document.getElementById("reception-main");
    els.welcomeOverlay = document.getElementById("rec-welcome-overlay");
    els.welcomeCta = document.getElementById("rec-welcome-cta");
    els.welcomeHint = document.getElementById("rec-welcome-hint");
    els.voteOverlay = document.getElementById("rec-vote-overlay");
    els.voteOptions = document.getElementById("rec-vote-options");
    els.voteButtons = document.querySelectorAll("[data-vote-team]");
    els.voteStatus = document.getElementById("rec-vote-status");
    els.voteResults = document.getElementById("rec-vote-results");
    els.voteBrideCount = document.getElementById("rec-vote-bride-count");
    els.voteGroomCount = document.getElementById("rec-vote-groom-count");
    els.voteTotal = document.getElementById("rec-vote-total");
    els.voteBrideBar = document.getElementById("rec-vote-bride-bar");
    els.voteGroomBar = document.getElementById("rec-vote-groom-bar");
    els.voteContinue = document.getElementById("rec-vote-continue");
  }

  let photoLightboxIndex = 0;
  let photoLightboxLastFocus = null;

  /* ───────────────────────────────────────────
     UTILITY
     ─────────────────────────────────────────── */
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
    showToast._timer = setTimeout(() => { els.toast.hidden = true; }, durationMs || 2800);
  }

  function apiUrl(action, extraParams) {
    const url = new URL(API_BASE);
    url.searchParams.set("action", action);
    if (RECEPTION_KEY) url.searchParams.set("key", RECEPTION_KEY);
    if (extraParams) {
      Object.entries(extraParams).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== "") {
          url.searchParams.set(key, String(value));
        }
      });
    }
    return url.toString();
  }

  async function apiGet(action, extraParams) {
    const res = await fetch(apiUrl(action, extraParams), { headers: apiHeaders(false) });
    if (!res.ok) throw new Error(`API error ${res.status}`);
    return res.json();
  }

  function escapeHtml(value) {
    const div = document.createElement("div");
    div.textContent = String(value || "");
    return div.innerHTML;
  }

  function normalizeQuery(q) {
    return String(q || "").trim().toLowerCase().normalize("NFD").replace(/\p{M}/gu, "");
  }

  function getInitials(name) {
    return (name || "").split(/\s+/).slice(0, 2).map(w => w[0] || "").join("").toUpperCase() || "?";
  }

  function getAvatarColor(name) {
    const colors = ["#4a7c5c", "#6b8c78", "#6b9a7e", "#c9a87c", "#d4a5b4", "#4a6b56", "#d4af47", "#8fa89a"];
    let hash = 0;
    for (let i = 0; i < (name || "").length; i++) {
      hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }
    return colors[Math.abs(hash) % colors.length];
  }

  /* ───────────────────────────────────────────
     THEME
     ─────────────────────────────────────────── */
  function setTheme(theme) {
    state.theme = theme;
    document.documentElement.setAttribute("data-theme", theme);
    localStorage.setItem(THEME_STORAGE, theme);
    if (els.themeToggleIcon) {
      els.themeToggleIcon.textContent = theme === "dark" ? "☀️" : "🌙";
    }
  }

  function toggleTheme() {
    setTheme(state.theme === "dark" ? "light" : "dark");
  }

  /* ───────────────────────────────────────────
     PARTICLE SYSTEM
     ─────────────────────────────────────────── */
  let particles = [];
  let particleAnimId = null;

  function initParticles() {
    const canvas = els.particleCanvas;
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    function resize() {
      canvas.width = window.innerWidth;
      canvas.height = window.innerHeight;
    }
    resize();
    window.addEventListener("resize", resize);

    const count = Math.min(60, Math.floor((canvas.width * canvas.height) / 15000));
    particles = [];
    for (let i = 0; i < count; i++) {
      particles.push({
        x: Math.random() * canvas.width,
        y: Math.random() * canvas.height,
        size: Math.random() * 3 + 1,
        speedX: (Math.random() - 0.5) * 0.3,
        speedY: (Math.random() - 0.5) * 0.3 - 0.1,
        opacity: Math.random() * 0.5 + 0.1,
        hue: Math.random() > 0.5 ? (Math.random() > 0.5 ? 45 : 120) : 340, // gold, sage, or blush
      });
    }

    function animate() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      const isLight = state.theme === "light";

      particles.forEach(p => {
        p.x += p.speedX;
        p.y += p.speedY;
        if (p.x < 0) p.x = canvas.width;
        if (p.x > canvas.width) p.x = 0;
        if (p.y < 0) p.y = canvas.height;
        if (p.y > canvas.height) p.y = 0;

        const alpha = isLight ? p.opacity * 0.4 : p.opacity;
        const color = p.hue === 45
          ? `rgba(212, 175, 71, ${alpha})`
          : p.hue === 120
          ? `rgba(107, 140, 120, ${alpha})`
          : `rgba(212, 165, 180, ${alpha})`;

        ctx.beginPath();
        ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
        ctx.fillStyle = color;
        ctx.fill();
      });

      particleAnimId = requestAnimationFrame(animate);
    }
    animate();
  }

  /* ───────────────────────────────────────────
     CONFETTI SYSTEM
     ─────────────────────────────────────────── */
  let confettiPieces = [];
  let confettiAnimId = null;

  function fireConfetti() {
    const canvas = els.confettiCanvas;
    if (!canvas) return;
    canvas.hidden = false;
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    const colors = ["#d4af47", "#c9a87c", "#ff6b6b", "#6b8c78", "#d4a5b4", "#ffffff", "#4a7c5c", "#e8ca7a"];
    confettiPieces = [];
    for (let i = 0; i < 120; i++) {
      confettiPieces.push({
        x: canvas.width / 2 + (Math.random() - 0.5) * 100,
        y: canvas.height / 2,
        size: Math.random() * 8 + 3,
        color: colors[Math.floor(Math.random() * colors.length)],
        speedX: (Math.random() - 0.5) * 12,
        speedY: -Math.random() * 14 - 4,
        rotation: Math.random() * 360,
        rotSpeed: (Math.random() - 0.5) * 10,
        gravity: 0.3,
        opacity: 1,
      });
    }

    let frame = 0;
    function animate() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      frame++;
      let alive = false;

      confettiPieces.forEach(p => {
        p.x += p.speedX;
        p.y += p.speedY;
        p.speedY += p.gravity;
        p.rotation += p.rotSpeed;
        p.speedX *= 0.99;

        if (frame > 30) p.opacity -= 0.012;
        if (p.opacity <= 0) return;

        alive = true;
        ctx.save();
        ctx.translate(p.x, p.y);
        ctx.rotate((p.rotation * Math.PI) / 180);
        ctx.globalAlpha = Math.max(0, p.opacity);
        ctx.fillStyle = p.color;
        ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
        ctx.restore();
      });

      if (alive) {
        confettiAnimId = requestAnimationFrame(animate);
      } else {
        canvas.hidden = true;
        confettiPieces = [];
      }
    }
    animate();
  }

  /* ───────────────────────────────────────────
     LOCK SCREEN — server-verified access
     ─────────────────────────────────────────── */
  function hasReceptionAccessKey() {
    return RECEPTION_KEY.trim().length > 0;
  }

  async function verifyReceptionKey(key) {
    try {
      const url = `${API_BASE}?action=verify-reception-key&key=${encodeURIComponent(key)}`;
      const res = await fetch(url, { headers: { "X-Reception-Key": key } });
      const data = await res.json().catch(() => null);
      return !!(data && data.success);
    } catch (err) {
      return false;
    }
  }

  function showLockScreen(message) {
    document.documentElement.classList.remove("reception-boot");
    if (els.lockOverlay) {
      els.lockOverlay.hidden = false;
      els.lockOverlay.classList.remove("is-verifying");
    }
    if (els.app) els.app.hidden = true;
    if (els.lockError) {
      els.lockError.textContent = message || "";
      els.lockError.hidden = !message;
    }
  }

  function applyAccessLock() {
    if (!hasReceptionAccessKey()) {
      showLockScreen();
      return;
    }

    // A QR visitor should meet the welcome introduction first, not a flash of
    // lock-screen copy while the key is verified in the background.
    if (els.lockOverlay) {
      els.lockOverlay.hidden = false;
      els.lockOverlay.classList.add("is-verifying");
    }
    if (els.app) els.app.hidden = true;
    verifyReceptionKey(RECEPTION_KEY).then((valid) => {
      if (valid) {
        unlockApp();
        return;
      }
      // Invalid or stale key — clear it and require a fresh scan.
      localStorage.removeItem(RECEPTION_KEY_STORAGE);
      showLockScreen("This access link is not valid. Please scan the QR code on your invitation.");
    });
  }

  function unlockApp() {
    if (els.lockOverlay) {
      const wasVerifying = els.lockOverlay.classList.contains("is-verifying");
      els.lockOverlay.classList.remove("is-verifying");
      if (wasVerifying) {
        // Avoid revealing the lock card for even a frame before the welcome.
        els.lockOverlay.hidden = true;
      } else {
        els.lockOverlay.classList.add("is-exiting");
        setTimeout(() => {
          els.lockOverlay.hidden = true;
        }, 500);
      }
    }
    document.title = "Reception | Jason & Rhona Mae";
    // Keep the app completely hidden until the guest leaves the introduction.
    // This prevents the Search panel from showing through the welcome overlay.
    if (!maybeShowWelcome()) revealApp();
  }

  function revealApp() {
    if (!els.app || !els.app.hidden) return;
    document.documentElement.classList.remove("reception-boot");
    els.app.hidden = false;
    els.app.removeAttribute("aria-hidden");
    els.app.classList.add("is-ready", "is-entering");
    setTimeout(() => els.app?.classList.remove("is-entering"), 600);
  }

  /* ───────────────────────────────────────────
     WELCOME SCREEN — the consistent first view after access is verified
     ─────────────────────────────────────────── */
  function maybeShowWelcome() {
    if (!els.welcomeOverlay) return false;

    els.welcomeOverlay.hidden = false;

    // Move focus to the welcome action after its entrance motion settles.
    setTimeout(() => {
      els.welcomeCta?.focus({ preventScroll: true });
    }, 700);
    return true;
  }

  function getVoterToken() {
    let token = localStorage.getItem(VOTER_TOKEN_STORAGE) || "";
    if (!/^[A-Za-z0-9-]{16,128}$/.test(token)) {
      token = window.crypto?.randomUUID
        ? window.crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
      localStorage.setItem(VOTER_TOKEN_STORAGE, token);
    }
    return token;
  }

  function setVoteButtonsDisabled(disabled) {
    els.voteButtons?.forEach((button) => { button.disabled = disabled; });
  }

  function renderVoteResults(data) {
    const bride = Number(data?.bride || 0);
    const groom = Number(data?.groom || 0);
    const total = Number(data?.total || bride + groom);
    const myTeam = data?.myTeam === "groom" ? "groom" : "bride";
    const bridePercent = total > 0 ? (bride / total) * 100 : 50;

    if (els.voteBrideCount) els.voteBrideCount.textContent = String(bride);
    if (els.voteGroomCount) els.voteGroomCount.textContent = String(groom);
    if (els.voteTotal) els.voteTotal.textContent = String(total);
    if (els.voteBrideBar) els.voteBrideBar.style.width = `${bridePercent}%`;
    if (els.voteGroomBar) els.voteGroomBar.style.width = `${100 - bridePercent}%`;
    els.voteButtons?.forEach((button) => {
      button.classList.toggle("is-selected", button.dataset.voteTeam === myTeam);
      button.disabled = true;
    });
    if (els.voteStatus) {
      els.voteStatus.classList.remove("is-error");
      els.voteStatus.textContent = `You're officially on Team ${myTeam === "bride" ? "Bride" : "Groom"}!`;
    }
    if (els.voteResults) els.voteResults.hidden = false;
  }

  function enterSeatSearch() {
    switchTab("search");
    const searchPanel = document.getElementById("panel-search");
    if (searchPanel) searchPanel.scrollTop = 0;
    revealApp();
    if (els.voteOverlay) els.voteOverlay.hidden = true;
    if (els.welcomeOverlay) {
      els.welcomeOverlay.hidden = true;
      els.welcomeOverlay.classList.remove("is-exiting");
    }
    setTimeout(() => els.searchInput?.focus({ preventScroll: true }), 100);
  }

  function showVoteOverlay() {
    if (!els.voteOverlay) {
      enterSeatSearch();
      return;
    }
    els.voteOverlay.hidden = false;
    setVoteButtonsDisabled(false);
    els.voteButtons?.forEach((button) => button.classList.remove("is-selected"));
    if (els.voteResults) els.voteResults.hidden = true;
    if (els.voteStatus) {
      els.voteStatus.textContent = "";
      els.voteStatus.classList.remove("is-error");
    }
    setTimeout(() => els.voteButtons?.[0]?.focus({ preventScroll: true }), 300);
  }

  async function continueFromWelcome() {
    if (!els.welcomeOverlay || els.welcomeCta?.disabled) return;
    if (els.welcomeCta) els.welcomeCta.disabled = true;
    if (els.welcomeHint) els.welcomeHint.textContent = "Checking your team vote…";

    try {
      const data = await apiGet("get-reception-votes", { voter_token: getVoterToken() });
      if (!data?.success) throw new Error(data?.error || "Could not check vote");

      const myTeam = data.data?.myTeam;
      if (myTeam === "bride" || myTeam === "groom") {
        localStorage.setItem(TEAM_VOTE_STORAGE, myTeam);
        enterSeatSearch();
        return;
      }

      // An admin reset removes the server vote, so clear the local marker too.
      localStorage.removeItem(TEAM_VOTE_STORAGE);
      els.welcomeOverlay.classList.add("is-exiting");
      setTimeout(() => {
        els.welcomeOverlay.hidden = true;
        els.welcomeOverlay.classList.remove("is-exiting");
        showVoteOverlay();
      }, 400);
    } catch (error) {
      if (els.welcomeHint) els.welcomeHint.textContent = "Could not load voting. Check your connection and try again.";
      if (els.welcomeCta) els.welcomeCta.disabled = false;
    }
  }

  async function submitTeamVote(team) {
    if (!["bride", "groom"].includes(team)) return;
    setVoteButtonsDisabled(true);
    if (els.voteStatus) {
      els.voteStatus.classList.remove("is-error");
      els.voteStatus.textContent = "Locking in your vote…";
    }

    try {
      const response = await fetch(apiUrl("submit-reception-vote"), {
        method: "POST",
        headers: apiHeaders(true),
        body: JSON.stringify({ voter_token: getVoterToken(), team }),
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.success) {
        throw new Error(data?.error || "Could not save vote");
      }

      const savedTeam = data.data?.myTeam;
      if (savedTeam === "bride" || savedTeam === "groom") {
        localStorage.setItem(TEAM_VOTE_STORAGE, savedTeam);
      }
      renderVoteResults(data.data);
      if (data.data?.created) fireConfetti();
    } catch (error) {
      setVoteButtonsDisabled(false);
      if (els.voteStatus) {
        els.voteStatus.classList.add("is-error");
        els.voteStatus.textContent = error.message || "Could not save vote. Please try again.";
      }
    }
  }

  function initWelcome() {
    els.welcomeCta?.addEventListener("click", continueFromWelcome);
    els.voteButtons?.forEach((button) => {
      button.addEventListener("click", () => submitTeamVote(button.dataset.voteTeam));
    });
    els.voteContinue?.addEventListener("click", enterSeatSearch);

    // Browsers may restore this page from the back/forward cache with the
    // previously open Search tab already painted. Reset it before the page is
    // frozen, so every restored QR visit starts at the introduction too.
    window.addEventListener("pagehide", () => {
      if (els.app) {
        els.app.hidden = true;
        els.app.setAttribute("aria-hidden", "true");
        els.app.classList.remove("is-ready", "is-entering");
      }
      if (els.welcomeOverlay) {
        els.welcomeOverlay.classList.remove("is-exiting");
        els.welcomeOverlay.hidden = false;
      }
      if (els.voteOverlay) els.voteOverlay.hidden = true;
      if (els.welcomeCta) els.welcomeCta.disabled = false;
      if (els.welcomeHint) els.welcomeHint.textContent = "Celebrating, dining & making memories together";
    });
  }

  function handleLockEnter() {
    const inputKey = (els.lockKeyInput?.value || "").trim();
    if (!inputKey) {
      if (els.lockError) {
        els.lockError.textContent = "Please enter your access key.";
        els.lockError.hidden = false;
      }
      return;
    }

    if (els.lockEnterBtn) els.lockEnterBtn.disabled = true;
    verifyReceptionKey(inputKey).then((valid) => {
      if (els.lockEnterBtn) els.lockEnterBtn.disabled = false;
      if (!valid) {
        if (els.lockError) {
          els.lockError.textContent = "Invalid access key. Please scan the QR code on your invitation.";
          els.lockError.hidden = false;
        }
        return;
      }
      localStorage.setItem(RECEPTION_KEY_STORAGE, inputKey);
      // Reload so the app initialises with the now-valid stored key.
      window.location.reload();
    });
  }

  /* ───────────────────────────────────────────
     TABS
     ─────────────────────────────────────────── */
  function updateTabIndicator() {
    if (!els.tabIndicator) return;
    const active = document.querySelector(".reception-tabs__btn.is-active");
    if (!active) return;
    const parent = active.closest(".reception-tabs");
    if (!parent) return;
    const left = active.offsetLeft;
    const width = active.offsetWidth;
    els.tabIndicator.style.left = `${left}px`;
    els.tabIndicator.style.width = `${width}px`;
  }

  function switchTab(tabId) {
    state.activeTab = tabId;
    const hash = tabId === "search" ? "" : tabId;
    if (hash) history.replaceState(null, "", `#${hash}`);
    else history.replaceState(null, "", window.location.pathname + window.location.search);

    els.panels.forEach(panel => {
      const isActive = panel.dataset.panel === tabId;
      panel.classList.toggle("is-active", isActive);
      panel.hidden = !isActive;
    });

    els.tabButtons.forEach(btn => {
      const isActive = btn.dataset.tab === tabId;
      btn.classList.toggle("is-active", isActive);
      if (isActive) btn.setAttribute("aria-current", "page");
      else btn.removeAttribute("aria-current");
    });

    updateTabIndicator();

    if (tabId === "photos" && !state.photos.length) loadPhotos();
  }

  let touchStartX = 0;
  let touchStartY = 0;
  let isSwiping = false;

  function initTabs() {
    els.tabButtons.forEach(btn => {
      btn.addEventListener("click", () => switchTab(btn.dataset.tab));
    });

    const hash = (window.location.hash || "").replace("#", "");
    if (["search", "floor", "menu", "photos", "gifts"].includes(hash)) {
      switchTab(hash);
    }

    updateTabIndicator();
    window.addEventListener("resize", updateTabIndicator);

    // Swipe gestures
    const main = els.receptionMain;
    if (main) {
      main.addEventListener("touchstart", e => {
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
        isSwiping = false;
      }, { passive: true });

      main.addEventListener("touchmove", e => {
        if (isSwiping) return;
        const dx = e.touches[0].clientX - touchStartX;
        const dy = e.touches[0].clientY - touchStartY;
        if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.5) {
          isSwiping = true;
          const tabs = ["search", "floor", "menu", "photos", "gifts"];
          const idx = tabs.indexOf(state.activeTab);
          if (dx < 0 && idx < tabs.length - 1) switchTab(tabs[idx + 1]);
          else if (dx > 0 && idx > 0) switchTab(tabs[idx - 1]);
        }
      }, { passive: true });
    }
  }

  /* ───────────────────────────────────────────
     GUEST SEARCH
     ─────────────────────────────────────────── */
  async function loadGuests() {
    if (!els.searchStatus) return;
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
        els.searchStatus.textContent = "Invalid reception link.";
      } else {
        els.searchStatus.textContent = "Could not load guest list.";
      }
      showToast(err?.message || "Guest list unavailable");
    }
  }

  function showSearchIdleStatus() {
    if (!els.searchStatus) return;
    els.searchStatus.textContent = "Type your name above to find your seat.";
  }

  function clearSearchResults() {
    if (els.searchResults) els.searchResults.innerHTML = "";
  }

  function guestMatches(guest, query) {
    if (query.length < MIN_SEARCH_CHARS) return false;
    const name = normalizeQuery(guest.name);
    const parts = query.split(/\s+/).filter(Boolean);
    return parts.every(part => name.includes(part));
  }

  function formatTableLabel(guest) {
    if (guest.tableNumber == null || guest.tableNumber < 1) {
      return { text: "Not assigned yet", unassigned: true };
    }
    let label = `Table ${guest.tableNumber}`;
    if (guest.seatNumber != null && guest.seatNumber > 0) {
      label += ` · Seat ${guest.seatNumber}`;
    }
    return { text: label, unassigned: false };
  }

  function onSearchInput() {
    if (!state.guestsLoaded) {
      clearSearchResults();
      return;
    }

    const query = normalizeQuery(els.searchInput?.value || "");

    if (!query.length) {
      showSearchIdleStatus();
      clearSearchResults();
      return;
    }

    if (query.length < MIN_SEARCH_CHARS) {
      if (els.searchStatus) els.searchStatus.textContent = `Type at least ${MIN_SEARCH_CHARS} letters of your name.`;
      clearSearchResults();
      return;
    }

    const filtered = state.guests.filter(g => guestMatches(g, query));
    renderSearchResults(filtered);

    if (!els.searchStatus) return;
    if (filtered.length === 0) {
      els.searchStatus.textContent = "No matches — try another spelling";
    } else if (filtered.length === 1) {
      els.searchStatus.textContent = "1 match";
    } else {
      els.searchStatus.textContent = `${filtered.length} matches`;
    }
  }

  function renderSearchResults(list) {
    if (!els.searchResults) return;
    els.searchResults.innerHTML = "";
    els.suggestionPills.innerHTML = "";

    if (!list.length) {
      const empty = document.createElement("li");
      empty.className = "reception-status";
      empty.textContent = "No matching names found.";
      els.searchResults.appendChild(empty);
      return;
    }

    list.forEach((guest, index) => {
      const li = document.createElement("li");
      const table = formatTableLabel(guest);
      const initials = getInitials(guest.name);
      const avatarColor = getAvatarColor(guest.name);

      li.innerHTML = `
        <div class="reception-result-card" style="animation-delay:${Math.min(index, 8) * 50}ms">
          <div class="rec-result-avatar" style="background:${avatarColor}">${initials}</div>
          <div class="reception-result-card__info">
            <p class="reception-result-card__name">${escapeHtml(guest.name)}</p>
            <p class="reception-result-card__table ${table.unassigned ? "is-unassigned" : ""}">${escapeHtml(table.text)}</p>
          </div>
          ${guest.tableNumber ? `
            <div class="reception-result-card__actions">
              <button type="button" class="reception-btn reception-btn--secondary" data-view-table="${guest.tableNumber}">
                View
              </button>
            </div>
          ` : ""}
        </div>
      `;
      els.searchResults.appendChild(li);
    });

    els.searchResults.querySelectorAll("[data-view-table]").forEach(btn => {
      btn.addEventListener("click", () => {
        const tableNum = parseInt(btn.getAttribute("data-view-table"), 10);
        switchTab("floor");
        setTimeout(() => highlightTable(tableNum), 100);
      });
    });
  }

  /* ───────────────────────────────────────────
     FLOOR PLAN — 3D Interactive
     ─────────────────────────────────────────── */
  function initFloorPlan() {
    renderFloorTables();
    initFloorPlanGestures();
    initFloorTilt();
  }

  function renderFloorTables() {
    if (!els.floorTables) return;
    const positions = [
      { n: 1, x: 180, y: 280 }, { n: 2, x: 280, y: 280 },
      { n: 3, x: 380, y: 280 }, { n: 4, x: 480, y: 280 },
      { n: 5, x: 580, y: 280 }, { n: 6, x: 180, y: 360 },
      { n: 7, x: 280, y: 360 }, { n: 8, x: 380, y: 360 },
      { n: 9, x: 480, y: 360 }, { n: 10, x: 580, y: 360 },
    ];

    els.floorTables.innerHTML = positions.map(t =>
      `<button type="button" class="rec-floor-table" data-table="${t.n}" style="left:${t.x}px;top:${t.y}px">
        <span class="rec-floor-table__number">${t.n}</span>
      </button>`
    ).join("");

    els.floorTables.querySelectorAll(".rec-floor-table").forEach(btn => {
      btn.addEventListener("click", () => {
        const tableNum = parseInt(btn.dataset.table, 10);
        showTablePopup(tableNum);
      });
    });
  }

  function showTablePopup(tableNum) {
    if (!els.tablePopup || !els.tablePopupTitle || !els.tablePopupList) return;

    const guestsAtTable = state.guests.filter(g => g.tableNumber === tableNum);
    els.tablePopupTitle.textContent = `Table ${tableNum}`;
    els.tablePopupList.innerHTML = guestsAtTable.length
      ? guestsAtTable.map((g, i) =>
          `<li style="animation-delay:${i * 40}ms">${escapeHtml(g.name)}</li>`
        ).join("")
      : `<li style="color:var(--rec-muted)">No guests assigned yet</li>`;

    els.tablePopup.hidden = false;

    els.tablePopup.querySelectorAll("[data-table-popup-close]").forEach(el => {
      el.addEventListener("click", () => { els.tablePopup.hidden = true; });
    });
  }

  function highlightTable(tableNumber) {
    els.floorTables?.querySelectorAll(".rec-floor-table").forEach(el => {
      const num = parseInt(el.dataset.table, 10);
      el.classList.toggle("is-highlighted", num === tableNumber);
    });
    if (els.floorHint) {
      els.floorHint.hidden = false;
      els.floorHint.textContent = `Table ${tableNumber} highlighted`;
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

    function getPinchDist() {
      const pts = [...pointers.values()];
      if (pts.length < 2) return 0;
      return Math.hypot(pts[1].x - pts[0].x, pts[1].y - pts[0].y);
    }

    viewport.addEventListener("pointerdown", e => {
      viewport.setPointerCapture(e.pointerId);
      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (pointers.size === 1) {
        isDragging = true;
        dragStart = { x: e.clientX, y: e.clientY };
        transformStart = { x: state.floorTransform.x, y: state.floorTransform.y };
        viewport.classList.add("is-dragging");
      } else if (pointers.size === 2) {
        lastPinchDist = getPinchDist();
      }
    }, { passive: true });

    viewport.addEventListener("pointermove", e => {
      if (!pointers.has(e.pointerId)) return;
      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

      if (pointers.size >= 2) {
        const dist = getPinchDist();
        if (lastPinchDist > 0) {
          state.floorTransform.scale = Math.min(4, Math.max(0.5, state.floorTransform.scale * (dist / lastPinchDist)));
          applyFloorTransform();
        }
        lastPinchDist = dist;
        isDragging = false;
      } else if (isDragging) {
        state.floorTransform.x = transformStart.x + (e.clientX - dragStart.x);
        state.floorTransform.y = transformStart.y + (e.clientY - dragStart.y);
        applyFloorTransform();
      }
    }, { passive: true });

    function endPointer(e) {
      pointers.delete(e.pointerId);
      if (pointers.size < 2) lastPinchDist = 0;
      if (pointers.size === 0) { isDragging = false; viewport.classList.remove("is-dragging"); }
    }

    viewport.addEventListener("pointerup", endPointer);
    viewport.addEventListener("pointercancel", endPointer);

    viewport.addEventListener("wheel", e => {
      e.preventDefault();
      const delta = e.deltaY > 0 ? 0.92 : 1.08;
      state.floorTransform.scale = Math.min(4, Math.max(0.5, state.floorTransform.scale * delta));
      applyFloorTransform();
    }, { passive: false });

    stage.style.position = "absolute";
    stage.style.top = "50%";
    stage.style.left = "50%";
    applyFloorTransform();
  }

  function initFloorTilt() {
    const container = els.floorContainer;
    if (!container || !window.DeviceOrientationEvent) return;

    let tiltActive = false;
    let tiltX = 0, tiltY = 0;

    function onDeviceOrientation(e) {
      if (e.gamma == null || e.beta == null) return;
      tiltY = Math.max(-15, Math.min(15, (e.gamma || 0) * 0.5));
      tiltX = Math.max(-10, Math.min(10, (e.beta || 0) * 0.3));
      tiltActive = true;
      updateTilt();
    }

    function updateTilt() {
      if (els.floorViewport) {
        const rx = tiltActive ? tiltX : 5;
        const ry = tiltActive ? tiltY : 0;
        els.floorViewport.style.setProperty("--rec-floor-rotate-x", `${rx}deg`);
        els.floorViewport.style.setProperty("--rec-floor-rotate-y", `${ry}deg`);
      }
    }

    // Try to request permission on iOS
    if (typeof DeviceOrientationEvent !== "undefined" && typeof DeviceOrientationEvent.requestPermission === "function") {
      // Will be activated on user gesture
    } else {
      window.addEventListener("deviceorientation", onDeviceOrientation);
    }

    // Fallback: gentle auto-rotation
    let autoAngle = 0;
    function autoTilt() {
      if (!tiltActive) {
        autoAngle += 0.002;
        const rx = 5 + Math.sin(autoAngle) * 2;
        const ry = Math.cos(autoAngle * 0.7) * 3;
        if (els.floorViewport) {
          els.floorViewport.style.setProperty("--rec-floor-rotate-x", `${rx}deg`);
          els.floorViewport.style.setProperty("--rec-floor-rotate-y", `${ry}deg`);
        }
      }
      requestAnimationFrame(autoTilt);
    }
    autoTilt();

    if (els.floorResetBtn) {
      els.floorResetBtn.addEventListener("click", resetFloorView);
    }
  }

  /* ───────────────────────────────────────────
     MENU
     ─────────────────────────────────────────── */
  async function loadMenu() {
    try {
      const res = await fetch("./data/menu.json");
      if (!res.ok) throw new Error("Menu not found");
      const data = await res.json();
      renderMenu(data);
    } catch {
      if (els.menuRoot) els.menuRoot.innerHTML = "<p class=\"reception-status\">Menu unavailable.</p>";
    }
  }

  let menuData = null;
  let activeMenuFilter = "all";

  function renderMenu(data) {
    menuData = data;
    if (!els.menuRoot) return;

    if (els.menuLegend && data.tagLegend) {
      els.menuLegend.innerHTML = Object.entries(data.tagLegend)
        .map(([code, label]) => `<span class="reception-tag">${escapeHtml(code)}: ${escapeHtml(label)}</span>`)
        .join("");
    }

    // Build filter buttons
    if (els.menuFilters && data.tagLegend) {
      const filtersHtml = ["all", ...Object.keys(data.tagLegend)].map(key => {
        const label = key === "all" ? "All" : key;
        return `<button type="button" class="reception-menu-filter ${key === activeMenuFilter ? "is-active" : ""}" data-filter="${key}">${label}</button>`;
      }).join("");
      els.menuFilters.innerHTML = filtersHtml;

      els.menuFilters.querySelectorAll(".reception-menu-filter").forEach(btn => {
        btn.addEventListener("click", () => {
          activeMenuFilter = btn.dataset.filter;
          renderMenuItems();
        });
      });
    }

    renderMenuItems();
  }

  function renderMenuItems() {
    if (!els.menuRoot || !menuData) return;

    const filteredSections = menuData.sections.map(section => ({
      ...section,
      items: section.items.filter(item => {
        if (activeMenuFilter === "all") return true;
        return (item.tags || []).includes(activeMenuFilter);
      }),
    })).filter(s => s.items.length > 0);

    els.menuRoot.innerHTML = filteredSections.map(section => {
      const items = section.items.map((item, idx) => {
        const tags = (item.tags || []).map(t => `<span class="reception-tag">${escapeHtml(t)}</span>`).join("");
        const isRec = idx === 0 && section.id !== "drinks";
        return `
          <article class="reception-menu-item ${isRec ? "is-recommended" : ""}" style="animation-delay:${idx * 60}ms">
            <p class="reception-menu-item__name">${escapeHtml(item.name)}</p>
            <p class="reception-menu-item__desc">${escapeHtml(item.description || "")}</p>
            ${tags ? `<div class="reception-tags">${tags}</div>` : ""}
          </article>
        `;
      }).join("");
      return `
        <section class="reception-menu-section">
          <h3 class="reception-menu-section__title">${escapeHtml(section.title)}</h3>
          ${items}
        </section>
      `;
    }).join("");

    // Update active filter button
    els.menuFilters?.querySelectorAll(".reception-menu-filter").forEach(btn => {
      btn.classList.toggle("is-active", btn.dataset.filter === activeMenuFilter);
    });
  }

  /* ───────────────────────────────────────────
     PHOTOS
     ─────────────────────────────────────────── */
  async function loadPhotos() {
    if (!els.photoStatus) return;
    els.photoStatus.textContent = "Loading gallery…";
    try {
      const result = await apiGet("get-reception-photos");
      if (result.success && Array.isArray(result.data)) {
        state.photos = result.data;
        updateMaxPhotoId(state.photos);
        renderPhotos();
        startPhotoPolling();
        els.photoStatus.textContent = state.photos.length === 0
          ? "Be the first to share a photo!"
          : `${state.photos.length} photo(s)`;
        return;
      }
      throw new Error(result.error || "Failed");
    } catch {
      els.photoStatus.textContent = "Gallery unavailable.";
    }
  }

  function renderPhotos() {
    if (!els.photoGallery) return;
    if (!state.photos.length) {
      els.photoGallery.innerHTML = "";
      if (els.photoGalleryWrap) els.photoGalleryWrap.hidden = true;
      return;
    }

    els.photoGallery.innerHTML = state.photos.map((p, i) => {
      const tagText = p.uploaderName
        ? (p.tableNumber ? `${escapeHtml(p.uploaderName)} · T${p.tableNumber}` : escapeHtml(p.uploaderName))
        : (p.tableNumber ? `Table ${p.tableNumber}` : '');
      const likes = p.likesCount || 0;

      return `
        <button type="button" class="reception-gallery__item" data-photo-index="${i}" aria-label="View photo ${i + 1}">
          <img src="${escapeHtml(p.url)}" alt="" loading="lazy" decoding="async" onerror="this.onerror=null;this.parentElement.style.display='none';" />
          ${tagText ? `<div class="rec-photo-tag-badge">${tagText}</div>` : ''}
          <div class="rec-photo-overlay">
            <button type="button" class="rec-photo-heart-btn ${isPhotoLiked(p) ? "is-liked" : ""}" data-like-id="${p.id}" aria-label="Like photo">
              ${isPhotoLiked(p) ? "❤️" : "🤍"} <span class="rec-like-count">${likes > 0 ? likes : ''}</span>
            </button>
          </div>
        </button>
      `;
    }).join("");

    if (els.photoGalleryWrap) els.photoGalleryWrap.hidden = false;

    // Render Live Marquee Ticker
    if (els.photoMarqueeTrack && els.photoMarqueeWrap) {
      els.photoMarqueeWrap.hidden = false;
      const itemsHtml = state.photos.map((p, i) => {
        const tag = p.uploaderName || (p.tableNumber ? `Table ${p.tableNumber}` : 'POV');
        return `
          <div class="rec-marquee-item" data-photo-index="${i}">
            <img src="${escapeHtml(p.url)}" alt="" loading="lazy" onerror="this.onerror=null;this.parentElement.style.display='none';" />
            <div class="rec-marquee-tag">${escapeHtml(tag)}</div>
          </div>
        `;
      }).join("");

      // Render all photos in track; duplicate sequence when >1 to allow seamless scrolling
      els.photoMarqueeTrack.innerHTML = state.photos.length > 1 ? itemsHtml + itemsHtml : itemsHtml;

      // Add lightbox click event for marquee items
      els.photoMarqueeTrack.querySelectorAll(".rec-marquee-item").forEach(item => {
        item.addEventListener("click", () => {
          const idx = parseInt(item.dataset.photoIndex, 10);
          if (!Number.isNaN(idx)) openPhotoLightbox(idx);
        });
      });
    }

    // Like buttons
    els.photoGallery.querySelectorAll(".rec-photo-heart-btn").forEach(btn => {
      btn.addEventListener("click", e => {
        e.stopPropagation();
        const photoId = parseInt(btn.dataset.likeId, 10);
        togglePhotoLike(photoId);
      });
    });
  }

  function findPhotoById(photoId) {
    return state.photos.find((photo) => Number(photo.id) === Number(photoId));
  }

  async function togglePhotoLike(photoId) {
    const photo = findPhotoById(photoId);
    if (!photo) return;

    if (!isPhotoLiked(photo)) {
      state.likedPhotos.add(Number(photo.id));
      saveLikedPhotoIds();
      photo.likesCount = (photo.likesCount || 0) + 1;
      renderPhotos();

      const galleryIndex = state.photos.findIndex((item) => Number(item.id) === Number(photo.id));
      if (galleryIndex >= 0) spawnFloatingHeart(galleryIndex);

      try {
        const form = new FormData();
        form.append("action", "like-reception-photo");
        form.append("photo_id", photo.id);

        const res = await fetch(apiUrl("like-reception-photo"), {
          method: "POST",
          headers: apiHeaders(false),
          body: form,
        });
        const json = await res.json();
        if (json.success && json.data) {
          photo.likesCount = json.data.likesCount;
          renderPhotos();
        }
      } catch (e) {
        console.warn("Heart reaction sync error", e);
      }
    }
  }

  function spawnFloatingHeart(index) {
    const item = els.photoGallery?.querySelector(`[data-photo-index="${index}"]`);
    if (!item) return;

    const heart = document.createElement("div");
    heart.className = "rec-floating-heart";
    heart.textContent = "❤️";
    heart.style.left = `${Math.random() * 60 + 20}%`;
    item.appendChild(heart);
    setTimeout(() => heart.remove(), 1200);
  }

  // 10s delta polling for real-time gallery updates
  let photoPollTimer = null;
  function startPhotoPolling() {
    if (photoPollTimer) clearInterval(photoPollTimer);
    photoPollTimer = setInterval(async () => {
      if (document.hidden) return;
      try {
        if (maxPhotoId > 0) {
          const result = await apiGet("get-reception-photos", { since_id: maxPhotoId });
          if (result.success && Array.isArray(result.data) && result.data.length > 0) {
            const existingIds = new Set(state.photos.map((photo) => Number(photo.id)));
            const newPhotos = result.data.filter((photo) => !existingIds.has(Number(photo.id)));
            if (newPhotos.length > 0) {
              state.photos = [...newPhotos, ...state.photos];
              updateMaxPhotoId(newPhotos);
              renderPhotos();
              showToast("New POV photo posted! 📸");
            }
          }
          return;
        }

        const result = await apiGet("get-reception-photos");
        if (result.success && Array.isArray(result.data)) {
          const currentCount = state.photos.length;
          state.photos = result.data;
          updateMaxPhotoId(state.photos);
          renderPhotos();
          if (state.photos.length > currentCount && currentCount > 0) {
            showToast("New POV photo posted! 📸");
          }
        }
      } catch (e) {
        // silent poll fail
      }
    }, PHOTO_POLL_MS);
  }

  /* ───────────────────────────────────────────
     PHOTO LIGHTBOX
     ─────────────────────────────────────────── */
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

    if (els.photoLikeBtn) {
      const isLiked = isPhotoLiked(photo);
      els.photoLikeBtn.classList.toggle("is-liked", isLiked);
    }
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

    setTimeout(() => {
      if (!els.photoLightbox.classList.contains("is-open")) {
        els.photoLightbox.hidden = true;
        if (els.photoLightboxImg) els.photoLightboxImg.removeAttribute("src");
      }
      photoLightboxLastFocus?.focus({ preventScroll: true });
      photoLightboxLastFocus = null;
    }, 200);
  }

  function stepPhotoLightbox(delta) {
    if (state.photos.length <= 1) return;
    photoLightboxIndex = (photoLightboxIndex + delta + state.photos.length) % state.photos.length;
    updatePhotoLightboxView();
  }

  function initPhotoGallery() {
    els.photoGallery?.addEventListener("click", event => {
      const item = event.target.closest("[data-photo-index]");
      if (!item) return;
      const index = parseInt(item.getAttribute("data-photo-index"), 10);
      if (Number.isNaN(index)) return;
      openPhotoLightbox(index);
    });

    if (!els.photoLightbox) return;

    els.photoLightbox.addEventListener("click", event => {
      if (event.target.closest("[data-photo-lightbox-close]")) closePhotoLightbox();
    });

    els.photoLightboxPrev?.addEventListener("click", () => stepPhotoLightbox(-1));
    els.photoLightboxNext?.addEventListener("click", () => stepPhotoLightbox(1));

    els.photoLikeBtn?.addEventListener("click", () => {
      const photo = state.photos[photoLightboxIndex];
      if (photo) togglePhotoLike(photo.id);
      updatePhotoLightboxView();
    });

    els.photoLightbox.addEventListener("keydown", event => {
      if (!els.photoLightbox.classList.contains("is-open")) return;
      if (event.key === "Escape") { event.preventDefault(); closePhotoLightbox(); }
      else if (event.key === "ArrowLeft") { event.preventDefault(); stepPhotoLightbox(-1); }
      else if (event.key === "ArrowRight") { event.preventDefault(); stepPhotoLightbox(1); }
    });
  }

  /* ───────────────────────────────────────────
     PHOTO UPLOAD with drag & drop
     ─────────────────────────────────────────── */
  async function compressPhoto(file) {
    if (file.type === "image/heic" || file.type === "image/heif") {
      return file;
    }

    return new Promise((resolve, reject) => {
      const img = new Image();
      const url = URL.createObjectURL(file);
      img.onload = () => {
        URL.revokeObjectURL(url);
        let { width, height } = img;
        const maxDimension = 1920;

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
            if (!blob) {
              resolve(file);
              return;
            }
            resolve(new File(
              [blob],
              (file.name || "photo").replace(/\.[^/.]+$/, "") + ".webp",
              { type: "image/webp", lastModified: Date.now() }
            ));
          },
          "image/webp",
          0.82
        );
      };
      img.onerror = () => {
        URL.revokeObjectURL(url);
        resolve(file);
      };
      img.src = url;
    });
  }

  async function uploadPhoto(file, nameTag, tableTag) {
    const compressed = await compressPhoto(file);
    const form = new FormData();
    form.append("photo", compressed, compressed.name || "photo.webp");
    if (nameTag) form.append("uploader_name", nameTag);
    if (tableTag) form.append("table_number", tableTag);

    const res = await fetch(apiUrl("upload-reception-photo"), {
      method: "POST",
      headers: apiHeaders(false),
      body: form,
    });

    const raw = await res.text();
    let result = null;

    try {
      result = raw ? JSON.parse(raw) : null;
    } catch {
      throw new Error(res.ok ? 'Invalid server response' : `Upload failed (${res.status})`);
    }

    if (!result || typeof result !== 'object') {
      throw new Error(`Upload failed (${res.status})`);
    }

    if (!res.ok || result.success === false) {
      throw new Error(result.error || `Upload failed (${res.status})`);
    }

    return result;
  }

  function isAllowedPhotoFile(file) {
    const maxBytes = 10 * 1024 * 1024;
    const allowedTypes = [
      "image/jpeg",
      "image/jpg",
      "image/pjpeg",
      "image/png",
      "image/webp",
      "image/heic",
      "image/heif",
    ];
    const allowedExt = ["jpg", "jpeg", "png", "webp", "heic", "heif"];
    const name = file.name || "";
    const ext = name.includes(".") ? name.split(".").pop().toLowerCase() : "";
    const typeOk = !file.type || allowedTypes.includes(file.type);
    const extOk = !name || allowedExt.includes(ext);

    if (!typeOk && !extOk) {
      return { ok: false, error: `${name || "Photo"}: use JPEG, PNG, WebP, or HEIC/HEIF` };
    }

    if (file.size > maxBytes) {
      return { ok: false, error: `${file.name}: must be under 10MB` };
    }

    return { ok: true };
  }

  function initPhotoUpload() {
    let cameraOpening = false;

    const resetFileInput = (input) => {
      if (input) input.value = "";
    };

    const handleSelectedFiles = async (files, sourceInput) => {
      if (!files.length) return;
      await processUploads(files);
      window.setTimeout(() => resetFileInput(sourceInput), 400);
    };

    const bindFileInput = (input, { isCamera = false } = {}) => {
      if (!input) return;
      input.addEventListener("change", async () => {
        cameraOpening = false;
        const files = [...(input.files || [])];
        if (!files.length) return;
        await handleSelectedFiles(files, input);
      });
      if (isCamera) {
        input.addEventListener("cancel", () => {
          cameraOpening = false;
        });
      }
    };

    bindFileInput(els.photoUploadCameraInput, { isCamera: true });
    bindFileInput(els.photoUploadGalleryInput);

    els.photoUploadCameraBtn?.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (cameraOpening || els.photoUploadCameraBtn?.disabled) return;
      if (!els.photoUploadCameraInput) return;
      cameraOpening = true;
      els.photoUploadCameraInput.click();
      window.setTimeout(() => {
        cameraOpening = false;
      }, 1500);
    });

    els.photoUploadActions?.addEventListener("click", (event) => {
      event.stopPropagation();
    });

    els.uploadZone?.addEventListener("click", (event) => {
      if (event.target.closest("#photo-upload-actions") || event.target.closest(".rec-upload-tags-wrap")) {
        return;
      }
      els.photoUploadGalleryInput?.click();
    });

    const zone = els.uploadZone;
    if (!zone) return;

    zone.addEventListener('dragover', (event) => {
      event.preventDefault();
      zone.classList.add('is-dragover');
    });

    zone.addEventListener('dragleave', (event) => {
      event.preventDefault();
      zone.classList.remove('is-dragover');
    });

    zone.addEventListener('drop', async (event) => {
      event.preventDefault();
      zone.classList.remove('is-dragover');
      const files = [...(event.dataTransfer?.files || [])].filter((file) => file.type.startsWith('image/'));
      if (!files.length) return;
      await handleSelectedFiles(files, null);
    });
  }

  async function processUploads(files) {
    const valid = [];
    const errors = [];

    files.forEach((file) => {
      const check = isAllowedPhotoFile(file);
      if (check.ok) {
        valid.push(file);
      } else {
        errors.push(check.error);
      }
    });

    if (errors.length) {
      showToast(errors[0]);
    }

    if (!valid.length) return;

    const nameTag = document.getElementById("photo-tag-name")?.value || "";
    const tableTag = document.getElementById("photo-tag-table")?.value || "";
    const cameraBtn = els.photoUploadCameraBtn;
    const cameraBtnLabel = cameraBtn?.querySelector(".rec-upload-btn-label");

    if (cameraBtn) cameraBtn.disabled = true;
    if (cameraBtnLabel) cameraBtnLabel.textContent = "Uploading…";

    if (cameraBtn) {
      cameraBtn.disabled = true;
      cameraBtn.textContent = "Uploading…";
    }

    try {
    const total = valid.length;
    let uploaded = 0;
    let failed = 0;

    for (let index = 0; index < total; index += 1) {
      if (els.photoStatus) {
        els.photoStatus.textContent = total > 1 ? `Compressing & uploading ${index + 1} of ${total}…` : 'Uploading…';
      }

      try {
        const result = await uploadPhoto(valid[index], nameTag, tableTag);
        if (result?.success && result.data) {
          state.photos.unshift(result.data);
          updateMaxPhotoId([result.data]);
          uploaded += 1;
        } else {
          failed += 1;
          throw new Error(result?.error || 'Upload failed');
        }
      } catch (error) {
        failed += 1;
        if (uploaded === 0 && index === 0) {
          showToast(error?.message || 'Upload failed');
          if (els.photoStatus) els.photoStatus.textContent = 'Upload failed — try again';
          return;
        }
      }
    }

    if (uploaded > 0) {
      try {
        const fresh = await apiGet("get-reception-photos");
        if (fresh.success && Array.isArray(fresh.data)) {
          state.photos = fresh.data;
          updateMaxPhotoId(state.photos);
        }
      } catch (e) {
        // fallback to state.photos
      }
      renderPhotos();
      if (els.photoStatus) els.photoStatus.textContent = `${state.photos.length} photo(s)`;
      showToast(uploaded === 1 ? 'POV shared! 🎉' : `${uploaded} POVs shared! 🎉`);
    }

    if (failed > 0 && uploaded > 0) {
      showToast(`${failed} photo(s) could not be uploaded`);
    }
    } finally {
      if (cameraBtn) cameraBtn.disabled = false;
      if (cameraBtnLabel) cameraBtnLabel.textContent = "Snap your POV";
    }
  }

  /* ───────────────────────────────────────────
     GIFT BOX
     ─────────────────────────────────────────── */
  function initGiftBox() {
    if (!els.giftBox) return;
    els.giftBox.addEventListener("click", () => {
      if (state.giftBoxOpened) return;
      state.giftBoxOpened = true;
      els.giftBox.classList.add("is-open");
      if (els.giftBoxCta) els.giftBoxCta.textContent = "🎉 Thank you!";
      setTimeout(() => {
        if (els.giftDetails) els.giftDetails.hidden = false;
        fireConfetti();
      }, 600);
    });
  }

  /* ───────────────────────────────────────────
     INIT
     ─────────────────────────────────────────── */
  function init() {
    cacheEls();
    applyAccessLock();
    setTheme(state.theme);

    if (!hasReceptionAccessKey()) {
      initParticles();
      initLockScreen();
      return;
    }

    initParticles();
    initTabs();
    initFloorPlan();
    initPhotoGallery();
    initPhotoUpload();
    initGiftBox();
    initWelcome();
    loadGuests();
    loadMenu();

    els.searchInput?.addEventListener("input", onSearchInput);
    els.themeToggle?.addEventListener("click", toggleTheme);
  }

  function initLockScreen() {
    els.lockEnterBtn?.addEventListener("click", handleLockEnter);
    els.lockKeyInput?.addEventListener("keydown", e => {
      if (e.key === "Enter") handleLockEnter();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
