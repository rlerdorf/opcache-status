<?php declare(strict_types=1);

// Set to true to disable cache reset and script invalidation
$readonly = false;

if (!extension_loaded('Zend OPcache')) {
    require __DIR__ . '/data-sample.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$readonly && isset($_POST['clear']) && $_POST['clear'] === '1' && function_exists('opcache_reset')) {
        opcache_reset();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (!$readonly && isset($_POST['invalidate']) && is_string($_POST['invalidate']) && $_POST['invalidate'] !== '' && function_exists('opcache_invalidate')) {
        opcache_invalidate($_POST['invalidate'], true);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

if (isset($_GET['json']) && $_GET['json'] === '1') {
    header('Content-Type: application/json');
    $dataModel = new OpCacheDataModel();
    $dataModel->buildScriptData();
    echo '{'
        . '"dataset":' . $dataModel->getGraphDataSetJson() . ','
        . '"healthChecks":' . json_encode($dataModel->getHealthChecks()) . ','
        . '"scriptList":' . $dataModel->getScriptListJson() . ','
        . '"scriptData":' . $dataModel->getScriptsJson() . ','
        . '"uptime":' . json_encode($dataModel->getUptime()) . ','
        . '"scriptCount":' . json_encode($dataModel->getScriptStatusCount()) . ','
        . '"statusRows":' . json_encode($dataModel->getStatusDataRows())
        . '}';
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

    public function getUptime(): string
    {
        if (!is_array($this->status) || empty($this->status['opcache_statistics']['start_time'])) {
            return '';
        }
        $start = $this->status['opcache_statistics']['start_time'];
        $lastRestart = $this->status['opcache_statistics']['last_restart_time'] ?? 0;
        $since = $lastRestart > 0 ? $lastRestart : $start;
        $diff = (new \DateTimeImmutable("@$since"))->diff(new \DateTimeImmutable());
        return match (true) {
            $diff->y > 0 => $diff->format('%yy %mmo'),
            $diff->m > 0 => $diff->format('%mmo %dd'),
            $diff->d > 0 => $diff->format('%dd %hh'),
            $diff->h > 0 => $diff->format('%hh %im'),
            default       => $diff->format('%im %ss'),
        };
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

                    if (is_array($v)) {
                        foreach ($v as $k2 => $v2) {
                            if ($v2 === false) {
                                $v2 = 'false';
                            } elseif ($v2 === true) {
                                $v2 = 'true';
                            }
                            $rows[] = "<tr><th>$k2</th><td>$v2</td></tr>\n";
                        }
                        continue;
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

        $preload = $this->configuration['directives']['opcache.preload'] ?? '';
        if ($preload !== '') {
            $preloadUser = $this->configuration['directives']['opcache.preload_user'] ?? '';
            $rows[] = "<tr><th colspan=\"2\" style=\"color:var(--accent);padding-top:12px\">Preloading</th></tr>\n";
            $rows[] = '<tr><th>preload</th><td>' . htmlspecialchars($preload) . "</td></tr>\n";
            if ($preloadUser !== '') {
                $rows[] = '<tr><th>preload_user</th><td>' . htmlspecialchars($preloadUser) . "</td></tr>\n";
            }
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

        if (PHP_MAJOR_VERSION !== 8) {
            return $defaults;
        }

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
            'opcache.optimization_level' => '<p>A bitmask integer that controls which optimisation passes the OPcache optimizer executes when compiling PHP scripts. The default value <code>0x7FFEBFFF</code> enables all safe passes. Each bit enables one pass:</p>'
                . '<table class="opt-doc-table">'
                . '<tr><th>Bit</th><th>Pass</th><th>Description</th></tr>'
                . '<tr><td><code>0x0001</code></td><td>Pre-evaluate constant operations</td><td>Evaluates constant expressions at compile time (e.g. <code>1+2</code> becomes <code>3</code>, string concatenations of literals are merged).</td></tr>'
                . '<tr><td><code>0x0004</code></td><td>Jump optimization</td><td>Converts sequences of jumps into direct jumps to the final target, eliminating unnecessary branches and unreachable code after unconditional jumps.</td></tr>'
                . '<tr><td><code>0x0008</code></td><td>Optimize function calls</td><td>Replaces calls to certain internal functions with faster opcode equivalents (e.g. <code>strlen()</code>, <code>defined()</code>, <code>call_user_func()</code>).</td></tr>'
                . '<tr><td><code>0x0010</code></td><td>CFG-based optimization</td><td>Builds a control flow graph and performs block-level optimisations: merging adjacent blocks, removing empty blocks, and simplifying conditional branches.</td></tr>'
                . '<tr><td><code>0x0020</code></td><td>Data flow analysis</td><td>SSA-based optimisations using Static Single Assignment form: type inference, range propagation, and value numbering to eliminate redundant computations.</td></tr>'
                . '<tr><td><code>0x0040</code></td><td>Call graph analysis</td><td>Analyses call relationships between functions to enable inter-procedural optimisations such as determining which functions can be inlined.</td></tr>'
                . '<tr><td><code>0x0080</code></td><td>SCCP (constant propagation)</td><td>Sparse Conditional Constant Propagation — propagates known constant values through the program and eliminates branches that can never be taken.</td></tr>'
                . '<tr><td><code>0x0100</code></td><td>Optimize temp variables</td><td>Reduces the number of temporary variables used by reusing slots, decreasing the memory footprint of each compiled function.</td></tr>'
                . '<tr><td><code>0x0200</code></td><td>NOP removal</td><td>Removes NOP (no-operation) instructions left behind by earlier optimisation passes, compacting the opcode array.</td></tr>'
                . '<tr><td><code>0x0400</code></td><td>Compact literals</td><td>De-duplicates identical literal values (strings, integers, floats) within a function, reducing memory usage in the literal table.</td></tr>'
                . '<tr><td><code>0x0800</code></td><td>Adjust used stack</td><td>Recalculates the actual stack size needed by each function after optimisation, freeing over-allocated stack slots.</td></tr>'
                . '<tr><td><code>0x1000</code></td><td>Compact unused variables</td><td>Removes variables that are assigned but never read, and compacts the remaining variable table to close gaps.</td></tr>'
                . '<tr><td><code>0x2000</code></td><td>Dead code elimination</td><td>Removes instructions whose results are never used, including assignments to variables that are overwritten before being read.</td></tr>'
                . '<tr class="unsafe"><td><code>0x4000</code></td><td>Constant substitution &#9888;</td><td>Replaces references to constants defined via <code>define()</code> with their values. Marked <em>unsafe</em> because it assumes constants are never redefined; can break code using conditionally defined constants.</td></tr>'
                . '<tr><td><code>0x8000</code></td><td>Trivial function inlining</td><td>Inlines very simple functions that just return a constant or a parameter, eliminating function call overhead entirely.</td></tr>'
                . '<tr class="unsafe"><td><code>0x10000</code></td><td>Ignore operator overloading &#9888;</td><td>Allows the optimizer to treat arithmetic operators as pure operations. Marked <em>unsafe</em> because classes like GMP and BCMath overload operators, and this pass may incorrectly optimize those expressions.</td></tr>'
                . '</table>'
                . '<p style="margin-top:10px;font-size:0.9em;color:var(--text-muted)">&#9888; = disabled by default because it may change runtime behaviour in edge cases.</p>',
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
                'opcache.optimization_level'    => $this->formatOptimizationLevel((int)$value),
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

    public function getBlacklistRows(): string
    {
        $blacklist = $this->configuration['blacklist'] ?? [];
        if (empty($blacklist)) {
            return "<tr><td style=\"font-style:italic;color:var(--text-muted)\">No blacklisted paths</td></tr>\n";
        }
        $rows = [];
        foreach ($blacklist as $path) {
            $rows[] = '<tr><td>' . htmlspecialchars($path) . '</td></tr>';
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
        if (isset($this->status['interned_strings_usage']['buffer_size'], $this->status['interned_strings_usage']['used_memory']) && $this->status['interned_strings_usage']['buffer_size'] > 0) {
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
        if (isset($this->status['jit']['buffer_size'], $this->status['jit']['buffer_free'])) {
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

        // 7. File Cache
        $fileCache = $config['opcache.file_cache'] ?? '';
        if ($fileCache !== '') {
            $fileCacheOnly = !empty($config['opcache.file_cache_only']);
            $detail = htmlspecialchars($fileCache);
            if ($fileCacheOnly) {
                $detail .= ' (file_cache_only)';
            }
            $checks[] = [
                'name' => 'File Cache',
                'directive' => 'opcache.file_cache',
                'utilization' => 0,
                'status' => 'info',
                'detail' => $detail,
                'suggestion' => '',
            ];
        }

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

        if (isset($this->status['jit']['buffer_size'], $this->status['jit']['buffer_free']) && $this->status['jit']['buffer_size'] > 0) {
            $dataset['jit'] = [
                $this->status['jit']['buffer_size'] - $this->status['jit']['buffer_free'],
                $this->status['jit']['buffer_free'],
                0,
            ];
        }

        if (isset($this->status['interned_strings_usage']['buffer_size'], $this->status['interned_strings_usage']['used_memory'], $this->status['interned_strings_usage']['free_memory']) && $this->status['interned_strings_usage']['buffer_size'] > 0) {
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
            $array['children'][] = $this->processPartition($v, (string)$k);
        }

        return $array;
    }

    private function formatValue(int $value): string
    {
        return self::THOUSAND_SEPARATOR ? number_format($value) : (string)$value;
    }

    private const OPTIMIZATION_PASSES = [
        0x0001 => 'Pre-evaluate constant operations',
        0x0004 => 'Jump optimization',
        0x0008 => 'Optimize function calls',
        0x0010 => 'CFG-based optimization',
        0x0020 => 'Data flow analysis',
        0x0040 => 'Call graph analysis',
        0x0080 => 'SCCP (constant propagation)',
        0x0100 => 'Optimize temp variables',
        0x0200 => 'NOP removal',
        0x0400 => 'Compact literals',
        0x0800 => 'Adjust used stack',
        0x1000 => 'Compact unused variables',
        0x2000 => 'Dead code elimination',
        0x4000 => 'Constant substitution (unsafe)',
        0x8000 => 'Trivial function inlining',
        0x10000 => 'Ignore operator overloading (unsafe)',
    ];

    private function formatOptimizationLevel(int $level): string
    {
        $hex = '0x' . strtoupper(dechex($level));
        $html = $hex . '<div class="opt-passes">';
        foreach (self::OPTIMIZATION_PASSES as $bit => $name) {
            $on = ($level & $bit) !== 0;
            $dot = $on ? '<span class="opt-on">&#x25CF;</span>' : '<span class="opt-off">&#x25CB;</span>';
            $html .= $dot . ' ' . htmlspecialchars($name) . '<br>';
        }
        return $html . '</div>';
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
        return match (true) {
            $pct > 75  => 'red',
            $pct >= 50 => 'yellow',
            default    => 'green',
        };
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
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        h1 .uptime {
            font-weight: 500;
            font-size: 0.85em;
            color: var(--text);
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
        #reset-cache-btn {
            display: inline-block;
            padding: 4px 12px;
            border: 1px solid var(--danger);
            border-radius: var(--radius);
            background: var(--bg);
            color: var(--danger);
            font-size: 0.9em;
            font-family: var(--font);
            font-weight: 500;
            cursor: pointer;
        }
        #reset-cache-btn:hover { background: var(--danger); color: #fff; }
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
            transition: max-width 0.15s ease;
        }
        #help-modal.wide {
            max-width: 820px;
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
            max-height: 65vh;
            overflow-y: auto;
        }
        .opt-doc-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.9em; }
        .opt-doc-table th, .opt-doc-table td { padding: 5px 8px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
        .opt-doc-table th { font-weight: 600; white-space: nowrap; }
        .opt-doc-table tr.unsafe td { color: var(--text-muted); }
        .opt-doc-table code { font-family: var(--mono); font-size: 0.92em; }
        #help-modal-link {
            display: block;
            padding: 10px 18px 14px;
            font-size: 0.82em;
            border-top: 1px solid var(--border);
            color: var(--text-muted);
        }
        #help-modal-link a { color: var(--accent); }

        /* Confirmation modal */
        #confirm-overlay {
            position: fixed;
            inset: 0;
            z-index: 200;
            background: rgba(0,0,0,0.45);
            display: none;
            justify-content: center;
            align-items: flex-start;
            padding-top: 18vh;
        }
        #confirm-overlay.visible { display: flex; }
        #confirm-modal {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            max-width: 480px;
            width: 90%;
            padding: 0;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }
        #confirm-title {
            font-size: 0.95em;
            font-weight: 600;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            color: var(--danger);
        }
        #confirm-body {
            padding: 16px 18px;
            font-size: 0.92em;
            line-height: 1.6;
        }
        #confirm-body .confirm-path {
            font-family: var(--mono);
            font-size: 0.9em;
            background: var(--bg-alt);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 6px 10px;
            margin: 8px 0;
            word-break: break-all;
        }
        #confirm-body .confirm-warn {
            color: var(--text-muted);
            font-size: 0.9em;
            margin-top: 10px;
            line-height: 1.5;
        }
        #confirm-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 12px 18px;
            border-top: 1px solid var(--border);
        }
        #confirm-cancel {
            background: var(--bg-alt);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 7px 16px;
            cursor: pointer;
            font-size: 0.9em;
            color: var(--text);
            font-family: var(--font);
        }
        #confirm-cancel:hover { border-color: var(--text-muted); }
        #confirm-ok {
            background: var(--danger);
            color: #fff;
            border: 1px solid var(--danger);
            border-radius: var(--radius);
            padding: 7px 16px;
            cursor: pointer;
            font-size: 0.9em;
            font-family: var(--font);
        }
        #confirm-ok:hover { opacity: 0.85; }

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

        /* Optimization level pass list */
        .opt-passes { font-size: 0.85em; margin-top: 4px; line-height: 1.6; }
        .opt-on { color: var(--success); }
        .opt-off { color: var(--text-muted); }

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
            overflow-y: auto;
            overflow-x: hidden;
            scrollbar-width: thin;
            scrollbar-color: var(--border) transparent;
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

        #scripts-table { table-layout: fixed; }
        #scripts-table td.path-cell {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            position: relative;
            padding-right: 28px;
        }
        #scripts-table tbody tr:hover td.path-cell { color: var(--accent); }
        .invalidate-btn {
            display: none;
            position: absolute;
            right: 4px;
            top: 50%;
            transform: translateY(-50%);
            background: var(--bg-alt);
            border: 1px solid var(--border);
            border-radius: 3px;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1em;
            padding: 1px 6px;
            line-height: 1;
        }
        .invalidate-btn:hover { color: var(--danger); border-color: var(--danger); }
        #scripts-table tbody tr:hover .invalidate-btn { display: block; }

        .auto-refresh-btn {
            display: inline-block;
            margin-left: 16px;
            padding: 4px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg);
            color: var(--text);
            cursor: pointer;
            font-size: 0.9em;
            font-family: var(--font);
        }
        .auto-refresh-btn:hover { border-color: var(--accent); }
        .auto-refresh-btn.active {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
        }
        .auto-refresh-select {
            padding: 4px 6px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg);
            color: var(--text);
            font-size: 0.9em;
            font-family: var(--font);
            cursor: pointer;
            vertical-align: middle;
        }
        .auto-refresh-select:hover { border-color: var(--accent); }
        .auto-refresh-btn.active::before {
            content: '';
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #fff;
            margin-right: 6px;
            vertical-align: middle;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        /* Realtime chart */
        #realtime-chart {
            margin-top: 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .rt-header {
            padding: 8px 12px 4px;
            font-size: 0.82em;
            font-weight: 600;
            color: var(--text-muted);
            background: var(--bg-alt);
            display: flex;
            justify-content: space-between;
        }
        #realtime-chart svg { display: block; }
        .rt-legend {
            display: flex;
            justify-content: center;
            gap: 14px;
            font-size: 0.78em;
            color: var(--text-muted);
            padding: 6px 0 8px;
            background: var(--bg-alt);
            font-family: var(--mono);
        }
        .rt-legend span span { margin-right: 3px; }

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
        #inline-partition text.agg-label {
            stroke: none;
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
        #partition text.agg-label {
            stroke: none;
        }
    </style>
