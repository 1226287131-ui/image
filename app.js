const form = document.querySelector("#createForm");
const consoleCard = document.querySelector(".console-card");
const promptInput = document.querySelector("#prompt");
const mentionMenu = document.querySelector("#mentionMenu");
const referenceInput = document.querySelector("#referenceInput");
const previewStrip = document.querySelector("#previewStrip");
const gallery = document.querySelector("#gallery");
const emptyState = document.querySelector("#emptyState");
const submitBtn = document.querySelector("#submitBtn");
const generateIcon = document.querySelector("#generateIcon");
const clearBtn = document.querySelector("#clearBtn");
const clearBtnLabel = document.querySelector("#clearBtn span");
const serverState = document.querySelector("#serverState");
const btnModelName = document.querySelector("#btnModelName");
const btnSizeName = document.querySelector("#btnSizeName");
const btnQualityName = document.querySelector("#btnQualityName");
const btnCountName = document.querySelector("#btnCountName");
const modalCurrentSize = document.querySelector("#modalCurrentSize");
const refCountText = document.querySelector("#refCountText");
const apiBaseText = document.querySelector("#apiBaseText");
const keyStateText = document.querySelector("#keyStateText");
const apiKeyInput = document.querySelector("#apiKeyInput");
const toggleKeyBtn = document.querySelector("#toggleKeyBtn");
const saveApiKeyBtn = document.querySelector("#saveApiKeyBtn");
const modelGrid = document.querySelector("#modelGrid");
const ratioGrid = document.querySelector("#ratioGrid");
const resolutionGroup = document.querySelector("#resolutionGroup");
const qualityGrid = document.querySelector("#qualityGrid");
const countGrid = document.querySelector("#countGrid");
const lightbox = document.querySelector("#lightbox");
const lightboxImg = document.querySelector("#lightboxImg");
const lightboxPrompt = document.querySelector("#lightboxPrompt");
const lightboxPrev = document.querySelector("#lightboxPrev");
const lightboxNext = document.querySelector("#lightboxNext");
const retentionNoticeWrap = document.querySelector("#retentionNoticeWrap");
const noticeTodayCheckbox = document.querySelector("#noticeTodayCheckbox");
const runningTasksCount = document.querySelector("#runningTasksCount");
const onlineUsersCount = document.querySelector("#onlineUsersCount");

const NOTICE_HIDDEN_DATE_KEY = "retention_notice_hidden_date";
const HISTORY_KEY = "image_tasks";
const MODEL_KEY = "image_model";
const SITE_STATUS_POLL_MS = 15000;
const MAX_REFERENCE_IMAGES = 16;
const MAX_REFERENCE_IMAGE_BYTES = 8 * 1024 * 1024;
const MAX_TOTAL_REFERENCE_BYTES = 24 * 1024 * 1024;
const TRANSPARENT_PIXEL = "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==";

const ratios = [
  { name: "方形", val: "1:1", w: 22, h: 22 },
  { name: "横屏", val: "5:4", w: 26, h: 21 },
  { name: "横屏", val: "4:3", w: 27, h: 20 },
  { name: "宽幅", val: "3:2", w: 28, h: 19 },
  { name: "宽屏", val: "16:9", w: 30, h: 17 },
  { name: "超宽", val: "21:9", w: 32, h: 14 },
  { name: "故事", val: "9:16", w: 17, h: 30 },
  { name: "标准", val: "4:5", w: 20, h: 25 },
  { name: "竖版", val: "3:4", w: 19, h: 27 },
  { name: "竖版", val: "2:3", w: 18, h: 28 }
];

const MODEL_OPTIONS = {
  "gpt-image-2": { label: "GPT-image-2" },
  "Nano Banana 2": { label: "Nano Banana 2" },
  "Nano Banana Pro": { label: "Nano Banana Pro" }
};

function normalizeModel(model) {
  const value = String(model || "").trim();
  const legacyValue = value.toLowerCase();
  if (legacyValue === "banana2" || legacyValue === "gemini-3.1-flash-image" || legacyValue === "nano banana 2") {
    return "Nano Banana 2";
  }
  if (legacyValue === "nano banana pro") return "Nano Banana Pro";
  return MODEL_OPTIONS[value] ? value : "gpt-image-2";
}

function modelLabel(model) {
  return MODEL_OPTIONS[normalizeModel(model)].label;
}

function loadHistory() {
  try {
    const tasks = JSON.parse(localStorage.getItem(HISTORY_KEY) || "[]");
    return Array.isArray(tasks) ? tasks : [];
  } catch (error) {
    localStorage.removeItem(HISTORY_KEY);
    return [];
  }
}

const state = {
  model: normalizeModel(localStorage.getItem(MODEL_KEY) || "gpt-image-2"),
  ratio: "1:1",
  resolution: "1K",
  quality: "auto",
  count: 1,
  exactSize: "1024x1024",
  apiKey: localStorage.getItem("gpt_api_key") || "",
  referenceImages: [],
  tasks: loadHistory()
};

const polls = new Map();
const selectedTaskIds = new Set();
const taskPreviewIndexes = new Map();
const imageObjectUrls = new Map();
const imageLoadPromises = new Map();
let lightboxState = { taskId: null, imageIndex: 0 };
let siteStatusTimer = null;
let dragDepth = 0;
const mentionState = {
  open: false,
  start: -1,
  activeIndex: 0
};

function syncDeleteButton() {
  const selectedCount = selectedTaskIds.size;
  if (!clearBtn) return;
  if (clearBtnLabel) clearBtnLabel.textContent = selectedCount ? `删除(${selectedCount})` : "清空";
  clearBtn.dataset.selectedCount = selectedCount ? String(selectedCount) : "";
  clearBtn.title = selectedCount ? `删除已选 ${selectedCount} 个任务` : "清空全部任务";
  clearBtn.setAttribute("aria-label", clearBtn.title);
  clearBtn.classList.toggle("has-selection", selectedCount > 0);
}

