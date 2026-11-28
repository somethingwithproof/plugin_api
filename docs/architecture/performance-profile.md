<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later
-->

# Performance Profile and Algorithmic Audit

This document records the algorithmic complexity audit, denial-of-service bound verification, and measured PHP runtime benchmarks for `plugin_api` before and after targeted optimizations.

## Measurement Environment

- **Runtime:** PHP 8.4.25 (CLI, NTS, OPcache enabled, arm64 Darwin)
- **Timers and memory probes:** `hrtime(true)` wall-clock nanoseconds and `memory_get_peak_usage(true)` peak allocated memory
- **Harness:** Warmed-up tight loops executing production classes directly against `InMemoryApiRepository` fixtures (zero external I/O)

## Complexity and ReDoS Audit Summary

| Component | Time Complexity | Space Complexity | Regex / Recursion Safety |
| --- | --- | --- | --- |
| `Aip\FilterParser` | $O(N)$ bytes, $N \le 2048$ | $O(T)$ tokens, $T \le 128$ | Zero regexes; iterative `OR`/`AND` loops; recursive `(`/`NOT` capped at `MAX_DEPTH = 16` |
| `Aip\SqlFilterCompiler` | $O(K)$ SQL compile; $O(F + R \cdot K)$ batch filter | $O(K)$ bound params | Anchored `SAFE_COLUMN_PATTERN` with bounded 256-entry cache; explicit `MAX_DEPTH = 64` guard |
| `Http\Router` | $O(1)$ route dispatch (lazy handler map) | $O(1)$ per request | Bounded `explode('/', $relative, 6)`; anchored custom-method regex `{1,31}` |
| `Aip\ResourceName` | $O(1)$ (max 6 segments, 600 bytes) | $O(1)$ | Bounded `explode('/', $trimmed, 8)`; anchored character classes; 64-bit integer overflow check in `intId()` |
| `OpenApi\OpenApiGenerator` | $O(C + E)$ core + extension collections | $O(C + E)$ | No regexes; direct in-place path insertion and copy-on-write shared constants |
| `Extension\ResourceRegistry` | $O(E \log E)$ first `all()`, $O(1)$ cached; $O(E)$ hook dispatch | $O(E)$, $E \le 128$ | Atomic per-hook local batch staging (eliminates $O(E^2)$ cloning) |
| `Aip\PageToken` | $O(1)$ HMAC-SHA256 encode/decode | $O(1)$ | `MAX_TOKEN_LENGTH = 512` and 43-char signature check before base64/HMAC |
| `Aip\FieldMask` | $O(M + F)$ parse and validate | $O(M)$, $M \le 64$ | `MAX_LENGTH = 2048`, bounded `explode`, linear non-backtracking dot-separated regex |
| `Aip\Etag` | $O(S)$ serialization + SHA-256; $O(1)$ `If-Match` | $O(S)$ | Scalar short-circuit in `canonicalize()`; `If-Match` capped at 32 tags |
| `Auth\TokenService` | $O(B + C)$ prefix bucket + CIDR checks | $O(1)$ | 67-byte length pre-check before fixed-width token regex; constant-time `hash_equals()`; zero-alloc `strncmp()` CIDR prefix match |
| `Auth\RateLimiter` | $O(1)$ fixed-window SHA-256 bucket | $O(1)$ | No regexes |
| `Aip\IdempotencyStore` | $O(S)$ payload canonicalization + SHA-256 | $O(S)$ | 36-byte length pre-check before anchored UUIDv4 regex; scalar short-circuit in `sortRecursive()` |
| `Aip\OrderByParser` | $O(K)$, $K \le 8$ clauses | $O(K)$ | `MAX_LENGTH = 512`; bounded `explode(..., 10)` and `preg_split(..., 3)` |
| `Aip\OperationManager` | $O(1)$ per CRUD/state transition | $O(1)$ | No regexes; CSPRNG `random_bytes(16)` UUIDv4 |

## Hotspots Discovered and Fixed

1. **`OpenApiGenerator::generate()` $O(N^2)$ path merging:** Calling `array_merge($paths, self::extensionPaths($descriptor))` inside the extension loop copied the entire accumulated paths map on every plugin. Replacing it with direct in-place mutation (`appendExtensionPaths()`) and copy-on-write shared error/parameter constants cut 100-resource generation latency by **54.1%** (2.18x faster).
2. **`FilterParser::tokenize()` per-character PHP loop and per-token HashTable allocation:** Lexing invoked `ctype_*()` per byte and allocated an associative array (`['type' => ..., 'value' => ...]`) per token. Switching to C-speed `strspn()`/`strcspn()` spans and parallel integer-type/string-value arrays, paired with a bounded safe-column cache in `SqlFilterCompiler`, cut combined parse+compile latency by **32.0%** (1.47x faster).
3. **Eager handler allocation in `Router::__construct()`:** Constructing all 9 core resource handlers on every shared-nothing HTTP request wasted 8 object allocations per call. Lazy instantiation via `CORE_COLLECTIONS` reduced cold construct+dispatch latency by **11.4%**.
4. **Recursive scalar calls in `Etag::canonicalize()` and `IdempotencyStore::sortRecursive()`:** Both helpers recursed on every scalar leaf field. Guarding recursion with `if (is_array($v))` cut `Etag::compute()` + `matches()` latency by **13.9%**.
5. **`ResourceRegistry::dispatchHooks()` $O(N^2)$ cloning and repeated sorting:** Cloning `$this` per hook duplicated the entire `$resources` map on every sibling plugin write. Staging only the active hook's descriptors in a local `$batch` map preserves atomic rollback at $O(k)$ cost, and caching `all()` makes repeated reads $O(1)$.
6. **Unbounded adversarial input splitting and scanning:** `ResourceName::parse()`, `Router::routePath()`, `PageToken::decode()`, `FieldMask::parse()`, `Etag::matches()`, and `OrderByParser::parse()` previously ran unbounded `explode()`, `preg_split()`, or HMAC/base64 decoding on oversized headers and query parameters. Adding strict length and split limits eliminated these CPU/memory exhaustion vectors (17x to 245x faster rejection).
7. **Database round-trips in `CactiApiRepository::getHealthStats()`:** Consolidated 6 separate `COUNT(*)` queries (which would have grown to 10 with the self-graphing telemetry counters) into 4 single-pass conditional aggregation queries.
8. **64-bit integer overflow in `ResourceName::intId()`:** Replaced regex-only matching with length and `PHP_INT_MAX` boundary validation so 20+ digit IDs fail closed with `400 INVALID_ARGUMENT` instead of saturating to `PHP_INT_MAX`.

