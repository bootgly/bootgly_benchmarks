# Bootgly Benchmarks — Laravel — Apache (mpm_event) → PHP-FPM (rendered per start)
# Placeholders: {{RUN}} {{ROOT}} {{PORT}} {{MODULES}}
# Self-contained, runs as a non-root user (no User/Group directives).
# Routing mirrors the nginx variant: every request goes straight to index.php
# via mod_proxy_fcgi (ProxyPassMatch) — no mod_rewrite, no filesystem stat.

ServerName 127.0.0.1
ServerRoot {{RUN}}
DefaultRuntimeDir {{RUN}}
PidFile {{RUN}}/apache.pid
Mutex file:{{RUN}}

Listen {{PORT}}
ErrorLog /dev/null
LogLevel crit

LoadModule mpm_event_module {{MODULES}}/mod_mpm_event.so
LoadModule authz_core_module {{MODULES}}/mod_authz_core.so
LoadModule proxy_module {{MODULES}}/mod_proxy.so
LoadModule proxy_fcgi_module {{MODULES}}/mod_proxy_fcgi.so

<IfModule mpm_event_module>
    StartServers 8
    ServerLimit 16
    ThreadLimit 64
    ThreadsPerChild 64
    MinSpareThreads 128
    MaxSpareThreads 1024
    MaxRequestWorkers 1024
    MaxConnectionsPerChild 0
</IfModule>

DocumentRoot {{ROOT}}

<Directory {{ROOT}}>
    Require all granted
</Directory>

# Send every request straight to the front controller over the FPM socket.
# fcgi://localhost{{ROOT}}/index.php sets SCRIPT_FILENAME; Laravel routes on REQUEST_URI.
# (mod_proxy_fcgi enablereuse was tried and serialized throughput on the unix socket
# — left off; Apache stays slower than the nginx variant for FPM proxying.)
ProxyPassMatch "^/.*$" "unix:{{RUN}}/fpm.sock|fcgi://localhost{{ROOT}}/index.php"
