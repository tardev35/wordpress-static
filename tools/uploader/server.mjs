import { spawn, spawnSync } from "node:child_process";
import { createWriteStream, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from "node:fs";
import { createServer } from "node:http";
import { basename, dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { readJson } from "../lib.mjs";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const HERE = dirname(fileURLToPath(import.meta.url));
const PORT = 4455;

const PREVIEW_PORT = 4456;

const PREFS = join(ROOT, "uploader.json");
const UPLOADS = join(ROOT, ".uploads");

const MIME = {
  html: "text/html; charset=utf-8",
  htm: "text/html; charset=utf-8",
  css: "text/css; charset=utf-8",
  js: "text/javascript; charset=utf-8",
  mjs: "text/javascript; charset=utf-8",
  json: "application/json; charset=utf-8",
  xml: "application/xml; charset=utf-8",
  xsl: "application/xml; charset=utf-8",
  txt: "text/plain; charset=utf-8",
  svg: "image/svg+xml",
  png: "image/png",
  jpg: "image/jpeg",
  jpeg: "image/jpeg",
  gif: "image/gif",
  webp: "image/webp",
  avif: "image/avif",
  ico: "image/x-icon",
  woff: "font/woff",
  woff2: "font/woff2",
  ttf: "font/ttf",
  otf: "font/otf",
  mp4: "video/mp4",
  webm: "video/webm",
  pdf: "application/pdf",
};

const ANSI = /\x1b\[[0-9;]*m/g;

/**
 * รับไฟล์เป็น body ดิบแล้วเทลงดิสก์ตรงๆ ไม่ผ่าน multipart และไม่อมไว้ใน memory
 * — export ของเว็บใหญ่ๆ เป็นร้อย MB ได้ และนี่คือ localhost จึงไม่ต้องแคร์แบนด์วิดท์
 *
 * @returns {Promise<{path: string, bytes: number}>}
 */
/**
 * ล้างไฟล์ zip ที่พักไว้ คืนข้อความสรุปไว้ต่อท้าย log
 *
 * @returns {string}
 */
function clearUploads() {
  if (!existsSync(UPLOADS)) {
    return "";
  }

  let bytes = 0;
  let count = 0;

  for (const name of readdirSync(UPLOADS)) {
    try {
      bytes += statSync(join(UPLOADS, name)).size;
      count++;
    } catch {
      /* อ่านขนาดไม่ได้ก็ยังลบได้ */
    }
  }

  rmSync(UPLOADS, { recursive: true, force: true });

  return count ? `ลบ zip ที่พักไว้แล้ว ${count} ไฟล์ (คืนพื้นที่ ${(bytes / 1024 / 1024).toFixed(1)} MB)` : "";
}

/**
 * ขนาดรวมของโฟลเดอร์ ใช้บอกว่าคืนพื้นที่ไปเท่าไหร่
 *
 * @param {string} dir
 * @returns {number}
 */
function dirSize(dir) {
  let total = 0;

  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, entry.name);
    try {
      total += entry.isDirectory() ? dirSize(p) : statSync(p).size;
    } catch {
      /* ไฟล์หายระหว่างนับก็ข้ามไป */
    }
  }

  return total;
}

/**
 * ขึ้นเว็บไปแล้วโฟลเดอร์ในเครื่องไม่ต้องเก็บ — เว็บที่รูปเยอะกินหลายสิบ MB ต่อเว็บ
 * และโฟลเดอร์ที่ค้างอยู่ใน sites/ ทำให้ทีมหลงว่ายังมีงานต้องทำกับเว็บนั้น
 *
 * ค่าตั้ง (โดเมนปลายทาง ชื่อ Worker account_id) ย้ายไปเก็บใน uploader.json แทน
 * รอบหน้าที่อัป zip ของ slug เดิม unpack จะดึงกลับมาเติมให้เอง — account_id
 * เป็นช่องที่กรอกผิดง่ายที่สุด ไม่ควรให้ใครต้องกรอกซ้ำทุกรอบ
 *
 * @param {string} slug
 * @returns {string}
 */
function clearSite(slug) {
  const dir = join(ROOT, "sites", slug);

  if (!existsSync(dir)) {
    return "";
  }

  const mb = (dirSize(dir) / 1024 / 1024).toFixed(1);
  const metaPath = join(dir, "site.json");

  if (existsSync(metaPath)) {
    try {
      const meta = readJson(metaPath);
      remember(slug, {
        origin: meta.origin || "",
        target_domain: meta.target_domain || "",
        worker_name: meta.worker_name || "",
        account_id: meta.account_id || "",
        compatibility_date: meta.compatibility_date || "",
        deployed_at: nowStamp(),
      });
    } catch {
      /* จำค่าไม่ได้ก็ยังลบได้ แค่รอบหน้าต้องกรอกเอง */
    }
  }

  try {
    rmSync(dir, { recursive: true, force: true });
  } catch {
    return `ลบโฟลเดอร์ sites/${slug} ไม่สำเร็จ — มีโปรแกรมเปิดไฟล์ในนั้นค้างอยู่ ปิดแล้วลบเองได้`;
  }

  if (previewSlug === slug) {
    previewSlug = "";
  }

  return `ลบเว็บออกจากเครื่องแล้ว (คืนพื้นที่ ${mb} MB) — จำค่าตั้งไว้ให้รอบหน้าแล้ว ไม่ต้องกรอกใหม่`;
}

/**
 * @param {string} slug
 * @param {object} entry
 */
function remember(slug, entry) {
  savePrefs({ remember: { ...(prefs().remember || {}), [slug]: entry } });
}

/**
 * @param {string} slug
 */
function forget(slug) {
  const all = { ...(prefs().remember || {}) };

  delete all[slug];
  savePrefs({ remember: all });
}

function nowStamp() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, "0");

  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