function pruneSelectedTasks() {
  const taskIds = new Set(state.tasks.map((task) => task.id));
  selectedTaskIds.forEach((id) => {
    if (!taskIds.has(id)) selectedTaskIds.delete(id);
  });
  taskPreviewIndexes.forEach((_, id) => {
    if (!taskIds.has(id)) taskPreviewIndexes.delete(id);
  });
  syncDeleteButton();
}

function getTaskPrompt(task) {
  return task?.request?.prompt || task?.prompt || "";
}

function taskDurationMs(task) {
  const explicit = Number(task?.durationMs);
  if (Number.isFinite(explicit) && explicit >= 0) return explicit;

  const startedAt = Number(task?.startedAt || task?.createdAt || 0);
  const finishedAt = Number(task?.finishedAt || task?.updatedAt || 0);
  if (startedAt > 0 && finishedAt >= startedAt) return finishedAt - startedAt;
  return 0;
}

function formatTaskDuration(task) {
  const durationMs = taskDurationMs(task);
  if (durationMs <= 0) return "耗时 --";

  const totalSeconds = Math.max(1, Math.round(durationMs / 1000));
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  if (minutes <= 0) return `耗时 ${totalSeconds}秒`;
  return `耗时 ${minutes}分${seconds}秒`;
}

function taskAspectRatioCss(ratio) {
  const match = String(ratio || "1:1").match(/^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/);
  if (!match || Number(match[1]) <= 0 || Number(match[2]) <= 0) return "1 / 1";
  return `${match[1]} / ${match[2]}`;
}

function taskImageKey(taskId, imageIndex) {
  return `${taskId}:${imageIndex}`;
}

function isProxyImageUrl(url) {
  return typeof url === "string" && url.startsWith("/api/image-file.php");
}

function proxyImageUrl(image) {
  if (!image) return "";
  return image.proxyUrl || (isProxyImageUrl(image.url) ? image.url : "");
}

function getTaskImageViewUrl(task, image, imageIndex) {
  const memoryUrl = imageObjectUrls.get(taskImageKey(task.id, imageIndex));
  if (memoryUrl) return memoryUrl;
  const rawUrl = String(image?.url || "");
  return rawUrl || TRANSPARENT_PIXEL;
}

function revokeTaskImageUrls(task) {
  if (!task?.id || !Array.isArray(task.images)) return;
  task.images.forEach((_, index) => {
    const key = taskImageKey(task.id, index);
    const url = imageObjectUrls.get(key);
    if (url) {
      URL.revokeObjectURL(url);
      imageObjectUrls.delete(key);
    }
    imageLoadPromises.delete(key);
  });
}

async function readErrorMessage(response, fallback) {
  const text = await response.text();
  if (!text) return fallback;
  try {
    const json = JSON.parse(text);
    return json.error || json.message || fallback;
  } catch (error) {
    return text.trim() || fallback;
  }
}

async function ensureTaskImageLoaded(taskId, imageIndex, options = {}) {
  const task = state.tasks.find((item) => item.id === taskId);
  const image = task?.images?.[imageIndex];
  if (!task || !image) return null;

  const key = taskImageKey(taskId, imageIndex);
  if (!options.force && imageObjectUrls.has(key)) {
    return imageObjectUrls.get(key);
  }
  if (!options.force && imageLoadPromises.has(key)) {
    return imageLoadPromises.get(key);
  }

  const proxyUrl = proxyImageUrl(image);
  if (!proxyUrl) return image.url || null;

  const promise = (async () => {
    const headers = {};
    if (state.apiKey) headers["X-API-Key"] = state.apiKey;

    const response = await fetch(proxyUrl, {
      method: "GET",
      headers,
      cache: "force-cache"
    });
    if (!response.ok) {
      throw new Error(await readErrorMessage(response, `图片读取失败，HTTP ${response.status}`));
    }

    const blob = await response.blob();
    const objectUrl = URL.createObjectURL(blob);
    const previousUrl = imageObjectUrls.get(key);
    if (previousUrl) URL.revokeObjectURL(previousUrl);
    imageObjectUrls.set(key, objectUrl);
    return objectUrl;
  })()
    .catch((error) => {
      console.warn(`任务 ${taskId} 第 ${imageIndex + 1} 张图片加载失败`, error);
      throw error;
    })
    .finally(() => {
      imageLoadPromises.delete(key);
    });

  imageLoadPromises.set(key, promise);
  const objectUrl = await promise;
  renderGallery();
  if (lightboxState.taskId === taskId) updateLightbox();
  return objectUrl;
}

async function preloadTaskImage(task, imageIndex = 0) {
  if (!task?.id || !Array.isArray(task.images)) return null;
  const image = task.images[imageIndex];
  if (!image) return null;

  const key = taskImageKey(task.id, imageIndex);
  if (imageObjectUrls.has(key)) return imageObjectUrls.get(key);
  if (imageLoadPromises.has(key)) return imageLoadPromises.get(key);

  const proxyUrl = proxyImageUrl(image);
  if (!proxyUrl) return image.url || null;

  const promise = (async () => {
    const headers = {};
    if (state.apiKey) headers["X-API-Key"] = state.apiKey;

    const response = await fetch(proxyUrl, {
      method: "GET",
      headers,
      cache: "force-cache"
    });
    if (!response.ok) {
      throw new Error(await readErrorMessage(response, `图片读取失败，HTTP ${response.status}`));
    }

    const blob = await response.blob();
    const objectUrl = URL.createObjectURL(blob);
    const previousUrl = imageObjectUrls.get(key);
    if (previousUrl) URL.revokeObjectURL(previousUrl);
    imageObjectUrls.set(key, objectUrl);
    return objectUrl;
  })()
    .catch((error) => {
      console.warn(`任务 ${task.id} 第 ${imageIndex + 1} 张图片预加载失败`, error);
      throw error;
    })
    .finally(() => {
      imageLoadPromises.delete(key);
    });

  imageLoadPromises.set(key, promise);
  return promise;
}

