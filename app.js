'use strict';
const $ = s => document.querySelector(s);
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money = n => new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN',maximumFractionDigits:0}).format(n);
let currentUser=null,csrf='',mode='Renta',authMode='login',afterAuth=null,recoveryCode='',sequence=0,previewURLs=[],shownMine=[];
const auth=$('#auth-form'), publish=$('#publish-form');
let emailLinkToken='',emailLinkPurpose='';
function consumeEmailFragment(){const params=new URLSearchParams(location.hash.slice(1));const purpose=params.has('verify')?'verify':params.has('reset')?'reset':'';if(!purpose)return false;emailLinkPurpose=purpose;emailLinkToken=params.get(purpose)||'';history.replaceState(null,'',location.pathname+location.search);return true;}
consumeEmailFragment();
window.addEventListener('hashchange',()=>{if(consumeEmailFragment())sessionReady.then(handleIncomingEmailLink).catch(e=>notify(e.message));});
$('#year').textContent=new Date().getFullYear();
function notify(message){$('#toast').textContent=message;$('#toast').hidden=false;clearTimeout(notify.timer);notify.timer=setTimeout(()=>$('#toast').hidden=true,6000);}
function closeDialogs(){document.querySelectorAll('dialog[open]').forEach(d=>d.close());}
function openDialog(id){closeDialogs();$(id).showModal();}
async function api(action,body,query={}){
 const url=new URL('api.php',location.href);url.searchParams.set('action',action);for(const [k,v] of Object.entries(query))if(v!==''&&v!==undefined)url.searchParams.set(k,v);
 const options={credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
 if(body!==undefined){options.method='POST';options.headers['X-CSRF-Token']=csrf;if(body instanceof FormData)options.body=body;else{options.headers['Content-Type']='application/json';options.body=JSON.stringify(body);}}
 let response;try{response=await fetch(url,options);}catch{throw Error('No pudimos conectar. Revisa tu conexión e intenta de nuevo.');}
 let data;try{data=await response.json();}catch{throw Error('El servidor no pudo responder. Intenta de nuevo en unos minutos.');}
 if(!response.ok){if(response.status===401&&!['login','recover'].includes(action)){currentUser=null;updateAccount();}throw Error(data.error||'No pudimos completar la operación.');}if(data.csrf)csrf=data.csrf;return data;
}
function updateAccount(){$('#account-open').textContent=currentUser?'Mi cuenta':'Entrar / Registrarme';}
async function busy(form,fn,status){const buttons=[...form.querySelectorAll('button[type="submit"],button:not([type])')];buttons.forEach(b=>b.disabled=true);status.textContent='Un momento…';try{await fn();}catch(e){status.textContent=e.message;}finally{buttons.forEach(b=>b.disabled=false);}}
document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>b.closest('dialog').close()));


