<?php

namespace App\Services;

use InvalidArgumentException;

class NginxTenantConfigGenerator
{
    /**
     * Get the standardized configuration filename for a tenant domain.
     */
    public static function getFilename(int $domainId, string $hostname): string
    {
        $validatedHost = TenantHostnameValidator::validate($hostname);
        $safeSuffix    = str_replace(['.', '-'], '_', $validatedHost);

        return "tenant_{$domainId}_{$safeSuffix}.conf";
    }

    /**
     * Generate Stage A (HTTP-only / ACME Challenge) Nginx configuration.
     */
    public static function generateHttpConfig(string $hostname): string
    {
        $validatedHost = TenantHostnameValidator::validate($hostname);

        return <<<NGINX
# Managed automatically by StudentXces Provisioner — DO NOT EDIT MANUALLY

server {
    listen 80;
    server_name {$validatedHost};

    location /.well-known/acme-challenge/ {
        root /var/www/html;
        try_files \$uri =404;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}
NGINX;
    }

    /**
     * Generate Stage B (Full HTTPS with reverse proxy to inner container) Nginx configuration.
     */
    public static function generateHttpsConfig(int $domainId, string $hostname): string
    {
        if ($domainId <= 0) {
            throw new InvalidArgumentException("Domain ID must be a positive integer.");
        }

        $validatedHost = TenantHostnameValidator::validate($hostname);
        $certName      = "studentxces-tenant-{$domainId}";

        return <<<NGINX
# Managed automatically by StudentXces Provisioner — DO NOT EDIT MANUALLY

server {
    listen 80;
    server_name {$validatedHost};

    location /.well-known/acme-challenge/ {
        root /var/www/html;
        try_files \$uri =404;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    server_name {$validatedHost};

    ssl_certificate /etc/letsencrypt/live/{$certName}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{$certName}/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    client_max_body_size 64M;

    # StudentXces Compression
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types
        application/javascript
        application/json
        application/x-javascript
        application/xml
        image/svg+xml
        text/css
        text/javascript
        text/plain
        text/xml;

    location /.well-known/acme-challenge/ {
        root /var/www/html;
        try_files \$uri =404;
    }

    location / {
        proxy_pass http://127.0.0.1:8082;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Port \$server_port;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }
}
NGINX;
    }
}