function hydrateTaskImages(task) {
  if (!task?.id || task.status !== "succeeded" || !Array.isArray(task.images)) return;
  task.images.forEach((_, index) => {
    ensureTaskImageLoaded(task.id, index).catch(() => {});
  });
}

async function openTaskImage(taskId, imageIndex) {
  const task = state.tasks.find((item) => item.id === taskId);
  const image = task?.images?.[imageIndex];
  if (!task || !image) return;

  try {
    const url = await ensureTaskImageLoaded(taskId, imageIndex);
    if (url) window.open(url, "_blank", "noopener,noreferrer");
  } catch (error) {
    alert(friendlyError(error.message));
  }
}

function clampTaskImageIndex(task, index = 0) {
  const imageCount = task?.images?.length || 0;
  const numericIndex = Number(index);
  const safeIndex = Number.isFinite(numericIndex) ? numericIndex : 0;
  if (!imageCount) return 0;
  return Math.min(imageCount - 1, Math.max(0, safeIndex));
}

function closeLightbox() {
  lightbox.classList.add("hidden");
  lightboxImg.src = TRANSPARENT_PIXEL;
  lightboxState = { taskId: null, imageIndex: 0 };
}

function updateLightbox() {
  const task = state.tasks.find((item) => item.id === lightboxState.taskId);
  if (!task?.images?.length) {
    closeLightbox();
    return;
  }

  const imageIndex = clampTaskImageIndex(task, lightboxState.imageIndex);
  const image = task.images[imageIndex];
  const hasMultipleImages = task.images.length > 1;
  lightboxState.imageIndex = imageIndex;
  taskPreviewIndexes.set(task.id, imageIndex);
  lightboxImg.src = getTaskImageViewUrl(task, image, imageIndex);
  lightboxPrompt.textContent = hasMultipleImages ? `${getTaskPrompt(task)}（第 ${imageIndex + 1} / ${task.images.length} 张）` : getTaskPrompt(task);
  ensureTaskImageLoaded(task.id, imageIndex).catch(() => {});

  [lightboxPrev, lightboxNext].forEach((button) => {
    if (!button) return;
    button.classList.toggle("hidden", !hasMultipleImages);
  });
  if (lightboxPrev) lightboxPrev.disabled = imageIndex <= 0;
  if (lightboxNext) lightboxNext.disabled = imageIndex >= task.images.length - 1;
}

function openLightbox(task, index = 0) {
  if (!task?.images?.length) return;
  lightboxState = { taskId: task.id, imageIndex: clampTaskImageIndex(task, index) };
  updateLightbox();
  lightbox.classList.remove("hidden");
}

function moveLightbox(direction) {
  const task = state.tasks.find((item) => item.id === lightboxState.taskId);
  if (!task?.images?.length) return;
  openLightbox(task, lightboxState.imageIndex + direction);
}

function getLocalDateKey() {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");
  return `${now.getFullYear()}-${month}-${day}`;
}

function hideRetentionNoticeForToday() {
  localStorage.setItem(NOTICE_HIDDEN_DATE_KEY, getLocalDateKey());
  retentionNoticeWrap?.classList.add("hidden");
  document.body.classList.add("notice-dismissed");
}

function initRetentionNotice() {
  if (!retentionNoticeWrap) return;
  const hiddenDate = localStorage.getItem(NOTICE_HIDDEN_DATE_KEY);
  const hiddenToday = hiddenDate === getLocalDateKey();
  retentionNoticeWrap.classList.toggle("hidden", hiddenToday);
  document.body.classList.toggle("notice-dismissed", hiddenToday);
  if (noticeTodayCheckbox) noticeTodayCheckbox.checked = false;
}

function openModal(id) {
  document.querySelectorAll(".modal").forEach((modal) => modal.classList.add("hidden"));
  document.querySelector(`#${id}Modal`)?.classList.remove("hidden");
}

function closeModal(id) {
  document.querySelector(`#${id}Modal`)?.classList.add("hidden");
}

function fileToDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

function saveHistory() {
  try {
    localStorage.setItem(HISTORY_KEY, JSON.stringify(state.tasks.slice(0, 60)));
  } catch (error) {
    console.warn("生成历史保存失败，可能是浏览器存储空间不足。", error);
  }
}

async function syncApiKeyCookie(apiKey) {
  try {
    await fetch("/api/sync-key.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ apiKey: apiKey || "" }),
      credentials: "same-origin",
      cache: "no-store"
    });
  } catch (error) {
    console.warn("同步图片访问凭证失败", error);
  }
}

function maskKey(value) {
  if (!value) return "未设置";
  if (value.length <= 12) return "已设置";
  return `${value.slice(0, 6)}...${value.slice(-4)}`;
}

function syncKeyUi() {
  if (apiKeyInput && apiKeyInput.value !== state.apiKey) apiKeyInput.value = state.apiKey;
  if (keyStateText) keyStateText.textContent = state.apiKey ? maskKey(state.apiKey) : "未设置";
  serverState.textContent = state.apiKey ? "令牌已保存" : "请先设置令牌";
}

