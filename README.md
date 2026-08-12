# WP Static Export → Cloudflare Workers

เอาเว็บ WordPress ที่รันอยู่บน hosting จริง แปลงเป็นไฟล์ static แล้วเอาขึ้น
Cloudflare Workers แบบเดียวกับที่เว็บ Astro ทำอยู่

ของมี 2 ชิ้น ทำงานคนละฝั่ง

| ชิ้น | อยู่ที่ | ทำอะไร |
| --- | --- | --- |
| plugin `wp-static-export` | ติดตั้งบน WP ของลูกค้า | ดึงทุกหน้า แก้ลิงก์ แพ็กเป็น ZIP |
| tool ใน `tools/` | เครื่องนี้ | แตก ZIP → ตรวจ → deploy ขึ้น Worker |

---

## ⓪ ติดตั้งบนเครื่องใหม่ (ทำครั้งเดียว)

### 1. Node.js

โหลดตัว **LTS** จาก <https://nodejs.org> (ต้อง **20 ขึ้นไป**) ตอนติดตั้งบน Windows
ปล่อยค่าเริ่มต้นไว้ทั้งหมด โดยเฉพาะช่อง **Add to PATH** ที่ติ๊กมาให้อยู่แล้ว ห้ามเอาออก

ปิดหน้าต่าง PowerShell ทุกบานแล้วเปิดใหม่ (PATH ที่เพิ่งเปลี่ยนไม่มีผลกับหน้าต่างที่เปิดค้างอยู่) แล้วเช็ก

```powershell
node -v      # ต้องขึ้น v20.x หรือใหม่กว่า
npm -v
tar --version   # Windows 10/11 มีมาให้อยู่แล้ว ใช้ตอนทำ zip ของ plugin
```

### 2. โหลดโปรเจกต์แล้วติดตั้ง dependency

```powershell
git clone git@gitlab.com:tardev-group/wordpress-static.git C:\wordpress\wp-project
cd C:\wordpress\wp-project
npm run ui
```

**ยังไม่ต้อง `npm install`** — หน้าควบคุมไม่มี dependency เลย เปิดได้ทันที
แล้วในขั้นที่ 2 ของหน้านั้นมีปุ่ม **"ติดตั้งเครื่องมือ (npm install)"** ให้กด
พร้อมบอกเวอร์ชัน Node และสถานะ wrangler ให้เห็นว่าเครื่องพร้อมหรือยัง

จะพิมพ์ `npm install` เองก็ได้ผลเหมือนกัน — มันลงแค่ `wrangler` ตัวเดียว (37 packages)

**ไม่ต้องลง PHP** ในเครื่องนี้ — PHP ใช้ที่เซิร์ฟเวอร์ของลูกค้าเท่านั้น

### 3. เชื่อม Cloudflare

```powershell
npx wrangler login
```

หรือกดปุ่มในขั้นที่ 2 ของหน้าควบคุมก็ได้ ล็อกอินครั้งเดียวใช้ได้ทุกเว็บ
token เก็บไว้ที่ `%APPDATA%\xdg.config\.wrangler`

### 4. เปิดหน้าควบคุม

**ดับเบิลคลิกไฟล์ `เปิดหน้าควบคุม.cmd`** ที่โฟลเดอร์โปรเจกต์ — เบราว์เซอร์เด้งขึ้นมาเอง
ปิดหน้าต่างดำเมื่อไหร่คือปิดโปรแกรม (ถ้าไม่มี Node.js มันจะบอกวิธีติดตั้งเป็นภาษาไทยให้)

สร้าง shortcut ไปวางไว้บนเดสก์ท็อปได้ (คลิกขวา → ส่งไปที่ → เดสก์ท็อป)

หรือจะสั่งเองก็ได้

```powershell
npm run ui
```

ใช้พอร์ต **4455** (หน้าควบคุม) กับ **4456** (ดูตัวอย่างเว็บ) ถ้าพอร์ตชนกับโปรแกรมอื่น
จะเปิดไม่ขึ้น — แก้ค่าที่หัวไฟล์ `tools/uploader/server.mjs`

---

### กับดักของ Windows ที่เจอมาแล้วจริง

อ่านข้อนี้ก่อนจะไปนั่งงงเอง ทุกข้อคือของที่เคยทำงานพังมาแล้ว

