<?php declare(strict_types=1);

if (!extension_loaded('Zend OPcache')) {
    require __DIR__ . '/data-sample.php';
}

if (isset($_GET['clear']) && $_GET['clear'] === '1') {
    opcache_reset();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

class OpCacheDataModel
{
    private const THOUSAND_SEPARATOR = true;

    private array $configuration;
    private array|false $status;
    private array $scripts = [];

    public function __construct()
    {
        $this->configuration = opcache_get_configuration();
        $this->status = opcache_get_status();
    }

    public function getStatus(): bool
    {
        return $this->status !== false;
    }

    public function getPageTitle(): string
    {
        return 'PHP ' . phpversion() . " with OPcache {$this->configuration['version']['version']}";
    }

    public function getStatusDataRows(): string
    {
        $rows = [];
        foreach ($this->status as $key => $value) {
            if ($key === 'scripts') {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if ($v === false) {
                        $v = 'false';
                    } elseif ($v === true) {
                        $v = 'true';
                    }
                    if ($k === 'used_memory' || $k === 'free_memory' || $k === 'wasted_memory'
                        || $k === 'buffer_size' || $k === 'buffer_free') {
                        $v = $this->sizeForHumans((int)$v);
                    }
                    if ($k === 'current_wasted_percentage' || $k === 'opcache_hit_rate') {
                        $v = number_format((float)$v, 2) . '%';
                    }
                    if ($k === 'blacklist_miss_ratio') {
                        $v = number_format((float)$v, 2) . '%';
                    }
                    if ($k === 'start_time' || $k === 'last_restart_time') {
                        $v = ($v ? date(DATE_RFC822, (int)$v) : 'never');
                    }
                    if (self::THOUSAND_SEPARATOR && is_int($v)) {
                        $v = number_format($v);
                    }

                    $rows[] = "<tr><th>$k</th><td>$v</td></tr>\n";
                }
                continue;
            }
            if ($value === false) {
                $value = 'false';
            } elseif ($value === true) {
                $value = 'true';
            }
            $rows[] = "<tr><th>$key</th><td>$value</td></tr>\n";
        }

        return implode("\n", $rows);
    }

    private function getCompiledDefaults(): array
    {
        // Common defaults shared across PHP 8.2–8.5
        $defaults = [
            'opcache.blacklist_filename' => '',
            'opcache.dups_fix' => '0',
            'opcache.enable' => '1',
            'opcache.enable_cli' => '0',
            'opcache.enable_file_override' => '0',
            'opcache.error_log' => '',
            'opcache.file_cache' => '',
            'opcache.file_cache_consistency_checks' => '1',
            'opcache.file_cache_only' => '0',
            'opcache.file_update_protection' => '2',
            'opcache.force_restart_timeout' => '180',
            'opcache.huge_code_pages' => '0',
            'opcache.interned_strings_buffer' => '8',
            'opcache.jit_bisect_limit' => '0',
            'opcache.jit_blacklist_root_trace' => '16',
            'opcache.jit_blacklist_side_trace' => '8',
            'opcache.jit_debug' => '0',
            'opcache.jit_hot_func' => '127',
            'opcache.jit_hot_loop' => '64',
            'opcache.jit_hot_return' => '8',
            'opcache.jit_hot_side_exit' => '8',
            'opcache.jit_max_exit_counters' => '8192',
            'opcache.jit_max_loop_unrolls' => '8',
            'opcache.jit_max_polymorphic_calls' => '2',
            'opcache.jit_max_recursive_calls' => '2',
            'opcache.jit_max_recursive_returns' => '2',
            'opcache.jit_max_root_traces' => '1024',
            'opcache.jit_max_side_traces' => '128',
            'opcache.jit_prof_threshold' => '0.005',
            'opcache.lockfile_path' => '/tmp',
            'opcache.log_verbosity_level' => '1',
            'opcache.max_accelerated_files' => '10000',
            'opcache.max_file_size' => '0',
            'opcache.max_wasted_percentage' => '5',
            'opcache.memory_consumption' => '128',
            'opcache.opt_debug_level' => '0',
            'opcache.optimization_level' => '0x7FFEBFFF',
            'opcache.preferred_memory_model' => '',
            'opcache.preload' => '',
            'opcache.preload_user' => '',
            'opcache.protect_memory' => '0',
            'opcache.record_warnings' => '0',
            'opcache.restrict_api' => '',
            'opcache.revalidate_freq' => '2',
            'opcache.revalidate_path' => '0',
            'opcache.save_comments' => '1',
            'opcache.use_cwd' => '1',
            'opcache.validate_permission' => '0',
            'opcache.validate_root' => '0',
            'opcache.validate_timestamps' => '1',
        ];

        $minor = PHP_MINOR_VERSION;

        // Per-version overrides
        if ($minor <= 2) {
            // PHP 8.2: jit=tracing, jit_buffer_size=0, has consistency_checks, no jit_max_trace_length
            $defaults['opcache.consistency_checks'] = '0';
            $defaults['opcache.jit'] = 'tracing';
            $defaults['opcache.jit_buffer_size'] = '0';
        } elseif ($minor === 3) {
            // PHP 8.3: jit=tracing, jit_buffer_size=0, adds jit_max_trace_length
            $defaults['opcache.jit'] = 'tracing';
            $defaults['opcache.jit_buffer_size'] = '0';
            $defaults['opcache.jit_max_trace_length'] = '1024';
        } elseif ($minor === 4) {
            // PHP 8.4: jit=disable, jit_buffer_size=64M
            $defaults['opcache.jit'] = 'disable';
            $defaults['opcache.jit_buffer_size'] = '64M';
            $defaults['opcache.jit_max_trace_length'] = '1024';
        } else {
            // PHP 8.5+: same as 8.4 but adds file_cache_read_only, jit_hot_loop=61
            $defaults['opcache.jit'] = 'disable';
            $defaults['opcache.jit_buffer_size'] = '64M';
            $defaults['opcache.jit_max_trace_length'] = '1024';
            $defaults['opcache.file_cache_read_only'] = '0';
            $defaults['opcache.jit_hot_loop'] = '61';
        }

        return $defaults;
    }

    private static function getConfigDocs(): array
    {
        return [
            'opcache.enable' => 'Enables the opcode cache. When disabled, code is not optimised or cached. Cannot be enabled at runtime through ini_set(), only disabled.',
            'opcache.enable_cli' => 'Enables the opcode cache for the CLI version of PHP.',
            'opcache.memory_consumption' => 'The size of the shared memory storage used by OPcache, in megabytes. The minimum value is 8.',
            'opcache.interned_strings_buffer' => 'The amount of memory used to store interned strings, in megabytes.',
            'opcache.max_accelerated_files' => 'The maximum number of keys (and therefore scripts) in the OPcache hash table. The actual value used will be the first number in the set of prime numbers that is bigger than the number configured. The minimum value is 200. The maximum value is 1000000.',
            'opcache.max_wasted_percentage' => 'The maximum percentage of wasted memory that is allowed before a restart is scheduled. The maximum value is 50.',
            'opcache.use_cwd' => 'If enabled, OPcache appends the current working directory to the script key, thus eliminating possible collisions between files with the same base name.',
            'opcache.validate_timestamps' => 'If enabled, OPcache will check for updated scripts every opcache.revalidate_freq seconds. When this directive is disabled, you must reset OPcache manually via opcache_reset(), opcache_invalidate() or by restarting the web server for changes to the filesystem to take effect.',
            'opcache.revalidate_freq' => 'How often to check script timestamps for updates, in seconds. 0 will result in OPcache checking for updates on every request.',
            'opcache.revalidate_path' => 'If disabled, existing cached files using unresolved include_path will be reused. Thus, if a file with the same name is elsewhere in the include_path, it won\'t be found.',
            'opcache.save_comments' => 'If disabled, all documentation comments will be discarded from the opcode cache to reduce the size of the optimised code. Disabling may break applications and frameworks that rely on comment parsing for annotations, including Doctrine, Zend Framework 2, and PHPUnit.',
            'opcache.fast_shutdown' => 'If enabled, a fast shutdown sequence is used that doesn\'t free each allocated block, but relies on the Zend Engine memory manager to deallocate the entire set of request variables en masse. Removed in PHP 7.2.0; integrated into PHP itself.',
            'opcache.enable_file_override' => 'When enabled, the opcode cache will be checked for whether a file has already been cached when file_exists(), is_file() and is_readable() are called. This may increase performance in applications that check the readability of PHP scripts, but risks returning stale data if opcache.validate_timestamps is disabled.',
            'opcache.optimization_level' => 'A bitmask that controls which optimisation passes are executed.',
            'opcache.inherited_hack' => 'This configuration directive is ignored.',
            'opcache.dups_fix' => 'This hack should only be enabled to work around "Cannot redeclare class" errors.',
            'opcache.blacklist_filename' => 'The location of the OPcache blacklist file. A blacklist file is a text file that contains the names of files that should not be accelerated, one per line. Wildcards are allowed, and prefixes can also be provided. Lines starting with a semicolon are ignored as comments.',
            'opcache.max_file_size' => 'The maximum file size that OPcache will cache, in bytes. If this is 0, all files will be cached.',
            'opcache.consistency_checks' => 'If non-zero, OPcache will verify the cache checksum every N requests. This should only be enabled when debugging, since it impacts performance. Removed in PHP 8.3.0.',
            'opcache.force_restart_timeout' => 'The length of time to wait for a scheduled restart to begin if the cache is not being accessed, in seconds. If the timeout is hit, OPcache assumes that something has gone wrong and will kill the process holding the cache lock to permit a restart.',
            'opcache.error_log' => 'OPcache error log. An empty string is treated the same as stderr, and will result in logs being sent to the standard error output (which will be the web server error log in most cases).',
            'opcache.log_verbosity_level' => 'Log verbosity level. By default, only fatal errors (level 0) and errors (level 1) are logged. Other levels available are warnings (level 2), info messages (level 3), and debug messages (level 4).',
            'opcache.preferred_memory_model' => 'The preferred memory model for OPcache to use. If left empty, OPcache will choose the most appropriate model, which is the correct behaviour in virtually all cases. Possible values include mmap, shm, posix, and win32.',
            'opcache.protect_memory' => 'Protects shared memory from unexpected writes while executing scripts. Useful for internal debugging only.',
            'opcache.restrict_api' => 'Allows calling OPcache API functions only from PHP scripts whose path starts with the specified string. An empty string means no restriction.',
            'opcache.mmap_base' => 'The base address to be used for the shared memory mapping on Windows only. All PHP processes have to map the shared memory into the same address space. Use this setting to fix "Unable to reattach to base address" errors.',
            'opcache.file_cache' => 'Enables and sets the second level cache directory. It should improve performance when SHM memory is full, at server restart, or SHM reset. The default empty string disables file-based caching.',
            'opcache.file_cache_only' => 'Enables or disables opcode caching in shared memory.',
            'opcache.file_cache_consistency_checks' => 'Enables or disables checksum validation when a script is loaded from the file cache.',
            'opcache.file_cache_fallback' => 'Implies opcache.file_cache_only=1 for a certain process that failed to reattach to the shared memory. Windows only.',
            'opcache.file_update_protection' => 'Prevents caching of files that are less than this number of seconds old. It protects from caching of incompletely updated files. If all file updates on your site are atomic, you may increase performance by setting this to 0.',
            'opcache.huge_code_pages' => 'Enables or disables copying of PHP code (text segment) into HUGE PAGES. This should improve performance, but requires appropriate OS configuration.',
            'opcache.lockfile_path' => 'Absolute path used to store shared lockfiles (for *nix only).',
            'opcache.opt_debug_level' => 'Produces opcode dumps for debugging different stages of optimisations. 0x10000 will output opcodes as the compiler produced them before any optimisation occurs. 0x20000 will output opcodes after optimisation.',
            'opcache.validate_permission' => 'Validates the cached file\'s permissions against the current user.',
            'opcache.validate_root' => 'Prevents name collisions in chroot\'ed environments. Should be enabled in all chroot\'ed environments to prevent the access to files outside of the chroot.',
            'opcache.preload' => 'Specifies a PHP script that is going to be compiled and executed at server start-up, and may preload other files by either including them or using the opcache_compile_file() function. All the entities (e.g. functions and classes) defined in these files will be available to requests out of the box, until the server is shut down.',
            'opcache.preload_user' => 'Allows preloading to be run as the specified system user. This is useful for servers that start as root before switching to an unprivileged system user. Preloading as root is not allowed by default for security reasons unless this directive is explicitly set to root.',
            'opcache.cache_id' => 'On Windows, all processes running the same PHP SAPI under the same user account having the same cache ID share a single OPcache instance. The value of the cache ID can be freely chosen.',
            'opcache.record_warnings' => 'If enabled, OPcache will record compile-time warnings and replay them on the next include, even if it is served from cache.',
            'opcache.file_cache_read_only' => 'Enables a read-only file cache. This is mainly useful in CI/Docker-style environments where a pre-warmed cache is mounted read-only.',
            'opcache.jit' => 'For typical usage, this should be set to one of these string values: disable, off, tracing/on, or function. For advanced usage, a 4-digit integer CRTO is accepted where each digit controls a specific JIT optimization flag.',
            'opcache.jit_buffer_size' => 'The amount of shared memory to reserve for compiled JIT code. A zero value disables the JIT.',
            'opcache.jit_debug' => 'A bitmask specifying which JIT debug output to enable. Refer to zend_jit.h for possible values.',
            'opcache.jit_bisect_limit' => 'Use a bisect search to debug issues with the JIT by disabling JIT compilation of functions above a specified limit. This requires compilation of a tracing function only, with trigger set to 0 or 1.',
            'opcache.jit_prof_threshold' => 'When using the "profile on first request" trigger mode, this threshold determines whether a function is considered hot. The number of calls to the function divided by the number of all calls must be above this threshold.',
            'opcache.jit_max_root_traces' => 'Maximum number of root traces. Setting this to 0 disables JIT trace compilation.',
            'opcache.jit_max_side_traces' => 'Maximum number of side traces per root trace.',
            'opcache.jit_max_exit_counters' => 'Maximum number of side trace exit counters. This limits the total number of side traces there can be (across all root traces).',
            'opcache.jit_hot_loop' => 'After how many iterations a loop is considered hot.',
            'opcache.jit_hot_func' => 'After how many calls a function is considered hot.',
            'opcache.jit_hot_return' => 'After how many returns a return is considered hot.',
            'opcache.jit_hot_side_exit' => 'After how many exits a side exit is considered hot.',
            'opcache.jit_blacklist_root_trace' => 'Maximum number of attempts to compile a root trace before it is blacklisted.',
            'opcache.jit_blacklist_side_trace' => 'Maximum number of attempts to compile a side trace before it is blacklisted.',
            'opcache.jit_max_loop_unrolls' => 'Maximum number of attempts to unroll a loop in a side trace.',
            'opcache.jit_max_recursive_calls' => 'Maximum number of unrolled recursive call loops.',
            'opcache.jit_max_recursive_returns' => 'Maximum number of unrolled recursive return loops.',
            'opcache.jit_max_polymorphic_calls' => 'Maximum number of attempts to inline a polymorphic (virtual or interface) call. Calls above this limit are treated as megamorphic and are not inlined.',
            'opcache.jit_max_trace_length' => 'Maximum length of a single JIT trace.',
        ];
    }

    public function getConfigDataRows(): string
    {
        $defaults = $this->getCompiledDefaults();
        $docs = self::getConfigDocs();
        $iniValues = function_exists('ini_get_all') ? (ini_get_all('zend opcache', false) ?: []) : [];
        $rows = [];
        foreach ($this->configuration['directives'] as $key => $value) {
            $changed = false;
            if (array_key_exists($key, $defaults)) {
                $cur = $iniValues[$key] ?? (string)$value;
                $changed = (string)$defaults[$key] !== (string)$cur;
            }
            $class = $changed ? ' class="changed"' : '';
            if ($value === false) {
                $value = 'false';
            } elseif ($value === true) {
                $value = 'true';
            }

            // Format values with appropriate units
            $value = match ($key) {
                'opcache.memory_consumption'    => $this->sizeForHumans((int)$value),
                'opcache.jit_buffer_size'       => $this->sizeForHumans((int)$value),
                'opcache.interned_strings_buffer'=> ((int)$value) . '&nbsp;MB',
                'opcache.max_wasted_percentage' => number_format((float)$value * 100, 1) . '%',
                'opcache.force_restart_timeout' => $value . 's',
                'opcache.revalidate_freq'       => $value . 's',
                'opcache.file_update_protection' => $value . 's',
                'opcache.jit'                   => $value === '' ? 'off' : $value,
                default                         => $value,
            };

            $helpBtn = isset($docs[$key])
                ? ' <span class="help-icon" data-doc="' . htmlspecialchars($key) . '" title="Show documentation">&#x24D8;</span>'
                : '';
            $rows[] = "<tr$class><th>$key$helpBtn</th><td>$value</td></tr>\n";
        }

        return implode("\n", $rows);
    }

    public function buildScriptData(): void
    {
        if (!is_array($this->status) || !isset($this->status['scripts'])) {
            return;
        }
        foreach ($this->status['scripts'] as $key => $data) {
            $this->arrayPset($this->scripts, $key, [
                'name' => basename($key),
                'size' => $data['memory_consumption'],
                'hits' => $data['hits'],
            ]);
        }

        $basename = '';
        while (true) {
            if (count($this->scripts) !== 1) {
                break;
            }
            $basename .= DIRECTORY_SEPARATOR . key($this->scripts);
            $this->scripts = reset($this->scripts);
        }

        $this->scripts = $this->processPartition($this->scripts, $basename);
    }

    public function getConfigDocsJson(): string
    {
        return json_encode(self::getConfigDocs(), JSON_HEX_TAG);
    }

    public function getScriptListJson(): string
    {
        if (!is_array($this->status) || !isset($this->status['scripts'])) {
            return '[]';
        }
        $list = [];
        foreach ($this->status['scripts'] as $key => $data) {
            $list[] = [
                'path' => $key,
                'hits' => $data['hits'],
                'memory' => $data['memory_consumption'],
            ];
        }
        return json_encode($list, JSON_HEX_TAG);
    }

    public function getScriptStatusCount(): int
    {
        return is_array($this->status) && isset($this->status['scripts']) ? count($this->status['scripts']) : 0;
    }

    public function getHealthChecks(): array
    {
        if (!is_array($this->status)) {
            return [];
        }

        $checks = [];
        $mem = $this->status['memory_usage'];
        $stats = $this->status['opcache_statistics'];
        $config = $this->configuration['directives'];

        // 1. Memory Usage
        $memTotal = $mem['used_memory'] + $mem['free_memory'];
        $memUtil = $memTotal > 0 ? ($mem['used_memory'] / $memTotal) * 100 : 0;
        $memStatus = $this->utilizationStatus($memUtil);
        if ($stats['oom_restarts'] > 0) {
            $memStatus = 'red';
        }
        $suggestion = '';
        if ($memStatus !== 'green') {
            $suggestion = 'Increase opcache.memory_consumption.';
            if ($stats['oom_restarts'] > 0) {
                $suggestion .= ' OOM restarts detected (' . $stats['oom_restarts'] . ').';
            }
            if ($mem['wasted_memory'] > 0) {
                $suggestion .= ' Wasted: ' . $this->sizeForHumansPlain($mem['wasted_memory']) . '.';
            }
        }
        $checks[] = [
            'name' => 'Memory Usage',
            'directive' => 'opcache.memory_consumption',
            'utilization' => round($memUtil, 1),
            'status' => $memStatus,
            'detail' => $this->sizeForHumansPlain($mem['used_memory']) . ' of ' . $this->sizeForHumansPlain($memTotal) . ' used',
            'suggestion' => $suggestion,
        ];

        // 2. Keys
        $keysUtil = $stats['max_cached_keys'] > 0 ? ($stats['num_cached_keys'] / $stats['max_cached_keys']) * 100 : 0;
        $keysStatus = $this->utilizationStatus($keysUtil);
        if ($stats['hash_restarts'] > 0) {
            $keysStatus = 'red';
        }
        $suggestion = '';
        if ($keysStatus !== 'green') {
            $suggestion = 'Increase opcache.max_accelerated_files.';
            if ($stats['hash_restarts'] > 0) {
                $suggestion .= ' Hash restarts detected (' . $stats['hash_restarts'] . ').';
            }
        }
        $checks[] = [
            'name' => 'Key Usage',
            'directive' => 'opcache.max_accelerated_files',
            'utilization' => round($keysUtil, 1),
            'status' => $keysStatus,
            'detail' => number_format($stats['num_cached_keys']) . ' of ' . number_format($stats['max_cached_keys']) . ' keys used',
            'suggestion' => $suggestion,
        ];

        // 3. Interned Strings
        if (isset($this->status['interned_strings_usage']['buffer_size']) && $this->status['interned_strings_usage']['buffer_size'] > 0) {
            $is = $this->status['interned_strings_usage'];
            $isUtil = ($is['used_memory'] / $is['buffer_size']) * 100;
            $isStatus = $this->utilizationStatus($isUtil);
            $checks[] = [
                'name' => 'Interned Strings',
                'directive' => 'opcache.interned_strings_buffer',
                'utilization' => round($isUtil, 1),
                'status' => $isStatus,
                'detail' => $this->sizeForHumansPlain($is['used_memory']) . ' of ' . $this->sizeForHumansPlain($is['buffer_size']) . ' used',
                'suggestion' => $isStatus !== 'green' ? 'Increase opcache.interned_strings_buffer.' : '',
            ];
        }

        // 4. JIT Buffer
        if (isset($this->status['jit']['buffer_size'])) {
            $jit = $this->status['jit'];
            if ($jit['buffer_size'] > 0) {
                $jitUsed = $jit['buffer_size'] - $jit['buffer_free'];
                $jitUtil = ($jitUsed / $jit['buffer_size']) * 100;
                $jitStatus = $this->utilizationStatus($jitUtil);
                $checks[] = [
                    'name' => 'JIT Buffer',
                    'directive' => 'opcache.jit_buffer_size',
                    'utilization' => round($jitUtil, 1),
                    'status' => $jitStatus,
                    'detail' => $this->sizeForHumansPlain($jitUsed) . ' of ' . $this->sizeForHumansPlain($jit['buffer_size']) . ' used',
                    'suggestion' => $jitStatus !== 'green' ? 'Increase opcache.jit_buffer_size.' : '',
                ];
            } else {
                $checks[] = [
                    'name' => 'JIT Buffer',
                    'directive' => 'opcache.jit_buffer_size',
                    'utilization' => 0,
                    'status' => 'info',
                    'detail' => 'JIT is disabled',
                    'suggestion' => '',
                ];
            }
        }

        // 5. Wasted Memory
        $currentWasted = $mem['current_wasted_percentage'];
        $maxWasted = (float)$config['opcache.max_wasted_percentage'] * 100;
        $wastedRatio = $maxWasted > 0 ? ($currentWasted / $maxWasted) * 100 : 0;
        if ($wastedRatio > 75) {
            $wastedStatus = 'red';
        } elseif ($wastedRatio > 25) {
            $wastedStatus = 'yellow';
        } else {
            $wastedStatus = 'green';
        }
        $checks[] = [
            'name' => 'Wasted Memory',
            'directive' => 'opcache.max_wasted_percentage',
            'utilization' => round($wastedRatio, 1),
            'status' => $wastedStatus,
            'detail' => number_format($currentWasted, 1) . '% wasted (restart at ' . number_format($maxWasted, 1) . '%)',
            'suggestion' => $wastedStatus !== 'green'
                ? 'High wasted memory indicates frequent recompilation. Check if opcache.validate_timestamps is on in production, or if deploys need a graceful restart.'
                : '',
        ];

        // 6. Hit Rate
        $hitRate = $stats['opcache_hit_rate'];
        if ($hitRate > 99) {
            $hitStatus = 'green';
        } elseif ($hitRate >= 95) {
            $hitStatus = 'yellow';
        } else {
            $hitStatus = 'red';
        }
        $checks[] = [
            'name' => 'Hit Rate',
            'directive' => '',
            'utilization' => round($hitRate, 1),
            'status' => $hitStatus,
            'detail' => number_format($hitRate, 2) . '% cache hit rate',
            'suggestion' => $hitStatus !== 'green'
                ? 'Low hit rate may be normal on a low-traffic server or after a recent restart. Otherwise, scripts may be evicted — check opcache.max_accelerated_files and opcache.memory_consumption. If opcache.revalidate_freq is 0, every request stats the filesystem.'
                : '',
        ];

        return $checks;
    }

    public function getHealthChecksJson(): string
    {
        return json_encode($this->getHealthChecks(), JSON_HEX_TAG);
    }

    public function getGraphDataSetJson(): string
    {
        if (!is_array($this->status)) {
            return json_encode(['memory' => [0, 0, 0], 'keys' => [0, 0, 0], 'hits' => [0, 0, 0], 'restarts' => [0, 0, 0]], JSON_HEX_TAG);
        }

        $dataset = [];
        $dataset['memory'] = [
            $this->status['memory_usage']['used_memory'],
            $this->status['memory_usage']['free_memory'],
            $this->status['memory_usage']['wasted_memory'],
        ];

        $dataset['keys'] = [
            $this->status['opcache_statistics']['num_cached_keys'],
            $this->status['opcache_statistics']['max_cached_keys'] - $this->status['opcache_statistics']['num_cached_keys'],
            0,
        ];

        $dataset['hits'] = [
            $this->status['opcache_statistics']['misses'],
            $this->status['opcache_statistics']['hits'],
            0,
        ];

        $dataset['restarts'] = [
            $this->status['opcache_statistics']['oom_restarts'],
            $this->status['opcache_statistics']['manual_restarts'],
            $this->status['opcache_statistics']['hash_restarts'],
        ];

        if (isset($this->status['jit']['buffer_size']) && $this->status['jit']['buffer_size'] > 0) {
            $dataset['jit'] = [
                $this->status['jit']['buffer_size'] - $this->status['jit']['buffer_free'],
                $this->status['jit']['buffer_free'],
                0,
            ];
        }

        if (isset($this->status['interned_strings_usage']['buffer_size']) && $this->status['interned_strings_usage']['buffer_size'] > 0) {
            $dataset['interned'] = [
                $this->status['interned_strings_usage']['used_memory'],
                $this->status['interned_strings_usage']['free_memory'],
                0,
            ];
        }

        return json_encode($dataset, JSON_HEX_TAG);
    }

    public function getHumanUsedMemory(): string
    {
        return is_array($this->status) ? $this->sizeForHumans($this->status['memory_usage']['used_memory']) : '0 bytes';
    }

    public function getHumanFreeMemory(): string
    {
        return is_array($this->status) ? $this->sizeForHumans($this->status['memory_usage']['free_memory']) : '0 bytes';
    }

    public function getHumanWastedMemory(): string
    {
        return is_array($this->status) ? $this->sizeForHumans($this->status['memory_usage']['wasted_memory']) : '0 bytes';
    }

    public function getWastedMemoryPercentage(): string
    {
        return is_array($this->status) ? number_format($this->status['memory_usage']['current_wasted_percentage'], 2) : '0.00';
    }

    public function getScriptsJson(): string
    {
        return json_encode($this->scripts ?: ['name' => '/', 'children' => []], JSON_HEX_TAG);
    }

    public function hasJit(): bool
    {
        return is_array($this->status) && isset($this->status['jit']['buffer_size']) && $this->status['jit']['buffer_size'] > 0;
    }

    public function hasInterned(): bool
    {
        return is_array($this->status) && isset($this->status['interned_strings_usage']['buffer_size']) && $this->status['interned_strings_usage']['buffer_size'] > 0;
    }

    public function getHumanJitUsed(): string
    {
        $jit = $this->status['jit'];
        return $this->sizeForHumans($jit['buffer_size'] - $jit['buffer_free']);
    }

    public function getHumanJitFree(): string
    {
        return $this->sizeForHumans($this->status['jit']['buffer_free']);
    }

    public function getHumanInternedUsed(): string
    {
        return $this->sizeForHumans($this->status['interned_strings_usage']['used_memory']);
    }

    public function getHumanInternedFree(): string
    {
        return $this->sizeForHumans($this->status['interned_strings_usage']['free_memory']);
    }

    private function processPartition(array $value, ?string $name = null): array
    {
        if (array_key_exists('size', $value)) {
            return $value;
        }

        $array = ['name' => $name, 'children' => []];

        foreach ($value as $k => $v) {
            $array['children'][] = $this->processPartition($v, $k);
        }

        return $array;
    }

    private function formatValue(int $value): string
    {
        return self::THOUSAND_SEPARATOR ? number_format($value) : (string)$value;
    }

    private function sizeForHumans(int $bytes): string
    {
        return match (true) {
            $bytes > 1048576 => sprintf('%.2f&nbsp;MB', $bytes / 1048576),
            $bytes > 1024    => sprintf('%.2f&nbsp;kB', $bytes / 1024),
            default          => sprintf('%d&nbsp;bytes', $bytes),
        };
    }

    private function sizeForHumansPlain(int $bytes): string
    {
        return match (true) {
            $bytes > 1048576 => sprintf('%.2f MB', $bytes / 1048576),
            $bytes > 1024    => sprintf('%.2f kB', $bytes / 1024),
            default          => sprintf('%d bytes', $bytes),
        };
    }

    private function utilizationStatus(float $pct): string
    {
        if ($pct > 75) return 'red';
        if ($pct >= 50) return 'yellow';
        return 'green';
    }

    private function arrayPset(array &$array, string $key, array $value): void
    {
        $keys = explode(DIRECTORY_SEPARATOR, ltrim($key, DIRECTORY_SEPARATOR));
        while (count($keys) > 1) {
            $key = array_shift($keys);
            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }
            $array = &$array[$key];
        }
        $array[array_shift($keys)] = $value;
    }
}

