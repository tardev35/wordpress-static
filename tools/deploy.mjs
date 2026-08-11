import { spawnSync } from "node:child_process";
import { existsSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { C, readJson } from "./lib.mjs";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");

const argv = process.argv.slice(2);
const slug = argv.find((a) => !a.startsWith("-"));
const dry = argv.includes("--dry");
const skipVerify = argv.includes("--skip-verify");

if (!slug) {
  console.log(`
  node tools/deploy.mjs <slug> [--dry] [--skip-verify]

  เขียน wrangler.toml จาก sites/<slug>/site.json แล้ว deploy ขึ้น Cloudflare Workers
  --dry          เขียน wrangler.toml อย่างเดียว ไม่ deploy
  --skip-verify  ข้ามการตรวจ (ใช้เมื่อรู้อยู่แล้วว่ากำลังทำอะไร)
`);
  process.exit(0);
}

const siteDir = join(ROOT, "sites", slug);
const metaPath = join(siteDir, "site.json");

if (!existsSync(metaPath)) {
  console.error(`\n${C.red("หยุด")} ไม่พบ ${metaPath} — แตก zip ด้วย tools/unpack.mjs ก่อน\n`);
  process.exit(1);
}

if (!existsSync(join(siteDir, "dist", "index.html"))) {
  console.error(`\n${C.red("หยุด")} ไม่มี sites/${slug}/dist/index.html\n`);
  process.exit(1);
}

const site = readJson(metaPath);

const missing = [];
if (!site.account_id) missing.push("account_id");
if (!site.worker_name) missing.push("worker_name");

if (missing.length) {
  console.error(`
${C.red("หยุด")} sites/${slug}/site.json ยังขาด: ${missing.join(", ")}

  account_id หาได้จากหน้า Workers & Pages ของ Cloudflare (มุมขวาล่าง หรือใน URL ของ dashboard)
  แล้วเติมลงไฟล์นั้นก่อนสั่ง deploy อีกครั้ง
`);
  process.exit(1);
}

if (!skipVerify) {
  console.log(C.dim("\nตรวจก่อน deploy…"));
  const v = spawnSync(process.execPath, [join(ROOT, "tools", "verify-export.mjs"), slug], {
    stdio: "inherit",
  });
  if (v.status !== 0) {
    console.error(`\n${C.red("หยุด")} verify ไม่ผ่าน — แก้ให้เขียวก่อนถึงจะ deploy ได้\n`);
    process.exit(1);
  }
}

const toml = `name = "${site.worker_name}"
account_id = "${site.account_id}"
compatibility_date = "${site.compatibility_date || new Date().toISOString().slice(0, 10)}"

workers_dev = false

[assets]
directory = "dist"
not_found_handling = "404-page"
`;

const tomlPath = join(siteDir, "wrangler.toml");
writeFileSync(tomlPath, toml, "utf8");
console.log(`\n${C.green("เขียนแล้ว")} sites/${slug}/wrangler.toml`);

if (dry) {
  console.log(C.dim("  --dry ตั้งไว้ ไม่ deploy ต่อ\n"));
  process.exit(0);
}

console.log(C.bold(`\ndeploy ${site.worker_name} …\n`));

const local = join(ROOT, "node_modules", ".bin", "wrangler");

if (!existsSync(local) && !existsSync(local + ".cmd")) {
  console.log(C.dim("  ไม่มี wrangler ในเครื่อง จะให้ npx ไปดึงมาให้ — ครั้งแรกจะช้าหน่อย"));
  console.log(C.dim("  ติดตั้งไว้เลยก็ได้ด้วย  npm install  จะได้ไม่ต้องดึงใหม่ทุกครั้ง\n"));
}

// shell: true จำเป็นบน Windows — ตั้งแต่ Node 18.20/20.12 เป็นต้นมา การ spawn
// ไฟล์ .cmd/.bat ตรงๆ จะตายด้วย EINVAL แบบเงียบๆ ไม่มีข้อความอะไรออกมาเลย
const run = spawnSync("npx", ["wrangler", "deploy"], {
  cwd: siteDir,
  stdio: "inherit",
  shell: true,
});

if (run.error) {
  console.error(`\n${C.red("เรียก wrangler ไม่ได้")} — ${run.error.message}\n`);
  process.exit(1);
}

if (run.status !== 0) {
  console.error(`\n${C.red("deploy ไม่สำเร็จ")} — อ่านข้อความของ wrangler ด้านบน\n`);
  process.exit(run.status || 1);
}

console.log(`
${C.green("deploy เสร็จแล้ว")}

ครั้งแรกของเว็บนี้ยังต้องทำอีก 2 อย่างบนหน้า Cloudflare (ทำครั้งเดียวจบ)

  1. Workers & Pages → เลือก Worker "${site.worker_name}" → แท็บ Domains → ${C.bold("+ Add Domain")}
     ใส่โดเมนเปล่าๆ ไม่ต้องมี www ไม่ต้องมี /*
     ${C.yellow("ห้ามใช้ + Add Route")} — มันไม่สร้าง DNS ให้ และไม่ครอบโดเมนหลัก

  2. ทำให้ www เด้งมาโดเมนหลัก (DNS record หลอก + Redirect Rule)

รายละเอียดทั้งสองข้ออยู่ใน DEPLOY-CLOUDFLARE.md ของโปรเจกต์ Astro ขั้นที่ 4–5
`);
