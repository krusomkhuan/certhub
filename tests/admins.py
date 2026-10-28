import urllib.request, urllib.parse, urllib.error, http.cookiejar, sqlite3, subprocess, secrets, re
from pathlib import Path
root=Path(__file__).resolve().parents[1];base='http://localhost/certhub/'
# Initialize additive migrations before inserting isolated test accounts.
urllib.request.urlopen(base).read()
con=sqlite3.connect(root/'data/certhub.sqlite');name='profile_test_'+secrets.token_hex(5);other=name+'_other';password=secrets.token_urlsafe(18);newpass=secrets.token_urlsafe(18)
hash_value=subprocess.check_output(['C:/xampp/php/php.exe','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',password],text=True)
ids=[]
for username in [name,other]:
    ids.append(con.execute('INSERT INTO admins(username,password) VALUES(?,?)',(username,hash_value)).lastrowid)
con.commit()
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
a=client();b=client()
def get(c,path):return c.open(base+path).read().decode('utf-8-sig')
def post(c,path,data):
    csrf=re.search(r'name="csrf" value="([^"]+)"',get(c,path)).group(1)
    return c.open(base+path,urllib.parse.urlencode({**data,'csrf':csrf}).encode()).read().decode('utf-8-sig')
def profile(**kw):return {'action':'profile','display_name':'ผู้ดูแลทดสอบ','email':'test@example.test','username':name,'current_password':'','new_password':'','confirm_password':'',**kw}
created=name+'_backup'
def payload(**kw):return {'action':'add_admin','display_name':'ผู้ดูแลสำรองทดสอบ','email':'backup@example.test','username':created,'new_password':newpass,'confirm_password':newpass,'current_password':password,**kw}
try:
    assert 'ภาพรวมระบบ' in post(a,'?page=login',{'action':'login','username':name,'password':password})
    assert 'รหัสผ่านปัจจุบันของคุณไม่ถูกต้อง' in post(a,'?page=admins',payload(current_password='wrong'))
    assert not con.execute('SELECT id FROM admins WHERE username=?',(created,)).fetchone()
    assert 'ไม่ตรงกัน' in post(a,'?page=admins',payload(confirm_password='wrong'))
    assert 'รูปแบบอีเมลไม่ถูกต้อง' in post(a,'?page=admins',payload(email='bad'))
    assert 'เพิ่มผู้ดูแลระบบสำรองแล้ว' in post(a,'?page=admins',payload())
    row=con.execute('SELECT id,password,email FROM admins WHERE username=?',(created,)).fetchone()
    assert row and row[1]!=newpass and row[2]=='backup@example.test'
    assert 'มีผู้ใช้งานแล้ว' in post(a,'?page=admins',payload())
    assert 'ภาพรวมระบบ' in post(b,'?page=login',{'action':'login','username':created,'password':newpass})
    assert 'เพิ่มผู้ดูแลระบบสำรอง' in get(b,'?page=admins')
    assert name in get(a,'?page=profile')
    assert 'ยินดีต้อนรับกลับ' in get(client(),'?page=admins')
    before=con.execute('SELECT COUNT(*) FROM admins').fetchone()[0]
    a.open(base+'?page=admins',urllib.parse.urlencode({**payload(username=created+'_bad'),'csrf':'invalid'}).encode()).read()
    assert con.execute('SELECT COUNT(*) FROM admins').fetchone()[0]==before
    print('PASS: admin creation, current password, email, confirmation, duplicate username, password hash, new login, existing session, anonymous protection, CSRF')
finally:
    con.execute('DELETE FROM admins WHERE username=?',(created,))
    con.executemany('DELETE FROM admins WHERE id=?',[(i,) for i in ids]);con.commit();con.close()
