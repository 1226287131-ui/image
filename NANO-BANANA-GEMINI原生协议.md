# Nano Banana 2 / Pro Gemini 原生协议

本文档描述 Gemini 原生 REST `generateContent` 协议下的文生图和图生图请求结构，适用于已确认支持 Gemini 原生协议的 Google Gemini API 或兼容网关。

## 先看当前项目状态

本项目当前线上版本中，`Nano Banana 2` 与 `Nano Banana Pro` 实际使用的是 OpenAI Images 兼容协议：

```text
POST /v1/images/generations
POST /v1/images/edits
```

并通过 `size` 控制尺寸。这个行为可见于 `api/lib.php` 的 `build_upstream_request()`。

本文件是 Gemini 原生协议的独立参考，不代表当前 `api.kkone.vip` 已经可直接改用此协议。切换前必须确认网关同时支持目标模型和 `/v1beta/models/{model}:generateContent`，并用真实请求测试比例、清晰度和图生图返回值。

## 模型名

`Nano Banana 2`、`Nano Banana Pro` 是本站当前使用的网关模型名，不一定等于 Gemini 原生接口的模型 ID。

```text
{banana2_native_model}    Banana 2 在目标 Gemini 网关中的原生模型 ID
{bananapro_native_model}  Banana Pro 在目标 Gemini 网关中的原生模型 ID
```

请求时把占位符替换成网关实际提供的模型 ID。不要假设旧别名 `gemini-3.1-flash-image` 在任意网关都可用。

## 端点与鉴权

Google Gemini API 常见写法：

```http
POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={GEMINI_API_KEY}
Content-Type: application/json
```

兼容网关常见写法：

```http
POST https://{gateway-host}/v1beta/models/{model}:generateContent
Authorization: Bearer {API_KEY}
Content-Type: application/json
```

以目标网关的鉴权要求为准。不要在请求体、前端代码、日志或文档中写入真实 API Key。

## 原生字段与本站字段映射

| 本站调用字段 | Gemini 原生字段 | 说明 |
|---|---|---|
| `prompt` | `contents[].parts[].text` | 生图提示词 |
| `referenceImages` | `contents[].parts[].inlineData` | 每张参考图对应一个 `inlineData` part |
| `ratio` | `generationConfig.imageConfig.aspectRatio` | 仅在模型/网关支持时生效 |
| `resolution` | `generationConfig.imageConfig.imageSize` | 通常使用 `1K`、`2K`、`4K`，以模型支持范围为准 |
| `exactSize` | 无直接等价字段 | Gemini 原生协议通常不接收 `1360x768` 这类精确像素尺寸 |
| `count` | 无 `n` 等价字段 | 需要客户端或服务端逐次调用 `generateContent` |
| `quality` | 无通用等价字段 | 不要直接传 `quality`，除非目标网关另有文档 |

以下 OpenAI Images 字段不是 Gemini 原生字段，不能放进 Gemini 原生请求体：`model`、`n`、`size`、`quality`、`response_format`、`reference_images`、`image`、`image[]`。

## 文生图

### 请求体

```json
{
  "contents": [
    {
      "role": "user",
      "parts": [
        {
          "text": "A cinematic sunrise over a mountain lake, realistic photography, soft mist, wide composition."
        }
      ]
    }
  ],
  "generationConfig": {
    "responseModalities": ["TEXT", "IMAGE"],
    "imageConfig": {
      "aspectRatio": "16:9",
      "imageSize": "2K"
    }
  }
}
```

### cURL 示例

```bash
curl --request POST \
  --url "https://{gateway-host}/v1beta/models/{banana2_native_model}:generateContent" \
  --header "Authorization: Bearer {API_KEY}" \
  --header "Content-Type: application/json" \
  --data '{
    "contents": [{
      "role": "user",
      "parts": [{"text": "A premium perfume advertisement, black background, glass reflections, realistic commercial photography."}]
    }],
    "generationConfig": {
      "responseModalities": ["TEXT", "IMAGE"],
      "imageConfig": {
        "aspectRatio": "4:5",
        "imageSize": "2K"
      }
    }
  }'
```

将 URL 内的模型占位符替换为 `{bananapro_native_model}`，即可使用 Banana Pro 的相同协议结构。

## 图生图

Gemini 原生协议把参考图放入同一条用户消息的 `parts` 数组，而不是使用 multipart `image[]` 字段。

### 单张参考图

