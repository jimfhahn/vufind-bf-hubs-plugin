#!/usr/bin/env bash
# Build the notebooks and publish them to the static Hugging Face Space.
# Usage: ./deploy-space.sh [repo_id]   (default jimfhahn/bibframe-hub-explorer)
set -euo pipefail
cd "$(dirname "$0")"

REPO="${1:-jimfhahn/bibframe-hub-explorer}"
HF="${HF:-../tools/hf-dataset/.venv/bin/hf}"   # needs hf >= 1.x
[ -x "$HF" ] || HF=hf

node_modules/.bin/notebooks build --root . -- *.html
cp space/README.md space/index.html .observable/dist/

# Same-origin copies of the Parquet files the explorer needs. Serving them from the Space
# avoids the dataset resolve → Xet CDN redirect, whose CORS headers are unreliable in Firefox.
"$HF" download "${DATASET:-jimfhahn/lc-bibframe-hubs}" relations.parquet hubs_connected.parquet \
  --repo-type dataset --local-dir .observable/dist/data
rm -rf .observable/dist/data/.cache

"$HF" upload "$REPO" .observable/dist . --repo-type space --delete "*" \
  --commit-message "Deploy notebooks $(git rev-parse --short HEAD 2>/dev/null || date +%F)"
echo "https://huggingface.co/spaces/$REPO"
