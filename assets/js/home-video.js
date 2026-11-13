/* ============================================================================
   home-video.js — the homepage demo player
   ----------------------------------------------------------------------------
   Two jobs, and nothing else:

   1. Load YouTube only when the visitor asks for it. The page ships no iframe,
      so the landing page costs nothing and YouTube sets no cookie until the
      play button is pressed. The embed URL comes from PHP, built from a
      validated video id — this file never builds one from user input.

   2. Degrade honestly. If the poster image is missing (a deleted video, a dead
      thumbnail upload) the built-in ReviewAI card takes its place; if the frame
      never loads, the visitor gets a message and a working link instead of a
      black rectangle.
   ========================================================================== */

(function () {
  'use strict';

  function init() {
    var player = document.getElementById('rgVideoPlayer');
    if (!player) return;

    var playBtn = document.getElementById('rgVideoPlay');
    var slot    = document.getElementById('rgVideoSlot');
    var error   = document.getElementById('rgVideoError');
    if (!playBtn || !slot) return;

    var embed = player.getAttribute('data-embed') || '';
    var title = player.getAttribute('data-title') || 'ReviewAI tutorial';
    var watch = player.getAttribute('data-watch') || '';
    var started = false;

    /* ---- poster fallback -------------------------------------------------
       YouTube sends a 120x90 grey placeholder for some missing videos, which
       cannot be detected here. What can be detected is a load that fails
       outright, and that is what this covers: the built-in card shows instead
       of a broken-image icon. */
    var posterImg = player.querySelector('[data-rg-poster-fallback]');
    if (posterImg) {
      posterImg.addEventListener('error', function () {
        posterImg.style.display = 'none';
        var fallback = player.querySelector('.rg-video-default');
        if (fallback) fallback.classList.remove('is-hidden');
      });
    }

    function showError(message, linkHref, linkText) {
      if (!error) return;
      error.textContent = '';
      error.appendChild(document.createTextNode(message + ' '));
      if (linkHref) {
        var a = document.createElement('a');
        a.href = linkHref;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.textContent = linkText;
        error.appendChild(a);
      }
      error.hidden = false;
    }

    function start() {
      if (started) return;
      if (!embed) {
        showError('This video is not available right now.', watch, 'Watch on YouTube ↗');
        playBtn.disabled = true;
        return;
      }
      started = true;

      var frame = document.createElement('iframe');
      frame.src = embed;
      frame.title = title;
      frame.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
      frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
      frame.setAttribute('allowfullscreen', '');
      frame.loading = 'eager';

      slot.innerHTML = '';
      slot.appendChild(frame);
      slot.hidden = false;
      playBtn.hidden = true;
      if (error) error.hidden = true;

      /* A frame that has not loaded after nine seconds is a network problem or
         a video that YouTube has taken down. Say so, and give a real link. */
      var loaded = false;
      frame.addEventListener('load', function () { loaded = true; });
      window.setTimeout(function () {
        if (!loaded) {
          showError('The video is taking too long to load.', watch, 'Open it on YouTube ↗');
        }
      }, 9000);

      /* Keyboard users land straight in YouTube's own player controls. */
      window.setTimeout(function () {
        var inner = slot.querySelector('iframe');
        if (inner) inner.focus({ preventScroll: true });
      }, 400);
    }

    playBtn.addEventListener('click', start);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
