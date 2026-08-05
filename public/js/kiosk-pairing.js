/**
 * Browser kiosk pairing — stores gate device token in localStorage so online scans
 * record which named kiosk was used (like JMC).
 *
 * Usage: include after setting window.KIOSK_PAIRING = { statusUrl, storageKey?, pairToken? }
 * Exposes window.KioskPairing: { getToken, headers, attachBody, refreshBanner, unpair }
 */
(function () {
  const cfg = window.KIOSK_PAIRING || {};
  const STORAGE_KEY = cfg.storageKey || 'acd_kiosk_token';
  const NAME_KEY = cfg.nameKey || 'acd_kiosk_name';

  function getToken() {
    try {
      return localStorage.getItem(STORAGE_KEY) || '';
    } catch (e) {
      return '';
    }
  }

  function getName() {
    try {
      return localStorage.getItem(NAME_KEY) || '';
    } catch (e) {
      return '';
    }
  }

  function setPairing(token, name) {
    try {
      if (token) localStorage.setItem(STORAGE_KEY, token);
      if (name) localStorage.setItem(NAME_KEY, name);
      else if (token) localStorage.removeItem(NAME_KEY);
    } catch (e) { /* private mode */ }
  }

  function unpair() {
    try {
      localStorage.removeItem(STORAGE_KEY);
      localStorage.removeItem(NAME_KEY);
    } catch (e) { /* ignore */ }
    refreshBanner();
  }

  function headers(extra) {
    const h = Object.assign({}, extra || {});
    const token = getToken();
    if (token) {
      h['X-Gate-Token'] = token;
      h['X-Kiosk-Token'] = token;
    }
    return h;
  }

  /** Merge kiosk_token into a plain object body (for JSON.stringify). */
  function attachBody(body) {
    const token = getToken();
    if (!token) return body || {};
    return Object.assign({}, body || {}, { kiosk_token: token });
  }

  /** Append kiosk_token to FormData. */
  function attachFormData(formData) {
    const token = getToken();
    if (token && formData && typeof formData.append === 'function') {
      formData.append('kiosk_token', token);
    }
    return formData;
  }

  function updateBanner(state) {
    const el = document.getElementById('kioskPairBanner');
    if (!el) return;

    const nameEl = el.querySelector('[data-kiosk-name]');
    const statusEl = el.querySelector('[data-kiosk-status]');
    const unpairBtn = el.querySelector('[data-kiosk-unpair]');

    if (state.paired) {
      el.hidden = false;
      el.classList.remove('kiosk-pair-banner--warn');
      el.classList.add('kiosk-pair-banner--ok');
      if (nameEl) nameEl.textContent = state.name || 'Kiosk';
      if (statusEl) statusEl.textContent = 'Paired — scans will show this name in attendance logs.';
      if (unpairBtn) unpairBtn.hidden = false;
    } else if (state.token) {
      el.hidden = false;
      el.classList.add('kiosk-pair-banner--warn');
      el.classList.remove('kiosk-pair-banner--ok');
      if (nameEl) nameEl.textContent = 'Invalid token';
      if (statusEl) statusEl.textContent = 'This kiosk token is inactive or was rotated. Re-pair from Gate devices.';
      if (unpairBtn) unpairBtn.hidden = false;
    } else {
      el.hidden = false;
      el.classList.add('kiosk-pair-banner--warn');
      el.classList.remove('kiosk-pair-banner--ok');
      if (nameEl) nameEl.textContent = 'Unnamed kiosk';
      if (statusEl) statusEl.textContent = 'Pair from Admin → Gate devices so logs show where students scanned.';
      if (unpairBtn) unpairBtn.hidden = true;
    }
  }

  function refreshBanner() {
    const token = getToken();
    if (!token) {
      updateBanner({ paired: false, token: false });
      return Promise.resolve({ paired: false });
    }

    if (!cfg.statusUrl) {
      updateBanner({ paired: true, name: getName() || 'Paired kiosk', token: true });
      return Promise.resolve({ paired: true, name: getName() });
    }

    return fetch(cfg.statusUrl, {
      method: 'GET',
      headers: headers({ Accept: 'application/json' }),
      credentials: 'same-origin',
    })
      .then((res) => res.json().then((data) => ({ ok: res.ok, data })))
      .then(({ ok, data }) => {
        if (ok && data.paired) {
          setPairing(token, data.name);
          updateBanner({ paired: true, name: data.name, token: true });
          return data;
        }
        updateBanner({ paired: false, token: true });
        return data;
      })
      .catch(() => {
        updateBanner({ paired: true, name: getName() || 'Paired kiosk', token: true });
        return { paired: true };
      });
  }

  // Pair from ?pair_token= on load (from Gate devices “Open as kiosk” link).
  (function consumePairQuery() {
    try {
      const params = new URLSearchParams(window.location.search);
      const pair = params.get('pair_token');
      if (!pair) return;
      setPairing(pair, params.get('kiosk_name') || '');
      params.delete('pair_token');
      params.delete('kiosk_name');
      const q = params.toString();
      const next = window.location.pathname + (q ? '?' + q : '') + window.location.hash;
      window.history.replaceState({}, '', next);
    } catch (e) { /* ignore */ }
  })();

  document.addEventListener('DOMContentLoaded', function () {
    const unpairBtn = document.querySelector('[data-kiosk-unpair]');
    if (unpairBtn) {
      unpairBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (confirm('Unpair this browser from the kiosk name? Future scans will show as Unnamed kiosk.')) {
          unpair();
        }
      });
    }
    refreshBanner();
  });

  window.KioskPairing = {
    getToken,
    getName,
    headers,
    attachBody,
    attachFormData,
    refreshBanner,
    unpair,
    setPairing,
  };
})();
