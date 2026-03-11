# Sendy Dashboard

A read-only subscriber engagement dashboard for [Sendy](https://sendy.co). Shows which subscribers are actually opening your autoresponder emails and which ones are dead weight — something Sendy doesn't surface on its own.

## What it does

Sendy tracks autoresponder opens in its database but doesn't expose this data in the UI. This dashboard reads that data and gives you:

- **Engagement status** for every subscriber: Engaged, Active, Pending, Warming, or Dead
- **Visual open tracking** — a dot grid showing which autoresponder emails each subscriber opened
- **Google Ads attribution** — identifies subscribers who came from Google Ads (via gclid in the referrer)
- **Filters** — by time range (preset or custom date range), source (ads/organic), list, engagement status, and email search
- **Sortable columns** — click any column header to sort
- **CSV export** — download filtered results for use in spreadsheets or other tools
- **Google Ads offline conversion export** — one-click CSV export of qualified subscribers (has gclid, 1+ opens, 24+ hours old) in Google Ads offline conversion import format
- **"No activity" flag** — highlights subscribers whose last activity timestamp matches their signup (meaning they never opened anything)

## Why this exists

If you're running Google Ads to a Sendy-powered email list, you need to know which ad clicks turn into real subscribers vs. bots. Google's conversion tracking counts the signup, but can't tell you if the person actually opens your autoresponder emails. 

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

Edit `config.php` and set your database credentials and dashboard password:

```php
define('DASHBOARD_PASSWORD', 'pick-something-secure');

define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'your_db_name');
```

These are the same database credentials from your Sendy installation.

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

Then create a `config.php` in the dashboard folder that just points to it:

```php
<?php
require_once('/home/user/private/sendy-dashboard-config.php');
```

Or set an environment variable in `.htaccess`:

```apache
SetEnv SENDY_DASHBOARD_CONFIG /home/user/private/sendy-dashboard-config.php
```

### 4. Visit your dashboard

Go to `https://yoursite.com/sendy-dashboard/` and log in with the password you set.

## Configuration options

| Constant | Required | Description |
|---|---|---|
| `DASHBOARD_PASSWORD` | Yes | Password for the login screen |
| `DB_HOST` | Yes | Database host (usually `localhost`) |
| `DB_USER` | Yes | Database username |
| `DB_PASS` | Yes | Database password |
| `DB_NAME` | Yes | Database name |
| `DB_PORT` | No | Database port (default: 3306) |
| `DB_CHARSET` | No | Database charset (default: `utf8`) |
| `TRACK_ARES_ID` | No | Autoresponder sequence ID to track. Set to `0` (default) to auto-detect the sequence with the most emails. |
| `$CUSTOM_CAMPAIGNS` | No | Array of Google Ads campaign IDs to add to the Source filter dropdown. Format: `['campaign_id' => 'Display Label']` |
| `GADS_CONVERSION_NAME` | No | Conversion action name for Google Ads export (default: `Engaged Subscriber`) |
| `GADS_CONVERSION_VALUE` | No | Optional conversion value in USD. If set, adds Value and Currency columns to the export. |
| `GADS_QUALIFY_HOURS` | No | Minimum hours since signup before a subscriber qualifies for export (default: `24`) |

## How engagement status works

The dashboard classifies subscribers based on autoresponder email opens:

| Status | Criteria | Color |
|---|---|---|
| **Engaged** | Opened 3+ autoresponder emails | Green |
| **Active** | Opened 1-2 autoresponder emails | Blue |
| **Pending** | Signed up less than 24 hours ago, no opens yet | Yellow |
| **Warming** | Signed up 24-48 hours ago, no opens yet | Orange |
| **Dead** | Signed up 48+ hours ago, zero opens | Red |

## How it reads open data

Sendy stores autoresponder open tracking in the `ares_emails.opens` database field as a longtext string in the format `subscriberID:country,subscriberID:country,...`. The dashboard parses this field to determine which subscribers opened which emails.

Sendy also updates the subscriber's `timestamp` field when an email is opened. If a subscriber's `timestamp` equals their `join_date`, they have never opened anything — the dashboard flags these as "no activity."

## Google Ads attribution

If your signup forms pass the Google Ads click ID (`gclid`) in the referrer field, the dashboard will:

- Show a **gclid** badge next to the subscriber's email
- Display the Google Ads campaign ID (if `campaignid=` is in the referrer)
- Let you filter to show only ad-sourced subscribers
- Include gclid in CSV exports (useful for Google Ads offline conversion imports)

## Google Ads offline conversion export

If your subscribers arrive via Google Ads (with `gclid` in the referrer), you can export qualified subscribers as a CSV for Google Ads offline conversion import. Click "Google Ads Export" in the header.

A subscriber qualifies when they have a gclid, have opened at least 1 autoresponder email, and signed up more than 24 hours ago (configurable via `GADS_QUALIFY_HOURS`). The export uses the subscriber's last activity timestamp as the conversion time.

To import: in Google Ads, go to Tools → Conversions → Uploads, and upload the CSV. You'll need a conversion action matching your `GADS_CONVERSION_NAME` setting.

## Important notes

- **Read-only** — this dashboard never writes to or modifies your database
- **Independent** — does not include, require, or modify any Sendy source files
- **Place outside Sendy's folder** — so you don't lose it when upgrading Sendy
- **This project is not affiliated with or endorsed by Sendy or Hex.

## License

MIT
