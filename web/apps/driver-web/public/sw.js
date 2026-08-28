/**
 * Service worker driver-web (DRV-02, DRV-33).
 *
 * Стратегії:
 *  - оболонка застосунку (навігація, статика) — cache-first із мережевим оновленням;
 *  - GET /api/driver/v1/route-sheet — network-first із записом у кеш;
 *    без мережі повертається останній успішно завантажений маршрутний лист.
 *
 * ЗБЕРЕЖЕНА КОПІЯ ПІДПИСАНА. Кешована відповідь віддається зі службовими
 * заголовками
 *   x-yms-from-cache: 1     — це збережена копія, мережі не було;
 *   x-yms-cached-at: <ISO>  — коли її записали.
 * Без цього підпису застосунок не міг відрізнити офлайн-відповідь від свіжої:
 * запит «успішний», отже стан «онлайн», отже банер «Показано збережений
 * маршрут» не зʼявлявся, а внизу стояло «Оновлено» з поточним часом на
 * добових даних (ISSUE-10). Тихий кеш — це не офлайн-режим, це неправда.
 *
 * Архітектура навмисно залишає місце для web-push фази 2 (DRV-37):
 * обробники 'push' і 'notificationclick' додаються без переінсталяції SW.
 */
const VERSION = 'v2';
const SHELL_CACHE = `yms-driver-shell-${VERSION}`;
const DATA_CACHE = `yms-driver-data-${VERSION}`;
const SHELL_ASSETS = ['/', '/index.html', '/manifest.webmanifest', '/icon-192.png'];

/** Заголовки свіжості; назви дублюються в core/data/http-driver.api.ts. */
const FROM_CACHE_HEADER = 'x-yms-from-cache';
const CACHED_AT_HEADER = 'x-yms-cached-at';

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(SHELL_CACHE)
      .then((cache) => cache.addAll(SHELL_ASSETS))
      .catch(() => undefined)
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((key) => key !== SHELL_CACHE && key !== DATA_CACHE)
            .map((key) => caches.delete(key)),
        ),
      )
      .then(() => self.clients.claim()),
  );
});

function isRouteSheetRequest(url) {
  // Маршрут в однині: /api/driver/v1/route-sheet. Зайва «s» тут означала,
  // що запит не потрапляв у network-first і назавжди віддавався з кешу —
  // водій не бачив ні нових точок, ні скасованих, ні перенесених слотів.
  return url.pathname.startsWith('/api/driver/v1/route-sheet');
}

/** Решта викликів API кешуванню не підлягає — тільки мережа. */
function isApiRequest(url) {
  return url.pathname.startsWith('/api/');
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  if (isRouteSheetRequest(url)) {
    event.respondWith(networkFirst(request, DATA_CACHE));
    return;
  }

  // Будь-який інший виклик API — тільки мережа. Раніше вони провалювалися
  // у cacheFirst нижче разом зі статикою і застрягали в кеші назавжди.
  if (isApiRequest(url)) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() =>
        caches.match('/index.html').then((cached) => cached || Response.error()),
      ),
    );
    return;
  }

  event.respondWith(cacheFirst(request, SHELL_CACHE));
});

async function networkFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  try {
    const response = await fetch(request);
    if (response.ok) {
      // Штамп часу ставиться при ЗАПИСІ: пізніше, віддаючи копію, дізнатися
      // цей момент уже нізвідки — заголовок Date сервера каже про інше.
      await cache.put(request, await stamped(response.clone()));
    }
    return response;
  } catch (error) {
    const cached = await cache.match(request);
    if (cached) {
      return markFromCache(cached);
    }
    throw error;
  }
}

/** Копія відповіді з відміткою часу запису в кеш. */
async function stamped(response) {
  const headers = new Headers(response.headers);
  headers.set(CACHED_AT_HEADER, new Date().toISOString());

  return new Response(await response.blob(), {
    status: response.status,
    statusText: response.statusText,
    headers,
  });
}

/**
 * Та сама відповідь, але підписана як збережена копія. Заголовки об'єкта
 * з Cache Storage незмінні, тому потрібна нова Response.
 */
async function markFromCache(cached) {
  const headers = new Headers(cached.headers);
  headers.set(FROM_CACHE_HEADER, '1');

  return new Response(await cached.blob(), {
    status: cached.status,
    statusText: cached.statusText,
    headers,
  });
}

async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) {
    return cached;
  }
  const response = await fetch(request);
  if (response.ok) {
    cache.put(request, response.clone());
  }
  return response;
}

/** Очищення кешу за командою застосунку (вихід водія, DRV-09). */
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'YMS_CLEAR_CACHE') {
    event.waitUntil(
      caches.keys().then((keys) => Promise.all(keys.map((key) => caches.delete(key)))),
    );
  }
});