$dataModel = new OpCacheDataModel();
$dataModel->buildScriptData();
$noOpcache = !extension_loaded('Zend OPcache');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $dataModel->getPageTitle() ?></title>
    <style>
        :root {
            --bg: #ffffff;
            --bg-alt: #f8f9fa;
            --text: #1a1a2e;
            --text-muted: #6c757d;
            --border: #dee2e6;
            --accent: steelblue;
            --danger: #B41F1F;
            --success: #1FB437;
            --warning: #ff7f0e;
            --radius: 6px;
            --font: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            --mono: ui-monospace, "Cascadia Code", Menlo, monospace;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #1a1a2e;
                --bg-alt: #16213e;
                --text: #e0e0e0;
                --text-muted: #8899aa;
                --border: #2a2a4a;
            }
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: var(--font);
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--text);
        }
        #container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        h1 {
            padding: 16px 0 8px;
            font-size: 1.4em;
            font-weight: 600;
        }
        .notice {
            background-color: #fff3cd;
            color: #856404;
            padding: 0.75em 1em;
            border-radius: var(--radius);
            margin-bottom: 16px;
            border: 1px solid #ffc107;
        }
        @media (prefers-color-scheme: dark) {
            .notice { background-color: #332b00; color: #ffc107; border-color: #665500; }
        }
        .actions {
            margin: 0 0 16px;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg-alt);
        }
        .actions a {
            color: var(--danger);
            text-decoration: none;
            font-weight: 500;
        }
        .actions a:hover { text-decoration: underline; }
        .main-layout {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 900px) {
            .main-layout {
                grid-template-columns: 1fr;
            }
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        tbody tr:nth-child(even) { background-color: var(--bg-alt); }
        th, td { padding: 6px 12px; text-align: left; }
        td { font-family: var(--mono); font-size: 0.9em; }
        tr.changed { background-color: rgba(70, 130, 180, 0.12); }
        tr.changed th { color: var(--accent); font-weight: 600; }
        .help-icon {
            cursor: pointer;
            display: inline-block;
            margin-left: 6px;
            font-size: 0.85em;
            opacity: 0.4;
            vertical-align: middle;
        }
        .help-icon:hover { opacity: 0.9; }
        #help-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 200;
            background: rgba(0,0,0,0.45);
            display: none;
            justify-content: center;
        }
        #help-modal-overlay.visible { display: flex; }
        #help-modal-overlay { align-items: flex-start; padding-top: 12vh; }
        #help-modal {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            max-width: 560px;
            width: 90%;
            padding: 0;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }
        #help-modal-title {
            font-family: var(--mono);
            font-size: 0.95em;
            font-weight: 600;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            color: var(--accent);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        #help-modal-title button {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1.3em;
            line-height: 1;
            padding: 0 4px;
        }
        #help-modal-title button:hover { color: var(--text); }
        #help-modal-body {
            padding: 16px 18px;
            font-size: 0.92em;
            line-height: 1.6;
        }
        #help-modal-link {
            display: block;
            padding: 10px 18px 14px;
            font-size: 0.82em;
            border-top: 1px solid var(--border);
            color: var(--text-muted);
        }
        #help-modal-link a { color: var(--accent); }
        .content td { text-align: right; }
        .content th { text-align: left; }
        .tab:nth-child(4) td { text-align: left; }

        /* Health check cards */
        .health-cards {
            padding: 12px;
            display: grid;
            gap: 10px;
        }
        .health-card {
            background: var(--bg-alt);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
        }
        .health-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 0.92em;
        }
        .health-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
            vertical-align: middle;
        }
        .health-bar-track {
            background: var(--border);
            border-radius: 3px;
            height: 8px;
            overflow: hidden;
            margin-bottom: 6px;
        }
        .health-bar-fill {
            height: 100%;
            border-radius: 3px;
        }
        .health-detail {
            font-family: var(--mono);
            font-size: 0.82em;
            color: var(--text-muted);
        }
        .health-suggestion {
            font-size: 0.82em;
            color: var(--text-muted);
            margin-top: 6px;
            font-style: italic;
        }

        /* CSS radio-button tab hack */
        .tabs { position: relative; min-height: 490px; }
        .tab { display: inline-block; }
        .tab label {
            display: inline-block;
            background: var(--bg-alt);
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-bottom: none;
            margin-right: -1px;
            cursor: pointer;
            position: relative;
            border-radius: var(--radius) var(--radius) 0 0;
            font-size: 0.9em;
            user-select: none;
        }
        .tab label:hover { background: var(--bg); }
        .tab [type=radio] { display: none; }
        .content {
            position: absolute;
            top: 38px;
            left: 0;
            right: 0;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 0 var(--radius) var(--radius) var(--radius);
            height: 450px;
            overflow: auto;
        }
        [type=radio]:checked ~ label {
            background: var(--bg);
            border-bottom: 1px solid var(--bg);
            z-index: 2;
            font-weight: 600;
        }
        [type=radio]:checked ~ label ~ .content { z-index: 1; }

        .clickable {
            cursor: pointer;
            user-select: none;
        }
        .clickable:hover { color: var(--accent); }
        .sortable {
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }
        .sortable:hover { color: var(--accent); }
        .sortable::after { content: ' \2195'; opacity: 0.3; }
        .sortable.asc::after { content: ' \2191'; opacity: 0.8; }
        .sortable.desc::after { content: ' \2193'; opacity: 0.8; }

        /* Graph panel */
        #graph {
            position: relative;
            text-align: center;
        }
        #graph form {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 14px;
            justify-content: center;
            margin-bottom: 8px;
            font-size: 0.9em;
        }
        #graph form label { cursor: pointer; white-space: nowrap; }
        #graph svg { display: block; margin: 0 auto; }
        #stats { margin-top: 8px; }
        #stats table { margin: 0 auto; width: auto; }
        #stats th {
            color: #fff;
            padding: 4px 10px;
            border-radius: 3px;
            font-size: 0.85em;
        }
        #stats td {
            padding: 4px 10px;
            font-size: 0.85em;
            font-family: var(--mono);
        }

        /* Inline treemap */
        #treemap-inline {
            margin-top: 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        #treemap-inline .partition-header {
            border-top: none;
            border-radius: var(--radius) var(--radius) 0 0;
        }
        #inline-partition {
            height: 350px;
            overflow: hidden;
        }
        #inline-partition svg { display: block; }
        #inline-partition text {
            pointer-events: none;
            font-family: var(--font);
            font-size: 11px;
            stroke: rgba(0,0,0,0.6);
            stroke-width: 3px;
            paint-order: stroke fill;
        }
        #fullscreen-treemap {
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 6px 12px;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 0.85em;
        }
        #fullscreen-treemap:hover { opacity: 0.85; }

        /* Partition overlay */
        #partition-overlay {
            position: fixed;
            inset: 0;
            z-index: 100;
            background: var(--bg);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s, visibility 0.25s;
            display: flex;
            flex-direction: column;
        }
        #partition-overlay.visible {
            opacity: 1;
            visibility: visible;
        }
        .partition-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 16px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-alt);
            flex-shrink: 0;
        }
        .partition-breadcrumb {
            font-size: 0.9em;
            font-family: var(--mono);
        }
        .partition-breadcrumb span {
            cursor: pointer;
            color: var(--accent);
        }
        .partition-breadcrumb span:hover { text-decoration: underline; }
        .partition-breadcrumb span.current {
            cursor: default;
            color: var(--text);
            font-weight: 600;
        }
        #close-partition {
            background: var(--danger);
            color: #fff;
            border: none;
            padding: 8px 14px;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 0.9em;
            flex-shrink: 0;
        }
        #close-partition:hover { opacity: 0.85; }
        #partition {
            flex: 1;
            overflow: hidden;
        }
        #partition svg { display: block; }
        #partition text {
            pointer-events: none;
            font-family: var(--font);
            font-size: 11px;
            stroke: rgba(0,0,0,0.6);
            stroke-width: 3px;
            paint-order: stroke fill;
        }
    </style>
