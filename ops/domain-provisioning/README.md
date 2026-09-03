# StudentXces Automated Domain Provisioning Host Runner

## Architecture Overview
The StudentXces automated domain provisioning system operates on a decoupled asynchronous architecture:

1. **Application Layer (Docker PHP Container):**
   - Super Admin queues a provisioning request in database table `domain_provisioning_requests`.
   - Laravel web process has **zero host root, sudo, or filesystem access**.
2. **Host Runner (Root Systemd Timer on VPS):**
   - Triggers `/usr/local/sbin/studentxces-domain-runner` every 30 seconds.
   - Claims pending request via `php artisan tenancy:provisioning:claim-next --json`.
   - Generates isolated `/etc/nginx/studentxces-tenants.d/tenant_<id>_<host>.conf`.
   - Issues dedicated Let's Encrypt certificate via webroot ACME challenge.
   - Reloads Nginx and reports completion via `php artisan tenancy:provisioning:mark-success`.
3. **Canonical Activation Revalidation:**
   - Application revalidates TLS handshake via `SystemHttpsProbe` before marking the domain `status: active` and `ssl_status: active` in the database.

---

## Future Host Installation Instructions (DO NOT EXECUTE IN P1P.2B)

When authorized for production deployment:

```bash
# 1. Install host script
cp ops/domain-provisioning/studentxces-domain-runner /usr/local/sbin/studentxces-domain-runner
chmod 0750 /usr/local/sbin/studentxces-domain-runner
chown root:root /usr/local/sbin/studentxces-domain-runner

# 2. Create tenant include directory
mkdir -p /etc/nginx/studentxces-tenants.d

# 3. Create HTTP-context include in Nginx
cat << 'EOF' > /etc/nginx/conf.d/studentxces-tenants.conf
include /etc/nginx/studentxces-tenants.d/*.conf;
EOF

# 4. Verify Nginx syntax
nginx -t
systemctl reload nginx

# 5. Install systemd timer
cp ops/domain-provisioning/studentxces-domain-runner.service /etc/systemd/system/
cp ops/domain-provisioning/studentxces-domain-runner.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now studentxces-domain-runner.timer
```
