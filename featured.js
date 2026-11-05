'use strict';
(()=>{
 const root=$('#featured-properties');if(!root)return;
 const status=$('#featured-status'),frame=$('#featured-current'),controls=$('#featured-controls'),position=$('#featured-position'),pause=$('#featured-pause'),announcement=$('#featured-announcement'),retry=$('#featured-refresh');
 let items=[],index=0,timer=0,paused=matchMedia('(prefers-reduced-motion: reduce)').matches,hover=false,focused=false,inView=false,loading=false;
 const formatUntil=value=>value?new Intl.DateTimeFormat('es-MX',{day:'numeric',month:'long'}).format(new Date(Number(value)*1000)):'';
 function stop(){clearTimeout(timer);timer=0;}
 function schedule(){stop();if(items.length>1&&!paused&&!hover&&!focused&&inView&&!document.hidden)timer=setTimeout(()=>show(index+1,false),7000);}
 function updatePause(){pause.textContent=paused?'Reanudar rotación':'Pausar rotación';pause.setAttribute('aria-pressed',String(paused));schedule();}
 function show(next,manual=true){
  if(!items.length)return;
  index=(next+items.length)%items.length;
  const p=items[index],photo=Array.isArray(p.photos)&&p.photos.length?p.photos[0]:'';
  const place=[p.neighborhood,p.city,p.state].filter(Boolean).join(', ');
  const until=formatUntil(p.featured_until);
  frame.innerHTML=`<article class="featured-slide">
   <div class="featured-photo"><img src="${photo?'api.php?action=photo&id='+encodeURIComponent(photo):'hogar.jpg'}" alt="${esc(p.title)}" loading="lazy"><span class="featured-label">PUBLICACIÓN DESTACADA</span></div>
   <div class="featured-copy"><span class="eyebrow">${esc(p.operation)} · ${esc(p.type)}</span>
    <h3><button type="button" class="plain-title" data-featured-detail="${esc(p.id)}">${esc(p.title)}</button></h3>
    <p class="featured-location">${esc(place)}</p>
    <p class="price">${money(p.price)} <small>MXN${p.operation==='Renta'?' / mes':''}</small></p>
    ${until?'<p class="featured-until">Destacada hasta '+esc(until)+'</p>':''}
    <button type="button" class="button" data-featured-detail="${esc(p.id)}">Ver publicación</button>
   </div>
  </article>`;
  frame.querySelectorAll('[data-featured-detail]').forEach(b=>b.addEventListener('click',()=>showDetail(p.id)));
  position.textContent=(index+1)+' de '+items.length;
  if(manual)announcement.textContent='Publicación destacada '+(index+1)+' de '+items.length+': '+p.title;
  schedule();
 }
 async function load(){
  if(loading)return;loading=true;retry.hidden=true;
  try{
   const data=await api('featured-list');
   const next=Array.isArray(data.properties)?data.properties:[];
   const previousId=items[index]?.id;
   items=next;
   if(!items.length){frame.replaceChildren();controls.hidden=true;status.hidden=false;status.textContent='Todavía no hay publicaciones destacadas activas. En cuanto haya alguna, aparecerá aquí.';stop();return;}
   index=Math.max(0,items.findIndex(p=>p.id===previousId));
   status.hidden=true;controls.hidden=items.length<2;show(index,false);
  }catch(e){status.hidden=false;status.textContent='No pudimos cargar las publicaciones destacadas.';retry.hidden=false;stop();}
  finally{loading=false;}
 }
 $('#featured-prev').addEventListener('click',()=>show(index-1));
 $('#featured-next').addEventListener('click',()=>show(index+1));
 pause.addEventListener('click',()=>{paused=!paused;updatePause();});
 retry.addEventListener('click',load);
 root.addEventListener('mouseenter',()=>{hover=true;schedule();});
 root.addEventListener('mouseleave',()=>{hover=false;schedule();});
 root.addEventListener('focusin',()=>{focused=true;schedule();});
 root.addEventListener('focusout',e=>{focused=root.contains(e.relatedTarget);schedule();});
 document.addEventListener('visibilitychange',()=>{if(document.hidden)stop();else{load();schedule();}});
 if('IntersectionObserver'in window){const observer=new IntersectionObserver(entries=>{inView=entries.some(e=>e.isIntersecting);schedule();},{threshold:.18});observer.observe(root);}else inView=true;
 load();setInterval(()=>{if(!document.hidden)load();},60000);
})();
