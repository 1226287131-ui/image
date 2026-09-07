# 生图网站 API 开发文档

本文档描述的是“调用本生图网站的 API”，不是直接调用上游中转站 API。  
外部系统、无限画布、自动化脚本应优先调用本站接口，因为本站会自动处理尺寸换算、任务落盘、图片下载保存、轮询状态、参考图顺序映射等逻辑。

`gpt-image-2` 的上游参数、URL-only 返回约束和直接请求示例，另见 [`IMAGE-2-API参数说明.md`](IMAGE-2-API参数说明.md)。

## 基础信息

- API 根地址：`https://你的域名`
- 请求格式：`application/json`
- 返回格式：`application/json`
- 鉴权方式：请求体中传入用户自己的 `apiKey`
- 生图方式：异步任务

完整流程：

1. 调用 `POST /api/create-task.php` 创建任务
2. 得到 `taskId`
3. 每隔 5-10 秒调用 `POST /api/check-task.php` 查询任务
4. 当 `status` 为 `succeeded` 时读取 `images[].url`

## 创建任务

接口：

```http
POST /api/create-task.php
Content-Type: application/json
```

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|---|---:|---:|---|
| `apiKey` | string | 是 | 用户在中转站申请的 API Key，通常是 `sk-...` |
| `prompt` | string | 是 | 生图提示词 |
| `model` | string | 否 | 可选 `gpt-image-2`、`Nano Banana 2` 或 `Nano Banana Pro`。默认 `gpt-image-2` |
| `ratio` | string | 否 | 画面比例，默认 `1:1` |
| `resolution` | string | 否 | `1K`、`2K`、`4K`，默认 `1K` |
| `exactSize` | string | 否 | 精确尺寸，如 `1024x1024`。不传时本站会按比例和分辨率自动计算 |
| `quality` | string | 否 | `auto`、`low`、`medium`、`high`，默认 `auto` |
| `count` | number | 否 | 生成张数，1-10，默认 1 |
| `referenceImages` | string[] | 否 | 参考图数组，最多 16 张，使用 Data URL 或 base64。传入后按图生图/编辑图处理 |
| `lockRatio` | boolean | 否 | 默认 `true`。为 true 时本站会自动在 prompt 前加入比例和尺寸说明 |

### 支持的模型

| 模型 | 说明 |
|---|---|
| `gpt-image-2` | OpenAI 风格接口，支持文生图、图生图、1K、2K、4K |
| `Nano Banana 2` | OpenAI Images 兼容链路，实际提交模型名称为 `Nano Banana 2` |
| `Nano Banana Pro` | OpenAI Images 兼容链路，实际提交模型名称为 `Nano Banana Pro` |

注意：

- 调用方不传 `model` 时，后端默认使用 `gpt-image-2`
- `Nano Banana 2` 与 `Nano Banana Pro` 会直接作为上游模型名提交
- 为兼容旧版本浏览器缓存和旧客户端，传入旧值 `banana2` 或 `gemini-3.1-flash-image` 时，后端仍会自动映射到 `Nano Banana 2`
- 当 `model = Nano Banana 2` 或 `model = Nano Banana Pro` 时，后端实际调用：

```text
POST /v1/images/generations
```

- 传入 `referenceImages` 时改用 `POST /v1/images/edits`
- 两个 Nano Banana 模型共用同一套 OpenAI Images 兼容请求结构，只有 `model` 值不同

### Nano Banana 已跑通参数说明

这是目前本站已经实际跑通的两款 `Nano Banana` 请求方式：

- 站内请求模型名：`Nano Banana 2`
- 上游真实模型名：`Nano Banana 2`
- 上游端口：`/v1/images/generations`
- 比例和分辨率生效字段：`size`
- 已实测成功：
  - `Nano Banana 2`：请求 `1360x768`，返回 `1376x768`（上游调整到支持的宽度步长）
  - `Nano Banana Pro`：请求 `2480x3312`，返回 `2480x3312`

补充说明：

