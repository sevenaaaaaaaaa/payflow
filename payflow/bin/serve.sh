#!/usr/bin/env bash
# PayFlow 本地开发：写入 dev 配置 + 种子商品 + 启动内置服务器
set -euo pipefail
cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-php}"
PORT="${PORT:-8787}"

"$PHP_BIN" bin/seed.php --dev
echo
echo "→ http://127.0.0.1:${PORT}/   (后台 /admin)"
exec "$PHP_BIN" -S "127.0.0.1:${PORT}" -t . index.php