function receiveUpload(req, name) {
  return new Promise((done, fail) => {
    rmSync(UPLOADS, { recursive: true, force: true });
    mkdirSync(UPLOADS, { recursive: true });

    const dest = join(UPLOADS, name);
    const out = createWriteStream(dest);
    let bytes = 0;

    req.on("data", (c) => {
      bytes += c.length;
    });
    req.on("error", fail);
    out.on("error", fail);
    out.on("finish", () => done({ path: dest, bytes }));

    req.pipe(out);
  });
}

function listSites() {
  const dir = join(ROOT, "sites");
  if (!existsSync(dir)) return [];

  const out = [];
  for (const slug of readdirSync(dir)) {
    if (slug.startsWith("_")) continue;

    const metaPath = join(dir, slug, "site.json");
    if (!existsSync(metaPath)) continue;

    let meta = {};
    try {
      meta = readJson(metaPath);
    } catch {
      continue;
    }

    const distDir = join(dir, slug, "dist");
    out.push({
      slug,
      ...meta,
      hasDist: existsSync(join(distDir, "index.html")),
      deployedAt: meta.deployed_at || "",
      report: readReport(join(dir, slug, "export-report.json")),
    });
  }

  return out;
}

function readReport(path) {
  if (!existsSync(path)) return null;

  try {
    const r = readJson(path);

    return {
      pages: r?.counts?.pages ?? 0,
      assets: r?.counts?.assets ?? 0,
      errors: r?.counts?.errors ?? 0,
      files: r?.counts?.files ?? 0,
      mb: ((r?.counts?.bytes ?? 0) / 1024 / 1024).toFixed(2),
      failedCodes: countCodes(r?.failed_urls || []),
      failedUrls: (r?.failed_urls || []).slice(0, 10).map((f) => `${f.http_code} ${f.url}`),
      redirects: (r?.redirects || []).length,
      plugins: (r?.active_plugins || []).map((p) => p.name),
      dynamic: r?.dynamic_bits || {},
    };
  } catch {
    return null;
  }
}

function countCodes(rows) {
  const map = {};
  for (const r of rows) {
    const k = String(r.http_code || 0);
    map[k] = (map[k] || 0) + 1;
  }

  return map;
}

function prefs() {
  if (!existsSync(PREFS)) return {};
  try {
    return readJson(PREFS);
  } catch {
    return {};
  }
}

function savePrefs(next) {
  writeFileSync(PREFS, JSON.stringify({ ...prefs(), ...next }, null, 2) + "\n", "utf8");
}

function json(res, code, body) {
  const payload = JSON.stringify(body);
  res.writeHead(code, {
    "content-type": "application/json; charset=utf-8",
    "content-length": Buffer.byteLength(payload),
    "cache-control": "no-store",
  });
  res.end(payload);
}

