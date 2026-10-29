# ติดตั้งระบบสั่งเสื้อกีฬา

PHP 8.4 + Apache, PDO/MySQL 8, Bootstrap 5, Vanilla JS, Select2 และ SweetAlert2 แบบ local assets ไม่มี Node runtime หรือ build frontend ใน production ระบบสร้าง **application container เดียว** เชื่อม MySQL และ Traefik ที่มีอยู่ ไม่สร้างหรือแก้ไขบริการเดิม

## 1. สิ่งที่ต้องเตรียม

- Docker Engine/Compose v2 ที่ทำงานอยู่, Traefik พร้อม HTTPS entrypoint และ certificate resolver
- External Docker network `de-net` สำหรับ MySQL และ `proxy` สำหรับ Traefik
- MySQL 8.0.16 ขึ้นไป (แนะนำ 8.4), database/user สำหรับแอปนี้โดยเฉพาะ
- DNS ของโดเมนชี้มายัง Traefik และผู้ดูแลตรวจว่ากำหนด router ไม่ชนระบบอื่น
- API Key EasySlip v2, บัญชีรับเงินจริง, ราคาและไซซ์จริง, ภาพ 20 ชุด, QR LINE และเบอร์ติดต่อจริง
- ผู้รับผิดชอบตรวจทานประกาศความเป็นส่วนตัวและเติมข้อมูลใน `app/Views/privacy.php`

ตรวจ network โดยไม่เปลี่ยนแปลง:

```sh
docker version
docker network inspect de-net
docker network inspect proxy
```

## 2. เตรียม MySQL แบบ least privilege

ให้ DBA รันคำสั่งเฉพาะ database ใหม่นี้ ห้ามใช้ root เป็น runtime user และห้ามแก้ไข database อื่น ตัวอย่างต่อไปนี้ต้องแทนชื่อและรหัสผ่านจริงด้วยช่องทางที่ปลอดภัย:

```sql
CREATE DATABASE sport_shirts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sport_shirts_app'@'%' IDENTIFIED BY 'REPLACE_WITH_RANDOM_SECRET';
GRANT SELECT, INSERT, UPDATE, DELETE ON sport_shirts.* TO 'sport_shirts_app'@'%';
CREATE USER 'sport_shirts_migrator'@'%' IDENTIFIED BY 'REPLACE_WITH_DIFFERENT_SECRET';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
ON sport_shirts.* TO 'sport_shirts_migrator'@'%';
```

จำกัด host/network เพิ่มตามโครงสร้างจริง แยก migration credentials ออกจาก runtime `.env` ไม่ให้แอปมีสิทธิ์ DDL, FILE, SUPER หรือ GRANT OPTION

## 3. ตั้งค่า environment

```sh
cp .env.example .env
chmod 600 .env
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

นำผลคำสั่งสุดท้ายใส่ `APP_KEY` และเก็บ key สำรองอย่างปลอดภัย **ห้ามเปลี่ยน key ระหว่างใช้งานปกติ** เพราะใช้สร้าง access token ที่คงเดิมเมื่อลอง submit ซ้ำ

ค่าที่ต้องกรอก:

| ตัวแปร | ความหมาย |
| --- | --- |
| IMAGE_TAG | tag ที่เผยแพร่แล้วของ `ghcr.io/5dmax/order.tnoi.me` ค่าเริ่มต้น `main`; production ควรใช้ release tag ที่ไม่ถูกเขียนทับ |
| APP_ENV | `production` |
| APP_URL | URL จริงขึ้นต้น `https://` |
| APP_TIMEZONE | `Asia/Bangkok` |
| APP_KEY | ค่าสุ่มอย่างน้อย 32 ตัวอักษร แนะนำ hex 64 ตัว |
| APP_TRUSTED_PROXIES | IP/CIDR ของ Traefik ที่แอปเชื่อถือ คั่นด้วย comma |
| DB_HOST / DB_PORT | DNS ของ shared MySQL บน network `de-net` / 3306 |
| DB_DATABASE / DB_USERNAME / DB_PASSWORD | database ใหม่และ runtime user |
| TRAEFIK_NETWORK | `proxy` |
| TRAEFIK_HOST | โดเมนอย่างเดียว ไม่ใส่ `https://` |
| TRAEFIK_ROUTER | ชื่อ router/service เฉพาะแอป ไม่ซ้ำระบบอื่น |
| TRAEFIK_ENTRYPOINT | ชื่อ HTTPS entrypoint ที่มีอยู่ |
| TRAEFIK_CERT_RESOLVER | resolver ที่มีอยู่ |
| EASYSLIP_API_KEY | API Key สำหรับ Branch ที่ใช้จริง |