```json
{
  "contents": [
    {
      "role": "user",
      "parts": [
        {
          "text": "Keep the subject from the reference image, replace the background with a modern art gallery, realistic photography."
        },
        {
          "inlineData": {
            "mimeType": "image/png",
            "data": "iVBORw0KGgoAAAANSUhEUg..."
          }
        }
      ]
    }
  ],
  "generationConfig": {
    "responseModalities": ["TEXT", "IMAGE"],
    "imageConfig": {
      "aspectRatio": "3:4",
      "imageSize": "2K"
    }
  }
}
```

`data` 只能是纯 Base64 内容，不能包含 `data:image/png;base64,` 前缀。`mimeType` 应与真实文件匹配，常用值为 `image/png`、`image/jpeg`、`image/webp`。

### 多张参考图

每张图都增加一个 `inlineData` part，并在提示词中明确输入顺序：

```json
{
  "contents": [
    {
      "role": "user",
      "parts": [
        {
          "text": "Use input image 1 for the person and input image 2 for the background. Produce a realistic editorial portrait."
        },
        {
          "inlineData": {
            "mimeType": "image/jpeg",
            "data": "BASE64_OF_REFERENCE_1"
          }
        },
        {
          "inlineData": {
            "mimeType": "image/png",
            "data": "BASE64_OF_REFERENCE_2"
          }
        }
      ]
    }
  ],
  "generationConfig": {
    "responseModalities": ["TEXT", "IMAGE"],
    "imageConfig": {
      "aspectRatio": "1:1",
      "imageSize": "1K"
    }
  }
}
```

本站前后端参考图数量上限为 16 张。Gemini 原生模型或兼容网关可能设置了更低的单请求图片数、总请求体大小或像素限制，应以网关返回的错误为准。复杂编辑建议先用 1-4 张参考图测试。

### 已上传文件的写法

当目标 Gemini 服务支持 Files API 时，也可用 `fileData` 代替大 Base64：

```json
{
  "fileData": {
    "mimeType": "image/png",
    "fileUri": "https://generativelanguage.googleapis.com/v1beta/files/{file-id}"
  }
}
```

只有已完成 Files API 上传且目标服务支持 `fileData` 时才能使用。普通公网图片 URL 不能直接冒充 `fileUri`。

## 比例与分辨率

Gemini 原生写法：

```json
{
  "generationConfig": {
    "responseModalities": ["TEXT", "IMAGE"],
    "imageConfig": {
      "aspectRatio": "9:16",
      "imageSize": "2K"
    }
  }
}
```

常用比例为 `1:1`、`16:9`、`9:16`、`4:5`、`5:4`、`3:4`、`4:3`、`3:2`、`2:3`、`21:9`。实际可用比例及 `imageSize` 支持范围由具体模型决定：不支持时应省略该字段或按网关文档降级，不能依赖提示词中的尺寸文字作为替代。

`responseFormat.image`、`size`、`image_size` 不是这里定义的标准 Gemini 原生字段。此前某些兼容网关会忽略非原生尺寸字段并返回默认正方形图片，因此必须使用 `generationConfig.imageConfig` 并实测返回像素。

## 响应解析

Gemini 原生接口通常把图片 Base64 放在响应的 `inlineData` 中：

```json
{
  "candidates": [
    {
      "content": {
        "parts": [
          {
            "text": "Generated image."
          },
          {
            "inlineData": {
              "mimeType": "image/png",
              "data": "iVBORw0KGgoAAAANSUhEUg..."
            }
          }
        ]
      },
      "finishReason": "STOP"
    }
  ]
}
```

解析规则：

1. 遍历 `candidates[].content.parts[]`。
2. 找到含 `inlineData.data` 的 part。
3. 以 `inlineData.mimeType` 和 Base64 拼成 Data URL，或先 Base64 解码为图片字节。
4. 同时记录 `finishReason`；没有图片时检查 `promptFeedback`、`error` 和文本 part。

部分兼容网关可能把字段改成 `inline_data`、`mime_type`。这是兼容层差异，不是推荐的原生字段名；原生 Gemini 请求和响应应优先使用 `inlineData`、`mimeType`。

## 最小实现检查表

- 端点为 `/v1beta/models/{model}:generateContent`。
- 文本放在 `contents[].parts[].text`。
- 参考图放在同一 `parts` 数组的 `inlineData` 中。
- 图片输出请求 `generationConfig.responseModalities: ["TEXT", "IMAGE"]`。
- 比例/清晰度使用 `generationConfig.imageConfig`，并只传模型实际支持的值。
- 每次请求按 1 张结果处理；需要多张时逐次调用。
- 从响应 `candidates[].content.parts[].inlineData` 读取图片，不要期待 OpenAI 风格 `data[].url`。
- 在切换本站线上链路前，用文生图、单参考图、多参考图和非 `1:1` 比例各跑一次真实回归。