function readBody(req) {
  return new Promise((done, fail) => {
    let raw = "";
    req.on("data", (c) => {
      raw += c;
      if (raw.length > 1_000_000) req.destroy();
    });
    req.on("end", () => {
      try {
        done(raw ? JSON.parse(raw) : {});
      } catch (e) {
        fail(e);
      }
    });
    req.on("error", fail);
  });
}

/**
 * ของที่เครื่องนี้ต้องมีก่อนถึงจะ deploy ได้ — หน้า UI เองไม่ต้องใช้ dependency
 * เลยเปิดดูได้ตั้งแต่ยังไม่ได้ npm install
 *
 * @returns {{node: string, nodeOk: boolean, wrangler: boolean}}
 */
function envStatus() {
  const bin = join(ROOT, "node_modules", ".bin", "wrangler");

  return {
    node: process.versions.node,
    nodeOk: Number(process.versions.node.split(".")[0]) >= 20,
    wrangler: existsSync(bin) || existsSync(bin + ".cmd"),
  };
}

/**
 * ใครล็อกอิน Cloudflare อยู่ และเข้าถึงบัญชีไหนได้บ้าง
 *
 * @returns {{loggedIn: boolean, email: string, accounts: Array<{name: string, id: string}>}}
 */
function cloudflareStatus() {
  // shell: true จำเป็นบน Windows — spawn ไฟล์ .cmd ตรงๆ ตายด้วย EINVAL
  const out = spawnSync("npx", ["wrangler", "whoami"], {
    cwd: ROOT,
    shell: true,
    encoding: "utf8",
    timeout: 60000,
  });

  const text = `${out.stdout || ""}${out.stderr || ""}`;

  if (/not authenticated|You are not logged in/i.test(text)) {
    return { loggedIn: false, email: "", accounts: [] };
  }

  const email = (text.match(/associated with the email\s+([^\s]+?)\.?\s/i) || [])[1] || "";
  const accounts = [];

  for (const m of text.matchAll(/│\s*(.+?)\s*│\s*([0-9a-f]{32})\s*│/g)) {
    accounts.push({ name: m[1].trim(), id: m[2] });
  }

  return { loggedIn: Boolean(email) || accounts.length > 0, email, accounts };
}

/**
 * รันคำสั่งแล้วส่ง stdout/stderr กลับไปทีละบรรทัดผ่าน SSE
 */
function stream(res, cmd, args, opts = {}) {
  res.writeHead(200, {
    "content-type": "text/event-stream; charset=utf-8",
    "cache-control": "no-cache",
    connection: "keep-alive",
  });

  const send = (type, data) => res.write(`event: ${type}\ndata: ${JSON.stringify(data)}\n\n`);

  const child = spawn(cmd, args, { cwd: opts.cwd || ROOT, shell: Boolean(opts.shell) });

  const pump = (buf) => {
    for (const line of String(buf).split(/\r?\n/)) {
      send("line", line.replace(ANSI, ""));
    }
  };

  child.stdout.on("data", pump);
  child.stderr.on("data", pump);

  child.on("close", (code) => {
    if (typeof opts.onDone === "function") {
      try {
        const note = opts.onDone(code);
        if (note) send("line", note);
      } catch {
        /* งานเก็บกวาดพังไม่ควรทำให้ผลของขั้นตอนหลักเปลี่ยน */
      }
    }

    send("done", { code });
    res.end();
  });

  res.on("close", () => child.kill());
}

/**
 * เสิร์ฟ dist ของเว็บที่เลือกไว้แบบเดียวกับที่ Workers Static Assets ทำ
 * (โฟลเดอร์ → index.html, ไม่เจอ → 404.html พร้อมรหัส 404) เพื่อให้ดูของจริงได้
 * ก่อนสลับโดเมน — workers.dev ถูกปิดไว้ตั้งใจ เลยไม่มีทางอื่นให้ดู
 */
let previewSlug = "";