`APP_TRUSTED_PROXIES` ต้องอ้างอิง IP ของ Traefik หรือ CIDR network ที่ควบคุมได้เท่านั้น ห้ามใช้ `0.0.0.0/0` หรือ `::/0` ระบบอ่าน `X-Forwarded-For` จากขวาไปซ้ายเฉพาะ proxy ที่เชื่อถือ เพื่อไม่ให้ผู้ซื้อทุกคนใช้ rate limit ร่วมกันและไม่รับ IP ปลอม ตรวจการตั้งค่า forwarded headers ของ Traefik ที่มีอยู่ด้วย การ redirect HTTP ไป HTTPS ให้ใช้การตั้งค่า Traefik เดิม แอปปฏิเสธ HTTP ใน production

`.env` เป็นรูปแบบ KEY=VALUE; ห้ามใส่ inline comment หรือ multiline secret ค่า Compose ที่มี `$` ต้อง escape ตาม Compose ห้าม commit `.env`

## 4. ดึง image และเริ่มระบบ

Compose ใช้ image `ghcr.io/5dmax/order.tnoi.me:${IMAGE_TAG:-main}` ต้อง build และ push image จาก Dockerfile นี้ไปยัง GHCR ก่อน deploy หาก package เป็น private ให้ login ด้วยบัญชีที่มีสิทธิ์อ่าน package โดยใช้ `docker login ghcr.io` การแก้ source ในเครื่อง deploy จะไม่มีผลจนกว่าจะเผยแพร่ image รุ่นใหม่

Libraries อยู่ใน `public/assets/vendor` พร้อมเวอร์ชันและ SHA-256 ใน `manifest.json` แล้ว ไม่มี CDN ตอนใช้งานจริง หากต้องดาวน์โหลดคืนใช้ `python3 bin/fetch-assets.py` บนเครื่องพัฒนา (ต้องมีอินเทอร์เน็ต)

```sh
docker compose config --quiet
docker compose pull app
docker compose up -d
```

ไม่มี `ports:`; Traefik ติดต่อ Apache port 80 ภายใน network เท่านั้น Uploads อยู่ใน named volume `uploads` ซึ่ง Compose เติม project prefix ให้เอง ห้าม mount Docker socket

ใช้ migration user ชั่วคราวผ่าน environment ของ process ไม่ใส่รหัสผ่านใน command line/history:

```sh
read -r DB_USERNAME
read -rs DB_PASSWORD
export DB_USERNAME DB_PASSWORD
docker compose exec -e DB_USERNAME -e DB_PASSWORD app php bin/console.php migrate
docker compose exec -e DB_USERNAME -e DB_PASSWORD app php bin/console.php seed
unset DB_USERNAME DB_PASSWORD
```

คำสั่ง `read -rs` ใช้ bash/zsh หาก shell ไม่รองรับให้ใช้ช่องทาง secret ของระบบ deploy Migration เป็น versioned และรันซ้ำได้ มี advisory lock; DDL ของ MySQL auto-commit จึงทำตารางด้วย IF NOT EXISTS เพื่อรองรับการเริ่มใหม่หลังล้มเหลว Seeder รันซ้ำได้ ไม่สร้างราคา ไซซ์ หรือรหัสผ่านสมมติ

สร้าง Admin คนแรกผ่าน terminal แบบ interactive:

```sh
docker compose exec app php bin/console.php admin:create
```

รหัสผ่านอย่างน้อย 14 ตัวอักษร ไม่ echo บนหน้าจอ เก็บด้วย Argon2id สร้างครั้งเดียว ผู้ใช้ถัดไปจัดการผ่าน Admin ไม่มี public registration

## 5. ตั้งค่าหลังบ้าน `/admin`

