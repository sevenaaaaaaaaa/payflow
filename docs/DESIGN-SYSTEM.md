# 芭乐派 · 设计规范（同步自 OpenFlow v2.6）

> 两个项目共用这套设计系统，保证矩阵内视觉一致。源文件在本目录 `assets-reference/`
>（tokens.css = 设计令牌，modules.css = 共享 archetype），以 OpenFlow 主仓为准做同步。

## 一、设计契约要点

1. **全 oklch 色彩体系，零 hex**（favicon 场景除外）
2. **字体**：Space Grotesk（显示/正文）+ PingFang/HarmonyOS 中文回退；代码 ui-monospace
3. **缓动**：弹簧 `cubic-bezier(.32,.72,0,1)`；`--ease-out` 用于卡片
4. **对比度**：`--faint` 亮色 51% L / 暗色 64% L——正文级小字 ≥4.5:1（WCAG AA）
5. **圆角**：`--r-lg: 26px` / `--r-md: 18px` / `--r-sm: 12px`
6. **暗色模式**：`[data-theme="dark"]` 全套变量覆盖；页面自带的 CSS 不得写死亮色

## 二、关键令牌速查

| 令牌 | 用途 |
|---|---|
| `--bg` / `--bg-soft` | 页面底色 |
| `--surface` / `--surface-strong` | 玻璃卡片面 |
| `--fg` / `--muted` / `--faint` | 文字三级 |
| `--border` / `--border-soft` | 边框两级 |
| `--accent` / `--accent-strong` / `--accent-soft` | 品牌蓝三级 |
| `--ok` / `--warn` / `--danger`（+ soft） | 语义色 |
| `--font-display` / `--font-body` / `--font-mono` | 字体三声部 |

## 三、共享 archetype 规则（modules.css 的使用方式）

- 区块/组件**不写私有样式**，映射到共享 archetype（`.hero-center` `.cols` `.stats` `.steps` `.bento` `.toolgrid` 等）
- 页面私有 CSS 只放该页独有的东西，且必须用令牌变量
- 新组件先进 modules.css 成为 archetype，再被页面使用——禁止一次性的行内大段样式

## 四、展示区块清单（35 种，可复用）

布局：hero / journey / accordion / tabs / showcase / spotlight / changelog
内容：features / text / bento / tool-grid / cluster / blog-grid / code / kbd / ticker
转化：cta / form / newsletter / checklist / countdown / banner / prompt
媒体：image-text / gallery / marquee / before-after / canvas-wall / portrait / feature-detail / video
社交证明：testimonials / proof / quote-wall / team / logo-wall

> 区块 HTML 子项写法与渲染契约，见 OpenFlow `docs/PRODUCT-MATRIX.md` 与示例页 `/b/growth-os-tour`。

## 五、同步方法

```bash
# OpenFlow 改了设计系统后，手动同步到本项目：
cp /Users/seveno/OpenFlow\ Dev/assets/tokens.css   docs/assets-reference/
cp /Users/seveno/OpenFlow\ Dev/assets/modules.css  docs/assets-reference/
# 生产环境资产走 Cloudflare R2（见 CLOUDFLARE.md），改 CSS 后必须同步 R2 + purge
```
