#!/bin/bash
# Emulates the browser side of the browser-tracing design with curl:
# 1. navigation carrying a pre-allocated transaction (trace ID + span ID),
# 2. the browser "completing" the transaction through the same-origin
#    callback endpoint with OTLP/JSON,
# 3. reading the joined trace back through Grafana's Jaeger data source.
set -e
BASE=${BASE:?Set BASE to the URL of the site, for example BASE=https://example.ddev.site}
PAGE=${1:-/user/login}
TRACE=$(openssl rand -hex 16); FETCH=$(openssl rand -hex 8); ROOT=$(openssl rand -hex 8); RES=$(openssl rand -hex 8)
START=$(date +%s%N)
curl -sk -o /dev/null -H "traceparent: 00-$TRACE-$FETCH-01" "$BASE$PAGE"
END=$(date +%s%N)
cat > /tmp/beacon.$$.json <<JSON
{"resourceSpans":[{"resource":{"attributes":[{"key":"service.name","value":{"stringValue":"drupal-browser"}}]},
 "scopeSpans":[{"scope":{"name":"drupal.browser"},"spans":[
 {"traceId":"$TRACE","spanId":"$ROOT","name":"pageload $PAGE","kind":1,"startTimeUnixNano":"$((START-5000000))","endTimeUnixNano":"$((END+400000000))",
  "attributes":[{"key":"url.full","value":{"stringValue":"$BASE$PAGE"}},{"key":"browser.web_vital.lcp","value":{"doubleValue":812.5}}]},
 {"traceId":"$TRACE","spanId":"$FETCH","parentSpanId":"$ROOT","name":"documentFetch","kind":3,"startTimeUnixNano":"$START","endTimeUnixNano":"$END"},
 {"traceId":"$TRACE","spanId":"$RES","parentSpanId":"$ROOT","name":"resourceFetch /core/misc/drupal.js","kind":3,"startTimeUnixNano":"$((END+50000000))","endTimeUnixNano":"$((END+90000000))"}
 ]}]}]}
JSON
curl -sk -o /dev/null -w "callback: %{http_code}\n" -X POST -H "Content-Type: application/json" --data-binary @/tmp/beacon.$$.json "$BASE/otel/v1/traces"
rm -f /tmp/beacon.$$.json
echo "trace: $TRACE"
echo "grafana: $BASE:3000/explore?left=%7B%22datasource%22:%22jaeger%22,%22queries%22:%5B%7B%22query%22:%22$TRACE%22%7D%5D%7D"
echo "jaeger:  $BASE:16686/trace/$TRACE"
