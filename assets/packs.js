(function(){'use strict';
const search=document.getElementById('pack-search'),status=document.getElementById('pack-status'),level=document.getElementById('pack-level');
function filter(){document.querySelectorAll('.pack-card').forEach(c=>c.hidden=!c.dataset.name.toLowerCase().includes(search.value.toLowerCase())||(status.value!==''&&status.value!==c.dataset.active)||(level.value!==''&&level.value!==c.dataset.level));}
[search,status,level].forEach(e=>e.addEventListener('input',filter));
document.querySelectorAll('form[data-confirm]').forEach(f=>f.addEventListener('submit',e=>{if(!confirm(window.GPanelUI?.t(f.dataset.confirm)||f.dataset.confirm))e.preventDefault();}));
const form=document.getElementById('pack-form');if(!form)return;const catalog=JSON.parse(document.getElementById('pack-catalog').textContent);
function preview(){const data=new FormData(form),count={live:0,movie:0,series:0};for(const s of catalog){const scope=Number(s.fournisseur_id)===0?'personal':s.stream_type;const all=data.has('all['+scope+']');const cats=data.getAll('categories['+scope+'][]');const group=scope==='movie'&&s.group_id&&data.getAll('groups[]').includes(String(s.group_id));if(all||cats.includes(String(s.category_id))||group)count[s.stream_type]++;}document.getElementById('pack-preview').textContent=`${count.live} Live · ${count.movie} Films · ${count.series} Séries`;}
form.addEventListener('change',preview);preview();
})();