- `exactSize` 会直接映射为上游的 `size`
- `quality` 会直接传给上游
- `count > 1` 时，本站仍然按张顺序请求上游，每次生成 1 张再合并
- `Nano Banana Pro` 使用相同的请求体、参考图处理和结果解析链路，仅上游模型名替换为 `Nano Banana Pro`

### 生成张数

`count` 支持 `1-10`。  
当 `count > 1` 时，本站后端会按张顺序请求上游接口，每次生成 1 张，最后合并到同一个任务结果里。这样比直接向上游提交 `n > 1` 更稳定，但等待时间会随张数增加。

返回成功后：

- `images[0]` 是第 1 张
- `images[1]` 是第 2 张
- 以此类推

如果是 4K 或参考图编辑，建议第三方调用方先使用 `count: 1` 测试。

### 支持的比例

`ratio` 只能使用下面这些值：

```text
1:1
5:4
4:3
3:2
16:9
21:9
9:16
4:5
3:4
2:3
```

### 分辨率与尺寸

本站支持三档分辨率：

| `resolution` | 目标像素量 | 说明 |
|---|---:|---|
| `1K` | 约 1,048,576 像素 | 默认档 |
| `2K` | 约 4,194,304 像素 | 使用 `gpt-image-2` |
| `4K` | 约 8,294,400 像素 | 使用 `gpt-image-2` |

当 `model = Nano Banana 2` 或 `model = Nano Banana Pro` 时：

- 后端会把 `exactSize` 直接映射到上游 `size`
- 上游可能将宽高调整到它支持的尺寸步长，但会保持请求比例
- 本站任务卡片会按任务比例完整显示图片，不再用正方形裁切

如果不传 `exactSize`，本站会根据 `ratio` 和 `resolution` 自动计算尺寸，并且：

- 宽高会按 16 的倍数取整
- 最大宽高不超过 `3840`
- 总像素不超过约 `8294400`
- 总像素不低于约 `655360`

常用示例：

| 比例 | 1K 示例 | 2K 示例 | 4K 示例 |
|---|---:|---:|---:|
| `1:1` | `1024x1024` | 约 `2048x2048` | 约 `2880x2880` |
| `16:9` | 约 `1360x768` | 约 `2736x1536` | 约 `3840x2160` |
| `9:16` | 约 `768x1360` | 约 `1536x2736` | 约 `2160x3840` |

实际尺寸以本站前端或后端计算结果为准。调用方也可以直接传 `exactSize`，例如 `1024x1024`、`1536x864`、`2160x3840`。

## 文生图示例

### 1K 文生图

```bash
curl -X POST "https://你的域名/api/create-task.php" \
  -H "Content-Type: application/json" \
  -d '{
    "apiKey": "sk-你的key",
    "prompt": "一只透明玻璃质感的蓝色水母，干净白色背景，商业摄影风格",
    "model": "gpt-image-2",
    "ratio": "1:1",
    "resolution": "1K",
    "quality": "auto",
    "count": 1
  }'
```

返回：

```json
{
  "taskId": "task_0123456789abcdef0123456789abcdef"
}
```

### 2K 文生图

```json
{
  "apiKey": "sk-你的key",
  "prompt": "赛博朋克城市夜景，雨水反光，电影感，高细节",
  "model": "gpt-image-2",
  "ratio": "16:9",
  "resolution": "2K",
  "quality": "high",
  "count": 1
}
```

### Nano Banana 2 文生图

这是调用本站接口的写法：

```json
{
  "apiKey": "sk-你的key",
  "prompt": "电影感山间湖泊日出，真实摄影，云雾，宽画幅构图",
  "model": "Nano Banana 2",
  "ratio": "16:9",
  "resolution": "2K",
  "quality": "auto",
  "count": 1
}
```

本站后端实际转发给上游的核心请求参数如下：

```json
{
  "model": "Nano Banana 2",
  "prompt": "Make the aspect ratio 16:9. Output size 2736x1536.\n电影感山间湖泊日出，真实摄影，云雾，宽画幅构图",
  "n": 1,
  "size": "2736x1536",
  "quality": "auto",
  "response_format": "url"
}
```

对应上游接口：

