#!/usr/bin/env bash
# Build one notebook and publish it to its static Hugging Face Space.
# Usage: ./deploy-space.sh [explorer|collocation] [repo_id]
#   explorer    (default) hub-family-explorer.html → jimfhahn/bibframe-hub-explorer
#   collocation hub-collocation.html     → jimfhahn/bibframe-hub-collocation
# For backward compatibility a single repo_id argument deploys the explorer there.
set -euo pipefail
cd "$(dirname "$0")"

TARGET="${1:-explorer}"
if [[ "$TARGET" == */* ]]; then set -- explorer "$TARGET"; TARGET=explorer; fi
case "$TARGET" in
  explorer)
    NOTEBOOK=hub-family-explorer.html; SPACE_DIR=space; DEFAULT_REPO=jimfhahn/bibframe-hub-explorer
    DATA_FILES=(relations.parquet hubs_connected.parquet) ;;
  collocation)
    NOTEBOOK=hub-collocation.html; SPACE_DIR=space-collocation; DEFAULT_REPO=jimfhahn/bibframe-hub-collocation
    DATA_FILES=(collocation/colloc_hub_class.parquet collocation/colloc_edges.parquet
                collocation/colloc_records.parquet collocation/colloc_coverage.parquet) ;;
  *) echo "unknown target: $TARGET (explorer|collocation)" >&2; exit 2 ;;
esac
REPO="${2:-$DEFAULT_REPO}"
HF="${HF:-../tools/hf-dataset/.venv/bin/hf}"   # needs hf >= 1.x
[ -x "$HF" ] || HF=hf
DIST=.observable/dist

rm -rf "$DIST"
node_modules/.bin/notebooks build --root . -- "$NOTEBOOK"
cp "$SPACE_DIR/README.md" "$SPACE_DIR/index.html" "$DIST/"

# Same-origin copies of the Parquet files the notebook needs, flattened into data/. Serving them
# from the Space avoids the dataset resolve → Xet CDN redirect, whose CORS headers are unreliable
# in Firefox.
TMP="$(mktemp -d)"
"$HF" download "${DATASET:-jimfhahn/lc-bibframe-hubs}" "${DATA_FILES[@]}" --repo-type dataset --local-dir "$TMP"
mkdir -p "$DIST/data"
for f in "${DATA_FILES[@]}"; do mv "$TMP/$f" "$DIST/data/"; done
rm -rf "$TMP"

"$HF" upload "$REPO" "$DIST" . --repo-type space --delete "*" \
  --commit-message "Deploy $NOTEBOOK $(git rev-parse --short HEAD 2>/dev/null || date +%F)"
echo "https://huggingface.co/spaces/$REPO"
