(function () {
  'use strict';

  var audio = document.getElementById('player-audio');
  var playToggle = document.getElementById('play-toggle');
  var closeWindow = document.getElementById('close-window');
  var status = document.getElementById('player-status');
  var stateKey = 'wedding-audio-player-state';
  var modeKey = 'wedding-audio-mode';
  var readyToPersist = false;

  function setStatus(text) {
    if (status) status.textContent = text;
  }

  function readState() {
    try {
      return JSON.parse(localStorage.getItem(stateKey) || '{}');
    } catch (err) {
      return {};
    }
  }

  function saveState() {
    if (!audio || !readyToPersist) return;
    try {
      localStorage.setItem(stateKey, JSON.stringify({
        currentTime: audio.currentTime || 0,
        playing: !audio.paused,
        volume: audio.volume
      }));
      localStorage.setItem(modeKey, 'popup');
    } catch (err) {
      return null;
    }
  }

  function restoreState() {
    var saved = readState();
    if (typeof saved.volume === 'number' && !Number.isNaN(saved.volume)) {
      audio.volume = saved.volume;
    } else {
      audio.volume = 0.65;
    }
    return saved;
  }

  function attemptPlay() {
    if (!audio) return;
    audio.play().then(function () {
      setStatus('Playing in background');
    }).catch(function () {
      setStatus('Playback blocked. Click Play / Pause once.');
    });
  }

  function togglePlayback() {
    if (!audio) return;
    if (audio.paused) {
      attemptPlay();
    } else {
      audio.pause();
      setStatus('Paused');
    }
  }

  if (audio) {
    audio.loop = true;
    audio.preload = 'auto';
    audio.autoplay = true;
    audio.playsInline = true;
    var savedState = restoreState();
    localStorage.setItem(modeKey, 'popup');

    function startPlayback() {
      if (typeof savedState.currentTime === 'number' && !Number.isNaN(savedState.currentTime)) {
        audio.currentTime = savedState.currentTime;
      }
      readyToPersist = true;
      saveState();
      attemptPlay();
    }

    if (audio.readyState >= 1) {
      startPlayback();
    } else {
      audio.addEventListener('loadedmetadata', startPlayback, { once: true });
    }

    audio.addEventListener('timeupdate', saveState);
    audio.addEventListener('play', saveState);
    audio.addEventListener('pause', saveState);
    audio.addEventListener('ended', saveState);
    window.addEventListener('beforeunload', saveState);
    window.addEventListener('pagehide', saveState);

    window.addEventListener('load', attemptPlay);
    window.addEventListener('pageshow', attemptPlay);

    if (playToggle) playToggle.addEventListener('click', togglePlayback);
    if (closeWindow) closeWindow.addEventListener('click', function () {
      try {
        saveState();
        window.close();
      } catch (err) {
        setStatus('Unable to close automatically. You can close the window manually.');
      }
    });
  }
})();