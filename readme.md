# Pin Throttle

**Contributors:** mrpin  
**Tags:** security, throttle, rate limiting, bot protection, ddos protection, ip blocking, request limit  
**Requires at least:** 5.0  
**Tested up to:** 6.6  
**Stable tag:** 1.1.0  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

---

## Description

**Pin Throttle** is a lightweight and secure plugin that helps protect your WordPress site by limiting the number of requests from a single IP address within a minute. It's ideal for preventing abuse, bot traffic, and DDoS-like attacks without complex configuration.

### Key Features:
- ✅ **Rate limiting** – Block IPs exceeding X requests per minute.
- ✅ **IP Whitelisting** – Exclude trusted IPs (e.g., admins, CDNs).
- ✅ **Email Notifications** – Get alerts when mass attacks are detected.
- ✅ **Detailed Logging** – Logs stored in database and optionally in files.
- ✅ **Auto Cleanup** – Automatically delete old logs to save space.
- ✅ **Statistics Dashboard** – View top IPs, user agents, blocked requests, and hourly activity.
- ✅ **429 Too Many Requests** – Standard-compliant blocking response.
- ✅ **Lightweight & Fast** – Uses caching and efficient queries.

Perfect for small to medium sites needing basic but effective protection.

---

## Installation

1. Upload the `pin-throttle` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **Settings > Pin Throttle** to configure:
   - Set requests per minute limit (default: 30)
   - Set block time in minutes (default: 30)
   - Add IPs to whitelist (one per line)
   - Enable email notifications and add recipient emails
   - Enable file logging (optional)
   - Set auto-cleanup days (default: 7)
4. Save settings.

---

## Frequently Asked Questions

### How does it detect the visitor's IP?

Pin Throttle checks `HTTP_CLIENT_IP`, `HTTP_X_FORWARDED_FOR`, and `REMOTE_ADDR` in order, using the first valid IP. It works behind most reverse proxies and CDNs.

### Can I whitelist my IP to avoid being blocked?

Yes! Go to **Settings > Pin Throttle > IP Whitelist** and add your IP address (one per line).

### Where are logs stored?

Logs are stored in the database (`wp_pin_throttle_logs` table). Optionally, you can enable file logging to `wp-content/uploads/pin-throttle.log`.

### Does it slow down my site?

No. The plugin uses minimal overhead, early `init` hook, and object caching for request counts. It skips admin, AJAX, REST, cron, and CLI requests.

### What happens when an IP is blocked?

The visitor receives a `429 Too Many Requests` HTTP status and a polite message. They must wait the configured block time before trying again.

### Can I get notified about attacks?

Yes! Enable "Enable email notifications for attacks" and enter one or more email addresses. You’ll get an email if an IP exceeds the limit.

---

## Screenshots

1. Settings page with throttling options and whitelist.
2. Statistics dashboard showing top IPs and request activity.
3. Email notification example when an attack is detected.

---

## Changelog

### 1.1.0
- Added: Input sanitization and validation for all settings
- Added: IP and email validation in admin
- Added: Object caching for request counting
- Added: Log file rotation (max 10MB)
- Added: Persistent notification cooldown via options
- Improved: Database index for faster queries
- Fixed: Minor security and performance improvements
- Updated: Code structure and comments

### 1.0.0
- Initial release
- Request throttling by IP
- Database and file logging
- Admin settings and statistics
- Email notifications for attacks
- Auto cleanup of old logs

---

## Upgrade Notice

### 1.1.0
Security and performance update. Adds input validation, caching, and log rotation. Recommended for all users.

---

## License

Pin Throttle is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

Pin Throttle is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see <https://www.gnu.org/licenses/>.