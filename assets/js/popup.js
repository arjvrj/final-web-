/* ============================================================================
   popup.js — when a promotion appears, and how it behaves
   ----------------------------------------------------------------------------
   The server decides WHICH campaign (status + dates + page targeting). This file
   decides only whether *this visitor* should see it now:

     trigger    immediate · delay · scroll · exit-intent (desktop only)
     frequency  every visit · once per session · 24h · 7d · once per campaign

   The server has already applied the frequency rule (and will not even render a
   popup this visitor should not see). The browser keeps its own copy as a second
   guard and as the fallback when cookies are blocked (private mode, storage off):
   the popup still works, it simply cannot remember quite as much.

   Nothing here loads until a campaign actually targets the page, because the
   <script> is only printed by PHP when one does.
   ========================================================================== */

(function () {
  'use strict';

  var root = document.getElementById('rgPopup');
  if (!root) return;

  var id        = root.getAttribute('data-popup-id') || '';
  var trigger   = root.getAttribute('data-trigger') || 'delay';
  var value     = parseInt(root.getAttribute('data-trigger-value') || '3', 10) || 3;
  var frequency = root.getAttribute('data-frequency') || 'session';
  var eventUrl  = root.getAttribute('data-event-url') || '';
  var analytics = root.getAttribute('data-analytics') !== '0';
  var coupon    = root.getAttribute('data-coupon') || '';
  var dismissed = false;

  var card      = root.querySelector('.rg-pop-card');
  var closeBtn  = root.querySelector('.rg-pop-close');
  var copyBtn   = root.querySelector('.rg-pop-copy');
  var copied    = document.getElementById('rgPopCopied');
  var cta       = root.querySelector('[data-rg-pop-cta]');

  var storageKey = 'rgpop:' + id;
  var shown = false;
  var lastFocused = null;

  /* ---------------- storage, with a memory fallback ---------------- */
  var memory = {};
  function read(store, key) {
    try {
      if (store === 'session') return window.sessionStorage.getItem(key);
      return window.localStorage.getItem(key);
    } catch (e) {
      return memory[store + key] || null;
    }
  }
  function write(store, key, val) {
    try {
      if (store === 'session') window.sessionStorage.setItem(key, val);
      else window.localStorage.setItem(key, val);
    } catch (e) {
      memory[store + key] = val;
    }
  }

  /* ---------------- may this visitor see it? ---------------- */
  function alreadySeen() {
    var session = read('session', storageKey);
    if (frequency === 'always') return false;
    if (frequency === 'session') return session !== null;

    var saved = read('local', storageKey);
    if (!saved) return false;

    if (frequency === 'campaign') return true;

    var millis = (frequency === '7d') ? 7 * 24 * 60 * 60 * 1000 : 24 * 60 * 60 * 1000;
    var last = parseInt(saved, 10);
    if (!last || isNaN(last)) return true;
    return (Date.now() - last) < millis;
  }

  function remember() {
    write('session', storageKey, '1');
    write('local', storageKey, String(Date.now()));
  }

  /* ---------------- counting ---------------- */
  function beacon(kind) {
    if (!analytics || !eventUrl) return;
    try {
      var token = '';
      var meta = document.querySelector('meta[name="csrf-token"]');
      if (meta) token = meta.getAttribute('content') || '';

      var body = new URLSearchParams();
      body.set('id', id);
      body.set('kind', kind);
      body.set('_csrf', token);

      /* keepalive lets the request finish even when the click navigates away */
      fetch(eventUrl, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).catch(function () { /* counting must never break the page */ });
    } catch (e) { /* same */ }
  }

  /* ---------------- showing ---------------- */
  function show() {
    if (shown) return;
    shown = true;

    lastFocused = document.activeElement;
    root.hidden = false;
    document.body.classList.add('rg-pop-open');
    if (analytics) {
      remember();
      beacon('impression');
    }

    if (closeBtn) {
      try { closeBtn.focus({ preventScroll: true }); } catch (e) { closeBtn.focus(); }
    }
  }

  function close() {
    if (root.hidden) return;
    root.hidden = true;
    document.body.classList.remove('rg-pop-open');

    /* A dismissal is an analytics event too: it tells the owner the offer was
       seen and refused, which is different from never being seen at all. */
    if (!dismissed) {
      dismissed = true;
      beacon('dismiss');
    }

    if (lastFocused && lastFocused.focus) {
      try { lastFocused.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
    }
  }

  /* ---------------- triggers ---------------- */
  function armTrigger() {
    if (trigger === 'immediate') { show(); return; }

    if (trigger === 'delay') {
      window.setTimeout(show, Math.max(1, value) * 1000);
      return;
    }

    if (trigger === 'scroll') {
      var percent = Math.min(100, Math.max(10, value));
      var fired = false;
      var onScroll = function () {
        if (fired) return;
        var scrollable = document.documentElement.scrollHeight - window.innerHeight;
        var progress = scrollable > 0 ? (window.scrollY / scrollable) * 100 : 100;
        if (progress >= percent) {
          fired = true;
          window.removeEventListener('scroll', onScroll);
          show();
        }
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
      return;
    }

    /* Exit intent. Desktop only, by request and by reality: a phone has no
       pointer leaving the window, and a "back" gesture must not be hijacked. */
    var desktopPointer = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    if (!desktopPointer || window.innerWidth < 900) return;

    var left = function (event) {
      if (event.clientY > 8) return;
      if (event.relatedTarget || event.toElement) return;
      document.removeEventListener('mouseout', left);
      show();
    };
    document.addEventListener('mouseout', left);
  }

  /* ---------------- the coupon button ---------------- */
  function copiedMessage(ok) {
    if (ok) beacon('coupon');
    if (!copied) return;
    copied.textContent = ok ? 'Copied to your clipboard' : 'Copy failed — select the code and copy it';
    copied.hidden = false;
    if (copyBtn) {
      copyBtn.classList.add('is-copied');
      copyBtn.textContent = ok ? 'Copied' : 'Copy code';
      window.setTimeout(function () {
        copyBtn.classList.remove('is-copied');
        copyBtn.textContent = 'Copy code';
      }, 2200);
    }
    window.setTimeout(function () { if (copied) copied.hidden = true; }, 3200);
  }

  function copyCoupon() {
    var text = coupon;
    var target = document.getElementById('rgPopCoupon');
    if (target && target.textContent.trim()) text = target.textContent.trim();
    if (!text) return;

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        copiedMessage(true);
      }).catch(function () {
        copiedMessage(fallbackCopy(text));
      });
      return;
    }
    copiedMessage(fallbackCopy(text));
  }

  /* Old browsers, and any browser that withholds the clipboard permission. */
  function fallbackCopy(text) {
    try {
      var area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.top = '-1000px';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();
      var ok = document.execCommand && document.execCommand('copy');
      document.body.removeChild(area);
      return !!ok;
    } catch (e) {
      return false;
    }
  }

  /* ---------------- artwork fallback ----------------
     The file can disappear after the page was rendered (a deleted upload, a
     half-finished deploy). Hide the broken image and reveal the branded block
     instead of leaving the browser's broken-image icon in the middle of the
     offer. The text, coupon and button below stay exactly as configured. */
  (function watchArtwork() {
    var image = root.querySelector('[data-rg-pop-image]');
    var fallback = document.getElementById('rgPopMediaFallback');
    if (!image) return;

    var fail = function () {
      image.hidden = true;
      if (fallback) fallback.hidden = false;
      root.classList.add('rg-pop-image-missing');
    };

    if (image.complete && image.naturalWidth === 0) fail();
    image.addEventListener('error', fail);
  })();

  /* ---------------- wiring ---------------- */
  root.addEventListener('click', function (event) {
    var closer = event.target.closest('[data-rg-pop-close]');
    if (closer) { close(); }
  });

  if (copyBtn) {
    copyBtn.addEventListener('click', function (event) {
      event.preventDefault();
      copyCoupon();
    });
  }

  if (cta) {
    cta.addEventListener('click', function () { beacon('click'); });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') close();

    /* Keep tabbing inside the dialog while it is open. */
    if (event.key === 'Tab' && !root.hidden) {
      var focusable = card ? card.querySelectorAll('button, a[href], [tabindex]:not([tabindex="-1"])') : [];
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });

  if (!analytics || !alreadySeen()) armTrigger();
})();
