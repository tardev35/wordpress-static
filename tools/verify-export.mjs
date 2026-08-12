import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { C, hostOf, readJson } from "./lib.mjs";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");

// เพดานของ Cloudflare Workers Static Assets แผนฟรี ชนเมื่อไหร่ deploy ไม่ผ่าน
const FILE_LIMIT = 20000;
const FILE_SIZE_LIMIT = 25 * 1024 * 1024;

const TEXT = /\.(html?|xml|css|js|mjs|json|txt|svg)$/i;
const PAGE = /\.html?$/i;

// ลิงก์หลังบ้าน WP ถูกฟ้องไปแล้วในข้อของมันเอง ไม่ต้องนับซ้ำเป็นลิงก์ตาย
// ส่วน /cdn-cgi/ เป็นของ Cloudflare เอง (email obfuscation ฯลฯ) ซึ่งถูกดักที่ edge
// ก่อนถึง Worker เสมอ จึงใช้งานได้จริงบนโดเมนปลายทาง ไม่ใช่ลิงก์ตาย
const WP_ONLY = /wp-admin|wp-login\.php|xmlrpc\.php|wp-json|wp-cron\.php|^\/cdn-cgi\//i;

const WP_BACKEND = ["/wp-admin/", "wp-login.php", "xmlrpc.php", "/wp-json/", "wp-cron.php"];

// URL ที่มี scheme ซ้อนกัน เช่น https:https://... แปลว่า rewriter ต่อ URL ผิด
// sitemap ที่มีบรรทัดแบบนี้ Google ทิ้งทั้งไฟล์ ไม่ใช่ข้ามทีละบรรทัด
const DOUBLE_SCHEME = /https?:(?:\\?\/\\?\/)?https?:\\?\/[^\s"'<>)\]]*/i;

const slug = process.argv.slice(2).find((a) => !a.startsWith("-"));

if (!slug) {
  console.log(`
  node tools/verify-export.mjs <slug>

  ตรวจ sites/<slug>/dist/ ก่อน deploy — อ่านอย่างเดียว ไม่แก้ไฟล์
  มี error แม้แต่ข้อเดียวคือห้าม deploy
`);
  process.exit(0);
}

const siteDir = join(ROOT, "sites", slug);
const dist = join(siteDir, "dist");

if (!existsSync(dist)) {
  console.error(`\n${C.red("หยุด")} ไม่พบ ${dist} — แตก zip ด้วย tools/unpack.mjs ก่อน\n`);
  process.exit(1);
}

let site = {};
if (existsSync(join(siteDir, "site.json"))) {
  site = readJson(join(siteDir, "site.json"));
}

const originHost = hostOf(site.origin);
const targetHost = hostOf(site.target_domain);

const errors = [];
const warnings = [];
const error = (where, msg) => errors.push({ where, msg });
const warn = (where, msg) => warnings.push({ where, msg });

function walk(dir, out = []) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, entry.name);
    if (entry.isDirectory()) walk(p, out);
    else out.push(p);
  }
  return out;
}

const files = walk(dist);
const rels = files.map((f) => f.slice(dist.length + 1).split("\\").join("/"));
const present = new Set(rels);

console.log(`\n${C.bold("verify-export")} — ${rels.length} ไฟล์ใน sites/${slug}/dist`);
console.log(C.dim(`  ต้นทาง ${originHost || "?"}  →  ปลายทาง ${targetHost || "?"}`));

// --- เพดานของ Workers ---

if (rels.length > FILE_LIMIT) {
  error("dist/", `${rels.length} ไฟล์ — เกินเพดาน ${FILE_LIMIT} ไฟล์ต่อ Worker`);
}

let total = 0;
for (const f of files) {
  const size = statSync(f).size;
  total += size;
  if (size > FILE_SIZE_LIMIT) {
    error(f.slice(dist.length + 1), `ไฟล์ใหญ่ ${(size / 1024 / 1024).toFixed(1)} MB — เกิน 25 MiB ต่อไฟล์`);
  }
}

// --- ไฟล์ที่ต้องมี ---

