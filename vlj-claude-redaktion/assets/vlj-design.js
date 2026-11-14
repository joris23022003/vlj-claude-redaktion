/* VLJ Niedersachsen – Website-Gestaltung „Recht und Freiheit“ (Plugin vlj-claude-redaktion)
 * Liest die Inhalte der Brizy-Seite aus und stellt sie in einem einheitlichen Layout dar.
 * Schlägt etwas fehl, bleibt die ursprüngliche Seite sichtbar. */
(function(){
'use strict';
if(window.__vljApp)return;window.__vljApp=true;
var d=document,root=d.documentElement,cfg=window.VLJ_CFG||{};
var reduce=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;
var MONATE=['januar','februar','märz','april','mai','juni','juli','august','september','oktober','november','dezember'];
var MONAT_NAME=['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
var I={
 kal:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>',
 mail:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M4 7l8 6 8-6"/></svg>',
 li:'<svg class="v-i-li" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 9h4v12H3zM9 9h3.8v1.7h.05c.53-1 1.83-2.05 3.77-2.05 4.03 0 4.78 2.65 4.78 6.1V21h-4v-5.5c0-1.3-.03-3-1.83-3-1.83 0-2.1 1.43-2.1 2.9V21H9z"/></svg>',
 pfeil:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
 runter:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>',
 oben:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
 links:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>',
 rechts:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>',
 zu:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
 pdf:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M12 11v6M9 14l3 3 3-3"/></svg>',
 teil:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>',
 lupe:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5M11 8v6M8 11h6"/></svg>'
};

/* ---------- Hilfen ---------- */
function $(s,c){return (c||d).querySelector(s)}
function $$(s,c){return Array.prototype.slice.call((c||d).querySelectorAll(s))}
function el(tag,cls,html){var e=d.createElement(tag);if(cls)e.className=cls;if(html!=null)e.innerHTML=html;return e}
function txt(e){return (e&&e.textContent||'').replace(/[ \s]+/g,' ').replace(/﻿/g,'').trim()}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function pfad(){return (location.pathname.replace(/\/+$/,'')||'/').toLowerCase()}
function sektionen(){return $$('.brz-root__container > section')}
function sektionMit(t,letzte){var a=sektionen().filter(function(s){return txt(s).indexOf(t)>-1});return letzte?a[a.length-1]:a[0]}
function hgBild(e){if(!e)return '';var b=e.matches&&e.matches('.brz-bg-image')?e:$('.brz-bg-image',e);if(!b)return '';var u=getComputedStyle(b).backgroundImage;var m=u&&u.match(/url\(["']?([^"')]+)["']?\)/);return m?m[1]:''}
function strich(){return '<span class="v-strich" aria-hidden="true"></span>'}
function marke(t,hell){return '<div class="v-marke'+(hell?' v-hell':'')+'">'+strich()+'<span>'+esc(t)+'</span></div>'}
function knopf(text,href,art,icon){var nach=icon===I.pfeil;var a=el('a','v-knopf'+(art?' '+art:''),(nach?'':(icon||''))+'<span>'+esc(text)+'</span>'+(nach?icon:''));if(href)a.href=href;return a}
/* Inhalt sauber übernehmen: Brizy-Klassen und Inline-Stile weg, Spans auflösen, Links behalten */
function sauber(node){
 var c=node.cloneNode(true);
 (function geh(e){
  Array.prototype.slice.call(e.children).forEach(function(k){
   geh(k);
   var t=k.tagName;
   if(t==='SPAN'||t==='FONT'){while(k.firstChild)k.parentNode.insertBefore(k.firstChild,k);k.remove();return}
   Array.prototype.slice.call(k.attributes).forEach(function(a){if(!(t==='A'&&/^(href|target|rel)$/.test(a.name)))k.removeAttribute(a.name)});
   if(t==='A'&&/^https?:/.test(k.getAttribute('href')||'')&&k.host!==location.host){k.target='_blank';k.rel='noopener'}
  });
 })(c);
 Array.prototype.slice.call(c.attributes).forEach(function(a){c.removeAttribute(a.name)});
 return c;
}
function absaetze(rt){return $$('p,h1,h2,h3,h4,h5,h6,ul,ol',rt).filter(function(p){return !p.parentNode.closest('p,ul,ol')&&txt(p)})}
function schriftzug(t){return t.replace(/\s+-\s+/g,' – ')}

/* ---------- Datum ---------- */
function datumAus(t){
 var m=t.match(/(\d{1,2})\.\s*(Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)\s+(\d{4})/i);
 if(!m)return null;
 var o={t:+m[1],m:MONATE.indexOf(m[2].toLowerCase()),j:+m[3]};
 var rest=t.slice(m.index,m.index+140),z=rest.match(/(\d{1,2})[:.](\d{2})(?:\s*[-–]\s*(\d{1,2})[:.](\d{2}))?\s*Uhr/);
 if(z){o.zeit=z[1].padStart(2,'0')+':'+z[2]}
 return o;
}
function istKommend(o){if(!o)return false;return new Date(o.j,o.m,o.t,23,59,59)>=new Date()}
function zwei(n){return (n<10?'0':'')+n}
function kalenderHref(o){return location.origin+'/?vlj_kalender='+o.j+'-'+zwei(o.m+1)+'-'+zwei(o.t)}

/* ---------- Grundgerüst ---------- */
var app=null,haupt=null;
function geruest(){
 app=el('div','v-app');app.id='vlj';
 var skip=el('a','v-skip','Zum Inhalt springen');skip.href='#v-inhalt';app.appendChild(skip);
 app.appendChild(kopf());
 haupt=el('main','v-haupt');haupt.id='v-inhalt';haupt.tabIndex=-1;app.appendChild(haupt);
 var b=$('.brz-root__container');b.parentNode.insertBefore(app,b);
}
function menueEintraege(){
 return $$('.brz-section__header .brz-menu__item a').map(function(a){return {t:txt(a),h:a.href}}).filter(function(x){return x.t});
}
function kopf(){
 var k=el('header','v-kopf'),w=el('div','v-w v-kopf-in');
 var logo=$('.brz-section__header img');
 var l=el('a','v-logo');l.href=location.origin+'/';l.setAttribute('aria-label','Startseite – Vereinigung Liberaler Juristen in Niedersachsen e.V.');
 l.innerHTML='<img src="'+esc(logo?logo.getAttribute('src'):'')+'" alt="Vereinigung Liberaler Juristen in Niedersachsen e.V." width="132" height="74">';
 w.appendChild(l);
 var nav=el('nav','v-nav');nav.setAttribute('aria-label','Hauptmenü');
 var hier=pfad();
 menueEintraege().forEach(function(m){
  var a=el('a','',esc(m.t));a.href=m.h;
  var p=(new URL(m.h,location.href)).pathname.replace(/\/+$/,'').toLowerCase()||'/';
  if(p===hier){a.className='v-an';a.setAttribute('aria-current','page')}
  if(/mitglied-werden/.test(p))a.className+=' v-knopf';
  nav.appendChild(a);
 });
 w.appendChild(nav);
 var b=el('button','v-burger','<span></span>');b.type='button';b.setAttribute('aria-label','Menü öffnen');b.setAttribute('aria-expanded','false');b.setAttribute('aria-controls','v-panel');
 w.appendChild(b);k.appendChild(w);
 var panel=el('div','v-panel');panel.id='v-panel';
 var pi=el('nav','v-w');pi.setAttribute('aria-label','Hauptmenü');
 $$('a',nav).forEach(function(a){var n=a.cloneNode(true);pi.appendChild(n)});
 panel.appendChild(pi);k.appendChild(panel);
 var zu=function(){root.classList.remove('v-menu-auf');b.setAttribute('aria-expanded','false');b.setAttribute('aria-label','Menü öffnen')};
 b.addEventListener('click',function(){var o=root.classList.toggle('v-menu-auf');b.setAttribute('aria-expanded',o?'true':'false');b.setAttribute('aria-label',o?'Menü schließen':'Menü öffnen')});
 d.addEventListener('keydown',function(e){if(e.key==='Escape')zu()});
 window.addEventListener('resize',function(){if(window.innerWidth>900)zu()});
 var tick=false;
 window.addEventListener('scroll',function(){if(tick)return;tick=true;requestAnimationFrame(function(){root.classList.toggle('v-gescrollt',window.scrollY>8);var o=$('.v-oben');if(o)o.classList.toggle('v-zeigen',window.scrollY>800);tick=false})},{passive:true});
 return k;
}
function held(opt){
 var s=el('section','v-held'+(opt.klein?' v-held-klein':''));
 s.innerHTML='<div class="v-held-bild" style="background-image:url(\''+esc(opt.bild||'')+'\')"></div><div class="v-held-film"></div>';
 var w=el('div','v-w v-held-in');
 if(opt.pfad){var nav=el('nav','v-pfad');nav.setAttribute('aria-label','Pfad');nav.innerHTML='<a href="'+location.origin+'/">Startseite</a><span aria-hidden="true">/</span><span aria-current="page">'+esc(opt.titel)+'</span>';w.appendChild(nav)}
 if(opt.marke)w.insertAdjacentHTML('beforeend',marke(opt.marke,true));
 var h=el('h1','v-held-titel',esc(opt.titel));w.appendChild(h);
 if(opt.knoepfe){var k=el('div','v-knoepfe');opt.knoepfe.forEach(function(x){k.appendChild(x)});w.appendChild(k)}
 s.appendChild(w);return s;
}
function heldAusSeite(){
 var s=sektionen()[1];if(!s)return null;
 var rt=$('.brz-rich-text',s);
 return {bild:hgBild(s),titel:rt?absaetze(rt).map(txt).join(' '):''};
}
function sektion(cls,id){var s=el('section','v-sek'+(cls?' '+cls:''));if(id)s.id=id;var w=el('div','v-w');s.appendChild(w);haupt.appendChild(s);return w}

function fuss(){
 var f=sektionMit('c/o FDP',true);if(!f)throw new Error('Fußzeile fehlt');
 var ps=absaetze(f).map(txt);
 var name=(ps.filter(function(p){return /VEREINIGUNG LIBERALER/i.test(p)})[0]||'');
 var copy=ps.filter(function(p){return /^©/.test(p)})[0]||'';
 copy=copy.replace(/©\s*\d{4}/,'© '+new Date().getFullYear());
 var adr=ps.filter(function(p){return /c\/o|\d{5}\s/.test(p)&&!/^©/.test(p)});
 var tel=ps.filter(function(p){return /^(Telefon|Fax|E-Mail)/i.test(p)});
 var managed=$$('a',f).filter(function(a){return /leo-skull/i.test(a.href)})[0];
 var weiss=(cfg.logoWeiss||'');
 var ft=el('footer','v-fuss'),w=el('div','v-w');
 var g=el('div','v-fuss-raster');
 var c1=el('div','v-fuss-marke');
 c1.innerHTML=(weiss?'<img src="'+esc(weiss)+'" alt="Vereinigung Liberaler Juristen in Niedersachsen e.V." width="150" height="84">':'')+'<p>'+esc(name.replace(/ e\.V\.$/i,'').replace(/\b([A-ZÄÖÜ])([A-ZÄÖÜ]+)\b/g,function(x,a,b){return a+b.toLowerCase()}).replace(/ In /,' in '))+' e.V.</p>';
 g.appendChild(c1);
 var c2=el('div','','<h2>Anschrift</h2>'+adr.map(function(a){return '<p>'+esc(a).replace(/^c\/o FDP, /,'c/o FDP<br>')+'</p>'}).join(''));g.appendChild(c2);
 var c3=el('div','','<h2>Kontakt</h2>'+tel.map(function(t){var m=t.match(/^E-Mail:\s*(\S+@\S+)/i);if(m)return '<p>E-Mail: <a href="mailto:'+esc(m[1])+'">'+esc(m[1])+'</a></p>';var tm=t.match(/^Telefon:\s*(.+)$/i);if(tm)return '<p>Telefon: <a href="tel:'+esc(tm[1].replace(/\s+/g,''))+'">'+esc(tm[1])+'</a></p>';return '<p>'+esc(t)+'</p>'}).join(''));g.appendChild(c3);
 var c4=el('div','','<h2>Seiten</h2>');menueEintraege().forEach(function(m){c4.insertAdjacentHTML('beforeend','<p><a href="'+esc(m.h)+'">'+esc(m.t)+'</a></p>')});g.appendChild(c4);
 w.appendChild(g);
 var u=el('div','v-fuss-unten');
 var links=$$('a',f).filter(function(a){return /impressum|datenschutz/i.test(a.href)});
 var rechts=el('div','v-fuss-links');
 links.forEach(function(a){rechts.insertAdjacentHTML('beforeend','<a href="'+esc(a.href)+'">'+esc(txt(a))+'</a>')});
 if($('.mhcookie-preferences-toggle')||window.mhCookie){var ck=el('button','v-link-knopf','Datenschutzeinstellungen');ck.type='button';ck.addEventListener('click',function(){var t=$('.mhcookie-preferences-toggle');if(t)t.click()});rechts.appendChild(ck)}
 u.appendChild(el('p','',esc(copy)));u.appendChild(rechts);
 if(managed){var mg=el('p','v-fuss-managed');mg.innerHTML='<a href="'+esc(managed.href)+'" target="_blank" rel="noopener">'+esc(txt(managed))+'</a>';u.appendChild(mg)}
 w.appendChild(u);ft.appendChild(w);app.appendChild(ft);
 var o=el('button','v-oben',I.oben);o.type='button';o.setAttribute('aria-label','Nach oben');
 o.addEventListener('click',function(){window.scrollTo({top:0,behavior:reduce?'auto':'smooth'})});app.appendChild(o);
}

/* ---------- Termine einlesen ---------- */
function termineSpalte(doc){
 var rt=$$('.brz-rich-text',doc).filter(function(r){return /Save the Date|Veranstaltung\s+\d{1,2}\.\s/.test(txt(r))})[0];
 return rt?rt.closest('.brz-column__items'):null;
}
function termineLesen(doc){
 var sp=termineSpalte(doc);if(!sp)return null;
 var gruppen=[],akt=null;
 Array.prototype.slice.call(sp.children).forEach(function(c){
  var rt=$('.brz-rich-text',c);
  if(rt){akt={rt:rt,bilder:[]};gruppen.push(akt);return}
  if(akt)$$('img',c).forEach(function(i){akt.bilder.push({src:i.getAttribute('src'),srcset:i.getAttribute('srcset')||'',alt:i.getAttribute('alt')||''})});
 });
 gruppen.forEach(function(g){
  var teile=absaetze(g.rt);
  var h=teile.filter(function(p){return /^H\d$/.test(p.tagName)})[0];
  g.titel=h?txt(h):txt(teile[0]);
  g.archiv=/^Auswahl vergangener/i.test(g.titel);
  g.rest=teile.filter(function(p){return p!==h});
  var unterEl=g.archiv?null:g.rest.filter(function(p){return datumAus(txt(p))&&txt(p).length<120})[0];
  g.unter=unterEl?schriftzug(txt(unterEl)):'';
  g.text=g.rest.filter(function(p){return p!==unterEl});
  g.datum=g.archiv?null:datumAus(txt(g.rt).slice(0,500));
  g.kommend=istKommend(g.datum);
  g.markeText=/^Save the Date/i.test(g.unter)?'Save the Date':(g.kommend?'Termin':'Rückblick');
  g.unterOhne=g.unter.replace(/^Save the Date\s*[–-]\s*/i,'');
 });
 return gruppen;
}

/* ---------- Startseite ---------- */
function startseite(){
 var s=sektionen();
 var h=heldAusSeite();
 var mehrSek=sektionMit('Herzlich');
 haupt.appendChild(held({bild:h.bild,titel:h.titel,marke:'Recht und Freiheit',knoepfe:[knopf('Mitglied werden',location.origin+'/mitglied-werden/','',''),knopf('Mehr erfahren','#v-grundsaetze','v-hell-umriss')]}));
 // Begrüßung
 if(mehrSek){
  var hs=$$('h2',mehrSek)[0],ps=$$('.brz-rich-text p',mehrSek).filter(function(p){return txt(p)});
  var sig=ps.filter(function(p){return /^Ihr\s/.test(txt(p))})[0];
  var text=ps.filter(function(p){return p!==sig});
  var w=sektion('v-gruss');
  var g=el('div','v-zwei');
  g.innerHTML='<div>'+marke('Vereinigung Liberaler Juristen')+'<h2 class="v-h2">'+esc(txt(hs))+'</h2></div>';
  var r=el('div','v-gruss-text');
  text.forEach(function(p){var c=sauber(p);c.className='v-lead';r.appendChild(c)});
  if(sig){var m=txt(sig).match(/^Ihr\s+(.+?)\s*\((.+)\)$/);var bild=cfg.bilder&&cfg.bilder.vorsitz;
   r.insertAdjacentHTML('beforeend','<div class="v-sig">'+(bild?'<img src="'+esc(bild)+'" alt="" width="64" height="64" loading="lazy">':'')+'<div><b>'+esc(m?'Ihr '+m[1]:txt(sig))+'</b>'+(m?'<span>'+esc(m[2])+'</span>':'')+'</div></div>')}
  g.appendChild(r);w.appendChild(g);
 }
 // Platz für nächsten Termin
 var terminW=sektion('v-termin-leiste');terminW.parentNode.hidden=true;
 // Aktuelles
 var akt=sektionMit('Aktuelles'),aktW=null,aktInfo=null;
 if(akt){
  var rt=$$('.brz-rich-text',akt).filter(function(r){return !/^Aktuelles$/.test(txt(r))})[0];
  var teile=rt?absaetze(rt):[];
  var titel=teile.filter(function(p){return /^„.+“$/.test(txt(p))})[0];
  var unter=teile.filter(function(p){return /^Rückblick/.test(txt(p))})[0];
  var link=$$('a',rt||akt).filter(function(a){return /Weitere Infos/i.test(txt(a))})[0];
  var text=teile.filter(function(p){return p!==titel&&p!==unter&&!(link&&p.contains(link))});
  aktW=sektion('v-aktuelles');
  var a=el('div','v-zwei v-zwei-bild');
  var bilder=el('div','v-akt-bilder');bilder.hidden=true;
  var t=el('div','v-akt-text');
  t.innerHTML=marke('Aktuelles')+(titel?'<h2 class="v-h2">'+esc(txt(titel))+'</h2>':'');
  if(unter)t.insertAdjacentHTML('beforeend','<p class="v-unter">'+esc(schriftzug(txt(unter)))+'</p>');
  text.forEach(function(p){t.appendChild(sauber(p))});
  if(link)t.appendChild(knopf('Weitere Infos',link.href,'v-text-link',I.pfeil));
  a.appendChild(bilder);a.appendChild(t);aktW.appendChild(a);
  aktInfo={titel:titel?txt(titel):'',bilder:bilder,box:a};
 }
 // Grundsätze
 var paare=[];
 s.forEach(function(sek){if(sek===akt||sek===mehrSek)return;$$('.brz-rich-text h3',sek).forEach(function(h3){
  var col=h3.closest('.brz-column__items');var p=col?$$('.brz-rich-text p',col).filter(function(x){return txt(x).length>60})[0]:null;
  if(p)paare.push({t:txt(h3),p:p});
 })});
 if(paare.length){
  var gw=sektion('v-grundsaetze','v-grundsaetze');
  var raster=el('div','v-saetze');
  paare.forEach(function(x,i){var it=el('article','v-satz');it.innerHTML='<div class="v-nr" aria-hidden="true">'+zwei(i+1)+'</div><div><h3 class="v-h3">'+esc(x.t)+'</h3></div>';var c=sauber(x.p);it.lastChild.appendChild(c);raster.appendChild(it)});
  gw.appendChild(raster);
  var bv=$$('.brz-btn').filter(function(b){return /liberalejuristen/i.test(b.href)})[0];
  if(bv){var k=knopf(txt(bv).toLowerCase().replace(/(^|\s)(\S)/g,function(m,a,b){return a+b.toUpperCase()}),bv.href,'v-umriss',I.pfeil);k.target='_blank';k.rel='noopener';var pk=el('p','v-mehr-knopf');pk.appendChild(k);gw.appendChild(pk)}
 }
 // Termine laden: nächster Termin und Fotos zum Rückblick
 if(window.fetch&&window.DOMParser){
  fetch('/termine/',{credentials:'same-origin'}).then(function(r){return r.text()}).then(function(html){
   var doc=new DOMParser().parseFromString(html,'text/html');var gs=termineLesen(doc)||[];
   var n=gs.filter(function(g){return g.kommend})[0];
   if(n){
    var t=el('div','v-termin');
    t.innerHTML='<div class="v-datum-gross"><b>'+zwei(n.datum.t)+'.'+zwei(n.datum.m+1)+'.</b><span>'+n.datum.j+(n.datum.zeit?' · '+n.datum.zeit+' Uhr':'')+'</span></div>';
    var mitte=el('div','v-termin-text');mitte.innerHTML=marke(n.markeText==='Save the Date'?'Save the Date':'Nächster Termin')+'<h2 class="v-h3">'+esc(n.titel)+'</h2>';
    n.text.slice(0,1).forEach(function(p){mitte.appendChild(sauber(p))});
    t.appendChild(mitte);
    var ak=el('div','v-termin-aktionen');ak.appendChild(knopf('In meinen Kalender',kalenderHref(n.datum),'',I.kal));ak.appendChild(knopf('Termine',location.origin+'/termine/','v-umriss',I.pfeil));t.appendChild(ak);
    terminW.appendChild(t);terminW.parentNode.hidden=false;
   }
   if(aktInfo){
    var g=gs.filter(function(x){return x.titel===aktInfo.titel})[0];
    if(g&&g.bilder.length){
     var b=g.bilder;aktInfo.bilder.innerHTML='<img class="v-akt-gross" src="'+esc(b[0].src)+'" srcset="'+esc(b[0].srcset)+'" sizes="(max-width: 900px) 100vw, 560px" alt="'+esc(b[0].alt)+'" loading="lazy">'+(b[2]?'<img class="v-akt-klein" src="'+esc(b[2].src)+'" srcset="'+esc(b[2].srcset)+'" sizes="(max-width: 900px) 45vw, 240px" alt="'+esc(b[2].alt)+'" loading="lazy">':'');
     aktInfo.bilder.hidden=false;
    }else aktInfo.box.classList.add('v-ohne-bild');
   }
  }).catch(function(){if(aktInfo)aktInfo.box.classList.add('v-ohne-bild')});
 }
}

/* ---------- Unterseiten: gemeinsame Teile ---------- */
function unterseiteKopf(){
 var h=heldAusSeite();
 haupt.appendChild(held({bild:h.bild,titel:h.titel,klein:true,pfad:true,marke:'Vereinigung Liberaler Juristen'}));
 return h;
}
function inhaltsText(){
 var s=sektionen()[2];if(!s)return [];
 var rts=$$('.brz-rich-text',s);
 return rts;
}

/* ---------- Über uns ---------- */
function ueberUns(){
 unterseiteKopf();
 var w=sektion('v-text-seite');
 var spalte=el('div','v-lesen');
 var ps=[];inhaltsText().forEach(function(rt){absaetze(rt).forEach(function(p){if(!/^VEREINIGUNG LIBERALER/i.test(txt(p))&&p.tagName!=='H2')ps.push(p)})});
 ps.forEach(function(p,i){
  var c=sauber(p);
  if(i===0)c.className='v-lead';
  var satz=null;
  if(i===ps.length-1){var m=c.innerHTML.match(/^(.*[.!?])\s+(Eine FDP-Mitgliedschaft ist nicht notwendig\.)\s*$/);if(m){c.innerHTML=m[1];satz=m[2]}}
  spalte.appendChild(c);
  if(satz)spalte.appendChild(el('blockquote','v-zitat',strich()+'<p>'+esc(satz)+'</p>'));
 });
 w.appendChild(spalte);
}

/* ---------- Mitglied werden ---------- */
function mitglied(){
 unterseiteKopf();
 var rt=inhaltsText().filter(function(r){return /Mitgliedsbeitrag/.test(txt(r))})[0];
 if(!rt)throw new Error('Mitgliedstext fehlt');
 var teile=absaetze(rt);
 var i=0,intro=[],beitrag=[],pdf=null,hinweis=null,satzung=[],satzKopf=null,satzDatum=null;
 teile.forEach(function(p){
  var t=txt(p);
  if(/^VEREINIGUNG LIBERALER/i.test(t)||(p.tagName==='H2'))return;
  if(/^Satzung$/.test(t)){satzKopf=p;i=3;return}
  if(i===0&&/^Wie hoch ist/.test(t)){i=1}
  if(i===0){intro.push(p);return}
  if(i===1){if($('a[href$=".pdf"]',p)){pdf=p;i=2;return}beitrag.push(p);return}
  if(i===2){hinweis=p;return}
  if(i===3){if(!satzDatum&&/^vom\s/.test(t)){satzDatum=p;return}satzung.push(p)}
 });
 var w=sektion('v-mitglied');
 var g=el('div','v-mitglied-raster');
 var links=el('div','v-lesen');
 intro.forEach(function(p,k){var c=sauber(p);if(k===0)c.className='v-lead';links.appendChild(c)});
 g.appendChild(links);
 var box=el('aside','v-beitritt');
 beitrag.forEach(function(p,k){var c=sauber(p);if(k===0){var h=el('h2','v-beitritt-frage',c.innerHTML);box.appendChild(h)}else box.appendChild(c)});
 if(pdf){var a=$('a',pdf);var k=knopf('Aufnahmeantrag (PDF)',a.href,'v-voll',I.pdf);k.target='_blank';k.rel='noopener';k.setAttribute('aria-label',txt(a));box.appendChild(k)}
 if(hinweis)box.appendChild(el('p','v-klein',esc(txt(hinweis))));
 g.appendChild(box);w.appendChild(g);
 // Satzung
 if(satzKopf){
  var sw=sektion('v-satzung','satzung');
  var kopfz=el('div','v-satzung-kopf');
  kopfz.innerHTML='<div>'+marke('Mitglied werden')+'<h2 class="v-h2">'+esc(txt(satzKopf))+'</h2>'+(satzDatum?'<p class="v-unter">'+esc(txt(satzDatum))+'</p>':'')+'</div>';
  var alle=el('button','v-link-knopf v-alle','Alle aufklappen');alle.type='button';kopfz.appendChild(alle);
  sw.appendChild(kopfz);
  var liste=el('div','v-paragraphen'),det=null,inh=null,schluss=[];
  satzung.forEach(function(p){
   var t=txt(p);
   if(/^§\s*\d+/.test(t)&&t.length<90){
    det=el('details','v-par');var m=t.match(/^(§\s*\d+)\s+(.*?)\.?$/);
    det.innerHTML='<summary><span class="v-par-nr">'+esc(m?m[1].replace(/\s+/,' '):'')+'</span><span class="v-par-titel">'+esc(m?m[2]:t)+'</span>'+I.runter+'</summary>';
    inh=el('div','v-par-inhalt');det.appendChild(inh);liste.appendChild(det);return;
   }
   if(/^Vorstehende Satzung/.test(t)){schluss.push(p);return}
   if(inh){var c=sauber(p);if(/^[a-g]\.?\)\s/.test(t))c.className='v-auf';inh.appendChild(c)}
  });
  sw.appendChild(liste);
  schluss.forEach(function(p){var c=sauber(p);c.className='v-klein v-satzung-schluss';sw.appendChild(c)});
  var ds=$$('details',liste);
  alle.addEventListener('click',function(){var auf=ds.some(function(x){return !x.open});ds.forEach(function(x){x.open=auf});alle.textContent=auf?'Alle zuklappen':'Alle aufklappen'});
 }
}

/* ---------- Impressum / Datenschutz ---------- */
function rechtstext(mitInhalt){
 var h=unterseiteKopf();
 var lt=$('.erecht24-legal-texts');if(!lt)throw new Error('Rechtstext fehlt');
 var w=sektion('v-recht');
 var spalte=el('div','v-lesen v-recht-text');
 var kopie=lt;lt.parentNode.removeChild(lt);
 var erste=$('h1,h2',kopie);if(erste&&txt(erste).toLowerCase().indexOf(h.titel.toLowerCase().slice(0,8))===0)erste.remove();
 // Brizy-Reste entfernen, Überschriften einheitlich
 $$('[style]',kopie).forEach(function(x){x.removeAttribute('style')});
 if(mitInhalt){
  var hs=$$('h2',kopie);
  if(hs.length>2){
   var toc=el('nav','v-toc');toc.setAttribute('aria-label','Inhalt');
   toc.innerHTML='<p class="v-toc-titel">Inhalt</p>';var ol=el('ol','');
   hs.forEach(function(x,i){x.id=x.id||('abschnitt-'+(i+1));ol.insertAdjacentHTML('beforeend','<li><a href="#'+x.id+'">'+esc(txt(x).replace(/^\d+\.\s*/,''))+'</a></li>')});
   toc.appendChild(ol);spalte.appendChild(toc);
  }
 }
 spalte.appendChild(kopie);w.appendChild(spalte);
}

/* ---------- Vorstand ---------- */
var ROLLEN=/^(Vorsitzender|Vorsitzende|\d\.\s*Stellv\.|Stellv\.|Schatzmeister|Schatzmeisterin|Schriftführer|Schriftführerin|Beisitzer|Beisitzerin)(?=\s|$)/i;
function profilDaten(name){
 var p=cfg.profile||{},k;for(k in p){if(name.normalize('NFC').indexOf(k.normalize('NFC'))>-1)return p[k]}return {};
}
function mailVon(name){
 var m=cfg.m||{},k;for(k in m){if(name.normalize('NFC').indexOf(k.normalize('NFC'))>-1){try{return atob(m[k]).split('').reverse().join('')}catch(e){}}}return '';
}
function kurz(name){return name.replace(/^(Prof\.\s*)?(Dr\.\s*)?/,'').split(' ').slice(-1)[0].toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/[^a-z]/g,'')}
function vorstand(){
 unterseiteKopf();
 var leute=[];
 $$('.brz-rich-text').forEach(function(r){
  var ps=absaetze(r).map(txt);if(ps.length<2||ps.length>4||!ROLLEN.test(ps[0]))return;
  var col=r.closest('.brz-columns');var bild=hgBild(col&&$(':scope > .brz-bg',col));
  var name=ps[1].normalize('NFC');
  leute.push({rolle:ps[0].replace(/^(\d\.)\s*stellv\./i,'$1 Stellv.'),name:name,beruf:ps[2]||'',bild:bild,id:kurz(name),mail:mailVon(name),daten:profilDaten(name)});
 });
 if(leute.length<3)throw new Error('Vorstand nicht gefunden');
 var w=sektion('v-vorstand');
 var g=el('div','v-personen');
 leute.forEach(function(p,i){
  var a=el('a','v-person');a.href='#'+p.id;a.setAttribute('aria-label',p.rolle+': '+p.name+' – Profil öffnen');
  var ini=p.name.replace(/^(Prof\.\s*)?(Dr\.\s*)?/,'').split(' ').map(function(x){return x[0]}).join('').slice(0,2);
  a.innerHTML='<div class="v-foto">'+(p.bild?'<img src="'+esc(p.bild)+'" alt="" loading="lazy">':'<span class="v-mono" aria-hidden="true">'+esc(ini)+'</span>')+'<span class="v-film"></span></div>'+
   '<div class="v-rolle">'+strich()+'<span>'+esc(p.rolle)+'</span></div><h2 class="v-name">'+esc(p.name)+'</h2><p class="v-beruf">'+esc(p.beruf)+'</p><span class="v-mehr">Profil'+I.pfeil+'</span>';
  a.addEventListener('click',function(e){e.preventDefault();profil(leute,i,true)});
  g.appendChild(a);
 });
 w.appendChild(g);
 window.addEventListener('hashchange',function(){var k=leute.findIndex(function(x){return '#'+x.id===location.hash});if(k>-1)profil(leute,k,false);else profilZu(false)});
 var start=leute.findIndex(function(x){return '#'+x.id===location.hash});if(start>-1)setTimeout(function(){profil(leute,start,false)},50);
}
var profilEl=null,profilVorher=null;
function profilZu(hist){
 if(!profilEl)return;var p=profilEl;profilEl=null;root.classList.remove('v-profil-auf');p.classList.remove('v-auf');
 setTimeout(function(){p.remove()},350);
 if(hist!==false&&location.hash&&history.replaceState)history.replaceState(null,'',location.pathname+location.search);
 if(profilVorher&&profilVorher.focus)profilVorher.focus();
}
function profil(leute,i,hist){
 var p=leute[i];if(!p)return;
 var neu=!profilEl;if(neu)profilVorher=d.activeElement;
 if(!profilEl){profilEl=el('div','v-profil');profilEl.setAttribute('role','dialog');profilEl.setAttribute('aria-modal','true');app.appendChild(profilEl);
  profilEl.addEventListener('click',function(e){if(e.target===profilEl)profilZu()});
  requestAnimationFrame(function(){profilEl&&profilEl.classList.add('v-auf')});root.classList.add('v-profil-auf')}
 var dat=p.daten||{},vor=leute[(i-1+leute.length)%leute.length],nach=leute[(i+1)%leute.length];
 profilEl.setAttribute('aria-label',p.name);
 profilEl.innerHTML='<div class="v-profil-karte"><button type="button" class="v-profil-zu" aria-label="Schließen">'+I.zu+'</button>'+
  '<div class="v-profil-bild">'+(p.bild?'<img src="'+esc(p.bild)+'" alt="'+esc(p.name)+'">':'<span class="v-mono">'+esc(p.name.replace(/^(Prof\.\s*)?(Dr\.\s*)?/,'').split(' ').map(function(x){return x[0]}).join('').slice(0,2))+'</span>')+'<span class="v-film"></span></div>'+
  '<div class="v-profil-text">'+marke(p.rolle)+'<h2>'+esc(p.name)+'</h2><p class="v-profil-beruf">'+esc(p.beruf)+'</p>'+
  (dat.bio?'<div class="v-profil-bio"><p>'+esc(dat.bio)+'</p></div>':'')+
  '<div class="v-knoepfe"></div>'+
  '<nav class="v-profil-navi" aria-label="Weitere Vorstandsmitglieder"><a href="#'+vor.id+'" class="v-zurueck">'+I.links+'<span><small>Zurück</small>'+esc(vor.name)+'</span></a><a href="#'+nach.id+'" class="v-weiter"><span><small>Weiter</small>'+esc(nach.name)+'</span>'+I.rechts+'</a></nav></div></div>';
 var kn=$('.v-knoepfe',profilEl);
 if(p.mail)kn.appendChild(knopf('E-Mail schreiben','mailto:'+p.mail,'',I.mail));
 if(dat.linkedin){var l=knopf('LinkedIn',dat.linkedin,'v-umriss',I.li);l.target='_blank';l.rel='noopener';kn.appendChild(l)}
 if(!kn.children.length)kn.remove();
 $('.v-profil-zu',profilEl).addEventListener('click',function(){profilZu()});
 $$('.v-profil-navi a',profilEl).forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();var k=a.classList.contains('v-zurueck')?(i-1+leute.length)%leute.length:(i+1)%leute.length;profil(leute,k,true)})});
 if(hist!==false&&history.replaceState)history.replaceState(null,'','#'+p.id);
 setTimeout(function(){var k=profilEl&&$('.v-profil-karte',profilEl);if(k){k.tabIndex=-1;k.focus({preventScroll:true})}},neu?60:0);
}
d.addEventListener('keydown',function(e){
 if(!profilEl)return;
 if(e.key==='Escape')profilZu();
 if(e.key==='ArrowRight'){var a=$('.v-weiter',profilEl);if(a)a.click()}
 if(e.key==='ArrowLeft'){var b=$('.v-zurueck',profilEl);if(b)b.click()}
 if(e.key==='Tab'){var f=$$('a,button',profilEl);if(!f.length)return;var first=f[0],last=f[f.length-1];if(e.shiftKey&&d.activeElement===first){e.preventDefault();last.focus()}else if(!e.shiftKey&&d.activeElement===last){e.preventDefault();first.focus()}}
});

