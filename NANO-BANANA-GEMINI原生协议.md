# Nano Banana 2 / Pro Gemini 原生协议

本文档描述 Gemini 原生 REST `generateContent` 协议下的文生图和图生图请求结构，适用于已确认支持 Gemini 原生协议的 Google Gemini API 或兼容网关。

## 先看当前项目状态

本项目当前线上版本中，`Nano Banana 2` 与 `Nano Banana Pro` 使用 Gemini 原生协议：

```text
POST /v1beta/models/{model}:generateContent
```

参考图通过同一条用户消息中的 `inlineData` 传递，并通过 `generationConfig.imageConfig` 控制比例和清晰度。`GPT-image-2` 继续使用 OpenAI Images 兼容协议。

这条路径与无限画布项目使用的 Gemini 请求结构保持一致。切换上游或模型名时，仍需用文生图、单参考图、多参考图和非 `1:1` 比例回归验证。

## 模型名

`Nano Banana 2`、`Nano Banana Pro` 是当前网关已验证的 Gemini 模型名，本站会原样 URL 编码后放进原生端点。

```text
Nano Banana 2
Nano Banana Pro
```

不要自行把它们替换成旧别名 `gemini-3.1-flash-image` 或其他猜测的模型 ID。

## 端点与鉴权

Google Gemini API 常见写法：

```http
POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={GEMINI_API_KEY}
Content-Type: application/json
```

本站 `api.kkone.vip` 当前使用的兼容网关写法：

```http
POST https://{gateway-host}/v1beta/models/{model}:generateContent
x-goog-api-key: {API_KEY}
Content-Type: application/json
```

不要在请求体、前端代码、日志或文档中写入真实 API Key。更换上游时，须按新网关要求重新确认鉴权头。

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
    "responseModalities": ["IMAGE"],
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
  --url "https://{gateway-host}/v1beta/models/Nano%20Banana%202:generateContent" \
  --header "x-goog-api-key: {API_KEY}" \
  --header "Content-Type: application/json" \
  --data '{
    "contents": [{
      "role": "user",
      "parts": [{"text": "A premium perfume advertisement, black background, glass reflections, realistic commercial photography."}]
    }],
    "generationConfig": {
    "responseModalities": ["IMAGE"],
      "imageConfig": {
        "aspectRatio": "4:5",
        "imageSize": "2K"
      }
    }
  }'
```

将 URL 内模型名替换为 `Nano Banana Pro`，即可使用 Banana Pro 的相同协议结构。

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
    "responseModalities": ["IMAGE"],
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
    "responseModalities": ["IMAGE"],
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
      "responseModalities": ["IMAGE"],
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
- 图片输出请求 `generationConfig.responseModalities: ["IMAGE"]`。
- 比例/清晰度使用 `generationConfig.imageConfig`，并只传模型实际支持的值。
- 每次请求按 1 张结果处理；需要多张时逐次调用。
- 从响应 `candidates[].content.parts[].inlineData` 读取图片，不要期待 OpenAI 风格 `data[].url`。
- 在切换本站线上链路前，用文生图、单参考图、多参考图和非 `1:1` 比例各跑一次真实回归。
