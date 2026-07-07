(function () {
  'use strict';

  var galleryGrid = document.getElementById('gallery-grid');
  var galleryMeta = document.getElementById('gallery-meta');
  var lightbox = document.getElementById('lightbox');
  var lightboxImg = document.getElementById('lightbox-image');
  var closeBtn = document.getElementById('lightbox-close');
  var prevBtn = document.getElementById('lightbox-prev');
  var nextBtn = document.getElementById('lightbox-next');
  var pagePrevBtn = document.getElementById('page-prev');
  var pageNextBtn = document.getElementById('page-next');
  var pageIndicator = document.getElementById('page-indicator');

  var images = [];
  var currentIndex = 0;
  var currentPage = 1;
  var pageSize = 24;
  var imageCache = {};

  function fallbackList() {
    return [
      'TRP-1.webp',
      'TRP-6.webp',
      'TRP-8.webp',
      'TRP-12.webp',
      'TRP-15.webp',
      'TRP-19.webp'
    ];
  }

  function setMeta(text) {
    if (galleryMeta) galleryMeta.textContent = text;
  }

  function thumbSrc(name) {
    return 'images/thumbs/' + name;
  }

  function fullSrc(name) {
    return 'images/' + name;
  }

  function createCard(name, index) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'gallery-card';
    btn.setAttribute('data-index', String(index));

    var img = document.createElement('img');
    img.src = thumbSrc(name);
    img.loading = 'lazy';
    img.decoding = 'async';
    img.alt = 'Gallery photo ' + (index + 1);
    img.addEventListener('error', function () {
      img.src = fullSrc(name);
    }, { once: true });

    btn.appendChild(img);
    btn.addEventListener('click', function () {
      openAt(index);
    });
    return btn;
  }

  function totalPages() {
    return Math.max(1, Math.ceil(images.length / pageSize));
  }

  function renderGrid() {
    if (!galleryGrid) return;
    galleryGrid.innerHTML = '';
    var start = (currentPage - 1) * pageSize;
    var end = Math.min(images.length, start + pageSize);

    for (var i = start; i < end; i++) {
      galleryGrid.appendChild(createCard(images[i], i));
    }

    setMeta('Showing ' + (start + 1) + '-' + end + ' of ' + images.length + ' photos');
    updatePagination();
  }

  function updatePagination() {
    if (pageIndicator) {
      pageIndicator.textContent = 'Page ' + currentPage + ' of ' + totalPages();
    }
    if (pagePrevBtn) pagePrevBtn.disabled = currentPage <= 1;
    if (pageNextBtn) pageNextBtn.disabled = currentPage >= totalPages();
  }

  function goToPage(page) {
    var target = Math.min(totalPages(), Math.max(1, page));
    if (target === currentPage) return;
    currentPage = target;
    renderGrid();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function preloadByIndex(index) {
    if (!images.length) return;
    var safeIndex = (index + images.length) % images.length;
    var src = fullSrc(images[safeIndex]);
    if (imageCache[src]) return;
    var img = new Image();
    img.decoding = 'async';
    img.src = src;
    imageCache[src] = true;
  }

  function updateLightbox() {
    if (!lightboxImg || !images.length) return;
    lightboxImg.src = fullSrc(images[currentIndex]);
    lightboxImg.alt = 'Gallery photo ' + (currentIndex + 1);
    preloadByIndex(currentIndex + 1);
    preloadByIndex(currentIndex - 1);
  }

  function openAt(index) {
    if (!images.length || !lightbox) return;
    currentIndex = (index + images.length) % images.length;
    updateLightbox();
    lightbox.classList.add('open');
    lightbox.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeLightbox() {
    if (!lightbox) return;
    lightbox.classList.remove('open');
    lightbox.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  function showNext() {
    if (!images.length) return;
    openAt(currentIndex + 1);
  }

  function showPrev() {
    if (!images.length) return;
    openAt(currentIndex - 1);
  }

  function bindEvents() {
    if (closeBtn) closeBtn.addEventListener('click', closeLightbox);
    if (nextBtn) nextBtn.addEventListener('click', showNext);
    if (prevBtn) prevBtn.addEventListener('click', showPrev);
    if (pagePrevBtn) pagePrevBtn.addEventListener('click', function () { goToPage(currentPage - 1); });
    if (pageNextBtn) pageNextBtn.addEventListener('click', function () { goToPage(currentPage + 1); });
    if (lightbox) {
      lightbox.addEventListener('click', function (event) {
        if (event.target === lightbox) closeLightbox();
      });
    }

    document.addEventListener('keydown', function (event) {
      if (!lightbox || !lightbox.classList.contains('open')) return;
      if (event.key === 'Escape') closeLightbox();
      if (event.key === 'ArrowRight') showNext();
      if (event.key === 'ArrowLeft') showPrev();
    });
  }

  function init() {
    bindEvents();
    fetch('js/trp-list.json', { cache: 'no-cache' })
      .then(function (response) { return response.json(); })
      .then(function (list) {
        images = Array.isArray(list) && list.length ? list : fallbackList();
        currentPage = 1;
        renderGrid();
      })
      .catch(function () {
        images = fallbackList();
        currentPage = 1;
        renderGrid();
        setMeta('Showing fallback gallery');
      });
  }

  init();
})();