/* ---------- Terminseite ---------- */
var lb=null,lbListe=[],lbI=0;
function lbZeigen(i){lbI=(i+lbListe.length)%lbListe.length;var img=$('img',lb),b=lbListe[lbI];img.style.opacity='0';var n=new Image();n.onload=function(){img.src=n.src;img.alt=b.alt||'';img.style.opacity='1'};n.src=b.gross||b.src;$('.v-lb-zahl',lb).textContent=(lbI+1)+' / '+lbListe.length}
function lbAuf(liste,i){
 lbListe=liste;var vorher=d.activeElement;
 lb=el('div','v-lb','<img alt=""><button type="button" class="v-lb-zu" aria-label="Schließen">'+I.zu+'</button><button type="button" class="v-lb-zur" aria-label="Vorheriges Foto">'+I.links+'</button><button type="button" class="v-lb-vor" aria-label="Nächstes Foto">'+I.rechts+'</button><p class="v-lb-zahl"></p>');
 lb.setAttribute('role','dialog');lb.setAttribute('aria-modal','true');lb.setAttribute('aria-label','Fotos');app.appendChild(lb);
 root.classList.add('v-lb-auf');requestAnimationFrame(function(){lb.classList.add('v-auf')});
 var zu=function(){lb.classList.remove('v-auf');d.removeEventListener('keydown',key);var x=lb;setTimeout(function(){x.remove()},250);root.classList.remove('v-lb-auf');if(vorher&&vorher.focus)vorher.focus()};
 var key=function(e){if(e.key==='Escape')zu();if(e.key==='ArrowRight')lbZeigen(lbI+1);if(e.key==='ArrowLeft')lbZeigen(lbI-1)};
 $('.v-lb-zu',lb).onclick=zu;$('.v-lb-vor',lb).onclick=function(){lbZeigen(lbI+1)};$('.v-lb-zur',lb).onclick=function(){lbZeigen(lbI-1)};
 lb.addEventListener('click',function(e){if(e.target===lb)zu()});d.addEventListener('keydown',key);
 var x0=null;lb.addEventListener('touchstart',function(e){x0=e.touches[0].clientX},{passive:true});lb.addEventListener('touchend',function(e){if(x0==null)return;var dx=e.changedTouches[0].clientX-x0;if(Math.abs(dx)>45)lbZeigen(lbI+(dx<0?1:-1));x0=null});
 if(liste.length<2){$('.v-lb-vor',lb).hidden=true;$('.v-lb-zur',lb).hidden=true}
 lbZeigen(i);lb.tabIndex=-1;lb.focus({preventScroll:true});
}
function groessteQuelle(b){if(!b.srcset)return b.src;var best=b.src,bw=0;b.srcset.split(',').forEach(function(s){var m=s.trim().match(/^(\S+)\s+(\d+)w$/);if(m&&+m[2]>bw&&+m[2]<=2048){bw=+m[2];best=m[1]}});return best}
function archivZeitleiste(g){
 var eintraege=[],akt=null;
 g.text.forEach(function(p){
  var t=txt(p);
  var kurzDatum=t.match(/^(Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)\s+(\d{4})$/);
  if(kurzDatum){akt={d:t,teile:[]};eintraege.push(akt);return}
  var lang=t.match(/^(?:Im|Am)\s+(?:\d{1,2}\.\s*)?(Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)\s+(\d{4})/);
  if(lang){akt={d:lang[1]+' '+lang[2],teile:[p]};eintraege.push(akt);return}
  if(akt)akt.teile.push(p);else{akt={d:'',teile:[p]};eintraege.push(akt)}
 });
 var ol=el('ol','v-zeitleiste');
 eintraege.forEach(function(e){var li=el('li','');li.innerHTML='<div class="v-zl-datum">'+esc(e.d)+'</div>';var c=el('div','v-zl-text');e.teile.forEach(function(p){var s=sauber(p);if(/^[>›]/.test(txt(s)))s.className='v-zl-sub';c.appendChild(s)});li.appendChild(c);ol.appendChild(li)});
 return ol;
}
function termine(){
 unterseiteKopf();
 var gs=termineLesen(d);if(!gs||!gs.length)throw new Error('Termine fehlen');
 var w=sektion('v-termine');
 var anz={kommende:0,rueckblick:0,archiv:0};
 gs.forEach(function(g){g.reiter=g.archiv?'archiv':(g.kommend?'kommende':'rueckblick');anz[g.reiter]++});
 var namen={kommende:'Kommende Termine',rueckblick:'Rückblick',archiv:'Archiv'};
 var leiste=el('div','v-reiter');leiste.setAttribute('role','tablist');leiste.setAttribute('aria-label','Termine');
 var liste=el('div','v-termin-liste');
 Object.keys(namen).forEach(function(k){if(!anz[k])return;var b=el('button','v-reiter-knopf',esc(namen[k])+(k!=='archiv'?'<span>'+anz[k]+'</span>':''));b.type='button';b.setAttribute('role','tab');b.id='reiter-'+k;b.dataset.reiter=k;b.addEventListener('click',function(){zeige(k,true)});leiste.appendChild(b)});
 w.appendChild(leiste);w.appendChild(liste);
 gs.forEach(function(g){
  var a=el('article','v-eintrag'+(g.kommend?' v-kommend':''));a.dataset.reiter=g.reiter;g.box=a;
  if(g.archiv){
   a.classList.add('v-archiv');
   a.innerHTML='<div class="v-eintrag-datum"></div><div class="v-eintrag-inhalt">'+marke('Archiv')+'<h2 class="v-h3">'+esc(g.titel)+'</h2></div>';
   $('.v-eintrag-inhalt',a).appendChild(archivZeitleiste(g));
   liste.appendChild(a);return;
  }
  var o=g.datum;
  a.innerHTML='<div class="v-eintrag-datum">'+(o?'<b>'+zwei(o.t)+'.'+zwei(o.m+1)+'.</b><span>'+o.j+(o.zeit?' · '+o.zeit+' Uhr':'')+'</span>':'')+'</div>';
  var inh=el('div','v-eintrag-inhalt');
  inh.innerHTML=marke(g.markeText)+'<h2 class="v-h3">'+esc(g.titel)+'</h2>'+(g.unterOhne?'<p class="v-unter">'+esc(g.unterOhne)+'</p>':'');
  var text=el('div','v-eintrag-text');var mehrLink=null;
  g.text.forEach(function(p){var t=txt(p);var l=$('a',p);if(l&&/^Weitere Infos$/i.test(t)){mehrLink=l;return}if(t===g.titel)return;text.appendChild(sauber(p))});
  inh.appendChild(text);
  if(g.bilder.length){
   var gal=el('div','v-galerie');var liste2=g.bilder.map(function(b){return {src:b.src,gross:groessteQuelle(b),alt:b.alt}});
   g.bilder.forEach(function(b,k){if(k>2)return;var bt=el('button','v-galerie-bild');bt.type='button';bt.setAttribute('aria-label','Foto '+(k+1)+' von '+g.bilder.length+' vergrößern');
    bt.innerHTML='<img src="'+esc(b.src)+'" srcset="'+esc(b.srcset)+'" sizes="(max-width: 700px) 50vw, 260px" alt="'+esc(b.alt)+'" loading="lazy">'+(k===2&&g.bilder.length>3?'<span class="v-galerie-mehr">+'+(g.bilder.length-3)+'</span>':'')+'<span class="v-galerie-lupe" aria-hidden="true">'+I.lupe+'</span>';
    bt.addEventListener('click',function(){lbAuf(liste2,k)});gal.appendChild(bt)});
   inh.appendChild(gal);
  }
  var ak=el('div','v-knoepfe');
  if(g.kommend&&o){ak.appendChild(knopf('In meinen Kalender',kalenderHref(o),'',I.kal));if(navigator.share){var tb=knopf('Teilen',null,'v-umriss',I.teil);tb.setAttribute('role','button');tb.tabIndex=0;tb.addEventListener('click',function(){navigator.share({title:g.titel,text:g.unter||g.titel,url:location.origin+'/termine/'}).catch(function(){})});ak.appendChild(tb)}}
  if(mehrLink){var ml=knopf('Weitere Infos',mehrLink.href,'v-text-link',I.pfeil);if(/\.pdf$/i.test(mehrLink.href)){ml.target='_blank';ml.rel='noopener'}ak.appendChild(ml)}
  if(ak.children.length)inh.appendChild(ak);
  a.appendChild(inh);liste.appendChild(a);
 });
 function einklappen(g){
  if(g.geklappt||!g.box||g.archiv)return;var t=$('.v-eintrag-text',g.box);if(!t||!t.offsetHeight)return;g.geklappt=true;
  var max=window.innerWidth<700?260:230;if(t.scrollHeight<max+120)return;
  t.classList.add('v-zu-lang');t.style.maxHeight=max+'px';t.id='text-'+Math.random().toString(36).slice(2,8);
  var b=el('button','v-link-knopf v-weiterlesen','<span>Weiterlesen</span>'+I.runter);b.type='button';b.setAttribute('aria-expanded','false');b.setAttribute('aria-controls',t.id);
  t.parentNode.insertBefore(b,t.nextSibling);
  b.addEventListener('click',function(){var auf=!t.classList.contains('v-offen');t.classList.toggle('v-offen',auf);t.style.maxHeight=(auf?t.scrollHeight+30:max)+'px';b.setAttribute('aria-expanded',auf?'true':'false');$('span',b).textContent=auf?'Weniger anzeigen':'Weiterlesen'});
 }
 var start=(location.hash||'').replace('#','');if(!anz[start])start=anz.kommende?'kommende':'rueckblick';
 function zeige(k,user){
  $$('.v-reiter-knopf',leiste).forEach(function(b){var an=b.dataset.reiter===k;b.setAttribute('aria-selected',an?'true':'false');b.tabIndex=an?0:-1});
  var erst=true;gs.forEach(function(g){var an=g.reiter===k;g.box.hidden=!an;g.box.classList.toggle('v-erst',an&&erst);if(an){erst=false;einklappen(g)}});
  liste.setAttribute('aria-labelledby','reiter-'+k);
  if(user&&history.replaceState)history.replaceState(null,'','#'+k);
 }
 leiste.addEventListener('keydown',function(e){if(e.key!=='ArrowRight'&&e.key!=='ArrowLeft')return;var bs=$$('.v-reiter-knopf',leiste),i=bs.indexOf(d.activeElement);if(i<0)return;var n=bs[(i+(e.key==='ArrowRight'?1:-1)+bs.length)%bs.length];n.focus();n.click()});
 liste.setAttribute('role','tabpanel');
 zeige(start,false);
}

