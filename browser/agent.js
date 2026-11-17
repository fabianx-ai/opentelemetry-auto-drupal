/**
 * @file
 * Browser agent: completes the tracing transaction issued by the server.
 *
 * Reads the transaction from the Server-Timing header of the page, measures
 * the page view (document fetch, resources, fetch/XHR calls, Web Vitals) and
 * sends it as OTLP/JSON to the same-origin callback endpoint when the page
 * is hidden. Does nothing when the server issued no transaction.
 *
 * No dependencies. See docs/browser-tracing.md.
 */
(function () {
  'use strict';

  var perf = window.performance;
  if (!perf || !perf.getEntriesByType || !perf.timeOrigin || !navigator.sendBeacon || !window.crypto) {
    return;
  }
  var nav = perf.getEntriesByType('navigation')[0];
  if (!nav || !nav.serverTiming) {
    return;
  }

  var script = document.currentScript;
  var endpoint = (script && script.getAttribute('data-endpoint')) || '/otel/v1/traces';
  var MAX_RESOURCES = 150;
  // A transaction older than this was replayed by a cache.
  var STALE_AFTER_MS = 60000;

  // --- The transaction -----------------------------------------------------

  var timing = {};
  nav.serverTiming.forEach(function (entry) {
    timing[entry.name] = entry;
  });

  var match = /^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/.exec(
    timing.traceparent ? timing.traceparent.description : ''
  );
  // No transaction, or not sampled: the server decided not to trace.
  if (!match || !(parseInt(match[3], 16) & 1)) {
    return;
  }

  var edge = timing.edge ? timing.edge.description : '';
  var serverStart = timing.srv ? parseInt(timing.srv.description, 10) : NaN;
  var serverDuration = timing.srv ? timing.srv.duration : NaN;
  var requestEpoch = perf.timeOrigin + nav.requestStart;

  // The transaction belongs to this page view only if the response was
  // produced for it. A response replayed by an edge cache or by the browser
  // carries the transaction of the request that rendered the page.
  // The navigation type says nothing here: going back can mean a new request
  // to the server or a copy from the cache. What counts is whether the
  // response came over the network.
  var stale = edge === 'HIT' ||
    nav.deliveryType === 'cache' ||
    (nav.transferSize === 0 && nav.decodedBodySize > 0) ||
    // Fallback for an edge that does not report its cache status.
    (!edge && !isNaN(serverStart) && requestEpoch - serverStart > STALE_AFTER_MS);

  function randomId(bytes) {
    var buffer = new Uint8Array(bytes);
    window.crypto.getRandomValues(buffer);
    var id = '';
    for (var i = 0; i < bytes; i++) {
      id += (buffer[i] < 16 ? '0' : '') + buffer[i].toString(16);
    }
    return id;
  }

  var traceId = stale ? randomId(16) : match[1];
  var fetchSpanId = stale ? randomId(8) : match[2];
  var rootSpanId = randomId(8);

  // --- Time ----------------------------------------------------------------

  // Shift browser time onto the server clock, assuming the network time is
  // split evenly between request and response. Without a server span to
  // align with, the browser clock is used as it is.
  var offset = 0;
  if (!stale && !isNaN(serverStart) && !isNaN(serverDuration) && nav.responseStart > 0) {
    var network = Math.max(0, nav.responseStart - nav.requestStart - serverDuration);
    offset = serverStart - (requestEpoch + network / 2);
  }

  // Nanoseconds since the epoch as a string, for a time relative to the
  // time origin in milliseconds. Built from two parts: the value does not
  // fit into a double.
  function nanos(relative) {
    var ms = perf.timeOrigin + relative + offset;
    var whole = Math.floor(ms);
    var rest = String(Math.round((ms - whole) * 1e6));
    if (rest.length > 6) {
      whole += 1;
      rest = '0';
    }
    return String(whole) + '000000'.slice(rest.length) + rest;
  }

  // --- Building spans ------------------------------------------------------

  // A measurement: always sent as a double, so that the attribute has one
  // type in the backend whether or not the value happens to be whole.
  function measured(value) {
    return {measured: value};
  }

  function attribute(key, value) {
    if (value && typeof value === 'object') {
      return typeof value.measured === 'number' && !isNaN(value.measured)
        ? {key: key, value: {doubleValue: value.measured}}
        : null;
    }
    if (value === undefined || value === null || value === '' || (typeof value === 'number' && isNaN(value))) {
      return null;
    }
    if (typeof value === 'boolean') {
      return {key: key, value: {boolValue: value}};
    }
    if (typeof value === 'number') {
      return Math.floor(value) === value
        ? {key: key, value: {intValue: String(value)}}
        : {key: key, value: {doubleValue: value}};
    }
    return {key: key, value: {stringValue: String(value)}};
  }

  function attributes(map) {
    var list = [];
    Object.keys(map).forEach(function (key) {
      var item = attribute(key, map[key]);
      if (item) {
        list.push(item);
      }
    });
    return list;
  }

  function span(id, parent, name, kind, start, end, attrs) {
    var result = {
      traceId: traceId,
      spanId: id,
      name: name,
      kind: kind,
      startTimeUnixNano: nanos(start),
      endTimeUnixNano: nanos(Math.max(start, end)),
      attributes: attributes(attrs || {})
    };
    if (parent) {
      result.parentSpanId = parent;
    }
    return result;
  }

  function event(name, time) {
    return {name: name, timeUnixNano: nanos(time)};
  }

  // URLs are reported without query string and fragment.
  function cleanUrl(url) {
    try {
      var parsed = new URL(url, location.href);
      return parsed.origin + parsed.pathname;
    }
    catch (e) {
      return '';
    }
  }

  function path(url) {
    try {
      return new URL(url, location.href).pathname;
    }
    catch (e) {
      return '';
    }
  }

  function sameOrigin(url) {
    try {
      return new URL(url, location.href).origin === location.origin;
    }
    catch (e) {
      return false;
    }
  }

  var INTERNAL = 1;
  var CLIENT = 3;

  // --- fetch and XMLHttpRequest ---------------------------------------------

  // Same-origin requests made by the page join the trace: they carry a
  // traceparent header, and the server span becomes a child of the span
  // recorded here.
  var pending = [];

  function requestSpan(method, url, start, end, status) {
    var id = randomId(8);
    return {
      id: id,
      header: '00-' + traceId + '-' + id + '-01',
      finish: function (finalEnd, finalStatus) {
        pending.push(span(id, rootSpanId, method + ' ' + path(url), CLIENT, start, finalEnd, {
          'http.request.method': method,
          'url.full': cleanUrl(url),
          'http.response.status_code': finalStatus
        }));
      }
    };
  }

  if (window.fetch) {
    var originalFetch = window.fetch;
    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : (input && input.url) || String(input);
      if (!sameOrigin(url) || path(url) === endpoint) {
        return originalFetch.apply(this, arguments);
      }
      var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
      var record = requestSpan(method, url, perf.now());
      var options = Object.assign({}, init);
      var headers = new Headers((init && init.headers) || (typeof input !== 'string' && input && input.headers) || {});
      headers.set('traceparent', record.header);
      options.headers = headers;
      return originalFetch.call(this, input, options).then(function (response) {
        record.finish(perf.now(), response.status);
        return response;
      }, function (error) {
        record.finish(perf.now(), 0);
        throw error;
      });
    };
  }

  if (window.XMLHttpRequest) {
    var open = XMLHttpRequest.prototype.open;
    var send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
      this.otelRequest = sameOrigin(url) ? {method: String(method).toUpperCase(), url: String(url)} : null;
      return open.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
      var request = this.otelRequest;
      if (request) {
        var xhr = this;
        var record = requestSpan(request.method, request.url, perf.now());
        try {
          xhr.setRequestHeader('traceparent', record.header);
        }
        catch (e) {}
        xhr.addEventListener('loadend', function () {
          record.finish(perf.now(), xhr.status);
        });
      }
      return send.apply(this, arguments);
    };
  }

  // --- Web Vitals ----------------------------------------------------------

  var vitals = {lcp: NaN, cls: 0, inp: NaN, fcp: NaN};

  function observe(type, callback, options) {
    try {
      var observer = new PerformanceObserver(function (list) {
        list.getEntries().forEach(callback);
      });
      observer.observe(Object.assign({type: type, buffered: true}, options || {}));
    }
    catch (e) {}
  }

  observe('largest-contentful-paint', function (entry) {
    vitals.lcp = entry.startTime;
  });

  observe('paint', function (entry) {
    if (entry.name === 'first-contentful-paint') {
      vitals.fcp = entry.startTime;
    }
  });

  // Cumulative layout shift: the largest burst of shifts, where a burst
  // ends after a gap of one second or a length of five seconds.
  var burst = {value: 0, first: 0, last: 0};
  observe('layout-shift', function (entry) {
    if (entry.hadRecentInput) {
      return;
    }
    if (burst.value && entry.startTime - burst.last < 1000 && entry.startTime - burst.first < 5000) {
      burst.value += entry.value;
    }
    else {
      burst.value = entry.value;
      burst.first = entry.startTime;
    }
    burst.last = entry.startTime;
    vitals.cls = Math.max(vitals.cls, burst.value);
  });

  // Interaction to next paint, as the slowest interaction of the page view.
  observe('event', function (entry) {
    if (entry.interactionId && !(entry.duration <= vitals.inp)) {
      vitals.inp = entry.duration;
    }
  }, {durationThreshold: 40});

  // --- Sending -------------------------------------------------------------

  var pageSent = false;

  function pageSpans() {
    var end = nav.loadEventEnd > 0 ? nav.loadEventEnd : perf.now();
    if (!isNaN(vitals.lcp)) {
      end = Math.max(end, vitals.lcp);
    }

    var root = span(rootSpanId, null, 'pageload ' + location.pathname, INTERNAL, 0, end, {
      'url.full': cleanUrl(location.href),
      'browser.navigation.type': nav.type,
      'browser.web_vital.ttfb': measured(nav.responseStart),
      'browser.web_vital.fcp': measured(vitals.fcp),
      'browser.web_vital.lcp': measured(vitals.lcp),
      'browser.web_vital.cls': measured(vitals.cls),
      'browser.web_vital.inp': measured(vitals.inp)
    });
    root.events = [];
    [
      ['domInteractive', nav.domInteractive],
      ['domContentLoaded', nav.domContentLoadedEventEnd],
      ['load', nav.loadEventEnd],
      ['firstContentfulPaint', vitals.fcp],
      ['largestContentfulPaint', vitals.lcp]
    ].forEach(function (item) {
      if (item[1] > 0) {
        root.events.push(event(item[0], item[1]));
      }
    });

    var documentFetch = span(fetchSpanId, rootSpanId, 'documentFetch', CLIENT, nav.fetchStart, nav.responseEnd, {
      'http.request.method': 'GET',
      'url.full': cleanUrl(location.href),
      'http.response.status_code': nav.responseStatus,
      'http.response.body.size': nav.transferSize,
      'network.protocol.name': nav.nextHopProtocol,
      'http.cache.edge': edge,
      // On a replayed response this is the outcome of another request.
      'drupal.page_cache': !stale && timing['drupal-cache'] ? timing['drupal-cache'].description : '',
      'browser.transaction': stale ? 'stale' : 'fresh'
    });
    documentFetch.events = [];
    [
      ['domainLookupStart', nav.domainLookupStart],
      ['connectStart', nav.connectStart],
      ['secureConnectionStart', nav.secureConnectionStart],
      ['requestStart', nav.requestStart],
      ['responseStart', nav.responseStart]
    ].forEach(function (item) {
      if (item[1] > 0) {
        documentFetch.events.push(event(item[0], item[1]));
      }
    });
    if (stale) {
      // The trace of the request that rendered this page.
      documentFetch.links = [{
        traceId: match[1],
        spanId: match[2],
        attributes: attributes({'link.kind': 'rendered_by'})
      }];
    }

    var spans = [root, documentFetch];
    perf.getEntriesByType('resource').slice(0, MAX_RESOURCES).forEach(function (entry) {
      // Calls made by the page are recorded by the wrappers above.
      if (path(entry.name) === endpoint ||
        ((entry.initiatorType === 'fetch' || entry.initiatorType === 'xmlhttprequest') && sameOrigin(entry.name))) {
        return;
      }
      spans.push(span(randomId(8), rootSpanId, 'resourceFetch ' + path(entry.name), CLIENT, entry.startTime, entry.responseEnd, {
        'url.full': cleanUrl(entry.name),
        'browser.resource.initiator': entry.initiatorType,
        'http.response.body.size': entry.transferSize,
        'http.response.status_code': entry.responseStatus
      }));
    });
    return spans;
  }

  function flush() {
    var spans = pending;
    pending = [];
    if (!pageSent) {
      pageSent = true;
      spans = pageSpans().concat(spans);
    }
    if (!spans.length) {
      return;
    }
    var body = JSON.stringify({
      resourceSpans: [{
        scopeSpans: [{scope: {name: 'drupal.browser'}, spans: spans}]
      }]
    });
    navigator.sendBeacon(endpoint, new Blob([body], {type: 'application/json'}));
  }

  // The page view is reported when the page is hidden: only then the Web
  // Vitals are final. Requests made after that are reported with the next
  // flush.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flush();
    }
  });
  window.addEventListener('pagehide', flush);
})();
