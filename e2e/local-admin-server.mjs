/**
 * Тимчасовий стенд для перевірки прод-збірки admin-web проти живого бекенду:
 * статика з dist/, усе під /api — проксі на admin.<IP>.sslip.io.
 *
 * Запуск:  node local-admin-server.mjs
 * Тести:   ADMIN_HOST=http://localhost:4598 npx playwright test tests/1*.spec.ts
 */
import { createServer } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { readFile, stat } from 'node:fs/promises';
import { join, extname } from 'node:path';

const ROOT = '/Users/ruslanmamedov/Desktop/Claude/yms-rampa/web/dist/apps/admin-web/browser';
const UPSTREAM = 'admin.104.248.132.130.sslip.io';
const PORT = Number(process.env.PORT ?? 4598);

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.ico': 'image/x-icon',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
};

function proxy(req, res) {
  const chunks = [];
  req.on('data', (c) => chunks.push(c));
  req.on('end', () => {
    const body = Buffer.concat(chunks);
    const headers = { ...req.headers, host: UPSTREAM };
    delete headers['accept-encoding'];
    if (body.length) headers['content-length'] = String(body.length);
    const upstream = httpsRequest(
      { host: UPSTREAM, port: 443, path: req.url, method: req.method, headers, rejectUnauthorized: false },
      (up) => {
        res.writeHead(up.statusCode ?? 502, up.headers);
        up.pipe(res);
      },
    );
    upstream.on('error', (e) => {
      res.writeHead(502, { 'content-type': 'text/plain' });
      res.end('proxy error: ' + e.message);
    });
    if (body.length) upstream.write(body);
    upstream.end();
  });
}

createServer(async (req, res) => {
  const url = new URL(req.url ?? '/', 'http://localhost');
  if (url.pathname.startsWith('/api/')) return proxy(req, res);

  let file = join(ROOT, url.pathname);
  try {
    const s = await stat(file);
    if (s.isDirectory()) file = join(file, 'index.html');
  } catch {
    file = join(ROOT, 'index.html');
  }
  try {
    const data = await readFile(file);
    res.writeHead(200, { 'content-type': MIME[extname(file)] ?? 'application/octet-stream' });
    res.end(data);
  } catch (e) {
    res.writeHead(404, { 'content-type': 'text/plain' });
    res.end('not found: ' + e.message);
  }
}).listen(PORT, () => console.log(`admin-web dist on http://localhost:${PORT} → https://${UPSTREAM}`));
