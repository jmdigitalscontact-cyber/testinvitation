(function () {
  'use strict';

  /* ===== Browser asset caching helpers ===== */
  function preloadCriticalAssets() {
    var assets = [
      'css/styles.css',
      'js/script.js',
      'audio/ambient.mp3'
    ];

    assets.forEach(function (asset) {
      var link = document.createElement('link');
      link.rel = 'preload';
      link.as = asset.endsWith('.css') ? 'style' : asset.endsWith('.js') ? 'script' : 'audio';
      link.href = asset;
      document.head.appendChild(link);
    });
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;

    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () {
        console.warn('Service worker registration failed');
      });
    });
  }

  function setupLazyImages() {
    if (!('IntersectionObserver' in window)) return;

    var lazyImages = Array.from(document.querySelectorAll('img'));
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var img = entry.target;
        var src = img.getAttribute('data-src');
        if (src) {
          img.setAttribute('src', src);
          img.removeAttribute('data-src');
          img.classList.add('is-loaded');
        }
        observer.unobserve(img);
      });
    }, { rootMargin: '180px 0px' });

    lazyImages.forEach(function (img) {
      var src = img.getAttribute('src');
      if (!src || img.getAttribute('data-src') || img.getAttribute('loading') === 'eager' || img.closest('.banner')) return;
      if (img.getAttribute('loading') === 'lazy' || img.closest('.gallery-item') || img.closest('.photo-card') || img.closest('.page-card') || img.closest('.lightbox-thumbs')) {
        img.setAttribute('data-src', src);
        img.removeAttribute('src');
        img.setAttribute('loading', 'lazy');
        img.setAttribute('decoding', 'async');
        observer.observe(img);
      }
    });
  }

  /* ===== Page Init ===== */
  window.addEventListener('load', function () {
    preloadCriticalAssets();
    registerServiceWorker();
    setupLazyImages();
  });

  /* ===== Countdown ===== */
  var WEDDING_DATE = new Date('2026-11-11T14:00:00+08:00').getTime();
  var els = {
    days: document.getElementById('days'),
    hours: document.getElementById('hours'),
    minutes: document.getElementById('minutes'),
    seconds: document.getElementById('seconds')
  };
  var lastSecond = null;

  function pad(n) { return String(n).padStart(2, '0'); }

  function updateCountdown() {
    var gap = Math.max(0, WEDDING_DATE - Date.now());
    if (els.days) els.days.textContent = pad(Math.floor(gap / 86400000));
    if (els.hours) els.hours.textContent = pad(Math.floor((gap % 86400000) / 3600000));
    if (els.minutes) els.minutes.textContent = pad(Math.floor((gap % 3600000) / 60000));
    if (els.seconds) {
      var s = Math.floor((gap % 60000) / 1000);
      els.seconds.textContent = pad(s);
      if (lastSecond !== null && s !== lastSecond) {
        var cell = els.seconds.closest('.countdown-cell');
        if (cell) { cell.style.transition = 'transform 0.1s'; cell.style.transform = 'scale(1.04)'; setTimeout(function () { cell.style.transform = 'scale(1)'; }, 100); }
      }
      lastSecond = s;
    }
  }

  if (els.days || els.hours || els.minutes || els.seconds) {
    updateCountdown();
    setInterval(updateCountdown, 1000);
  }

  /* ===== Header Scroll Effect ===== */
  var header = document.querySelector('header');
  if (header) {
    window.addEventListener('scroll', function () {
      header.classList.toggle('scrolled', window.scrollY > 80);
    });
  }

  /* ===== Mobile Menu Toggle ===== */
  var menuBtn = document.querySelector('.menu-toggle');
  var nav = document.querySelector('.nav');

  if (menuBtn && nav) {
    menuBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      nav.classList.toggle('open');
      menuBtn.setAttribute('aria-expanded', nav.classList.contains('open'));
    });

    document.querySelectorAll('.nav a').forEach(function (link) {
      link.addEventListener('click', function () {
        nav.classList.remove('open');
        if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
      });
    });

    document.addEventListener('click', function (e) {
      if (window.innerWidth < 768 && nav.classList.contains('open') && !nav.contains(e.target) && !menuBtn.contains(e.target)) {
        nav.classList.remove('open');
        menuBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* ===== Section Navigation ===== */
  var navButtons = Array.from(document.querySelectorAll('.nav-btn'));

  function setActiveNav(targetId) {
    if (!targetId) return;
    navButtons.forEach(function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-target') === targetId);
    });
  }

  navButtons.forEach(function (navBtn) {
    navBtn.addEventListener('click', function (e) {
      e.preventDefault();
      var target = navBtn.getAttribute('data-target');
      if (!target) return;
      var section = document.getElementById(target);
      if (section) section.scrollIntoView({ behavior: 'smooth' });
      setActiveNav(target);
    });
  });

  var navSections = navButtons
    .map(function (btn) { return btn.getAttribute('data-target'); })
    .filter(Boolean)
    .map(function (id) { return document.getElementById(id); })
    .filter(Boolean);

  function initHeroScrollEffect() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var heroContent = document.querySelector('.hero-content');
    var scrollRing = document.querySelector('.scroll-ring');
    var banner = document.querySelector('.banner');
    if (!heroContent || !banner) return;

    var ticking = false;
    function updateHero() {
      var top = window.scrollY || 0;
      var limit = Math.max(1, banner.offsetHeight || window.innerHeight);
      var progress = Math.min(1, Math.max(0, top / limit));

      heroContent.style.transform = 'translateY(' + (progress * 38) + 'px) scale(' + (1 - (progress * 0.03)) + ')';
      heroContent.style.opacity = String(1 - (progress * 0.38));
      if (scrollRing) {
        scrollRing.style.transform = 'translateX(-50%) translateY(' + (progress * 24) + 'px)';
        scrollRing.style.opacity = String(1 - (progress * 0.45));
      }
      ticking = false;
    }

    function onScroll() {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(updateHero);
    }

    updateHero();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
  }

  function applyRevealStagger() {
    var revealItems = Array.from(document.querySelectorAll('.reveal'));
    revealItems.forEach(function (el, index) {
      el.style.setProperty('--reveal-delay', ((index % 6) * 70) + 'ms');
    });
  }

  initHeroScrollEffect();
  applyRevealStagger();

  if ('IntersectionObserver' in window && navSections.length) {
    var currentSectionId = navSections[0].id;

    var navObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        currentSectionId = entry.target.id;
      });
      setActiveNav(currentSectionId);
    }, {
      root: null,
      rootMargin: '-40% 0px -50% 0px',
      threshold: 0.01
    });

    navSections.forEach(function (section) { navObserver.observe(section); });

    var initialHashId = window.location.hash ? window.location.hash.replace('#', '') : '';
    if (initialHashId && document.getElementById(initialHashId)) {
      setActiveNav(initialHashId);
    } else {
      setActiveNav(currentSectionId);
    }
  }

  /* ===== Scroll Reveal ===== */
  var revealObserver = new IntersectionObserver(function (entries, observer) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('visible');
      observer.unobserve(entry.target);
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -20% 0px' });

  document.querySelectorAll('.reveal').forEach(function (el) { revealObserver.observe(el); });

  function getInvitationToken() {
    var params = new URLSearchParams(window.location.search);
    return params.get('invitation_id') || params.get('invite') || params.get('token') || '';
  }

  function getInvitationMode() {
    var params = new URLSearchParams(window.location.search);
    var invitationId = params.get('invitation_id') || params.get('invite') || '';
    var token = params.get('token') || '';
    return {
      invitationId: invitationId,
      token: token,
      source: invitationId ? 'invite' : (token ? 'token' : '')
    };
  }

  function setInviteLoading(loading, message) {
    var loadingState = document.getElementById('rsvp-loading-state');
    var form = document.getElementById('rsvp-form');
    if (loadingState) {
      loadingState.hidden = !loading;
      if (message) loadingState.textContent = message;
    }
    if (form) {
      form.hidden = loading;
    }
  }

  function renderInvitedParty(invitation) {
    var title = document.getElementById('rsvp-invite-title');
    var meta = document.getElementById('rsvp-invite-meta');
    var list = document.getElementById('invited-party-list');
    var invitationInput = document.getElementById('invitation-id');
    var tokenInput = document.getElementById('invitation-token');
    var invitedNames = Array.isArray(invitation.invited_guest_names) && invitation.invited_guest_names.length
      ? invitation.invited_guest_names
      : [invitation.guest_name || 'Guest'];

    if (title) {
      title.textContent = 'Welcome, ' + (invitation.guest_name || 'Guest');
    }
    if (meta) {
      meta.textContent = 'Please tick each name that will attend. Your invite includes ' + invitedNames.length + ' name' + (invitedNames.length === 1 ? '' : 's') + '.';
    }
    if (invitationInput) invitationInput.value = invitation.invitation_id || '';
    if (tokenInput) tokenInput.value = invitation.token || invitation.invitation_id || '';

    if (!list) return;
    list.innerHTML = '';

    invitedNames.forEach(function (name, index) {
      var row = document.createElement('label');
      row.className = 'invite-party-row';
      row.setAttribute('data-attendee-row', String(index));
      var person = document.createElement('span');
      person.className = 'invite-party-person';

      var personName = document.createElement('span');
      personName.className = 'invite-party-name';
      personName.textContent = name;

      var personNote = document.createElement('span');
      personNote.className = 'invite-party-note';
      personNote.textContent = 'Tick if attending';

      var checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.className = 'invite-party-toggle';
      checkbox.name = 'attendee-going';
      checkbox.checked = true;

      person.appendChild(personName);
      person.appendChild(personNote);
      row.appendChild(person);
      row.appendChild(checkbox);

      checkbox.addEventListener('change', function () {
        row.classList.toggle('is-checked', checkbox.checked);
      });
      row.classList.add('is-checked');
      list.appendChild(row);
    });

    setInviteLoading(false);
    var form = document.getElementById('rsvp-form');
    if (form) form.hidden = false;
  }

  function loadInvitationDetails() {
    var mode = getInvitationMode();
    var lockedState = document.getElementById('rsvp-locked-state');
    var formState = document.getElementById('rsvp-form-state');
    if (!lockedState || !formState) return;

    if (!mode.invitationId && !mode.token) {
      lockedState.hidden = false;
      formState.hidden = true;
      setInviteLoading(true, 'RSVP is for invited guests only. Open the personal link or scan the QR code from your invitation to respond.');
      return;
    }

    lockedState.hidden = true;
    formState.hidden = false;
    setInviteLoading(true, 'Loading invitation details...');

    var endpoint;
    if (mode.source === 'invite') {
      endpoint = 'rsvp/api.php?action=verify-invitation-qr&invitation_id=' + encodeURIComponent(mode.invitationId);
    } else {
      endpoint = 'rsvp/api.php?action=get-invitation-details&token=' + encodeURIComponent(mode.token);
    }

    fetch(endpoint, { cache: 'no-cache' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data || !data.success || !data.data) {
          throw new Error(data && data.error ? data.error : 'Unable to load invitation details');
        }
        renderInvitedParty(data.data);
      })
      .catch(function (error) {
        setInviteLoading(true, error.message || 'Unable to load invitation details.');
        if (lockedState) lockedState.hidden = false;
        if (formState) formState.hidden = true;
      });
  }

  function applyRSVPGating() {
    var mode = getInvitationMode();
    var lockedState = document.getElementById('rsvp-locked-state');
    var formState = document.getElementById('rsvp-form-state');

    if (!lockedState || !formState) return;

    if (mode.invitationId || mode.token) {
      lockedState.hidden = true;
      formState.hidden = false;
    } else {
      lockedState.hidden = false;
      formState.hidden = true;
    }
  }

  applyRSVPGating();
  loadInvitationDetails();

  /* ===== RSVP Form ===== */
  var form = document.getElementById('rsvp-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fb = document.getElementById('rsvp-feedback');
      var btn = form.querySelector('.btn-primary');
      var token = document.getElementById('invitation-token').value.trim();
      var dietary = document.getElementById('dietary').value.trim();
      var attendeeRows = Array.from(document.querySelectorAll('.invite-party-row'));

      if (!token) {
        showFeedback(fb, 'Your invitation could not be loaded. Please open the QR link again.', 'error');
        return;
      }

      var attendees = attendeeRows.map(function (row) {
        var nameEl = row.querySelector('.invite-party-name');
        var checkbox = row.querySelector('input[type="checkbox"]');
        return {
          name: nameEl ? nameEl.textContent.trim() : '',
          attending: !!(checkbox && checkbox.checked)
        };
      }).filter(function (attendee) { return attendee.name; });

      var selectedCount = attendees.filter(function (attendee) { return attendee.attending; }).length;
      var attending = selectedCount > 0 ? 'yes' : 'no';

      if (attendees.length === 0) {
        showFeedback(fb, 'We could not find any names on this invitation.', 'error');
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Submitting...';

      fetch('rsvp/api.php?action=submit-rsvp', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          token: token,
          attending: attending,
          attendee_count: selectedCount,
          attendees: attendees,
          dietary_restrictions: dietary,
          special_notes: ''
        })
      })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data || !data.success) {
          throw new Error(data && data.error ? data.error : 'RSVP submission failed');
        }
        showFeedback(fb, 'Thank you! Your RSVP has been received.', 'success');
        btn.textContent = 'RSVP Received';
      })
      .catch(function (error) {
        btn.disabled = false;
        btn.textContent = 'Submit RSVP';
        showFeedback(fb, error.message || 'RSVP submission failed.', 'error');
      });
    });
  }

  function showFeedback(el, msg, type) {
    if (!el) return;
    el.textContent = msg;
    el.className = 'feedback show ' + type;
  }

  /* ===== TRP preload and gallery observer (always available) ===== */
  var trpList = [];
  var trpLoadedCount = 0;
  var galleryMarquees = Array.from(document.querySelectorAll('.gallery-marquee'));

  function updateGalleryMotion() {
    if (!galleryMarquees.length) return;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var shouldAnimate = !reduceMotion;
    galleryMarquees.forEach(function (marquee) {
      marquee.classList.toggle('reduced-motion', !shouldAnimate);
      marquee.classList.toggle('is-paused', !shouldAnimate);
    });
  }

  function loadTrpList() {
    if (trpList && trpList.length) return Promise.resolve(trpList);
    return fetch('js/trp-list.json', { cache: 'no-cache' })
      .then(function (r) { return r.json(); })
      .then(function (list) { trpList = Array.isArray(list) ? list : []; return trpList; })
      .catch(function (err) { console.warn('TRP list load failed', err); trpList = []; return trpList; });
  }

  function preloadNames(names) {
    if (!names || !names.length) return;
    names.forEach(function (name) {
      var img = new Image();
      img.decoding = 'async';
      img.src = 'images/' + name;
      img.onload = function () { trpLoadedCount++; };
      img.onerror = function () { trpLoadedCount++; };
    });
  }

  function startInitialPreload() {
    loadTrpList().then(function (list) {
      if (!list.length) return;
      var first = list.slice(0, 2);
      preloadNames(first);
    });
  }

  function setupGalleryObserver() {
    var gallery = document.getElementById('gallery');
    if (!gallery) return;
    var observed = false;
    var obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting && !observed) {
          observed = true;
          galleryMarquees.forEach(function (marquee) { marquee.classList.remove('is-paused'); });
          loadTrpList().then(function (list) {
            if (list.length <= 2) return;
            var idx = 2;
            function batch() {
              var batchItems = list.slice(idx, idx + 2);
              if (batchItems.length) {
                preloadNames(batchItems);
                idx += 2;
                setTimeout(batch, 800);
              }
            }
            batch();
          });
          obs.disconnect();
        }
      });
    }, { root: null, threshold: 0.15 });
    obs.observe(gallery);
  }

  /* ===== Randomize marquee photo order on each page load ===== */
  function shuffleMarqueeTracks() {
    var photoPools = [
      [
        { src: 'images/TRP-2.webp',   alt: 'Outfit look 1' },
        { src: 'images/TRP-66.webp',  alt: 'Outfit look 2' },
        { src: 'images/TRP-130.webp', alt: 'Outfit look 3' },
        { src: 'images/TRP-209.webp', alt: 'Outfit look 4' },
        { src: 'images/TRP-67.webp',  alt: 'Location 1' },
        { src: 'images/TRP-184.webp', alt: 'Location 2' },
        { src: 'images/TRP-6.webp',   alt: 'Location 3' },
        { src: 'images/TRP-208.webp', alt: 'Location 4' }
      ],
      [
        { src: 'images/TRP-8.webp',   alt: 'Outfit look 1' },
        { src: 'images/TRP-76.webp',  alt: 'Outfit look 2' },
        { src: 'images/TRP-134.webp', alt: 'Outfit look 3' },
        { src: 'images/TRP-201.webp', alt: 'Outfit look 4' },
        { src: 'images/TRP-73.webp',  alt: 'Location 1' },
        { src: 'images/TRP-177.webp', alt: 'Location 2' },
        { src: 'images/TRP-3.webp',   alt: 'Location 3' },
        { src: 'images/TRP-203.webp', alt: 'Location 4' }
      ]
    ];

    function shuffle(arr) {
      var a = arr.slice();
      for (var i = a.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var tmp = a[i]; a[i] = a[j]; a[j] = tmp;
      }
      return a;
    }

    function buildTrackHTML(items) {
      var doubled = items.concat(items); // duplicate for seamless loop
      return doubled.map(function (p) {
        return '<div class="gallery-item"><img src="' + p.src + '" alt="' + p.alt + '" loading="lazy"></div>';
      }).join('');
    }

    galleryMarquees.forEach(function (marquee, idx) {
      var track = marquee.querySelector('.gallery-track');
      var pool = photoPools[idx] || photoPools[0];
      if (track) track.innerHTML = buildTrackHTML(shuffle(pool));
    });
  }

  window.addEventListener('load', function () {
    // Shuffle photos in each marquee row independently on every page load
    shuffleMarqueeTracks();
    // Randomly assign which marquee row scrolls in reverse on each page load
    if (galleryMarquees.length >= 2) {
      var reverseIdx = Math.random() < 0.5 ? 0 : 1;
      galleryMarquees[reverseIdx].classList.add('reverse');
    }
    updateGalleryMotion();
    galleryMarquees.forEach(function (marquee) { marquee.classList.add('is-paused'); });
    setupGalleryObserver();
  });

  window.addEventListener('resize', updateGalleryMotion);
  document.addEventListener('visibilitychange', function () {
    if (!galleryMarquees.length) return;
    if (document.hidden) {
      galleryMarquees.forEach(function (marquee) { marquee.classList.add('is-paused'); });
    } else {
      updateGalleryMotion();
    }
  });

  var reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (reducedMotionQuery.addEventListener) {
    reducedMotionQuery.addEventListener('change', updateGalleryMotion);
  }

  /* ===== Audio Autoplay ===== */
  var audioElement = document.getElementById('wedding-audio');
  if (audioElement) {
    if (window.localStorage && window.localStorage.getItem('wedding-audio-mode') === 'popup') {
      audioElement.pause();
      audioElement.removeAttribute('autoplay');
      audioElement.currentTime = 0;
    } else {
      var audioStateKey = 'wedding-audio-state';
      audioElement.volume = 0.65;
      audioElement.loop = true;
      audioElement.autoplay = true;
      audioElement.playsInline = true;

      function readAudioState() {
        try {
          return JSON.parse(sessionStorage.getItem(audioStateKey) || '{}');
        } catch (err) {
          return {};
        }
      }

      function saveAudioState() {
        try {
          sessionStorage.setItem(audioStateKey, JSON.stringify({
            currentTime: audioElement.currentTime || 0,
            playing: !audioElement.paused
          }));
        } catch (err) {
          return null;
        }
      }

      function attemptAudioPlay() {
        audioElement.play().catch(function () {
          return null;
        });
      }

      var savedState = readAudioState();
      if (typeof savedState.currentTime === 'number' && !Number.isNaN(savedState.currentTime)) {
        audioElement.currentTime = savedState.currentTime;
      }

      window.addEventListener('load', attemptAudioPlay);
      audioElement.addEventListener('timeupdate', saveAudioState);
      audioElement.addEventListener('play', saveAudioState);
      audioElement.addEventListener('pause', saveAudioState);
      window.addEventListener('pagehide', saveAudioState);

      // (Preload functions are defined at top-level so they're always available)

      // (Gallery observer is defined at top-level)

      ['click', 'touchstart', 'keydown'].forEach(function (eventName) {
        window.addEventListener(eventName, function userGestureHandler() {
          if (!audioElement.paused) {
            window.removeEventListener('click', userGestureHandler);
            window.removeEventListener('touchstart', userGestureHandler);
            window.removeEventListener('keydown', userGestureHandler);
            return;
          }
          attemptAudioPlay();
        }, { once: true });
      });
    }
  }

  function openPersistentAudioPlayer() {
    try {
      if (window.localStorage) {
        window.localStorage.setItem('wedding-audio-mode', 'popup');
      }
      var existingAudio = document.getElementById('wedding-audio');
      var playerWindow = window.open('player.html', 'wedding-audio-player', 'popup,width=320,height=180,left=20,top=20');
      if (!playerWindow) return false;

      if (existingAudio) {
        setTimeout(function () {
          existingAudio.pause();
        }, 120);
      }

      return true;
    } catch (err) {
      return false;
    }
  }

  /* ===== Lightbox for Gallery (with navigation + thumbnails) ===== */
  var lightbox = document.getElementById('lightbox');
  if (lightbox) {
    var viewImg = lightbox.querySelector('.lightbox-view img');
    var lightboxClose = lightbox.querySelector('.lightbox-close');
    var prevBtn = lightbox.querySelector('.lightbox-prev');
    var nextBtn = lightbox.querySelector('.lightbox-next');
    var thumbsWrap = lightbox.querySelector('.lightbox-thumbs');
    var attireImages = Array.from(document.querySelectorAll('#attire .page-card-media img'));
    var galleryOpenBtn = document.getElementById('open-gallery-btn');
    var currentIndex = 0;
    var standaloneImageMode = false;
    var thumbBatchSize = 20;
    var thumbCursor = 0;
    var renderedAllThumbs = false;
    var lightboxImageCache = {};

    function setStandaloneImageMode(enabled) {
      standaloneImageMode = !!enabled;
      lightbox.classList.toggle('is-standalone', standaloneImageMode);
    }

    function renderThumbBatch() {
      if (!thumbsWrap || !trpList.length || renderedAllThumbs) return;
      var end = Math.min(trpList.length, thumbCursor + thumbBatchSize);
      for (var i = thumbCursor; i < end; i++) {
        var name = trpList[i];
        var t = document.createElement('button');
        t.type = 'button';
        t.className = 'thumb';
        t.setAttribute('data-index', i);
        var img = document.createElement('img');
        img.loading = 'lazy';
        img.alt = '';
        img.src = 'images/' + name;
        t.appendChild(img);
        t.addEventListener('click', function () { openLightboxAt(Number(this.getAttribute('data-index'))); });
        thumbsWrap.appendChild(t);
      }
      thumbCursor = end;
      renderedAllThumbs = thumbCursor >= trpList.length;
    }

    function maybeRenderMoreThumbs() {
      if (!thumbsWrap || !trpList.length || renderedAllThumbs) return;
      var threshold = 140;
      var nearBottom = thumbsWrap.scrollHeight - (thumbsWrap.scrollTop + thumbsWrap.clientHeight) < threshold;
      if (nearBottom) renderThumbBatch();
    }

    function updateActiveThumb() {
      if (!thumbsWrap) return;
      var children = thumbsWrap.children;
      for (var i = 0; i < children.length; i++) {
        children[i].classList.toggle('active', Number(children[i].getAttribute('data-index')) === currentIndex);
      }
      var active = thumbsWrap.querySelector('.thumb.active');
      if (active) active.scrollIntoView({ behavior: 'smooth', inline: 'center' });
    }

    function preloadLightboxImage(src, onReady) {
      if (!src) return;
      if (lightboxImageCache[src]) {
        if (lightboxImageCache[src].ready) {
          if (onReady) onReady(lightboxImageCache[src].img);
        } else if (onReady) {
          lightboxImageCache[src].waiters.push(onReady);
        }
        return;
      }

      var img = new Image();
      img.decoding = 'async';
      img.onload = function () {
        lightboxImageCache[src].ready = true;
        lightboxImageCache[src].waiters.forEach(function (ready) { ready(img); });
        lightboxImageCache[src].waiters = [];
      };
      img.onerror = function () {
        lightboxImageCache[src].ready = true;
        lightboxImageCache[src].waiters.forEach(function (ready) { ready(img); });
        lightboxImageCache[src].waiters = [];
      };
      lightboxImageCache[src] = { img: img, ready: false, waiters: [] };
      img.src = src;
      if (onReady) lightboxImageCache[src].waiters.push(onReady);
    }

    function showLightboxImage(src, alt) {
      if (!src) return;
      viewImg.style.opacity = 0;
      preloadLightboxImage(src, function () {
        viewImg.src = src;
        viewImg.alt = alt || '';
        viewImg.style.opacity = 1;
      });
    }

    function openLightboxAt(index) {
      setStandaloneImageMode(false);
      currentIndex = (index + trpList.length) % trpList.length;
      var src = 'images/' + trpList[currentIndex];
      showLightboxImage(src, trpList[currentIndex] || '');
      lightbox.classList.add('open');
      document.body.style.overflow = 'hidden';
      updateActiveThumb();

      var nextIndex = (currentIndex + 1) % trpList.length;
      var prevIndex = (currentIndex - 1 + trpList.length) % trpList.length;
      preloadLightboxImage('images/' + trpList[nextIndex]);
      preloadLightboxImage('images/' + trpList[prevIndex]);
    }

    // Fallback: open lightbox directly from a filename when trpList isn't available
    function openLightboxByName(name) {
      if (!name) return;
      setStandaloneImageMode(false);
      var src = name.indexOf('/') === -1 ? ('images/' + name) : name;
      showLightboxImage(src, name || '');
      lightbox.classList.add('open');
      document.body.style.overflow = 'hidden';
      // prefetch neighbors if in list
      var idx = trpList.indexOf(name);
      if (idx !== -1) {
        preloadLightboxImage('images/' + trpList[(idx + 1) % trpList.length]);
        preloadLightboxImage('images/' + trpList[(idx - 1 + trpList.length) % trpList.length]);
      }
      // clear active thumb when trpList absent
      if (thumbsWrap) { var ch = thumbsWrap.children; for (var i = 0; i < ch.length; i++) ch[i].classList.remove('active'); }
    }

    function openSingleLightboxImage(src, alt) {
      if (!src) return;
      setStandaloneImageMode(true);
      showLightboxImage(src, alt || '');
      lightbox.classList.add('open');
      document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
      lightbox.classList.remove('open');
      setStandaloneImageMode(false);
      document.body.style.overflow = '';
      if (viewImg) {
        viewImg.src = '';
        viewImg.alt = '';
      }
    }

    function showNext() { openLightboxAt(currentIndex + 1); }
    function showPrev() { openLightboxAt(currentIndex - 1); }

    function getFirstVisibleGalleryImageName() {
      var firstImg = document.querySelector('.gallery-marquee .gallery-item img');
      if (!firstImg) return '';
      var src = firstImg.getAttribute('src') || firstImg.getAttribute('data-src') || '';
      if (!src) return '';
      var cleanSrc = src.split('?')[0].split('#')[0];
      var parts = cleanSrc.split('/');
      return parts.length ? parts[parts.length - 1] : '';
    }

    function openGalleryFromButton() {
      openPersistentAudioPlayer();
      window.location.href = 'gallery.html';
    }

    // Keyboard and button handlers
    if (galleryOpenBtn) galleryOpenBtn.addEventListener('click', openGalleryFromButton);
    if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
    if (prevBtn) prevBtn.addEventListener('click', showPrev);
    if (nextBtn) nextBtn.addEventListener('click', showNext);
    attireImages.forEach(function (img) {
      img.classList.add('attire-zoomable');
      img.setAttribute('role', 'button');
      img.setAttribute('tabindex', '0');
      img.setAttribute('aria-label', 'Open image zoom');

      img.addEventListener('click', function () {
        openSingleLightboxImage(img.currentSrc || img.src || img.getAttribute('data-src') || '', img.alt || '');
      });

      img.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        openSingleLightboxImage(img.currentSrc || img.src || img.getAttribute('data-src') || '', img.alt || '');
      });
    });
    if (thumbsWrap) thumbsWrap.addEventListener('scroll', maybeRenderMoreThumbs);
    lightbox.addEventListener('click', function (e) { if (e.target === lightbox) closeLightbox(); });
    document.addEventListener('keydown', function (e) {
      if (!lightbox.classList.contains('open')) return;
      if (e.key === 'Escape') closeLightbox();
      if (standaloneImageMode) return;
      if (e.key === 'ArrowRight') showNext();
      if (e.key === 'ArrowLeft') showPrev();
    });

    // Initialize once TRP list is available
    loadTrpList().catch(function () {
      return null;
    });
  }

  /* ===== Smooth scroll for anchor links ===== */
  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var target = document.querySelector(this.getAttribute('href'));
      if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth' }); }
    });
  });

})();