| อาการ | สาเหตุ | ทางแก้ |
| --- | --- | --- |
| `npm : ไม่สามารถโหลดไฟล์ ... เนื่องจากมีการปิดใช้งานการเรียกใช้สคริปต์` | PowerShell ปิด script ไว้ตามค่าโรงงาน | `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned` แล้วตอบ Y |
| พิมพ์ `cd x && npm run ui` แล้ว error | **PowerShell 5.1 ไม่มี `&&`** | ใช้ `;` คั่น หรือพิมพ์ทีละบรรทัด |
| ภาษาไทยใน terminal เป็นตัวยึกยือ | codepage ไม่ใช่ UTF-8 | `chcp 65001` ก่อนรันคำสั่ง |
| ติดตั้ง plugin ที่ WP แล้วขึ้น **"ไม่พบปลั๊กอินที่ใช้งานได้"** | บีบ zip ด้วย `Compress-Archive` ซึ่งเขียน path ด้วย `\` ผิดมาตรฐาน ZIP | ใช้ `npm run zip:plugin` เท่านั้น (มันใช้ `tar` และตรวจโครงให้) |
| deploy แล้วเงียบ ไม่มีข้อความอะไรเลย | Node 18.20+ บล็อกการ spawn ไฟล์ `.cmd` (EINVAL) | **แก้ในโค้ดแล้ว** ถ้ายังเจอ แปลว่าใช้โค้ดเก่า — `git pull` |
| `npx wrangler` ช้ามากทุกครั้ง | ไม่ได้รัน `npm install` npx เลยต้องโหลด wrangler ใหม่ทุกรอบ | `npm install` |

> ใช้ **PowerShell** หรือ **Command Prompt** ก็ได้ทั้งคู่ แต่ตัวอย่างในคู่มือนี้เขียนแบบ
> PowerShell ถ้าใช้ Git Bash ต้องระวังเรื่อง path แบบ `C:\...` ที่ต้องเขียนเป็น `/c/...`

---

## ⓪.5 ตั้งค่า Cloudflare ให้ export ผ่าน (ทำก่อน export ทุกเว็บ)

**ข้ามข้อนี้ได้ถ้าเว็บลูกค้าไม่ได้อยู่หลัง Cloudflare** แต่ถ้าอยู่ ต้องทำก่อน ไม่งั้นจะได้
เว็บที่ไม่มี CSS/JS เลยโดยไม่รู้ตัว

ตัว export ยิง request จากเซิร์ฟเวอร์ของ WordPress ออกไปหาเว็บตัวเอง ซึ่งวิ่งผ่าน
Cloudflare อีกที กฎความปลอดภัยที่บล็อก `/wp-content/` กับ `/wp-includes/` (พบบ่อยมาก)
จะตอบ 403 ใส่ทุกไฟล์ CSS/JS/รูป

ทุก request ของ export ส่ง header **`X-WPSE-Export: 1`** ไปด้วยเสมอ เอาไปทำข้อยกเว้น

1. Cloudflare → เลือกโดเมนของลูกค้า → **Security → WAF → Custom rules → Create rule**
2. ตั้งชื่อกฎว่า `wpse-export` แล้วใส่เงื่อนไข
   - Field = **Header**
   - Header name = `x-wpse-export`
   - Operator = **equals**
   - Value = `1`
3. Then take action = **Skip** แล้วติ๊ก **ทุกช่อง**
   (All remaining custom rules / All rate limiting rules / All managed rules /
   All Super Bot Fight Mode Rules และใน More components ติ๊กให้ครบ
   โดยเฉพาะ **Browser Integrity Check** กับ **Hotlink Protection**)
4. **Deploy** แล้วค่อยไปกด export ที่ WordPress
5. 🚨 **ลบกฎทิ้งทันทีที่ export เสร็จ** — ตราบใดที่กฎยังอยู่ ใครที่รู้ชื่อ header นี้
   ก็ยิงข้ามไฟร์วอลล์ของลูกค้าได้ทั้งหมด

เช็กว่ากฎทำงานไหมโดยไม่ต้อง export ทั้งเว็บ:

```powershell
curl.exe -s -o NUL -w "%{http_code}`n" -H "x-wpse-export: 1" "https://โดเมนลูกค้า/wp-includes/js/jquery/jquery.min.js"
```

ได้ `200` คือผ่าน ได้ `403` คือกฎยังไม่ทำงาน

**ถ้าลูกค้าใช้ LiteSpeed Cache ด้วย** ให้ปิด **Guest Mode**, **Guest Optimization**,
**CSS/JS Combine**, **UCSS/CCSS** แล้วกด Purge All ก่อน export — ไม่งั้นจะได้หน้าเวอร์ชัน
ที่ระบบเสิร์ฟให้บอทซึ่งไม่ใช่ของจริง เสร็จแล้วค่อยเปิดกลับ

---

## ทำไมได้ผลกับทุก plugin

ตัว export **ไม่ได้อ่านจากฐานข้อมูล** แต่ยิง HTTP เข้าไปที่หน้าเว็บจริงแล้วเก็บสิ่งที่
ออกมา — ผลลัพธ์จึงเหมือนที่คนเข้าเว็บเห็นทุกอย่าง รวมของที่ plugin แต่ละตัวแทรกเข้ามา
ตอน render ด้วย plugin ที่เราไม่เคยเห็นก็ได้ผลถูกต้องโดยไม่ต้องเขียนโค้ดรองรับเป็นรายตัว

หน้าที่ไม่มีลิงก์ไหนชี้ถึงเลยจะไม่ถูกเก็บ — ตัวไล่ URL เริ่มจาก sitemap + ฐานข้อมูล
(ทุก post type, ทุก term, ทุกหน้า pagination) แล้วค่อยตามลิงก์ต่อไปเรื่อยๆ
ถ้ายังมีหน้าตกหล่นให้ใส่เองในช่อง "URL เพิ่มเอง"

---

## ① ติดตั้ง plugin ที่เว็บลูกค้า

```powershell
npm run zip:plugin
```

ได้ `build/wp-static-export.zip` แล้วเอาไปติดตั้งที่เว็บลูกค้า:
**ปลั๊กอิน → เพิ่มปลั๊กอินใหม่ → อัปโหลดปลั๊กอิน → เปิดใช้งาน**

> 🚨 **ห้ามบีบ zip ด้วย PowerShell `Compress-Archive`** — เวอร์ชัน 5.1 (ที่ Windows แถมมา)
> เขียน path ข้างในไฟล์ด้วย `\` ซึ่งผิดมาตรฐาน ZIP WordPress จะแตกไฟล์ออกมาแล้วไม่เห็นโฟลเดอร์
> แล้วฟ้องว่า **"แพคเกจไม่สามารถติดตั้งได้ ไม่พบปลั๊กอินที่ใช้งานได้"**
> สคริปต์ `zip:plugin` ใช้ `tar` แทน และตรวจโครงข้างใน zip ให้ทุกครั้งก่อนบอกว่าเสร็จ
> ถ้าจะบีบเองให้ใช้ 7-Zip หรือคลิกขวา → Send to → Compressed folder

เมนูจะอยู่ที่ **เครื่องมือ → Static Export**

ต้องมีบนเซิร์ฟเวอร์: PHP 7.4 ขึ้นไป และ **PHP zip extension** (`ZipArchive`)
ถ้าไม่มี zip หน้า admin จะเตือนตั้งแต่เปิดเข้ามา

> **เว็บที่เคยติดตั้งไว้แล้ว ให้ติดตั้งทับด้วย zip ตัวใหม่ก่อน export รอบถัดไป**
> เลขเวอร์ชันโชว์อยู่ข้างหัวข้อ *Static Export* ในหน้า admin เทียบกับ `Version:` ใน
> `plugin/wp-static-export/wp-static-export.php` ได้ว่าเว็บนั้นใช้ตัวเก่าอยู่หรือเปล่า

---

## ② กด export

ตั้ง **โดเมนปลายทาง** ก่อนเป็นอย่างแรก แล้วกด "เริ่ม export ใหม่"

| ช่อง | ความหมาย |
| --- | --- |
| โดเมนปลายทาง | โดเมนที่เว็บ static จะไปอยู่จริง ใช้เขียน canonical / og:url / sitemap |
| จำนวนต่อรอบ | ยิงกี่ URL ต่อ 1 request — โฮสต์ที่ตัดไวให้ลดเหลือ 3–5 |
| หน่วงระหว่าง URL | กันไม่ให้ export ทำเว็บตัวเองล่ม |
| เพดานจำนวน URL | กันเว็บที่ลิงก์วนไม่รู้จบ |
| URL เพิ่มเอง | หน้าที่ไม่มีลิงก์ไหนชี้ถึง |
| ข้าม URL ที่มีคำนี้ | เช่นใส่ `/tag/` ถ้าไม่อยากได้หน้าแท็ก |
| ล้าง tag ของ WordPress | ตัด shortlink / oEmbed / RSD / generator ที่ตายอยู่แล้วบน static |

**ปิดแท็บระหว่างทางได้** งานค้างอยู่ในฐานข้อมูล กลับมากด "ทำต่อจากที่ค้าง"
ตัว plugin ทำทีละชุดอยู่แล้วเพื่อไม่ให้ชน `max_execution_time` ของ hosting

เสร็จแล้วกดโหลด ZIP

### ถ้าเห็น HTTP 403 เต็มไปหมด — เว็บอยู่หลังไฟร์วอลล์

เจอมาแล้วกับเว็บที่ต่อ Cloudflare อยู่: หน้า HTML ดึงได้ปกติ แต่ทุกอย่างใต้
`/wp-content/` กับ `/wp-includes/` โดน 403 ตอบกลับมาเป็นหน้า
*"Sorry, you have been blocked"* ของ Cloudflare — ไม่ใช่ที่ hosting และเปลี่ยน
User-Agent ไม่ช่วย เพราะเป็นกฎที่ดูจาก path

ผลคือได้เว็บ static ที่ไม่มี CSS/JS/รูปเลย **อย่า deploy ทั้งอย่างนั้น**

ทางแก้ — ทุก request ของ export ส่ง header `X-WPSE-Export: 1` ไปด้วยเสมอ
เอาไปทำข้อยกเว้นที่ Cloudflare ได้:

1. Cloudflare → เลือกโดเมน → **Security → WAF → Custom rules → Create rule**
2. เงื่อนไข: field `Header` → name `x-wpse-export` → operator `equals` → value `1`
3. Action เลือก **Skip** แล้วติ๊ก All remaining custom rules + Managed rules + Rate limiting
4. Deploy rule แล้วกลับมา **ล้างงานทิ้ง → เริ่ม export ใหม่**
   (URL ที่ 403 ไปแล้วถือว่าจบงานแล้ว กด "ทำต่อ" เฉยๆ มันจะไม่ลองซ้ำให้)
5. **ลบ rule ทิ้งหลัง export เสร็จ** ไม่งั้นใครก็ตามที่ส่ง header นี้จะข้ามไฟร์วอลล์ได้หมด

อยากรู้ว่ากฎไหนบล็อก: Cloudflare → Security → Events แล้วหา Ray ID จากหน้า 403

---

## ③ เอา ZIP ลงเครื่อง แล้วตรวจ

### วิธีง่าย — หน้าควบคุมในเบราว์เซอร์ (แนะนำสำหรับทีม)

```powershell
npm run ui
```

เปิด <http://localhost:4455> แล้วทำตามการ์ด 1 → 2 → 3 ไม่ต้องพิมพ์คำสั่งและไม่ต้องแก้ไฟล์ไหนเอง

- **การ์ด 1** ลาก ZIP มาวางหรือคลิกเลือกไฟล์เอง มีแถบความคืบหน้า แล้วกดแตกไฟล์
- **การ์ด 2** กรอกโดเมนปลายทาง / ชื่อ Worker / `account_id` แล้วบันทึก
  — `account_id` ถูกจำไว้ใน `uploader.json` เว็บถัดไปเติมให้อัตโนมัติ
- **การ์ด 3** ปุ่มตรวจกับปุ่มขึ้นเว็บ พร้อม log สดและตัวเลขสรุปจาก `export-report.json`
  ถ้ามี 403 ค้างอยู่มันจะขึ้นกล่องแดงเตือนว่าอย่าเพิ่งขึ้นเว็บ พร้อมบอกวิธีแก้

### วิธีพิมพ์คำสั่ง

```powershell
node tools/unpack.mjs C:\path\to\ชื่อเว็บ-static-1234567890.zip
node tools/verify-export.mjs <slug>
```

`unpack` แตกไฟล์ลง `sites/<slug>/dist/` และเขียน `sites/<slug>/site.json`
ครั้งแรกต้อง **เติม `account_id` ของ Cloudflare เอง** ไฟล์นี้จะไม่ถูกเขียนทับตอน unpack รอบหน้า

`verify-export` เป็นเกทก่อน deploy อ่านอย่างเดียว ไม่แก้ไฟล์ **มี error แม้แต่ข้อเดียวคือห้าม deploy**

| error | แปลว่า | แก้ยังไง |
| --- | --- | --- |
| ยังมีลิงก์ absolute กลับไปที่ … | เว็บ static ยังวิ่งไปหา hosting เดิม | ดูว่าหลุดมาจากไหน (มักเป็น URL ที่ JS ประกอบเอง) แล้วใส่ใน "URL เพิ่มเอง" หรือแก้ที่ต้นทาง |
| อ้างถึง /wp-admin/ … | มีลิงก์ไปหลังบ้าน WP ค้างอยู่ | ปกติมาจากธีม/วิดเจ็ต ลบที่ WP แล้ว export ใหม่ |
| ลิงก์ไปหาไฟล์ที่ไม่มีอยู่ | หน้านั้นไม่ถูกเก็บ หรือชื่อไฟล์เพี้ยน | ใส่ URL นั้นในช่อง "URL เพิ่มเอง" แล้ว export ใหม่ |
| canonical ชี้ … ไม่ใช่ … | ตอน export ยังไม่ได้ตั้งโดเมนปลายทาง | ตั้งแล้ว export ใหม่ |
| เกินเพดาน 20,000 ไฟล์ | Workers แผนฟรีรับไม่ไหว | ใช้ "ข้าม URL ที่มีคำนี้" ตัดส่วนที่ไม่ต้องการออก |
| ไฟล์ใหญ่เกิน 25 MiB | Workers ไม่รับไฟล์เดี่ยวขนาดนี้ | ย้ายไฟล์นั้นไปโฮสต์ที่อื่น |
| ไม่มี 404.html | หน้า 404 ของธีมดึงไม่สำเร็จ | ดู `export-report.json` ว่าตอนดึงเจอ error อะไร |

คำเตือน (สีเหลือง) ไม่บล็อก — เป็นของที่ควรดูแต่ deploy ได้

---

## ④ deploy

```powershell
node tools/deploy.mjs <slug>
```

มันจะรัน verify ให้ก่อนอัตโนมัติ ไม่ผ่านคือไม่ deploy จากนั้นเขียน `wrangler.toml`
แล้วเรียก `npx wrangler deploy`

**ครั้งแรกของแต่ละเว็บ ยังต้องทำมือบนหน้า Cloudflare อีก 2 อย่าง** (ทำครั้งเดียวจบ)

1. Workers & Pages → เลือก Worker → แท็บ **Domains** → **+ Add Domain** ใส่โดเมนเปล่าๆ
   🚨 **ห้ามใช้ + Add Route** — มันไม่สร้าง DNS ให้ และไม่ครอบโดเมนหลัก
2. ทำให้ `www` เด้งมาโดเมนหลัก

ทั้งสองข้อมีขั้นตอนละเอียดอยู่แล้วใน `DEPLOY-CLOUDFLARE.md` ของโปรเจกต์ Astro **ขั้นที่ 4–5**
รวมถึงกับดักโดเมนที่ยังไม่มี DNS record เลย

### อยากดูเว็บก่อนสลับโดเมนจริง

โดเมนปลายทางมักเป็นโดเมนเดียวกับที่ WordPress ยังรันอยู่ วินาทีที่กด Add Domain คือ
วินาทีที่เว็บลูกค้าสลับมาเป็น static ต่อหน้าคนใช้จริง ดูก่อนได้ 2 ทาง

| ทาง | ได้อะไร | ข้อควรระวัง |
| --- | --- | --- |
| ปุ่ม **ดูตัวอย่าง** ในขั้นที่ 4 | เปิด `localhost:4456` เสิร์ฟไฟล์ชุดเดียวกับที่ deploy ด้วยกฎเดียวกับ Workers | ไม่ได้ทดสอบฝั่ง edge จริง |
| เปิด toggle **Worker URL** ที่หน้า Cloudflare | ได้ URL `*.workers.dev` ของจริงบน edge | ดูข้างล่าง |

**ถ้าใช้ toggle:** `wrangler.toml` ตั้ง `workers_dev = false` ไว้ ดังนั้น **deploy รอบถัดไปจะปิด
toggle กลับเองเงียบๆ** — ถ้าเปิดค้างไว้ดูอยู่แล้ว deploy ทับ URL จะดับกลางคัน ต้องไปเปิดใหม่
และระหว่างเปิดอยู่ Google เก็บ index ได้จริง (duplicate content) ถ้าจะเปิดค้างข้ามวันให้
เปลี่ยน dropdown ข้างๆ จาก Public เป็น **Access** เพื่อบังคับให้ล็อกอินก่อนเข้า

---

## ข้อจำกัดที่ต้องรู้ก่อนรับงาน

- **redirect ของ plugin พวก 301 Redirects ตามมาด้วย** แต่เปลี่ยนรูป — ต้นทางตอบ 3xx
  ตัว export จะไม่เดินตามไปเก็บหน้าปลายทาง (ถ้าเดินตาม จะได้เว็บ affiliate ฝังมาในเว็บเรา)
  แต่เขียนเป็นหน้า **meta-refresh + `location.replace`** ไว้ที่ path เดิมแทน วิธีเดียวกับที่
  Astro ทำให้ทุก entry ใน `redirects:` ผลคือคนกด `/line` ก็ยังไปที่เดิม แต่เป็น 200 ไม่ใช่ 301
  ถ้าอยากได้ 301 จริงๆ ต้องไปตั้ง Redirect Rule ที่ Cloudflare เอง — ดูรายการทั้งหมดได้ที่
  หัวข้อ `redirects` ใน `export-report.json`
- **ไม่รองรับของที่ต้องมี server**: ฟอร์มติดต่อ ช่องค้นหา คอมเมนต์ ตะกร้าสินค้า
  เว็บกลุ่มที่เครื่องมือนี้ทำไว้ให้คือเว็บเนื้อหาล้วน ถ้าเว็บลูกค้ามีของพวกนี้
  `export-report.json` จะบอกไว้ในหัวข้อ `dynamic_bits` — อ่านก่อนส่งงาน
- **ไฟล์ที่ JS ประกอบ URL ขึ้นมาตอน runtime หาไม่เจอ** เพราะมันไม่มีอยู่ใน HTML ตอนดึง
  ถ้ารูป/ฟอนต์บางตัวหาย ให้ดูใน DevTools ว่าเรียกไฟล์ไหน แล้วใส่ใน "URL เพิ่มเอง"
- **เพดาน Workers แผนฟรี**: 20,000 ไฟล์ต่อ Worker และไฟล์เดี่ยวไม่เกิน 25 MiB
  เก็บเฉพาะไฟล์ที่มีหน้าอ้างถึงจริง ไม่ได้เหมา `uploads/` ทั้งโฟลเดอร์ จึงมักไม่ชน
  แต่เว็บที่รูปเยอะมากก็ชนได้ — verify จะฟ้องก่อน deploy
- **สิ่งที่เปลี่ยนหลัง export ต้อง export ใหม่ทั้งรอบ** ไม่มี incremental

---

## เช็กว่าเครื่องมือยังดีอยู่

```powershell
node tools/verify-export.mjs _selftest
```

ต้องขึ้น `✓ ผ่านหมด` — `sites/_selftest/` เป็นเว็บจำลองเล็กๆ ที่ถูกต้องทุกข้อ
(รวมหน้า path ภาษาไทย) ถ้าอันนี้ไม่เขียว แปลว่าตัว verify เองพัง ไม่ใช่เว็บลูกค้าพัง

---

## โครงไฟล์

```
wp-project/
  plugin/wp-static-export/     ซอร์ส plugin ฝั่ง WordPress
    includes/                  discovery · crawler · extractor · rewriter · writer · packer · report
    admin/                     หน้า admin + JS + CSS
  tools/
    zip-plugin.mjs             แพ็ก plugin เป็น zip ไปติดตั้ง
    unpack.mjs                 แตก ZIP ที่ export มา
    verify-export.mjs          เกทก่อน deploy
    deploy.mjs                 เขียน wrangler.toml + wrangler deploy
  sites/<slug>/
    site.json                  origin / โดเมนปลายทาง / worker name / account_id
    dist/                      ไฟล์เว็บ (ไม่เข้า git)
    export-report.json         รายงานจากฝั่ง WordPress
```
