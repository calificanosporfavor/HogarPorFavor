'use strict';
(() => {
 const fold=v=>String(v||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/\s+/g,' ').trim();
 const stateKey=v=>({'estado de mexico':'mexico','cdmx':'ciudad de mexico','distrito federal':'ciudad de mexico','coahuila':'coahuila de zaragoza','michoacan':'michoacan de ocampo','veracruz':'veracruz de ignacio de la llave'}[fold(v)]||fold(v));
 const cache=new Map();
 function fetchPlaces(state='',city='') {const key=state+'/'+city;if(!cache.has(key)){const url=new URL('places.php',location.href);if(state)url.searchParams.set('state',state);if(city)url.searchParams.set('city',city);cache.set(key,fetch(url).then(async r=>{const data=await r.json();if(!r.ok)throw Error(data.error);return data.items;}).catch(e=>{cache.delete(key);throw e;}));}return cache.get(key);}
 function addList(input,id){const list=document.createElement('datalist');list.id=id;input.setAttribute('list',id);input.setAttribute('autocomplete','off');input.after(list);return list;}
 function fill(list,items){list.replaceChildren(...items.map(item=>{const option=document.createElement('option');option.value=item.name;return option;}));}
 function controller(state,city,colony,status,prefix,publication=false){
  const lists=[addList(state,prefix+'-states'),addList(city,prefix+'-cities'),addList(colony,prefix+'-colonies')];
  let states=[],cities=[],colonies=[],selectedState='',selectedCity='',generation=0,ready;
  const say=message=>{status.textContent=message;};
  const findState=value=>states.find(s=>stateKey(s.name)===stateKey(value));
  const findCity=value=>cities.find(c=>fold(c.name)===fold(value));
  function enabled(){if(!publication){city.disabled=!selectedState;colony.disabled=!selectedCity;}}
  async function stateChanged(){const item=findState(state.value);const id=item?.id||'';if(id===selectedState)return;const ticket=++generation;selectedState=id;selectedCity='';cities=[];colonies=[];city.value='';colony.value='';fill(lists[1],[]);fill(lists[2],[]);enabled();if(!id){say(state.value?'Elige un estado de las sugerencias o escribe su nombre completo.':'Elige un estado para ver sus ciudades o municipios.');return;}state.value=item.name;say('Cargando ciudades y municipios…');try{const values=await fetchPlaces(id);if(ticket!==generation)return;cities=values;fill(lists[1],cities);say('Ahora elige la ciudad o municipio.');}catch(e){if(ticket!==generation)return;if(!publication)city.disabled=false;say('No se pudieron cargar las sugerencias. Puedes escribir la ubicación.');}}
  async function cityChanged(){const item=findCity(city.value);const id=item?.id||'';if(id===selectedCity)return;const ticket=++generation;selectedCity=id;colonies=[];colony.value='';fill(lists[2],[]);enabled();if(!id){say(city.value?'Escribe la ciudad o municipio completo para ver sus colonias.':'Elige la ciudad o municipio para ver sus colonias.');return;}city.value=item.name;say('Cargando colonias…');try{const values=await fetchPlaces(selectedState,id);if(ticket!==generation)return;colonies=values;fill(lists[2],colonies);say('Puedes elegir una colonia o dejarla vacía para incluir todas.');}catch(e){if(ticket!==generation)return;if(!publication)colony.disabled=false;say('No se pudieron cargar las colonias. Puedes escribir la ubicación.');}}
  state.addEventListener('input',stateChanged);state.addEventListener('change',stateChanged);
  city.addEventListener('input',cityChanged);city.addEventListener('change',cityChanged);
  state.addEventListener('blur',()=>{const exact=findState(state.value);const matches=states.filter(s=>stateKey(s.name).startsWith(stateKey(state.value)));if(!exact&&state.value&&matches.length===1){state.value=matches[0].name;stateChanged();}});
  city.addEventListener('blur',()=>{const matches=cities.filter(c=>fold(c.name).startsWith(fold(city.value)));if(!findCity(city.value)&&city.value&&matches.length===1){city.value=matches[0].name;cityChanged();}});
  colony.addEventListener('change',()=>{const match=colonies.find(c=>fold(c.name)===fold(colony.value));if(match)colony.value=match.name;});
  enabled();
  ready=fetchPlaces().then(items=>{states=items;fill(lists[0],states);say('Elige un estado para ver sus ciudades o municipios.');}).catch(()=>{city.disabled=false;colony.disabled=false;say('Sugerencias no disponibles. Puedes escribir la ubicación.');});
  return {async setValues(p){const ticket=++generation;selectedState='';selectedCity='';cities=[];colonies=[];fill(lists[1],[]);fill(lists[2],[]);if(!p){state.value='';city.value='';colony.value='';enabled();say('Elige un estado para ver sus ciudades o municipios.');return;}await ready;if(ticket!==generation)return;const s=findState(p.state);state.value=s?.name||p.state||'';city.value=p.city||'';colony.value=p.neighborhood||'';if(!s){enabled();return;}selectedState=s.id;try{cities=await fetchPlaces(s.id);if(ticket!==generation)return;fill(lists[1],cities);const c=findCity(p.city);if(c){selectedCity=c.id;city.value=c.name;colonies=await fetchPlaces(s.id,c.id);if(ticket!==generation)return;fill(lists[2],colonies);}enabled();say('Sugerencias del catálogo postal. Puedes conservar la ubicación existente.');}catch{say('Puedes conservar o escribir la ubicación de tu anuncio.');}}};
 }
 const search=controller(document.querySelector('#search-state'),document.querySelector('#search-city'),document.querySelector('#search-neighborhood'),document.querySelector('#search-location-status'),'filter');
 const form=document.querySelector('#publish-form');
 const editor=controller(form.elements.state,form.elements.city,form.elements.neighborhood,document.querySelector('#publish-location-status'),'listing',true);
 document.querySelector('#clear-location').addEventListener('click',()=>{search.setValues(null);loadProperties();});
 window.hogarLocations={setPublish:p=>editor.setValues(p)};
})();
