(() => {
 'use strict';
 const list=document.getElementById('sb-links');if(!list)return;
 const template=list.firstElementChild.cloneNode(true);
 const mode=document.getElementById('sb-mode');
 function rank(){[...list.children].forEach((row,i)=>{
  row.querySelector('.sb-rank').textContent=i===0?'PRIMARY':'BACKUP '+i;
  row.querySelector('select').name=`links[${i}][candidate]`;
  row.querySelector('input[type=url]').name=`links[${i}][url]`;
  row.querySelectorAll('.sb-enabled,.sb-enabled-default').forEach(input=>input.name=`links[${i}][enabled]`);
  row.querySelector('[data-sb-up]').disabled=i===0;
  row.querySelector('[data-sb-down]').disabled=i===list.children.length-1;
  row.querySelector('[data-sb-up]').setAttribute('aria-label','Monter la source '+(i+1));
  row.querySelector('[data-sb-down]').setAttribute('aria-label','Descendre la source '+(i+1));
 });document.getElementById('sb-count').value=list.children.length;}
 document.getElementById('sb-add').addEventListener('click',()=>{
  const row=template.cloneNode(true);row.querySelector('select').value='0';row.querySelector('input[type=url]').value='';row.querySelector('.sb-enabled').checked=true;list.append(row);mode.value='custom';rank();row.querySelector('select').focus();
 });
 list.addEventListener('click',e=>{
  const button=e.target.closest('button');if(!button)return;const row=button.closest('.sb-link');
  mode.value='custom';
  if(button.hasAttribute('data-sb-remove'))row.remove();
  if(button.hasAttribute('data-sb-up')&&row.previousElementSibling)list.insertBefore(row,row.previousElementSibling);
  if(button.hasAttribute('data-sb-down')&&row.nextElementSibling)list.insertBefore(row.nextElementSibling,row);
  rank();
 });
 list.addEventListener('change',e=>{
  const row=e.target.closest('.sb-link');if(!row)return;mode.value='custom';
  if(e.target.tagName==='SELECT' && e.target.value!=='0')row.querySelector('input[type=url]').value='';
  if(e.target.type==='url' && e.target.value.trim())row.querySelector('select').value='0';
 });
 rank();
})();
