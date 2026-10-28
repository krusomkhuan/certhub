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
try:
    for c in [a,b]:assert 'ภาพรวมระบบ' in post(c,'?page=login',{'action':'login','username':name,'password':password})
    assert 'บันทึกโปรไฟล์แล้ว' in post(a,'?page=profile',profile())
    assert con.execute('SELECT display_name FROM admins WHERE id=?',(ids[0],)).fetchone()[0]=='ผู้ดูแลทดสอบ'
    assert 'รูปแบบอีเมล' in post(a,'?page=profile',profile(email='invalid'))
    assert 'รหัสผ่านปัจจุบันไม่ถูกต้อง' in post(a,'?page=profile',profile(username=name+'_new'))
    assert 'มีผู้ใช้งานแล้ว' in post(a,'?page=profile',profile(username=other,current_password=password))
    assert 'ไม่ตรงกัน' in post(a,'?page=profile',profile(new_password=newpass,confirm_password='mismatch',current_password=password))
    assert 'บันทึกโปรไฟล์แล้ว' in post(a,'?page=profile',profile(username=name+'_new',new_password=newpass,confirm_password=newpass,current_password=password))
    assert 'ยินดีต้อนรับกลับ' in get(b,'?page=profile')
    assert 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง' in post(b,'?page=login',{'action':'login','username':name+'_new','password':password})
    assert 'ภาพรวมระบบ' in post(b,'?page=login',{'action':'login','username':name+'_new','password':newpass})
    assert con.execute('SELECT username FROM admins WHERE id=?',(ids[1],)).fetchone()[0]==other
    assert 'ยินดีต้อนรับกลับ' in get(client(),'?page=profile')
    print('PASS: profile save, email validation, current password, duplicate username, confirmation, credential update, old password rejected, other sessions invalidated, account isolation, anonymous access')
finally:
    con.executemany('DELETE FROM admins WHERE id=?',[(i,) for i in ids]);con.commit();con.close()