function calculateExactSize(ratio, resolution) {
  const [rw, rh] = ratio.split(":").map(Number);
  const r = rw / rh || 1;
  const targetPixels = resolution === "4K" ? 8294400 : resolution === "2K" ? 4194304 : 1048576;
  let height = Math.round(Math.sqrt(targetPixels / r) / 16) * 16;
  let width = Math.round((height * r) / 16) * 16;

  if (width > 3840) {
    width = 3840;
    height = Math.round((width / r) / 16) * 16;
  }
  if (height > 3840) {
    height = 3840;
    width = Math.round((height * r) / 16) * 16;
  }

  const pixels = width * height;
  if (pixels > 8294400) {
    const scale = Math.sqrt(8294400 / pixels);
    width = Math.floor((width * scale) / 16) * 16;
    height = Math.floor((height * scale) / 16) * 16;
  } else if (pixels < 655360) {
    const scale = Math.sqrt(655360 / pixels);
    width = Math.ceil((width * scale) / 16) * 16;
    height = Math.ceil((height * scale) / 16) * 16;
  }

  return `${width}x${height}`;
}

function updateSizeLabels() {
  state.exactSize = calculateExactSize(state.ratio, state.resolution);
  btnSizeName.textContent = `${state.ratio} (${state.exactSize})`;
  modalCurrentSize.textContent = state.exactSize;
}

function updateModelUi() {
  state.model = normalizeModel(state.model);
  btnModelName.textContent = modelLabel(state.model);
  if (modelGrid) {
    modelGrid.querySelectorAll("button").forEach((button) => {
      button.classList.toggle("active", button.dataset.model === state.model);
    });
  }
  renderResolution();
  updateSizeLabels();
}

function renderRatioGrid() {
  ratioGrid.innerHTML = ratios
    .map(
      (ratio) => `
        <button type="button" class="ratio-btn ${ratio.val === state.ratio ? "active" : ""}" data-ratio="${ratio.val}">
          <span class="ratio-shape" style="width:${ratio.w}px;height:${ratio.h}px"></span>
          <span>${ratio.name}</span>
          <small>${ratio.val}</small>
        </button>
      `
    )
    .join("");
}

function renderResolution() {
  resolutionGroup.querySelectorAll("button").forEach((button) => {
    button.disabled = false;
    button.classList.toggle("active", button.dataset.resolution === state.resolution);
    button.style.opacity = "1";
  });
}

function renderQuality() {
  qualityGrid.querySelectorAll("button").forEach((button) => {
    button.classList.toggle("active", button.dataset.quality === state.quality);
  });
  const text = { low: "Low", auto: "Auto", medium: "Medium", high: "High" };
  btnQualityName.textContent = text[state.quality] || "Auto";
}

function renderCount() {
  countGrid.querySelectorAll("button").forEach((button) => {
    button.classList.toggle("active", Number(button.dataset.count) === state.count);
  });
  btnCountName.textContent = `${state.count}张`;
}

function renderPreviews() {
  previewStrip.innerHTML = state.referenceImages
    .map(
      (src, index) => `
        <div class="preview-wrap">
          <img class="preview" src="${src}" alt="参考图 ${index + 1}">
          <button class="preview-ref-tag" type="button" data-insert-ref="${index}" title="插入 @参考图${index + 1}">@参考图${index + 1}</button>
          <button class="preview-remove" type="button" data-remove-ref="${index}"><i class="fa-solid fa-xmark"></i></button>
        </div>
      `
    )
    .join("");
  refCountText.textContent = state.referenceImages.length ? `已传${state.referenceImages.length}张` : "传图/拖入";
}

function insertPromptToken(token) {
  const start = promptInput.selectionStart ?? promptInput.value.length;
  const end = promptInput.selectionEnd ?? promptInput.value.length;
  const before = promptInput.value.slice(0, start);
  const after = promptInput.value.slice(end);
  const prefix = before && !/\s$/.test(before) ? " " : "";
  const suffix = after && !/^\s/.test(after) ? " " : "";
  const insertion = `${prefix}${token}${suffix}`;
  promptInput.value = `${before}${insertion}${after}`;
  const cursor = start + insertion.length;
  promptInput.focus();
  promptInput.setSelectionRange(cursor, cursor);
  autoResizeTextarea();
  closeMentionMenu();
}

function closeMentionMenu() {
  mentionState.open = false;
  mentionState.start = -1;
  mentionState.activeIndex = 0;
  mentionMenu?.classList.add("hidden");
  if (mentionMenu) mentionMenu.innerHTML = "";
}

function getMentionQuery() {
  const cursor = promptInput.selectionStart ?? promptInput.value.length;
  const beforeCursor = promptInput.value.slice(0, cursor);
  const atIndex = beforeCursor.lastIndexOf("@");
  if (atIndex === -1) return null;
  const query = beforeCursor.slice(atIndex + 1);
  if (/\s/.test(query) || query.length > 12) return null;
  return { start: atIndex, end: cursor, query };
}

function mentionItems(query = "") {
  const normalized = query.replace(/^参考图/, "");
  return state.referenceImages
    .map((src, index) => ({ src, index, label: `参考图${index + 1}`, token: `@参考图${index + 1}` }))
    .filter((item) => !normalized || item.label.includes(query) || item.label.includes(normalized) || String(item.index + 1).startsWith(normalized));
}

function renderMentionMenu(items) {
  if (!mentionMenu) return;
  mentionMenu.innerHTML = items
    .map((item, position) => `
      <button class="mention-item ${position === mentionState.activeIndex ? "active" : ""}" type="button" role="option" aria-selected="${position === mentionState.activeIndex}" data-mention-index="${item.index}">
        <img src="${item.src}" alt="${item.label}">
        <span>${item.token}</span>
      </button>
    `)
    .join("");
}

function openMentionMenu() {
  const mention = getMentionQuery();
  if (!mention || !state.referenceImages.length) {
    closeMentionMenu();
    return;
  }

  const items = mentionItems(mention.query);
  if (!items.length) {
    closeMentionMenu();
    return;
  }

  mentionState.open = true;
  mentionState.start = mention.start;
  mentionState.activeIndex = Math.min(mentionState.activeIndex, items.length - 1);
  renderMentionMenu(items);
  mentionMenu?.classList.remove("hidden");
}

