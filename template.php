<?php
require __DIR__.'/bootstrap.php';
if(!admin()) {http_response_code(403);exit;}
$t=rows('SELECT * FROM templates WHERE project_id=? AND role=?',[(int)($_GET['id']??0),$_GET['role']??''])[0]??null;
if(!$t){http_response_code(404);exit;}
header('Content-Type: image/svg+xml');header('Content-Disposition: attachment; filename="certhub-background.svg"');
$color=e($t['color']);
echo '<svg xmlns="http://www.w3.org/2000/svg" width="1123" height="794" viewBox="0 0 1123 794"><rect width="1123" height="794" fill="#fffdf7"/><path d="M0 0H310L0 210Z M1123 794H813L1123 584Z" fill="'.$color.'"/><path d="M0 220L320 0 M803 794L1123 574" stroke="#c6a45d" stroke-width="8"/><rect x="35" y="35" width="1053" height="724" fill="none" stroke="#c6a45d" stroke-width="2"/><rect x="43" y="43" width="1037" height="708" fill="none" stroke="'.$color.'" stroke-width="1"/></svg>';