## Measured Before vs After Benchmarks

### Standard Hot Paths

| Benchmark | Iterations | Before (ms) | After (ms) | Before (us/op) | After (us/op) | Change | Peak Memory |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `FilterParser::parse` + `SqlFilterCompiler::compile` | 50,000 | 819.13 | 556.94 | 16.38 | 11.14 | -32.0% (1.47x) | 2.00 MiB |
| `OpenApiGenerator::generate` (100 sibling plugins) | 500 | 104.90 | 48.12 | 209.79 | 96.25 | -54.1% (2.18x) | 4.00 MiB |
| `ResourceRegistry::dispatchHooks` (100 plugins) | 200 | 73.38 | 67.32 | 366.89 | 336.59 | -8.3% (1.09x) | 4.00 MiB |
| `Router` cold construct + single dispatch | 10,000 | 134.97 | 119.54 | 13.50 | 11.95 | -11.4% (1.13x) | 2.00 MiB |
| `Router::handle` warm (GET, nested graph, `:enable`) | 25,000 | 301.04 | 287.81 | 12.04 | 11.51 | -4.4% (1.05x) | 2.00 MiB |
| `Etag::compute` + `Etag::matches` | 25,000 | 88.53 | 76.22 | 3.54 | 3.05 | -13.9% (1.16x) | 2.00 MiB |
| `IdempotencyStore` validate + fingerprint + record + lookup | 10,000 | 25.73 | 24.74 | 2.57 | 2.47 | -3.8% (1.04x) | 2.00 MiB |
| `TokenService::authenticate` (SHA-256 + CIDR) | 10,000 | 27.86 | 28.52 | 2.79 | 2.85 | Within noise | 2.00 MiB |
| `PageToken::encode` + `PageToken::decode` | 25,000 | 88.35 | 89.63 | 3.53 | 3.59 | Within noise | 2.00 MiB |
| `ResourceName::parse` + `intId` | 50,000 | 50.23 | 51.62 | 1.00 | 1.03 | Within noise | 2.00 MiB |
| `RateLimiter::consume` | 25,000 | 15.96 | 16.82 | 0.64 | 0.67 | Within noise | 2.00 MiB |
| `OperationManager::create` + `complete` | 10,000 | 26.96 | 26.75 | 2.70 | 2.67 | Within noise | 2.00 MiB |
| `SqlFilterCompiler::filterRecords` (50 queries x 500 rows) | 25,000 rows | n/a (new) | 36.15 | n/a | 1.45 us/row | 691,568 rows/s | 2.00 MiB |

### Adversarial / DoS Inputs

| Adversarial Workload | Iterations | Before (ms) | After (ms) | Before (us/op) | After (us/op) | Speedup |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `ResourceName::parse` (2,000 slashes) | 2,000 | 30.44 | 1.74 | 15.22 | 0.87 | 17.5x |
| `PageToken::decode` (100 KB cursor) | 200 | 40.65 | 0.36 | 203.24 | 1.83 | 112.9x |
| `Etag::matches` (5,000-tag `If-Match`) | 200 | 136.92 | 1.03 | 684.61 | 5.15 | 132.9x |
| `FieldMask::parse` (2,000 fields) | 200 | 63.69 | 0.26 | 318.46 | 1.32 | 245.0x |
| `OrderByParser::parse` (2,000 words) | 200 | 18.10 | 0.25 | 90.50 | 1.25 | 72.4x |
| `FilterParser::parse` (20 nested parens) | 10,000 | 100.79 | 92.09 | 10.08 | 9.21 | 1.09x |

## Profile-Guided Tradeoffs Retained

During benchmarking, converting small lists (`<= 24` items) to hash maps with `array_fill_keys()` on every single call in `Principal::__construct()`, `FieldMask::validate*()`, and single-record `SqlFilterCompiler::matchesRecord()` caused measurable overhead (~8% to 14%) compared to C-level `in_array()` on PHP packed arrays. Those paths retain `in_array()` for small allowlists while using prebuilt hash sets for large allowlists (`> 24` entries) and batch `SqlFilterCompiler::filterRecords()`. Similarly, JIT-compiled PCRE `/^[A-Za-z0-9_-]+$/` outperformed `strspn()` with a 64-byte character mask in `PageToken::base64UrlDecode()`, so the anchored regex was kept behind the `MAX_TOKEN_LENGTH = 512` pre-check.
