# PayFlow · AI 配置（DeepSeek，同步自 OpenFlow）

> PayFlow/矩阵产品统一走 OpenAI 兼容协议接 DeepSeek。真实 API Key 放
> `docs/secrets-local.md`（gitignored），本文件只存非敏感配置与用法。

## DeepSeek（主力供应商）

| 项 | 值 |
|---|---|
| 协议 | OpenAI 兼容（chat/completions） |
| Base URL | `https://api.deepseek.com/v1` |
| 默认模型 | `deepseek-chat`（文本） |
| 视觉模型 | deepseek-chat **不支持图片**；截图类功能需另配视觉模型（glm-4v / gpt-4o / claude） |
| Key | 见 `docs/secrets-local.md` |

## 接入示例（curl）

```bash
curl -s https://api.deepseek.com/v1/chat/completions \
  -H "Authorization: Bearer $DEEPSEEK_KEY" \
  -H "Content-Type: application/json" \
  -d '{"model":"deepseek-chat","messages":[{"role":"user","content":"只回复一个词：OK"}],"max_tokens":10}'
```

## 接入约定（与 OpenFlow AiCenter 一致）

- 生成场景统一走 `temperature 0.7`，JSON 输出要求加"只输出合法 JSON"系统提示
- **预算保险丝**：调用前检查额度，额度尽则降级不重试
- 超时分档：访客等待型 30s / 后台批处理 90s
- 供应商可配置多把 key，按连通性自检择优（openai/claude/minimax 分支见 AiCenter 实现）

## 密钥管理红线

- 真实 key 只存 `docs/secrets-local.md`（gitignored）与运行时配置（`data/ai-config.json`，服务器侧）
- **禁止**把 key 写进任何会被 git 提交的文件、README、代码常量
- 轮换：在 DeepSeek 控制台作废旧 key 后，更新服务器 `data/ai-config.json` 与本文件密码文件
