# ติดตั้ง CertHub บนเซิร์ฟเวอร์จริง

คู่มือนี้ใช้ Apache 2.4 และ PHP บนเซิร์ฟเวอร์เดียว ใช้ฐานข้อมูล MySQL 8.0+ หรือ MariaDB 10.6+ (InnoDB + utf8mb4) รุ่น 2.0.0 ไม่ใช้ SQLite ในการเปิดเว็บ และไม่ต้องติดตั้ง Composer/Node.js สำหรับใช้งานเว็บ

## 1. เตรียมโดเมนและ PHP

ตั้ง DNS ของโดเมนหรือ subdomain ให้ชี้ไปยังเซิร์ฟเวอร์ เช่น `cert.example.ac.th` เปิด HTTPS ผ่านระบบจัดการโฮสติ้งหรือใบรับรองของเซิร์ฟเวอร์

**ต้องใช้ PHP 8.1 ขึ้นไป** (แนะนำ 8.4+) PHP 7.4 หรือ 8.0 จะเปิดหน้าเว็บไม่ได้ (fatal error) ตรวจรุ่นที่ใช้กับโดเมนจริงในแผงควบคุมโฮสติ้งก่อนอัปโหลด เพราะรุ่นที่ phpMyAdmin แสดงอาจไม่ตรงกับรุ่นของเว็บ ใช้รุ่นที่ผู้ให้บริการดูแลแพตช์ และทดสอบบนรุ่นที่เลือกก่อนเปิดใช้งาน เปิดส่วนขยาย `pdo_mysql`, `mbstring`, `fileinfo`, `Phar` และฟังก์ชันอัปโหลดไฟล์ โดย PHP ที่รันผ่านเว็บต้องมีส่วนขยายด้วย ไม่ใช่เฉพาะ PHP CLI

ค่าที่แนะนำใน php.ini ของเว็บ:

```ini
date.timezone = Asia/Bangkok
file_uploads = On
upload_max_filesize = 8M
post_max_size = 16M
display_errors = Off
log_errors = On
session.cookie_httponly = On
session.cookie_samesite = Lax
session.cookie_secure = On
```

ตั้ง `session.cookie_secure` เมื่อเปิด HTTPS แล้ว สำหรับการตั้งบัญชีครั้งแรกผ่าน HTTP loopback ตามข้อ 4 ให้ใช้ค่าชั่วคราว `Off` เฉพาะ PHP ตัวช่วยนั้น

## 2. นำไฟล์ขึ้นเซิร์ฟเวอร์

ตัวอย่างติดตั้งที่ `/var/www/certhub`:

```bash
git clone https://github.com/krusomkhuan/certhub.git /var/www/certhub
```

หรือดาวน์โหลด ZIP จาก GitHub แล้วอัปโหลดผ่าน SFTP/File Manager ให้มี `index.php`, `bootstrap.php`, `version.php`, โฟลเดอร์ `assets`, `data` และไฟล์อื่นครบ รวมไฟล์ซ่อน `.htaccess` ที่ root, `data/.htaccess`, `database/.htaccess` และ `tools/.htaccess`

GitHub ไม่มีฐานข้อมูล ภาพอัปโหลด โครงการสมมุติ หรือบัญชีทดสอบในเครื่องพัฒนา การติดตั้งใหม่จะเริ่มด้วยข้อมูลว่าง

ให้บัญชีที่รัน PHP อ่านไฟล์โปรแกรมและเขียนได้เฉพาะ `data/` กับโฟลเดอร์ session/temp ที่ตั้งไว้ ตัวอย่างสำหรับ PHP ที่ทำงานเป็น `www-data` (เปลี่ยนตามเครื่องจริง):

```bash
sudo chown -R www-data:www-data /var/www/certhub/data
sudo chmod 750 /var/www/certhub/data
```

ไม่ใช้สิทธิ์ 777 กับทั้งโปรเจกต์ โฟลเดอร์ data ใช้เก็บภาพและไฟล์สำรองชั่วคราว ไม่เก็บฐานข้อมูลหลักแล้ว

