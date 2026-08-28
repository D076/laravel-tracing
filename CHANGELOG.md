# Changelog

All notable changes to `d076/laravel-tracing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the package is on `0.x`, minor versions may contain breaking changes; patch versions never do.

## [0.5.2] - 2026-08-28

### Added
- **Light and dark themes for the web UI, with a switcher in the header.** The interface had exactly one appearance — a light one — hardcoded as 297 Tailwind palette utilities across nine components, which made an audit tool that people keep open all day unusable next to a dark editor. Colour now goes through a semantic token layer: components name `bg-surface`, `text-fg-faint`, `border-line-input`, `bg-status-error`, `text-method-get-fg`, and `tailwind.config.js` maps each onto `rgb(var(--tr-*) / <alpha-value>)` over the channel triples defined in `resources/css/themes.css`. Keeping the variables as **channels** rather than finished colours is what preserves Tailwind's alpha modifiers (`bg-surface/50`); a variable holding `#ffffff` would have silently broken every one of them.
- `TRACING_UI_THEME` (default `system`) — the theme a visitor who has not chosen one is served. `system` follows `prefers-color-scheme` and switches with the OS; `light` and `dark` pin the interface. It is only the default: the switcher stores a per-browser choice under the `tracing.theme` key in `localStorage`, and that wins from then on. A value naming no theme the stylesheet defines falls back to `system` instead of reaching the markup, so a typo renders the default interface rather than an unstyled one.
- The stored choice is applied by an inline script in `<head>`, ahead of the stylesheet and of any markup. Applying it after the first paint is what makes a theme switcher flash the wrong theme on every single load, which is worse than having no dark theme at all.

Themes are keyed on a `data-theme` attribute rather than on Tailwind's `darkMode: 'class'`. That switch has exactly two states, and the next theme on the list ([#6](https://github.com/D076/laravel-tracing/issues/6)) is neither light nor dark — so the two-state mechanism would have had to be replaced the moment it was needed. `data-theme` also lets each palette declare its own `color-scheme`, without which a dark UI keeps light scrollbars, light autofill and a light date picker inside `<input type="date">`.

HTTP verbs carry their own token group (`method-get`, `method-post`, …) separate from response classes (`status-success`, `status-error`), even though several pairs are the same colour today. They answer different questions — "which verb" and "did it work" — and a theme that recolours the verbs must not be able to change what a green badge means.

The registry of themes lives in `resources/themes.json` and is read by both halves of the UI: the SPA renders the switcher from it, and `D076\Tracing\Support\Theme` validates `tracing.ui.theme` and the `localStorage` value against it. Adding a theme is therefore a palette block in `resources/css/themes.css`, one entry in `resources/themes.json`, and a rebuild — no component changes, which `tests/Unit/Ui/ThemeTokensTest.php` locks in by failing the build on any raw palette utility (`bg-gray-200`, `hover:text-red-500`) reappearing in `resources/js`.