const previewServer = createServer((req, res) => {
  if (!previewSlug) {
    res.writeHead(503, { "content-type": "text/html; charset=utf-8" });
    res.end("<h1>ยังไม่ได้เลือกเว็บ</h1><p>กดปุ่ม “ดูตัวอย่าง” ในหน้าควบคุมก่อน</p>");

    return;
  }

  const dist = join(ROOT, "sites", previewSlug, "dist");
  let path = decodeURIComponent(new URL(req.url, "http://x").pathname);

  if (path.includes("..")) {
    res.writeHead(400);
    res.end("bad path");

    return;
  }

  let file = join(dist, path);

  if (path.endsWith("/") || !/\.[a-z0-9]{1,6}$/i.test(path)) {
    file = join(file, "index.html");
  }

  let code = 200;

  if (!existsSync(file)) {
    file = join(dist, "404.html");
    code = 404;

    if (!existsSync(file)) {
      res.writeHead(404, { "content-type": "text/html; charset=utf-8" });
      res.end("<h1>404</h1>");

      return;
    }
  }

  const ext = String(file.split(".").pop()).toLowerCase();
  const body = readFileSync(file);

  res.writeHead(code, { "content-type": MIME[ext] || "application/octet-stream", "content-length": body.length });
  res.end(body);
});

const server = createServer(async (req, res) => {
  const url = new URL(req.url, `http://127.0.0.1:${PORT}`);

  if (url.pathname === "/") {
    const html = readFileSync(join(HERE, "index.html"));
    res.writeHead(200, { "content-type": "text/html; charset=utf-8", "cache-control": "no-store" });
    res.end(html);

    return;
  }

  if (url.pathname === "/api/state") {
    json(res, 200, { sites: listSites(), prefs: prefs(), env: envStatus() });

    return;
  }

  if (url.pathname === "/api/cf") {
    json(res, 200, cloudflareStatus());

    return;
  }

  if (url.pathname === "/api/site/delete" && req.method === "POST") {
    const slug = String(url.searchParams.get("slug") || "");
    const dir = join(ROOT, "sites", slug);

    if (!slug || slug.startsWith("_") || slug.includes("..") || slug.includes("/") || slug.includes("\\")) {
      json(res, 400, { error: "ลบเว็บนี้ไม่ได้" });

      return;
    }

    if (!existsSync(dir)) {
      json(res, 404, { error: "ไม่พบเว็บ " + slug });

      return;
    }

    const mb = (dirSize(dir) / 1024 / 1024).toFixed(1);

    if (previewSlug === slug) {
      previewSlug = "";
    }

    rmSync(dir, { recursive: true, force: true });
    forget(slug);
    json(res, 200, { ok: true, mb });

    return;
  }

  if (url.pathname === "/api/preview") {
    const slug = String(url.searchParams.get("slug") || "");

    if (!existsSync(join(ROOT, "sites", slug, "dist", "index.html"))) {
      json(res, 404, { error: "ยังไม่มีไฟล์เว็บของ " + slug });

      return;
    }

    previewSlug = slug;
    json(res, 200, { url: `http://localhost:${PREVIEW_PORT}/` });

    return;
  }

  if (url.pathname === "/api/upload" && req.method === "PUT") {
    const raw = basename(String(url.searchParams.get("name") || ""));
    const name = raw.replace(/[^\w.\-]+/g, "_");

    if (!/\.zip$/i.test(name)) {
      json(res, 400, { error: "รับเฉพาะไฟล์ .zip" });

      return;
    }

    try {
      const saved = await receiveUpload(req, name);
      json(res, 200, { path: saved.path, mb: (saved.bytes / 1024 / 1024).toFixed(2) });
    } catch (e) {
      json(res, 500, { error: "เขียนไฟล์ไม่สำเร็จ: " + String(e && e.message ? e.message : e) });
    }

    return;
  }

  if (url.pathname === "/api/site" && req.method === "POST") {
    let body;
    try {
      body = await readBody(req);
    } catch {
      json(res, 400, { error: "อ่านข้อมูลไม่ออก" });

      return;
    }

    const slug = String(body.slug || "").trim();
    if (!slug || slug.includes("..") || slug.includes("/") || slug.includes("\\")) {
      json(res, 400, { error: "slug ไม่ถูกต้อง" });

      return;
    }

    const metaPath = join(ROOT, "sites", slug, "site.json");
    if (!existsSync(metaPath)) {
      json(res, 404, { error: "ยังไม่มีเว็บนี้ — แตก ZIP ก่อน" });

      return;
    }

    const meta = readJson(metaPath);
    const next = {
      ...meta,
      target_domain: String(body.target_domain ?? meta.target_domain ?? "").trim(),
      worker_name: String(body.worker_name ?? meta.worker_name ?? slug).trim(),
      account_id: String(body.account_id ?? meta.account_id ?? "").trim(),
    };

    writeFileSync(metaPath, JSON.stringify(next, null, 2) + "\n", "utf8");

    if (next.account_id) {
      savePrefs({ account_id: next.account_id });
    }

    json(res, 200, { ok: true, site: next });

    return;
  }

  if (url.pathname === "/api/run") {
    const step = url.searchParams.get("step");
    const slug = url.searchParams.get("slug") || "";
    const zip = url.searchParams.get("zip") || "";

    const tool = (script, args, opts) => stream(res, process.execPath, [join(ROOT, "tools", script), ...args], opts);

    if (step === "unpack") {
      const wanted = String(url.searchParams.get("slug") || "").replace(/[^a-z0-9-]+/g, "");
      tool("unpack.mjs", zip ? [zip, ...(wanted ? [wanted] : [])] : []);

      return;
    }
    if (step === "verify") {
      tool("verify-export.mjs", [slug]);

      return;
    }
    if (step === "deploy") {
      // ขึ้นเว็บแล้ว zip ต้นทางไม่มีประโยชน์อีก — ปล่อยไว้กินดิสก์เปล่าๆ
      // เว็บที่รูปเยอะๆ ไฟล์เดียวหลายร้อย MB
      tool("deploy.mjs", [slug], {
        onDone: (code) => (code === 0 ? [clearUploads(), clearSite(slug)].filter(Boolean).join("\n") : ""),
      });

      return;
    }
    if (step === "login") {
      stream(res, "npx", ["wrangler", "login"], { shell: true });

      return;
    }
    if (step === "install") {
      stream(res, "npm", ["install", "--no-audit", "--no-fund"], { shell: true });

      return;
    }

    json(res, 400, { error: "ไม่รู้จักขั้นตอนนี้" });

    return;
  }

  res.writeHead(404, { "content-type": "text/plain; charset=utf-8" });
  res.end("ไม่มีหน้านี้");
});

