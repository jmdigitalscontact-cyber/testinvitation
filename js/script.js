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

  /* ===== Banner Slides with Concentric Circle Animation ===== */
  window.addEventListener('load', function () {
    preloadCriticalAssets();
    registerServiceWorker();
    setupLazyImages();
    var slides = document.querySelectorAll('.bg-slide');
    var slideBtns = document.querySelectorAll('.slide-btn');

    function activateSlide(targetClass) {
      if (!targetClass) return false;
      var matched = false;
      slides.forEach(function (s) {
        var isTarget = s.classList.contains(targetClass);
        s.classList.toggle('active', isTarget);
        if (isTarget) matched = true;
      });
      return matched;
    }

    function activateButton(targetClass) {
      slideBtns.forEach(function (btn) {
        btn.classList.toggle('active', btn.getAttribute('data-target') === targetClass);
      });
    }

    // Ensure first slide gets activated to trigger CSS transition
    var currentActive = document.querySelector('.bg-slide.active');
    if (!currentActive) {
      if (slides.length > 0) {
        slides[0].classList.add('active');
        var defaultTarget = Array.from(slides[0].classList).find(function (name) {
          return name.startsWith('slide-');
        });
        if (defaultTarget) activateButton(defaultTarget);
      }
    } else {
      var currentTarget = Array.from(currentActive.classList).find(function (name) {
        return name.startsWith('slide-');
      });
      if (currentTarget) activateButton(currentTarget);
    }

    slideBtns.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var target = btn.getAttribute('data-target');
        if (!target) return;
        var didActivate = activateSlide(target);
        if (didActivate) activateButton(target);
      });
    });
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
  document.querySelectorAll('.nav-btn').forEach(function (navBtn) {
    navBtn.addEventListener('click', function (e) {
      e.preventDefault();
      var target = navBtn.getAttribute('data-target');
      if (!target) return;
      var section = document.getElementById(target);
      if (section) section.scrollIntoView({ behavior: 'smooth' });
      document.querySelectorAll('.nav-btn').forEach(function (n) { n.classList.remove('active'); });
      navBtn.classList.add('active');
    });
  });

  /* ===== Scroll Reveal ===== */
  var revealObserver = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) entry.target.classList.add('visible');
    });
  }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

  document.querySelectorAll('.reveal').forEach(function (el) { revealObserver.observe(el); });

  /* ===== RSVP Form ===== */
  var form = document.getElementById('rsvp-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fb = document.getElementById('rsvp-feedback');
      var name = form.querySelector('[name="name"]').value.trim();
      var attending = form.querySelector('[name="attendance"]').value;
      var btn = form.querySelector('.btn-primary');

      if (!name) { showFeedback(fb, 'Please enter your name.', 'error'); return; }
      if (!attending) { showFeedback(fb, 'Please select your attendance.', 'error'); return; }

      showFeedback(fb, 'Thank you! Your RSVP has been received.', 'success');
      btn.disabled = true;
      btn.textContent = 'RSVP Received';
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
    var isSmallScreen = window.matchMedia('(max-width: 768px)').matches;
    var shouldAnimate = !reduceMotion && !isSmallScreen;
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

  window.addEventListener('load', function () {
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
    audioElement.volume = 0.65;
    audioElement.loop = true;
    audioElement.autoplay = true;
    audioElement.playsInline = true;

    function attemptAudioPlay() {
      audioElement.play().catch(function () {
        return null;
      });
    }

    window.addEventListener('load', attemptAudioPlay);

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

  /* ===== Lightbox for Gallery (with navigation + thumbnails) ===== */
  var lightbox = document.getElementById('lightbox');
  if (lightbox) {
    var viewImg = lightbox.querySelector('.lightbox-view img');
    var lightboxClose = lightbox.querySelector('.lightbox-close');
    var prevBtn = lightbox.querySelector('.lightbox-prev');
    var nextBtn = lightbox.querySelector('.lightbox-next');
    var thumbsWrap = lightbox.querySelector('.lightbox-thumbs');
    var galleryOpenBtn = document.getElementById('open-gallery-btn');
    var currentIndex = 0;
    var thumbBatchSize = 20;
    var thumbCursor = 0;
    var renderedAllThumbs = false;
    var lightboxImageCache = {};

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

    function closeLightbox() {
      lightbox.classList.remove('open');
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
      loadTrpList().then(function () {
        thumbCursor = 0;
        renderedAllThumbs = false;
        if (thumbsWrap) thumbsWrap.innerHTML = '';
        if (viewImg) {
          viewImg.src = '';
          viewImg.alt = '';
        }
        renderThumbBatch();

        if (trpList.length) {
          openLightboxAt(0);
          return;
        }

        var fallbackName = getFirstVisibleGalleryImageName();
        if (fallbackName) {
          openLightboxByName(fallbackName);
          return;
        }

        openLightboxByName('TRP-1.webp');
      });
    }

    // Keyboard and button handlers
    if (galleryOpenBtn) galleryOpenBtn.addEventListener('click', openGalleryFromButton);
    if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
    if (prevBtn) prevBtn.addEventListener('click', showPrev);
    if (nextBtn) nextBtn.addEventListener('click', showNext);
    if (thumbsWrap) thumbsWrap.addEventListener('scroll', maybeRenderMoreThumbs);
    lightbox.addEventListener('click', function (e) { if (e.target === lightbox) closeLightbox(); });
    document.addEventListener('keydown', function (e) {
      if (!lightbox.classList.contains('open')) return;
      if (e.key === 'Escape') closeLightbox();
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