</head>
<body>
    <div id="container">
        <h1><?= $dataModel->getPageTitle() ?></h1>

        <?php if ($noOpcache): ?>
        <div class="notice">Zend OPcache extension not loaded &mdash; showing sample data.</div>
        <?php endif; ?>

        <div class="actions">
            <a href="?clear=1" onclick="return confirm('Reset the entire OPcache?')">Reset cache</a>
        </div>

        <div class="main-layout">
            <div class="tabs">
                <div class="tab">
                    <input type="radio" id="tab-status" name="tab-group-1" checked>
                    <label for="tab-status">Status</label>
                    <div class="content">
                        <?php if ($dataModel->getStatus()): ?>
                        <table><?= $dataModel->getStatusDataRows() ?></table>
                        <?php else: ?>
                        <p style="padding:1em">OPcache is disabled.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tab">
                    <input type="radio" id="tab-config" name="tab-group-1">
                    <label for="tab-config">Configuration</label>
                    <div class="content">
                        <table><?= $dataModel->getConfigDataRows() ?></table>
                    </div>
                </div>

                <div class="tab">
                    <input type="radio" id="tab-health" name="tab-group-1">
                    <label for="tab-health">Health</label>
                    <div class="content">
                        <div id="health-checks" class="health-cards"></div>
                    </div>
                </div>

                <div class="tab">
                    <input type="radio" id="tab-scripts" name="tab-group-1">
                    <label for="tab-scripts">Scripts (<?= $dataModel->getScriptStatusCount() ?>)</label>
                    <div class="content">
                        <?php if ($dataModel->getStatus()): ?>
                        <table id="scripts-table" style="font-size:0.85em">
                            <thead><tr>
                                <th width="10%" class="sortable" data-sort="hits">Hits</th>
                                <th width="20%" class="sortable" data-sort="memory">Memory</th>
                                <th width="70%" class="sortable" data-sort="path">Path</th>
                            </tr></thead>
                            <tbody></tbody>
                        </table>
                        <?php else: ?>
                        <p style="padding:1em">OPcache is disabled.</p>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <div id="graph">
                <form>
                    <label><input type="radio" name="dataset" value="memory" checked> Memory</label>
                    <label><input type="radio" name="dataset" value="keys"> Keys</label>
                    <label><input type="radio" name="dataset" value="hits"> Hits</label>
                    <label><input type="radio" name="dataset" value="restarts"> Restarts</label>
                    <?php if ($dataModel->hasJit()): ?>
                    <label><input type="radio" name="dataset" value="jit"> JIT</label>
                    <?php endif; ?>
                    <?php if ($dataModel->hasInterned()): ?>
                    <label><input type="radio" name="dataset" value="interned"> Interned</label>
                    <?php endif; ?>
                </form>
                <div id="stats"></div>
            </div>
        </div>

        <div id="treemap-inline">
            <div class="partition-header">
                <div class="partition-breadcrumb" id="inline-breadcrumb"></div>
                <button id="fullscreen-treemap" title="Fullscreen">&#x26F6; Fullscreen</button>
            </div>
            <div id="inline-partition"></div>
        </div>
    </div>

    <div id="help-modal-overlay">
        <div id="help-modal">
            <div id="help-modal-title"><span></span><button>&times;</button></div>
            <div id="help-modal-body"></div>
            <div id="help-modal-link"></div>
        </div>
    </div>

    <div id="partition-overlay">
        <div class="partition-header">
            <div class="partition-breadcrumb" id="breadcrumb"></div>
            <button id="close-partition">&#10006; Close</button>
        </div>
        <div id="partition"></div>
    </div>

    <script>
    (function() {
        "use strict";

        var dataset = <?= $dataModel->getGraphDataSetJson() ?>;

        // --- Stats text descriptions per dataset ---
        var statsInfo = {
            memory: {
                labels: ['Used', 'Free', 'Wasted'],
                colors: ['#B41F1F', '#1FB437', '#ff7f0e'],
                values: ['<?= $dataModel->getHumanUsedMemory() ?>', '<?= $dataModel->getHumanFreeMemory() ?>', '<?= $dataModel->getHumanWastedMemory() ?> (<?= $dataModel->getWastedMemoryPercentage() ?>%)']
            },
            keys: {
                labels: ['Cached keys', 'Free keys'],
                colors: ['#B41F1F', '#1FB437'],
                values: null
            },
            hits: {
                labels: ['Misses', 'Cache hits'],
                colors: ['#B41F1F', '#1FB437'],
                values: null
            },
            restarts: {
                labels: ['Memory', 'Manual', 'Keys'],
                colors: ['#B41F1F', '#1FB437', '#ff7f0e'],
                values: null
            }
            <?php if ($dataModel->hasJit()): ?>
            ,jit: {
                labels: ['Used', 'Free'],
                colors: ['#B41F1F', '#1FB437'],
                values: ['<?= $dataModel->getHumanJitUsed() ?>', '<?= $dataModel->getHumanJitFree() ?>']
            }
            <?php endif; ?>
            <?php if ($dataModel->hasInterned()): ?>
            ,interned: {
                labels: ['Used', 'Free'],
                colors: ['#B41F1F', '#1FB437'],
                values: ['<?= $dataModel->getHumanInternedUsed() ?>', '<?= $dataModel->getHumanInternedFree() ?>']
            }
            <?php endif; ?>
        };

        // --- Format numbers with thousand separators ---
        function formatValue(v) {
            return v.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function sizeForHumans(bytes) {
            if (bytes > 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
            if (bytes > 1024) return (bytes / 1024).toFixed(2) + ' kB';
            return bytes + ' bytes';
        }

        // =============================================
        //  DONUT CHART — vanilla SVG
        // =============================================
        var donutColors = ['#B41F1F', '#1FB437', '#ff7f0e'];
        var svgNS = 'http://www.w3.org/2000/svg';
        var graphEl = document.getElementById('graph');
        var statsEl = document.getElementById('stats');
        var svgSize = 300;
        var cx = svgSize / 2, cy = svgSize / 2;
        var outerR = svgSize / 2 - 10, innerR = outerR - 40;

        var svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('width', svgSize);
        svg.setAttribute('height', svgSize);
        svg.setAttribute('viewBox', '0 0 ' + svgSize + ' ' + svgSize);
        graphEl.insertBefore(svg, statsEl);

        function polarToCart(cxp, cyp, r, angle) {
            var rad = (angle - 90) * Math.PI / 180;
            return { x: cxp + r * Math.cos(rad), y: cyp + r * Math.sin(rad) };
        }

        function arcPath(cxp, cyp, oR, iR, startA, endA) {
            if (endA - startA >= 359.999) {
                // full circle — draw two half arcs
                var m1 = polarToCart(cxp, cyp, oR, startA);
                var m2 = polarToCart(cxp, cyp, oR, startA + 180);
                var m3 = polarToCart(cxp, cyp, iR, startA + 180);
                var m4 = polarToCart(cxp, cyp, iR, startA);
                return 'M' + m1.x + ',' + m1.y +
                       ' A' + oR + ',' + oR + ' 0 0 1 ' + m2.x + ',' + m2.y +
                       ' A' + oR + ',' + oR + ' 0 0 1 ' + m1.x + ',' + m1.y +
                       ' L' + m4.x + ',' + m4.y +
                       ' A' + iR + ',' + iR + ' 0 0 0 ' + m3.x + ',' + m3.y +
                       ' A' + iR + ',' + iR + ' 0 0 0 ' + m4.x + ',' + m4.y + ' Z';
            }
            var s1 = polarToCart(cxp, cyp, oR, startA);
            var e1 = polarToCart(cxp, cyp, oR, endA);
            var s2 = polarToCart(cxp, cyp, iR, endA);
            var e2 = polarToCart(cxp, cyp, iR, startA);
            var large = endA - startA > 180 ? 1 : 0;
            return 'M' + s1.x + ',' + s1.y +
                   ' A' + oR + ',' + oR + ' 0 ' + large + ' 1 ' + e1.x + ',' + e1.y +
                   ' L' + s2.x + ',' + s2.y +
                   ' A' + iR + ',' + iR + ' 0 ' + large + ' 0 ' + e2.x + ',' + e2.y + ' Z';
        }

        function drawDonut(data, colors) {
            while (svg.firstChild) svg.removeChild(svg.firstChild);
            var total = 0;
            for (var i = 0; i < data.length; i++) total += data[i];
            if (total === 0) {
                svg.style.display = 'none';
                return;
            }
            svg.style.display = 'block';
            var angle = 0;
            for (var j = 0; j < data.length; j++) {
                if (data[j] === 0) continue;
                var slice = (data[j] / total) * 360;
                var path = document.createElementNS(svgNS, 'path');
                path.setAttribute('d', arcPath(cx, cy, outerR, innerR, angle, angle + slice));
                path.setAttribute('fill', colors[j % colors.length]);
                svg.appendChild(path);
                angle += slice;
            }
        }

        function updateStats(key) {
            var info = statsInfo[key];
            var data = dataset[key];
            if (!info || !data) return;
            var html = '<table>';
            var count = 0;
            for (var i = 0; i < info.labels.length; i++) {
                if (i >= data.length) break;
                if (info.labels.length === 2 && i === 2) break;
                var val = info.values ? info.values[i] : formatValue(data[i]);
                html += '<tr><th style="background:' + info.colors[i] + '">' + info.labels[i] + '</th><td>' + val + '</td></tr>';
                count++;
            }
            html += '</table>';
            statsEl.innerHTML = html;
        }

        // Initial draw
        drawDonut(dataset.memory, donutColors);
        updateStats('memory');

        // Dataset switching
        var radios = document.querySelectorAll('input[name="dataset"]');
        for (var r = 0; r < radios.length; r++) {
            radios[r].addEventListener('change', function() {
                var key = this.value;
                if (!dataset[key]) return;
                var nonZero = dataset[key].filter(function(v) { return v > 0; });
                if (nonZero.length > 0) {
                    var info = statsInfo[key];
                    drawDonut(dataset[key], info ? info.colors : donutColors);
                } else {
                    svg.style.display = 'none';
                }
                updateStats(key);
            });
        }

        // =============================================
        //  COLLAPSIBLE SCRIPT GROUPS
        // =============================================
        var hidden = {};
        document.addEventListener('click', function(e) {
            var th = e.target.closest('[data-group]');
            if (!th) return;
            var group = th.getAttribute('data-group');
            var rows = document.querySelectorAll('[data-row="' + group + '"]');
            hidden[group] = !hidden[group];
            for (var i = 0; i < rows.length; i++) {
                rows[i].style.display = hidden[group] ? 'none' : '';
            }
            th.style.color = hidden[group] ? 'var(--text-muted)' : '';
        });

        // =============================================
        //  CONFIG HELP MODAL
        // =============================================
        var configDocs = <?= $dataModel->getConfigDocsJson() ?>;
        var helpOverlay = document.getElementById('help-modal-overlay');
        var helpTitle = document.querySelector('#help-modal-title span');
        var helpClose = document.querySelector('#help-modal-title button');
        var helpBody = document.getElementById('help-modal-body');
        var helpLink = document.getElementById('help-modal-link');

        // php.net anchor IDs are inconsistent — some use hyphens, some underscores
        var phpNetAnchors = {
            'opcache.enable': 'ini.opcache.enable',
            'opcache.enable_cli': 'ini.opcache.enable-cli',
            'opcache.memory_consumption': 'ini.opcache.memory-consumption',
            'opcache.interned_strings_buffer': 'ini.opcache.interned-strings-buffer',
            'opcache.max_accelerated_files': 'ini.opcache.max-accelerated-files',
            'opcache.max_wasted_percentage': 'ini.opcache.max-wasted-percentage',
            'opcache.use_cwd': 'ini.opcache.use-cwd',
            'opcache.validate_timestamps': 'ini.opcache.validate-timestamps',
            'opcache.revalidate_freq': 'ini.opcache.revalidate-freq',
            'opcache.revalidate_path': 'ini.opcache.revalidate-path',
            'opcache.save_comments': 'ini.opcache.save-comments',
            'opcache.fast_shutdown': 'ini.opcache.fast-shutdown',
            'opcache.enable_file_override': 'ini.opcache.enable-file-override',
            'opcache.optimization_level': 'ini.opcache.optimization-level',
            'opcache.inherited_hack': 'ini.opcache.inherited-hack',
            'opcache.dups_fix': 'ini.opcache.dups-fix',
            'opcache.blacklist_filename': 'ini.opcache.blacklist-filename',
            'opcache.max_file_size': 'ini.opcache.max-file-size',
            'opcache.consistency_checks': 'ini.opcache.consistency-checks',
            'opcache.force_restart_timeout': 'ini.opcache.force-restart-timeout',
            'opcache.error_log': 'ini.opcache.error-log',
            'opcache.log_verbosity_level': 'ini.opcache.log-verbosity-level',
            'opcache.record_warnings': 'ini.opcache.record-warnings',
            'opcache.preferred_memory_model': 'ini.opcache.preferred-memory-model',
            'opcache.protect_memory': 'ini.opcache.protect-memory',
            'opcache.mmap_base': 'ini.opcache.mmap-base',
            'opcache.restrict_api': 'ini.opcache.restrict-api',
            'opcache.file_update_protection': 'ini.opcache.file_update_protection',
            'opcache.huge_code_pages': 'ini.opcache.huge_code_pages',
            'opcache.lockfile_path': 'ini.opcache.lockfile_path',
            'opcache.opt_debug_level': 'ini.opcache.opt_debug_level',
            'opcache.file_cache': 'ini.opcache.file-cache',
            'opcache.file_cache_only': 'ini.opcache.file-cache-only',
            'opcache.file_cache_consistency_checks': 'ini.opcache.file-cache-consistency-checks',
            'opcache.file_cache_fallback': 'ini.opcache.file-cache-fallback',
            'opcache.validate_permission': 'ini.opcache.validate-permission',
            'opcache.validate_root': 'ini.opcache.validate-root',
            'opcache.preload': 'ini.opcache.preload',
            'opcache.preload_user': 'ini.opcache.preload-user',
            'opcache.cache_id': 'ini.opcache.cache-id',
            'opcache.jit': 'ini.opcache.jit',
            'opcache.jit_buffer_size': 'ini.opcache.jit-buffer-size',
            'opcache.jit_debug': 'ini.opcache.jit-debug',
            'opcache.jit_bisect_limit': 'ini.opcache.jit-bisect-limit',
            'opcache.jit_prof_threshold': 'ini.opcache.jit-prof-threshold',
            'opcache.jit_max_root_traces': 'ini.opcache.jit-max-root-traces',
            'opcache.jit_max_side_traces': 'ini.opcache.jit-max-side-traces',
            'opcache.jit_max_exit_counters': 'ini.opcache.jit-max-exit-counters',
            'opcache.jit_hot_loop': 'ini.opcache.jit-hot-loop',
            'opcache.jit_hot_func': 'ini.opcache.jit-hot-func',
            'opcache.jit_hot_return': 'ini.opcache.jit-hot-return',
            'opcache.jit_hot_side_exit': 'ini.opcache.jit-hot-side-exit',
            'opcache.jit_blacklist_root_trace': 'ini.opcache.jit-blacklist-root-trace',
            'opcache.jit_blacklist_side_trace': 'ini.opcache.jit-blacklist-side-trace',
            'opcache.jit_max_loop_unrolls': 'ini.opcache.jit-max-loop-unrolls',
            'opcache.jit_max_recursive_calls': 'ini.opcache.jit-max-recursive-calls',
            'opcache.jit_max_recursive_returns': 'ini.opcache.jit-max-recursive-return',
            'opcache.jit_max_polymorphic_calls': 'ini.opcache.jit-max-polymorphic-calls'
        };

        document.addEventListener('click', function(e) {
            var icon = e.target.closest('.help-icon');
            if (!icon) return;
            var key = icon.getAttribute('data-doc');
            if (!key || !configDocs[key]) return;
            helpTitle.textContent = key;
            helpBody.textContent = configDocs[key];
            var anchor = phpNetAnchors[key] || 'ini.' + key;
            helpLink.innerHTML = '<a href="https://php.net/manual/en/opcache.configuration.php#' +
                anchor + '" target="_blank" rel="noopener">php.net documentation &rarr;</a>';
            helpOverlay.classList.add('visible');
        });

        function closeHelp() { helpOverlay.classList.remove('visible'); }
        helpClose.addEventListener('click', closeHelp);
        helpOverlay.addEventListener('click', function(e) {
            if (e.target === helpOverlay) closeHelp();
        });
        document.addEventListener('keyup', function(e) {
            if (e.key === 'Escape' && helpOverlay.classList.contains('visible')) closeHelp();
        });

        // =============================================
        //  HEALTH CHECK CARDS
        // =============================================
        var healthChecks = <?= $dataModel->getHealthChecksJson() ?>;
        var healthContainer = document.getElementById('health-checks');
        if (healthContainer && healthChecks.length > 0) {
            var statusColors = {
                green: 'var(--success)',
                yellow: 'var(--warning)',
                red: 'var(--danger)',
                info: 'var(--accent)'
            };
            var html = '';
            for (var i = 0; i < healthChecks.length; i++) {
                var c = healthChecks[i];
                var color = statusColors[c.status] || 'var(--text-muted)';
                html += '<div class="health-card">';
                html += '<div class="health-card-header">';
                html += '<span><span class="health-dot" style="background:' + color + '"></span>' + c.name + '</span>';
                html += '<span>' + c.utilization + '%</span>';
                html += '</div>';
                html += '<div class="health-bar-track"><div class="health-bar-fill" style="width:' + Math.min(c.utilization, 100) + '%;background:' + color + '"></div></div>';
                html += '<div class="health-detail">' + c.detail + '</div>';
                if (c.suggestion) {
                    html += '<div class="health-suggestion">' + c.suggestion + '</div>';
                }
                html += '</div>';
            }
            healthContainer.innerHTML = html;
        }

        // =============================================
        //  SORTABLE SCRIPTS TABLE
        // =============================================
        var scriptList = <?= $dataModel->getScriptListJson() ?>;
        var scriptsTable = document.getElementById('scripts-table');
        var currentSort = { key: 'path', dir: 1 }; // 1=asc, -1=desc

        function renderScriptTable() {
            if (!scriptsTable) return;
            var tbody = scriptsTable.querySelector('tbody');
            tbody.innerHTML = '';
            var sorted = scriptList.slice().sort(function(a, b) {
                var av = a[currentSort.key], bv = b[currentSort.key];
                if (typeof av === 'string') return av.localeCompare(bv) * currentSort.dir;
                return (av - bv) * currentSort.dir;
            });
            for (var i = 0; i < sorted.length; i++) {
                var s = sorted[i];
                var tr = document.createElement('tr');
                var td1 = document.createElement('td');
                td1.textContent = formatValue(s.hits);
                var td2 = document.createElement('td');
                td2.innerHTML = sizeForHumans(s.memory);
                var td3 = document.createElement('td');
                td3.textContent = s.path;
                tr.appendChild(td1);
                tr.appendChild(td2);
                tr.appendChild(td3);
                tbody.appendChild(tr);
            }
        }

        if (scriptsTable) {
            var headers = scriptsTable.querySelectorAll('.sortable');
            for (var si = 0; si < headers.length; si++) {
                headers[si].addEventListener('click', function() {
                    var key = this.getAttribute('data-sort');
                    if (currentSort.key === key) {
                        currentSort.dir *= -1;
                    } else {
                        currentSort.key = key;
                        currentSort.dir = key === 'path' ? 1 : -1;
                    }
                    // Update header classes
                    for (var j = 0; j < headers.length; j++) {
                        headers[j].classList.remove('asc', 'desc');
                    }
                    this.classList.add(currentSort.dir === 1 ? 'asc' : 'desc');
                    renderScriptTable();
                });
            }
            renderScriptTable();
        }

        // =============================================
        //  SQUARIFIED TREEMAP
        // =============================================
        var scriptData = <?= $dataModel->getScriptsJson() ?>;

        // Two render targets: inline and fullscreen overlay
        var overlay = document.getElementById('partition-overlay');
        var fsPartitionEl = document.getElementById('partition');
        var fsBreadcrumbEl = document.getElementById('breadcrumb');
        var inlinePartitionEl = document.getElementById('inline-partition');
        var inlineBreadcrumbEl = document.getElementById('inline-breadcrumb');

        // Active target state
        var activePartitionEl = inlinePartitionEl;
        var activeBreadcrumbEl = inlineBreadcrumbEl;
        var currentRoot = scriptData;
        var drillStack = [scriptData];

        // Squarify algorithm
        function squarify(children, rect) {
            if (!children || children.length === 0) return [];
            var totalSize = 0;
            for (var i = 0; i < children.length; i++) totalSize += getSize(children[i]);
            if (totalSize === 0) return [];

            var sorted = children.slice().sort(function(a, b) { return getSize(b) - getSize(a); });

            var rects = [];
            var remaining = sorted.slice();
            var x = rect.x, y = rect.y, w = rect.w, h = rect.h;
            var remainingArea = w * h;
            var remainingSize = totalSize;

            while (remaining.length > 0) {
                var shortSide = Math.min(w, h);
                var row = [];
                var rowSize = 0;
                var worst = Infinity;

                for (var j = 0; j < remaining.length; j++) {
                    var childArea = (getSize(remaining[j]) / remainingSize) * remainingArea;
                    var testRow = row.slice();
                    testRow.push(childArea);
                    var testWorst = worstRatio(testRow, shortSide);
                    if (testRow.length === 1 || testWorst <= worst) {
                        row.push(childArea);
                        rowSize += childArea;
                        worst = testWorst;
                    } else {
                        break;
                    }
                }

                var rowItems = remaining.splice(0, row.length);
                var rowLength = rowSize / shortSide;
                var offset = 0;
                var horizontal = w >= h;

                for (var k = 0; k < rowItems.length; k++) {
                    var itemLen = row[k] / rowLength;
                    if (horizontal) {
                        rects.push({ x: x, y: y + offset, w: rowLength, h: itemLen, node: rowItems[k] });
                    } else {
                        rects.push({ x: x + offset, y: y, w: itemLen, h: rowLength, node: rowItems[k] });
                    }
                    offset += itemLen;
                }

                if (horizontal) { x += rowLength; w -= rowLength; }
                else { y += rowLength; h -= rowLength; }
                remainingSize = 0;
                for (var m = 0; m < remaining.length; m++) remainingSize += getSize(remaining[m]);
                remainingArea = w * h;
            }
            return rects;
        }

        function worstRatio(row, shortSide) {
            var s = 0, minA = Infinity, maxA = 0;
            for (var i = 0; i < row.length; i++) {
                s += row[i];
                if (row[i] < minA) minA = row[i];
                if (row[i] > maxA) maxA = row[i];
            }
            var ss = shortSide * shortSide;
            return Math.max((ss * maxA) / (s * s), (s * s) / (ss * minA));
        }

        function getSize(node) {
            if (node.size !== undefined) return node.size;
            if (!node.children) return 0;
            var s = 0;
            for (var i = 0; i < node.children.length; i++) s += getSize(node.children[i]);
            return s;
        }

        function getLeafMinMax(node) {
            if (node.size !== undefined) return { min: node.size, max: node.size };
            if (!node.children) return { min: 0, max: 0 };
            var min = Infinity, max = 0;
            for (var i = 0; i < node.children.length; i++) {
                var mm = getLeafMinMax(node.children[i]);
                if (mm.min < min) min = mm.min;
                if (mm.max > max) max = mm.max;
            }
            return { min: min, max: max };
        }

        function heatColor(value, min, max) {
            if (max === min) return 'hsl(120, 50%, 45%)';
            var t = (value - min) / (max - min);
            var hue = 120 - t * 120;
            return 'hsl(' + hue + ', 55%, 45%)';
        }

        // Directory color palette — visually distinct muted tones
        var dirColors = ['#4682b4','#6a5acd','#2e8b57','#b8860b','#cd5c5c','#20b2aa','#9370db','#d2691e'];

        // Collect all leaf nodes from a subtree
        function collectLeaves(node) {
            if (node.size !== undefined) return [node];
            if (!node.children) return [];
            var out = [];
            for (var i = 0; i < node.children.length; i++) {
                out = out.concat(collectLeaves(node.children[i]));
            }
            return out;
        }

        function renderTreemap(root, targetEl) {
            if (targetEl) activePartitionEl = targetEl;
            currentRoot = root;
            activePartitionEl.innerHTML = '';
            var w = activePartitionEl.clientWidth || window.innerWidth;
            var h = activePartitionEl.clientHeight || (window.innerHeight - 50);
            if (w === 0 || h === 0) return;

            var children = root.children || [];
            if (children.length === 0) return;

            var tSvg = document.createElementNS(svgNS, 'svg');
            tSvg.setAttribute('width', w);
            tSvg.setAttribute('height', h);

            var rects = squarify(children, { x: 0, y: 0, w: w, h: h });
            var leafMM = getLeafMinMax(root);
            var pad = 3;

            for (var i = 0; i < rects.length; i++) {
                var r = rects[i];
                var node = r.node;
                var isDir = node.children && node.children.length > 0;
                var g = document.createElementNS(svgNS, 'g');

                if (isDir) {
                    var dirColor = dirColors[i % dirColors.length];
                    var headerH = 22;

                    // Outer border
                    var bg = document.createElementNS(svgNS, 'rect');
                    bg.setAttribute('x', r.x + pad / 2);
                    bg.setAttribute('y', r.y + pad / 2);
                    bg.setAttribute('width', Math.max(0, r.w - pad));
                    bg.setAttribute('height', Math.max(0, r.h - pad));
                    bg.setAttribute('rx', 3);
                    bg.setAttribute('fill', 'var(--bg-alt)');
                    bg.setAttribute('stroke', dirColor);
                    bg.setAttribute('stroke-width', '2');
                    bg.style.cursor = 'pointer';
                    g.appendChild(bg);

                    // Header bar
                    if (r.h > headerH + pad) {
                        var hdr = document.createElementNS(svgNS, 'rect');
                        hdr.setAttribute('x', r.x + pad / 2);
                        hdr.setAttribute('y', r.y + pad / 2);
                        hdr.setAttribute('width', Math.max(0, r.w - pad));
                        hdr.setAttribute('height', headerH);
                        hdr.setAttribute('rx', 3);
                        hdr.setAttribute('fill', dirColor);
                        hdr.setAttribute('fill-opacity', '0.8');
                        g.appendChild(hdr);

                        // Flatten all leaves inside this directory and render them
                        var leaves = collectLeaves(node);
                        var innerRect = {
                            x: r.x + pad,
                            y: r.y + pad / 2 + headerH + 2,
                            w: Math.max(0, r.w - pad * 2),
                            h: Math.max(0, r.h - pad - headerH - 2)
                        };
                        if (innerRect.w > 4 && innerRect.h > 4 && leaves.length > 0) {
                            var subRects = squarify(leaves, innerRect);
                            for (var s = 0; s < subRects.length; s++) {
                                var sr = subRects[s];
                                var sn = sr.node;
                                var subRect = document.createElementNS(svgNS, 'rect');
                                subRect.setAttribute('x', sr.x + 1);
                                subRect.setAttribute('y', sr.y + 1);
                                subRect.setAttribute('width', Math.max(0, sr.w - 2));
                                subRect.setAttribute('height', Math.max(0, sr.h - 2));
                                subRect.setAttribute('rx', 2);
                                subRect.setAttribute('fill', heatColor(sn.size, leafMM.min, leafMM.max));
                                subRect.setAttribute('fill-opacity', '0.85');
                                subRect.setAttribute('stroke', 'var(--bg)');
                                subRect.setAttribute('stroke-width', '1');
                                var subTitle = document.createElementNS(svgNS, 'title');
                                subTitle.textContent = sn.name + '\n' + sizeForHumans(sn.size) + (sn.hits !== undefined ? '\nHits: ' + formatValue(sn.hits) : '');
                                subRect.appendChild(subTitle);
                                g.appendChild(subRect);
                                if (sr.w > 36 && sr.h > 13) {
                                    var st = document.createElementNS(svgNS, 'text');
                                    st.setAttribute('x', sr.x + 4);
                                    st.setAttribute('y', sr.y + 13);
                                    st.setAttribute('fill', '#fff');
                                    st.setAttribute('font-size', '10px');
                                    var sMaxC = Math.floor((sr.w - 8) / 6);
                                    var sLabel = sn.name || '';
                                    if (sLabel.length > sMaxC) sLabel = sLabel.substring(0, Math.max(0, sMaxC - 1)) + '\u2026';
                                    st.textContent = sLabel;
                                    g.appendChild(st);
                                }
                            }
                        }
                    }

                    // Directory label in header
                    if (r.w > 30 && r.h > 14) {
                        var text = document.createElementNS(svgNS, 'text');
                        text.setAttribute('x', r.x + pad + 4);
                        text.setAttribute('y', r.y + pad + 14);
                        text.setAttribute('fill', '#fff');
                        text.setAttribute('font-weight', '600');
                        text.setAttribute('font-size', '12px');
                        var maxChars = Math.floor((r.w - pad - 8) / 7);
                        var dirLabel = (node.name || '') + ' (' + sizeForHumans(getSize(node)) + ')';
                        if (dirLabel.length > maxChars) dirLabel = dirLabel.substring(0, Math.max(0, maxChars - 1)) + '\u2026';
                        text.textContent = dirLabel;
                        g.appendChild(text);
                    }

                    // Click to drill in
                    (function(n) {
                        g.addEventListener('click', function(e) {
                            e.stopPropagation();
                            drillStack.push(n);
                            renderTreemap(n);
                            updateBreadcrumb();
                        });
                    })(node);
                } else {
                    // Leaf file
                    var rect = document.createElementNS(svgNS, 'rect');
                    rect.setAttribute('x', r.x + pad / 2);
                    rect.setAttribute('y', r.y + pad / 2);
                    rect.setAttribute('width', Math.max(0, r.w - pad));
                    rect.setAttribute('height', Math.max(0, r.h - pad));
                    rect.setAttribute('rx', 2);
                    rect.setAttribute('fill', heatColor(node.size, leafMM.min, leafMM.max));
                    rect.setAttribute('fill-opacity', '0.85');
                    rect.setAttribute('stroke', 'var(--border)');
                    rect.setAttribute('stroke-width', '1');
                    g.appendChild(rect);

                    var title = document.createElementNS(svgNS, 'title');
                    var tip = node.name + '\n' + sizeForHumans(node.size || 0);
                    if (node.hits !== undefined) tip += '\nHits: ' + formatValue(node.hits);
                    title.textContent = tip;
                    g.appendChild(title);

                    if (r.w > 40 && r.h > 14) {
                        var text = document.createElementNS(svgNS, 'text');
                        text.setAttribute('x', r.x + pad + 4);
                        text.setAttribute('y', r.y + pad + 13);
                        text.setAttribute('fill', '#fff');
                        text.setAttribute('font-size', '11px');
                        var maxChars = Math.floor((r.w - pad - 8) / 6.5);
                        var label = node.name || '';
                        if (label.length > maxChars) label = label.substring(0, Math.max(0, maxChars - 1)) + '\u2026';
                        text.textContent = label;
                        g.appendChild(text);

                        if (r.h > 28) {
                            var text2 = document.createElementNS(svgNS, 'text');
                            text2.setAttribute('x', r.x + pad + 4);
                            text2.setAttribute('y', r.y + pad + 26);
                            text2.setAttribute('fill', 'rgba(255,255,255,0.7)');
                            text2.setAttribute('font-size', '10px');
                            text2.textContent = sizeForHumans(node.size);
                            g.appendChild(text2);
                        }
                    }
                }

                tSvg.appendChild(g);
            }
            activePartitionEl.appendChild(tSvg);
        }

        function updateBreadcrumb() {
            activeBreadcrumbEl.innerHTML = '';
            for (var i = 0; i < drillStack.length; i++) {
                if (i > 0) activeBreadcrumbEl.appendChild(document.createTextNode(' / '));
                var span = document.createElement('span');
                span.textContent = drillStack[i].name || '(root)';
                if (i === drillStack.length - 1) {
                    span.className = 'current';
                } else {
                    (function(idx) {
                        span.addEventListener('click', function() {
                            drillStack = drillStack.slice(0, idx + 1);
                            renderTreemap(drillStack[idx]);
                            updateBreadcrumb();
                        });
                    })(i);
                }
                activeBreadcrumbEl.appendChild(span);
            }
        }

        // Render inline treemap on load
        drillStack = [scriptData];
        renderTreemap(scriptData, inlinePartitionEl);
        updateBreadcrumb();

        // Fullscreen overlay
        function showFullscreen() {
            activePartitionEl = fsPartitionEl;
            activeBreadcrumbEl = fsBreadcrumbEl;
            overlay.classList.add('visible');
            drillStack = [scriptData];
            renderTreemap(scriptData, fsPartitionEl);
            updateBreadcrumb();
        }

        function hideFullscreen() {
            overlay.classList.remove('visible');
            activePartitionEl = inlinePartitionEl;
            activeBreadcrumbEl = inlineBreadcrumbEl;
        }

        document.getElementById('fullscreen-treemap').addEventListener('click', showFullscreen);
        document.getElementById('close-partition').addEventListener('click', hideFullscreen);
        document.addEventListener('keyup', function(e) {
            if (e.key === 'Escape') hideFullscreen();
        });

        // Re-render on resize
        var resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (overlay.classList.contains('visible')) {
                    renderTreemap(currentRoot, fsPartitionEl);
                } else {
                    renderTreemap(currentRoot, inlinePartitionEl);
                }
            }, 150);
        });

        // =============================================
        //  HOVER: Scripts table → treemap drill-in
        // =============================================
        // Find a directory node in the tree by matching path segments
        function findDirNode(root, dirPath) {
            // Strip the root name prefix from the dirPath
            var rootName = root.name || '';
            var rel = dirPath;
            if (rootName && dirPath.indexOf(rootName) === 0) {
                rel = dirPath.substring(rootName.length);
            }
            // Split into segments, filtering empties
            var parts = rel.split('/').filter(function(p) { return p.length > 0; });
            var node = root;
            for (var i = 0; i < parts.length; i++) {
                if (!node.children) return null;
                var found = null;
                for (var j = 0; j < node.children.length; j++) {
                    if (node.children[j].name === parts[i]) {
                        found = node.children[j];
                        break;
                    }
                }
                if (!found) return null;
                node = found;
            }
            return (node.children && node.children.length > 0) ? node : null;
        }

        var hoverTimeout;
        if (scriptsTable) {
            var tbody = scriptsTable.querySelector('tbody');
            tbody.addEventListener('mouseover', function(e) {
                var tr = e.target.closest('tr');
                if (!tr) return;
                var pathCell = tr.querySelector('td:last-child');
                if (!pathCell) return;
                var filePath = pathCell.textContent;
                var lastSlash = filePath.lastIndexOf('/');
                if (lastSlash < 0) return;
                var dirPath = filePath.substring(0, lastSlash);

                clearTimeout(hoverTimeout);
                var dirNode = findDirNode(scriptData, dirPath);
                if (dirNode && !overlay.classList.contains('visible')) {
                    activePartitionEl = inlinePartitionEl;
                    activeBreadcrumbEl = inlineBreadcrumbEl;
                    drillStack = [scriptData];
                    // Build drill stack to this directory
                    var rootName = scriptData.name || '';
                    var rel = dirPath;
                    if (rootName && dirPath.indexOf(rootName) === 0) rel = dirPath.substring(rootName.length);
                    var parts = rel.split('/').filter(function(p) { return p.length > 0; });
                    var cur = scriptData;
                    for (var i = 0; i < parts.length; i++) {
                        if (!cur.children) break;
                        for (var j = 0; j < cur.children.length; j++) {
                            if (cur.children[j].name === parts[i]) {
                                cur = cur.children[j];
                                drillStack.push(cur);
                                break;
                            }
                        }
                    }
                    renderTreemap(dirNode, inlinePartitionEl);
                    updateBreadcrumb();
                }
            });
            tbody.addEventListener('mouseleave', function() {
                if (overlay.classList.contains('visible')) return;
                hoverTimeout = setTimeout(function() {
                    activePartitionEl = inlinePartitionEl;
                    activeBreadcrumbEl = inlineBreadcrumbEl;
                    drillStack = [scriptData];
                    renderTreemap(scriptData, inlinePartitionEl);
                    updateBreadcrumb();
                }, 300);
            });
        }
    })();
    </script>
</body>
</html>
