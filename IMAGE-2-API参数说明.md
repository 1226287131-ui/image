# gpt-image-2 模型调用参数说明

本文档说明本项目调用 `gpt-image-2` 时使用的参数，以及 URL-only 返回约束。
这里的“返回 URL”指上游响应中的 `data[].url`；图片内容不得通过 `b64_json`、Data URL 或其他 base64 字段返回。

## URL-only 约束

每次请求都会固定发送：

```json
{
  "response_format": "url"
}
```

调用方应按 URL-only 约定处理上游响应：

- 每个图片项必须存在 `data[].url`
- `url` 必须是 `http://` 或 `https://` 地址
- `b64_json`、`data:image/...;base64,...` 均视为不合规
- 客户端只读取 `data[].url`，不读取 `b64_json`

注意：请求参数中的参考图仍可以使用 Data URL（其中包含 base64），这是输入格式；URL-only 约束只针对生成结果。

## 上游接口

### 文生图

```http
POST https://你的上游域名/v1/images/generations
Authorization: Bearer sk-你的key
Content-Type: application/json
```

请求体：

```json
{
  "model": "gpt-image-2",
  "prompt": "一只透明玻璃质感的蓝色水母，白色背景，商业摄影风格",
  "n": 1,
  "size": "1024x1024",
  "quality": "auto",
  "response_format": "url"
}
```

### 图生图 / 编辑图

有参考图时使用：

```http
POST https://你的上游域名/v1/images/edits
Authorization: Bearer sk-你的key
Content-Type: multipart/form-data
```

文本字段：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `model` | string | 是 | 固定为 `gpt-image-2` |
| `prompt` | string | 是 | 编辑要求；项目可能会在前面追加比例和参考图映射说明 |
| `n` | integer/string | 是 | 生成数量；项目单次上游请求通常为 `1` |
| `size` | string | 是 | 如 `1024x1024`、`1536x864` |
| `quality` | string | 否 | `auto`、`low`、`medium` 或 `high` |
| `response_format` | string | 是 | 固定为 `url`，禁止改为 `b64_json` |

文件字段：

- 1 张参考图：`image`
- 多张参考图：`image[0]`、`image[1]`、……

## 参数说明

| 参数 | 类型 | 必填 | 本项目行为 |
|---|---|---:|---|
| `model` | string | 否 | 不传时使用 `gpt-image-2`；其他模型不会套用本节 URL-only 校验 |
| `prompt` | string | 是 | 生图或编辑提示词 |
| `n` | integer | 否 | 上游单次生成数量；站内通过 `count` 控制，范围 `1-10`，多张时按张顺序调用 |
| `size` | string | 否 | 上游尺寸；站内优先使用 `exactSize`，否则根据 `ratio` + `resolution` 计算 |
| `quality` | string | 否 | 默认 `auto` |
| `response_format` | string | 是 | 后端强制为 `url`，调用方不能覆盖 |
| `image` / `image[i]` | file | 编辑时必填 | 由 `referenceImages` 转换为 multipart 文件 |

项目对外的异步接口使用更高层的参数：

| 站内参数 | 映射到上游 |
|---|---|
| `count` | `n`（多张时拆成多个 `n=1` 请求） |
| `exactSize` | `size` |
| `ratio` + `resolution` | 自动计算 `size`；默认 `1:1` + `1K` |
| `referenceImages` | `/v1/images/edits` 的 `image` / `image[i]` |

## 本项目调用示例

创建异步任务：

```bash
curl -X POST "https://你的域名/api/create-task.php" \
  -H "Content-Type: application/json" \
  -d '{
    "apiKey": "sk-你的key",
    "prompt": "赛博朋克城市夜景，雨水反光，电影感，高细节",
    "model": "gpt-image-2",
    "ratio": "16:9",
    "resolution": "2K",
    "quality": "high",
    "count": 1
  }'
```

查询任务：

```bash
curl -X POST "https://你的域名/api/check-task.php" \
  -H "Content-Type: application/json" \
  -d '{
    "apiKey": "sk-你的key",
    "taskId": "task_0123456789abcdef0123456789abcdef"
  }'
```

成功时，调用方只读取 `images[].url`：

```json
{
  "status": "succeeded",
  "images": [
    {
      "url": "/api/image-file.php?taskId=task_...&index=0",
      "absoluteUrl": "https://你的域名/api/image-file.php?taskId=task_...&index=0"
    }
  ]
}
```

不要读取或依赖 `b64_json`。本项目会隐藏上游源字段，只向外部提供图片代理 URL。

## 注意事项

请确认上游中转服务支持 `response_format: "url"`。如果上游仍返回 `b64_json`，不要在客户端解码；应检查请求参数或更换支持 URL 响应的上游服务。
