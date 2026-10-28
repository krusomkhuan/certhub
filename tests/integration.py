import os, time
import zipfile, json, hashlib
import urllib.request, urllib.parse, http.cookiejar, re, sqlite3, subprocess, secrets, io, base64
from pathlib import Path
if 'CERTHUB_TEST_ROOT' not in os.environ: raise SystemExit('Run python tests/registry.py to use an isolated test database')
root=Path(os.environ['CERTHUB_TEST_ROOT'])
base=os.environ['CERTHUB_TEST_URL']
con=sqlite3.connect(root/'data/certhub.sqlite')
username='test_'+secrets.token_hex(8)
password=secrets.token_urlsafe(24)
hash_value=subprocess.check_output(['C:/xampp/php/php.exe','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',password],text=True)
con.execute('INSERT INTO admins(username,password) VALUES(?,?)',(username,hash_value));con.commit()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
project=None
original_settings=None
files=[]
def get(url,opener=client):
    return opener.open(base+url).read().decode('utf-8-sig')
def post(url,values):
    html=get(url); values['csrf']=re.search(r'name="csrf" value="([^"]+)"',html).group(1)
    return client.open(base+url,urllib.parse.urlencode(values).encode()).read().decode('utf-8-sig')
def upload(url,action,field,filename,data,mime,extra={}):
    html=get(url); values={'csrf':re.search(r'name="csrf" value="([^"]+)"',html).group(1),'action':action,**extra}
    boundary='----'+secrets.token_hex(16); parts=[]
    for k,v in values.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode()+data+b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    req=urllib.request.Request(base+url,data=b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary})
    return client.open(req).read().decode('utf-8-sig')