function selectMention(index = mentionState.activeIndex) {
  const mention = getMentionQuery();
  if (!mention) return;
  const items = mentionItems(mention.query);
  const item = items[index];
  if (!item) return;

  const before = promptInput.value.slice(0, mention.start);
  const after = promptInput.value.slice(mention.end);
  const prefix = before && !/\s$/.test(before) ? " " : "";
  const suffix = after && !/^\s/.test(after) ? " " : "";
  const insertion = `${prefix}${item.token}${suffix}`;
  promptInput.value = `${before}${insertion}${after}`;
  const cursor = before.length + insertion.length;
  promptInput.focus();
  promptInput.setSelectionRange(cursor, cursor);
  autoResizeTextarea();
  closeMentionMenu();
}

function moveMentionActive(direction) {
  const mention = getMentionQuery();
  if (!mention) return;
  const items = mentionItems(mention.query);
  if (!items.length) return;
  mentionState.activeIndex = (mentionState.activeIndex + direction + items.length) % items.length;
  renderMentionMenu(items);
}

function referencedImageNumbers(prompt) {
  const matches = prompt.matchAll(/@参考图\s*(\d+)/g);
  return [...new Set([...matches].map((match) => Number(match[1])).filter(Number.isFinite))];
}

async function addReferenceFiles(files) {
  const incomingFiles = [...files].filter((file) => file.type.startsWith("image/"));
  if (!incomingFiles.length) {
    alert("请拖入图片文件作为参考图。");
    return;
  }

  const availableSlots = MAX_REFERENCE_IMAGES - state.referenceImages.length;
  if (availableSlots <= 0) {
    alert(`最多只能上传 ${MAX_REFERENCE_IMAGES} 张参考图。`);
    return;
  }

  const selectedFiles = incomingFiles.slice(0, availableSlots);
  const oversized = selectedFiles.find((file) => file.size > MAX_REFERENCE_IMAGE_BYTES);
  if (oversized) {
    alert(`参考图「${oversized.name}」超过 8MB，请压缩后再上传。`);
    return;
  }

  const totalBytes = selectedFiles.reduce((sum, file) => sum + file.size, 0);
  if (totalBytes > MAX_TOTAL_REFERENCE_BYTES) {
    alert("本次参考图总大小超过 24MB，请减少图片数量或压缩后再上传。");
    return;
  }

  try {
    const nextImages = await Promise.all(selectedFiles.map(fileToDataUrl));
    state.referenceImages = [...state.referenceImages, ...nextImages].slice(0, MAX_REFERENCE_IMAGES);
    if (incomingFiles.length > selectedFiles.length) alert(`最多只能上传 ${MAX_REFERENCE_IMAGES} 张参考图，已自动保留前 ${selectedFiles.length} 张。`);
    renderPreviews();
    openMentionMenu();
  } catch (error) {
    alert("参考图读取失败，请换一张图片重试。");
  }
}

function hasDraggedFiles(event) {
  return [...(event.dataTransfer?.types || [])].includes("Files");
}

function setDragActive(active) {
  consoleCard?.classList.toggle("drag-active", active);
}

function setBusy(isBusy) {
  submitBtn.disabled = isBusy;
  generateIcon.className = isBusy ? "fa-solid fa-spinner fa-spin" : "fa-solid fa-paper-plane";
}

function setStatusNumber(element, value) {
  if (!element) return;
  const number = Number(value);
  element.textContent = Number.isFinite(number) ? String(number) : "-";
}

async function refreshSiteStatus() {
  try {
    const response = await fetch("/api/site-status.php", {
      method: "GET",
      cache: "no-store"
    });
    const status = await readJsonResponse(response);
    if (!response.ok) throw new Error(status.error || "状态读取失败");
    setStatusNumber(runningTasksCount, status.runningTasks);
    setStatusNumber(onlineUsersCount, status.onlineVisitors);
  } catch (error) {
    setStatusNumber(runningTasksCount, null);
    setStatusNumber(onlineUsersCount, null);
  }
}

function startSiteStatusPolling() {
  refreshSiteStatus();
  if (siteStatusTimer) clearInterval(siteStatusTimer);
  siteStatusTimer = setInterval(refreshSiteStatus, SITE_STATUS_POLL_MS);
}

function syncEmptyState() {
  emptyState.classList.toggle("hidden", state.tasks.length > 0);
}

function friendlyError(message) {
  const text = String(message || "");
  if (/Operation timed out|timed out|超时/i.test(text)) {
    return "生成超时：中转站 20 分钟内没有返回结果。建议减少张数，或降低分辨率/质量后重试。";
  }
  if (/Failed to fetch|NetworkError|Network request failed/i.test(text)) {
    return "网络请求失败：请检查网络或稍后重试。";
  }
  if (/openai_error/i.test(text)) {
    return "上游生成失败：模型接口返回 openai_error。请调整提示词、减少张数，或降低分辨率/质量后重试。";
  }
  if (/content_policy|safety|moderation|policy/i.test(text)) {
    return "内容被上游安全策略拦截，请修改提示词后重试。";
  }
  return text || "生成失败";
}

function taskStatusText(task) {
  if (task.status === "succeeded") return "生成完成";
  if (task.status === "failed") return friendlyError(task.error);
  return "正在努力画图...";
}