function setAuthMode(next){
 authMode=next;auth.reset();$('#auth-status').textContent='';
 const register=next==='register',recover=next==='recover',forgot=next==='forgot',reset=next==='reset',codeReset=next==='code-reset';
 $('#name-label').hidden=!register;auth.elements.name.required=register;
 $('#privacy-check').hidden=!register;auth.elements.privacy.required=register;
 $('#terms-check').hidden=!register;auth.elements.terms.required=register;
 $('#recovery-label').hidden=!(recover||codeReset);auth.elements.code.required=recover||codeReset;auth.elements.code.minLength=codeReset?6:32;auth.elements.code.maxLength=codeReset?6:32;auth.elements.code.pattern=codeReset?'[0-9]{6}':'.{32}';auth.elements.code.inputMode=codeReset?'numeric':'text';auth.elements.code.autocomplete=codeReset?'one-time-code':'off';
 $('#password-wrapper').hidden=forgot;auth.elements.password.required=!forgot;
 auth.elements.email.required=!reset;auth.elements.email.closest('label').hidden=reset;
 $('#password-help').hidden=!(register||recover||reset||codeReset);auth.elements.password.minLength=register||recover||reset||codeReset?12:1;
 auth.elements.password.autocomplete=next==='login'?'current-password':'new-password';
 $('#password-label').textContent=recover||reset||codeReset?'Nueva contraseña':'Contraseña';
 $('#auth-title').textContent=forgot?'Recupera tu acceso.':reset||codeReset?'Elige una nueva contraseña.':'Tu próximo comienzo.';
 $('#auth-submit').textContent=register?'Crear cuenta gratis ↗':recover?'Recuperar cuenta ↗':forgot?'Enviar código por correo ↗':reset||codeReset?'Guardar contraseña ↗':'Entrar ↗';
 document.querySelectorAll('.auth-tabs button').forEach(b=>b.classList.toggle('selected',b.dataset.auth===next));
}
document.querySelectorAll('[data-auth]').forEach(b=>b.addEventListener('click',()=>setAuthMode(b.dataset.auth)));
async function needAuth(callback){try{await sessionReady;if(currentUser)return callback();afterAuth=callback;setAuthMode('login');openDialog('#auth-dialog');}catch(e){notify(e.message);}}
$('#account-open').addEventListener('click',()=>needAuth(showAccount));
auth.addEventListener('submit',e=>{e.preventDefault();busy(auth,async()=>{
 const values=Object.fromEntries(new FormData(auth));values.privacy=auth.elements.privacy.checked;values.terms=auth.elements.terms.checked;
 if(authMode==='forgot'){const data=await api('forgot-password',{email:values.email});setAuthMode('code-reset');auth.elements.email.value=values.email;$('#auth-status').textContent=data.message;auth.elements.code.focus();return;}
 if(authMode==='code-reset'){const data=await api('reset-code',{email:values.email,code:values.code,password:values.password});currentUser=null;updateAccount();setAuthMode('login');$('#auth-status').textContent=data.message;return;}
 if(authMode==='reset'){const data=await api('reset-password',{token:emailLinkToken,password:values.password});emailLinkToken='';currentUser=null;updateAccount();setAuthMode('login');$('#auth-status').textContent=data.message;return;}
 const data=await api(authMode,values);currentUser=data.user;updateAccount();auth.reset();
 if(authMode==='register'){showEmailPending(data.message);return;}
 if(data.recovery){recoveryCode=data.recovery;$('#recovery-code').textContent=recoveryCode;openDialog('#recovery-dialog');return;}
 closeDialogs();const next=afterAuth;afterAuth=null;if(next)await next();else await showAccount();
},$('#auth-status'));});
$('#recovery-download').addEventListener('click',()=>{const url=URL.createObjectURL(new Blob([`Hogar Por Favor — código privado de recuperación\nCuenta: ${currentUser.email}\nCódigo: ${recoveryCode}\n\nGuárdalo de forma segura. No lo compartas. Permite cambiar tu contraseña.\n`],{type:'text/plain;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download='hogar-por-favor-recuperacion.txt';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
$('#recovery-continue').addEventListener('click',async()=>{recoveryCode='';$('#recovery-code').textContent='';closeDialogs();const next=afterAuth;afterAuth=null;if(next)await next();else await showAccount();});
$('#logout').addEventListener('click',async()=>{try{await api('logout',{});currentUser=null;updateAccount();$('#my-properties').replaceChildren();closeDialogs();notify('Sesión cerrada.');}catch(e){$('#account-status').textContent=e.message;}});
const photoURL=id=>`api.php?action=photo&id=${encodeURIComponent(id)}`;
const locationText=p=>[p.neighborhood,p.city,p.state].filter(Boolean).join(', ');
function propertyCard(p,owner=false){return `<article class="property-card"><button class="property-image" data-detail="${esc(p.id)}" aria-label="Ver ${esc(p.title)}"><img src="${photoURL(p.photos[0])}" alt="${esc(p.title)}" loading="lazy"><span class="property-badge">${esc(p.operation)}${owner&&p.status==='paused'?' · Pausado':''}</span></button><div class="property-card-body"><div class="price">${money(p.price)} <small>MXN${p.operation==='Renta'?' / mes':''}</small></div><h3><button class="plain-title" data-detail="${esc(p.id)}">${esc(p.title)}</button></h3><p class="property-location">${esc(locationText(p))}</p><div class="property-specs"><span>${esc(p.type)}</span>${p.bedrooms?`<span>${p.bedrooms} rec.</span>`:''}${p.bathrooms?`<span>${p.bathrooms} baños</span>`:''}${p.area?`<span>${p.area} m²</span>`:''}</div>${owner?`<div class="owner-actions"><button class="plain" data-edit="${esc(p.id)}">Editar</button><button class="plain" data-toggle="${esc(p.id)}" data-status="${p.status==='active'?'paused':'active'}">${p.status==='active'?'Pausar anuncio':'Volver a publicar'}</button></div>`:'<span class="unverified">Anuncio sin verificar</span>'}</div></article>`;}
function bindCards(container){container.querySelectorAll('[data-detail]').forEach(b=>b.addEventListener('click',()=>showDetail(b.dataset.detail)));container.querySelectorAll('[data-edit]').forEach(b=>b.addEventListener('click',()=>openPublish(shownMine.find(p=>p.id===b.dataset.edit))));container.querySelectorAll('[data-toggle]').forEach(b=>b.addEventListener('click',async()=>{if(b.dataset.status==='active'){const p=shownMine.find(p=>p.id===b.dataset.toggle);if(p){openPublish(p);publish.elements.status.value='active';}return;}b.disabled=true;try{await api('status',{id:b.dataset.toggle,status:b.dataset.status});await showAccount();await loadProperties();}catch(e){notify(e.message);}finally{b.disabled=false;}}));}
async function loadProperties(page=1){const request=++sequence;$('#results').innerHTML='<p>Buscando propiedades…</p>';try{const data=await api('list',undefined,{operation:mode,type:$('#property').value,state:$('#search-state').value.trim(),city:$('#search-city').value.trim(),neighborhood:$('#search-neighborhood').value.trim(),budget:$('#budget').value,page});if(request!==sequence)return;$('#property-grid').innerHTML=data.properties.map(p=>propertyCard(p)).join('');bindCards($('#property-grid'));$('#results').innerHTML=data.total?`<p><strong>${data.total} ${data.total===1?'propiedad':'propiedades'}</strong> en ${mode.toLowerCase()} · Contacto directo</p>`:'<span class="empty-icon">⌂</span><div><h3>No encontramos propiedades con estos filtros.</h3><p>Prueba otra zona o presupuesto. Si tienes un espacio, puedes publicar el primer anuncio gratis.</p></div>';$('#pagination').replaceChildren();if(data.pages>1)for(const [label,next] of [['← Anterior',page-1],[`Página ${page} de ${data.pages}`,0],['Siguiente →',page+1]]){const b=document.createElement('button');b.textContent=label;b.disabled=next<1||next>data.pages;b.addEventListener('click',()=>loadProperties(next));$('#pagination').append(b);}}catch(e){if(request!==sequence)return;$('#property-grid').replaceChildren();$('#results').replaceChildren();const p=document.createElement('p');p.textContent=e.message;const b=document.createElement('button');b.className='plain';b.textContent='Reintentar';b.addEventListener('click',()=>loadProperties(page));$('#results').append(p,b);}}
document.querySelectorAll('[data-mode]').forEach(b=>b.addEventListener('click',()=>{mode=b.dataset.mode==='renta'?'Renta':'Venta';document.querySelectorAll('[data-mode]').forEach(item=>{item.classList.toggle('selected',item===b);item.setAttribute('aria-pressed',String(item===b));});loadProperties();}));
$('#search').addEventListener('submit',e=>{e.preventDefault();loadProperties();});
async function showAccount(){$('#change-email-open').hidden=currentUser.role==='superadmin';openDialog('#account-dialog');renderEmailAccountStatus();$('#account-description').textContent=`Hola, ${currentUser.name}. Tus anuncios activos son públicos; los pausados solo los puedes ver tú.`;$('#account-status').textContent='Cargando tus anuncios…';try{const data=await api('mine');shownMine=data.properties;$('#my-properties').className='property-grid';$('#my-properties').innerHTML=data.properties.map(p=>propertyCard(p,true)).join('');bindCards($('#my-properties'));$('#account-status').textContent=data.properties.length?'':'Todavía no tienes anuncios. Publica tu primer espacio gratis.';}catch(e){$('#account-status').textContent=e.message;}}
function clearPreviews(){previewURLs.forEach(URL.revokeObjectURL);previewURLs=[];$('#photo-preview').replaceChildren();}
function openPublish(p=null){publish.reset();clearPreviews();$('#publish-status').textContent='';$('#publish-title').textContent=p?'Edita tu propiedad.':'Publica tu propiedad.';$('#publish-submit').textContent=p?'Guardar cambios ↗':'Publicar gratis ↗';$('#photos-input').required=!p;$('#photo-help').textContent=p?'Opcional: selecciona de 1 a 8 fotos para reemplazar todas las actuales. Si no eliges fotos se conservan las existentes.':'De 1 a 8 fotos JPG, PNG o WebP. Máximo 6 MB por foto.';if(p){for(const name of ['id','title','operation','type','price','state','city','neighborhood','description','contact_type','contact','bedrooms','bathrooms','area','status'])publish.elements[name].value=p[name];publish.elements.contact_consent.checked=true;p.photos.forEach(id=>{const img=document.createElement('img');img.src=photoURL(id);img.alt='Foto actual';$('#photo-preview').append(img);});}$('#contact-type').dispatchEvent(new Event('change'));window.hogarLocations?.setPublish(p);openDialog('#publish-dialog');}
document.querySelectorAll('[data-open]').forEach(b=>b.addEventListener('click',()=>needAuth(()=>{if(!currentUser.email_verified){afterAuth=()=>openPublish();showEmailPending();}else openPublish();})));
$('#photos-input').addEventListener('change',()=>{clearPreviews();const files=[...$('#photos-input').files];if(files.length>8||files.some(f=>f.size>6*1024*1024||!['image/jpeg','image/png','image/webp'].includes(f.type))){$('#publish-status').textContent='Elige hasta 8 fotos JPG, PNG o WebP, de máximo 6 MB cada una.';$('#photos-input').value='';return;}$('#publish-status').textContent='';files.forEach(file=>{const img=document.createElement('img');const url=URL.createObjectURL(file);previewURLs.push(url);img.src=url;img.alt=file.name;$('#photo-preview').append(img);});});
$('#contact-type').addEventListener('change',()=>{const field=publish.elements.contact;const email=$('#contact-type').value==='Correo';field.type=email?'email':'tel';field.inputMode=email?'email':'tel';field.placeholder=email?'contacto@ejemplo.com':'Código de país + número';});
publish.addEventListener('submit',e=>{e.preventDefault();busy(publish,async()=>{const data=new FormData(publish);await api(data.get('id')?'update':'create',data);clearPreviews();publish.reset();await loadProperties();await showAccount();notify('Anuncio guardado. Puedes editarlo o pausarlo desde aquí.');},$('#publish-status'));});
async function showDetail(id){openDialog('#detail-dialog');$('#property-detail').textContent='Cargando propiedad…';try{const {property:p}=await api('detail',undefined,{id});const href=p.contact_type==='WhatsApp'?`https://wa.me/${encodeURIComponent(p.contact)}`:p.contact_type==='Teléfono'?`tel:+${p.contact}`:`mailto:${encodeURIComponent(p.contact)}`;$('#property-detail').innerHTML=`<div class="detail-gallery">${p.photos.map((ph,i)=>`<img src="${photoURL(ph)}" alt="Foto ${i+1} de ${esc(p.title)}" loading="lazy">`).join('')}</div><span class="eyebrow">${esc(p.type)} · ${esc(p.operation)}${p.status==='paused'?' · PAUSADO':''}</span><h2 id="detail-title">${esc(p.title)}</h2><p class="price">${money(p.price)} <small>MXN${p.operation==='Renta'?' / mes':''}</small></p><p>${esc(locationText(p))}</p><div class="property-specs">${p.bedrooms?`<span>${p.bedrooms} recámaras</span>`:''}${p.bathrooms?`<span>${p.bathrooms} baños</span>`:''}${p.area?`<span>${p.area} m²</span>`:''}</div><p class="description-text">${esc(p.description)}</p><aside class="care-note"><span class="care-label">UN MOMENTO PARA DECIDIR CON CALMA</span><strong>Tu próximo hogar merece una revisión cuidadosa.</strong><p>Habla con quien publica, visita el lugar y comprueba su identidad y autorización para ofrecerlo. Revisa precio, condiciones y documentos antes de entregar dinero, compartir información sensible o firmar un contrato.</p><p class="care-caption">Tú decides y eres responsable de validar la información y los acuerdos. Hogar Por Favor facilita el contacto; no verifica este anuncio ni interviene en pagos o contratos.</p></aside><div class="contact-box"><span>Publicado por ${esc(p.owner_name)}</span><a class="button full" href="${esc(href)}" ${p.contact_type==='WhatsApp'?'target="_blank" rel="noopener noreferrer"':''}>Contactar por ${esc(p.contact_type)} ↗</a><small>Contacto público: ${esc(p.contact)}</small></div><p class="safety-note">¿Algo no te cuadra? Puedes reportar la publicación para que la revisemos.</p><div class="detail-actions"><button class="plain" id="copy-link">Copiar enlace</button><button class="plain" id="report-open">Reportar publicación</button></div>`;$('#copy-link').addEventListener('click',async()=>{const url=new URL(location.origin+location.pathname);url.searchParams.set('prop',id);try{await navigator.clipboard.writeText(url.href);notify('Enlace copiado.');}catch{notify(`Enlace: ${url.href}`);}});$('#report-open').addEventListener('click',()=>needAuth(()=>{openDialog('#report-dialog');$('#report-form').reset();$('#report-form').elements.id.value=id;$('#report-status').textContent='';}));}catch(e){$('#property-detail').textContent=e.message;}}
$('#report-form').addEventListener('submit',e=>{e.preventDefault();busy($('#report-form'),async()=>{await api('report',Object.fromEntries(new FormData($('#report-form'))));$('#report-status').textContent='Reporte recibido. Gracias por ayudarnos a identificar publicaciones sospechosas.';},$('#report-status'));});
const sessionReady=api('session').then(data=>{currentUser=data.user;updateAccount();return data;});sessionReady.catch(()=>{$('#account-open').textContent='Entrar / Registrarme';});
sessionReady.then(()=>{loadProperties();handleIncomingEmailLink();const id=new URLSearchParams(location.search).get('prop');if(id&&/^[a-f0-9]{24}$/.test(id))showDetail(id);}).catch(e=>{$('#results').textContent=e.message;});

function showEmailPending(message=''){
 $('#email-title').textContent='Confirma tu correo.';
 $('#email-description').textContent=currentUser?'Escribe el código enviado a '+currentUser.email+'. Revisa también spam. El código vence en 10 minutos.':'Inicia sesión para solicitar un código de confirmación.';
 $('#email-code-form').hidden=!currentUser;$('#email-code-form').reset();$('#email-confirm').hidden=true;$('#email-check').hidden=true;$('#email-resend').hidden=!currentUser;document.querySelectorAll('.email-next').forEach(b=>b.remove());
 $('#email-status').textContent=message;openDialog('#email-dialog');
}
function renderEmailAccountStatus(){
 const box=$('#email-account-status');box.replaceChildren();
 const text=document.createElement('p');text.textContent=currentUser.email_verified?'✓ Correo confirmado':'Confirma tu correo para publicar anuncios visibles.';box.append(text);
 if(!currentUser.email_verified){const b=document.createElement('button');b.className='plain';b.textContent='Confirmar mi correo';b.addEventListener('click',()=>showEmailPending());box.append(b);}
}
function handleIncomingEmailLink(){
 if(!emailLinkPurpose)return;
 if(!/^[a-f0-9]{64}$/.test(emailLinkToken)){notify('El enlace no es válido. Solicita uno nuevo desde tu cuenta.');return;}
 if(emailLinkPurpose==='reset'){setAuthMode('reset');openDialog('#auth-dialog');return;}
 $('#email-title').textContent='Confirma tu correo.';$('#email-description').textContent='Pulsa el botón para confirmar la dirección de correo a la que enviamos este enlace.';
 $('#email-code-form').hidden=true;$('#email-confirm').hidden=false;$('#email-check').hidden=true;$('#email-resend').hidden=true;$('#email-status').textContent='';openDialog('#email-dialog');
}
$('#email-confirm').addEventListener('click',async()=>{
 const b=$('#email-confirm');b.disabled=true;$('#email-status').textContent='Confirmando…';
 try{const data=await api('verify-email',{token:emailLinkToken});emailLinkToken='';currentUser=data.user;updateAccount();$('#email-status').textContent=data.message;b.hidden=true;
 const next=document.createElement('button');next.className='button full email-next';next.textContent=currentUser?'Ir a mi cuenta ↗':'Iniciar sesión ↗';next.addEventListener('click',()=>{next.remove();if(currentUser)showAccount();else{setAuthMode('login');openDialog('#auth-dialog');}});$('#email-status').after(next);
 }catch(e){$('#email-status').textContent=e.message;if(currentUser){$('#email-resend').hidden=false;}}finally{b.disabled=false;}
});
$('#email-resend').addEventListener('click',async()=>{
 const b=$('#email-resend');b.disabled=true;$('#email-status').textContent='Solicitando envío…';
 try{const data=await api('resend-verification',{});$('#email-status').textContent=data.message;}catch(e){$('#email-status').textContent=e.message;}finally{b.disabled=false;}
});
$('#email-check').addEventListener('click',async()=>{
 try{const data=await api('session');currentUser=data.user;updateAccount();
 if(currentUser?.email_verified){const next=afterAuth;afterAuth=null;if(next)await next();else await showAccount();}
 else $('#email-status').textContent='Aún no se ha confirmado el correo. Abre el enlace del mensaje y pulsa Confirmar correo.';
 }catch(e){$('#email-status').textContent=e.message;}
});

$('#email-code-form').addEventListener('submit',e=>{e.preventDefault();busy($('#email-code-form'),async()=>{const code=$('#email-code-form').elements.code.value;const data=await api('verify-code',{code});currentUser=data.user;updateAccount();const next=afterAuth;afterAuth=null;notify(data.message);if(next)await next();else await showAccount();},$('#email-status'));});

$('#delete-account-open').addEventListener('click',()=>{$('#delete-account-form').reset();$('#delete-account-status').textContent='';openDialog('#delete-account-dialog');});
$('#delete-account-cancel').addEventListener('click',()=>{$('#delete-account-form').reset();showAccount();});
$('#delete-account-form').addEventListener('submit',e=>{e.preventDefault();busy(e.target,async()=>{const values=Object.fromEntries(new FormData(e.target));values.confirm_delete=e.target.elements.confirm_delete.checked;const result=await api('delete-account',values);e.target.reset();currentUser=null;shownMine=[];clearPreviews();publish.reset();updateAccount();$('#my-properties').replaceChildren();$('#property-detail').replaceChildren();closeDialogs();csrf='';recoveryCode='';emailLinkToken='';emailLinkPurpose='';afterAuth=null;auth.reset();notify(result.message);try{await api('session');await loadProperties();}catch{};},$('#delete-account-status'));});

$('#edit-name-open').addEventListener('click',()=>{const form=$('#edit-name-form');form.reset();form.elements.name.value=currentUser.name;$('#edit-name-status').textContent='';openDialog('#edit-name-dialog');});
$('#change-password-open').addEventListener('click',()=>{$('#change-password-form').reset();$('#change-password-status').textContent='';openDialog('#change-password-dialog');});
document.querySelectorAll('[data-account-back]').forEach(b=>b.addEventListener('click',()=>{b.closest('form')?.reset();showAccount();}));
for(const [selector,action,status] of [['#edit-name-form','update-name','#edit-name-status'],['#change-password-form','change-password','#change-password-status']]){$(selector).addEventListener('submit',e=>{e.preventDefault();busy(e.target,async()=>{const result=await api(action,Object.fromEntries(new FormData(e.target)));currentUser=result.user;e.target.reset();if(action==='change-password'){recoveryCode='';emailLinkToken='';emailLinkPurpose='';}updateAccount();await showAccount();await loadProperties();notify(result.message);},$(status));});}
for(const id of ['edit-name-dialog','change-password-dialog'])$('#'+id).addEventListener('close',()=>{$('#'+id).querySelector('form').reset();});

function resetEmailChange(){$('#change-email-request').reset();$('#change-email-confirm').reset();$('#change-email-request').hidden=false;$('#change-email-confirm').hidden=true;$('#change-email-restart').hidden=true;$('#change-email-request-status').textContent='';$('#change-email-confirm-status').textContent='';}
$('#change-email-open').addEventListener('click',()=>{resetEmailChange();$('#current-email-note').textContent='Correo actual: '+currentUser.email;openDialog('#change-email-dialog');});
$('#change-email-request').addEventListener('submit',e=>{e.preventDefault();busy(e.target,async()=>{const r=await api('request-email-change',Object.fromEntries(new FormData(e.target)));e.target.reset();e.target.hidden=true;$('#change-email-confirm').hidden=false;$('#change-email-restart').hidden=false;$('#pending-email-note').textContent='Escribe el código enviado a '+r.pending_email+'. Revisa también spam.';$('#change-email-confirm-status').textContent=r.message;},$('#change-email-request-status'));});
$('#change-email-confirm').addEventListener('submit',e=>{e.preventDefault();busy(e.target,async()=>{const r=await api('confirm-email-change',Object.fromEntries(new FormData(e.target)));currentUser=r.user;resetEmailChange();recoveryCode='';emailLinkToken='';emailLinkPurpose='';updateAccount();await showAccount();notify(r.message);},$('#change-email-confirm-status'));});
$('#change-email-restart').addEventListener('click',async()=>{try{await api('cancel-email-change',{});resetEmailChange();}catch(e){$('#change-email-confirm-status').textContent=e.message;}});
$('#change-email-dialog').addEventListener('close',()=>{$('#change-email-request').elements.current_password.value='';$('#change-email-confirm').elements.code.value='';});

async function loadSessions(){$('#sessions-status').textContent='Cargando sesiones…';try{const r=await api('sessions');const format=t=>t?new Intl.DateTimeFormat('es-MX',{dateStyle:'medium',timeStyle:'short',timeZone:'America/Mexico_City'}).format(new Date(t*1000)):'Fecha de inicio no disponible';$('#sessions-list').innerHTML=r.sessions.map(s=>`<article class="care-note"><strong>${esc(s.device)}${s.current?' · Esta sesión':''}</strong><p>Inicio: ${esc(format(s.started))}<br>Última actividad: ${esc(format(s.last_active))}</p><button type="button" class="plain" data-close-session="${esc(s.id)}">${s.current?'Cerrar esta sesión':'Cerrar sesión en este dispositivo'}</button><div data-session-confirm hidden><p>¿Cerrar ${s.current?'esta sesión en uso':'la sesión de '+esc(s.device)}? Las demás sesiones seguirán abiertas.${s.current?' Tendrás que iniciar sesión nuevamente en este dispositivo.':''}</p><button type="button" class="plain" data-session-cancel>Conservar sesión</button><button type="button" class="button" data-session-confirm-id="${esc(s.id)}">Sí, cerrar esta sesión</button><p class="form-status" role="status"></p></div></article>`).join('');$('#sessions-status').textContent=r.sessions.length+' '+(r.sessions.length===1?'sesión activa.':'sesiones activas.')+' Fechas en horario de Ciudad de México.';}catch(e){$('#sessions-status').textContent=e.message;}}
$('#sessions-open').addEventListener('click',()=>{$('#logout-all-form').reset();$('#logout-all-status').textContent='';$('#sessions-dialog details').open=false;openDialog('#sessions-dialog');loadSessions();});$('#sessions-refresh').addEventListener('click',loadSessions);
$('#logout-all-form').addEventListener('submit',e=>{e.preventDefault();busy(e.target,async()=>{const r=await api('logout-all',{current_password:e.target.elements.current_password.value,confirm:e.target.elements.confirm.checked});e.target.reset();await finishSessionLogout(r.message);},$('#logout-all-status'));});
async function finishSessionLogout(message){currentUser=null;shownMine=[];recoveryCode='';emailLinkToken='';emailLinkPurpose='';afterAuth=null;auth.reset();publish.reset();clearPreviews();$('#my-properties').replaceChildren();$('#sessions-list').replaceChildren();updateAccount();closeDialogs();notify(message);await loadProperties();}$('#sessions-dialog').addEventListener('close',()=>$('#logout-all-form').reset());

$('#sessions-list').addEventListener('click',async e=>{
 const open=e.target.closest('[data-close-session]'),cancel=e.target.closest('[data-session-cancel]'),confirm=e.target.closest('[data-session-confirm-id]');
 if(open){const card=open.closest('article');card.querySelector('[data-session-confirm]').hidden=false;open.hidden=true;return;}
 if(cancel){const card=cancel.closest('article');card.querySelector('[data-session-confirm]').hidden=true;card.querySelector('[data-close-session]').hidden=false;return;}
 if(!confirm)return;
 const card=confirm.closest('article'),status=card.querySelector('[role="status"]');confirm.disabled=true;status.textContent='Cerrando sesión…';
 try{const r=await api('logout-session',{session_id:confirm.dataset.sessionConfirmId,confirm:true});if(r.closed_current)await finishSessionLogout(r.message);else{await loadSessions();notify(r.message);}}
 catch(err){status.textContent=err.message;confirm.disabled=false;}
});
