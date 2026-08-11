import { spawnSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, rmSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { C } from "./lib.mjs";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");

const SLUG = "wp-static-export";
const MAIN = `${SLUG}/${SLUG}.php`;

const pluginRoot = join(ROOT, "plugin");
const src = join(pluginRoot, SLUG);
const outDir = join(ROOT, "build");
const out = join(outDir, `${SLUG}.zip`);

if (!existsSync(src)) {
  console.error(`\n${C.red("หยุด")} ไม่พบ ${src}\n`);
  process.exit(1);
}

mkdirSync(outDir, { recursive: true });
rmSync(out, { force: true });

/**
 * ใช้ bsdtar ที่ Windows 10/11 มีมาให้ ไม่ใช้ Compress-Archive เด็ดขาด —
 * PowerShell 5.1 เขียนชื่อไฟล์ในzip ด้วย backslash ซึ่งผิดมาตรฐาน ZIP
 * WordPress แตกออกมาแล้วไม่เห็นโฟลเดอร์ เลยฟ้อง "ไม่พบปลั๊กอินที่ใช้งานได้"
 */
const tar = spawnSync("tar", ["-a", "-cf", out, "-C", pluginRoot, SLUG], { stdio: "inherit" });

if (tar.status !== 0 || !existsSync(out)) {
  console.error(`
${C.red("หยุด")} สร้าง zip ไม่สำเร็จ (ไม่มี tar ในเครื่อง?)

  ทำเองได้ด้วยการคลิกขวาที่โฟลเดอร์ plugin\\${SLUG} → 7-Zip → Add to archive → zip
  ${C.yellow("อย่าใช้")} PowerShell Compress-Archive — มันสร้าง zip ที่ WordPress ติดตั้งไม่ได้
`);
  process.exit(1);
}

/**
 * อ่านชื่อไฟล์ใน zip จาก local file header ตรงๆ ไม่ต้องพึ่ง library
 *
 * @param {Buffer} buf
 * @returns {string[]}
 */
function entries(buf) {
  const names = [];

  for (let i = 0; i + 30 <= buf.length; i++) {
    if (buf.readUInt32LE(i) !== 0x04034b50) continue;

    const nameLen = buf.readUInt16LE(i + 26);
    if (nameLen === 0 || i + 30 + nameLen > buf.length) continue;

    names.push(buf.subarray(i + 30, i + 30 + nameLen).toString("utf8"));
  }

  return names;
}

const names = entries(readFileSync(out));
const backslashed = names.filter((n) => n.includes("\\"));

if (backslashed.length) {
  console.error(`
${C.red("หยุด")} zip ที่ได้ใช้ backslash คั่น path — WordPress ติดตั้งไม่ได้

  ตัวอย่าง: ${backslashed[0]}
`);
  process.exit(1);
}

if (!names.includes(MAIN)) {
  console.error(`
${C.red("หยุด")} ใน zip ไม่มี ${MAIN} ที่ตำแหน่งที่ WordPress หา

  ที่เจอ: ${names.slice(0, 5).join(", ")}
`);
  process.exit(1);
}

console.log(`
${C.green("พร้อมติดตั้ง")} build/${SLUG}.zip  ${C.dim(`(${names.length} ไฟล์ ตรวจโครงแล้ว)`)}

  ที่เว็บลูกค้า: ปลั๊กอิน → เพิ่มปลั๊กอินใหม่ → อัปโหลดปลั๊กอิน → เลือกไฟล์นี้ → เปิดใช้งาน
  แล้วไปที่ เครื่องมือ → Static Export
`);
