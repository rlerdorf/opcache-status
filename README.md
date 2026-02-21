OPcache Status  [![Packagist](http://img.shields.io/packagist/v/rlerdorf/opcache-status.svg)](https://packagist.org/packages/rlerdorf/opcache-status)
---------------

A single-file OPcache status page for PHP 8.2+. Drop `opcache.php` into any directory and get a full overview of your OPcache configuration, cached scripts, and health — zero external dependencies.

![Screenshot](screenshot.png)

### Features

- **Status tab** — all `opcache_get_status()` metrics at a glance, plus preload script and user when `opcache.preload` is configured
- **Configuration tab** — current directives with changed values highlighted, inline documentation from php.net, human-readable formatting (memory sizes, time values, percentages), and a blacklisted paths section
- **Health tab** — seven checks (memory, keys, interned strings, JIT buffer, wasted memory, hit rate, file cache) with green/yellow/red/info indicators, progress bars, and tuning suggestions
- **Scripts tab** — sortable table of all cached scripts by hits, memory, or path with per-script cache invalidation on hover
- **Cache management** — reset the entire cache or invalidate individual scripts, with confirmation modals that warn about the impact of a full reset
- **Auto-refresh** — toggle a 5-second reload cycle with a live activity chart showing cache hits/s, misses/s, and memory usage over time
- **Donut charts** — memory, keys, hits, restarts, and conditionally JIT and interned strings
- **Squarified treemap** — interactive visualization of cached scripts by memory usage with drill-down navigation, inline and fullscreen modes
- **Dark mode** — automatic via `prefers-color-scheme`
- **Responsive** — CSS Grid layout adapts to narrow screens
- **Read-only mode** — set `$readonly = true` at the top of `opcache.php` to disable all cache modification actions (reset and invalidate)

Everything is self-contained in a single PHP file with inline CSS and vanilla JavaScript. No jQuery, no D3.js, no CDN scripts.

### Usage

Install the package:
```
composer require --dev rlerdorf/opcache-status dev-master
```

Drop it where you want it in your public folder:
```
ln -s vendor/rlerdorf/opcache-status/opcache.php ./public/
```

Open the file in your browser:
```
http://127.0.0.1:8000/opcache.php
```

If the Zend OPcache extension is not loaded, sample data from `data-sample.php` is shown automatically.

### Development

```
php -S localhost:8000
```

Then open `http://localhost:8000/opcache.php`. The fallback to sample data is based on `extension_loaded('Zend OPcache')`, not `opcache.enable`.