## 2.1 สร้างฐานข้อมูล MySQL และตั้งค่าเชื่อมต่อ

ใน cPanel / DirectAdmin / phpMyAdmin สร้างฐานข้อมูลและบัญชี MySQL แล้วให้สิทธิ์บัญชีนั้นเฉพาะฐานข้อมูล CertHub (ไม่ใช้ root บนเซิร์ฟเวอร์จริง) เลือก charset `utf8mb4` และ collation `utf8mb4_bin`

คัดลอก `config.example.php` เป็น `config.php` แล้วกรอก host, port, database, username, password ที่โฮสติ้งให้ ชื่อฐานข้อมูลอาจมี prefix เช่น `college_certhub` ตัวไฟล์ config.php ถูกยกเว้นจาก Git และไฟล์สำรอง ZIP ต้องสร้างใหม่เมื่อย้ายเครื่อง

สามารถใช้ environment variables แทนได้: `CERTHUB_DB_HOST`, `CERTHUB_DB_PORT`, `CERTHUB_DB_DATABASE`, `CERTHUB_DB_USERNAME`, `CERTHUB_DB_PASSWORD`, `CERTHUB_DB_SSL_CA` โดย environment จะทับค่าใน config.php ถ้าฐานข้อมูลอยู่เครื่องอื่นและต้องใช้ TLS ให้ใส่ path ของ CA ตามผู้ให้บริการ

ติดตั้งใหม่: นำเข้า `database/schema.sql` ผ่าน phpMyAdmin หรือเปิดเว็บครั้งแรกให้ระบบสร้างตาราง (ต้องมีสิทธิ์ CREATE/INDEX/REFERENCES) หากตารางมีอยู่แล้ว ระบบใช้ SELECT/INSERT/UPDATE/DELETE ในการทำงานตามปกติ บัญชีสำรองข้อมูลต้องอ่าน SHOW CREATE TABLE ได้

ย้ายข้อมูลเดิม: ใช้ไฟล์ `database/certhub.sql` จาก ZIP สำรอง MySQL นำเข้าฐานข้อมูล **ว่าง** แทน schema.sql เพราะไฟล์สำรองมีทั้งโครงสร้างและข้อมูล ห้ามนำเข้าทับฐานข้อมูลที่มีข้อมูลอยู่

## 3. ตั้ง Apache

กำหนด DocumentRoot ของโดเมนเป็น `/var/www/certhub` และเพิ่มส่วนนี้ใน VirtualHost HTTPS ที่มีใบรับรองและ PHP handler อยู่แล้ว:

```apache
DocumentRoot /var/www/certhub
<Directory /var/www/certhub>
    Options -Indexes
    DirectoryIndex index.php
    AllowOverride FileInfo AuthConfig
    Require all granted
</Directory>
<Directory /var/www/certhub/data>
    Require all denied
</Directory>
<Directory /var/www/certhub/tests>
    Require all denied
</Directory>
<Directory /var/www/certhub/database>
    Require all denied
</Directory>
<Directory /var/www/certhub/tools>
    Require all denied
</Directory>
<Directory /var/www/certhub/.git>
    Require all denied
</Directory>
```

ตรวจ configuration ก่อน reload Apache ตามคำสั่งของระบบ เช่น `apachectl configtest` หากใช้ shared hosting ให้ผู้ให้บริการยืนยันว่า `.htaccess` และ `Require all denied` ทำงานจริง หากใช้ Nginx ต้องกำหนด deny สำหรับ `/data/`, `/tests/`, `/.git/`, `/database/`, `/tools/` และไฟล์ `.env` / `config.php` ใน server configuration เพราะ Nginx ไม่อ่าน `.htaccess`

## 4. สร้างบัญชีหลักครั้งแรก

ระบบอนุญาตสร้างบัญชีแรกเมื่อ request มาจาก `127.0.0.1` หรือ `::1` เท่านั้น การเปิดผ่านโดเมนจากเครื่องส่วนตัวจะยังตั้งบัญชีแรกไม่ได้

