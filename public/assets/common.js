'use strict';
const $id=id=>document.getElementById(id);
const escapeHTML=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const baht=n=>{n=Number(n);return `${Math.trunc(n/100).toLocaleString('th-TH')}.${String(n%100).padStart(2,'0')}`;};
const randomKey=()=>Array.from(crypto.getRandomValues(new Uint8Array(32)),v=>v.toString(16).padStart(2,'0')).join('');
const statusLabel=s=>({pending_payment:'รอชำระเงิน',processing:'กำลังตรวจสอบ',paid:'ชำระแล้ว',verified:'ตรวจสอบสำเร็จ',underpaid:'ยอดขาด — ติดต่อเจ้าหน้าที่',overpaid:'ยอดเกิน — ติดต่อเจ้าหน้าที่',review:'รอเจ้าหน้าที่ตรวจสอบ',duplicate:'สลิปซ้ำ',rejected:'ไม่ผ่านการตรวจสอบ',cancelled:'ยกเลิก',fulfilled:'จัดส่งแล้ว'}[s]||s);
async function api(url,data){const opts={headers:{'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content}};if(data!==undefined){opts.method='POST';if(data instanceof FormData)opts.body=data;else{opts.headers['Content-Type']='application/json';opts.body=JSON.stringify(data);}}const res=await fetch(url,opts);let result;try{result=await res.json();}catch{throw Error('ระบบไม่ตอบสนอง กรุณาลองใหม่');}if(!res.ok||result.error)throw Error(result.error||'ดำเนินการไม่สำเร็จ');return result;}
async function busy(button,fn){if(button.disabled)return;button.disabled=true;const label=button.textContent;button.textContent='กำลังดำเนินการ…';try{return await fn();}catch(e){await Swal.fire({icon:'error',title:'ไม่สามารถดำเนินการได้',text:e.message});}finally{button.disabled=false;button.textContent=label;}}
function selects(root=document){$(root).find('select').each(function(){if($(this).hasClass('select2-hidden-accessible'))return;$(this).select2({theme:'bootstrap-5',width:'100%',dropdownParent:$(this).closest('.modal').length?$(this).closest('.modal'):$(document.body)});});}
function preview(input,img){input.addEventListener('change',()=>{if(img.dataset.url)URL.revokeObjectURL(img.dataset.url);const f=input.files[0];img.hidden=!f;if(f){img.dataset.url=URL.createObjectURL(f);img.src=img.dataset.url;}});}
selects();
