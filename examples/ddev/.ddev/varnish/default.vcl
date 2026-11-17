vcl 4.1;

backend default {
    .host = "web";
    .port = "80";
}

sub vcl_deliver {
    # Tell the browser agent what the edge did. On a hit the traceparent
    # metric that is replayed from the cached object belongs to the request
    # that rendered the page, not to this one.
    if (obj.hits > 0) {
        set resp.http.Server-Timing = resp.http.Server-Timing + {", edge;desc="HIT""};
    } else {
        set resp.http.Server-Timing = resp.http.Server-Timing + {", edge;desc="MISS""};
    }
}
