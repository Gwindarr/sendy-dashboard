# Sendy Dashboard

A read-only subscriber engagement dashboard for [Sendy](https://sendy.co). Shows which subscribers are actually opening your autoresponder emails and which ones are dead weight — something Sendy doesn't surface on its own.

## What it does

Sendy tracks autoresponder opens in its database but doesn't expose this data in the UI. This dashboard reads that data and gives you:

- **Engagement status** for every subscriber: Engaged, Active, Pending, Warming, or Dead
- **Visual open tracking** — a dot grid showing which autoresponder emails each subscriber opened
- **Google Ads attribution** — identifies subscribers who came from Google Ads (via gclid in the referrer)
- **Filters** — by time range, source (ads/organic), list, engagement status, and email search
- **Sortable columns** — click any column header to sort
- **CSV export** — download filtered results for use in Google Ads offline conversions or other tools
- **"No activity" flag** — highlights subscribers whose last activity timestamp matches their signup (meaning they never opened anything)

## Why this exists

If you're running Google Ads to a Sendy-powered email list, you need to know which ad clicks turn into real subscribers vs. bots. Google's conversion tracking counts the signup, but can't tell you if the person actually opens your emails. This dashboard can.

## Requirements

- PHP 7.4+ (tested on PHP 8.2)
- A working [Sendy](https://sendy.co) installation with at least one autoresponder sequence
- MySQL/MariaDB (whatever Sendy uses)
- Web server with PHP support (Apache, Nginx, LiteSpeed, etc.)

## Installation

### 1. Upload files

Upload the `sendy-dashboard` folder to your server. Place it **outside** your Sendy directory to avoid conflicts during Sendy upgrades.

```
/public_html/
├── sendy/              ← your Sendy installation
├── sendy-dashboard/    ← this dashboard
│   ├── index.php
│   ├── config.php      ← you create this from config.example.php
│   └── config.example.php
```

### 2. Create your config

```bash
cp config.example.php config.php
```

Edit `config.php` and set:

```php
// Your dashboard password
define('DASHBOARD_PASSWORD', 'pick-something-secure');

// Path to Sendy's config file (contains DB credentials)
define('SENDY_CONFIG_PATH', '/home/user/public_html/sendy/includes/config.php');

// Path to Sendy's short.php helper
define('SENDY_SHORT_PATH', '/home/user/public_html/sendy/includes/helpers/short.php');
```

### 3. (Recommended) Move config outside web root

For better security, move `config.php` to a private directory that isn't web-accessible:

```
/home/user/
├── private/
│   └── sendy-dashboard-config.php    ← config here
├── public_html/
│   ├── sendy/
│   └── sendy-dashboard/
│       └── index.php                 ← dashboard here
```

Then update the config path in `index.php` or set the environment variable:

**Option A:** Rename and move the config, then create a `config.php` in the dashboard folder that just points to it:

```php
<?php
require_once('/home/user/private/sendy-dashboard-config.php');
```

**Option B:** Set an environment variable in `.htaccess`:

```apache
SetEnv SENDY_DASHBOARD_CONFIG /home/user/private/sendy-dashboard-config.php
```

### 4. Visit your dashboard

Go to `https://yoursite.com/sendy-dashboard/` and log in with the password you set.

## Configuration options

| Constant | Required | Description |
|---|---|---|
| `DASHBOARD_PASSWORD` | Yes | Password for the login screen |
| `SENDY_CONFIG_PATH` | Yes | Absolute path to Sendy's `includes/config.php` |
| `SENDY_SHORT_PATH` | No | Absolute path to Sendy's `includes/helpers/short.php` |
| `TRACK_ARES_ID` | No | Autoresponder sequence ID to track. Set to `0` (default) to auto-detect the sequence with the most emails. |
| `$CUSTOM_CAMPAIGNS` | No | Array of Google Ads campaign IDs to add to the Source filter dropdown. Format: `['campaign_id' => 'Display Label']` |

## How engagement status works

The dashboard classifies subscribers based on autoresponder email opens:

| Status | Criteria | Color |
|---|---|---|
| **Engaged** | Opened 3+ autoresponder emails | Green |
| **Active** | Opened 1-2 autoresponder emails | Blue |
| **Pending** | Signed up less than 24 hours ago, no opens yet | Yellow |
| **Warming** | Signed up 24-48 hours ago, no opens yet | Orange |
| **Dead** | Signed up 48+ hours ago, zero opens | Red |

## How open tracking works in Sendy

Sendy's `t.php` tracking pixel handler records opens in the `ares_emails.opens` field as a longtext string in the format `subscriberID:country,subscriberID:country,...`. The dashboard parses this field to determine which subscribers opened which emails.

Sendy also updates the subscriber's `timestamp` field when an email is opened. If a subscriber's `timestamp` equals their `join_date`, they have never opened anything — the dashboard flags these as "no activity."

## Google Ads attribution

If your signup forms pass the Google Ads click ID (`gclid`) in the referrer field, the dashboard will:

- Show a **gclid** badge next to the subscriber's email
- Display the Google Ads campaign ID (if `campaignid=` is in the referrer)
- Let you filter to show only ad-sourced subscribers
- Include gclid in CSV exports (useful for Google Ads offline conversion imports)

## Important notes

- **Read-only** — this dashboard never writes to or modifies Sendy's database
- **Place outside Sendy's folder** — so you don't lose it when upgrading Sendy
- **Not a Sendy plugin** — it's a standalone PHP page that reads Sendy's DB via its config
- **500 subscribers per page** — hard limit to keep queries fast. Use filters to narrow results.

## License

MIT