/**
 * เก็บกวาดเว็บที่ deploy จบไปแล้วแต่โฟลเดอร์ยังค้างอยู่
 *
 * เครื่องมือรุ่นก่อนลบแค่ dist/ แล้วปล่อยโฟลเดอร์ทิ้งไว้ ของค้างพวกนั้นเลยยังอยู่ในเครื่อง
 * ที่อัปเดตมาจากรุ่นเก่า และยังทำงานอยู่ต่อไปด้วยถ้าวันไหน rmSync ลบไม่ผ่านเพราะไฟล์ถูกล็อก
 *
 * @returns {void}
 */
function sweepDeployed() {
  const dir = join(ROOT, "sites");

  if (!existsSync(dir)) {
    return;
  }

  for (const site of listSites()) {
    if (!site.deployedAt || site.hasDist) {
      continue;
    }

    const said = clearSite(site.slug);
    if (said) {
      console.log(`  เก็บกวาด ${site.slug} — ${said}`);
    }
  }
}

sweepDeployed();

server.listen(PORT, "127.0.0.1", () => {
  console.log(`
  WP Static Export — หน้าควบคุม

  เปิดที่  http://localhost:${PORT}
  ตัวอย่างเว็บ  http://localhost:${PREVIEW_PORT}

  ปิดด้วย Ctrl+C หรือปิดหน้าต่างนี้
`);

  // --open ใช้ตอนดับเบิลคลิกไฟล์ .cmd ซึ่งคนกดไม่ได้จ้องหน้าต่างดำอยู่
  if (process.argv.includes("--open")) {
    spawn("cmd", ["/c", "start", "", `http://localhost:${PORT}`], { detached: true, stdio: "ignore" }).unref();
  }
});

server.on("error", (e) => {
  if (e.code === "EADDRINUSE") {
    console.error(`\n  พอร์ต ${PORT} ถูกใช้อยู่แล้ว — น่าจะเปิดหน้าควบคุมค้างไว้อีกหน้าต่างหนึ่ง`);
    console.error(`  ปิดหน้าต่างนั้นก่อน หรือเปิด http://localhost:${PORT} ที่มีอยู่แล้วใช้ต่อได้เลย\n`);
    process.exit(1);
  }

  throw e;
});

previewServer.listen(PREVIEW_PORT, "127.0.0.1");
