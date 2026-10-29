#!/usr/bin/env bash
# Public HTTPS tunnel for the Laravel app (no free-ngrok CORS interstitial).
# Usage:  ./bin/dev-tunnel.sh
# Then paste the https://….trycloudflare.com URL into Theme → App embeds → ShopPulse app URL
# and set APP_URL to the same value in .env

set -euo pipefail
PORT="${1:-8000}"
exec cloudflared tunnel --url "http://127.0.0.1:${PORT}"
