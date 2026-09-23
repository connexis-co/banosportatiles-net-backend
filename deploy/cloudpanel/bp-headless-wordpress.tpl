#{"rootDirectory":"","phpVersion":"8.4"}
# bp-headless — "BP-Headless-WordPress-v1": CloudPanel v2 "WordPress" vhost template
# (github.com/cloudpanel-io/vhost-templates, v2/WordPress) adapted for the headless CMS:
# single site (no multisite rewrites), host never indexed, no PHP in uploads.
# Every non-file path goes to WordPress: /wp-json and /wp-admin work as usual and the
# bp-headless plugin 301-redirects the front-end to the public site (BP_FRONTEND_URL).
server {
  listen 80;
  listen [::]:80;
  listen 443 ssl http2;
  listen [::]:443 ssl http2;
  {{ssl_certificate_key}}
  {{ssl_certificate}}
  {{server_name}}
  {{root}}

  {{nginx_access_log}}
  {{nginx_error_log}}

  if ($scheme != "https") {
    rewrite ^ https://$host$uri permanent;
  }

  location ~ /.well-known {
    auth_basic off;
    allow all;
  }

  {{settings}}

  # bp-headless: the CMS host must never be indexed (PHP also sends this header).
  add_header X-Robots-Tag "noindex, nofollow" always;

  location = /xmlrpc.php {
    deny all;
  }

  # bp-headless: no PHP execution in uploads; no debug log or dotfiles over HTTP.
  location ~* ^/wp-content/uploads/.*\.php$ {
    deny all;
  }

  location ~* ^/wp-content/debug\.log$ {
    deny all;
  }

  location ~ /\.(?!well-known) {
    deny all;
  }

  try_files $uri $uri/ /index.php?$args;
  index index.php index.html;

  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_intercept_errors on;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    try_files $uri =404;
    fastcgi_read_timeout 3600;
    fastcgi_send_timeout 3600;
    fastcgi_param HTTPS $fastcgi_https;
    fastcgi_pass 127.0.0.1:{{php_fpm_port}};
    fastcgi_param PHP_VALUE "{{php_settings}}";
  }

  location ~* ^.+\.(css|js|jpg|jpeg|gif|png|ico|gz|svg|svgz|ttf|otf|woff|woff2|eot|mp4|ogg|ogv|webm|webp|avif|pdf|zip|swf)$ {
    add_header Access-Control-Allow-Origin "*";
    add_header X-Robots-Tag "noindex, nofollow" always;
    expires max;
    access_log off;
  }

  if (-f $request_filename) {
    break;
  }
}
