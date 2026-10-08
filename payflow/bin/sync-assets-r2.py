#!/usr/bin/env python3
"""
PayFlow 静态资源 → Cloudflare R2（幂等，仅上传变更）

用法（凭据走环境变量，禁止硬编码入库）：
  R2_ACCESS_KEY=xxx R2_SECRET=yyy python3 payflow/bin/sync-assets-r2.py

默认：
  端点 https://00d02a54a3c0f7a3f6c3fc75068e29c5.r2.cloudflarestorage.com
  桶   nownexts-static
  键前缀 assets/payflow/   （对应 nownexts.com/assets/payflow/<file>，由 r2-assets Worker 提供）
"""
import hashlib
import mimetypes
import os
import sys

try:
    import boto3
except ImportError:
    sys.exit("需要 boto3：pip install boto3")

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # payflow/
ASSETS_DIR = os.path.join(ROOT, "public", "assets")

ENDPOINT = os.environ.get("R2_ENDPOINT", "https://00d02a54a3c0f7a3f6c3fc75068e29c5.r2.cloudflarestorage.com")
BUCKET = os.environ.get("R2_BUCKET", "nownexts-static")
PREFIX = os.environ.get("R2_PREFIX", "assets/payflow/")
KEY = os.environ.get("R2_ACCESS_KEY", "")
SECRET = os.environ.get("R2_SECRET", "")

if not KEY or not SECRET:
    sys.exit("缺少 R2_ACCESS_KEY / R2_SECRET 环境变量")

s3 = boto3.client(
    "s3",
    endpoint_url=ENDPOINT,
    aws_access_key_id=KEY,
    aws_secret_access_key=SECRET,
    region_name="auto",
)


def md5(path: str) -> str:
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def main() -> int:
    if not os.path.isdir(ASSETS_DIR):
        sys.exit(f"资源目录不存在：{ASSETS_DIR}")

    existing = {}
    token = None
    while True:
        kwargs = {"Bucket": BUCKET, "Prefix": PREFIX}
        if token:
            kwargs["ContinuationToken"] = token
        r = s3.list_objects_v2(**kwargs)
        for obj in r.get("Contents", []):
            existing[obj["Key"]] = obj["ETag"].strip('"')
        if not r.get("IsTruncated"):
            break
        token = r.get("NextContinuationToken")

    uploaded = skipped = 0
    for name in sorted(os.listdir(ASSETS_DIR)):
        path = os.path.join(ASSETS_DIR, name)
        if not os.path.isfile(path):
            continue
        key = PREFIX + name
        local = md5(path)
        if existing.get(key) == local:
            skipped += 1
            continue
        ctype = mimetypes.guess_type(path)[0] or "application/octet-stream"
        s3.upload_file(
            path,
            BUCKET,
            key,
            ExtraArgs={
                "ContentType": ctype,
                # 短缓存：同名键可覆盖，改版后 purge 或等 5 分钟
                "CacheControl": "public, max-age=300",
            },
        )
        print(f"↑ {key} ({ctype})")
        uploaded += 1

    print(f"完成：上传 {uploaded}，跳过 {skipped}，前缀 {PREFIX}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
