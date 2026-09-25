/**
 * Publication state (bp-headless): keeps the admin bar item and the dashboard widget up to date.
 * Asks GET /bp/v1/status once when the page was rendered without build.json, and then every 15 s ONLY while the
 * state is "scheduled" or "publishing" (one request at a time; paused while the tab is hidden). Between requests
 * the countdown, the elapsed time and the estimated progress tick locally.
 */
(function () {
  'use strict';
  var cfg = window.bpPublishStatus;
  if (!cfg || !cfg.status) {
    return;
  }
  var status = cfg.status;
  var pollMs = (cfg.pollSeconds || 15) * 1000;
  var timer = null;
  var ticker = null;
  var inFlight = false;

  function seconds(iso) {
    var ms = Date.parse(iso || '');
    return isNaN(ms) ? null : Math.floor(ms / 1000);
  }
  function duration(s) {
    s = Math.max(0, Math.round(s));
    if (s < 60) return s + ' s';
    if (s < 3600) return Math.floor(s / 60) + ' min' + (s % 60 ? ' ' + (s % 60) + ' s' : '');
    return Math.floor(s / 3600) + ' h' + (Math.floor((s % 3600) / 60) ? ' ' + Math.floor((s % 3600) / 60) + ' min' : '');
  }
  function ago(s) {
    s = Math.max(0, Math.round(s));
    if (s < 60) return 'hace unos segundos';
    if (s < 3600) return 'hace ' + Math.floor(s / 60) + ' min';
    if (s < 86400) return 'hace ' + Math.floor(s / 3600) + ' h';
    var d = Math.floor(s / 86400);
    return 'hace ' + d + (d === 1 ? ' día' : ' días');
  }

  function render() {
    var now = Date.now() / 1000;
    var detail = status.detail || '';
    var progress = typeof status.progress === 'number' ? status.progress : 0;
    var since = seconds(status.since);
    var until = seconds(status.until);
    if (status.state === 'scheduled' && until !== null) {
      detail = until - now > 0 ? 'en ' + duration(until - now) : 'en unos segundos';
    } else if (status.state === 'publishing' && since !== null) {
      detail = duration(now - since);
      progress = Math.min(0.95, (now - since) / (status.estimateSeconds || 180));
    } else if (status.state === 'published' && since !== null) {
      detail = ago(now - since);
    }
    var text = status.label + (detail ? ' · ' + detail : '');
    document.querySelectorAll('[data-bp-publish]').forEach(function (node) {
      node.className = node.className.replace(/bp-publish--(scheduled|publishing|published|error|pending|unknown)/, 'bp-publish--' + status.state);
      node.setAttribute('data-state', status.state);
      var dot = node.querySelector('.bp-publish__dot');
      var label = node.querySelector('.bp-publish__text');
      var bar = node.querySelector('.bp-publish__bar > span');
      if (dot) dot.style.background = status.color;
      if (label) label.textContent = text;
      if (bar) bar.style.width = Math.round(progress * 100) + '%';
    });
  }

  function plan() {
    clearTimeout(timer);
    clearInterval(ticker);
    if (status.poll) {
      timer = setTimeout(refresh, pollMs);
      ticker = setInterval(render, 1000);
    } else if (status.state === 'published') {
      ticker = setInterval(render, 30000);
    }
  }

  function refresh() {
    if (inFlight) return;
    if (document.hidden) {
      timer = setTimeout(refresh, pollMs);
      return;
    }
    inFlight = true;
    fetch(cfg.endpoint, { credentials: 'omit', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (data && data.publish) {
          status = data.publish;
          render();
        }
      })
      .catch(function () { /* keep the last known state */ })
      .then(function () {
        inFlight = false;
        plan();
      });
  }

  // The block editor announces a save (publish-editor.js): the deploy is queued a moment later.
  window.addEventListener('bp:publish-refresh', function () {
    clearTimeout(timer);
    timer = setTimeout(refresh, 2000);
  });
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && status.poll) refresh();
  });

  render();
  if (cfg.fresh) {
    plan();
  } else {
    refresh();
  }
})();
