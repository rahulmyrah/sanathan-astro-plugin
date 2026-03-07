# Sanathan Astro Services — WordPress Plugin

**Version:** 1.3.0 | **Requires WP:** 5.8+ | **Requires PHP:** 7.4+ | **Tested up to:** 6.9

The backend engine for the **Sanathan** Vedic Astrology platform. Powers the Flutter mobile app via a REST API, and manages predictions caching, Kundali storage, Guruji AI, AI Tools bulk import, and Firebase push notifications — all from a single WordPress plugin.

---

## Table of Contents

1. [Overview](#overview)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Settings Reference](#settings-reference)
5. [Features](#features)
   - [Phase 1 — Predictions & Kundali](#phase-1--predictions--kundali)
   - [Phase 2 — Personal Guruji AI](#phase-2--personal-guruji-ai)
   - [Phase 3 — Push Notifications (FCM)](#phase-3--push-notifications-fcm)
   - [AI Tools Setup](#ai-tools-setup)
6. [REST API Endpoints](#rest-api-endpoints)
7. [Cron Jobs](#cron-jobs)
8. [Database Tables](#database-tables)
9. [Admin Pages](#admin-pages)
10. [GitHub Auto-Update](#github-auto-update)
11. [Building a New Release](#building-a-new-release)
12. [Changelog](#changelog)

---

## Overview

```
WordPress (sanathan.app)
│
├── Sanathan Astro Services (this plugin)
│   ├── REST API  →  Flutter Mobile App
│   ├── Predictions Cache  →  VedicAstroAPI.com
│   ├── Kundali Storage  →  MySQL DB
│   ├── Guruji AI  →  Qdrant (RAG) + AIP Plugin (LLM)
│   ├── AI Tools  →  AIP Forms + Gemini (images + SEO)
│   └── Push Notifications  →  Firebase FCM
│
└── AIP Plugin (AI Power)  ← required dependency
```

---

## Requirements

| Dependency | Purpose | Where to get |
|---|---|---|
| WordPress 5.8+ | Core platform | wordpress.org |
| PHP 7.4+ | Runtime | Hosting provider |
| **AIP Plugin** (AI Power) | LLM calls, embeddings, AI forms | Installed on sanathan.app |
| **Qdrant** (Cloud or self-hosted) | Vector DB for RAG | qdrant.io |
| **VedicAstroAPI.com** API key | Prediction data | vedicastroapi.com |
| **Google Gemini API** key | AI Tool images + SEO content | aistudio.google.com |
| **Firebase FCM** Server Key | Push notifications (Phase 3) | console.firebase.google.com |

---

## Installation

### First-time install

1. Build the ZIP: `cd sanathan-app && .\make-zip.ps1`
2. The script generates `sanathan-app.zip` one level up (in the workspace root)
3. Upload to: **WP Admin → Plugins → Add New → Upload Plugin**
4. Activate the plugin
5. Go to **Astro Services → Settings** and fill in all API keys

### Updating (GitHub auto-update)

1. Make your code changes
2. Bump `SAS_VERSION` in `sanathan-app.php`
3. Run `.\make-zip.ps1` — it updates `plugin-info.json` automatically
4. Commit and push: `git add -A && git commit && git push`
5. Create a GitHub Release tagged `v{version}` and upload `sanathan-app.zip`
6. On the WordPress site: **Plugins → Update Available → Update Now**

> **Important:** `make-zip.ps1` uses `git archive` (not `Compress-Archive`) to ensure forward-slash ZIP paths, which is required for correct extraction on Linux/Hostinger servers.

### Manual install via Hostinger File Manager (emergency fallback)

If WordPress auto-update fails with "Filesystem error":

1. Download `sanathan-app.zip` from the GitHub Release
2. Open **Hostinger hPanel → Files → File Manager**
3. Navigate to `public_html/wp-content/plugins/`
4. Delete the existing `sanathan-app/` folder
5. Upload `sanathan-app.zip` here (directly inside `plugins/`)
6. Right-click the ZIP → **Extract** (extract into `plugins/`, not inside a subfolder)
7. Delete the ZIP after extraction

---

## Settings Reference

Go to **WP Admin → Astro Services → Settings** to configure:

### Personal Guruji — AIP Integration

| Setting | Description | Where to find |
|---|---|---|
| **AIP REST API Key** | Key used to call the AIP plugin's internal API for LLM responses and embeddings | AIP → Settings (gear icon) → API tab → Public API Key |
| **Guruji AI Model** | LLM model Guruji uses to answer questions | Dropdown — or click "Sync from AIP" to load live models |
| **Custom Model ID** | Overrides the dropdown if set. Use any model ID (e.g. `gpt-4o`, `claude-haiku-4-5-20251001`) | Leave blank to use dropdown |

> **Cost tip:** GPT-4o Mini and Claude Haiku are the cheapest. Qdrant RAG is always checked first — LLM is only called as a fallback.

### Qdrant Vector Search (RAG Knowledge Base)

| Setting | Description | Example |
|---|---|---|
| **Qdrant URL** | Full URL to your Qdrant instance with port | `https://abc123.gcp.cloud.qdrant.io:6333` |
| **Qdrant API Key** | Auth key for Qdrant Cloud (leave blank for unauthenticated self-hosted) | From Qdrant Cloud → cluster → API Keys |

**Required Qdrant collections:**

| Collection | Dimensions | Distance | Purpose |
|---|---|---|---|
| `sanathan_knowledge` | 1536 | Cosine | Global Vedic knowledge base (43 documents) |
| `user_kundali` | 1536 | Cosine | Per-user Kundali summaries (multitenancy by `user_id`) |

Use the **Test Qdrant Connection** button to verify live connectivity and see document counts.

### Google Gemini API (AI Tools)

| Setting | Description | Where to find |
|---|---|---|
| **Gemini API Key** | Used for Imagen 3 (featured images) and Gemini Flash (SEO content) | [aistudio.google.com/apikey](https://aistudio.google.com/apikey) |

Models used:
- Image generation: `imagen-3.0-generate-001`
- SEO content: `gemini-2.0-flash`

### Prediction Languages

Check/uncheck which languages to pre-cache predictions for. Each enabled language adds 12 API calls per daily cron run (one per zodiac sign).

| Code | Language |
|---|---|
| `en` | English |
| `hi` | Hindi |
| `ta` | Tamil |
| `te` | Telugu |
| `ka` | Kannada |
| `ml` | Malayalam |
| `be` | Bengali |
| `sp` | Spanish |
| `fr` | French |

### Push Notifications — Firebase FCM

| Setting | Description | Where to find |
|---|---|---|
| **FCM Server Key** | Legacy server key for Firebase Cloud Messaging | Firebase Console → Project Settings → Cloud Messaging → Server key |

---

## Features

### Phase 1 — Predictions & Kundali

**Predictions Cache**
- Pre-fetches daily, weekly, and yearly horoscope predictions for all 12 zodiac signs × enabled languages
- Served from the local MySQL DB — zero API latency for the Flutter app
- VedicAstroAPI.com is called only on a cache miss
- Cache is refreshed by cron jobs (daily, weekly, yearly)

**Kundali Storage**
- One Kundali per WordPress user per language
- English (`en`) record always stored (required for Guruji AI context)
- User's preferred language stored as a second record
- Two tiers:
  - `core` — 5 basic calculations (lagna, planets, navamsa, dasha, basic doshas)
  - `full` — core + 6 dosha endpoints (Mangal, Kaal Sarp, Pitra, Sadhesati, Shani, Pitru)
- Upgrade hook: `sas_can_upgrade_kundali` — filter to gate upgrades behind payment

---

### Phase 2 — Personal Guruji AI

- **RAG-first architecture**: Qdrant is searched before any LLM call
- **Knowledge Base**: 43 pre-indexed Vedic astrology documents:
  - 12 zodiac sign profiles
  - 9 planetary descriptions
  - 10 house meanings
  - 5 dosha explanations
  - 6 remedy categories
  - 8 core concepts (Dasha, Nakshatra, etc.)
- **Fallback chain**: Qdrant → AIP Plugin (LLM) → Generic response
- **Multilingual**: replies in the user's preferred language
- **Personalised**: uses the user's Kundali data as context
- **Session management**: conversation history stored per user
- **Configurable avatars**: 8 Guruji avatar personas (Swami, Pandit, Sage, etc.)

---

### Phase 3 — Push Notifications (FCM)

> Status: Scaffolded. FCM server key setting is live. Daily alert cron is registered but currently a no-op. Full implementation in next phase.

- Device token registration endpoint
- Notification history endpoint
- Daily alert cron at 01:30 UTC

---

### AI Tools Setup

Admin page: **Astro Services → AI Tools Setup**

A one-click bulk-operation dashboard to populate the AI Tools Custom Post Type (`ai-toolai_tool`) from your 100+ AIP plugin forms.

**Step 1 — Create Categories**
Creates 7 canonical `ai_tool_category` taxonomy terms:

| Category | Keyword triggers |
|---|---|
| Vedic Astrology | rashi, kundali, horoscope, zodiac, nakshatra, dasha, planetary, jyotish |
| Mantra & Prayer | mantra, aarti, chant, archana, namavali, prayer, stotram |
| Bhakti & Devotion | bhakti, bhajan, devotion, slokas, kirtan, spiritual practice |
| Hindu Scriptures | gita, bhagavad, upanishad, ramayana, mahabharat, purana |
| Vaastu & Remedies | vaastu, vastu, remedy, dosh, dosha, mangal, gemstone, feng |
| Festivals & Rituals | festival, puja, ritual, yatra, dham, daan, charity, pilgrimage |
| Spiritual Life | *(default — everything else)* |

**Step 2 — Update Existing Posts**
Assigns categories, tags, and Gemini SEO "How to Use" guide to the 13 pre-existing AI Tool posts.

**Step 3 — Import AI Tool Posts**
- Scans all AIP forms and skips non-Sanathan ones (Blog Post Generator, Customer Support Reply Builder, etc.)
- Creates a new `ai-toolai_tool` post for each relevant form
- Post content structure:
  ```
  [aipkit_ai_form id=XXXXX]

  <div class="sas-tool-guide">
    <p class="sas-tool-intro"><!-- SEO intro --></p>
    <h2>How to Use {Title}</h2>
    <ol><!-- Gemini Flash steps --></ol>
    <h2>What Results to Expect</h2>
    <h2>Tips for Best Results</h2>
  </div>
  ```
- Auto-detects category from form title keywords
- Auto-generates tags from title words

**Step 4 — Generate Featured Images**
- Calls Gemini Imagen 3 (`imagen-3.0-generate-001`) with a spiritual Hindu illustration prompt
- Prompt style: warm saffron and gold tones, sacred art aesthetic, no text
- Downloads the base64 image → uploads to WP Media Library → sets as post featured image
- Processes up to 20 images per batch (to avoid timeouts)

---

## REST API Endpoints

Base URL: `https://sanathan.app/wp-json/sanathan/v1/`

Authentication: WordPress cookie auth or [Application Password](https://wordpress.org/documentation/article/application-passwords/).

### Public Endpoints

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/predictions` | Get cached horoscope prediction |

**`GET /predictions` parameters:**

| Param | Required | Values | Description |
|---|---|---|---|
| `zodiac` | Yes | `aries` … `pisces` | Zodiac sign |
| `cycle` | Yes | `daily` / `weekly` / `yearly` | Prediction cycle |
| `lang` | No | `en`, `hi`, `ta`, `te`, `ka`, `ml`, `be`, `sp`, `fr` | Language (default: `en`) |
| `date` | No | `DD/MM/YYYY` | For daily predictions |
| `week` | No | `thisweek` / `nextweek` | For weekly predictions |
| `year` | No | `YYYY` | For yearly predictions |

---

### Authenticated Endpoints (require logged-in WP user)

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/kundali` | Create or retrieve Kundali |
| `GET` | `/kundali` | List user's Kundalis |
| `POST` | `/kundali/upgrade` | Upgrade Kundali to full tier |
| `GET` | `/user/tier` | Get user's tier + feature flags |
| `POST` | `/device/register` | Register FCM device token |
| `GET` | `/notifications` | Get push notification history |

---

### Guruji Endpoints (Phase 2)

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/guruji/chat` | Send a message to Guruji AI |
| `GET` | `/guruji/history` | Get Guruji conversation history |

---

## Cron Jobs

| Cron Hook | Schedule | Time (UTC) | What it does |
|---|---|---|---|
| `sas_daily_predictions` | Daily | 18:31 | Fetches predictions for 9 langs × 12 zodiacs = 108 API calls |
| `sas_weekly_predictions` | Every Monday | 18:31 | Fetches weekly predictions for all enabled langs × zodiacs |
| `sas_yearly_predictions` | Every Jan 1 | 00:05 | Fetches yearly predictions for all enabled langs × zodiacs |
| `sas_daily_alerts` | Daily | 01:30 | Phase 3 FCM alerts (currently no-op) |

---

## Database Tables

All tables are created on plugin activation (`SAS_DB::create_tables()`).

| Table | Purpose |
|---|---|
| `sanathan_predictions` | Cached prediction text per zodiac × lang × cycle × date |
| `sanathan_kundali` | Birth chart data per user × lang, with tier flag |
| `sanathan_guruji_sessions` | Guruji AI conversation sessions per user |
| `sanathan_guruji_messages` | Individual messages within Guruji sessions |
| `sanathan_user_devices` | FCM tokens per user (one device per token) |
| `sanathan_notifications` | Push notification log per user |

---

## Admin Pages

| Menu Item | URL slug | Description |
|---|---|---|
| Dashboard | `sas-dashboard` | Overview stats: predictions cached, Kundalis, sessions |
| Settings | `sas-settings` | All API keys and configuration |
| Predictions | `sas-predictions` | Browse cached predictions, trigger manual refresh |
| Kundali | `sas-kundali` | Browse stored Kundalis per user |
| Knowledge Base | `sas-knowledge` | Index Vedic documents to Qdrant, monitor collections |
| AI Tools Setup | `sas-ai-tools-setup` | Bulk import AIP forms, generate images and SEO content |

---

## GitHub Auto-Update

The plugin polls `plugin-info.json` on GitHub every 12 hours. When the remote version is higher than the installed version, WordPress shows "Update Available".

**Updater config** (in `sanathan-app.php`):
```php
new SAS_Updater(
    'https://raw.githubusercontent.com/rahulmyrah/sanathan-astro-plugin/main/plugin-info.json'
);
```

**`plugin-info.json` fields:**

| Field | Description |
|---|---|
| `version` | Must match `SAS_VERSION` in the PHP header |
| `download_url` | Direct URL to the ZIP on the GitHub Release |
| `last_updated` | Date string shown in WP admin |
| `requires` | Minimum WP version |
| `tested` | WP version tested up to |
| `requires_php` | Minimum PHP version |
| `changelog` | HTML changelog shown in the update modal |

---

## Building a New Release

```powershell
# 1. Bump SAS_VERSION in sanathan-app.php (e.g. 1.3.0 → 1.4.0)

# 2. Run the build script from inside the sanathan-app/ folder
cd "C:\Users\rahul\Desktop\Sanathan APP\Sanathan App - Vedic Astro\sanathan-app"
.\make-zip.ps1
# → enters changelog, updates plugin-info.json, creates ../sanathan-app.zip

# 3. Commit everything
git add -A
git commit -m "Release v1.4.0"
git push origin main

# 4. Create GitHub Release
gh release create v1.4.0 ../sanathan-app.zip --title "v1.4.0" --notes "Your changelog here"

# 5. WordPress sites will auto-detect and prompt for update within 6-12 hours
```

> **Linux compatibility note:** `make-zip.ps1` uses `git archive --prefix=sanathan-app/` which always produces forward-slash ZIP entries. This is required for correct extraction on Linux/Hostinger servers. Never use PowerShell's `Compress-Archive` for WordPress plugin ZIPs.

---

## Changelog

### 1.3.0 (2026-03-06)
- **AI Tools Setup** admin page with 4 one-click operations
- Create 7 canonical AI Tool categories with keyword auto-detection
- Bulk import AIP forms as `ai-toolai_tool` CPT posts
- Gemini Imagen 3 featured image generation (spiritual Hindu art style)
- Gemini Flash SEO "How to Use" guide generation per post
- Settings: added Gemini API key field
- Fixed `make-zip.ps1` to use `git archive` for Linux-safe ZIP paths

### 1.2.0
- RAG Knowledge Base: Qdrant integration for semantic search
- 43 pre-indexed Vedic astrology documents
- Guruji now searches Qdrant before calling the LLM
- New admin page: Knowledge Base (index content, monitor Qdrant)
- Settings: Qdrant URL + API key with live connection test

### 1.1.0
- Personal Guruji AI: per-user profiles, avatars, multilingual replies
- AIP model selector with live sync
- Session and message history storage

### 1.0.0
- Initial release: Predictions cache, Kundali storage, REST API, Admin UI
- 9 languages × 12 zodiacs predictions pre-caching
- VedicAstroAPI.com integration
- GitHub auto-updater via `plugin-info.json`
