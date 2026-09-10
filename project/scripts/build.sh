#!/usr/bin/env bash
set -euo pipefail

project_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
dist_root="$project_root/dist"

rm -rf "$dist_root"
mkdir -p "$dist_root/server" "$dist_root/.openai"

# El worker es un árbol ESM. El artefacto remoto sigue siendo un único
# módulo con `default.fetch`, igual que cuando todo vivía en index.js.
npx --yes esbuild "$project_root/worker/index.js" \
  --bundle \
  --format=esm \
  --platform=neutral \
  --legal-comments=none \
  --outfile="$dist_root/server/index.js"

cp "$project_root/.openai/hosting.json" "$dist_root/.openai/hosting.json"

echo "Built $dist_root"