</head>
<body>
    <div id="container">
        <h1><?= $dataModel->getPageTitle() ?><?php $uptime = $dataModel->getUptime(); if ($uptime): ?><span class="uptime">Uptime: <?= $uptime ?></span><?php endif; ?></h1>

        <?php if ($noOpcache): ?>
        <div class="notice">Zend OPcache extension not loaded &mdash; showing sample data.</div>
        <?php endif; ?>

        <div class="actions">
            <?php if (!$readonly): ?>
            <button type="button" id="reset-cache-btn">Reset cache</button>
            <?php endif; ?>
            <button class="auto-refresh-btn" id="auto-refresh-btn">Auto-refresh</button>
            <select id="auto-refresh-interval" class="auto-refresh-select">
                <option value="2000">2s</option>
                <option value="5000" selected>5s</option>
                <option value="10000">10s</option>
                <option value="30000">30s</option>
            </select>
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
                        <h3 style="padding:12px 12px 4px;font-size:0.95em;color:var(--text-muted)">Blacklisted Paths</h3>
                        <table><?= $dataModel->getBlacklistRows() ?></table>
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

        <div id="realtime-chart" style="display:none"></div>

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

    <div id="confirm-overlay">
        <div id="confirm-modal">
            <div id="confirm-title"></div>
            <div id="confirm-body"></div>
            <div id="confirm-actions">
                <button id="confirm-cancel">Cancel</button>
                <button id="confirm-ok">Confirm</button>
            </div>
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

        // Restore active tab across reloads
        var savedTab = sessionStorage.getItem('opcache-tab');
        if (savedTab) {
            var tabEl = document.getElementById(savedTab);
            if (tabEl) tabEl.checked = true;
        }
        var tabRadios = document.querySelectorAll('input[name="tab-group-1"]');
        for (var ti = 0; ti < tabRadios.length; ti++) {
            tabRadios[ti].addEventListener('change', function() {
                sessionStorage.setItem('opcache-tab', this.id);
            });
        }

        // Restore scroll positions per tab
        var contentDivs = document.querySelectorAll('.content');
        var savedScroll = {};
        try { var _ss = sessionStorage.getItem('opcache-scroll'); if (_ss) savedScroll = JSON.parse(_ss); } catch(e) {}
        for (var ci = 0; ci < contentDivs.length; ci++) {
            var tabId = contentDivs[ci].parentNode.querySelector('input[type=radio]');
            if (tabId && savedScroll[tabId.id]) {
                contentDivs[ci].scrollTop = savedScroll[tabId.id];
            }
        }
        window.addEventListener('beforeunload', function() {
            var scrollState = {};
            for (var ci = 0; ci < contentDivs.length; ci++) {
                var tabId = contentDivs[ci].parentNode.querySelector('input[type=radio]');
                if (tabId && contentDivs[ci].scrollTop > 0) {
                    scrollState[tabId.id] = contentDivs[ci].scrollTop;
                }
            }
            sessionStorage.setItem('opcache-scroll', JSON.stringify(scrollState));
        });

        var readOnly = <?= $readonly ? 'true' : 'false' ?>;
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

        // Initial draw — restore saved dataset or default to memory
        var savedDataset = sessionStorage.getItem('opcache-dataset') || 'memory';
        if (!dataset[savedDataset]) savedDataset = 'memory';

        function showDataset(key) {
            if (!dataset[key]) return;
            var nonZero = dataset[key].filter(function(v) { return v > 0; });
            if (nonZero.length > 0) {
                var info = statsInfo[key];
                drawDonut(dataset[key], info ? info.colors : donutColors);
            } else {
                svg.style.display = 'none';
            }
            updateStats(key);
        }

        showDataset(savedDataset);

        // Dataset switching
        var radios = document.querySelectorAll('input[name="dataset"]');
        for (var r = 0; r < radios.length; r++) {
            if (radios[r].value === savedDataset) radios[r].checked = true;
            radios[r].addEventListener('change', function() {
                sessionStorage.setItem('opcache-dataset', this.value);
                showDataset(this.value);
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
            var modal = document.getElementById('help-modal');
            helpTitle.textContent = key;
            var isHtml = configDocs[key].charAt(0) === '<';
            if (isHtml) {
                helpBody.innerHTML = configDocs[key];
                modal.classList.add('wide');
            } else {
                helpBody.textContent = configDocs[key];
                modal.classList.remove('wide');
            }
            var anchor = phpNetAnchors[key] || 'ini.' + key;
            helpLink.innerHTML = '<a href="https://php.net/manual/en/opcache.configuration.php#' +
                anchor + '" target="_blank" rel="noopener">php.net documentation &rarr;</a>';
            helpOverlay.classList.add('visible');
        });

        function closeHelp() { helpOverlay.classList.remove('visible'); document.getElementById('help-modal').classList.remove('wide'); }
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
        var statusColors = {
            green: 'var(--success)',
            yellow: 'var(--warning)',
            red: 'var(--danger)',
            info: 'var(--accent)'
        };

        function renderHealthCards() {
            if (!healthContainer || healthChecks.length === 0) return;
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
        renderHealthCards();

        // =============================================
        //  CONFIRMATION MODAL
        // =============================================
        var confirmOverlay = document.getElementById('confirm-overlay');
        var confirmTitle = document.getElementById('confirm-title');
        var confirmBody = document.getElementById('confirm-body');
        var confirmOk = document.getElementById('confirm-ok');
        var confirmCancel = document.getElementById('confirm-cancel');
        var confirmCallback = null;

        function showConfirm(title, bodyHtml, okLabel, onConfirm) {
            confirmTitle.textContent = title;
            confirmBody.innerHTML = bodyHtml;
            confirmOk.textContent = okLabel || 'Confirm';
            confirmCallback = onConfirm;
            confirmOverlay.classList.add('visible');
            confirmCancel.focus();
        }

        function closeConfirm() {
            confirmOverlay.classList.remove('visible');
            confirmCallback = null;
        }

        confirmOk.addEventListener('click', function() {
            if (confirmCallback) confirmCallback();
            closeConfirm();
        });
        confirmCancel.addEventListener('click', closeConfirm);
        confirmOverlay.addEventListener('click', function(e) {
            if (e.target === confirmOverlay) closeConfirm();
        });
        document.addEventListener('keyup', function(e) {
            if (e.key === 'Escape' && confirmOverlay.classList.contains('visible')) closeConfirm();
        });

        // Reset cache button
        function postAction(params) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            for (var key in params) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = params[key];
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }
        var resetBtn = document.getElementById('reset-cache-btn');
        if (resetBtn) {
            resetBtn.addEventListener('click', function(e) {
                showConfirm(
                    'Reset Entire OPcache',
                    '<p>This will invalidate <strong>every cached script</strong>, forcing PHP to recompile all files on next access. ' +
                    'This typically causes a temporary spike in CPU usage and response latency across the site.</p>' +
                    '<p class="confirm-warn">For a less disruptive approach, switch to the <strong>Scripts</strong> tab and ' +
                    'invalidate individual files by hovering over a row and clicking the <strong>\u00d7</strong> button on the right.</p>',
                    'Reset cache',
                    function() { sessionStorage.removeItem('opcache-history'); postAction({clear: '1'}); }
                );
            });
        }

        // =============================================
        //  SORTABLE SCRIPTS TABLE
        // =============================================
        var scriptList = <?= $dataModel->getScriptListJson() ?>;
        var scriptsTable = document.getElementById('scripts-table');
        var savedSort = sessionStorage.getItem('opcache-sort');
        var currentSort = { key: 'path', dir: 1 };
        if (savedSort) {
            try {
                var parsedSort = JSON.parse(savedSort);
                if (parsedSort && typeof parsedSort.key === 'string' && typeof parsedSort.dir === 'number') {
                    currentSort = parsedSort;
                }
            } catch (e) {
                sessionStorage.removeItem('opcache-sort');
            }
        }

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
                td3.className = 'path-cell';
                td3.textContent = s.path;
                td3.title = s.path;
                if (!readOnly) {
                    var btn = document.createElement('button');
                    btn.className = 'invalidate-btn';
                    btn.innerHTML = '&times;';
                    btn.title = 'Invalidate this script';
                    (function(path) {
                        btn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            showConfirm(
                                'Invalidate Script',
                                '<p>Remove this file from the OPcache. It will be recompiled on next access.</p>' +
                                '<div class="confirm-path">' + path.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>',
                                'Invalidate',
                                function() { postAction({invalidate: path}); }
                            );
                        });
                    })(s.path);
                    td3.appendChild(btn);
                }
                tr.appendChild(td1);
                tr.appendChild(td2);
                tr.appendChild(td3);
                tbody.appendChild(tr);
            }
        }

        if (scriptsTable) {
            var headers = scriptsTable.querySelectorAll('.sortable');
            for (var si = 0; si < headers.length; si++) {
                // Restore saved sort indicator
                if (headers[si].getAttribute('data-sort') === currentSort.key) {
                    headers[si].classList.add(currentSort.dir === 1 ? 'asc' : 'desc');
                }
                headers[si].addEventListener('click', function() {
                    var key = this.getAttribute('data-sort');
                    if (currentSort.key === key) {
                        currentSort.dir *= -1;
                    } else {
                        currentSort.key = key;
                        currentSort.dir = key === 'path' ? 1 : -1;
                    }
                    for (var j = 0; j < headers.length; j++) {
                        headers[j].classList.remove('asc', 'desc');
                    }
                    this.classList.add(currentSort.dir === 1 ? 'asc' : 'desc');
                    sessionStorage.setItem('opcache-sort', JSON.stringify(currentSort));
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
            var totalLeaves = collectLeaves(root).length;
            var viewArea = w * h;
            var avgArea = viewArea / Math.max(1, totalLeaves);
            var minDim = Math.max(4, Math.ceil(Math.sqrt(avgArea) * 0.8));
            if (totalLeaves > 1000) minDim = Math.max(minDim, Math.ceil(200 / Math.sqrt(Math.max(w, h))));

            var topSkippedSize = 0, topSkippedCount = 0, topRendered = 0;
            var topSkipMinX = Infinity, topSkipMinY = Infinity, topSkipMaxX = 0, topSkipMaxY = 0;
            var maxTopLeaves = Math.max(200, Math.floor((w * h) / 400));
            for (var i = 0; i < rects.length; i++) {
                var r = rects[i];
                var node = r.node;
                var isDir = node.children && node.children.length > 0;

                if (!isDir && rects.length > 8 && (r.w < minDim || r.h < minDim || topRendered >= maxTopLeaves)) {
                    topSkippedSize += node.size || 0;
                    topSkippedCount++;
                    if (r.x < topSkipMinX) topSkipMinX = r.x;
                    if (r.y < topSkipMinY) topSkipMinY = r.y;
                    if (r.x + r.w > topSkipMaxX) topSkipMaxX = r.x + r.w;
                    if (r.y + r.h > topSkipMaxY) topSkipMaxY = r.y + r.h;
                    continue;
                }

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

                        // Show immediate children; subdirs as sized blocks
                        var innerItems = [];
                        for (var ci = 0; ci < (node.children || []).length; ci++) {
                            var child = node.children[ci];
                            if (child.size !== undefined) {
                                innerItems.push(child);
                            } else {
                                innerItems.push({ name: child.name, size: getSize(child), _dir: child });
                            }
                        }
                        var innerRect = {
                            x: r.x + pad,
                            y: r.y + pad / 2 + headerH + 2,
                            w: Math.max(0, r.w - pad * 2),
                            h: Math.max(0, r.h - pad - headerH - 2)
                        };
                        if (innerRect.w > 4 && innerRect.h > 4 && innerItems.length > 0) {
                            var subRects = squarify(innerItems, innerRect);
                            for (var s = 0; s < subRects.length; s++) {
                                var sr = subRects[s];
                                var sn = sr.node;
                                if (sr.w < 3 || sr.h < 3) continue;
                                var subRect = document.createElementNS(svgNS, 'rect');
                                subRect.setAttribute('x', sr.x + 1);
                                subRect.setAttribute('y', sr.y + 1);
                                subRect.setAttribute('width', Math.max(0, sr.w - 2));
                                subRect.setAttribute('height', Math.max(0, sr.h - 2));
                                subRect.setAttribute('rx', 2);
                                if (sn._dir) {
                                    subRect.setAttribute('fill', dirColor);
                                    subRect.setAttribute('fill-opacity', '0.35');
                                    subRect.setAttribute('stroke', dirColor);
                                    subRect.setAttribute('stroke-width', '1');
                                    subRect.style.cursor = 'pointer';
                                    (function(dn) {
                                        subRect.addEventListener('click', function(e) {
                                            e.stopPropagation();
                                            drillStack.push(dn);
                                            renderTreemap(dn);
                                            updateBreadcrumb();
                                        });
                                    })(sn._dir);
                                } else {
                                    subRect.setAttribute('fill', heatColor(sn.size, leafMM.min, leafMM.max));
                                    subRect.setAttribute('fill-opacity', '0.85');
                                    subRect.setAttribute('stroke', 'var(--bg)');
                                    subRect.setAttribute('stroke-width', '1');
                                }
                                var subTitle = document.createElementNS(svgNS, 'title');
                                var subTip = (sn._dir ? sn.name + '/\n' : sn.name + '\n') + sizeForHumans(sn.size);
                                if (sn.hits !== undefined) subTip += '\nHits: ' + formatValue(sn.hits);
                                if (sn._dir) subTip += '\nClick to drill in';
                                subTitle.textContent = subTip;
                                subRect.appendChild(subTitle);
                                g.appendChild(subRect);
                                if (sr.w > 36 && sr.h > 13) {
                                    var st = document.createElementNS(svgNS, 'text');
                                    st.setAttribute('x', sr.x + 4);
                                    st.setAttribute('y', sr.y + 13);
                                    st.setAttribute('fill', '#fff');
                                    st.setAttribute('font-size', '10px');
                                    var sMaxC = Math.floor((sr.w - 8) / 6);
                                    var sLabel = (sn.name || '') + (sn._dir ? '/' : '');
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
                    topRendered++;
                }

                tSvg.appendChild(g);
            }
            if (topSkippedCount > 3) {
                var topAggW = Math.max(0, topSkipMaxX - topSkipMinX - 2);
                var topAggH = Math.max(0, topSkipMaxY - topSkipMinY - 2);
                var ag = document.createElementNS(svgNS, 'g');
                var aRect = document.createElementNS(svgNS, 'rect');
                aRect.setAttribute('x', topSkipMinX + 1);
                aRect.setAttribute('y', topSkipMinY + 1);
                aRect.setAttribute('width', topAggW);
                aRect.setAttribute('height', topAggH);
                aRect.setAttribute('rx', 2);
                aRect.setAttribute('fill', 'var(--fg)');
                aRect.setAttribute('fill-opacity', '0.12');
                var topAggLine1 = '+' + formatValue(topSkippedCount) + ' files';
                var topAggLine2 = sizeForHumans(topSkippedSize);
                var aTitle = document.createElementNS(svgNS, 'title');
                aTitle.textContent = topAggLine1 + ' (' + topAggLine2 + ')';
                aRect.appendChild(aTitle);
                ag.appendChild(aRect);
                if (topAggW > 40 && topAggH > 14) {
                    var topAggText = document.createElementNS(svgNS, 'text');
                    topAggText.setAttribute('class', 'agg-label');
                    topAggText.setAttribute('text-anchor', 'middle');
                    topAggText.setAttribute('fill', '#222');
                    topAggText.setAttribute('font-weight', '600');
                    topAggText.setAttribute('font-size', '11px');
                    var topSpan1 = document.createElementNS(svgNS, 'tspan');
                    topSpan1.setAttribute('x', topSkipMinX + topAggW / 2 + 1);
                    topSpan1.setAttribute('y', topSkipMinY + topAggH / 2 - 1);
                    topSpan1.textContent = topAggLine1;
                    topAggText.appendChild(topSpan1);
                    if (topAggH > 28) {
                        var topSpan2 = document.createElementNS(svgNS, 'tspan');
                        topSpan2.setAttribute('x', topSkipMinX + topAggW / 2 + 1);
                        topSpan2.setAttribute('y', topSkipMinY + topAggH / 2 + 13);
                        topSpan2.textContent = topAggLine2;
                        topAggText.appendChild(topSpan2);
                    }
                    ag.appendChild(topAggText);
                }
                tSvg.appendChild(ag);
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
        // =============================================
        //  AUTO-REFRESH TOGGLE
        // =============================================
        var autoRefreshBtn = document.getElementById('auto-refresh-btn');
        var autoRefreshSelect = document.getElementById('auto-refresh-interval');
        var autoRefreshInterval = null;
        var AUTO_REFRESH_MS = parseInt(sessionStorage.getItem('opcache-refresh-ms') || '5000', 10);
        if (autoRefreshSelect) {
            autoRefreshSelect.value = String(AUTO_REFRESH_MS);
        }

        // =============================================
        //  REALTIME MONITORING CHART
        // =============================================
        var rtContainer = document.getElementById('realtime-chart');
        var RT_KEY = 'opcache-history';
        var RT_MAX = 120;
        var RT_STALE = 300000; // 5 min
        var rtHistory = [];
        try { var _s = sessionStorage.getItem(RT_KEY); if (_s) rtHistory = JSON.parse(_s); } catch(e) {}

        function rtNice(v) {
            var e = Math.pow(10, Math.floor(Math.log10(v)));
            var f = v / e;
            return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * e;
        }

        function rtFmtRate(v) {
            if (v >= 1000) return (v / 1000).toFixed(1) + 'k/s';
            if (v >= 100) return Math.round(v) + '/s';
            if (v >= 1) return v.toFixed(1) + '/s';
            return v.toFixed(2) + '/s';
        }

        function rtShortSizeD(b, d) {
            if (b > 1048576) return (b / 1048576).toFixed(d) + 'M';
            if (b > 1024) return (b / 1024).toFixed(d) + 'k';
            return Math.round(b) + 'B';
        }

        function pushRealtimePoint() {
            var pt = {
                t: Date.now(),
                h: dataset.hits[1],
                m: dataset.hits[0],
                mem: dataset.memory[0]
            };
            if (rtHistory.length > 0 && pt.t - rtHistory[rtHistory.length - 1].t > RT_STALE) {
                rtHistory = [];
            }
            rtHistory.push(pt);
            if (rtHistory.length > RT_MAX) rtHistory = rtHistory.slice(-RT_MAX);
            try { sessionStorage.setItem(RT_KEY, JSON.stringify(rtHistory)); } catch(e) {}
        }

        function renderRealtimeChart() {
            if (!rtContainer) return;
            rtContainer.innerHTML = '';
            if (rtHistory.length < 2) {
                rtContainer.style.display = 'none';
                return;
            }
            rtContainer.style.display = 'block';

            var rates = [];
            for (var ri = 1; ri < rtHistory.length; ri++) {
                var dt = (rtHistory[ri].t - rtHistory[ri - 1].t) / 1000;
                if (dt <= 0) continue;
                rates.push({
                    t: rtHistory[ri].t,
                    hps: Math.max(0, (rtHistory[ri].h - rtHistory[ri - 1].h) / dt),
                    mps: Math.max(0, (rtHistory[ri].m - rtHistory[ri - 1].m) / dt),
                    mem: rtHistory[ri].mem
                });
            }

            if (rates.length < 1) {
                rtContainer.style.display = 'none';
                return;
            }

            var W = rtContainer.clientWidth || 380;
            var H = 150;
            var P = {l: 44, r: 44, t: 8, b: 20};
            var cw = W - P.l - P.r, ch = H - P.t - P.b;

            var tMin = rates[0].t, tMax = rates[rates.length - 1].t;
            if (tMax === tMin) tMax = tMin + 1;

            var maxRate = 0, minMem = Infinity, maxMem = 0;
            for (var ri = 0; ri < rates.length; ri++) {
                if (rates[ri].hps > maxRate) maxRate = rates[ri].hps;
                if (rates[ri].mps > maxRate) maxRate = rates[ri].mps;
                if (rates[ri].mem < minMem) minMem = rates[ri].mem;
                if (rates[ri].mem > maxMem) maxMem = rates[ri].mem;
            }

            maxRate = rtNice(maxRate || 1);
            var memSpan = maxMem - minMem;
            if (memSpan === 0) memSpan = maxMem * 0.05 || 1;
            minMem = Math.max(0, minMem - memSpan * 0.2);
            maxMem = maxMem + memSpan * 0.2;

            function rtSx(t) { return P.l + ((t - tMin) / (tMax - tMin)) * cw; }
            function rtSyR(v) { return P.t + ch - (v / maxRate) * ch; }
            function rtSyM(v) { return P.t + ch - ((v - minMem) / (maxMem - minMem)) * ch; }

            // Header
            var elapsed = (tMax - tMin) / 1000;
            var hdr = document.createElement('div');
            hdr.className = 'rt-header';
            var hdrL = document.createElement('span');
            hdrL.textContent = 'Live Activity';
            var hdrR = document.createElement('span');
            hdrR.textContent = elapsed >= 120
                ? Math.floor(elapsed / 60) + 'm ' + Math.round(elapsed % 60) + 's window'
                : Math.round(elapsed) + 's window';
            hdr.appendChild(hdrL);
            hdr.appendChild(hdrR);
            rtContainer.appendChild(hdr);

            // SVG
            var rtSvg = document.createElementNS(svgNS, 'svg');
            rtSvg.setAttribute('width', W);
            rtSvg.setAttribute('height', H);

            // Chart background
            var rtBg = document.createElementNS(svgNS, 'rect');
            rtBg.setAttribute('x', P.l); rtBg.setAttribute('y', P.t);
            rtBg.setAttribute('width', cw); rtBg.setAttribute('height', ch);
            rtBg.setAttribute('fill', 'var(--bg)'); rtBg.setAttribute('rx', '2');
            rtSvg.appendChild(rtBg);

            // Grid
            for (var gi = 0; gi <= 4; gi++) {
                var gy = P.t + (gi / 4) * ch;
                var gl = document.createElementNS(svgNS, 'line');
                gl.setAttribute('x1', P.l); gl.setAttribute('x2', W - P.r);
                gl.setAttribute('y1', gy); gl.setAttribute('y2', gy);
                gl.setAttribute('stroke', 'var(--border)'); gl.setAttribute('stroke-width', '0.5');
                rtSvg.appendChild(gl);
            }

            // Line helper
            function rtLine(data, yFn, color) {
                var d = '';
                for (var i = 0; i < data.length; i++) {
                    d += (i === 0 ? 'M' : 'L') + rtSx(data[i].t).toFixed(1) + ',' + yFn(data[i]).toFixed(1);
                }
                var p = document.createElementNS(svgNS, 'path');
                p.setAttribute('d', d);
                p.setAttribute('stroke', color);
                p.setAttribute('stroke-width', '1.5');
                p.setAttribute('fill', 'none');
                p.setAttribute('stroke-linejoin', 'round');
                rtSvg.appendChild(p);
            }

            rtLine(rates, function(r) { return rtSyM(r.mem); }, 'steelblue');
            rtLine(rates, function(r) { return rtSyR(r.hps); }, '#1FB437');
            rtLine(rates, function(r) { return rtSyR(r.mps); }, '#B41F1F');

            // Axis labels
            function rtLabel(x, y, text, anchor) {
                var t = document.createElementNS(svgNS, 'text');
                t.setAttribute('x', x); t.setAttribute('y', y);
                t.setAttribute('text-anchor', anchor || 'start');
                t.setAttribute('fill', 'var(--text-muted)');
                t.setAttribute('font-size', '9px');
                t.setAttribute('font-family', 'var(--mono)');
                t.textContent = text;
                rtSvg.appendChild(t);
            }

            // Find minimum precision where top/bottom labels differ
            var memHi, memLo;
            for (var md = 1; md <= 4; md++) {
                memHi = rtShortSizeD(maxMem, md);
                memLo = rtShortSizeD(minMem, md);
                if (memHi !== memLo) break;
            }

            rtLabel(P.l - 4, P.t + 8, rtFmtRate(maxRate), 'end');
            rtLabel(P.l - 4, P.t + ch, '0/s', 'end');
            if (memHi !== memLo) {
                rtLabel(W - P.r + 4, P.t + 8, memHi);
                rtLabel(W - P.r + 4, P.t + ch, memLo);
            } else {
                rtLabel(W - P.r + 4, P.t + ch / 2 + 4, memHi);
            }

            rtContainer.appendChild(rtSvg);

            // Legend with current values
            var last = rates[rates.length - 1];
            var legend = document.createElement('div');
            legend.className = 'rt-legend';
            legend.innerHTML =
                '<span><span style="color:#1FB437">\u25CF</span> ' + rtFmtRate(last.hps) + ' hits</span>' +
                '<span><span style="color:#B41F1F">\u25CF</span> ' + rtFmtRate(last.mps) + ' misses</span>' +
                '<span><span style="color:steelblue">\u25CF</span> ' + sizeForHumans(last.mem) + '</span>';
            rtContainer.appendChild(legend);
        }

        // =============================================
        //  AJAX DATA REFRESH
        // =============================================
        function refreshData() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '?json=1');
            xhr.onload = function() {
                if (xhr.status !== 200) { stopAutoRefresh(); return; }
                var data;
                try { data = JSON.parse(xhr.responseText); } catch(e) { stopAutoRefresh(); return; }

                // Update donut chart data
                dataset = data.dataset;

                // Recompute statsInfo human-readable values from raw data
                var memTotal = dataset.memory[0] + dataset.memory[1] + dataset.memory[2];
                var wastedPct = memTotal > 0 ? ((dataset.memory[2] / memTotal) * 100).toFixed(2) : '0.00';
                statsInfo.memory.values = [
                    sizeForHumans(dataset.memory[0]),
                    sizeForHumans(dataset.memory[1]),
                    sizeForHumans(dataset.memory[2]) + ' (' + wastedPct + '%)'
                ];
                if (dataset.jit && statsInfo.jit) {
                    statsInfo.jit.values = [sizeForHumans(dataset.jit[0]), sizeForHumans(dataset.jit[1])];
                }
                if (dataset.interned && statsInfo.interned) {
                    statsInfo.interned.values = [sizeForHumans(dataset.interned[0]), sizeForHumans(dataset.interned[1])];
                }

                // Re-render donut + stats for current dataset selection
                var currentKey = sessionStorage.getItem('opcache-dataset') || 'memory';
                if (!dataset[currentKey]) currentKey = 'memory';
                showDataset(currentKey);

                // Update health cards
                healthChecks = data.healthChecks;
                renderHealthCards();

                // Update scripts table
                scriptList = data.scriptList;
                renderScriptTable();

                // Update treemap data and re-render at root
                scriptData = data.scriptData;
                if (!overlay.classList.contains('visible')) {
                    drillStack = [scriptData];
                    activePartitionEl = inlinePartitionEl;
                    activeBreadcrumbEl = inlineBreadcrumbEl;
                    renderTreemap(scriptData, inlinePartitionEl);
                    updateBreadcrumb();
                }

                // Update uptime
                var uptimeEl = document.querySelector('.uptime');
                if (uptimeEl && data.uptime) {
                    uptimeEl.textContent = 'Uptime: ' + data.uptime;
                }

                // Update script count in tab label
                var scriptsLabel = document.querySelector('label[for="tab-scripts"]');
                if (scriptsLabel) {
                    scriptsLabel.textContent = 'Scripts (' + formatValue(data.scriptCount) + ')';
                }

                // Update status table
                var statusTabEl = document.getElementById('tab-status');
                if (statusTabEl) {
                    var statusTbl = statusTabEl.parentNode.querySelector('table');
                    if (statusTbl) statusTbl.innerHTML = data.statusRows;
                }

                // Push realtime chart data point and re-render
                pushRealtimePoint();
                renderRealtimeChart();
            };
            xhr.onerror = function() {
                stopAutoRefresh();
            };
            xhr.send();
        }

        function startAutoRefresh() {
            sessionStorage.setItem('opcache-auto-refresh', '1');
            autoRefreshBtn.classList.add('active');
            autoRefreshBtn.textContent = 'Refreshing';
            pushRealtimePoint();
            renderRealtimeChart();
            autoRefreshInterval = setInterval(refreshData, AUTO_REFRESH_MS);
        }

        function stopAutoRefresh() {
            sessionStorage.removeItem('opcache-auto-refresh');
            sessionStorage.removeItem('opcache-history');
            rtHistory = [];
            autoRefreshBtn.classList.remove('active');
            autoRefreshBtn.textContent = 'Auto-refresh';
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
            if (rtContainer) { rtContainer.style.display = 'none'; rtContainer.innerHTML = ''; }
        }

        if (autoRefreshBtn) {
            autoRefreshBtn.addEventListener('click', function() {
                if (autoRefreshInterval) {
                    stopAutoRefresh();
                } else {
                    startAutoRefresh();
                }
            });
            if (sessionStorage.getItem('opcache-auto-refresh') === '1') {
                startAutoRefresh();
            }
        }

        if (autoRefreshSelect) {
            autoRefreshSelect.addEventListener('change', function() {
                AUTO_REFRESH_MS = parseInt(this.value, 10);
                sessionStorage.setItem('opcache-refresh-ms', String(AUTO_REFRESH_MS));
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                    autoRefreshBtn.textContent = 'Refreshing';
                    autoRefreshInterval = setInterval(refreshData, AUTO_REFRESH_MS);
                }
            });
        }
    })();
    </script>
</body>
</html>
