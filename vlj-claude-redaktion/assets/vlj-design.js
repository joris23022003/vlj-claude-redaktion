/* VLJ Niedersachsen – Design-Upgrade (Plugin vlj-claude-redaktion) */
(function(){
'use strict';
if(window.__vljUi)return;window.__vljUi=true;
var d=document,root=d.documentElement,cfg=window.VLJ_CFG||{};
var reduce=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;
var MONATE=['januar','februar','märz','april','mai','juni','juli','august','september','oktober','november','dezember'];
var MON_KURZ=['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
var ICON={
 kal:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4M12 14v4M10 16h4"/></svg>',
 teil:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>',
 mail:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M4 7l8 6 8-6"/></svg>',
 pfeil:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>',
 oben:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
 rechts:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>',
 links:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>',
 zu:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
 pdf:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M12 11v6M9 14l3 3 3-3"/></svg>'
};
function $(s,c){return (c||d).querySelector(s)}
function $$(s,c){return Array.prototype.slice.call((c||d).querySelectorAll(s))}
function el(tag,cls,html){var e=d.createElement(tag);if(cls)e.className=cls;if(html!=null)e.innerHTML=html;return e}
function txt(e){return (e&&e.textContent||'').replace(/\s+/g,' ').trim()}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function sektionen(){return $$('.brz-root__container > section, .brz > section').filter(function(s,i,a){return a.indexOf(s)===i})}
function sektionMit(t,letzte){var a=sektionen().filter(function(s){return txt(s).indexOf(t)>-1});return letzte?a[a.length-1]:a[0]}

root.classList.add('vlj-ui');

/* ---------- Datum ---------- */
function datumAus(t){
 var m=t.match(/(\d{1,2})\.\s*(Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)\s+(\d{4})/i);
 if(!m)return null;
 var o={t:+m[1],m:MONATE.indexOf(m[2].toLowerCase()),j:+m[3]};
 var rest=t.slice(m.index,m.index+120),z=rest.match(/(\d{1,2})[:.](\d{2})(?:\s*[-–]\s*(\d{1,2})[:.](\d{2}))?\s*Uhr/);
 if(z){o.h=+z[1];o.min=+z[2];if(z[3]){o.h2=+z[3];o.min2=+z[4]}}
 return o;
}
function letzterSonntag(j,m){var x=new Date(Date.UTC(j,m+1,0));x.setUTCDate(x.getUTCDate()-x.getUTCDay());return x}
function berlinUtc(j,m,t,h,mi){
 var guess=Date.UTC(j,m,t,h-1,mi),s=letzterSonntag(j,2).getTime()+3600e3,w=letzterSonntag(j,9).getTime()+3600e3;
 var off=(guess>=s&&guess<w)?2:1;return new Date(Date.UTC(j,m,t,h-off,mi));
}
function istKommend(o){if(!o)return false;var n=new Date();var ende=new Date(o.j,o.m,o.t,23,59,59);return ende>=n}
function icsZeit(x){return x.toISOString().replace(/[-:]/g,'').replace(/\.\d{3}/,'')}
function icsText(s){return String(s).replace(/\\/g,'\\\\').replace(/\n/g,'\\n').replace(/([,;])/g,'\\$1')}
function icsFalten(z){var out=[];while(z.length>74){out.push(z.slice(0,74));z=' '+z.slice(74)}out.push(z);return out.join('\r\n')}
function ics(ev){
 var o=ev.datum,L=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//VLJ Niedersachsen//Termine//DE','CALSCALE:GREGORIAN','METHOD:PUBLISH','BEGIN:VEVENT'];
 L.push('UID:'+o.j+'-'+(o.m+1)+'-'+o.t+'-'+ev.titel.replace(/[^a-z0-9]/gi,'').slice(0,24).toLowerCase()+'@vlj-niedersachsen.de');
 L.push('DTSTAMP:'+icsZeit(new Date()));
 if(o.h!=null){var a=berlinUtc(o.j,o.m,o.t,o.h,o.min),b=o.h2!=null?berlinUtc(o.j,o.m,o.t,o.h2,o.min2):new Date(a.getTime()+2*3600e3);L.push('DTSTART:'+icsZeit(a),'DTEND:'+icsZeit(b))}
 else{var p=function(n){return(n<10?'0':'')+n},e=new Date(o.j,o.m,o.t+1);L.push('DTSTART;VALUE=DATE:'+o.j+p(o.m+1)+p(o.t),'DTEND;VALUE=DATE:'+e.getFullYear()+p(e.getMonth()+1)+p(e.getDate()))}
 L.push('SUMMARY:'+icsText(ev.titel));
 if(ev.text)L.push('DESCRIPTION:'+icsText(ev.text+'\n\n'+location.origin+'/termine/'));
 if(ev.ort)L.push('LOCATION:'+icsText(ev.ort));
 L.push('URL:'+location.origin+'/termine/','END:VEVENT','END:VCALENDAR');
 return L.map(icsFalten).join('\r\n');
}
function kalenderKnopf(ev){
 var a=el('a','vlj-knopf',ICON.kal+'<span>In meinen Kalender</span>');
 a.href='data:text/calendar;charset=utf-8,'+encodeURIComponent(ics(ev));
 a.download=(ev.titel.replace(/[„“"]/g,'').replace(/[^\wäöüÄÖÜß -]/g,'').trim().replace(/\s+/g,'-')||'termin')+'.ics';
 return a;
}
function teilenKnopf(ev){
 if(!navigator.share)return null;
 var b=el('button','vlj-knopf vlj-zweit',ICON.teil+'<span>Teilen</span>');b.type='button';
 b.addEventListener('click',function(){navigator.share({title:ev.titel,text:ev.unter||ev.titel,url:location.origin+'/termine/'}).catch(function(){})});
 return b;
}
function datumBadge(o,vergangen){
 var b=el('div','vlj-datum'+(vergangen?' vlj-vergangen':''));
 b.innerHTML='<b>'+(o.t<10?'0':'')+o.t+'</b><small>'+MON_KURZ[o.m]+' '+o.j+'</small>';
 b.setAttribute('aria-hidden','true');return b;
}

/* ---------- Termine einlesen (eigene Seite oder geladen) ---------- */
function termineSpalte(doc){
 var rt=$$('.brz-rich-text',doc).filter(function(r){return /Save the Date|Veranstaltung\s+\d{1,2}\.\s/.test(txt(r))})[0];
 return rt?rt.closest('.brz-column__items'):null;
}
function termineGruppen(spalte){
 var gruppen=[],akt=null;
 Array.prototype.slice.call(spalte.children).forEach(function(c){
  var hatText=!!c.querySelector('.brz-rich-text'),t=txt(c);
  if(c.querySelector('.brz-line')&&!t){c.classList.add('vlj-weg');return}
  if(hatText){akt={knoten:[c],rt:c};gruppen.push(akt);return}
  if(akt)akt.knoten.push(c);
 });
 gruppen.forEach(function(g){
  var r=g.rt,h=$('h1,h2,h3,h4',r),ps=$$('p',r).map(txt).filter(Boolean);
  g.titel=h?txt(h):(ps[0]||'');
  g.archiv=/^Auswahl vergangener/i.test(g.titel)||/^Auswahl vergangener/i.test(txt(r));
  g.datum=g.archiv?null:datumAus(txt(r).slice(0,500));
  g.unter=ps.filter(function(p){return datumAus(p)})[0]||'';
  var ortZ=ps.filter(function(p){return /^Ort:/i.test(p)})[0];g.ort=ortZ?ortZ.replace(/^Ort:\s*/i,''):'';
  g.text=ps.filter(function(p){return p!==g.unter&&p!==g.titel}).slice(0,2).join('\n\n');
  g.kommend=istKommend(g.datum);
 });
 return gruppen;
}

/* ---------- Kopfzeile ---------- */
function kopf(){
 var h=$('.brz-section__header');if(!h)return;
 var weg=location.pathname.replace(/\/+$/,'')||'/';
 $$('.brz-menu__item',h).forEach(function(li){
  var a=$('a',li);if(!a)return;var p=(new URL(a.href,location.href)).pathname.replace(/\/+$/,'')||'/';
  if(p===weg)li.classList.add('vlj-aktiv');
  if(/mitglied-werden/.test(p))li.classList.add('vlj-cta');
 });
 var row=$('.brz-row',h),menu=$('.brz-menu__ul',h);
 if(row&&menu){
  var btn=el('button','vlj-burger','<span></span>');btn.type='button';btn.setAttribute('aria-label','Menü');btn.setAttribute('aria-expanded','false');
  row.appendChild(btn);
  var panel=el('nav','vlj-panel');panel.setAttribute('aria-label','Hauptmenü');
  $$('a',menu).forEach(function(a){var n=el('a',a.parentNode.className.indexOf('vlj-aktiv')>-1?'vlj-aktiv':'',esc(txt(a)));n.href=a.href;if(/mitglied-werden/.test(a.href))n.className+=' vlj-cta';panel.appendChild(n)});
  d.body.appendChild(panel);
  var zu=function(){root.classList.remove('vlj-menu-offen');btn.setAttribute('aria-expanded','false')};
  btn.addEventListener('click',function(){var o=root.classList.toggle('vlj-menu-offen');btn.setAttribute('aria-expanded',o?'true':'false');root.style.setProperty('--vlj-hh',h.getBoundingClientRect().bottom+'px')});
  d.addEventListener('keydown',function(e){if(e.key==='Escape')zu()});
  d.addEventListener('click',function(e){if(root.classList.contains('vlj-menu-offen')&&!panel.contains(e.target)&&!btn.contains(e.target))zu()});
 }
 var setH=function(){root.style.setProperty('--vlj-kopfh',h.offsetHeight+'px')};
 if(!root.classList.contains('vlj-scrolled'))setH();
 window.addEventListener('resize',function(){if(window.scrollY<12)setH()});
 if(window.ResizeObserver){var ro=new ResizeObserver(function(){if(window.scrollY<12)setH()});ro.observe(h)}
 var tick=false;
 var upd=function(){root.classList.toggle('vlj-scrolled',window.scrollY>12);var o=$('.vlj-oben');if(o)o.classList.toggle('vlj-zeigen',window.scrollY>700);tick=false};
 window.addEventListener('scroll',function(){if(!tick){tick=true;requestAnimationFrame(upd)}},{passive:true});upd();
}

/* ---------- Allgemeine Gestaltung ---------- */
function allgemein(){
 $$('.brz-rich-text p').forEach(function(p){if(txt(p).length<100)p.classList.add('vlj-noj')});
 var s=sektionen(),kopfS=$('.brz-section__header');
 var erste=s.filter(function(x){return x!==kopfS})[0];
 if(erste&&$('.brz-bg-image',erste)&&getComputedStyle($('.brz-bg-image',erste)).backgroundImage!=='none')erste.classList.add('vlj-hero');
 var fuss=sektionMit('c/o FDP',true);
 if(fuss){fuss.classList.add('vlj-fuss');
  var w=d.createTreeWalker(fuss,NodeFilter.SHOW_TEXT,null),n,j=String(new Date().getFullYear());
  while((n=w.nextNode())){if(/©\s*20\d\d/.test(n.nodeValue))n.nodeValue=n.nodeValue.replace(/©\s*20\d\d/,'© '+j)}
 }
 var o=el('button','vlj-oben',ICON.oben);o.type='button';o.setAttribute('aria-label','Nach oben');
 o.addEventListener('click',function(){window.scrollTo({top:0,behavior:reduce?'auto':'smooth'})});d.body.appendChild(o);
}

/* ---------- Startseite ---------- */
function startseite(){
 if(!d.body.classList.contains('home'))return;
 var akt=sektionMit('Aktuelles');
 if(akt){
  $$('.brz-columns',akt).some(function(c){var bg=$(':scope>.brz-bg>.brz-bg-color',c);if(bg){var f=getComputedStyle(bg).backgroundColor;if(f&&f!=='rgba(0, 0, 0, 0)'&&f!=='transparent'){c.classList.add('vlj-news','vlj-card');return true}}return false});
 }
 var farben=['var(--vlj-hell)','var(--vlj-magenta)','var(--vlj-gelb)','var(--vlj-blau)'],i=0;
 sektionen().forEach(function(s){
  var cols=$$('.brz-columns',s).filter(function(c){return $(':scope>.brz-column__items h3',c)||$(':scope>.brz-column__items .brz-rich-text h3',c)});
  if(!cols.length||s.classList.contains('vlj-fuss')||s===akt)return;
  cols=cols.filter(function(c){return !cols.some(function(o){return o!==c&&c.contains(o)})});
  if(cols.length<2)return;
  s.classList.add('vlj-soft');
  cols.forEach(function(c){c.classList.add('vlj-wert');c.style.setProperty('--vlj-akzent',farben[i++%4])});
  $$('.brz-line',s).forEach(function(l){var w=l.closest('.brz-wrapper');if(w)w.classList.add('vlj-weg')});
  $$('.brz-spacer',s).forEach(function(l){var w=l.closest('.brz-wrapper');if(w)w.classList.add('vlj-weg')});
  $$('.brz-row__container',s).forEach(function(r){if(!txt(r)&&!$('img,.brz-bg-image,.brz-icon,svg',r)&&r.offsetHeight<30)r.classList.add('vlj-weg')});
 });
 if(akt&&window.fetch&&window.DOMParser){
  fetch('/termine/',{credentials:'same-origin'}).then(function(r){return r.text()}).then(function(h){
   var doc=new DOMParser().parseFromString(h,'text/html'),sp=termineSpalte(doc);if(!sp)return;
   var ev=termineGruppen(sp).filter(function(g){return g.kommend})[0];if(!ev)return;
   var sek=el('section','vlj-teaser-sektion'),wrap=el('div','vlj-teaser-wrap'),t=el('div','vlj-teaser vlj-rv');
   t.appendChild(datumBadge(ev.datum,false));
   var mitte=el('div','');mitte.innerHTML='<div class="vlj-kicker">Nächster Termin</div><h3>'+esc(ev.titel)+'</h3>'+(ev.unter?'<div class="vlj-sub">'+esc(ev.unter)+'</div>':'')+(ev.text?'<p>'+esc(ev.text.split('\n\n')[0])+'</p>':'');
   t.appendChild(mitte);
   var ak=el('div','vlj-aktionen');ak.appendChild(kalenderKnopf(ev));var z=el('a','vlj-knopf vlj-zweit','<span>Termine</span>'+ICON.rechts);z.href='/termine/';ak.appendChild(z);t.appendChild(ak);
   wrap.appendChild(t);sek.appendChild(wrap);akt.parentNode.insertBefore(sek,akt);beobachte(t);
  }).catch(function(){});
 }
}

/* ---------- Vorstand ---------- */
function mailAdresse(name){
 var m=cfg.m||{},k;
 for(k in m){if(name.indexOf(k.normalize?k.normalize('NFC'):k)>-1){try{return atob(m[k]).split('').reverse().join('')}catch(e){}}}
 m=cfg.mails||{};for(k in m){if(name.indexOf(k.normalize?k.normalize('NFC'):k)>-1)return m[k]}
 return null;
}
function vorstand(){
 var rollen=/^(Vorsitzender|Vorsitzende|\d\.\s*Stellv\.|Stellv\.|Schatzmeister|Schatzmeisterin|Schriftführer|Schriftführerin|Beisitzer|Beisitzerin)(?=\s|$)/i;
 $$('.brz-rich-text').forEach(function(r){
  var ps=$$('p',r).map(txt).filter(Boolean);if(ps.length<2||ps.length>4||!rollen.test(ps[0]))return;
  var col=r.closest('.brz-columns');if(!col||col.classList.contains('vlj-person'))return;
  col.classList.add('vlj-person');
  $$('p',r).forEach(function(p){p.classList.add('vlj-noj')});
  var name=ps[1].normalize?ps[1].normalize('NFC'):ps[1],adr=mailAdresse(name);if(!adr)return;
  var a=el('a','vlj-mail',ICON.mail+'<span class="vlj-tip">E-Mail schreiben</span>');a.href='mailto:'+adr;a.setAttribute('aria-label','E-Mail an '+name);
  col.appendChild(a);
 });
}

/* ---------- Termine ---------- */
var lbBilder=[],lbIdx=0,lb=null;
function lbZeigen(i){
 lbIdx=(i+lbBilder.length)%lbBilder.length;var img=$('img',lb);img.style.opacity='0';
 var src=lbBilder[lbIdx];var n=new Image();n.onload=function(){img.src=src;img.style.opacity='1'};n.src=src;
 $('.vlj-zahl',lb).textContent=(lbIdx+1)+' / '+lbBilder.length;
}
function lbOeffnen(bilder,i){
 lbBilder=bilder;
 lb=el('div','vlj-lb','<img alt=""><button type="button" class="vlj-zu" aria-label="Schließen">'+ICON.zu+'</button><button type="button" class="vlj-zur" aria-label="Zurück">'+ICON.links+'</button><button type="button" class="vlj-vor" aria-label="Weiter">'+ICON.rechts+'</button><div class="vlj-zahl"></div>');
 lb.setAttribute('role','dialog');lb.setAttribute('aria-modal','true');
 d.body.appendChild(lb);requestAnimationFrame(function(){lb.classList.add('vlj-auf')});
 var zu=function(){lb.classList.remove('vlj-auf');d.removeEventListener('keydown',key);var x=lb;setTimeout(function(){x.remove()},250);d.body.style.overflow='';root.classList.remove('vlj-lb-offen')};
 var key=function(e){if(e.key==='Escape')zu();if(e.key==='ArrowRight')lbZeigen(lbIdx+1);if(e.key==='ArrowLeft')lbZeigen(lbIdx-1)};
 $('.vlj-zu',lb).onclick=zu;$('.vlj-vor',lb).onclick=function(){lbZeigen(lbIdx+1)};$('.vlj-zur',lb).onclick=function(){lbZeigen(lbIdx-1)};
 lb.addEventListener('click',function(e){if(e.target===lb)zu()});
 d.addEventListener('keydown',key);
 var x0=null;lb.addEventListener('touchstart',function(e){x0=e.touches[0].clientX},{passive:true});
 lb.addEventListener('touchend',function(e){if(x0==null)return;var dx=e.changedTouches[0].clientX-x0;if(Math.abs(dx)>45)lbZeigen(lbIdx+(dx<0?1:-1));x0=null});
 if(lbBilder.length<2){$('.vlj-vor',lb).style.display='none';$('.vlj-zur',lb).style.display='none'}
 d.body.style.overflow='hidden';root.classList.add('vlj-lb-offen');lbZeigen(i);$('.vlj-zu',lb).focus();
}
function termine(){
 if(!/^\/termine\/?$/.test(location.pathname))return;
 var sp=termineSpalte(d);if(!sp)return;
 var sek=sp.closest('section');if(sek)sek.classList.add('vlj-soft');
 var gruppen=termineGruppen(sp);if(!gruppen.length)return;
 var anz={kommende:0,rueckblick:0,archiv:0};
 gruppen.forEach(function(g){
  var box=el('div','vlj-ev');g.box=box;
  g.reiter=g.archiv?'archiv':(g.kommend?'kommende':'rueckblick');anz[g.reiter]++;
  box.dataset.reiter=g.reiter;
  g.knoten[0].parentNode.insertBefore(box,g.knoten[0]);
  if(g.datum)box.appendChild(datumBadge(g.datum,!g.kommend));else box.classList.add('vlj-ohne-datum');
  if(g.kommend)box.classList.add('vlj-kommend');
  g.knoten.forEach(function(k){box.appendChild(k)});
  if(g.kommend&&g.datum){var ak=el('div','vlj-aktionen');ak.appendChild(kalenderKnopf(g));var tk=teilenKnopf(g);if(tk)ak.appendChild(tk);g.rt.parentNode.insertBefore(ak,g.rt.nextSibling)}
  var bilder=$$('img',box).filter(function(i){return !i.closest('.brz-rich-text')});
  bilder.forEach(function(img,i){var w=img.closest('.brz-image')||img;w.setAttribute('role','button');w.setAttribute('tabindex','0');w.setAttribute('aria-label','Foto vergrößern');
   var auf=function(){lbOeffnen(bilder.map(function(b){return b.currentSrc||b.src}),i)};w.addEventListener('click',auf);w.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();auf()}})});
 });
 // lange Texte einklappen (erst wenn sichtbar, sonst ist die Höhe 0)
 function einklappen(g){
  if(g.geklappt)return;var r=g.rt,hoch=r.scrollHeight;if(!hoch)return;g.geklappt=true;
  var max=(g.archiv?420:300)+(window.innerWidth<768?140:0);if(hoch<max+140)return;
  r.classList.add('vlj-mehr');r.style.setProperty('--vlj-max',max+'px');
  var b=el('button','vlj-mehr-knopf','<span>Weiterlesen</span>'+ICON.pfeil);b.type='button';b.setAttribute('aria-expanded','false');
  r.parentNode.insertBefore(b,r.nextSibling);
  b.addEventListener('click',function(){var auf=!r.classList.contains('vlj-auf');r.classList.toggle('vlj-auf',auf);r.style.setProperty('--vlj-max',(auf?r.scrollHeight+40:max)+'px');b.setAttribute('aria-expanded',auf?'true':'false');$('span',b).textContent=auf?'Weniger anzeigen':'Weiterlesen';if(!auf){var y=g.box.getBoundingClientRect().top+window.scrollY-100;if(window.scrollY>y)window.scrollTo({top:y,behavior:reduce?'auto':'smooth'})}});
 }
 // Reiter
 var namen={kommende:'Kommende',rueckblick:'Rückblick',archiv:'Archiv'};
 var leiste=el('div','vlj-tabs');leiste.setAttribute('role','tablist');
 var start=(location.hash||'').replace('#','');if(!anz[start])start=anz.kommende?'kommende':'rueckblick';
 Object.keys(namen).forEach(function(k){if(!anz[k])return;
  var b=el('button','vlj-tab',esc(namen[k])+(k!=='archiv'?'<span>'+anz[k]+'</span>':''));b.type='button';b.setAttribute('role','tab');b.dataset.reiter=k;
  b.addEventListener('click',function(){zeige(k,true)});leiste.appendChild(b)});
 gruppen[0].box.parentNode.insertBefore(leiste,gruppen[0].box);
 function zeige(k,user){
  $$('.vlj-tab',leiste).forEach(function(b){b.setAttribute('aria-selected',b.dataset.reiter===k?'true':'false')});
  gruppen.forEach(function(g,i){var an=g.reiter===k;g.box.hidden=!an;if(an)einklappen(g);if(an&&user){g.box.classList.remove('vlj-neu');void g.box.offsetWidth;g.box.style.animationDelay=(i%6)*60+'ms';g.box.classList.add('vlj-neu')}});
  if(user&&history.replaceState)history.replaceState(null,'','#'+k);
 }
 zeige(start,false);
 if(location.hash&&anz[start])setTimeout(function(){leiste.scrollIntoView({behavior:'auto',block:'start'})},50);
}

/* ---------- Mitglied werden ---------- */
function mitglied(){
 $$('.brz-rich-text p').forEach(function(p){
  if(/^Wie hoch ist der Mitgliedsbeitrag/.test(txt(p))&&!p.closest('.vlj-beitrag')){
   var n=p.nextElementSibling,box=el('div','vlj-beitrag');p.parentNode.insertBefore(box,p);box.appendChild(p);
   if(n&&n.tagName==='P'&&/€|Euro/.test(txt(n)))box.appendChild(n);
  }
 });
 $$('.brz-rich-text a').forEach(function(a){if(/^Download/i.test(txt(a))&&/\.pdf/i.test(a.href)&&!a.classList.contains('vlj-pdf')){a.classList.add('vlj-pdf');a.insertAdjacentHTML('afterbegin',ICON.pdf)}});
 $$('.brz-rich-text').forEach(function(rt){
  var erster=$$('p',rt).filter(function(c){return /^§\s*\d+\s/.test(txt(c))})[0];if(!erster)return;
  var r=erster.parentNode;
  var kinder=Array.prototype.slice.call(r.children),koepfe=kinder.filter(function(c){return c.tagName==='P'&&/^§\s*\d+\s/.test(txt(c))&&txt(c).length<90});
  if(koepfe.length<4)return;
  var leiste=el('div','vlj-satzung-leiste'),btn=el('button','','Alle aufklappen');btn.type='button';leiste.appendChild(btn);
  r.insertBefore(leiste,koepfe[0]);
  var alle=[];
  koepfe.forEach(function(k){
   var det=el('details','vlj-par'),sum=el('summary',''),inh=el('div','vlj-par-inhalt');
   r.insertBefore(det,k);var n=k.nextSibling;sum.appendChild(k);det.appendChild(sum);det.appendChild(inh);
   while(n&&koepfe.indexOf(n)<0&&!(n.nodeType===1&&/^Vorstehende Satzung/.test(txt(n)))){var nx=n.nextSibling;if(n.nodeType===1&&n.tagName==='P'&&!txt(n)&&!$('img',n)){n.remove()}else inh.appendChild(n);n=nx}
   alle.push(det);
  });
  btn.addEventListener('click',function(){var auf=alle.some(function(x){return !x.open});alle.forEach(function(x){x.open=auf});btn.textContent=auf?'Alle zuklappen':'Alle aufklappen'});
 });
}

/* ---------- Einblenden beim Scrollen ---------- */
var io=null;
function beobachte(e){
 if(reduce||!('IntersectionObserver' in window))return;
 if(e.getBoundingClientRect().top<window.innerHeight*0.92){return}
 e.classList.add('vlj-rv');if(io)io.observe(e);
}
function einblenden(){
 if(reduce||!('IntersectionObserver' in window))return;
 io=new IntersectionObserver(function(es){
  var n=0;es.forEach(function(x){if(!x.isIntersecting)return;var t=x.target;io.unobserve(t);t.style.transitionDelay=(n++*90)+'ms';t.classList.add('vlj-in');
   var fertig=function(){t.classList.remove('vlj-rv','vlj-in');t.style.transitionDelay='';t.removeEventListener('transitionend',fertig)};t.addEventListener('transitionend',fertig);setTimeout(fertig,1600)});
 },{rootMargin:'0px 0px -8% 0px',threshold:0.06});
 var kand=$$('.brz-section:not(.brz-section__header):not(.vlj-hero) .brz-column__items > *').filter(function(e){return e.offsetHeight>0&&!e.classList.contains('vlj-weg')});
 var gewaehlt=[];
 kand.forEach(function(e){
  if(gewaehlt.some(function(g){return g.contains(e)}))return;
  var karten=$$('.vlj-wert,.vlj-person',e);
  if(karten.length>1&&!e.classList.contains('vlj-ev')){karten.forEach(function(k){gewaehlt.push(k)});return}
  gewaehlt.push(e);
 });
 gewaehlt.forEach(beobachte);
}

function los(){
 try{kopf()}catch(e){console.warn('vlj kopf',e)}
 try{allgemein()}catch(e){console.warn('vlj allgemein',e)}
 try{startseite()}catch(e){console.warn('vlj start',e)}
 try{vorstand()}catch(e){console.warn('vlj vorstand',e)}
 try{termine()}catch(e){console.warn('vlj termine',e)}
 try{mitglied()}catch(e){console.warn('vlj mitglied',e)}
 try{einblenden()}catch(e){console.warn('vlj einblenden',e)}
}
if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',los);else los();
})();