ถ้ามี SSH สามารถใช้ PHP server ชั่วคราวที่รับเฉพาะ loopback เพื่อสร้างบัญชี:

บนเซิร์ฟเวอร์ (เปิดค้างไว้จนตั้งบัญชีเสร็จ):

```bash
cd /var/www/certhub
php -d session.cookie_secure=0 -S 127.0.0.1:9090 -t /var/www/certhub
```

บนเครื่องของผู้ติดตั้ง (อีกหน้าต่างหนึ่ง):

```bash
ssh -N -L 9090:127.0.0.1:9090 your-user@your-server
```

เปิด `http://localhost:9090/?page=login` สร้างชื่อผู้ใช้และรหัสผ่านอย่างน้อย 12 ตัวอักษร จากนั้นหยุดทั้ง PHP server ชั่วคราวและ SSH tunnel ด้วย Ctrl+C แล้วใช้งานผ่านโดเมน HTTPS ปกติ ไม่ใช้ PHP built-in server เป็นบริการเว็บจริง

ถ้าไม่มี SSH ให้ผู้ดูแลโฮสติ้งช่วยตั้งบัญชีผ่าน loopback หรือเตรียมฐานข้อมูลใหม่ที่มีเฉพาะบัญชีผู้ดูแลจริงจากเครื่องติดตั้ง แล้วส่งฐานข้อมูลผ่านช่องทางส่วนตัว ห้ามใช้ฐานข้อมูลบัญชีทดสอบเป็นระบบจริงโดยไม่ตรวจสอบ

## 5. ตั้งค่าหลังบ้าน

1. เข้าระบบผ่านโดเมน HTTPS
2. ตั้งค่า / สำรองข้อมูล → ระบุ URL ของระบบให้ตรงกับของจริง เช่น `https://cert.example.ac.th` หรือ `https://example.ac.th/certhub` ไม่ใส่ index.php หรือ query
3. ออกเลขที่เกียรติบัตร → ตั้งรูปแบบเลขกลางและลำดับถัดไปให้ต่อกับทะเบียนจริงของวิทยาลัย ตรวจเลขเดิมก่อนเปิดออกเลขใหม่
4. เพิ่มบัญชีตามหน้าที่: ผู้ดูแลหลัก, เจ้าหน้าที่งานบริหารงานทั่วไป, รองผู้อำนวยการฝ่ายบริหารทรัพยากร
5. กรอกช่องทางติดต่อและฐานการใช้ข้อมูลใน PDPA แล้วประกาศใช้เมื่อวิทยาลัยยืนยันรายละเอียด

ถ้า HTTPS จบที่ reverse proxy ต้องให้ผู้ดูแลเซิร์ฟเวอร์ตั้งการส่งต่อสถานะ HTTPS และคุกกี้ให้ถูกต้องใน trusted proxy configuration ไม่แก้แอปให้เชื่อ header จากผู้ใช้ทุกคน

## 6. ตรวจการติดตั้ง

- เปิดหน้าหลักและรายละเอียดโครงการได้โดยไม่เข้าสู่ระบบ
- ค้นหาโดยชื่อและโครงการ ดู/พิมพ์เกียรติบัตรที่เผยแพร่แล้วได้
- หน้าเข้าสู่ระบบและสิทธิ์ทั้งสามบทบาททำงานตามหน้าที่
- URL `/data/certhub.sqlite`, `/config.php`, `/database/schema.sql`, `/tools/migrate-sqlite.php`, `/.git/config`, `/tests/registry.py` ต้องตอบ 403 หรือ 404 และต้องไม่ดาวน์โหลดเนื้อหาได้
- บนเซิร์ฟเวอร์ต้องไม่มีไฟล์ config อื่นนอกจาก `config.php` (เช่น `config2.php`) และไม่มีไฟล์ dump ฐานข้อมูล (`.sql`) หรือไฟล์ `.json` ประวัติข้อมูลในโฟลเดอร์เว็บ นอกจาก `database/schema.sql`
- อัปโหลดภาพและดาวน์โหลด ZIP สำรองได้
- สแกน QR จากโทรศัพท์ที่ไม่ได้อยู่ในเครื่องเซิร์ฟเวอร์ แล้วเปิด URL ถูกต้อง

