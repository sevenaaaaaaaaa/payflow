# PayFlow · 自我进化机制

> 目标：像 OpenFlow 一样，让 PayFlow 能**持续自我审计、自我提议、受控迭代**——而不是依赖
> 人工想起来才改。核心闭环：**观测 → 审计 → 施工图 → 提议 → 实现 → 回归 → 发布 → 复盘**。

## 一、对标 OpenFlow 的闭环

| OpenFlow | PayFlow 对应 |
|---|---|
| `docs/AUDIT-01…08` 定期审计 | `bin/audit.php` 一键审计（质量/性能/入口覆盖），可 `--md=` 落盘 |
| `docs/BACKLOG.md` 唯一施工图 | `docs/BACKLOG.md`，按 🔴地基/🟢攻/🟦守 排序 |
| GrowthEngine / AI 提议（提议≠执行） | `bin/propose.php`（读审计+指标 → DeepSeek → 生成提案卡片，人工批准） |
| `tests/*` + bench 回归 | `payflow/tests/run.php`（102 项）+ CI（php -l/测试/密钥守卫） |
| `docs/ROADMAP.md` / `EVOLUTION.md` 复盘 | `docs/ROADMAP.md` + 本文档 + `CHANGELOG.md` |
| 自治守卫 `AutonomyGuard` | 发布门禁：audit 无 🔴 + CI 绿 + 人工批准 |

## 二、机制落地（可执行）

### 1. 观测（Observability）
- 业务：`/admin/analytics` 与 `/api/v1/analytics/summary`（GMV/转化/续费/渠道/佣金）
- 事件：`events` 审计流（订单/订阅/佣金/提现/交付全链路）
- 集成：Webhook 投递记录（成功/失败/重试）
- 系统：cron 报告（订阅续费、佣金解冻、Webhook 重试、事件保留）

### 2. 审计（Audit）
```bash
php payflow/bin/audit.php --md=docs/AUDIT.md
```
覆盖：语法、入口覆盖（路由↔控制器↔导航）、死代码、全量加载热点、配置键、调试残留、自检。
退出码非零即视为**不可发布**（供 CI/发布门禁使用）。

### 3. 施工图（BACKLOG）
审计与提议汇总到 `docs/BACKLOG.md`，按优先级单一排序，作为「接下来建什么」的唯一来源。

### 4. AI 提议（Proposal，非执行）
`bin/propose.php` 读取审计报告 + 生产指标 → 调 DeepSeek 生成结构化提案（问题/证据/方案/影响/成本）。
**提案必须人工批准**后才进入 BACKLOG/实现，模型输出永不直接写成「已执行」。

### 5. 实现与回归
- 小步提交，每步跑 `php payflow/tests/run.php` 与 `php -l`
- CI（GitHub Actions：PHP 8.3/8.4 × 语法 × 密钥守卫 × 自检）
- 变更先本地、再部署、后公网验证

### 6. 发布与复盘
- 版本号：`config/app.version`，`GET /api/v1/meta` 暴露
- `CHANGELOG.md` 记录每次发布；`ROADMAP.md` 勾选；本文档写复盘

## 三、自治等级（对齐 OpenFlow 的谨慎原则）

| 等级 | 行为 | 现状 |
|---|---|---|
| L0 观测 | 只收集与展示 | ✅ |
| L1 提议 | 生成提案与排序，人工决定 | ✅（`bin/audit.php` + 人审） |
| L2 受控自动执行 | 人工批准后自动实现/发布（低风险类） | ⬜ 目标 |
| L3 受限自治 | 守卫 + 预算内自动执行 | ⬜ 远期 |

**不变量**：模型只提议；执行走领域函数（权限/审计/幂等）；金额与业务事实只来自系统。

## 四、发布门禁（Definition of Done）

1. `php payflow/bin/audit.php` 退出码 0、无 🔴
2. `php payflow/tests/run.php` 全绿；`php -l` 全绿
3. CI 通过；无密钥入库
4. 生产：双入口 200、关键页面 200、驱动/指标正常
5. `CHANGELOG.md` / `ROADMAP.md` 已更新

## 五、当前状态

- `bin/audit.php` 已落地，本地/生产可跑，exit 0
- 自检 102 项；CI（PHP 8.3/8.4）连续通过
- 下一步：`bin/propose.php`（AI 提议）+ BACKLOG 汇总 + L2 受控执行