try:
    assert 'ภาพรวมระบบ' in post('?page=login',{'action':'login','username':username,'password':password})
    original_settings=list(con.execute('SELECT name,value FROM settings'))
    html=post('?page=new',{'action':'project','title':username,'date':'2026-10-29','end_date':'2026-10-31','location':'ห้องทดสอบ','description':'รายละเอียดทดสอบ','owner':'ผู้รับผิดชอบทดสอบ'})
    project=con.execute('SELECT id FROM projects WHERE title=?',(username,)).fetchone()[0]
    assert con.execute('SELECT COUNT(*) FROM templates WHERE project_id=?',(project,)).fetchone()[0]==4
    url=f'?page=project&id={project}'
    assert con.execute('SELECT end_date FROM projects WHERE id=?',(project,)).fetchone()[0]=='2026-10-31'
    invalid=post(url+'&tab=edit',{'action':'project','title':username,'date':'2026-10-29','end_date':'2026-10-28','location':'test','description':'','owner':'test'})
    assert 'วันสิ้นสุดต้อง' in invalid
    post('?page=register',{'action':'central_numbering','number_pattern':username+'/{year}/{seq}','number_next':str(max(25,int(con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0]))),'number_width':'4'})
    post('?page=settings',{'action':'settings','public_url':'http://localhost/certhub','privacy_contact':'test@example.test','privacy_basis':'ฐานทดสอบ','privacy_published':'1'})
    assert 'test@example.test' in get('?page=privacy')
    csv='name,organization,role\r\nทดสอบ ผู้เข้าอบรม,วิทยาลัย,participant\r\nทดสอบ วิทยากร,วิทยาลัย,speaker\r\nทดสอบ กรรมการ,วิทยาลัย,committee\r\n'.encode('utf-8-sig')
    upload(url+'&tab=people','import','csv','people.csv',csv,'text/csv')
    upload(url+'&tab=people','import','csv','people.csv',csv,'text/csv')
    assert con.execute('SELECT COUNT(*) FROM recipients WHERE project_id=?',(project,)).fetchone()[0]==3
    post(url,{'action':'issue'});post(url,{'action':'publish','published':'1'})
    anon=urllib.request.build_opener()
    assert 'ดู / ดาวน์โหลด' in get('?q='+urllib.parse.quote('ทดสอบ ผู้เข้าอบรม'),anon)
    rid,token=con.execute('SELECT id,token FROM recipients WHERE project_id=? LIMIT 1',(project,)).fetchone()
    cert_html=get('?page=certificate&token='+token,anon)
    assert 'certificate-qr' in cert_html and '31/10/2569' in cert_html
    number=con.execute('SELECT certificate_number FROM recipients WHERE id=?',(rid,)).fetchone()[0]
    assert number.startswith(username+'/2569/')
    assert 'ดู / ดาวน์โหลด' in get('?q='+urllib.parse.quote(number),anon)
    post('?page=register',{'action':'central_numbering','number_pattern':'CHANGED-{seq}','number_next':str(max(100,int(con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0]))),'number_width':'3'})
    post(url,{'action':'issue'})
    assert con.execute('SELECT certificate_number FROM recipients WHERE id=?',(rid,)).fetchone()[0]==number
    post(url+'&tab=report',{'action':'report','objective':'วัตถุประสงค์ทดสอบ','results':'ผลการอบรมทดสอบ','problems':'ไม่มี','suggestions':'จัดต่อเนื่อง'})
    assert 'ผลการอบรมทดสอบ' in get(f'?page=report&id={project}')
    png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1sAAAAASUVORK5CYII=')
    upload(url+'&tab=report','photo','photo','test.png',png,'image/png',{'caption':'ภาพกิจกรรมทดสอบ'})
    files=[r[0] for r in con.execute('SELECT filename FROM photos WHERE project_id=?',(project,))]
    assert len(files)==1 and 'ภาพกิจกรรมทดสอบ' in get(f'?page=report&id={project}')
    assert '<svg' in get(f'template.php?id={project}&role=participant')
    settings_html=get('?page=settings')
    csrf=re.search(r'name="csrf" value="([^"]+)"',settings_html).group(1)
    response=client.open(base+'?page=settings',urllib.parse.urlencode({'action':'backup','csrf':csrf}).encode())
    assert response.headers.get_content_type()=='application/zip'
    archive=zipfile.ZipFile(io.BytesIO(response.read()))
    assert archive.testzip() is None
    restored=sqlite3.connect(':memory:');restored.deserialize(archive.read('data/certhub.sqlite'))
    assert restored.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
    assert restored.execute('SELECT certificate_number FROM recipients WHERE id=?',(rid,)).fetchone()[0]==number
    restored.close()
    manifest=json.loads(archive.read('manifest.json'))
    for name,digest in manifest['sha256'].items():assert hashlib.sha256(archive.read(name)).hexdigest()==digest
    assert 'data/'+files[0] in archive.namelist()
    for _ in range(20):
        if not list((root/'data').glob('backup-*')):break
        time.sleep(0.05) # Response bytes may arrive before server-side finally cleanup.
    assert not list((root/'data').glob('backup-*'))
    assert 'ฉบับร่าง' not in get('?page=privacy')
    post(url,{'action':'revoke','recipient_id':rid})
    try:get('?page=certificate&token='+token,anon);raise AssertionError('revoked certificate visible')
    except urllib.error.HTTPError as e:assert e.code==404
    assert 'Require all denied' in (root/'data/.htaccess').read_text() # Built-in test server does not apply Apache rules
    before=con.execute('SELECT COUNT(*) FROM recipients WHERE project_id=?',(project,)).fetchone()[0]
    client.open(base+url,urllib.parse.urlencode({'action':'recipient','name':'Bad','role':'speaker','organization':'x','csrf':'invalid'}).encode()).read()
    assert con.execute('SELECT COUNT(*) FROM recipients WHERE project_id=?',(project,)).fetchone()[0]==before
    post(url+'&tab=people',{'action':'recipient','name':'collision test','organization':'','role':'participant'})
    next_no=int(con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0])
    con.execute('INSERT INTO recipients(project_id,name,role,token,certificate_number,issued) VALUES(?,?,?,?,?,1)',(project,'legacy collision','participant',secrets.token_hex(16),'CHANGED-'+str(next_no).zfill(3)));con.commit()
    assert 'เลขที่ซ้ำ' in post(url,{'action':'issue'})
    assert con.execute('SELECT certificate_number FROM recipients WHERE project_id=? AND name=?',(project,'collision test')).fetchone()[0] is None
    try:get('backup.php',anon);raise AssertionError('backup directly accessible')
    except urllib.error.HTTPError as e:assert e.code==403
    print('PASS: configurable numbers and immutability, public number search, date range/validation, QR markup, PDPA settings, ZIP/manifest/media/cleanup, login, project/owner, 4 automatic templates, CSV import/deduplication, issue, publish, public search, certificate, report, image upload, SVG, revoke, database protection, CSRF')
finally:
    if project:
        for table in ['photos','recipients','templates']:con.execute(f'DELETE FROM {table} WHERE project_id=?',(project,))
        con.execute('DELETE FROM projects WHERE id=?',(project,))
    if original_settings is not None:
        con.execute('DELETE FROM settings');con.executemany('INSERT INTO settings(name,value) VALUES(?,?)',original_settings)
    con.execute('DELETE FROM admins WHERE username=?',(username,));con.commit();con.close()
    for name in files:(root/'data'/name).unlink(missing_ok=True)