กรณีย้ายข้อมูลจริง ให้หยุดการเขียนข้อมูลเดิมก่อนนำฐานข้อมูลและภาพมาครบชุด ห้ามเปิดสองระบบแยกกันให้ออกเลขพร้อมกัน หากใช้คนละฐานข้อมูลจะมีตัวนับของตัวเอง ทุกเครื่องที่ให้บริการต้องใช้ MySQL กลางเดียวกัน หากกู้คืนชุดเก่าต้องกระทบยอดเลขที่ออกหลังวันสำรองก่อนเปิดออกเลขใหม่

## 7. อัปเดตและสำรอง

ก่อนอัปเดตให้ดาวน์โหลดไฟล์สำรอง ตรวจว่าเปิด ZIP ได้ และเก็บในที่จำกัดสิทธิ์ จากนั้นอัปเดตซอร์สจาก GitHub โดยไม่เขียนทับ `data/` ต้องทำตาม CHANGELOG และคู่มือย้ายฐานข้อมูลของรุ่นนั้นก่อนเปิดระบบ รุ่น 2.0.0 ต้องมีฐานข้อมูล MySQL ที่ย้ายข้อมูลแล้ว ตรวจเลขเวอร์ชันและฟังก์ชันสำคัญหลังอัปเดตทุกครั้ง

ไฟล์สำรองไม่เข้ารหัส และรวมข้อมูลบัญชี/ข้อมูลส่วนบุคคล ไม่ควรวาง ZIP ไว้ในโฟลเดอร์ที่บุคคลทั่วไปดาวน์โหลดได้

### 7.1 อัปเกรดเซิร์ฟเวอร์ที่ใช้ MySQL รุ่น 2.0.0 อยู่แล้ว (ลำดับเลขเริ่มใหม่ทุกปี)

รุ่นนี้เปลี่ยนโครงสร้างฐานข้อมูลจาก `mysql-1` เป็น `mysql-2` (เพิ่มคอลัมน์ `recipients.sequence_year` และเปลี่ยนการกันเลขซ้ำเป็นต่อปี พ.ศ.) ทำตามลำดับ:

1. หยุดการออกเลขชั่วคราว แจ้งเจ้าหน้าที่ไม่ให้ออกเลขระหว่างอัปเกรด
2. เข้า “ตั้งค่า / สำรองข้อมูล” ดาวน์โหลด ZIP สำรอง และ Export ฐานข้อมูลจาก phpMyAdmin เก็บไว้อีกชุด
3. อัปโหลดไฟล์ใหม่ทับไฟล์โปรแกรมเดิม (หรือ `git pull`) **ห้ามเขียนทับ** `config.php` และโฟลเดอร์ `data/` และห้ามอัปโหลดไฟล์จากเครื่องพัฒนาที่ไม่ได้อยู่ใน Git เช่น `config2.php`, `database/*.sql` อื่นนอกจาก schema.sql, `data/*.json`
4. เปิดหน้าเว็บ 1 ครั้ง ระบบอัปเกรดฐานข้อมูลเองอัตโนมัติ (บัญชี MySQL ต้องมีสิทธิ์ `ALTER` และ `INDEX`) ตรวจใน phpMyAdmin ว่าตาราง `settings` มี `schema_version` = `mysql-2` และมี `central_year`
5. ถ้าบัญชีไม่มีสิทธิ์ ALTER ให้ผู้ดูแลฐานข้อมูลรัน SQL นี้ใน phpMyAdmin แทน แล้วเปิดเว็บอีกครั้ง:

```sql
ALTER TABLE recipients ADD COLUMN sequence_year INT NULL AFTER central_sequence;
UPDATE recipients SET sequence_year=IF(issued_at REGEXP '^[0-9]{4}-',CAST(LEFT(issued_at,4) AS UNSIGNED)+543,0) WHERE central_sequence IS NOT NULL;
ALTER TABLE recipients ADD UNIQUE KEY year_sequence_unique(sequence_year,central_sequence), DROP INDEX central_sequence_unique;
INSERT IGNORE INTO settings(name,value) SELECT 'central_year',MAX(sequence_year) FROM recipients;
UPDATE settings SET value='mysql-2' WHERE name='schema_version';
```