1. **สินค้าและราคา** เพิ่มไซซ์/ราคาทั้งสามประเภท เปิดใช้งานประเภทและไซซ์ที่ขาย ราคาใช้ร่วมกันทุกกลุ่ม ไม่มี stock ส่วนลด หรือค่าส่ง
2. **ภาพตามกลุ่ม** เลือกกลุ่ม/ประเภท อัปโหลดภาพและตรวจ preview ให้ครบ 20 ชุด ไม่มีภาพสินค้าสมมติ
3. **ช่องทางติดต่อ** ตั้งเบอร์จริงและอัปโหลด QR LINE ของเจ้าหน้าที่ ระบบไม่สร้าง QR หรือบัญชีให้
4. **ธนาคาร / EasySlip** กดรายชื่อธนาคารจาก GET `/banks` ตรวจ bankCode และ `extraVerify` ที่ธนาคารต้องการ กรอกบัญชีจริง 1 บัญชี ชื่อไทย/อังกฤษ ประเภท `NATURAL` หรือ `JURISTIC` และพิมพ์ข้อความยืนยันก่อนบันทึก
5. ตั้งค่าบัญชีรับเงินใน EasySlip ให้ตรงกับหลังบ้าน ระบบเก็บรูปสลิปแล้วส่ง multipart image ไป POST `/v2/verify/bank` พร้อม `matchAccount=true`, `checkDuplicate=true` และ `matchAmount` จากยอดคำสั่งซื้อ ยืนยันเมื่อ success=true, isDuplicate=false, rawSlip.receiver.bank.id ตรงกับธนาคารในคำสั่งซื้อ และ rawSlip.amount.amount ตรงกับยอดชำระ ใช้ transRef ป้องกันการใช้ซ้ำภายในระบบ ไม่บังคับ matchedAccount, amountInSlip หรือ amount.local ใน response; หากมีค่าขัดแย้งจะไม่อนุมัติ ไม่ต้องติดตั้งตัวอ่าน QR ในเซิร์ฟเวอร์.
6. เติมข้อมูลจริงและตรวจประกาศใน `app/Views/privacy.php` build/deploy ใหม่ จากนั้นเลือกว่าตรวจทานแล้วในหน้าธนาคาร ควรเปลี่ยนเวอร์ชันประกาศใน view/shop/order service พร้อมกันเมื่อแก้สาระสำคัญ
7. **ผู้ใช้ / สิทธิ์** สร้าง Sales/Support และเลือกสิทธิ์ที่จำเป็น ทุก endpoint ตรวจสิทธิ์ที่ server สิทธิ์ผู้ใช้/บทบาท/ธนาคารสงวน Admin รีเซ็ตรหัสผ่านหรือเปลี่ยนข้อมูลผู้ใช้จะยกเลิก session เดิม

ออเดอร์จะยืนยันไม่ได้ถ้ายังไม่มีบัญชีที่เชื่อม EasySlip หรือยังไม่รับรองประกาศ สินค้าที่ไม่มีราคาหรือปิดขายสั่งไม่ได้ รูปและข้อมูลติดต่อควรตรวจครบก่อนเปิดให้ผู้ซื้อ

## 6. พฤติกรรมคำสั่งซื้อและชำระเงิน

- Order ใหม่เป็น `pending_payment`; คำนวณเป็น integer satang ฝั่ง server และ snapshot ราคากับบัญชีรับเงิน ค่าจัดส่ง 0
- ลิงก์ผู้ซื้อใช้ Order Reference และ token 256 บิตใน URL fragment ไม่ใส่ token ใน query string หรือ access log ต้องเก็บลิงก์เอง ไม่มีสมาชิกหรือการส่ง notification
- Client ส่ง token ใน POST body พร้อม CSRF ห้ามส่งลิงก์ให้ผู้อื่น
- ยอดตรง บัญชี/ธนาคารตรง currency THB ข้อมูลครบ และไม่ซ้ำ จึงเป็น `verified` / `paid`
- ยอดขาด/เกินเป็น `underpaid` / `overpaid` ให้ติดต่อเจ้าหน้าที่ ไม่มีระบบเติมยอดหรือคืนเงินอัตโนมัติ
- API error/timeout/quota/missing key หรือข้อมูลไม่พอเป็น `review`; ไม่อนุมัติอัตโนมัติ
- บัญชีผิด/สลิปซ้ำไม่เปลี่ยนเป็น paid มีทั้ง provider duplicate และ local unique transaction reference
- ไม่ถือ DB/session lock ระหว่างเรียก API จำกัด 3 ครั้งต่อออเดอร์ ภาพเดิม/คำขอเดิมไม่เรียก API ซ้ำ
- เจ้าหน้าที่ผู้มี `payments.approve` อนุมัติ Manual ได้หลังตรวจเงินจริง ต้องระบุเหตุผลและเลขอ้างอิง; เลขที่ provider ส่งมาเปลี่ยนไม่ได้ ผลตรวจเดิมไม่ถูกแก้ย้อนหลัง เก็บผู้อนุมัติ เวลา เหตุผลแยกตาราง
- Sales/Support ไม่ได้สิทธิ์เริ่มต้น ต้องให้สิทธิ์โดย Admin ไม่อนุญาตลบ master data ที่อ้างอิงคำสั่งซื้อ; ใช้ปิดใช้งาน
- สถานะจัดส่งเปลี่ยนได้จาก paid → fulfilled; ยกเลิกได้จาก pending_payment เมื่อไม่มีการตรวจสลิปค้าง

