(function () {
  'use strict';
  const LANGS = {
    fr: {
      label:'Français', dir:'ltr', flag:'🇫🇷',
      'Navigation':'Navigation','Gestion':'Gestion','Tableau de bord':'Dashboard','Gestion des Bouquets':'Gestion des Bouquets','Gestion des Bouquets (Catégories)':'Gestion des Bouquets (Catégories)','Forcer l’importation':'Forcer l’importation','Forcer l\'importation':'Forcer l’importation','Gestion Clients':'Gestion Clients','Fournisseurs (Sources)':'Fournisseurs (Sources)','Déconnexion':'Déconnexion','Système opérationnel':'Système opérationnel','Clients Actifs':'Clients Actifs','Sources Connectées':'Sources Connectées','Administrateur':'Administrateur','Importer un fournisseur':'Importer un fournisseur','Synchronisation':'Synchronisation','Importation ciblée : une seule source est synchronisée. Les autres fournisseurs, leurs contenus et leurs filtres restent inchangés.':'Importation ciblée : une seule source est synchronisée. Les autres fournisseurs, leurs contenus et leurs filtres restent inchangés.','Fournisseur à synchroniser':'Fournisseur à synchroniser','-- Choisir un fournisseur --':'-- Choisir un fournisseur --','Lancer la synchronisation':'Lancer la synchronisation','Retour aux bouquets':'Retour aux bouquets','Rechercher...':'Rechercher...','Tous les types':'Tous les types','Live (Direct)':'Live (Direct)','Films (VOD)':'Films (VOD)','Séries / Épisodes':'Séries / Épisodes','Logo':'Logo','Nom':'Nom','Type':'Type','URL / Host':'URL / Host','Actions':'Actions','Statut':'Statut','Client':'Client','Password':'Password','Actif':'Actif','Lien':'Lien','Modifier':'Modifier','Supprimer':'Supprimer','Ajouter une Source':'Ajouter une Source','Nom d’identification':'Nom d’identification','Type de source':'Type de source','API Xtream Codes':'API Xtream Codes','Lien M3U Direct':'Lien M3U Direct','Portail Stalker (MAC)':'Portail Stalker (MAC)','URL de base / Host':'URL de base / Host','Utilisateur':'Utilisateur','Mot de passe':'Mot de passe','Adresse MAC':'Adresse MAC','Enregistrer la source':'Enregistrer la source','Liste des Clients':'Liste des Clients','Sources (Fournisseurs)':'Sources (Fournisseurs)','Ajouter':'Ajouter','Enregistrer':'Enregistrer','Fermer':'Fermer','Synchroniser':'Synchroniser','Importer':'Importer','Catégories':'Catégories','Flux':'Flux','Visible':'Visible','Masqué':'Masqué','Modifier le client':'Modifier le client','Modifier la source':'Modifier la source','Lien M3U & API Xtream':'Lien M3U & API Xtream','Copiez et envoyez ce lien directement au client :':'Copiez et envoyez ce lien directement au client :','Flux mis à jour.':'Flux mis à jour.','Flux supprimé définitivement.':'Flux supprimé définitivement.'
    },
    en: {
      label:'English', dir:'ltr', flag:'🇺🇸',
      'Navigation':'Navigation','Gestion':'Management','Tableau de bord':'Dashboard','Gestion des Bouquets':'Content Manager','Gestion des Bouquets (Catégories)':'Content Manager (Categories)','Forcer l’importation':'Force Import','Forcer l\'importation':'Force Import','Gestion Clients':'Client Management','Fournisseurs (Sources)':'Providers (Sources)','Déconnexion':'Sign out','Système opérationnel':'System operational','Clients Actifs':'Active Clients','Sources Connectées':'Connected Sources','Administrateur':'Administrator','Importer un fournisseur':'Import Provider','Synchronisation':'Synchronization','Importation ciblée : une seule source est synchronisée. Les autres fournisseurs, leurs contenus et leurs filtres restent inchangés.':'Targeted import: only the selected source is synchronized. Other providers, content and visibility filters remain unchanged.','Fournisseur à synchroniser':'Provider to synchronize','-- Choisir un fournisseur --':'-- Select a provider --','Lancer la synchronisation':'Start synchronization','Retour aux bouquets':'Back to categories','Rechercher...':'Search...','Tous les types':'All types','Live (Direct)':'Live (Live)','Films (VOD)':'Movies (VOD)','Séries / Épisodes':'Series / Episodes','Logo':'Logo','Nom':'Name','Type':'Type','URL / Host':'URL / Host','Actions':'Actions','Statut':'Status','Client':'Client','Password':'Password','Actif':'Active','Lien':'Link','Modifier':'Edit','Supprimer':'Delete','Ajouter une Source':'Add Provider','Nom d’identification':'Display name','Type de source':'Provider type','API Xtream Codes':'Xtream Codes API','Lien M3U Direct':'Direct M3U URL','Portail Stalker (MAC)':'Stalker Portal (MAC)','URL de base / Host':'Base URL / Host','Utilisateur':'Username','Mot de passe':'Password','Adresse MAC':'MAC Address','Enregistrer la source':'Save provider','Liste des Clients':'Client List','Sources (Fournisseurs)':'Providers','Ajouter':'Add','Enregistrer':'Save','Fermer':'Close','Synchroniser':'Synchronize','Importer':'Import','Catégories':'Categories','Flux':'Streams','Visible':'Visible','Masqué':'Hidden','Modifier le client':'Edit Client','Modifier la source':'Edit Provider','Lien M3U & API Xtream':'M3U & Xtream API Link','Copiez et envoyez ce lien directement au client :':'Copy and send this link directly to the client:','Flux mis à jour.':'Stream updated.','Flux supprimé définitivement.':'Stream permanently deleted.'
    },
    ar: {
      label:'العربية', dir:'rtl', flag:'🇸🇦',
      'Navigation':'التنقل','Gestion':'الإدارة','Tableau de bord':'لوحة التحكم','Gestion des Bouquets':'إدارة المحتوى','Gestion des Bouquets (Catégories)':'إدارة المحتوى (التصنيفات)','Forcer l’importation':'فرض الاستيراد','Forcer l\'importation':'فرض الاستيراد','Gestion Clients':'إدارة العملاء','Fournisseurs (Sources)':'المصادر','Déconnexion':'تسجيل الخروج','Système opérationnel':'النظام يعمل','Clients Actifs':'العملاء النشطون','Sources Connectées':'المصادر المتصلة','Administrateur':'المدير','Importer un fournisseur':'استيراد مصدر','Synchronisation':'المزامنة','Importation ciblée : une seule source est synchronisée. Les autres fournisseurs, leurs contenus et leurs filtres restent inchangés.':'استيراد مخصص: تتم مزامنة المصدر المحدد فقط، وتبقى بقية المصادر والمحتوى والفلاتر كما هي.','Fournisseur à synchroniser':'المصدر المراد مزامنته','-- Choisir un fournisseur --':'-- اختر مصدراً --','Lancer la synchronisation':'بدء المزامنة','Retour aux bouquets':'العودة إلى التصنيفات','Rechercher...':'بحث...','Tous les types':'كل الأنواع','Live (Direct)':'بث مباشر','Films (VOD)':'أفلام (VOD)','Séries / Épisodes':'مسلسلات / حلقات','Logo':'الشعار','Nom':'الاسم','Type':'النوع','URL / Host':'الرابط / المضيف','Actions':'الإجراءات','Statut':'الحالة','Client':'العميل','Password':'كلمة المرور','Actif':'نشط','Lien':'الرابط','Modifier':'تعديل','Supprimer':'حذف','Ajouter une Source':'إضافة مصدر','Nom d’identification':'اسم التعريف','Type de source':'نوع المصدر','API Xtream Codes':'واجهة Xtream Codes','Lien M3U Direct':'رابط M3U مباشر','Portail Stalker (MAC)':'بوابة Stalker (MAC)','URL de base / Host':'الرابط الأساسي / المضيف','Utilisateur':'اسم المستخدم','Mot de passe':'كلمة المرور','Adresse MAC':'عنوان MAC','Enregistrer la source':'حفظ المصدر','Liste des Clients':'قائمة العملاء','Sources (Fournisseurs)':'المصادر','Ajouter':'إضافة','Enregistrer':'حفظ','Fermer':'إغلاق','Synchroniser':'مزامنة','Importer':'استيراد','Catégories':'التصنيفات','Flux':'القنوات','Visible':'ظاهر','Masqué':'مخفي','Modifier le client':'تعديل العميل','Modifier la source':'تعديل المصدر','Lien M3U & API Xtream':'رابط M3U و Xtream API','Copiez et envoyez ce lien directement au client :':'انسخ هذا الرابط وأرسله مباشرة إلى العميل:','Flux mis à jour.':'تم تحديث القناة.','Flux supprimé définitivement.':'تم حذف القناة نهائياً.'
    },
  };
  Object.entries(window.PANEL_TRANSLATIONS || {}).forEach(([key, values]) => { ['fr','en','ar'].forEach((lang, i) => LANGS[lang][key] = values[i]); });
  Object.entries({'Regroupement VOD':['Regroupement VOD','VOD grouping','تجميع الأفلام'],'Imports sélectifs':['Imports sélectifs','Selective imports','استيراد انتقائي'],'Catalogue administré':['Catalogue administré','Managed catalogue','الكتالوج المدار'],'G-PANEL V2 / Administration':['G-PANEL V2 / Administration','G-PANEL V2 / Administration','G-PANEL V2 / الإدارة']}).forEach(([key,values])=>['fr','en','ar'].forEach((lang,i)=>LANGS[lang][key]=values[i]));
  const storageTheme='gpanel.theme';
  let selectedLanguage = ['fr','en','ar'].includes(window.PANEL_CONFIG?.lang) ? window.PANEL_CONFIG.lang : 'fr';
  function currentLang(){return selectedLanguage;}
  function t(key){return LANGS[currentLang()][key] || key;}
  function currentTheme(){return localStorage.getItem(storageTheme)||'dark';}
  function keyForNode(node){ if(!node.parentElement) return ''; const t=(node.nodeValue||'').replace(/\s+/g,' ').trim(); return t; }
  function translate(root=document){
    const lang=currentLang(), dict=LANGS[lang]||LANGS.en;
    root.querySelectorAll('*').forEach(el=>{
      if(el.closest('script,style,[data-gp-skip]') || el.classList.contains('gp-i18n')) return;
      Array.from(el.childNodes).forEach(n=>{
        if(n.nodeType!==3) return;
        const current=n.nodeValue.replace(/\s+/g,' ').trim();
        const canonical=Object.keys(LANGS.fr).find(key=>['fr','en','ar'].some(code=>LANGS[code][key]===current)) || current;
        const original=n.__gpLast === n.nodeValue ? n.__gpOriginal : canonical;
        n.__gpOriginal=original;
        if(!original) return;
        const value=dict[original];
        if(value) {
          const leading=n.nodeValue.match(/^\s*/)?.[0]||''; const trailing=n.nodeValue.match(/\s*$/)?.[0]||'';
          n.nodeValue=leading+value+trailing;
        } else {
          const leading=n.nodeValue.match(/^\s*/)?.[0]||''; const trailing=n.nodeValue.match(/\s*$/)?.[0]||'';
          n.nodeValue=leading+original+trailing;
        }
        n.__gpLast=n.nodeValue;
      });
      ['placeholder','title','aria-label'].forEach(attr=>{
        if(!el.hasAttribute(attr)) return;
        const original=el.getAttribute('data-gp-'+attr)||el.getAttribute(attr);
        if(!el.hasAttribute('data-gp-'+attr)) el.setAttribute('data-gp-'+attr,original);
        if(dict[original]) el.setAttribute(attr,dict[original]); else el.setAttribute(attr,original);
      });
    });
    document.documentElement.lang=lang; document.documentElement.dir=dict.dir;
    document.body.classList.toggle('gp-rtl',dict.dir==='rtl');
  }
  function setTheme(theme){
    theme=theme==='light'?'light':'dark'; document.documentElement.dataset.theme=theme; localStorage.setItem(storageTheme,theme);
    document.querySelectorAll('img[src*="g-panel-logo"]').forEach(img=>{ if(img.classList.contains('brand-mark')) return; img.src=theme==='dark'?'assets/g-panel-logo-dark.png':'assets/g-panel-logo.png'; });
    const icon=document.querySelector('#gp-theme-toggle i'); if(icon) icon.className=theme==='dark'?'fas fa-sun':'fas fa-moon';
    const text=document.querySelector('#gp-theme-toggle .gp-theme-text'); if(text) text.textContent=t(theme==='dark'?'Clair':'Sombre');
  }
  async function setLang(lang){
    if(!LANGS[lang]) return;
    const previous=selectedLanguage;
    if(window.PANEL_CONFIG?.authenticated){
      try {
        const response=await fetch('panel_language.php',{method:'POST',headers:{'X-CSRF-Token':window.PANEL_CONFIG.csrf},body:new URLSearchParams({language:lang})});
        const data=await response.json();
        if(!response.ok || !data.success) throw new Error(data.error?.message || t('Enregistrement de la langue indisponible.'));
      } catch(error){
        document.querySelectorAll('#gp-lang-select,#gp-login-lang').forEach(sel=>sel.value=previous);
        alert(error.message); return;
      }
    }
    selectedLanguage=lang;
    translate();setTheme(currentTheme());
    document.querySelectorAll('#gp-lang-select,#gp-login-lang').forEach(sel=>sel.value=lang);
  }
  function mountToolbar(){
    /* Login page: compact controls centered below the login panel. */
    const loginPanel=document.querySelector('.login-panel');
    if(loginPanel){
      let controls=loginPanel.querySelector('.gp-login-controls');
      if(!controls){
        controls=document.createElement('div');
        controls.className='gp-login-controls';
        controls.innerHTML='<select id="gp-login-lang" class="gp-lang-select" aria-label="Language"></select><button id="gp-login-theme" class="gp-theme-toggle" type="button" aria-label="Theme"><i></i><span class="gp-theme-text"></span></button>';
        loginPanel.appendChild(controls);
      }
      const sel=controls.querySelector('#gp-login-lang');
      Object.keys(LANGS).forEach(k=>{
        const o=document.createElement('option');
        o.setAttribute('data-gp-skip',''); o.value=k; o.textContent=LANGS[k].flag+' '+LANGS[k].label;
        sel.appendChild(o);
      });
      sel.value=currentLang();
      sel.addEventListener('change',e=>setLang(e.target.value));
      controls.querySelector('#gp-login-theme').addEventListener('click',()=>setTheme(currentTheme()==='dark'?'light':'dark'));
      setTheme(currentTheme());
      return;
    }

    /* Authenticated panel: keep language/theme controls inside sidebar. */
    const sidebar=document.querySelector('.sidebar') || document.querySelector('.gp-import-top,.top-bar') || document.body;
    if(!sidebar) return;

    let footer=sidebar.querySelector('.gp-sidebar-footer');
    if(!footer){
      footer=document.createElement('div');
      footer.className='gp-sidebar-footer';
      sidebar.appendChild(footer);
    }
    if(footer.querySelector('#gp-lang-select')) return;

    const bar=document.createElement('div');
    bar.className='gp-interface-controls';
    bar.innerHTML='<div class="gp-control-label">Interface</div><select id="gp-lang-select" class="gp-lang-select" aria-label="Language"></select><button id="gp-theme-toggle" class="gp-theme-toggle" type="button" aria-label="Theme"><i></i><span class="gp-theme-text"></span></button>';
    footer.appendChild(bar);

    const sel=bar.querySelector('#gp-lang-select');
    Object.keys(LANGS).forEach(k=>{
      const o=document.createElement('option');
      o.setAttribute('data-gp-skip',''); o.value=k; o.textContent=LANGS[k].flag+' '+LANGS[k].label;
      sel.appendChild(o);
    });
    sel.value=currentLang();
    sel.addEventListener('change',e=>setLang(e.target.value));
    bar.querySelector('#gp-theme-toggle').addEventListener('click',()=>setTheme(currentTheme()==='dark'?'light':'dark'));
    setTheme(currentTheme());
  }
  function mountNavigation(){
    const sidebar=document.querySelector('.sidebar'); if(!sidebar) return;
    sidebar.id='gp-navigation';
    const toggle=document.createElement('button'); toggle.type='button'; toggle.className='gp-mobile-nav';
    toggle.textContent='☰ Menu'; toggle.setAttribute('aria-controls',sidebar.id); toggle.setAttribute('aria-expanded','false');
    const shade=document.createElement('div'); shade.className='gp-nav-shade';
    const close=()=>{document.body.classList.remove('gp-nav-open');toggle.setAttribute('aria-expanded','false');};
    toggle.addEventListener('click',()=>{const open=document.body.classList.toggle('gp-nav-open');toggle.setAttribute('aria-expanded',String(open));});
    shade.addEventListener('click',close); sidebar.querySelectorAll('a').forEach(a=>a.addEventListener('click',close));
    document.addEventListener('keydown',e=>{if(e.key==='Escape'){close();toggle.focus();}});
    document.body.append(toggle,shade);
  }
  function init(){
    mountNavigation();
    mountToolbar();setTheme(currentTheme());translate();
    const observer=new MutationObserver(()=>{
      observer.disconnect();translate();observer.observe(document.body,{childList:true,subtree:true,characterData:true});
    });
    observer.observe(document.body,{childList:true,subtree:true,characterData:true});
    document.documentElement.classList.add('gp-ready');
  }
  window.GPanelUI={setLang,setTheme,translate,t,LANGS};
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
