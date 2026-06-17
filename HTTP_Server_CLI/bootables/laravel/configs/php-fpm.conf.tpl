; Bootgly Benchmarks — Laravel — PHP-FPM 8.4 pool (rendered per start)
; Placeholders: {{RUN}} {{CHILDREN}} {{JIT}} {{APP}} {{USER}}
; opcache settings mirror TechEmpower's Laravel reference (deploy/conf/php.ini).

[global]
pid = {{RUN}}/php-fpm.pid
error_log = /dev/null
log_level = error
daemonize = yes

[www]
listen = {{RUN}}/fpm.sock
listen.mode = 0660
listen.backlog = 65535

; static process pool. Each child = 1 blocking request = at most 1 PG connection,
; so {{CHILDREN}} also caps concurrent Postgres connections (keep < PG max_connections).
pm = static
pm.max_children = {{CHILDREN}}
pm.max_requests = 0

clear_env = no
catch_workers_output = no

; --- OPcache (TechEmpower-tuned) ---
php_admin_value[opcache.enable] = 1
php_admin_value[opcache.enable_cli] = 0
php_admin_value[opcache.memory_consumption] = 256
php_admin_value[opcache.interned_strings_buffer] = 16
php_admin_value[opcache.max_accelerated_files] = 30000
php_admin_value[opcache.validate_timestamps] = 0
php_admin_value[opcache.save_comments] = 0
php_admin_value[opcache.enable_file_override] = 1
php_admin_value[opcache.huge_code_pages] = 1
php_admin_value[opcache.jit] = {{JIT}}
php_admin_value[opcache.jit_buffer_size] = 128M
; Preload the framework into shared memory at master start (TechEmpower-style)
php_admin_value[opcache.preload] = {{APP}}/opcache_preload.php
php_admin_value[opcache.preload_user] = {{USER}}
php_admin_value[realpath_cache_size] = 4096K
php_admin_value[realpath_cache_ttl] = 600
php_admin_value[memory_limit] = 512M
php_admin_flag[display_errors] = off

; Disable Xdebug for this pool — it overrides zend_execute_ex, which force-disables
; JIT and adds heavy per-opcode overhead. (Also set XDEBUG_MODE=off in the launcher
; env so the override is never installed at startup.)
php_admin_value[xdebug.mode] = off