6. เข้า “ออกเลขที่เกียรติบัตร” → “ตั้งค่ารูปแบบเลขกลาง” ตรวจรูปแบบ (ต้องมีทั้ง `{seq}` และ `{year}` เช่น `{seq}/{year}`) จำนวนหลัก (เช่น 4) และ “เลขที่จะออกถัดไป” ให้ต่อกับทะเบียนจริง
7. ตรวจหน้ารายละเอียดโครงการ เปิดเกียรติบัตร 1 ใบ และทดลองพิมพ์ ก่อนเปิดให้ออกเลขอีกครั้ง

ฐานข้อมูลบนเซิร์ฟเวอร์และในเครื่องพัฒนาเป็นคนละชุด ข้อมูลที่แก้ในเครื่องพัฒนา (รายชื่อ เลขที่คืนไว้ การตั้งค่าเลข) จะไม่ขึ้นเซิร์ฟเวอร์เมื่ออัปโหลดโค้ด ต้องทำซ้ำบนเซิร์ฟเวอร์ หรือย้ายฐานข้อมูลทั้งชุดตามข้อ 2.1 โดยหยุดการออกเลขทั้งสองฝั่งก่อน

อ้างอิง: [PHP รุ่นที่ยังรองรับ](https://www.php.net/supported-versions.php), [Apache .htaccess](https://httpd.apache.org/docs/2.4/howto/htaccess.html)

## ย้ายจาก SQLite รุ่น 1.x มายัง MySQL รุ่น 2.0.0

ทำบนเครื่องเดิมที่ยังอ่าน SQLite ได้ เช่น XAMPP ของผู้พัฒนา โฮสติ้งปลายทางไม่ต้องมี SQLite:

1. ปิดการใช้งานระบบเดิมชั่วคราวและเก็บสำเนา `data/` ทั้งชุดพร้อมซอร์สเดิม
2. สร้างฐานข้อมูล MySQL ว่าง แล้วตั้ง config.php ไปยังฐานข้อมูลใหม่
3. รันบนเครื่องเดิม:

```bash
php tools/migrate-sqlite.php data/certhub.sqlite
```

เครื่องมือต้องใช้ `pdo_sqlite` เฉพาะตอนอ่านต้นทาง และ `pdo_mysql` สำหรับปลายทาง จะปฏิเสธฐานข้อมูลที่ไม่ว่าง ตรวจเทียบทุกคอลัมน์ รหัสผ่านที่แฮชแล้ว เลขเกียรติบัตร token วันที่ และจำนวนแถวก่อน commit หากเกิดความผิดพลาดจะ rollback ข้อมูลใน MySQL ไฟล์ SQLite ต้นทางไม่ถูกแก้ไขหรือลบ

4. เปิดเว็บและตรวจบัญชี/โครงการ จากนั้นดาวน์โหลด ZIP สำรองใหม่ ไฟล์ SQL อยู่ที่ `database/certhub.sql`
5. นำ SQL เข้า MySQL ว่างของโฮสติ้งผ่าน phpMyAdmin และนำไฟล์ซอร์ส/ภาพขึ้นครบชุด สร้าง config.php ด้วยค่าของโฮสติ้ง ตรวจทุกอย่างก่อนเปิดใช้งาน
6. เก็บ SQLite เดิมไว้เป็นสำรองแบบออฟไลน์ อย่านำระบบ SQLite เก่าและระบบ MySQL ใหม่มาออกเลขคู่ขนานกัน

ข้อมูลจากรุ่นก่อน v1.0.0 ที่มี schema ไม่ครบควรเปิดด้วยซอร์ส v1.0.0 เพื่อทำ migration เดิมก่อนใช้เครื่องมือแปลงนี้
