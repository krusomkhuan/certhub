import os, secrets, json, base64
import mysql_support
import tempfile, shutil, subprocess, pathlib, urllib.request, urllib.parse, urllib.error, http.cookiejar, re, time, socket, concurrent.futures
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='certhub-register-') as temp:
    work=pathlib.Path(temp)
    for p in root.glob('*.php'):
        if p.name!='config.php':shutil.copy2(p,work/p.name)
    shutil.copytree(root/'database',work/'database');shutil.copytree(root/'tools',work/'tools')
    shutil.copy2(root/'INSTALL.md',work/'INSTALL.md')
    database='certhub_test_'+secrets.token_hex(8)
    controller=mysql_support.pymysql.connect(**mysql_support.options())
    controller.cursor().execute('CREATE DATABASE `'+database+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin')
    opts=mysql_support.options();cfg={'host':opts['host'],'port':opts['port'],'username':opts['user'],'password':opts['password'],'database':database}
    encoded=base64.b64encode(json.dumps(cfg).encode()).decode()
    (work/'config.php').write_text("<?php return json_decode(base64_decode('"+encoded+"'),true);")
    shutil.copy2(root/'.htaccess',work/'.htaccess');shutil.copy2(root/'README.md',work/'README.md');shutil.copy2(root/'CHANGELOG.md',work/'CHANGELOG.md');shutil.copytree(root/'assets',work/'assets');(work/'data').mkdir();shutil.copy2(root/'data/.htaccess',work/'data/.htaccess')
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base=f'http://127.0.0.1:{port}/';flags=getattr(subprocess,'CREATE_NO_WINDOW',0)
    server=subprocess.Popen(['C:/xampp/php/php.exe','-S',f'127.0.0.1:{port}','-t',str(work)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=flags)
    con=None
    try:
        for _ in range(50):
            try:urllib.request.urlopen(base).read();break
            except urllib.error.URLError:time.sleep(.1)
        client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        def get(path):return client.open(base+path).read().decode()
        def post(path,values):
            form=get(path);csrf=re.search(r'name="csrf" value="([^"]+)"',form).group(1)
            if values.get('action')=='direct_issue':values={**values,'issue_nonce':re.search(r'name="issue_nonce" value="([^"]+)"',form).group(1)}
            return client.open(base+path,urllib.parse.urlencode({**values,'csrf':csrf}).encode()).read().decode()
        post('?page=login',{'action':'setup','username':'registry_tester','password':'Test-only-long-password!'})
        con=mysql_support.Connection(database)
        assert 'คู่มือการใช้งาน' in get('?page=guide')
        for public_page in ['', '?page=request', '?page=guide', '?page=privacy']:
            public_html=get(public_page)
            assert 'href="?page=admins"' in public_html and 'registry_tester' in public_html
            assert 'ออกจากระบบ (Logout)' in public_html
        assert 'href="?page=admins"' in get('?page=dashboard')
        assert 'บันทึกรูปแบบเลขกลาง' in post('?page=register',{'action':'central_numbering','number_pattern':'TEST/{year}/{seq}','number_next':'10','number_width':'4'})
        direct={'action':'direct_issue','title':'งานมอบรางวัล','name':'ผู้รับทดสอบ','owner':'ผู้รับผิดชอบทดสอบ','date':'2026-10-29','event_type':'award','output_mode':'register','heading':'','published':'1'}
        assert 'ไม่มีเอกสาร' in post('?page=register',direct)
        r=con.execute('SELECT certificate_number,central_sequence,record_only,token,project_id FROM recipients').fetchone()
        assert r[1:3]==(10,1)
        assert con.execute('SELECT published FROM projects WHERE id=?',(r[4],)).fetchone()[0]==0
        try:get('?page=certificate&token='+r[3]);raise AssertionError('number-only document exposed')
        except urllib.error.HTTPError as e:assert e.code==404
        assert 'ไม่พบเกียรติบัตร' in get('?q='+urllib.parse.quote(r[0]))
        assert 'ออกเลขที่' in post('?page=register',{**direct,'name':'ผู้รับเอกสาร','output_mode':'certificate'})
        doc=con.execute('SELECT token,central_sequence FROM recipients WHERE record_only=0').fetchone();assert doc[1]==11
        assert 'เพื่อแสดงว่าได้รับเกียรติบัตรในงานนี้' in get('?page=certificate&token='+doc[0])
        doc_project=con.execute('SELECT project_id FROM recipients WHERE token=?',(doc[0],)).fetchone()[0]
        public=urllib.request.build_opener()
        detail=public.open(base+f'?page=public-project&id={doc_project}').read().decode()
        assert 'ค้นหาเกียรติบัตรในโครงการนี้' in detail and 'href="?page=profile"' not in detail
        found=public.open(base+f'?page=public-project&id={doc_project}&q='+urllib.parse.quote('ผู้รับเอกสาร')).read().decode()
        assert 'ดู / ดาวน์โหลด' in found
        found=public.open(base+f'?project_id={doc_project}&q='+urllib.parse.quote('ผู้รับเอกสาร')).read().decode()
        assert 'ดู / ดาวน์โหลด' in found and 'name="project_id"' in found
        assert 'ดู / ดาวน์โหลด' in public.open(base+'?project_id=0&q='+urllib.parse.quote('ผู้รับเอกสาร')).read().decode()
        wrong=public.open(base+'?project_id=999999&q='+urllib.parse.quote('ผู้รับเอกสาร')).read().decode()
        assert 'ดู / ดาวน์โหลด' not in wrong
        try:public.open(base+f'?page=public-project&id={r[4]}');raise AssertionError('unpublished detail visible')
        except urllib.error.HTTPError as e:assert e.code==404
        assert f'?page=public-project&id={doc_project}' in public.open(base).read().decode()
        print('PASS: public project cards/details, anonymous scoped name search, project filter, unpublished project denied')
        post('?page=new',{'action':'project','title':'งานอบรมทดสอบ','date':'2026-10-29','end_date':'2026-10-30','location':'วิทยาลัย','description':'','owner':'ผู้ทดสอบ','event_type':'training'})
        pid=con.execute('SELECT MAX(id) FROM projects').fetchone()[0]
        post(f'?page=project&id={pid}&tab=people',{'action':'recipient','name':'ผู้เข้าอบรม','organization':'','role':'participant'})
        post(f'?page=project&id={pid}',{'action':'issue'})
        assert con.execute('SELECT central_sequence FROM recipients WHERE project_id=?',(pid,)).fetchone()[0]==12
        # Independent processes race for the same shared sequence; SQLite serializes reservations.
        ids=[]
        for i in range(6):
            cur=con.execute("INSERT INTO projects(title,date,end_date,location,owner,event_type) VALUES(?,'2026-10-29','2026-10-29','','test','other')",(f'parallel{i}',));ident=cur.lastrowid;ids.append(ident)
            con.execute("INSERT INTO recipients(project_id,name,role,token,record_only) VALUES(?,'test','general',?,1)",(ident,f'parallel-token-{i}'))
        con.commit()
        php=work/'issue-test.php';php.write_text("<?php require __DIR__.'/bootstrap.php'; issueCertificates((int)$argv[1]);",encoding='utf-8')
        def issue(ident):return subprocess.run(['C:/xampp/php/php.exe',str(php),str(ident)],capture_output=True,text=True,creationflags=flags)
        with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:results=list(pool.map(issue,ids))
        assert all(p.returncode==0 for p in results),[p.stderr for p in results]
        sequences=[r[0] for r in con.execute('SELECT central_sequence FROM recipients ORDER BY central_sequence')]
        assert sequences==list(range(10,19)),sequences
        # Revocation deletes the recipient and returns the number for a new recipient.
        rec=con.execute('SELECT id,certificate_number FROM recipients WHERE project_id=?',(pid,)).fetchone()
        post(f'?page=project&id={pid}',{'action':'revoke','recipient_id':rec[0]})
        before=con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0]
        post(f'?page=project&id={pid}',{'action':'issue'})
        assert con.execute('SELECT certificate_number FROM recipients WHERE id=?',(rec[0],)).fetchone() is None
        con.execute("INSERT INTO recipients(project_id,name,role,token) VALUES(?,'replacement','general','replacement-token')",(pid,))
        post(f'?page=project&id={pid}',{'action':'issue'})
        assert con.execute("SELECT certificate_number FROM recipients WHERE token='replacement-token'").fetchone()[0]==rec[1]
        assert con.execute("SELECT COUNT(*) FROM settings WHERE LEFT(name,16)='released_number_'").fetchone()[0]==0
        assert con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0]==before
        assert 'ห้ามย้อนลำดับ' in post('?page=register',{'action':'central_numbering','number_pattern':'OTHER/{year}/{seq}','number_next':'1','number_width':'4'})
        # A legacy certificate collision rolls back the entire new direct issuance.
        next_num='TEST/2569/'+str(before).zfill(4)
        con.execute("INSERT INTO recipients(project_id,name,role,token,certificate_number,issued) VALUES(?,'legacy','general','legacy-token',?,1)",(pid,next_num));con.commit()
        count=con.execute('SELECT COUNT(*) FROM projects').fetchone()[0]
        assert 'เลขที่ซ้ำ' in post('?page=register',direct)
        assert con.execute('SELECT COUNT(*) FROM projects').fetchone()[0]==count
        assert con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0]==before
        # Database constraints remain a second line of defense.
        try:con.execute("UPDATE recipients SET certificate_number=? WHERE token='parallel-token-0'",(rec[1],));raise AssertionError('duplicate accepted')
        except mysql_support.IntegrityError:con.rollback()
        # Restore a noncolliding pattern for request approval tests.
        post('?page=register',{'action':'central_numbering','number_pattern':'REQTEST/{year}/{seq}','number_next':before,'number_width':'4'})
        # The sequence restarts at 1 for the first issuance of a new Buddhist year.
        year=time.localtime().tm_year+543
        con.execute('UPDATE recipients SET sequence_year=sequence_year-1 WHERE sequence_year IS NOT NULL');con.execute("UPDATE settings SET value=? WHERE name='central_year'",(str(year-1),));con.commit()
        assert f'REQTEST/{year}/0001' in get('?page=register')
        post('?page=register',{**direct,'names':'New Year One\nNew Year Two'})
        fresh=con.execute("SELECT certificate_number,central_sequence,sequence_year FROM recipients WHERE name IN ('New Year One','New Year Two') ORDER BY id").fetchall()
        assert fresh==((f'REQTEST/{year}/0001',1,year),(f'REQTEST/{year}/0002',2,year)),fresh
        assert con.execute("SELECT value FROM settings WHERE name IN ('central_next','central_year') ORDER BY name").fetchall()==(('3',),(str(year),))
        print('PASS: central sequence restarts at 0001 in a new Buddhist year')
        anon=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        form=anon.open(base+'?page=request').read().decode();csrf=re.search(r'name="csrf" value="([^"]+)"',form).group(1)
        pending_before=con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0]
        request_data={'action':'submit_request','csrf':csrf,'requester':'ผู้ขอ','department':'แผนกทดสอบ','contact':'test@example.test','title':'ขอเลขกิจกรรม','purpose':'ทดสอบคำร้อง','event_type':'activity','event_date':'2026-10-29','quantity':'2','names':'ข้อมูลชื่อนี้ต้องไม่ถูกบันทึก'}
        response=anon.open(base+'?page=request',urllib.parse.urlencode(request_data).encode());receipt=response.read().decode()
        request=con.execute('SELECT id,token,status FROM certificate_requests').fetchone();assert request[2]=='pending'
        assert con.execute('SELECT names,quantity FROM certificate_requests').fetchone()==('[]',2)
        assert con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0]==pending_before
        assert 'รอพิจารณา' in receipt
        assert 'อนุมัติและออกเลขกลาง' in post('?page=requests',{'action':'review_request','request_id':request[0],'decision':'approved','review_note':'ตรวจสอบแล้ว'})
        approved=con.execute('SELECT status,project_id FROM certificate_requests WHERE id=?',(request[0],)).fetchone();assert approved[0]=='approved'
        assigned=con.execute('SELECT certificate_number,record_only FROM recipients WHERE project_id=?',(approved[1],)).fetchall();assert len(assigned)==2 and all(x[1]==1 for x in assigned)
        next_after=con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0]
        assert 'ถูกพิจารณาแล้ว' in post('?page=requests',{'action':'review_request','request_id':request[0],'decision':'approved'})
        assert con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0]==next_after
        receipt=anon.open(base+'?page=request&token='+request[1]).read().decode();assert all(x[0] in receipt for x in assigned)
        # Named recipients added later take the request's reserved numbers in order instead of new ones.
        reserved_next=con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0]
        for person in ['Reserved First','Reserved Second','Reserved Third']:post(f'?page=project&id={approved[1]}&tab=people',{'action':'recipient','name':person,'organization':'','role':'participant'})
        post(f'?page=project&id={approved[1]}',{'action':'issue'})
        named=con.execute('SELECT name,certificate_number FROM recipients WHERE project_id=? AND record_only=0 ORDER BY id',(approved[1],)).fetchall()
        assert [x[1] for x in named[:2]]==sorted(x[0] for x in assigned) and named[2][1] not in [x[0] for x in assigned],named
        assert con.execute('SELECT COUNT(*) FROM recipients WHERE project_id=? AND record_only=1',(approved[1],)).fetchone()[0]==0
        assert int(con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0])==int(reserved_next)+1
        receipt=anon.open(base+'?page=request&token='+request[1]).read().decode();assert all(x[0] in receipt for x in assigned)
        print('PASS: reserved request numbers go to named recipients first, then new numbers')
        # Bulk removal needs confirmation, releases each number, and new names reuse them lowest first.
        page_url=f'?page=project&id={approved[1]}&tab=people'
        def bulk(ids,confirm):
            html=get(page_url);token=re.search(r'name="csrf" value="([^"]+)"',html).group(1)
            assert 'data-person-selection' in html
            fields=[('csrf',token),('action','revoke_many')]+[('recipient_ids[]',str(i)) for i in ids]+([('confirm_delete','1')] if confirm else [])
            return client.open(base+f'?page=project&id={approved[1]}',urllib.parse.urlencode(fields).encode()).read().decode()
        doomed=con.execute('SELECT id,certificate_number FROM recipients WHERE project_id=? AND record_only=0 ORDER BY id LIMIT 2',(approved[1],)).fetchall()
        assert 'กรุณายืนยันการลบรายชื่อ' in bulk([x[0] for x in doomed],False)
        assert 'ลบรายชื่อ 2 คน คืนเลขที่ 2 เลข' in bulk([doomed[1][0],doomed[0][0]],True)
        assert con.execute("SELECT COUNT(*) FROM settings WHERE LEFT(name,16)='released_number_'").fetchone()[0]==2
        for person in ['Bulk New One','Bulk New Two']:post(page_url,{'action':'recipient','name':person,'organization':'','role':'participant'})
        post(f'?page=project&id={approved[1]}',{'action':'issue'})
        renamed=con.execute("SELECT certificate_number FROM recipients WHERE project_id=? AND name IN ('Bulk New One','Bulk New Two') ORDER BY id",(approved[1],)).fetchall()
        assert [x[0] for x in renamed]==sorted(x[1] for x in doomed),renamed
        assert con.execute("SELECT COUNT(*) FROM settings WHERE LEFT(name,16)='released_number_'").fetchone()[0]==0
        print('PASS: bulk recipient removal with confirmation; released numbers reissued lowest first')
        # A published request work shows its number range publicly.
        post(f'?page=project&id={approved[1]}',{'action':'publish','published':'1'})
        span=con.execute('SELECT certificate_number FROM recipients WHERE project_id=? AND certificate_number IS NOT NULL ORDER BY sequence_year,central_sequence',(approved[1],)).fetchall()
        detail=urllib.request.build_opener().open(base+f'?page=public-project&id={approved[1]}').read().decode()
        assert f'จากเลขที่ <strong>{span[0][0]}</strong> ถึงเลขที่ <strong>{span[-1][0]}</strong> จำนวน {len(span)} เลข' in detail,detail
        print('PASS: public project page shows issued number range')
        try:anon.open(base+'?page=request&token=missing');raise AssertionError('missing token accepted')
        except urllib.error.HTTPError as e:assert e.code==404
        unauthorized=anon.open(base+'?page=requests').read().decode();assert 'ยินดีต้อนรับกลับ' in unauthorized
        # Role checks: registry staff can issue; report viewer is read-only.
        for login,role in [('affairs_test','registry'),('director_test','report_viewer')]:
            assert 'เพิ่มผู้ดูแลระบบสำรองแล้ว' in post('?page=admins',{'action':'add_admin','username':login,'display_name':login,'email':'','role':role,'new_password':'Test-account-password!','confirm_password':'Test-account-password!','current_password':'Test-only-long-password!'})
            actor=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            login_html=actor.open(base+'?page=login').read().decode();token=re.search(r'name="csrf" value="([^"]+)"',login_html).group(1)
            result=actor.open(base+'?page=login',urllib.parse.urlencode({'action':'login','csrf':token,'username':login,'password':'Test-account-password!'}).encode());html=result.read().decode()
            assert ('?page=register' if role=='registry' else '?page=reports') in result.url
            assert 'รายงานสำหรับผู้บริหาร' in actor.open(base+'?page=reports').read().decode()
            for public_page in ['', '?page=request', '?page=guide', '?page=privacy']:
                public_html=actor.open(base+public_page).read().decode()
                assert 'href="?page=profile"' in public_html and 'ออกจากระบบ (Logout)' in public_html
                for hidden in ['?page=settings','?page=admins']+(['?page=register','?page=requests'] if role=='report_viewer' else []):
                    assert 'href="'+hidden+'"' not in public_html,(public_page,hidden)
                assert login in public_html
            assert 'href="?page=profile"' in actor.open(base+'?page=reports').read().decode()
            for forbidden in ['settings','admins','new']+(['register','requests'] if role=='report_viewer' else []):
                try:actor.open(base+'?page='+forbidden);raise AssertionError(role+' accessed '+forbidden)
                except urllib.error.HTTPError as e:assert e.code==403
            profile_html=actor.open(base+'?page=profile').read().decode();csrf=re.search(r'name="csrf" value="([^"]+)"',profile_html).group(1)
            try:actor.open(base+'?page=profile',urllib.parse.urlencode({'action':'backup','csrf':csrf}).encode());raise AssertionError('backup access granted')
            except urllib.error.HTTPError as e:assert e.code==403
        next_before=int(con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0])
        assert 'จำนวน 3 เลข' in post('?page=register',{**direct,'names':'Batch One\nBatch Two\nBatch Three'})
        latest=con.execute('SELECT MAX(id) FROM projects').fetchone()[0]
        seqs=[x[0] for x in con.execute('SELECT central_sequence FROM recipients WHERE project_id=? ORDER BY id',(latest,))]
        assert seqs==list(range(next_before,next_before+3))
        assert 'ชื่อซ้ำ' in post('?page=register',{**direct,'names':'Repeated\nRepeated'})
        assert int(con.execute("SELECT value FROM settings WHERE name='central_next'").fetchone()[0])==next_before+3
        print('PASS: bulk direct numbering contiguous, duplicate names rejected without consuming numbers')
        print('PASS: staff and deputy roles, role-specific login, reports access, forbidden pages and backup mutation blocked')
        print('PASS: request submission without allocation, approval, number-only results, duplicate approval blocked, private tracking token, admin-only review')
        # Admin deletion requires explicit confirmation and keeps allocated numbers reserved.
        doomed=con.execute("INSERT INTO projects(title,date,end_date,owner) VALUES('delete fixture','2026-10-30','2026-10-30','test')").lastrowid
        con.execute("INSERT INTO templates(project_id,role,heading) VALUES(?,'general','test')",(doomed,))
        con.execute("INSERT INTO recipients(project_id,name,role,token,certificate_number,issued) VALUES(?,'delete recipient','general','delete-token','DELETE-RESERVED',1)",(doomed,))
        assert 'กรุณายืนยัน' in post('?page=dashboard',{'action':'delete_project','project_id':doomed})
        assert con.execute('SELECT id FROM projects WHERE id=?',(doomed,)).fetchone()
        assert 'ลบโครงการ' in post('?page=dashboard',{'action':'delete_project','project_id':doomed,'confirm_delete':'1'})
        assert con.execute('SELECT id FROM projects WHERE id=?',(doomed,)).fetchone() is None
        for table in ['recipients','templates']:
            assert con.execute('SELECT COUNT(*) FROM '+table+' WHERE project_id=?',(doomed,)).fetchone()[0]==0
        assert con.execute("SELECT COUNT(*) FROM settings WHERE value='DELETE-RESERVED'").fetchone()[0]==1
        print('PASS: confirmed admin project deletion, cascade cleanup and certificate number reservation')
        bulk_ids=[con.execute("INSERT INTO projects(title,date,end_date,owner) VALUES('bulk deletion','2026-10-30','2026-10-30','test')").lastrowid for _ in range(2)]
        bulk={'action':'delete_projects','confirm_delete':'1','project_ids[0]':bulk_ids[0],'project_ids[1]':bulk_ids[1]}
        # A nonexistent member rolls the whole batch back.
        post('?page=dashboard',{**bulk,'project_ids[2]':999999999})
        assert all(con.execute('SELECT id FROM projects WHERE id=?',(i,)).fetchone() for i in bulk_ids)
        assert '2 โครงการ' in post('?page=dashboard',bulk)
        assert all(con.execute('SELECT id FROM projects WHERE id=?',(i,)).fetchone() is None for i in bulk_ids)
        viewer=actor
        csrf=re.search(r'name="csrf" value="([^"]+)"',viewer.open(base+'?page=profile').read().decode()).group(1)
        try:viewer.open(base+'?page=reports',urllib.parse.urlencode({**bulk,'csrf':csrf}).encode());raise AssertionError('viewer can bulk delete')
        except urllib.error.HTTPError as e:assert e.code==403
        print('PASS: bulk project deletion, atomic rollback for invalid selection, non-admin denied')
        issued_page=get('?page=issued-numbers')
        assert 'เลขที่เกียรติบัตรที่ขอแล้ว' in issued_page and rec[1] in issued_page
        assert 'id="direct-issue"' not in issued_page and 'name="number_pattern"' not in issued_page
        assert 'ยินดีต้อนรับกลับ' in anon.open(base+'?page=issued-numbers').read().decode()
        assert 'ยังไม่มีเลขที่ตรงกับการค้นหา' in get('?page=issued-numbers&search=NONEXISTENT-NUMBER')
        print('PASS: issued-number menu, existing records, search and anonymous access protection')
        status_html=get('?page=register')
        highest=con.execute('SELECT certificate_number FROM recipients WHERE certificate_number IS NOT NULL AND issued=1 ORDER BY sequence_year DESC,central_sequence DESC,issued_at DESC,id DESC LIMIT 1').fetchone()[0]
        assert 'ขณะนี้ออกเลขถึง: '+highest in status_html
        assert 'เลขที่จะออกถัดไป:' in status_html
        assert 'ขณะนี้ออกเลขถึง:' in get('?page=requests')
        assert 'ขณะนี้ออกเลขถึง:' in get(f'?page=project&id={pid}&tab=people')
        print('PASS: current highest issued number and next allocation displayed before issuing')
        # Public search ignores the title prefix and spacing, accepts parts of a name and small typos,
        # but needs at least 3 characters so a bare title cannot list everyone.
        post('?page=register',{**direct,'names':'นายทดสอบ  ค้นหาชื่อ','output_mode':'certificate','published':'1'})
        visitor=urllib.request.build_opener()
        for typed in ['ทดสอบ ค้นหาชื่อ','นายทดสอบ ค้นหาชื่อ','  ทดสอบ   ค้นหาชื่อ ','ค้นหาชื่อ','ทดสอบ','ทดสอบ ค้นหาชือ','ค้นหาชือ']:
            assert 'นายทดสอบ  ค้นหาชื่อ' in visitor.open(base+'?q='+urllib.parse.quote(typed)).read().decode(),typed
        for rejected in ['นาย','ทด','%','นางสาว']:
            assert 'นายทดสอบ  ค้นหาชื่อ' not in visitor.open(base+'?q='+urllib.parse.quote(rejected)).read().decode(),rejected
        print('PASS: public search ignores title prefix and spacing, finds partial and similar names, needs 3+ characters')
        regression=subprocess.run(['python',str(root/'tests/integration.py')],env={**os.environ,'PYTHONIOENCODING':'utf-8','CERTHUB_TEST_DATABASE':database,'CERTHUB_TEST_ROOT':str(work),'CERTHUB_TEST_URL':base},capture_output=True,text=True,encoding='utf-8',creationflags=flags)
        assert regression.returncode==0,regression.stdout+regression.stderr
        print(regression.stdout.strip())
        for extra in ['profile.py','admins.py','migration.py']:
            check=subprocess.run(['python',str(root/'tests'/extra)],env={**os.environ,'PYTHONIOENCODING':'utf-8','CERTHUB_TEST_DATABASE':database,'CERTHUB_TEST_ROOT':str(work),'CERTHUB_TEST_URL':base},capture_output=True,text=True,encoding='utf-8',creationflags=flags)
            assert check.returncode==0,check.stdout+check.stderr
            print(check.stdout.strip())
        assert 'ออกจากระบบ (Logout)' not in post('?page=guide',{'action':'logout'})
        assert 'ยินดีต้อนรับกลับ' in get('?page=register')
        assert 'href="?page=admins"' not in get('')
        print('PASS: authenticated navigation retains account and role menus; explicit logout removes access')
        print('PASS: isolated DB; central menu; number-only without document; generated document; training shares counter; six simultaneous issuers; immutable/revoked numbers; no rewind; legacy collision atomic rollback; unique DB constraint; guide')
    finally:
        if con:con.close()
        server.terminate();server.wait(timeout=10)
        controller.cursor().execute('DROP DATABASE `'+database+'`');controller.close()
