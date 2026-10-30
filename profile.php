<?php if(!isset($db) || !admin()){http_response_code(403);exit;} ?>
<div class="page-heading"><div><p class="eyebrow">MY ACCOUNT</p><h1>แก้ไขโปรไฟล์</h1><p class="muted">จัดการข้อมูลส่วนตัวและข้อมูลเข้าสู่ระบบของคุณ</p></div></div>
<form method="post" class="card form-card"><?=csrf()?><input type="hidden" name="action" value="profile">
<h2>ข้อมูลผู้ดูแลระบบ</h2>
<label>ชื่อที่แสดง<input name="display_name" maxlength="100" required autocomplete="name" value="<?=e($_POST['display_name']??($currentAdmin['display_name']?:$currentAdmin['username']))?>"></label>
<label>อีเมลติดต่อ (ไม่บังคับ)<input type="email" name="email" maxlength="254" autocomplete="email" value="<?=e($_POST['email']??$currentAdmin['email'])?>"></label><p class="muted">อีเมลนี้ใช้บันทึกข้อมูลติดต่อ ยังไม่ใช่ช่องทางกู้คืนรหัสผ่าน</p>
<div class="divider"></div><h2>ข้อมูลเข้าสู่ระบบ</h2>
<label>ชื่อผู้ใช้<input name="username" required minlength="3" maxlength="100" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?=e($_POST['username']??$currentAdmin['username'])?>"></label>
<label>รหัสผ่านปัจจุบัน<input type="password" name="current_password" autocomplete="current-password" maxlength="1024"></label><p class="muted">จำเป็นเมื่อเปลี่ยนชื่อผู้ใช้หรือรหัสผ่าน</p>
<div class="form-grid"><label>รหัสผ่านใหม่<input type="password" name="new_password" minlength="12" maxlength="72" autocomplete="new-password"></label><label>ยืนยันรหัสผ่านใหม่<input type="password" name="confirm_password" minlength="12" maxlength="72" autocomplete="new-password"></label></div>
<p class="info">เว้นรหัสผ่านใหม่ว่างไว้หากไม่ต้องการเปลี่ยน รหัสผ่านใหม่อย่างน้อย 12 ตัวอักษรและไม่เกิน 72 ไบต์ เมื่อเปลี่ยนชื่อผู้ใช้หรือรหัสผ่าน ระบบจะยกเลิกเซสชันในเครื่องอื่น</p><button>บันทึกโปรไฟล์</button>
</form>
