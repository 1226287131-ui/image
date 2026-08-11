# PHP Virtual Host Version

Upload the contents of this folder to the PHP virtual host web root.

API documentation:

- `API开发文档.md`: site API and asynchronous task integration.
- `IMAGE-2-API参数说明.md`: `gpt-image-2` upstream parameters and the URL-only response requirement.
- `NANO-BANANA-GEMINI原生协议.md`: Gemini native `generateContent` text-to-image and image-to-image request reference for Nano Banana models.

Required PHP capabilities:

- PHP 8.0 or newer.
- cURL extension enabled.
- HTTPS requests allowed from PHP.
- `storage/tasks/` writable by PHP.
- Request timeout long enough for image generation, recommended 1200 seconds or higher for large-size or reference-image generations.

No server-side default API key is used. Every user enters their own API Key in the settings modal. The key is saved in that user's browser `localStorage` and is sent only when creating/checking a task.

## If money is charged but the browser shows "Failed to fetch"

That usually means the PHP virtual host or reverse proxy cut off the browser request while PHP was still waiting for the image API. This version keeps polling after transient network failures and asks PHP to continue running after browser disconnects, but some cheap virtual hosts forcibly kill long PHP requests anyway.

If it still happens, increase these host settings:

- `max_execution_time`: 1260 or higher
- `default_socket_timeout`: 1200 or higher
- reverse proxy / CDN timeout: 1200 seconds or higher
- PHP-FPM request timeout: 1260 or higher

If the host does not allow long-running PHP requests, image generation cannot be made fully reliable on that hosting plan. Use a VPS/Node service or a PHP host that allows long requests.

Files to upload:

- `index.html`
- `style.css`
- `app.js`
- `api/`
- `storage/`

Do not upload the Node files from the parent project (`server.js`, `package.json`, `node_modules`).
