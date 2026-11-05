import { randomUUID } from 'node:crypto';

const NETWORK_EVENTS = [
  'Network.requestWillBeSent', 'Network.requestWillBeSentExtraInfo',
  'Network.responseReceived', 'Network.responseReceivedExtraInfo',
  'Network.loadingFinished', 'Network.loadingFailed', 'Network.requestServedFromCache',
];
const CDP_COMMANDS = ['Network.enable', 'Network.getResponseBody', 'Page.captureScreenshot'];
const MAX_EVENTS = 100_000;
const MAX_EVENT_BYTES = 256 * 1024 * 1024;
const MAX_PAGE_SIZE = 1_000;
const DEFAULT_VIEWPORT = { width: 1280, height: 720 };

function requireValue(condition, code) {
  if (!condition) throw new Error(code);
}

function captureNetwork(page, session, tabId) {
  const source = { tabId };
  const retained = [];
  const lastDropped = new Map();
  let sequence = 0;
  let bytes = 0;

  for (const method of NETWORK_EVENTS) {
    session.on(method, params => {
      if (page.isClosed()) return;
      const event = { sequence: ++sequence, method, params, source };
      if (lastDropped.size === 0 && retained.length < MAX_EVENTS) {
        const size = Buffer.byteLength(JSON.stringify(event));
        if (bytes + size <= MAX_EVENT_BYTES) {
          retained.push(event);
          bytes += size;
          return;
        }
      }
      // Keep a contiguous prefix. A dropped method is never presented as complete coverage.
      lastDropped.set(method, sequence);
    });
  }
  page.once('close', () => {
    retained.length = 0;
    lastDropped.clear();
  });

  return {
    send(method, params = {}, options = {}) {
      requireValue(!page.isClosed() && options.target === undefined && CDP_COMMANDS.includes(method),
        'HEADLESS_CDP_COMMAND_UNSUPPORTED');
      return session.send(method, params);
    },
    readEvents({ methods, limit, afterSequence = sequence, target }) {
      requireValue(!page.isClosed() && target === undefined
        && Array.isArray(methods) && methods.length > 0
        && methods.every(method => NETWORK_EVENTS.includes(method))
        && Number.isSafeInteger(limit) && limit > 0 && limit <= MAX_PAGE_SIZE
        && Number.isSafeInteger(afterSequence) && afterSequence >= 0 && afterSequence <= sequence,
      'HEADLESS_CDP_EVENT_PAGE_INVALID');

      const events = [];
      const selected = new Set(methods);
      let hasMore = false;
      for (let index = afterSequence; index < retained.length; index++) {
        const event = retained[index];
        if (!selected.has(event.method)) continue;
        if (events.length === limit) {
          hasMore = true;
          break;
        }
        events.push(event);
      }
      return {
        events: structuredClone(events),
        cursor: hasMore ? events.at(-1).sequence : sequence,
        hasMore,
        truncated: methods.some(method => (lastDropped.get(method) ?? 0) > afterSequence),
      };
    },
  };
}

/** Use the installed Playwright Chromium supplied by the caller, with a fresh ephemeral context. */
export async function launchHeadlessBrowser(chromium, { executablePath } = {}) {
  const browser = await chromium.launch({ headless: true, executablePath });
  let context;
  try {
    context = await browser.newContext({ viewport: DEFAULT_VIEWPORT });
  } catch (error) {
    await browser.close().catch(() => {});
    throw error;
  }

  const byPage = new Map();
  const targetIds = new Set();
  let nextTabId = 0;
  let viewportSize = DEFAULT_VIEWPORT;

  async function registerTab(page) {
    const session = await context.newCDPSession(page);
    const { targetInfo } = await session.send('Target.getTargetInfo');
    const targetId = targetInfo?.targetId;
    requireValue(targetInfo?.type === 'page' && typeof targetId === 'string' && targetId.length > 0
      && !targetIds.has(targetId), 'HEADLESS_NATIVE_TAB_IDENTITY_UNPROVED');
    targetIds.add(targetId);
    // The B provider contract uses a numeric tab handle, while CDP uses an opaque target ID.
    // Allocate the handle only after binding it to this real page's unique CDP target.
    const tabId = ++nextTabId;
    const cdp = captureNetwork(page, session, tabId);
    await page.setViewportSize(viewportSize);

    return {
      id: String(tabId),
      goto: (url, options) => page.goto(url, options),
      url: () => page.url(),
      close: () => page.close(),
      playwright: {
        evaluate: (fn, arg) => page.evaluate(fn, arg),
        waitForLoadState: ({ state, ...options }) => page.waitForLoadState(state, options),
        getByLabel: (text, options) => page.getByLabel(text, options),
        getByPlaceholder(text, options) {
          const native = page.getByPlaceholder(text, options);
          if (options?.exact !== true || !['输入账号', '填写登录密码'].includes(text)) return native;
          const uniInput = page.locator('uni-input > .uni-input-wrapper > .uni-input-placeholder')
            .and(page.getByText(text, { exact: true }))
            .locator('..').locator(':scope > input.uni-input-input:visible:not([placeholder])');
          return native.or(uniInput);
        },
        getByRole: (role, options) => page.getByRole(role, options),
        getByText: (text, options) => page.getByText(text, options),
        locator: (selector, options) => page.locator(selector, options),
      },
      capabilities: { get: name => name === 'cdp' ? cdp : undefined },
    };
  }

  function tabFor(page) {
    if (!byPage.has(page)) {
      byPage.set(page, registerTab(page));
      page.once('close', () => byPage.delete(page));
    }
    return byPage.get(page);
  }

  const liveTabs = () => Promise.all(context.pages().map(tabFor));
  async function setViewport({ width, height }) {
    requireValue(Number.isSafeInteger(width) && width > 0
      && Number.isSafeInteger(height) && height > 0, 'HEADLESS_VIEWPORT_INVALID');
    viewportSize = { width, height };
    await Promise.all(context.pages().map(page => page.setViewportSize(viewportSize)));
  }
  const viewport = { set: setViewport, reset: () => setViewport(DEFAULT_VIEWPORT) };

  return {
    browserId: randomUUID(),
    tabs: {
      list: async () => (await liveTabs()).map(({ id }) => ({ id })),
      get: async id => {
        const tab = (await liveTabs()).find(value => value.id === id);
        requireValue(tab !== undefined, 'HEADLESS_TAB_NOT_LIVE');
        return tab;
      },
      new: async () => tabFor(await context.newPage()),
    },
    capabilities: { get: name => name === 'viewport' ? viewport : undefined },
    close: () => browser.close(),
  };
}
