"""Verify migration of a populated legacy fixture, including number/hash identity."""
import os, sqlite3, subprocess
from pathlib import Path
import mysql_support

if 'CERTHUB_TEST_ROOT' not in os.environ:
    raise SystemExit('Run python tests/registry.py for isolated tests')
root=Path(os.environ['CERTHUB_TEST_ROOT'])
source=mysql_support.Connection()
legacy=sqlite3.connect(root/'data/legacy-fixture.sqlite')
tables=['admins','projects','templates','recipients','photos','settings','certificate_requests']
for table in tables:
    columns=source.execute('SHOW COLUMNS FROM `'+table+'`').fetchall()
    definitions=','.join('"'+row[0]+'" '+('INTEGER' if 'int' in row[1].lower() else 'TEXT') for row in columns)
    legacy.execute('CREATE TABLE "'+table+'" ('+definitions+')')
    rows=source.execute('SELECT * FROM `'+table+'`').fetchall()
    if rows:legacy.executemany('INSERT INTO "'+table+'" VALUES ('+','.join('?' for _ in columns)+')',rows)
legacy.commit();legacy.close()
target_name=os.environ['CERTHUB_TEST_DATABASE']+'_migrate'
controller=mysql_support.pymysql.connect(**mysql_support.options())
controller.cursor().execute('CREATE DATABASE `'+target_name+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin')
target=None
try:
    env={**os.environ,'CERTHUB_DB_DATABASE':target_name}
    cmd=['C:/xampp/php/php.exe',str(root/'tools/migrate-sqlite.php'),str(root/'data/legacy-fixture.sqlite')]
    result=subprocess.run(cmd,env=env,capture_output=True,text=True,encoding='utf-8')
    assert result.returncode==0,result.stdout+result.stderr
    target=mysql_support.Connection(target_name)
    for table in tables:
        key='name' if table=='settings' else 'id'
        assert source.execute('SELECT * FROM `'+table+'` ORDER BY `'+key+'`').fetchall()==target.execute('SELECT * FROM `'+table+'` ORDER BY `'+key+'`').fetchall(),table
    second=subprocess.run(cmd,env=env,capture_output=True,text=True,encoding='utf-8')
    assert second.returncode!=0 and 'not empty' in second.stderr
    print('PASS: populated SQLite migration preserves every row, credentials, certificates and tokens; rerun refuses nonempty destination')
finally:
    source.close()
    if target:target.close()
    controller.cursor().execute('DROP DATABASE `'+target_name+'`');controller.close()