```http
POST https://api.kkone.vip/v1/images/generations
Authorization: Bearer sk-你的key
Content-Type: application/json
```

说明：

- 真正控制 Nano Banana 比例/分辨率的是 OpenAI Images 请求的 `size`
- 当前 Key 对应的 `api.kkone.vip` Gemini 路由会将图片尺寸参数丢失并回退到约 `2048x2048`，所以本站不再使用该路由
- 上游返回的图片数据位于：

```text
data[].url
```

对于 `gpt-image-2`，请求参数应固定使用 `response_format: "url"`。调用方只应读取任务结果中的 `images[].url`，不要读取 `b64_json`。

本站会通过 `/api/image-file.php` 代理上游图片地址给前端显示。

- 本站会把这部分图片数据转成任务图片，最终对外仍通过：

```text
images[].url
```

提供访问地址

### 4K 文生图

```json
{
  "apiKey": "sk-你的key",
  "prompt": "高端护肤品海报，极简构图，柔光，真实摄影",
  "model": "gpt-image-2",
  "ratio": "9:16",
  "resolution": "4K",
  "quality": "high",
  "count": 1
}
```

## 图生图 / 参考图编辑

传入 `referenceImages` 后，本站会调用图像编辑流程。

当 `model = Nano Banana 2` 或 `model = Nano Banana Pro` 时，参考图走 OpenAI multipart 编辑接口 `/v1/images/edits`，比例和分辨率仍通过 `size` 传递。

### Nano Banana Pro 文生图

`Nano Banana Pro` 与 `Nano Banana 2` 的参数完全相同，只替换模型名：

```json
{
  "apiKey": "sk-你的key",
  "prompt": "高端香水广告，黑色背景，玻璃反射，真实商业摄影",
  "model": "Nano Banana Pro",
  "ratio": "4:5",
  "resolution": "2K",
  "quality": "auto",
  "count": 1
}
```

对应上游接口：

```http
POST https://api.kkone.vip/v1/images/generations
```

`referenceImages` 推荐传 Data URL：

```text
data:image/png;base64,....
data:image/jpeg;base64,....
data:image/webp;base64,....
```

限制建议与本站前端一致：

- 最多 16 张参考图
- 单张建议不超过 8MB
- 单次参考图总大小建议不超过 24MB

### 单张参考图编辑

```json
{
  "apiKey": "sk-你的key",
  "prompt": "把这张图改成暖色调室内摄影风格，保留主体结构",
  "model": "gpt-image-2",
  "ratio": "1:1",
  "resolution": "1K",
  "quality": "auto",
  "count": 1,
  "referenceImages": [
    "data:image/png;base64,iVBORw0KGgo..."
  ]
}
```

### 多张参考图与 @ 引用

本站支持在提示词中使用：

```text
@参考图1
@参考图2
@参考图3
```

这些编号对应 `referenceImages` 数组顺序：

| 提示词写法 | 对应图片 |
|---|---|
| `@参考图1` | `referenceImages[0]` |
| `@参考图2` | `referenceImages[1]` |
| `@参考图3` | `referenceImages[2]` |

示例：

```json
{
  "apiKey": "sk-你的key",
  "prompt": "把 @参考图1 的人物保留下来，把背景替换成 @参考图2 的背景，保持真实摄影风格",
  "model": "gpt-image-2",
  "ratio": "1:1",
  "resolution": "2K",
  "quality": "high",
  "count": 1,
  "referenceImages": [
    "data:image/png;base64,第一张图...",
    "data:image/png;base64,第二张图..."
  ]
}
```

本站后端会自动在真实提交给上游的 prompt 前加入参考图映射说明，例如：

```text
Reference image mapping:
- @参考图1 means input image 1 in the uploaded image order.
- @参考图2 means input image 2 in the uploaded image order.

User prompt:
把 @参考图1 的人物保留下来，把背景替换成 @参考图2 的背景
```

这样可以让模型更容易识别“哪张图是哪张图”。

## 查询任务

接口：

```http
POST /api/check-task.php
Content-Type: application/json
```

请求：