EasySlip v2 อ้างอิง: https://document.easyslip.com/th/v2/verify/bank/image และ https://document.easyslip.com/th/v2/bank-accounts/create ตรวจเอกสารวันที่ 20 ตุลาคม 2026; การทดสอบใน repository ใช้ mock ไม่ยืนยันการเชื่อมต่อจริง

## 7. ตรวจความพร้อมและทดสอบ

```sh
php tests/run.php
python3 tests/smoke_http.py
docker compose exec app php tests/run.php
docker compose ps
docker compose exec app curl -fsS http://127.0.0.1/health
```

`/health` ตรวจ database และการเขียน upload directory ไม่ตรวจ EasySlip ไม่ใช้ quota

MySQL integration tests ต้อง **สร้าง database ว่างแยก** ลงท้าย `_test` และให้ผู้ใช้ทดสอบมีสิทธิ์ DDL เฉพาะ database นี้ ห้ามใช้ production; test มี guard ไม่แก้ฐานข้อมูลที่มีออเดอร์/สินค้า/ผู้ใช้อยู่แล้ว หลังรันทิ้ง fixtures ไว้ให้ตรวจสอบและให้ DBA ลบ database ทดสอบเอง:

```sh
# ตั้ง DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD ให้ชี้ test database user
TEST_DB_DATABASE=sport_shirts_test php tests/integration.php
```

ครอบคลุม migration/seed ซ้ำ, 5 กลุ่ม 20 จังหวัด, 20 image slots, ราคา snapshot, หลายไซซ์, ปิดขาย/ไม่มีราคา, idempotency, PDPA, RBAC, session revocation, EasySlip mock ยอดตรง/ขาด/เกิน/ผิดบัญชี/ธนาคาร/ซ้ำ/error และ worker สอง process แย่ง transaction reference เดียวกัน Browser tests สำหรับเครื่องพัฒนาเท่านั้น (ไม่ต้องมี Node ใน production): ติดตั้ง Playwright ใน tooling environment แล้วรัน `node tests/browser.cjs` หากใช้ Chromium ที่ติดตั้งอยู่ให้ตั้ง `BROWSER_EXECUTABLE` เป็น path ของ browser; ทดสอบ mobile checkout ด้วย mocked API และบันทึกภาพไว้ใน `/tmp` ไม่ใช่ end-to-end database test

Exit 77 หมายถึง **ไม่ได้รันทดสอบ** ไม่ใช่ผ่าน

ตรวจด้วยบัญชีจริงก่อน production: ธนาคารและชื่อบัญชีถูกต้อง, API Key/โควตา, สลิปจริงหนึ่งรายการ, บัญชีผิด/สลิปซ้ำ, responsive บนมือถือ, permission ของแต่ละ role, Backup/Restore และ HTTPS จริง ห้ามอ้างผ่าน live verification จากผล mock

### สถานะการตรวจในสภาพแวดล้อมพัฒนา

- PHP 8.4 CLI: unit tests 60 assertions และ HTTP smoke tests 20 assertions ผ่าน
- Browser flow: 13 checks ผ่านด้วย Brave/Playwright โดย mock catalog/order API (ไม่ได้ใช้ MySQL จริง)
- Docker CLI มี แต่ daemon ไม่ทำงาน; build/container/healthcheck จริงยังไม่ได้รัน
- ไม่มี MySQL test credentials; integration/concurrency tests ยังไม่ได้รัน
- ไม่มี EasySlip API Key จริง; ไม่ได้ทำ live verification

## 8. งานดูแลประจำ

```sh
docker compose exec app php bin/console.php maintenance
```

