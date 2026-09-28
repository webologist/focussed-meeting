# Deploying Focused Meetings

## Option A: Hostinger or any cPanel shared hosting

1. **Create the database.** In hPanel → Databases → MySQL Databases, create a database and a user, and give the user all privileges on it. Note the database name, user and password (on Hostinger they look like `u123456789_meetings`).

2. **Upload the code.** Download the repository as a ZIP from GitHub and upload it with File Manager, or use Git (hPanel → Advanced → Git).
   - **Best:** put the project in a folder outside `public_html` (for example `/home/USER/focused-meetings`) and point your domain or subdomain’s document root at `/home/USER/focused-meetings/public`.
   - **If you can’t change the document root:** upload everything into `public_html`. The included root `.htaccess` sends all traffic into `/public` and blocks direct access to `app`, `config`, `storage` and `database`.

3. **Configure.** Copy `config/config.sample.php` to `config/config.php` and set:
   - `app.url` to your full URL, for example `https://meetings.yourdomain.com` (no trailing slash)
   - `app.key` to 64 random hex characters (Hostinger → Advanced → SSH, run `php -r "echo bin2hex(random_bytes(32));"`, or use any password generator that outputs hex). **Keep it safe and never change it after go-live**, because it encrypts every company’s saved passwords and tokens.
   - `db` with the database details from step 1 (host is usually `localhost`)
   - `mail` with a platform mailbox, for example `no-reply@yourdomain.com` on `smtp.hostinger.com`, port 465, `ssl`. It sends sign-up verification, password resets and team invitations.

4. **Create the tables.** Either run `php database/install.php` over SSH, or open phpMyAdmin, select the database and import `database/schema.sql`.

5. **Make storage writable.** `storage/logs`, `storage/cache` and `storage/uploads` must be writable by PHP (755 folders are usually enough on Hostinger).

6. **Turn on SSL** for the domain (hPanel → Security → SSL) and make sure `app.url` starts with `https://`.

7. **Add the cron job.** hPanel → Advanced → Cron Jobs → every 5 minutes:
   ```
   /usr/bin/php /home/USER/focused-meetings/cron/run.php >> /home/USER/focused-meetings/storage/logs/cron.log 2>&1
   ```
   Adjust the path to where you uploaded the project (for the `public_html` layout it is `/home/USER/public_html/cron/run.php`).

8. **Sign up.** Open `https://your-domain/register`, create the first company, confirm the email, then go to **Integrations** to connect the company’s email.

9. **Set PHP options** (hPanel → Advanced → PHP Configuration): PHP 8.1+, `upload_max_filesize` and `post_max_size` at least 32M for note images.

## Option B: VPS (Ubuntu, Nginx, PHP-FPM)

```bash
sudo apt install nginx mariadb-server php8.3-fpm php8.3-mysql php8.3-curl php8.3-mbstring php8.3-intl
git clone https://github.com/webologist/focused-meetings.git /var/www/focused-meetings
cd /var/www/focused-meetings && cp config/config.sample.php config/config.php   # edit it
php database/install.php
sudo chown -R www-data:www-data storage
```

Nginx server block:

```nginx
server {
    server_name meetings.example.com;
    root /var/www/focused-meetings/public;
    index index.php;
    client_max_body_size 32m;
    location / { try_files $uri /index.php?$query_string; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
    location ~ /\. { deny all; }
}
```

Then add HTTPS with `certbot --nginx` and a cron entry:

```
*/5 * * * * www-data php /var/www/focused-meetings/cron/run.php >> /var/www/focused-meetings/storage/logs/cron.log 2>&1
```

## After go-live checklist

- `app.env` is `production` (errors are logged to `storage/logs`, not shown)
- `https://your-domain/config/config.php` returns 403 or 404
- Sign-up email arrives (check spam; set SPF/DKIM for the platform mailbox)
- Cron log shows a line every 5 minutes
- Back up the database daily and keep a copy of `config/config.php` (the `app.key` in particular)
- To stop public sign-ups (for a private deployment), set `app.allow_signups` to `false`