/* ---------- Einblenden ---------- */
function einblenden(){
 if(reduce||!('IntersectionObserver' in window))return;
 var io=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting){x.target.classList.add('v-da');io.unobserve(x.target)}})},{rootMargin:'0px 0px -6% 0px',threshold:.05});
 $$('.v-sek .v-w > *, .v-personen > *, .v-saetze > *').forEach(function(e){if(e.getBoundingClientRect().top>window.innerHeight){e.classList.add('v-ein');io.observe(e)}});
}

/* ---------- Start ---------- */
var SEITEN={'/':startseite,'/der-vorstand':vorstand,'/vorstand':vorstand,'/termine':termine,'/mitglied-werden':mitglied,'/ueber-uns':ueberUns,'/impressum':function(){rechtstext(false)},'/datenschutz':function(){rechtstext(true)}};
function los(){
 var f=SEITEN[pfad()];
 if(!f||!$('.brz-root__container')){root.classList.remove('vlj-ui');return}
 try{
  geruest();f();fuss();
  root.classList.add('vlj-fertig');
  einblenden();
  if(location.hash&&!/^#(kommende|rueckblick|archiv)$/.test(location.hash)){var z=d.getElementById(location.hash.slice(1));if(z)setTimeout(function(){z.scrollIntoView()},30)}
 }catch(e){
  if(window.console)console.warn('VLJ-Gestaltung nicht angewandt:',e);
  if(app)app.remove();root.classList.remove('vlj-ui','vlj-fertig');
 }
}
if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',los);else los();
})();
