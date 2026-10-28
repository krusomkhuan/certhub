(() => {
 const input=document.getElementById('batch-names');
 const output=document.getElementById('batch-count');
 if(!input || !output) return;
 const update=()=>{const names=input.value.split(/\r?\n/).map(s=>s.trim()).filter(Boolean);const duplicate=names.length-new Set(names).size; output.textContent=`จำนวน ${names.length.toLocaleString('th-TH')} รายชื่อ / เลขที่`+(duplicate?` • พบชื่อซ้ำ ${duplicate} รายการ กรุณาตรวจสอบ`: '');};
 input.addEventListener('input',update);update();
})();
