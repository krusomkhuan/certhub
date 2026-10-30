"""Test-only DB adapter; every automated database uses a certhub_test_ prefix."""
import os, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'data' / 'test-deps'))
import pymysql
IntegrityError = pymysql.IntegrityError

def options():
    return dict(host=os.environ.get('CERTHUB_TEST_MYSQL_HOST','127.0.0.1'),
                port=int(os.environ.get('CERTHUB_TEST_MYSQL_PORT','3306')),
                user=os.environ.get('CERTHUB_TEST_MYSQL_USERNAME','root'),
                password=os.environ.get('CERTHUB_TEST_MYSQL_PASSWORD',''),
                charset='utf8mb4', autocommit=True)

class Connection:
    def __init__(self, name=None):
        name = name or os.environ['CERTHUB_TEST_DATABASE']
        if not name.startswith('certhub_test_') or not name.replace('_','').isalnum():
            raise ValueError('Refusing to test against a non-test database')
        self.db = pymysql.connect(database=name, **options())
    def execute(self, sql, args=()):
        cursor = self.db.cursor()
        cursor.execute(sql.replace('?', '%s'), args)
        return cursor
    def executemany(self, sql, args):
        cursor = self.db.cursor();cursor.executemany(sql.replace('?', '%s'), args);return cursor
    def commit(self): self.db.commit()
    def rollback(self): self.db.rollback()
    def close(self): self.db.close()
