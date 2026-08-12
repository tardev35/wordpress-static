import { spawnSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { basename, dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { C, readJson } from "./lib.mjs";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");

/**
 * เว็บที่ deploy จบแล้วจะถูกลบทั้งโฟลเดอร์ออกจาก sites/ ค่าตั้งของมันย้ายไปอยู่ใน
 * uploader.json ดึงกลับมาที่นี่ เพื่อให้รอบถัดไปของเว็บเดิมไม่ต้องกรอก account_id ใหม่
 *
 * @param {string} slug
 * @returns {object}
 */
function remembered(slug) {
  const path = join(ROOT, "uploader.json");

  if (!existsSync(path)) {
    return {};
  }

  try {
    return readJson(path)?.remember?.[slug] || {};
  } catch {
    return {};
  }
}

const USAGE = `
  node tools/unpack.mjs <ไฟล์.zip> [slug]

  แตก ZIP ที่ได้จาก plugin WP Static Export ลง sites/<slug>/
  ถ้าไม่ใส่ slug จะใช้ค่าจาก site.json ใน zip

  ผลลัพธ์
    sites/<slug>/dist/              ไฟล์เว็บทั้งหมด
    sites/<slug>/site.json          ค่าที่ใช้ตอน deploy (เติม account_id เองครั้งแรก)
    sites/<slug>/export-report.json รายงานจากฝั่ง WordPress
`;

const argv = process.argv.slice(2);
const zipArg = argv.find((a) => !a.startsWith("-"));
const slugArg = argv.filter((a) => !a.startsWith("-"))[1];

if (!zipArg) {
  console.log(USAGE);
  process.exit(0);
}

const zip = resolve(zipArg);
if (!existsSync(zip)) {
  console.error(`\n${C.red("หยุด")} ไม่พบไฟล์ ${zip}\n`);
  process.exit(1);
}

/**
 * แตก zip ด้วยเครื่องมือที่ Windows มีอยู่แล้ว ไม่ต้องลง dependency เพิ่ม
 */
function extract(file, dest) {
  mkdirSync(dest, { recursive: true });

  const tar = spawnSync("tar", ["-xf", file, "-C", dest], { stdio: "inherit" });
  if (tar.status === 0) return true;

  console.log(C.dim("  tar ใช้ไม่ได้ ลอง Expand-Archive แทน"));

  const ps = spawnSync(
    "powershell",
    ["-NoProfile", "-Command", `Expand-Archive -LiteralPath '${file}' -DestinationPath '${dest}' -Force`],
    { stdio: "inherit" },
  );

  return ps.status === 0;
}

const staging = join(ROOT, ".unpack-tmp");
rmSync(staging, { recursive: true, force: true });

console.log(`\n${C.bold("แตกไฟล์")} ${basename(zip)}`);

if (!extract(zip, staging)) {
  console.error(`\n${C.red("หยุด")} แตก zip ไม่สำเร็จ\n`);
  process.exit(1);
}

if (!existsSync(join(staging, "dist"))) {
  rmSync(staging, { recursive: true, force: true });
  console.error(`\n${C.red("หยุด")} ใน zip ไม่มีโฟลเดอร์ dist/ — ไฟล์นี้ไม่ได้มาจาก plugin ตัวนี้\n`);
  process.exit(1);
}

let meta = {};
const metaPath = join(staging, "site.json");
if (existsSync(metaPath)) {
  try {
    meta = readJson(metaPath);
  } catch {
    console.log(C.yellow("  อ่าน site.json ใน zip ไม่ออก ข้ามไป"));
  }
}

const slug = slugArg || meta.slug || basename(zip).replace(/\.zip$/i, "");
const siteDir = join(ROOT, "sites", slug);
const distDir = join(siteDir, "dist");

mkdirSync(siteDir, { recursive: true });

let existing = {};
let fromMemory = false;
const existingPath = join(siteDir, "site.json");
if (existsSync(existingPath)) {
  try {
    existing = readJson(existingPath);
  } catch {
    /* ไฟล์เสีย ถือว่าไม่มี */
  }
} else {
  existing = remembered(slug);
  fromMemory = Boolean(existing.account_id || existing.target_domain);
}

rmSync(distDir, { recursive: true, force: true });

const moved = spawnSync(
  "powershell",
  [
    "-NoProfile",
    "-Command",
    `Move-Item -LiteralPath '${join(staging, "dist")}' -Destination '${distDir}' -Force`,
  ],
  { stdio: "inherit" },
);

if (moved.status !== 0) {
  console.error(`\n${C.red("หยุด")} ย้าย dist/ เข้า ${distDir} ไม่สำเร็จ\n`);
  process.exit(1);
}

for (const name of ["export-report.json"]) {
  const from = join(staging, name);
  if (existsSync(from)) {
    writeFileSync(join(siteDir, name), readFileSync(from));
  }
}

const merged = {
  slug,
  origin: meta.origin || existing.origin || "",
  target_domain: meta.target_domain || existing.target_domain || "",
  worker_name: existing.worker_name || meta.worker_name || slug,
  account_id: existing.account_id || meta.account_id || "",
  compatibility_date: existing.compatibility_date || new Date().toISOString().slice(0, 10),
  exported_at: meta.exported_at || "",
};

writeFileSync(existingPath, JSON.stringify(merged, null, 2) + "\n", "utf8");

rmSync(staging, { recursive: true, force: true });

console.log(`
${C.green("แตกเสร็จแล้ว")}

  slug            ${C.bold(slug)}${fromMemory ? C.dim("  (เคยขึ้นเว็บแล้ว — ดึงค่าตั้งเดิมกลับมาให้)") : ""}
  ไฟล์เว็บ         sites/${slug}/dist/
  โดเมนต้นทาง      ${merged.origin || C.dim("(ไม่มีข้อมูล)")}
  โดเมนปลายทาง     ${merged.target_domain || C.dim("(ยังไม่ตั้ง)")}
  account_id      ${merged.account_id || C.yellow("ยังว่าง — เติมใน sites/" + slug + "/site.json ก่อน deploy")}

ขั้นต่อไป
  node tools/verify-export.mjs ${slug}
`);
