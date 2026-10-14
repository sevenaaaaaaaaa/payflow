#!/usr/bin/env bash
# PayFlow 双轨同步（唯一入口）——支付应用，服务器数据为源，务必只推代码
#   GitHub 轨：lint + 自测 → gitleaks 扫描 → git add -A（.gitignore 排除 data/ uploads/ .env* secrets*）→ commit → push origin main
#   服务器轨：rsync 本地 payflow/ → /www/wwwroot/payflow/（按 .cursor/rules/release-sync.mdc：-rtz、不改属主/权限、
#             不推仓库根 README/docs/.github；排除 data/ uploads/ 等服务器运行时数据）→ 线上 HTTP 验收
# 用法：bash deploy/sync.sh [-m "提交信息"] [--server-only] [--dry-run]
#   --server-only  跳过测试与 git，只同步服务器
#   --dry-run      rsync -n 预演；不 commit/push（任意分支可跑，非 main 仅警告）
#   正式部署只允许在 main 分支；仓库根 ops/（gitignored）若非空，另行 rsync --update 到 /www/ops/payflow/（Web 根之外，不删远端）
set -euo pipefail
cd "$(dirname "$0")/.."

# TODO: docs/DEPLOY.md 中主机/端口为占位符 <SERVER_IP>/<SSH_PORT>；此处取兄弟项目（UserLoop/LearnFlow）同机的值，请确认
HOST=172.96.253.73
SSH_PORT=28766
APP_DIR=/www/wwwroot/payflow
OPS_DIR=/www/ops/payflow   # tool-usage workspace; must stay outside the web root
SITE=https://nownexts.com/payflow
GITLEAKS="${GITLEAKS:-$HOME/.local/bin/gitleaks}"

SERVER_ONLY=0; DRY_RUN=0
MSG="chore: sync $(date '+%Y-%m-%d %H:%M')"
while [ $# -gt 0 ]; do
  case "$1" in
    --server-only) SERVER_ONLY=1 ;;
    --dry-run) DRY_RUN=1 ;;
    -m) shift; MSG="${1:?-m 需要提交信息}" ;;
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    *) echo "未知参数: $1（用法见 --help）"; exit 1 ;;
  esac
  shift
done

step() { printf "\n\033[1;36m=== %s ===\033[0m\n" "$*"; }

# 只允许从 main 部署（--dry-run 任意分支可跑，仅警告）
CUR_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [ "$CUR_BRANCH" != main ]; then
  if [ "$DRY_RUN" = 1 ]; then
    echo "⚠ 当前分支 ${CUR_BRANCH}，非 main：dry-run 仅预演，正式部署只允许从 main 进行"
  else
    echo "✗ 当前分支 ${CUR_BRANCH}，部署只允许从 main 进行；功能分支请先合并"; exit 1
  fi
fi

# 安全闸：payflow/data 绝不能被 git 跟踪
if git ls-files --error-unmatch payflow/data >/dev/null 2>&1 || [ -n "$(git ls-files payflow/data payflow/uploads docs/secrets-local.md)" ]; then
  echo "检测到 payflow/data、payflow/uploads 或 docs/secrets-local.md 被 git 跟踪，中止"; exit 1
fi

# ─── 1. 本地检查（同 CI）+ GitHub 轨 ───
if [ "$SERVER_ONLY" = 1 ]; then
  echo "--server-only：跳过测试与 git"
else
  step "lint + 自测"
  find payflow -name '*.php' -not -path 'payflow/data/*' -print0 | xargs -0 -n1 php -l > /dev/null
  php payflow/tests/run.php | tail -3

  if [ "$DRY_RUN" = 1 ]; then
    step "git（dry-run：只展示待提交变更，不 commit/push）"
    git status --short
    "$GITLEAKS" git --pre-commit --redact --no-banner || { echo "gitleaks 发现泄漏（未暂存变更），中止"; exit 1; }
  else
    step "gitleaks 扫描 + git 提交推送"
    if [ -n "$(git status --porcelain)" ]; then
      git add -A
      if ! "$GITLEAKS" git --pre-commit --staged --redact --no-banner; then
        echo "gitleaks 发现泄漏，已中止（变更仍在暂存区，请修复或 git reset 后重试）"; exit 1
      fi
      git commit -m "$MSG" | head -2
    else
      echo "无未提交变更（跳过 commit）"
    fi
    git push origin main
  fi
fi

# ─── 2. 服务器轨：只推 payflow/ 代码 ───
# 刻意不用 --delete：服务器上 data/（订单/密钥/config.json/sqlite）、uploads/ 等为运行时数据，宁可残留旧文件也不误删。
# TODO: 若需清理已删除的代码文件，请人工在服务器上处理（勿给本命令加 --delete）。
step "rsync payflow/ → ${HOST}:${APP_DIR}/"
RSYNC_FLAGS="-rtz"; [ "$DRY_RUN" = 1 ] && RSYNC_FLAGS="-rtzn --itemize-changes"
rsync $RSYNC_FLAGS -e "ssh -p ${SSH_PORT}" \
  --exclude='.git/' --exclude='/data/' --exclude='data/' --exclude='/uploads/' --exclude='uploads/' \
  --exclude='.env' --exclude='.env*' --exclude='.user.ini' --exclude='*.bak*' \
  --exclude='secrets*' --exclude='*.secret.json' \
  --exclude='*.sqlite*' --exclude='*.db' --exclude='*.lock' --exclude='*.log' \
  --exclude='vendor/' --exclude='node_modules/' --exclude='.DS_Store' \
  payflow/ "root@${HOST}:${APP_DIR}/"

# ─── 2b. 仓库根 ops/ → 服务器 Web 根之外（--update，只增不删；空则跳过） ───
if [ -d ops ] && [ -n "$(find ops -mindepth 1 ! -name .DS_Store -print -quit)" ]; then
  step "ops/ → ${HOST}:${OPS_DIR}/（--update，只增不删）"
  OPS_FLAGS="-rtz"; [ "$DRY_RUN" = 1 ] && OPS_FLAGS="-rtzn --itemize-changes"
  rsync $OPS_FLAGS --update --exclude='.DS_Store' -e "ssh -p ${SSH_PORT}" \
    --rsync-path="mkdir -p ${OPS_DIR} && rsync" \
    ops/ "root@${HOST}:${OPS_DIR}/"
fi

if [ "$DRY_RUN" = 1 ]; then
  echo "dry-run 完成：未提交、未推送"; exit 0
fi

# ─── 3. 线上验收（不改属主/权限，见 release-sync.mdc） ───
step "线上验收"
CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "${SITE}/" || true)
echo "${SITE}/ → HTTP ${CODE}"
# TODO: 确认入口期望状态码；目前 200/302 视为通过。release-sync.mdc 还要求用 ego-browser 做端到端核对
case "$CODE" in 200|302) echo "同步完成" ;; *) echo "线上验收未通过，请人工检查"; exit 1 ;; esac