รันเป็นระยะด้วย scheduler ที่มีอยู่ เช่นทุก 5 นาที คำสั่งลบ rate-limit buckets ที่หมดอายุ และเปลี่ยน attempt ที่ processing ค้างเกิน 5 นาทีเป็น review เพื่อเจ้าหน้าที่ตรวจสอบ ไม่มี retry API อัตโนมัติ ประวัติออเดอร์และ audit ไม่ถูกลบโดยคำสั่งนี้ ต้องกำหนด retention จริงในนโยบายแล้ววางกระบวนการลบ/สำรองอย่างมีสิทธิ์ควบคุมแยกต่างหาก

## 9. สำรองและกู้คืน

สำรอง database กับ uploads ในช่วงที่หยุดรับรายการชั่วคราวเพื่อให้สอดคล้องกัน เก็บ `.env`/APP_KEY เข้ารหัสแยกต่างหาก จำกัดสิทธิ์และทดสอบกู้คืนจริง ไฟล์สำรองมีข้อมูลส่วนบุคคล

ใช้ MySQL client ของ DBA ที่มีอยู่ ไม่สร้าง MySQL container เพิ่ม:

```sh
mysqldump --login-path=shirt-backup --single-transaction --routines --triggers --no-tablespaces sport_shirts > sport_shirts.sql
docker compose exec -T app tar -C /var/www/html/storage -czf - uploads > uploads.tar.gz
```

`shirt-backup` เป็น login-path ที่ DBA สร้างด้วย mysql_config_editor และให้สิทธิ์สำรองเฉพาะที่จำเป็น ตัวแอปไม่ต้องมี mysqldump

กู้คืนใน database/volume แยกเพื่อทดสอบก่อน ชี้ environment ไปยัง database เป้าหมายอย่างระมัดระวัง:

```sh
mysql --login-path=shirt-restore sport_shirts < sport_shirts.sql
docker compose exec -T app tar -C /var/www/html/storage -xzf - < uploads.tar.gz
docker compose exec app chown -R www-data:www-data storage/uploads
```

ตรวจ APP_KEY เดิม, จำนวนออเดอร์, payment claims, ไฟล์สลิป และ permission ก่อนเปิดรับงานอีกครั้ง ไม่ใช้ `docker compose down -v` เพราะลบ uploads volume

## 10. Update / Rollback

1. สำรอง DB/uploads/env และเก็บ image เวอร์ชันปัจจุบันด้วย tag ที่ระบุรุ่น
2. ตรวจ source และ tests ในสภาพแวดล้อมทดสอบ โดยเฉพาะ migration ใหม่
3. เผยแพร่ image รุ่นใหม่ ตั้ง `IMAGE_TAG` ใน `.env` แล้วรัน `docker compose pull app` ในช่วงที่เหมาะสม
4. รัน migration ด้วย migrator credentials, seed เฉพาะเมื่อจำเป็น
5. `docker compose up -d` และตรวจ health/login/order/payment
6. Rollback โดยตั้ง `IMAGE_TAG` กลับเป็น release tag เดิม แล้ว `docker compose pull app` และ `docker compose up -d`; ถ้า schema ไม่ backward-compatible ต้อง restore backup ที่สอดคล้องกันและวางแผนรายการหลัง backup กับผู้รับผิดชอบก่อน ห้ามย้อนฐานข้อมูลโดยไม่ตรวจข้อมูลเงินจริง

OPcache ไม่ตรวจ timestamp ใน production ต้อง recreate/restart หลังเปลี่ยน source

## 11. Logs / Restart / Stop

```sh
docker compose logs --tail=100 app
docker compose restart app
docker compose stop app
docker compose up -d
```

แอปไม่ log password/API key/access token หรือ response เต็ม Error log แสดงประเภท error/code โดยไม่เปิด stack trace ต่อผู้ใช้ ระวังระบบ log ของ reverse proxy ที่มีอยู่ไม่บันทึก POST body หรือ Authorization

## 12. Troubleshooting