if (!present.has("index.html")) {
  error("dist/", "ไม่มี index.html ที่ราก — หน้าแรกหาย");
}
if (!present.has("404.html")) {
  error("dist/", 'ไม่มี 404.html — not_found_handling = "404-page" จะไม่มีอะไรให้เสิร์ฟ');
}

// --- เนื้อไฟล์ ---

const externalHosts = new Map();
const dead = new Map();
let pages = 0;
let redirects = 0;

/**
 * URL ที่ดึงไม่สำเร็จตอน export พร้อมรหัสที่เว็บต้นทางตอบกลับมา
 *
 * ไฟล์ที่หายไปจาก dist มีสองแบบที่ต้องแยกกันให้ขาด: ของที่พังอยู่ก่อนแล้วบนเว็บเดิม
 * (รูปถูกลบออกจากดิสก์แต่ยังถูกอ้างในเนื้อหา — เจอบ่อยมากกับเว็บที่ใช้งานมานาน) กับ
 * ของที่หายเพราะ export เอง อย่างแรกต่อให้ export ใหม่กี่รอบก็ไม่กลับมา ต้องไปแก้ที่
 * WordPress — บล็อก deploy ไว้ก็ไม่ได้ทำให้ใครแก้ได้เร็วขึ้น
 */
const originFailed = new Map();
const reportPath = join(siteDir, "export-report.json");

if (existsSync(reportPath)) {
  try {
    for (const f of readJson(reportPath).failed_urls || []) {
      const p = pathKey(new URL(f.url).pathname);
      if (p) originFailed.set(p, Number(f.http_code) || 0);
    }
  } catch {
    /* ไม่มีรายงานหรืออ่านไม่ออก ก็ยังตรวจต่อได้ แค่แยกสาเหตุไม่ได้ */
  }
}

/**
 * ทำ path ให้อยู่รูปเดียวกันทั้งฝั่ง URL ของรายงานและฝั่งไฟล์ใน dist
 *
 * @param {string} p
 * @returns {string}
 */
