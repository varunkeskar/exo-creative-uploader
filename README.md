# ExoClick Bulk Creative Uploader

A full-stack PHP application for batch-uploading ad creatives to the [ExoClick](https://www.exoclick.com/) advertising network. Supports banner, native, push notification, video, and popunder formats with real-time progress streaming and automated copy translation.

Built as a single-page application with a vanilla JavaScript frontend and a PHP backend that wraps the ExoClick REST API (v2).

## Features

- **Batch variation creation** — Upload one creative and deploy it across multiple campaigns simultaneously using concurrent `curl_multi` requests (up to 8 in parallel)
- **Real-time progress** — Server-Sent Events (SSE) stream per-campaign success/failure status to the browser as each variation is created
- **Multi-format support** — Banner (300x250, 728x90, etc.), native (aspect-ratio validated), push notification (icon + image), video (MP4/WebM), and popunder formats with format-specific validation
- **Auto-translation** — Integrates with [LibreTranslate](https://libretranslate.com/) to translate ad copy (title, description, brand) into the target language based on each campaign's geo targeting
- **Smart campaign filtering** — Filter campaigns by ad format, campaign group, and geo region with a chip-based multi-filter UI
- **File validation** — Server-side MIME type checking, pixel dimension validation, and aspect-ratio enforcement (native ads support 1:1, 4:3, 16:9 with 10% tolerance)
- **Client-side rate limiting** — Sliding-window tracker (150 requests / 60 seconds) with visual countdown, matching ExoClick's API limits
- **Token management** — Persistent token caching with automatic refresh on 401 responses
- **Retry with backoff** — Exponential backoff on 429 rate-limit responses (2s, 5s, 10s delays)
- **Drag-and-drop uploads** — Concurrent upload queue (max 4 simultaneous) with progress indicators
- **CSRF protection** — Session-bound tokens on all mutating API calls
- **Wake Lock** — Prevents screen sleep during long batch submissions

## Architecture

```
public/                  # Web root (Apache document root)
  index.php              # Front controller — routing, API handlers, HTML shell
  .htaccess              # Apache rewrite rules
  assets/
    app.js               # Frontend SPA (vanilla JS, IIFE pattern)
    style.css            # Dark theme UI (CSS custom properties)
src/
  config.php             # Constants, format specs, geo presets, language maps
  ExoClickClient.php     # API client — auth, rate limiting, file upload, batch creation
  FileValidator.php      # Upload validation — MIME, dimensions, aspect ratios
  GeoGroupManager.php    # Geo set persistence (JSON file-backed)
data/                    # Runtime data (gitignored)
logs/                    # Application logs (gitignored)
```

### Request Flow

1. User authenticates with their ExoClick API token (validated against `/login`)
2. Frontend fetches campaigns, groups, and geo data via `/api/campaigns` and `/api/campaign-countries`
3. User selects campaigns (same ad-format enforced), uploads creative files, and fills in text fields
4. On submit, the frontend opens an SSE connection to `/api/create-variations-batch`
5. Backend iterates campaigns, creates variations via `curl_multi`, and streams JSON events back per-campaign

### API Endpoints

| Method | Route | Description |
|--------|-------|-------------|
| POST | `/api/auth` | Authenticate with ExoClick API token |
| GET | `/api/campaigns` | List campaigns (filtered by group, cached) |
| POST | `/api/upload` | Upload creative file to ExoClick library |
| POST | `/api/create-variations` | Create variation for a single campaign |
| POST | `/api/create-variations-batch` | Batch create across campaigns (SSE stream) |
| GET | `/api/campaign-countries` | Get geo targeting per campaign |
| GET | `/api/offers` | List offers for a campaign |
| POST | `/api/translate` | Translate text via LibreTranslate |

## Setup

### Requirements

- PHP 7.4+ with `curl`, `json`, `gd` (or `imagick`) extensions
- Apache with `mod_rewrite` enabled
- [LibreTranslate](https://libretranslate.com/) instance (optional, for auto-translation)

### Installation

```bash
git clone https://github.com/varunkeskar/exo-creative-uploader.git
cd exo-creative-uploader

# Create runtime directories
mkdir -p data/uploads logs

# Set permissions
chmod 700 data/ logs/

# Point Apache document root to public/
# Example virtual host:
# <VirtualHost *:80>
#     DocumentRoot /path/to/exo-creative-uploader/public
#     <Directory /path/to/exo-creative-uploader/public>
#         AllowOverride All
#         Require all granted
#     </Directory>
# </VirtualHost>
```

### Configuration

Edit `src/config.php` to adjust:

- **`AUTH_REQUIRED`** — Set to `true` to require session-based login before the UI loads (default: `false`, API token is the sole auth gate)
- **`CAMPAIGN_GROUP_FILTER`** — Substring filter for campaign group names (default: `''` shows all groups)
- **`LIBRETRANSLATE_URL`** — URL of your LibreTranslate instance (or set the `LIBRETRANSLATE_URL` environment variable)
- **`RATE_LIMIT_MAX`** / **`RATE_LIMIT_WINDOW`** — Client-side rate limit parameters
- **`FORMAT_CATEGORY_MAP`** / **`FORMAT_SPECS`** — Ad format definitions and validation rules

## Technical Highlights

- **Zero dependencies** — No Composer packages, no npm, no build step. Pure PHP + vanilla JS.
- **Persistent cURL handles** — Reuses TCP connections across API calls (`CURLOPT_FORBID_REUSE => false`, `CURLOPT_TCP_KEEPALIVE => 1`) for lower latency on batch operations.
- **SSE streaming** — `handleCreateVariationsBatch()` disables output buffering and flushes `data:` events in real-time, giving immediate per-campaign feedback during batch creation.
- **Concurrent uploads** — Both server-side (`curl_multi_exec` with 8 handles) and client-side (`runConcurrent` utility with 4 slots) use bounded concurrency to maximize throughput without overwhelming the API.
- **Format-aware validation** — `FileValidator` enforces format-specific rules: exact pixel sizes for banners, aspect-ratio tolerance (10%) for native ads, separate icon/image slots for push notifications.
- **Geo-aware translation** — Resolves each campaign's target countries → ISO language codes → LibreTranslate API, with an editable review panel before submission.

## License

MIT
