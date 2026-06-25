# Bootgly Benchmarks — Laravel — nginx fronting PHP-FPM (rendered per start)
# Placeholders: {{RUN}} {{ROOT}} {{PORT}} {{WORKERS}} {{NGINX_PREFIX}}
# Routing mirrors TechEmpower's Laravel nginx.conf: every request is passed
# straight to public/index.php over fastcgi (no try_files / no filesystem stat).

worker_processes {{WORKERS}};
worker_rlimit_nofile 200000;
daemon on;
pid {{RUN}}/nginx.pid;
error_log stderr crit;

events {
    worker_connections 16384;
    multi_accept off;
}

http {
    include {{NGINX_PREFIX}}/mime.types;
    default_type application/octet-stream;

    access_log off;
    error_log stderr crit;
    server_tokens off;

    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    # 5s, not 65s: during a 10s measured load traffic is continuous, so
    # connections never idle out (in-load keepalive throughput unchanged), but
    # between loads idle client connections close fast instead of lingering ~65s
    # in nginx keepalive — otherwise the next route's preflight queues behind
    # them and read-times-out (turns a working DB route into N/A).
    keepalive_timeout 5;
    keepalive_requests 1000000;

    client_body_temp_path {{RUN}}/body;
    fastcgi_temp_path {{RUN}}/fastcgi;

    fastcgi_buffers 256 16k;
    fastcgi_buffer_size 128k;
    fastcgi_busy_buffers_size 256k;
    fastcgi_temp_file_write_size 256k;
    reset_timedout_connection on;

    upstream fastcgi_backend {
        server unix:{{RUN}}/fpm.sock;
        keepalive 256;
    }

    server {
        listen {{PORT}} reuseport;
        server_name _;
        root {{ROOT}};

        location / {
            fastcgi_pass fastcgi_backend;
            fastcgi_keep_conn on;
            fastcgi_param SCRIPT_FILENAME $document_root/index.php;
            fastcgi_param PATH_INFO $uri;
            include {{NGINX_PREFIX}}/fastcgi_params;
        }
    }
}