function renderGallery() {
  syncEmptyState();
  pruneSelectedTasks();
  gallery.innerHTML = state.tasks
    .map((task) => {
      const request = task.request || {};
      const prompt = request.prompt || task.prompt || "";
      const taskAspectRatio = taskAspectRatioCss(request.ratio);
      const selected = selectedTaskIds.has(task.id);
      const selectControl = `
        <label class="task-select" title="选择此任务">
          <input type="checkbox" data-select-task="${task.id}" ${selected ? "checked" : ""}>
          <span><i class="fa-solid fa-check"></i></span>
        </label>
      `;
      if (task.status === "succeeded" && task.images?.length) {
        const images = task.images;
        const hasMultipleImages = images.length > 1;
        const previewIndex = clampTaskImageIndex(task, taskPreviewIndexes.get(task.id));
        const heroImage = images[previewIndex];
        const thumbTiles = images
          .map((image, index) => `
            <button class="task-thumb ${index === previewIndex ? "active" : ""}" type="button" data-preview="${task.id}" data-image-index="${index}" title="在大预览中查看第 ${index + 1} 张" aria-label="在大预览中查看第 ${index + 1} 张">
              <img src="${getTaskImageViewUrl(task, image, index)}" alt="${escapeHtml(prompt)} 第 ${index + 1} 张" loading="lazy">
            </button>
          `)
          .join("");
        return `
          <article class="task-card ${hasMultipleImages ? "has-multiple" : ""} ${selected ? "selected" : ""}" data-task-id="${task.id}">
            ${selectControl}
            <div class="task-media">
              <div class="task-image-wrap is-hero" style="--task-aspect-ratio: ${taskAspectRatio}; aspect-ratio: ${taskAspectRatio}">
                <button class="task-hero-button" type="button" data-lightbox="${task.id}" data-image-index="${previewIndex}" title="查看原图">
                  <img src="${getTaskImageViewUrl(task, heroImage, previewIndex)}" alt="${escapeHtml(prompt)} 第 ${previewIndex + 1} 张" loading="lazy">
                </button>
                ${hasMultipleImages ? `<span class="image-count-pill"><i class="fa-regular fa-images"></i>${images.length}张</span>` : ""}
                <div class="card-actions">
                  <button class="mini-btn" type="button" data-lightbox="${task.id}" data-image-index="${previewIndex}" title="查看原图"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
                  <button class="mini-btn" type="button" data-open-image="${task.id}" data-image-index="${previewIndex}" title="打开"><i class="fa-solid fa-arrow-up-right-from-square"></i></button>
                </div>
              </div>
              ${hasMultipleImages ? `<div class="task-thumb-strip" aria-label="本任务生成的 ${images.length} 张图片">${thumbTiles}</div>` : ""}
            </div>
            <div class="card-meta">
              <strong class="card-prompt" title="${escapeHtml(prompt || "生成图片")}">${escapeHtml(prompt || "生成图片")}</strong>
              <div class="card-subline">
                <span>${request.resolution || ""} · ${request.ratio || ""} · ${request.quality || "auto"} · ${images.length}张</span>
                <span class="task-duration">${formatTaskDuration(task)}</span>
              </div>
            </div>
          </article>
        `;
      }

      const failed = task.status === "failed";
      return `
        <article class="task-card ${selected ? "selected" : ""}" data-task-id="${task.id}">
          ${selectControl}
          <div class="loading-tile" style="--task-aspect-ratio: ${taskAspectRatio}; aspect-ratio: ${taskAspectRatio}">
            <i class="fa-solid ${failed ? "fa-triangle-exclamation" : "fa-spinner fa-spin"}"></i>
          </div>
          <div class="card-meta">
            <strong class="${failed ? "error" : ""}">${escapeHtml(taskStatusText(task))}</strong>
            <div class="progress"><span style="width:${task.progress || 12}%"></span></div>
            <span class="card-prompt" title="${escapeHtml(prompt || "任务处理中")}">${escapeHtml(prompt || "任务处理中")}</span>
            <span class="task-duration">${formatTaskDuration(task)}</span>
          </div>
        </article>
      `;
    })
    .join("");
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;"
  })[char]);
}

async function readJsonResponse(response) {
  const text = await response.text();
  try {
    return JSON.parse(text);
  } catch (error) {
    const plain = text
      .replace(/<br\s*\/?>/gi, "\n")
      .replace(/<[^>]*>/g, " ")
      .replace(/\s+/g, " ")
      .trim();
    throw new Error(plain || `接口没有返回 JSON，HTTP ${response.status}`);
  }
}

async function pollTask(taskId) {
  try {
    const response = await fetch("/api/check-task.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ taskId, apiKey: state.apiKey }),
      cache: "no-store"
    });
    const task = await readJsonResponse(response);
    if (!response.ok) {
      const index = state.tasks.findIndex((item) => item.id === taskId);
      if (index !== -1 && (response.status === 400 || response.status === 404)) {
        state.tasks[index] = {
          ...state.tasks[index],
          status: "failed",
          progress: 100,
          error: task.error || "任务不存在，请重新提交"
        };
        saveHistory();
        renderGallery();
        clearInterval(polls.get(taskId));
        polls.delete(taskId);
        refreshSiteStatus();
        return;
      }
      throw new Error(task.error || "任务查询失败");
    }

    if (task.status === "succeeded" && task.images?.length) {
      const previewIndex = clampTaskImageIndex(task, taskPreviewIndexes.get(task.id));
      await preloadTaskImage(task, previewIndex).catch(() => null);
    }

    const index = state.tasks.findIndex((item) => item.id === taskId);
    if (index === -1) state.tasks.unshift(task);
    else state.tasks[index] = task;
    saveHistory();
    renderGallery();
    if (task.status === "succeeded") hydrateTaskImages(task);
    if (task.status === "succeeded" || task.status === "failed") {
      clearInterval(polls.get(taskId));
      polls.delete(taskId);
      refreshSiteStatus();
    }
  } catch (error) {
    const index = state.tasks.findIndex((item) => item.id === taskId);
    if (index !== -1) {
      state.tasks[index] = {
        ...state.tasks[index],
        status: state.tasks[index].status === "failed" ? "failed" : "running",
        progress: Math.max(state.tasks[index].progress || 12, 25),
        transientError: error.message
      };
      saveHistory();
      renderGallery();
    }
  }
}