- **HTTPS required**: ตรวจ `APP_TRUSTED_PROXIES`, IP ที่ Traefik ใช้, forwarded proto และ HTTPS entrypoint
- **404/502 จาก Traefik**: ตรวจ network จริง, router/service ไม่ซ้ำ, rule host และ port 80; อย่าเพิ่ม port mapping production เพื่อแก้ขัด
- **503/health unavailable**: ตรวจ DB hostname/user/grants/database, migrate/seed, สิทธิ์ volume และ APP_KEY
- **ยืนยันออเดอร์ไม่ได้**: ตรวจบัญชีเชื่อม EasySlip, privacy_ready, ราคาและสถานะประเภท/ไซซ์
- **EasySlip review**: ตรวจ missing key, quota, network, bank registration, currency/response structure; ไม่แก้ status เป็น paid ด้วย SQL ให้ใช้ Manual พร้อมเหตุผลหลังตรวจเงินจริง
- **รูปไม่ขึ้น**: ยังไม่ได้อัปโหลด หรือ volume/permission ไม่ถูกต้อง; รูปต้องเป็น JPEG/PNG/GIF/WebP ไม่เกิน 4 MB และ 12 ล้านพิกเซล ระบบ re-encode JPEG
- **419/403/เซสชันหมดอายุ**: โหลดหน้าใหม่ ต้อง HTTPS/cookie และ CSRF token จากหน้าเดียวกัน; login ใหม่หลังเปลี่ยนรหัสผ่าน/สิทธิ์
- **Rate limit 429**: รอ window หมด ตรวจ trusted proxy อย่าเพิ่มสิทธิ์ trust ให้ทุก IP
- **Migrate ไม่ผ่าน**: ใช้ migrator user และ MySQL >=8.0.16 ตรวจ error ฝั่ง CLI; ไม่เพิ่ม DDL ให้ runtime user

## 13. Security checklist ก่อนเปิดจริง

- [ ] APP_ENV production, HTTPS จริง, APP_KEY สุ่ม, trusted proxy ถูกต้อง
- [ ] DB runtime user จำกัดสิทธิ์; ไม่มี secrets ใน Git/image/log
- [ ] Traefik/MySQL เดิมไม่ถูกเปลี่ยน, ไม่มี published app port/socket mount
- [ ] Admin ใช้รหัสผ่านแข็งแรง, ทดสอบ Sales/Support และ session revocation
- [ ] สลิปอยู่นอก public root เข้าผ่าน route ที่ตรวจสิทธิ์เท่านั้น
- [ ] บัญชีจริงและ EasySlip registration ตรง; ทดสอบ live แยกจาก mock
- [ ] ราคา ไซซ์ รูป QR LINE และเบอร์โทรครบ
- [ ] Privacy Notice ระบุผู้ควบคุม ฐานกฎหมาย ผู้รับข้อมูล ระยะเวลา และช่องทางสิทธิครบ
- [ ] Unit/HTTP/MySQL/concurrency/browser tests ผ่านใน staging
- [ ] ทดสอบ backup/restore และกำหนด retention/maintenance จริง

### เพิ่มหลายไซซ์และรูปพร้อมกัน

ที่เมนูสินค้าและราคา กด **เพิ่มหลายไซซ์พร้อมกัน** เพิ่ม/ลบแถวและกำหนดประเภท ไซซ์ ราคา สถานะได้สูงสุด 50 รายการ แล้วกดบันทึกครั้งเดียว ถ้าข้อมูลผิดหรือไซซ์ซ้ำจะไม่เพิ่มรายการใดในชุดนั้น

กด **อัปโหลดทุกกลุ่มพร้อมกัน** เพื่อเลือกภาพในตาราง 5 โซน × 4 ประเภท พร้อม preview ภาพใหม่ ช่องว่างจะคงภาพเดิมไว้ ภาพละไม่เกิน 4 MB รวมได้ 20 ภาพต่อคำขอ ถ้าไฟล์ใดไม่ผ่าน ระบบจะไม่เปลี่ยนภาพในฐานข้อมูลและลบไฟล์ใหม่ของชุดที่ไม่สำเร็จ

PHP กำหนด `post_max_size=96M`, `upload_max_filesize=4M`, `max_file_uploads=20` ต้อง build/push image ใหม่และ deploy เพื่อใช้ค่าที่เปลี่ยน หาก Traefik มี middleware จำกัด request body ให้รองรับอย่างน้อย 96 MiB สำหรับหลังบ้านด้วย

ทดสอบ validation ของ batch: `php tests/product_batch.php`

### เพิ่มหมวดเสื้อนักกีฬา
หลัง deploy image ใหม่ ให้รัน `docker compose exec app php bin/console.php migrate` เพื่อเพิ่มหมวดที่ 4 และช่องภาพทุกโซนในฐานข้อมูลเดิม หมวดใหม่เริ่มปิดขาย ให้เพิ่มไซซ์ ราคา รูป แล้วเปิดขายจากหลังบ้าน ข้อมูลหมวดเดิมและคำสั่งซื้อเดิมคงอยู่ สำหรับติดตั้งใหม่ seed จะสร้างครบ 4 หมวด