function pathKey(p) {
  let s = String(p).replace(/[?#].*$/, "");

  try {
    s = decodeURIComponent(s);
  } catch {
    /* URL ที่ encode มาเพี้ยน ใช้ของเดิมไปเทียบตรงๆ */
  }

  return s.replace(/^\/+/, "").replace(/\/index\.html$/i, "").replace(/\/$/, "");
}

for (const rel of rels) {
  if (!TEXT.test(rel)) continue;

  const body = readFileSync(join(dist, rel), "utf8");

  if (originHost && originHost !== targetHost) {
    const re = new RegExp(`https?://(?:www\\.)?${originHost.replace(/\./g, "\\.")}`, "i");
    if (re.test(body)) {
      error(rel, `ยังมีลิงก์ absolute กลับไปที่ ${originHost}`);
    }
  }

  const isRobots = rel === "robots.txt";

  // ที่ต้องห้าม deploy คือ "ลิงก์" ที่พาคนไปหาหลังบ้าน WordPress ไม่ใช่ทุกที่ที่มีคำนี้โผล่
  // ชื่อพวกนี้ยังอยู่ในที่ที่ไม่มีอะไรวิ่งไปหาอีกหลายแบบ: บรรทัด Disallow ของ robots.txt,
  // รายการยกเว้นใน speculationrules ของ WP 6.8 (ซึ่งแปลว่า "อย่า prefetch" — ตรงข้ามกับลิงก์)
  // และตัวแปร ajaxurl ของธีมที่บนเว็บ static ไม่มีสคริปต์ไหนเรียก
  const linked = new Set();
  for (const m of body.matchAll(/\s(?:href|src|action|poster)\s*=\s*["']([^"']+)["']/gi)) {
    const hit = WP_BACKEND.find((w) => m[1].includes(w));
    if (hit) linked.add(hit);
  }

  if (linked.size) {
    error(rel, `ลิงก์ในหน้าชี้ไป ${[...linked].join(" · ")} ซึ่งไม่มีอยู่บน static`);
  } else {
    const hit = WP_BACKEND.find((w) => body.includes(w));
    if (hit) {
      warn(
        rel,
        isRobots
          ? `ยังมีบรรทัด ${hit} ของ WordPress ติดมา — ไม่มีผลกับเว็บ (export รุ่นใหม่ตัดให้แล้ว)`
          : `มี ${hit} อยู่ในสคริปต์ แต่ไม่ใช่ลิงก์ — บน static ไม่มีอะไรเรียกถึง`,
      );
    }
  }

  const doubled = body.match(DOUBLE_SCHEME);
  if (doubled) {
    error(rel, `URL ต่อ scheme ซ้ำ: ${doubled[0].slice(0, 80)} — ต้อง export ใหม่ด้วย plugin รุ่นล่าสุด`);
  }

  if (isRobots) {
    for (const m of body.matchAll(/^[ \t]*sitemap[ \t]*:[ \t]*(\S+)/gim)) {
      if (DOUBLE_SCHEME.test(m[1])) continue;

      let file;
      try {
        const u = new URL(m[1]);
        if (targetHost && u.host !== targetHost) {
          error(rel, `Sitemap: ชี้ไปโดเมน ${u.host} ไม่ใช่ ${targetHost}`);
          continue;
        }
        file = decodeURIComponent(u.pathname).replace(/\/{2,}/g, "/").replace(/^\//, "");
      } catch {
        error(rel, `บรรทัด Sitemap: ไม่ใช่ URL ที่ใช้ได้ — ${m[1].slice(0, 80)}`);
        continue;
      }
      if (file && !present.has(file)) {
        error(rel, `Sitemap: ชี้ไป /${file} แต่ไม่มีไฟล์นั้นใน dist`);
      }
    }
  }

  if (!PAGE.test(rel)) continue;

  // หน้า meta-refresh คือ redirect ที่ถูกแปลงเป็นไฟล์ ไม่ใช่หน้าเนื้อหา
  // จะไม่มี canonical/description และลิงก์ในนั้นชี้ออกนอกเว็บโดยตั้งใจ
  if (/<meta[^>]+http-equiv=["']refresh["']/i.test(body)) {
    redirects++;
    continue;
  }

  pages++;

  const title = (body.match(/<title[^>]*>([\s\S]*?)<\/title>/i) || [])[1];
  if (!title || !title.trim()) {
    error(rel, "ไม่มี <title>");
  }

  const canonical = (body.match(/<link[^>]+rel=["']canonical["'][^>]+href=["']([^"']+)["']/i) || [])[1];
  if (rel !== "404.html") {
    if (!canonical) {
      warn(rel, "ไม่มี canonical");
    } else if (targetHost && hostOf(canonical) !== targetHost) {
      error(rel, `canonical ชี้ ${hostOf(canonical) || canonical} ไม่ใช่ ${targetHost}`);
    }
  }

  const desc = (body.match(/<meta[^>]+name=["']description["'][^>]+content=["']([^"']*)["']/i) || [])[1];
  if (!desc || !desc.trim()) {
    warn(rel, "ไม่มี meta description");
  }

  for (const m of body.matchAll(/\s(?:href|src)=["'](https?:\/\/[^"'\/]+)/gi)) {
    const h = hostOf(m[1]);
    if (h && h !== targetHost && h !== originHost) {
      externalHosts.set(h, (externalHosts.get(h) || 0) + 1);
    }
  }

  for (const link of internalLinks(body)) {
    if (WP_ONLY.test(link)) continue;

    const target = resolveLink(link, rel);
    if (target && !present.has(target)) {
      // ของที่หายชิ้นเดียว (โลโก้ใน header, รูปใน footer) ถูกอ้างจากทุกหน้าในเว็บ
      // ถ้าฟ้องทีละหน้า รายงานจะยาวเป็นหางว่าวโดยที่มีของต้องแก้อยู่ชิ้นเดียว
      if (!dead.has(target)) dead.set(target, { link, pages: new Set() });
      dead.get(target).pages.add(rel);
    }
  }
}

for (const [target, { link, pages }] of dead) {
  const first = [...pages][0];
  const where = pages.size === 1 ? first : `${first} (+อีก ${pages.size - 1} หน้า)`;
  const code = originFailed.get(pathKey(target));

  if (code === 404 || code === 410) {
    warn(where, `${link} ไม่มีอยู่ — เว็บต้นทางก็ตอบ ${code} อยู่แล้ว รูปนี้พังมาก่อน export ต้องไปแก้ที่ WordPress`);
  } else if (code) {
    error(where, `${link} ดึงมาไม่ได้ตอน export — เว็บต้นทางตอบ ${code} (403 มักแปลว่าไฟร์วอลล์บล็อกอยู่)`);
  } else {
    error(where, `ลิงก์ไปหาไฟล์ที่ไม่มีอยู่: ${link}`);
  }
}

/**
 * ลิงก์ที่ชี้ภายในเว็บเดียวกัน (root-relative หรือ relative) จาก href/src/srcset
 */
function internalLinks(html) {
  const out = new Set();

  for (const m of html.matchAll(/\s(?:href|src|poster)=["']([^"']+)["']/gi)) {
    out.add(m[1]);
  }
  for (const m of html.matchAll(/\ssrcset=["']([^"']+)["']/gi)) {
    for (const part of m[1].split(",")) {
      const u = part.trim().split(/\s+/)[0];
      if (u) out.add(u);
    }
  }

  return [...out].filter((u) => {
    if (!u || u.startsWith("#") || u.startsWith("data:")) return false;
    if (/^[a-z][a-z0-9+.-]*:/i.test(u)) return false;
    if (u.startsWith("//")) return false;
    // ตัวแปรของ template เช่น {$sitemapURL} ใน XSLT ของ sitemap ไม่ใช่ลิงก์จริง
    if (/[{}]/.test(u)) return false;
    return true;
  });
}

/**
 * แปลงลิงก์ในหน้าเป็น path ไฟล์ที่ควรมีอยู่จริงใน dist
 */
function resolveLink(link, fromRel) {
  let p = link.split("#")[0].split("?")[0];
  if (p === "") return null;

  try {
    p = decodeURIComponent(p);
  } catch {
    /* ปล่อยตามเดิมถ้า decode ไม่ผ่าน */
  }

  let full;
  if (p.startsWith("/")) {
    full = p.slice(1);
  } else {
    const base = fromRel.includes("/") ? fromRel.slice(0, fromRel.lastIndexOf("/")) : "";
    full = base ? `${base}/${p}` : p;
  }

  const parts = [];
  for (const seg of full.split("/")) {
    if (seg === "." || seg === "") continue;
    if (seg === "..") parts.pop();
    else parts.push(seg);
  }
  full = parts.join("/");

  if (full === "") return "index.html";
  if (link.endsWith("/") || !/\.[a-z0-9]{1,6}$/i.test(full)) {
    full += "/index.html";
  }

  return full;
}

// --- รายงาน ---

if (externalHosts.size) {
  const list = [...externalHosts.entries()].sort((a, b) => b[1] - a[1]).slice(0, 12);
  console.log(`\n${C.dim("โดเมนภายนอกที่หน้าเว็บยังเรียกอยู่")}`);
  for (const [h, n] of list) {
    console.log(`  ${String(n).padStart(4)}×  ${h}`);
  }
}

console.log(
  `\n${C.dim("สรุป")} ${pages} หน้า · ${redirects} หน้า redirect · ${rels.length} ไฟล์ · ${(total / 1024 / 1024).toFixed(1)} MB`,
);

if (warnings.length) {
  console.log(`\n── ${warnings.length} คำเตือน — ไม่บล็อก แต่ควรดู ──`);
  for (const w of warnings.slice(0, 30)) {
    console.log(`  ${C.yellow("!")} ${w.where}  ${w.msg}`);
  }
  if (warnings.length > 30) console.log(C.dim(`  … อีก ${warnings.length - 30} รายการ`));
}

if (errors.length) {
  console.log(`\n── ${C.red(`${errors.length} ERROR`)} — ห้าม deploy จนกว่าจะแก้ ──`);
  for (const e of errors.slice(0, 40)) {
    console.log(`  ${C.red("×")} ${e.where}  ${e.msg}`);
  }
  if (errors.length > 40) console.log(C.dim(`  … อีก ${errors.length - 40} รายการ`));
  console.log("");
  process.exit(1);
}

console.log(`\n${C.green("✓ ผ่านหมด")} (${warnings.length} คำเตือน) — deploy ได้\n`);