```json
{
  "taskId": "task_0123456789abcdef0123456789abcdef",
  "apiKey": "sk-你的key"
}
```

返回处理中：

```json
{
  "id": "task_0123456789abcdef0123456789abcdef",
  "status": "running",
  "progress": 25,
  "createdAt": 1710000000000,
  "updatedAt": 1710000005000,
  "request": {
    "prompt": "提示词",
    "model": "gpt-image-2",
    "ratio": "1:1",
    "resolution": "2K",
    "exactSize": "2048x2048",
    "quality": "high",
    "count": 1,
    "referenceImageCount": 2
  },
  "images": [],
  "error": null
}
```

返回成功：

```json
{
  "id": "task_0123456789abcdef0123456789abcdef",
  "status": "succeeded",
  "progress": 100,
  "request": {
    "prompt": "提示词",
    "model": "gpt-image-2",
    "ratio": "1:1",
    "resolution": "2K",
    "exactSize": "2048x2048",
    "quality": "high",
    "count": 1,
    "referenceImageCount": 2
  },
  "images": [
    {
      "url": "/api/image-file.php?taskId=task_0123456789abcdef0123456789abcdef&index=0",
      "proxyUrl": "/api/image-file.php?taskId=task_0123456789abcdef0123456789abcdef&index=0",
      "absoluteUrl": "https://你的域名/api/image-file.php?taskId=task_0123456789abcdef0123456789abcdef&index=0",
      "upstreamUrl": "https://...",
      "revisedPrompt": ""
    }
  ],
  "error": null
}
```

返回失败：

```json
{
  "id": "task_0123456789abcdef0123456789abcdef",
  "status": "failed",
  "progress": 100,
  "images": [],
  "error": "失败原因"
}
```

## 状态字段

| `status` | 说明 |
|---|---|
| `queued` | 已创建，等待处理 |
| `running` | 正在生成 |
| `succeeded` | 生成成功 |
| `failed` | 生成失败 |

建议轮询策略：

- 创建任务后 1 秒左右开始查
- 前 1 分钟每 5 秒查一次
- 之后每 10-20 秒查一次
- 最长等待可按 20 分钟处理

## 本站接口与中转站接口的区别

很多无限画布直接调用中转站 API 会失败，通常是因为它没有处理本站额外做的这些事情：

1. 本站会把 `ratio + resolution` 换算成真实 `size`
2. 本站会固定使用 `gpt-image-2`，并开放 1K、2K、4K
3. 本站会把多张图按顺序上传为编辑图输入
4. 本站会把 `@参考图1` 映射成第 1 张上传参考图
5. 本站不会把上游返回的图片落盘到本站磁盘；任务只保存图片源信息，前端通过 `/api/image-file.php` 按需取图
6. 本站用任务文件保存状态，调用方只需要轮询 `taskId`
7. 本站会处理多张生成图的顺序请求，降低上游批量生成不稳定的问题

因此，第三方工具要接入时，不建议直接复刻中转站参数。  
更稳的方式是调用：

```text
POST /api/create-task.php
POST /api/check-task.php
```

## 常见错误

### `Prompt is required.`

没有传 `prompt`，或传了空字符串。

### `API key is required.`

没有传 `apiKey`。

### `Invalid task id.`

`taskId` 格式错误。正确格式类似：

```text
task_0123456789abcdef0123456789abcdef
```

### `Task not found.`

任务不存在，或任务文件已被 48 小时自动清理。

## 最小接入示例

JavaScript：

```js
async function createImageTask(apiKey, prompt) {
  const response = await fetch("https://你的域名/api/create-task.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      apiKey,
      prompt,
      model: "gpt-image-2",
      ratio: "1:1",
      resolution: "1K",
      quality: "auto",
      count: 1
    })
  });

  if (!response.ok) throw new Error(await response.text());
  return response.json();
}

async function checkImageTask(apiKey, taskId) {
  const response = await fetch("https://你的域名/api/check-task.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ apiKey, taskId })
  });

  if (!response.ok) throw new Error(await response.text());
  return response.json();
}
```