async function submitTask() {
  const prompt = promptInput.value.trim();
  if (!prompt) {
    promptInput.focus();
    return;
  }
  const invalidRef = referencedImageNumbers(prompt).find((number) => number < 1 || number > state.referenceImages.length);
  if (invalidRef) {
    alert(`提示词里写了 @参考图${invalidRef}，但当前只上传了 ${state.referenceImages.length} 张参考图。`);
    promptInput.focus();
    return;
  }
  if (!state.apiKey) {
    openModal("settings");
    apiKeyInput?.focus();
    return;
  }
  setBusy(true);
  const model = state.model;
  const payload = {
    prompt,
    model,
    ratio: state.ratio,
    resolution: state.resolution,
    exactSize: state.exactSize,
    quality: state.quality,
    count: state.count,
    referenceImages: state.referenceImages,
    apiKey: state.apiKey
  };

  try {
    const response = await fetch("/api/create-task.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const body = await readJsonResponse(response);
    if (!response.ok) throw new Error(body.error || "任务提交失败");

    const pendingTask = {
      id: body.taskId,
      status: "queued",
      progress: 1,
      request: {
        prompt,
        model,
        modelLabel: modelLabel(model),
        ratio: state.ratio,
        resolution: state.resolution,
        exactSize: state.exactSize,
        quality: state.quality,
        count: state.count,
        referenceImageCount: state.referenceImages.length
      },
      images: []
    };
    state.tasks.unshift(pendingTask);
    saveHistory();
    renderGallery();
    refreshSiteStatus();
    promptInput.value = "";
    autoResizeTextarea();
    state.referenceImages = [];
    renderPreviews();
    setTimeout(() => pollTask(body.taskId), 800);
    polls.set(body.taskId, setInterval(() => pollTask(body.taskId), 5000));
  } catch (error) {
    alert(friendlyError(error.message));
  } finally {
    setBusy(false);
  }
}

function autoResizeTextarea() {
  promptInput.style.height = "auto";
  promptInput.style.height = `${Math.min(promptInput.scrollHeight, 150)}px`;
}

async function getConfig() {
  const response = await fetch("/api/config.php");
  const config = await readJsonResponse(response);
  if (apiBaseText) apiBaseText.textContent = config.apiBaseUrl || "-";
  syncKeyUi();
}

document.querySelectorAll("[data-open]").forEach((button) => {
  button.addEventListener("click", (event) => {
    event.preventDefault();
    openModal(button.dataset.open);
  });
});

document.querySelectorAll("[data-close]").forEach((button) => {
  button.addEventListener("click", () => closeModal(button.dataset.close));
});

document.querySelectorAll(".modal").forEach((modal) => {
  modal.addEventListener("click", (event) => {
    if (event.target === modal) modal.classList.add("hidden");
  });
});

ratioGrid.addEventListener("click", (event) => {
  const button = event.target.closest("[data-ratio]");
  if (!button) return;
  state.ratio = button.dataset.ratio;
  renderRatioGrid();
  updateSizeLabels();
});

resolutionGroup.addEventListener("click", (event) => {
  const button = event.target.closest("[data-resolution]");
  if (!button || button.disabled) return;
  state.resolution = button.dataset.resolution;
  updateModelUi();
});

modelGrid?.addEventListener("click", (event) => {
  const button = event.target.closest("[data-model]");
  if (!button) return;
  state.model = normalizeModel(button.dataset.model);
  localStorage.setItem(MODEL_KEY, state.model);
  updateModelUi();
  closeModal("model");
});

qualityGrid.addEventListener("click", (event) => {
  const button = event.target.closest("[data-quality]");
  if (!button) return;
  state.quality = button.dataset.quality;
  renderQuality();
  closeModal("quality");
});

countGrid.addEventListener("click", (event) => {
  const button = event.target.closest("[data-count]");
  if (!button) return;
  state.count = Math.min(10, Math.max(1, Number(button.dataset.count) || 1));
  if (state.count >= 5) {
    alert("一次生成 5 张以上会按张顺序生成，等待时间会更久。如果使用 4K 或参考图，建议先用 1-4 张测试。");
  }
  renderCount();
  closeModal("count");
});

previewStrip.addEventListener("click", (event) => {
  const insertButton = event.target.closest("[data-insert-ref]");
  if (insertButton) {
    insertPromptToken(`@参考图${Number(insertButton.dataset.insertRef) + 1}`);
    return;
  }

  const button = event.target.closest("[data-remove-ref]");
  if (!button) return;
  state.referenceImages.splice(Number(button.dataset.removeRef), 1);
  renderPreviews();
  openMentionMenu();
});

referenceInput.addEventListener("change", async () => {
  const files = [...referenceInput.files];
  referenceInput.value = "";
  if (files.length) await addReferenceFiles(files);
});

["dragenter", "dragover"].forEach((type) => {
  consoleCard?.addEventListener(type, (event) => {
    if (!hasDraggedFiles(event)) return;
    event.preventDefault();
    event.stopPropagation();
    event.dataTransfer.dropEffect = "copy";
    if (type === "dragenter") dragDepth += 1;
    setDragActive(true);
  });
});

consoleCard?.addEventListener("dragleave", (event) => {
  if (!hasDraggedFiles(event)) return;
  event.preventDefault();
  event.stopPropagation();
  dragDepth = Math.max(0, dragDepth - 1);
  if (dragDepth === 0) setDragActive(false);
});

consoleCard?.addEventListener("drop", async (event) => {
  if (!hasDraggedFiles(event)) return;
  event.preventDefault();
  event.stopPropagation();
  dragDepth = 0;
  setDragActive(false);
  await addReferenceFiles(event.dataTransfer.files);
});

promptInput.addEventListener("input", () => {
  autoResizeTextarea();
  openMentionMenu();
});

promptInput.addEventListener("keydown", (event) => {
  if (!mentionState.open) return;

  if (event.key === "ArrowDown") {
    event.preventDefault();
    moveMentionActive(1);
  } else if (event.key === "ArrowUp") {
    event.preventDefault();
    moveMentionActive(-1);
  } else if (event.key === "Enter" || event.key === "Tab") {
    event.preventDefault();
    selectMention();
  } else if (event.key === "Escape") {
    event.preventDefault();
    closeMentionMenu();
  }
});

promptInput.addEventListener("blur", () => {
  setTimeout(closeMentionMenu, 120);
});

mentionMenu?.addEventListener("mousedown", (event) => {
  event.preventDefault();
});

mentionMenu?.addEventListener("click", (event) => {
  const button = event.target.closest("[data-mention-index]");
  if (!button) return;
  const mention = getMentionQuery();
  const items = mention ? mentionItems(mention.query) : [];
  const position = items.findIndex((item) => item.index === Number(button.dataset.mentionIndex));
  selectMention(Math.max(0, position));
});

form.addEventListener("submit", (event) => {
  event.preventDefault();
  submitTask();
});

clearBtn.addEventListener("click", () => {
  if (selectedTaskIds.size) {
    const selected = new Set(selectedTaskIds);
    selected.forEach((id) => {
      if (polls.has(id)) {
        clearInterval(polls.get(id));
        polls.delete(id);
      }
    });
    state.tasks.filter((task) => selected.has(task.id)).forEach(revokeTaskImageUrls);
    state.tasks = state.tasks.filter((task) => !selected.has(task.id));
    selectedTaskIds.clear();
  } else {
    if (!state.tasks.length) return;
    if (!confirm("确定清空全部图片任务吗？")) return;
    polls.forEach((timer) => clearInterval(timer));
    polls.clear();
    state.tasks.forEach(revokeTaskImageUrls);
    state.tasks = [];
  }
  saveHistory();
  renderGallery();
});

saveApiKeyBtn?.addEventListener("click", () => {
  state.apiKey = apiKeyInput.value.trim();
  if (state.apiKey) localStorage.setItem("gpt_api_key", state.apiKey);
  else localStorage.removeItem("gpt_api_key");
  syncKeyUi();
  closeModal("settings");
  syncApiKeyCookie(state.apiKey);
  state.tasks.forEach((task) => {
    revokeTaskImageUrls(task);
    hydrateTaskImages(task);
  });
});

toggleKeyBtn?.addEventListener("click", () => {
  const visible = apiKeyInput.type === "text";
  apiKeyInput.type = visible ? "password" : "text";
  toggleKeyBtn.innerHTML = visible ? '<i class="fa-solid fa-eye"></i>' : '<i class="fa-solid fa-eye-slash"></i>';
});

noticeTodayCheckbox?.addEventListener("change", () => {
  if (noticeTodayCheckbox.checked) hideRetentionNoticeForToday();
});

gallery.addEventListener("change", (event) => {
  const checkbox = event.target.closest("[data-select-task]");
  if (!checkbox) return;
  if (checkbox.checked) selectedTaskIds.add(checkbox.dataset.selectTask);
  else selectedTaskIds.delete(checkbox.dataset.selectTask);
  renderGallery();
});

gallery.addEventListener("click", (event) => {
  const previewButton = event.target.closest("[data-preview]");
  if (previewButton) {
    const task = state.tasks.find((item) => item.id === previewButton.dataset.preview);
    if (!task?.images?.length) return;
    const previewIndex = clampTaskImageIndex(task, previewButton.dataset.imageIndex);
    taskPreviewIndexes.set(task.id, previewIndex);
    ensureTaskImageLoaded(task.id, previewIndex).catch(() => {});
    renderGallery();
    return;
  }

  const openButton = event.target.closest("[data-open-image]");
  if (openButton) {
    const task = state.tasks.find((item) => item.id === openButton.dataset.openImage);
    if (!task?.images?.length) return;
    openTaskImage(task.id, clampTaskImageIndex(task, openButton.dataset.imageIndex));
    return;
  }

  const button = event.target.closest("[data-lightbox]");
  if (!button) return;
  const task = state.tasks.find((item) => item.id === button.dataset.lightbox);
  if (!task?.images?.length) return;
  openLightbox(task, button.dataset.imageIndex);
});

document.querySelector(".lightbox-close").addEventListener("click", () => {
  closeLightbox();
});

lightboxPrev?.addEventListener("click", (event) => {
  event.stopPropagation();
  moveLightbox(-1);
});

lightboxNext?.addEventListener("click", (event) => {
  event.stopPropagation();
  moveLightbox(1);
});

lightbox.addEventListener("click", (event) => {
  if (event.target === lightbox) closeLightbox();
});

document.addEventListener("keydown", (event) => {
  if (lightbox.classList.contains("hidden")) return;
  if (event.key === "Escape") closeLightbox();
  if (event.key === "ArrowLeft") moveLightbox(-1);
  if (event.key === "ArrowRight") moveLightbox(1);
});

async function initializeApp() {
  renderRatioGrid();
  renderResolution();
  renderQuality();
  renderCount();
  updateModelUi();
  renderPreviews();
  syncKeyUi();
  initRetentionNotice();
  startSiteStatusPolling();

  await syncApiKeyCookie(state.apiKey);

  renderGallery();
  state.tasks.forEach(hydrateTaskImages);

  getConfig().catch(() => {
    serverState.textContent = "配置读取失败";
  });

  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) refreshSiteStatus();
  });

  state.tasks.forEach((task) => {
    if (task.status === "queued" || task.status === "running") {
      polls.set(task.id, setInterval(() => pollTask(task.id), 5000));
    }
  });
}

initializeApp();
