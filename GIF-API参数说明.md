# GPT Image 2.6 GIF 动图

`gpt-image-2.6-flare` 和 `gpt-image-2.6-sunburst` 的实际请求模型名与界面模型名对应，原样提交上游。这两个模型的静态文生图、图生图沿用 `gpt-image-2` 协议；只有这两个模型可以启用 GIF。

## 网页使用

选择上述模型后，点击底部“静态图片 / GIF”，切换至“GIF 动图”。可设置帧数、每帧时长及提交尺寸，播放时长会实时显示。生成数量仍在底部“张数”中设置。

默认设置：`512x512`、9 帧、每帧 200ms、1 张，每轮播放 1.8 秒。GIF 目前仅支持文字生成，有参考图时请先移除参考图。预览、单张下载及批量 ZIP 下载保留 GIF 原始文件和动画。

## 上游请求

```http
POST /v1/images/generations
Authorization: Bearer <API_KEY>
Content-Type: application/json
```

```json
{
  "model": "gpt-image-2.6-sunburst",
  "prompt": "一只可爱的橘猫挥爪打招呼，固定镜头，角色保持一致，动作自然，首尾无缝循环，无文字。",
  "output_format": "gif",
  "frame_count": 9,
  "frame_duration_ms": 200,
  "size": "512x512",
  "n": 1,
  "response_format": "url"
}
```

| 参数 | 本站校验 / 默认值 |
| --- | --- |
| `model` | 仅 `gpt-image-2.6-flare`、`gpt-image-2.6-sunburst` |
| `prompt` | 非空字符串；建议描述主体、动作、画风及首尾循环 |
| `output_format` | `gif` |
| `frame_count` | 整数 2–16，默认 9 |
| `frame_duration_ms` | 数值 20–2000，按 10ms 四舍五入，默认 200 |
| `size` | `auto` 或宽高均为 16 的正整数倍的 `宽x高`，默认 `512x512` |
| `n` | 整数 1–10，默认 1；本站接口也兼容 `count`，同时传时 `count` 优先 |
| `response_format` | 本站固定发送 `url`，读取上游 `data[].url` |

不支持 `stream`、`partial_images`、`mask`、`output_compression`、`prompt_rewriter`。GIF 请求包含这些字段或参考图时，本站会在创建任务前返回 HTTP 400。静态图片的 `quality`、`resolution`、`exactSize` 不会混入 GIF 上游请求。

`n` 是 GIF 张数，`frame_count` 是每张 GIF 的帧数。每轮播放时长 = `frame_count × frame_duration_ms`。多张生成沿用本站现有顺序批处理策略，每次上游请求 `n=1`，最终汇总指定张数。

尺寸、帧数与张数受上游像素预算限制。建议先使用默认组合；`auto` 可能超预算。提交尺寸不保证是最终尺寸，上游可能返回不同尺寸；本站不会缩放或转码返回的动图。

## 本站异步任务接口

```http
POST /api/create-task.php
Content-Type: application/json
```

请求体使用上面的 GIF 参数，另加 `apiKey`。本站返回 HTTP 202 和 `taskId`；随后向现有 `/api/check-task.php` 提交 `taskId` 和 `apiKey`（JSON POST）轮询任务。完成后从任务 `images[].url` 读取本站图片代理地址，返回的媒体类型为 `image/gif`。

## 回归验证

服务器有 PHP CLI 和 cURL 扩展时，运行 `php tests/gif-request.php`。该测试覆盖请求构造、参数边界、非法模型限制、静态文生图/图生图兼容和 GIF 原始字节识别，不调用付费上游接口。

`python3 tests/gif-integration.py` 使用临时目录和本地模拟上游，验证排队前的 HTTP 400、创建任务、顺序批量、轮询、GIF 代理及静态编辑。需要 Python 3 和 PHP CLI。

安装 Playwright 及其 Chromium 后，可运行 `node tests/gif-ui.cjs`，检查网页操作、模型切换、桌面/手机/横屏布局和 GIF ZIP 下载。也可通过环境变量 `PLAYWRIGHT_CHANNEL=msedge` 使用本机 Edge，`GIF_SCREENSHOT_DIR` 可指定截图目录。测试 API 均在本地模拟，不会向付费上游发送请求。
