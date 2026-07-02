(function () {
    var STORAGE_CLICK = 'zendy_click_id';
    var STORAGE_LAUNCHED = 'zendy_launched_at';
    var STORAGE_SENT = 'zendy_tab_close_sent';
    var NAV_FLAG = 'zendy_navigating_away';

    function csrfToken() {
        var el = document.querySelector('meta[name="csrf-token"]');
        return el ? el.getAttribute('content') : '';
    }

    function sessionEndUrl() {
        return window.zendySessionConfig && window.zendySessionConfig.endUrl;
    }

    function clearStorage() {
        try {
            sessionStorage.removeItem(STORAGE_CLICK);
            sessionStorage.removeItem(STORAGE_LAUNCHED);
            sessionStorage.removeItem(STORAGE_SENT);
            sessionStorage.removeItem(NAV_FLAG);
        } catch (e) {}
    }

    function syncFromConfig() {
        var cfg = window.zendySessionConfig;
        if (!cfg) {
            return;
        }

        if (cfg.clickId && cfg.launchedAt) {
            try {
                sessionStorage.setItem(STORAGE_CLICK, cfg.clickId);
                sessionStorage.setItem(STORAGE_LAUNCHED, cfg.launchedAt);
                sessionStorage.removeItem(STORAGE_SENT);
            } catch (e) {}
        } else if (cfg.clearSession) {
            clearStorage();
        }
    }

    function durationSeconds() {
        try {
            var launched = sessionStorage.getItem(STORAGE_LAUNCHED);
            if (!launched) {
                return 0;
            }

            return Math.max(0, Math.floor((Date.now() - Date.parse(launched)) / 1000));
        } catch (e) {
            return 0;
        }
    }

    function sendTabClose() {
        try {
            if (sessionStorage.getItem(NAV_FLAG) === '1') {
                return;
            }

            if (sessionStorage.getItem(STORAGE_SENT) === '1') {
                return;
            }

            var clickId = sessionStorage.getItem(STORAGE_CLICK);
            if (!clickId) {
                return;
            }

            var url = sessionEndUrl();
            if (!url) {
                return;
            }

            var fd = new FormData();
            fd.append('_token', csrfToken());
            fd.append('click_id', clickId);
            fd.append('duration_seconds', String(durationSeconds()));

            sessionStorage.setItem(STORAGE_SENT, '1');

            if (navigator.sendBeacon) {
                navigator.sendBeacon(url, fd);
            } else {
                fetch(url, { method: 'POST', body: fd, keepalive: true, credentials: 'same-origin' });
            }
        } catch (e) {}
    }

    function markNavigatingAway() {
        try {
            sessionStorage.setItem(NAV_FLAG, '1');
        } catch (e) {}
    }

    syncFromConfig();

    window.addEventListener('pagehide', sendTabClose);

    window.zendySession = {
        markNavigatingAway: markNavigatingAway,
        clearStorage: clearStorage,
    };
})();
