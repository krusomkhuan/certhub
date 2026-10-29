# ติดตั้ง CertHub บนเซิร์ฟเวอร์จริง

คู่มือนี้ใช้ Apache 2.4 และ PHP บนเซิร์ฟเวอร์เดียว ฐานข้อมูลเป็น SQLite ไม่ต้องสร้างฐานข้อมูล MySQL และไม่ต้องติดตั้ง Composer/Node.js สำหรับใช้งานเว็บ

## 1. เตรียมโดเมนและ PHP

ตั้ง DNS ของโดเมนหรือ subdomain ให้ชี้ไปยังเซิร์ฟเวอร์ เช่น `cert.example.ac.th` เปิด HTTPS ผ่านระบบจัดการโฮสติ้งหรือใบรับรองของเซิร์ฟเวอร์

ใช้ PHP 8.4 หรือรุ่นใหม่กว่าที่ผู้ให้บริการดูแลแพตช์ และทดสอบบนรุ่นที่เลือกก่อนเปิดใช้งาน เปิดส่วนขยาย `pdo_sqlite`, `sqlite3`, `mbstring`, `fileinfo`, `Phar` และฟังก์ชันอัปโหลดไฟล์ โดย PHP ที่รันผ่านเว็บต้องมีส่วนขยายด้วย ไม่ใช่เฉพาะ PHP CLI

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

หรือดาวน์โหลด ZIP จาก GitHub แล้วอัปโหลดผ่าน SFTP/File Manager ให้มี `index.php`, `bootstrap.php`, `version.php`, โฟลเดอร์ `assets`, `data` และไฟล์อื่นครบ รวมไฟล์ซ่อน `.htaccess` ที่ root และ `data/.htaccess`

GitHub ไม่มีฐานข้อมูล ภาพอัปโหลด โครงการสมมุติ หรือบัญชีทดสอบในเครื่องพัฒนา การติดตั้งใหม่จะเริ่มด้วยข้อมูลว่าง

ให้บัญชีที่รัน PHP อ่านไฟล์โปรแกรมและเขียนได้เฉพาะ `data/` กับโฟลเดอร์ session/temp ที่ตั้งไว้ ตัวอย่างสำหรับ PHP ที่ทำงานเป็น `www-data` (เปลี่ยนตามเครื่องจริง):

```bash
sudo chown -R www-data:www-data /var/www/certhub/data
sudo chmod 750 /var/www/certhub/data
```

ไม่ใช้สิทธิ์ 777 กับทั้งโปรเจกต์ SQLite ต้องเขียนทั้งตัวไฟล์และโฟลเดอร์สำหรับ journal/ไฟล์สำรองชั่วคราวได้

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
<Directory /var/www/certhub/.git>
    Require all denied
</Directory>
```

ตรวจ configuration ก่อน reload Apache ตามคำสั่งของระบบ เช่น `apachectl configtest` หากใช้ shared hosting ให้ผู้ให้บริการยืนยันว่า `.htaccess` และ `Require all denied` ทำงานจริง หากใช้ Nginx ต้องกำหนด deny สำหรับ `/data/`, `/tests/`, `/.git/` และไฟล์ `.env` ใน server configuration เพราะ Nginx ไม่อ่าน `.htaccess`

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
- URL `/data/certhub.sqlite`, `/.git/config`, `/tests/registry.py` ต้องตอบ 403 หรือ 404 และต้องไม่ดาวน์โหลดเนื้อหาได้
- อัปโหลดภาพและดาวน์โหลด ZIP สำรองได้
- สแกน QR จากโทรศัพท์ที่ไม่ได้อยู่ในเครื่องเซิร์ฟเวอร์ แล้วเปิด URL ถูกต้อง

กรณีย้ายข้อมูลจริง ให้หยุดการเขียนข้อมูลเดิมก่อนนำฐานข้อมูลและภาพมาครบชุด ห้ามเปิดสองระบบแยกกันให้ออกเลขพร้อมกัน เพราะแต่ละ SQLite จะมีตัวนับของตัวเอง หากกู้คืนชุดเก่าต้องกระทบยอดเลขที่ออกหลังวันสำรองก่อนเปิดออกเลขใหม่

## 7. อัปเดตและสำรอง

ก่อนอัปเดตให้ดาวน์โหลดไฟล์สำรอง ตรวจว่าเปิด ZIP ได้ และเก็บในที่จำกัดสิทธิ์ จากนั้นอัปเดตซอร์สจาก GitHub โดยไม่เขียนทับ `data/` การเปิดเว็บจะรัน additive migrations ของฐานข้อมูลตามโค้ดรุ่นนั้น ตรวจเลขเวอร์ชันและฟังก์ชันสำคัญหลังอัปเดตทุกครั้ง

ไฟล์สำรองไม่เข้ารหัส และรวมข้อมูลบัญชี/ข้อมูลส่วนบุคคล ไม่ควรวาง ZIP ไว้ในโฟลเดอร์ที่บุคคลทั่วไปดาวน์โหลดได้

อ้างอิง: [PHP รุ่นที่ยังรองรับ](https://www.php.net/supported-versions.php), [Apache .htaccess](https://httpd.apache.org/docs/2.4/howto/htaccess.html)