- **`bimbo-pink` — a third theme, glossy pink, offered next to `light` and `dark`** ([#6](https://github.com/D076/laravel-tracing/issues/6)). Set it as the default with `TRACING_UI_THEME=bimbo-pink`, or pick it in the header switcher, where it carries a heart. The page sits on baby pink with white cards, the active `Incoming`/`Outgoing` pill and inline code blocks are cotton-candy pink, filter chips and inputs are outlined in blush and light up in bimbo pink on hover and focus, monospaced URLs read plum and a trace id dusty rose.
  Response classes keep their meaning: 2xx is still green and 5xx still red, only pastelised (mint on `#E1F5EE`, soft red on `#FCEBEB`). That is why HTTP verbs have had a token group of their own since 0.5.2 — `POST` turns bubblegum pink and `GET` lavender without touching what a green badge says about a response.
  Contrast was checked pair by pair rather than eyeballed, and two swatches from the issue were deepened to clear WCAG AA where they could not: the accent (white button text on `#FF4FA3` reaches only 3.04:1, so the fill is a deeper bimbo pink at 4.81:1) and `text-faint` (dusty rose `#D19BBC` reaches 2.30:1 on white, so a trace id — the smallest text in the table — sits on a deeper rose at 3.85:1). Every body-text pair is at least 4.5:1 and every badge at least 3:1; the original hues are unchanged, only their lightness.
  The theme is a palette block in `resources/css/themes.css` plus an entry in `resources/themes.json` — no component and no `tailwind.config.js` change, which is what the token layer introduced in 0.5.2 was built for.
- **`TRACING_UI_ENABLED_THEMES` — the themes an installation offers.** Empty, the default, offers everything the stylesheet ships; `TRACING_UI_ENABLED_THEMES=light,dark` offers those two, and an application that publishes the config can write the same list as an array under `tracing.ui.enabled_themes`. It exists because a theme is a matter of taste an operator may not want to hand out — a pink audit log is not every team's idea of a shared tool — and because the comma-separated env form works without publishing a config file at all.
  `system` is treated as what it is: not a palette, but the rule "follow `prefers-color-scheme`", which is a choice *between* light and dark. Offering it once dark is switched off leaves the visitor a control that changes nothing, so its availability is **inferred rather than configured** — `resources/themes.json` gives it `"requires": ["light", "dark"]`, and any theme with a non-empty `requires` disappears the moment one of the themes it derives from does. `TRACING_UI_ENABLED_THEMES=system,light` therefore offers `light` alone.
  The result can never be empty: a list of nothing but unknown ids, or `system` with nothing left to derive from, falls back to `light`, since an interface with no palette is a worse outcome than a configuration that was ignored. The default theme is reconciled against the same list — `tracing.ui.theme` naming a withdrawn theme opens on `system` when that is available and on the first available theme otherwise, which is a stated rule rather than whatever the array order happened to be. The visitor's stored choice is validated against the narrowed list as well, so a theme switched off after someone picked it stops applying on their next load instead of surviving in `localStorage` outside the operator's control. With one theme left the switcher is not rendered at all.
  Withdrawing a theme does not shrink `app.css` — every palette is compiled into the bundle regardless, because stripping one would mean generating the stylesheet per installation at runtime. The key decides what the interface *offers*, not what it downloads; `docs/configuration.md` says so explicitly.

### Changed
- **The default masking lists now cover the whole token family — this changes what gets written to the database out of the box.** A body field is matched by its **exact** key name, never as a substring, so the `token` entry in the shipped defaults did nothing for `access_token`, `refresh_token`, `api_token`, `api_key` or `client_secret`: on an installation running those defaults all five were stored in clear text, which was confirmed against live data. All five have been added to **all four** body lists — inbound request, inbound response, outbound request, outbound response — and `private_key`, previously only on the inbound request list, now covers the other three as well. `password_confirmation` and `current_password` stay on the inbound request list alone: they are things a client *sends*, and a response echoing one is a bug of its own rather than a case to plan for.

  **An application that has published its config is not affected** and keeps exactly what its own file says. Masking lists are read from the published config *whole* and are never merged element-by-element with the package's — that is the behaviour settled in 0.5.0, and it is what stops a deliberately shortened list from being silently repopulated. Copy the new keys across by hand if you want them.

  Nothing is retroactive either: masking happens on write, so rows already in the database keep the values they were stored with. The README described headers and body fields as one list of "common secrets", which is where the belief that `authorization` masks a body field came from; the two are now listed separately, and both the README and `docs/configuration.md` state that matching is by whole key name.

- The reading surface behind JSON and code (`--tr-surface-code`) is now a token of its own, split off from `--tr-surface-sunken`. The two had shared one value because a grey palette can serve both roles: sunken picks an element *out* — the active tab, a hovered row — while a code surface sits *behind* a wall of monospace and wants the opposite. A saturated palette cannot do both from one value, which is what the pink theme exposed. Light and dark are unchanged, both tokens holding what `sunken` held before; a theme must now declare the extra token, and the token-set test says so if it does not.
- `tests/Unit/Ui/ThemeTokensTest.php` now checks that **every** registered theme defines the **whole** token set and declares a `color-scheme`, not merely that it has a palette block. A token a theme forgets does not fall back to something neutral — it falls back to the `:root` value, which is the light palette, so a single missing line renders one grey border or one dark-on-dark label in an otherwise correct theme. That is the cheapest kind of bug to ship and the most expensive to notice, and it now fails the suite instead.

### Fixed
- **`ip_address` recorded the reverse proxy instead of the client** on every application deployed behind one. `TracingMiddleware` read `$request->ip()` in `handle()`, and the service provider registers the middleware with `prependMiddleware()` — deliberately, so a trace spans the entire request — which puts it at the very front of the global stack, *ahead of* `TrustProxies`. At that moment no proxy is trusted yet and `ip()` falls back to `REMOTE_ADDR`. Behind traefik that produced records reading `ip_address = 172.21.0.2` while the `X-Forwarded-For` header stored on the same row named the real client, so the record contradicted itself. The capture has moved to `terminate()`, which runs after the whole stack against the same `Request` object and is already where the resolved route and the authenticated user are read. `prependMiddleware` is unchanged. An application that trusts no proxies records precisely what it did before, and a forwarded header arriving from an untrusted source is still ignored — the trust decision stays entirely with the host application's `TrustProxies` configuration. Existing rows are not rewritten.
- **`body_params` restated the query string as body fields** on every `POST`/`PUT`/`PATCH` that carried one. `captureBody()` built the body with `$request->except(...)`, and `except()` reads `$request->all()` — which in Laravel is the query string *merged with* the body. A live request carrying `source`, `utm[...]` and `flags[]` in the URL alongside `order[...]`, `lines[]` and `customer[...]` in a form-encoded body was recorded with all six groups sitting in `body_params`. For an audit log that is a false statement about the request: the record claimed the client sent in a body what it sent in a URL, and the package has a `query_params` column precisely because the two are meant to be told apart. The duplicates also spent the `max_body_size` budget twice, so a long query string could push a genuine body over the limit and get it truncated for nothing. The body is now read as a body — `$request->json()->all()` for a JSON request, `$request->request->all()` otherwise. The branch matters: `$request->request` is empty for a JSON body, so reading it alone would have stopped recording JSON bodies altogether. Everything around it is unchanged — uploaded files are still dropped, an empty result is still stored as `null`, and only `POST`/`PUT`/`PATCH` are captured. Backwards compatible in shape: same column, same type, same API. What changes is the content — query parameters are no longer duplicated there, and are found in `query_params` and `url` as they always were. Existing rows are not rewritten.
- **The UI bundles were cached for a year at a URL that never changes.** `/tracing/assets/app.js` and its stylesheet were served with `Cache-Control: public, max-age=31536000, immutable` and no `ETag` or `Last-Modified` to fall back on. `immutable` instructs the browser not to re-ask *even on a reload*, so after a package upgrade a visitor kept the previous bundle for up to a year and an ordinary F5 could not shift it — clearing site data was the only cure. Those headers are only truthful on a URL that names its own contents, and `resources/vite.config.js` builds under fixed names on purpose, because the controller serves the files by name.

  The shell now links each bundle as `app.js?v=<hash>`, and `asset()` answers `immutable` only when the request carries the version it is actually serving. An unversioned or outdated URL gets `no-cache, must-revalidate` together with an `ETag`, so it revalidates into a 304 instead of a full download and can never freeze again.

  The version is a hash of the file's own bytes (`D076\Tracing\Support\AssetVersion`), deliberately neither the package version nor `filemtime`. The package version stands still where it matters most — a path repository symlinked into an application while the SPA is being worked on, where the bundle changes on every build — and `filemtime` moves without a byte changing on `composer install`, archive extraction and deploy copies alike, which would invalidate every client's cache for nothing while still missing a genuine change that preserved the timestamp. The hash is computed once per file per process, so a rebuild during a long-lived Octane worker's life is picked up when it next boots.

## [0.5.1] - 2026-08-28

### Added
- Outgoing request details now show **query parameters** as a block of their own, the way inbound requests have always shown them, instead of leaving them buried in a long URL ([#4](https://github.com/D076/laravel-tracing/issues/4)). An API called only over `GET` with a dozen filtering parameters is readable again.
- A body sent as `application/x-www-form-urlencoded` is displayed as **fields rather than one line** ([#5](https://github.com/D076/laravel-tracing/issues/5)); the same applies to a form-encoded response body. The raw string stays one click away behind a *Raw* toggle, since for an audit trail the exact bytes that went on the wire are sometimes the point. A body cut at `max_body_size` is labelled *truncated*, because its last field is incomplete and the parsed view would otherwise state it as fact.

Neither is a schema change: the URL and the bodies are already stored raw, so `GET /tracing/api/outgoing/{id}` derives `query_params`, `request_body_params` and `response_body_params` when it reads the record. No migration to run, and records written by earlier versions display the same way. Parsing goes through PHP's `parse_str`, matching how the inbound side records the identical syntax, so bracket nesting and repeated keys look the same on both halves of a trace. Masking is unaffected — it happens on write, so a masked field shows its `[REDACTED]` placeholder in the parsed view too.

## [0.5.0] - 2026-07-31

### Added
- `only_paths` — an inbound route **allowlist**, the mirror image of `ignore_paths`. Empty (the default) keeps today's behaviour: everything is traced except `ignore_paths`. Non-empty flips the rule and traces **only** matching routes, which makes "audit the API and nothing else" a two-line config change instead of an ever-growing exclusion list. `ignore_paths` is still applied and subtracted from the allowlist, so `only_paths => ['api/*']` with `ignore_paths => ['api/health']` traces the API minus its health check. Same `*` wildcard and same `Request::is()` matching as `ignore_paths`; as with an ignored path, being outside the allowlist suppresses only the recording — `trace_id`, `Context` propagation and the `X-Trace-Id` header are unaffected.

### Fixed
- Binary payloads (images, PDFs, gzip streams, raw digests) were converted into pseudo-Cyrillic garbage instead of being stored as the `[non-UTF-8 body, N bytes]` marker. The cause was charset **detection**: Windows-1251 leaves exactly **one** byte of 256 undefined, so `mb_detect_encoding(strict: true)` accepted almost any blob and `mb_convert_encoding` mangled it, roughly doubling its size on the way. Since outbound `store_response_body` defaults to `true` and — unlike the inbound side — has no JSON-only guard, every binary response was written as inflated noise up to `max_body_size`. Detection has been **removed** rather than fenced off; see *Changed* below.
- Request parameters, headers and `user_agent` in a declared legacy charset were stored as U+FFFD instead of being converted. `BodyEncoding::toUtf8()` only applied to bodies, but these arrive already parsed, so a client sending `charset=windows-1251` put its bytes inside individual values, where only the `cleanForStorage()` backstop saw them — the record survived, its text did not. The new `toUtf8Deep()` converts values, nested values and keys using the charset the client declared.
- `?date_from=` / `?date_to=` with an unparseable value returned HTTP 500. The raw string reached the driver and PostgreSQL answered `invalid input syntax for type timestamp`. The same happened for a well-formed `date_to` that included a time, because ` 23:59:59` was appended unconditionally. Both are fixed; the accepted formats are now explicit, see *Changed*.
- `%` and `_` in a search term were treated as LIKE wildcards: `?search=%` matched **every** row (and, via `?payload=`, ran the expensive scan to do it), while `a_test` matched `a.test`. Terms are now escaped and matched literally, using `!` as the LIKE escape character because a literal backslash inside `ESCAPE '...'` is spelled differently on PostgreSQL and MySQL. Applies to `?search=`, `?payload=` and the `route_path` filter.
- An oversized `body_params` was discarded at a third of its budget when it contained non-ASCII text. The size was measured on `json_encode()` **without** `JSON_UNESCAPED_UNICODE`, counting every Cyrillic letter as its 6-byte `\uXXXX` escape — while PostgreSQL and MySQL normalize those escapes away on write, so the escaped form was never what got stored. At the default limit a Cyrillic payload was dropped past roughly 3 300 bytes' worth of text instead of 10 000.
- An empty `TRACING_MAX_BODY_SIZE` (or `TRACING_OUTGOING_MAX_BODY_SIZE`) — e.g. a bare `TRACING_MAX_BODY_SIZE=` left over in `.env` — silently disabled truncation instead of falling back to the default. `env()` returns `''` (not the default) for a key that is present but empty, and the previous `(int) env(...)` cast turned that into `0`, which this release redefines to mean *do not truncate* (see *Changed*). On MySQL, where the payload columns are `text` (capped at 65535 bytes), an unbounded response over that size failed the `INSERT` with `Data too long`, and since `TracingService::persist()` logs and swallows that failure, the entire trace record was silently lost, not just its body. Both keys now fall back to the documented default of `10000` unless the env value is genuinely numeric; an explicit `0` is still honoured as the opt-out from truncation.
- A **multi-value charset** would have re-enabled the very detection this release removes, turning binary back into fabricated pseudo-text. `Content-Type` is now parsed with `Symfony\Component\HttpFoundation\HeaderUtils` rather than an ad hoc `charset=` regex, and unlike the regex it also accepts a charset containing a comma — a malformed `charset="UTF-8, Windows-1251"`, or `windows-1251, text/plain` as produced by PSR-7's `getHeaderLine()` joining two `Content-Type` headers. `mb_convert_encoding()` treats its charset argument as a comma-separated *detection list*, not a single label, once it holds more than one name. `BodyEncoding::isConvertible()` therefore rejects any charset that is not a single RFC-token-shaped label before it reaches `mb_convert_encoding()`; such a value is treated as undeclared, producing the `[non-UTF-8 body, N bytes]` marker for a body or leaving a parameter value as-is.
- The 422 above also holds when the host application runs with `Carbon::useStrictMode(false)`, where a naive implementation answers HTTP 500 instead. `Carbon::rawCreateFromFormat()` is declared `?static` and only throws `InvalidFormatException` in strict mode (Carbon's default); with strict mode off it returns `null`, which a round-trip check would then call `format()` on. The loop over `DATE_FORMATS` treats a `null` result the same as a caught exception and tries the next format.
- `?date_to=` is inclusive of the unit it names, symmetrically across the accepted formats: the upper bound is rounded up to the end of whichever unit the matched format leaves unspecified — end-of-day for `Y-m-d`, end-of-minute for `Y-m-d H:i`, unchanged for the fully-specified `Y-m-d H:i:s`. Rounding only the bare date, as an earlier cut of this release did, made `?date_to=2026-01-01 10:30` silently drop every record between `10:30:00` and `10:30:59`.
- A published config (`vendor:publish --tag=tracing-config`) that predates a nested config key added since would read that key as `null` rather than getting the package default, because `ServiceProvider::mergeConfigFrom()` does a flat `array_merge()`: it fills in a *missing top-level* key, but a nested array present in the user's file (`outgoing`, `ui`, `rate_limit`, `tags`) replaces the package's whole sub-array, including keys the user's copy doesn't know about yet. This has not misfired to date only because none of those sub-arrays has gained a key since `v0.1.0`, but the failure mode for the next one added would range from silent (a boolean read as falsy) to a hard failure (`Route::middleware(null)`, `Limit::perMinutes(0, 0)` — a permanent 429, `foreach (null as ...)`). `TracingServiceProvider` now merges its own config: top-level keys are filled in when missing (as before), and one level of *associative* sub-array is additionally filled in key-by-key, while a *list* value is always taken from the published config as a whole — never merged element-by-element, which is what `replaceConfigRecursivelyFrom()`/`array_replace_recursive` would do and which would make a user-shortened list (e.g. a trimmed `ignore_paths`) impossible, since the package's own trailing elements would survive at their original index.
- `?status_group=` returned **every** record instead of none when its value named no known status class. Each arm of the `match()` over the requested groups fell through to a no-op `default`, so no clause was added inside the nested `where()` — and Laravel drops an empty nested where entirely, leaving the query unfiltered. The endpoint answered 200 over the whole table, which reads as "found everything" rather than "found nothing", and is the same failure `stringQuery()` and `dateQuery()` are documented to prevent. A falsy value (`?status_group=0`) skipped the filter for a second reason: the raw parameter was read for truthiness, exactly as `?search=0` was before 0.4.0. A value naming no known class now selects nothing; an absent or blank value (`?status_group=`, `?status_group=,,`) still applies no filter at all; an unrecognized group mixed in with a recognized one (`5xx,9xx`) is ignored rather than widening the result. Entries are also trimmed, so `4xx, 5xx` works, and a nested array (`?status_group[][]=`) is skipped instead of being cast to the literal `"Array"`. The block was duplicated in `index()` and `outgoingIndex()` and had already drifted between them; both now call one `applyStatusGroupFilter()`.

### Changed
- **Charset detection removed; encoding is now taken only from what the sender declared.** Previously an undeclared non-UTF-8 body was guessed at with `mb_detect_encoding`, and the guess was fenced off with a media-type list plus NUL and control-byte heuristics. The fence leaked on every review: high-byte-only blobs (signatures, encrypted chunks) carry no control bytes at all, a `multipart` body with a binary part slipped through, and a short body could be condemned over one stray byte. The rule is now:

  | Input | Stored as |
  |---|---|
  | Valid UTF-8 | unchanged |
  | Declared charset that converts | converted |
  | Anything else — body | `[non-UTF-8 body, N bytes]` |
  | Anything else — parameter, header, user agent | left as-is, then U+FFFD via the storage backstop |

  **The trade-off:** legacy text from a sender that declares no charset is no longer recovered — it becomes the marker, or U+FFFD, where it previously came out as readable Russian. That is the price of never fabricating text, which for an audit trail is the worse failure: U+FFFD is visibly broken, while invented Cyrillic is indistinguishable from the truth. Query parameters are the weak spot, since a `GET` carries no `Content-Type`. A charset parameter is still believed even if the payload turns out to be binary — a single-byte charset converts anything — because the alternative is a media-type blocklist, i.e. guessing again.

  The behaviour is pinned by property tests over text and binary corpora and randomized input rather than hand-picked byte strings; three earlier rounds of tests here passed only because their inputs happened to contain `0x98`, the one byte cp1251 rejects.
- `?date_from=` / `?date_to=` now accept only `Y-m-d`, `Y-m-d H:i` and `Y-m-d H:i:s` and answer **422** on anything else, including a nonexistent date (`2026-02-31`) and an array (`?date_from[]=`). Parsing leniently instead would have traded the old 500 for a quieter failure: `Carbon::parse()` reads `x` as a military timezone and silently shifts the window by hours, and treats a mistyped year as a valid date that matches nothing — both answering 200 over a wrong result set. The web UI already sends `Y-m-d`, so only hand-written API calls are affected.
- `max_body_size` and `outgoing.max_body_size` remain denominated in **bytes** — the config comment previously described them as characters, which was never what the code did. Bytes are what storage, the column limit and the queue payload actually cost, so the comment was corrected rather than the code. The cut still lands on a character boundary (`mb_strcut`) and never splits a multi-byte character. Multi-byte text consequently keeps proportionally fewer characters, because it occupies proportionally more disk; raise the limit if you want more of such bodies kept. Documented alongside the MySQL `text` ceiling of 65535 bytes, above which a write fails and — since the failure is logged and swallowed — costs the whole trace record.
- A **non-positive** `max_body_size` / `outgoing.max_body_size` now means *do not truncate* rather than *keep nothing*. `0` (or `null`) previously put every body over budget: response bodies were stored as the bare `...[truncated]` marker and every `body_params` was replaced with `{"_truncated": true, "_original_size": N}`. That is also what a config key missing from an incomplete published file casts to, so the failure mode was silent data loss on a misconfiguration. `0` is now the documented way to store bodies whole — mind the MySQL column ceiling above before using it.
- Defaults are no longer duplicated as the second argument of `config()` calls in `src/`; the published config file is the single source of truth for them. Two of those code-side defaults contradicted the shipped config (`store_response_body` read as `false` against a config of `true`, both `retention_days` as `0` against `30`) and were dead — `mergeConfigFrom()` means the keys always exist — but they read as the real defaults.

## [0.4.0] - 2026-07-24

### Added
- **Tags on traced records** — attach arbitrary, application-defined tags and search by them, the equivalent of `Telescope::tag()`. Exposed through the new `D076\Tracing\Facades\Tracing` facade with full control over the set: `tag()` (add on top), `setTags()` (replace), `untag()` (remove), `clearTags()`, `tags()`. Tags live in `Illuminate\Support\Facades\Context` like `trace_id`, so they need no wiring: a tag set during a request is attached to the inbound record *and* to every outbound `Http::*` call made within it, and is inherited across job/event/chain boundaries and from CLI entry points. Records are searchable via `?tag=` (exact, AND across multiple) and via the standard `?search=` box (substring); the web UI renders tags as chips on list rows and detail pages, and clicking a chip applies the exact-tag filter.
- `TRACING_TAGS_IN_LOGS` (default `false`) — tags are stored in the **hidden** `Context` by default, so they propagate everywhere but never appear in application log records. Set to `true` to put them in the visible `Context` and have Laravel add them to every log entry.
- Additive migration adding a nullable `tags` JSON column to `tracing_requests` and `tracing_outgoing_requests`, plus a **GIN index** on each on PostgreSQL (a btree index cannot serve JSON containment). Existing installations upgrade without touching already-applied migrations; with no tags in use, behaviour and storage are unchanged.
- **Deep search across captured payloads** (`?payload=`, "Deep search" input in the UI) — searches request/response bodies, query parameters, and the recorded exception, on top of everything the standard search already covers. This makes incident investigation possible when all you have is business data, e.g. finding every trace containing a phone number regardless of endpoint. It is a strict superset of `?search=`, combines with all other filters, and works on both the inbound and outbound endpoints.

  Deliberately bound to a **separate input that runs on Enter** rather than folded into the main search box: `LIKE '%term%'` cannot use a btree index, so scanning payload columns on a package that captures 100 % of traffic is expensive. Isolating it means the cost is paid only on explicit request, and the standard search stays cheap. Narrowing the date range remains the effective way to bound the scan.

### Fixed
- Search terms containing non-ASCII characters returned nothing on PostgreSQL when any letter was uppercase. The term was lowercased with PHP's byte-wise `strtolower()`, which leaves Cyrillic and other multi-byte letters untouched, while SQL `lower()` on the column does fold them — so `%Москва%` was compared against `москва` and never matched. Now uses `mb_strtolower()`. Affected the standard search, the new payload search, and the `route_path` filter. MySQL masked the bug behind its case-insensitive collation, so it was only visible on PostgreSQL.
- Passing an array for a string filter (`?search[]=x`, `?payload[]=x`, `?method[]=GET`, …) returned HTTP 500 instead of being ignored.
- A search term of `"0"` was silently dropped — because `"0"` is falsy in PHP, the filter never applied and the endpoint returned **every** record, which reads as "matched everything" rather than "matched nothing".
- A nested array in `?tag[][]=` raised an "Array to string conversion" warning and filtered by the literal string `"Array"`.
- The standard search did not cover all headers it appeared to: outbound records ignored `request_headers` entirely, and `response_headers` were not searched on either table. Both are now included on both endpoints. Header columns are small, so the standard search stays inexpensive.
- Web UI: changing a filter while on page 2+ issued two requests instead of one (both the filter watcher and the page-reset watcher triggered a load). Harmless before, but it meant running the expensive deep search twice.

## [0.3.3] - 2026-07-24

### Fixed
- `TRACING_ENABLED=false` is now a true master switch. Previously it only stopped inbound database recording: `TraceIdMiddleware` still ran and pushed a `trace_id` into `Illuminate\Support\Facades\Context` — which Laravel automatically attaches to every log record, so the id leaked into the host app's logs even with tracing "off" and no record was ever written. Outbound requests also kept being recorded, because they were gated by the independent `TRACING_OUTGOING_ENABLED` rather than the master flag. With the package disabled, the service provider now short-circuits its entire runtime: no middleware, no `Context`/`trace_id`, no `X-Trace-Id` response header, no outbound recording, and no UI routes. Migrations and config publishing stay registered, so the package still installs and `migrate`s while disabled.

### Changed
- **Behavior change** (only affects `TRACING_ENABLED=false`): the `X-Trace-Id` response header is no longer emitted when tracing is disabled. Previously the header was set on every response regardless of the flag. If your app disables tracing but relies on `X-Trace-Id`, keep `TRACING_ENABLED=true` and exclude routes via `ignore_paths` instead.

## [0.3.2] - 2026-07-23

### Fixed
- Non-UTF-8 response/request bodies no longer break persistence on a strict backend. Bodies are now normalized to UTF-8 before masking and truncation via the new `Support\BodyEncoding` helper: a body in a legacy charset declared in `Content-Type` (e.g. `windows-1251`) is transcoded, an undeclared legacy charset is detected best-effort, and a body that is neither valid UTF-8 nor decodable is stored as a `[non-UTF-8 body, N bytes]` marker. Previously such a body was written verbatim and a PostgreSQL UTF8 database aborted the whole `INSERT` with `invalid byte sequence for encoding "UTF8"`, so the record was lost (`Tracing: failed to persist ...`). SQLite/MySQL stored the raw bytes silently and were unaffected.
- Body truncation no longer splits a multi-byte UTF-8 character. `substr()` cut on a byte boundary and could leave a dangling lead byte (e.g. a Cyrillic body truncated mid-character), which a strict backend then rejected. Truncation now uses `mb_strcut`, keeping the byte budget while respecting character boundaries.
- Incoming records are no longer dropped when a JSON column (`body_params`, `query_params`, request/response headers) contains a non-UTF-8 byte. Eloquent's `array` cast `json_encode`d such a value to `false` and threw `JsonEncodingException`, which `persist()` swallowed — losing the record on **any** driver. Payloads now pass through `BodyEncoding::cleanForStorage()`, substituting invalid bytes with U+FFFD before the write.

## [0.3.1] - 2026-06-11

### Fixed
- Outbound `4xx`/`5xx` responses are now recorded even when the caller opts into Guzzle's `http_errors => true` (non-default). In that mode Guzzle turns the response into a rejected promise that Laravel marshals without dispatching a client event, so the `ResponseReceived`/`ConnectionFailed` listeners introduced in 0.3.0 never saw it. `OutgoingTracingMiddleware` sits inside Guzzle's `http_errors` middleware, still observes the fulfilled response, and records it. The default `http_errors => false` mode is unchanged — events remain the sole recorder, and there is no double-recording (verified for `2xx`, `4xx`/`5xx`, and connection failures under both modes).

## [0.3.0] - 2026-06-11

### Fixed
- Outbound requests that fail **without a response** — connection refused, DNS failure, read/connect timeout — are now recorded in `tracing_outgoing_requests`. Previously the Guzzle middleware attached its recording via a promise `->then(onRejected)` callback, which only observes *rejected* promises and silently missed exceptions thrown synchronously inside the handler stack, so these failures were never logged. Ordinary `4xx`/`5xx` responses were unaffected and are still recorded.
- Octane: inbound `duration_ms` is now measured from a per-request timestamp instead of the process-global `LARAVEL_START` constant. Under a long-lived Octane worker the constant is immutable and kept the first request's start time, so every later request reported an inflated duration. Timing under PHP-FPM is unaffected.

### Changed
- Outbound request **recording** moved from the `Http` facade global Guzzle middleware to the HTTP client events `ResponseReceived` and `ConnectionFailed`. The framework guarantees one of these fires for every request (any status, plus connection failures), so coverage no longer depends on Guzzle promise/handler-stack semantics. `OutgoingTracingMiddleware` is retained solely to inject the `X-Trace-Id` header when `outgoing.propagate_trace_id` is enabled — request mutation is only possible at the middleware level, not from an event.
- `exception_class` for connection-level failures is now `Illuminate\Http\Client\ConnectionException` (previously the underlying Guzzle class such as `GuzzleHttp\Exception\ConnectException`). Anything querying or alerting on that column value should account for the new value.

### Notes
- Edge case: if the host app explicitly opts into Guzzle's `http_errors => true` (not Laravel's default), a `4xx`/`5xx` becomes a rejected promise for which Laravel dispatches neither `ResponseReceived` nor `ConnectionFailed`, so that response is not recorded. With the default `http_errors => false` every response is captured. *(Fixed in 0.3.1.)*

## [0.2.4] - 2026-06-11

### Added
- Web UI: list filters, sorting, and pagination are now persisted in the URL query string. They survive navigation to a detail view and back (browser **Back**), and a filtered list can be bookmarked or shared via its URL.
- Web UI: detail views can be opened in a new tab — Cmd/Ctrl/Shift + click or middle-click on a table row, or the **↗** link that appears on row hover.

## [0.2.3] - 2026-05-28

### Changed
- Service provider is no longer auto-discovered. Register `D076\Tracing\Providers\TracingServiceProvider` explicitly in `bootstrap/providers.php`.

## [0.2.2] - 2026-05-27

### Added
- `phpbench` benchmark suite (`benchmarks/`) measuring per-request overhead in four modes; results table added to README.
- PHPStan level 6 via `larastan/larastan` (`phpstan.neon`); added as a CI step before tests.
- Generic array types (`array<string, mixed>`, `list<string>`, `array<string, list<string>>`) on all public service and model signatures.

### Fixed
- `TracingApiController`: `getDriverName()` was called inside a `where()` closure on `ConnectionInterface`, which does not declare that method. Moved outside the closure where the Eloquent Builder's concrete connection type is known.

## [0.2.1] - 2026-05-27

### Added
- GitHub Actions CI (`tests.yml`): PHP 8.3/8.4/8.5 × Laravel 11/12/13 matrix on SQLite, plus a separate cross-DB matrix for PostgreSQL and MySQL.
- README badges: CI status, PHP version, Laravel version, license.
- Both models override `getConnectionName()` so the custom connection is used automatically without calling `::on(...)` explicitly.
- Both migrations implement `getConnection()` so `php artisan migrate` creates `tracing_requests` and `tracing_outgoing_requests` on the configured connection, not the default one.
- `docs/configuration.md` gains a dedicated **"Separate database for tracing"** section with setup instructions, a docker-compose example, and an explanation of what changes under the hood.

### Fixed
- `TRACING_DB_CONNECTION` now correctly routes **all** tracing operations — writes, reads (UI + API), and pruning — to the configured connection. Previously only inserts used the custom connection; `prunable()` queries and the UI API silently fell back to the default database.

## [0.2.0] - 2026-05-27

### Added
- `trace_id` propagation across job boundaries via `Illuminate\Support\Facades\Context`. Queued jobs, broadcasted events, chains, and retries automatically inherit the parent request's `trace_id` — no setup required in the host app.
- Response body capture and masking for inbound requests (`tracing.store_response_body`, `tracing.masked_response_body_params`). Masking is applied before truncation.
- Response body masking for outbound requests (`tracing.outgoing.masked_response_body_params`).
- Named rate limiter `tracing-api` for the UI API, configurable via `tracing.rate_limit.*`. The host app can override it by registering its own `RateLimiter::for('tracing-api', ...)`.
- `cross-db` test group exercising the search endpoint against PostgreSQL, MySQL, and SQLite.

### Changed
- Documentation split from a single README into `docs/architecture.md`, `docs/configuration.md`, and `docs/database.md`. README now contains only a quick start.
- Minimum PHP version lowered to **8.3** (was 8.4). No code changes required.

### Security
- Outbound `application/x-www-form-urlencoded` request bodies now go through the same masking pipeline as JSON bodies. Previously sensitive fields (e.g. `password=...`) sent via `Http::asForm()` were stored in `tracing_outgoing_requests.request_body` in plain text. Masked keys are reused from `tracing.outgoing.masked_body_params`; nested fields follow PHP bracket syntax (`user[password]=...` ↔ `user.password`).

## [0.1.0] - 2026-05-25

Initial release.

### Added
- Inbound HTTP request tracing: captures method, URL, headers, body, response status, and duration into `tracing_requests`.
- Outbound HTTP request tracing via `Http` facade global middleware, persisted to `tracing_outgoing_requests`.
- `trace_id` (UUID7) generation; `X-Trace-Id` response header on all responses; optional propagation of `X-Trace-Id` to outbound requests.
- Configurable masking for headers and body parameters (dot-notation supported) on inbound requests and outbound JSON bodies.
- Two persistence modes: synchronous (`database`) and asynchronous (`queue`).
- Optional Vue SPA at `/tracing`, gated by the `viewTracing` ability (local environment by default).
- Retention via `php artisan model:prune` (`tracing.retention_days`, default 30).
- Cross-database SQL compatibility: PostgreSQL, MySQL, SQLite.

[0.5.2]: https://github.com/d076/laravel-tracing/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/d076/laravel-tracing/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/d076/laravel-tracing/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/d076/laravel-tracing/compare/v0.3.3...v0.4.0
[0.3.3]: https://github.com/d076/laravel-tracing/compare/v0.3.2...v0.3.3
[0.3.2]: https://github.com/d076/laravel-tracing/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/d076/laravel-tracing/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/d076/laravel-tracing/compare/v0.2.4...v0.3.0
[0.2.4]: https://github.com/d076/laravel-tracing/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/d076/laravel-tracing/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/d076/laravel-tracing/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/d076/laravel-tracing/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/d076/laravel-tracing/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/d076/laravel-tracing/releases/tag/v0.1.0
