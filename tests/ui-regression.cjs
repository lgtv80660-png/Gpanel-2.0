// PLAYWRIGHT_PATH points to an existing Playwright package; no package installation needed.
const {chromium}=require(process.env.PLAYWRIGHT_PATH || 'playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 const page=await browser.newPage({viewport:{width:1440,height:1000}});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://**/*',r=>{
  const url=r.request().url();
  const vendor=path.join(__dirname,'../player1');
  if(url.includes('bootstrap') && url.endsWith('.css') && fs.existsSync(path.join(vendor,'styles/vendors/bootstrap-5.3.3.min.css')))
   return r.fulfill({path:path.join(vendor,'styles/vendors/bootstrap-5.3.3.min.css'),contentType:'text/css'});
  if(url.includes('bootstrap') && url.endsWith('.js') && fs.existsSync(path.join(vendor,'scripts/vendors/bootstrap-5.3.3.bundle.min.js')))
   return r.fulfill({path:path.join(vendor,'scripts/vendors/bootstrap-5.3.3.bundle.min.js'),contentType:'text/javascript'});
  return r.abort();
 }); // Serve existing vendor assets locally; external requests never leave this fixture.
 await page.goto('http://127.0.0.1:18976/__fixture.php');
 await page.goto('http://127.0.0.1:18976/admin.php');
 await page.waitForSelector('html.gp-ready');
 const languages=await page.locator('#gp-lang-select option').evaluateAll(items=>items.map(i=>i.value));
 assert.deepEqual(languages,['fr','en','ar']);
 for(const [lang,title,dir] of [['en','Dashboard','ltr'],['ar','لوحة التحكم','rtl'],['fr','Tableau de bord','ltr']]){
  await page.selectOption('#gp-lang-select',lang);
  await page.waitForFunction(([lang,title])=>document.documentElement.lang===lang&&document.querySelector('h1')?.textContent===title,[lang,title]);
  assert.equal(await page.locator('html').getAttribute('dir'),dir);
  assert.equal(await page.locator('td[data-gp-skip]').first().innerText(),'Visible'); // Catalogue/client word must remain untouched.
  const geometry=await page.evaluate(()=>({sidebar:document.querySelector('.sidebar').getBoundingClientRect().toJSON(),main:document.querySelector('.main-content').getBoundingClientRect().toJSON()}));
  if(lang==='ar'){assert(geometry.sidebar.x>1000);assert(geometry.main.x<geometry.sidebar.x);}
  else {assert(geometry.sidebar.x<=10);assert(geometry.main.x>=260);}
  await page.reload();await page.waitForSelector('html.gp-ready');
  assert.equal(await page.locator('html').getAttribute('lang'),lang);
  console.log('PASS '+lang+' single language, persistent preference, preserved client data and layout');
 }
 await page.goto('http://127.0.0.1:18976/editor.php');
 await page.waitForSelector('html.gp-ready');
 await page.selectOption('#gp-lang-select','ar');
 await page.waitForFunction(()=>document.documentElement.lang==='ar');
 assert.equal(await page.locator('.folder-link span[data-gp-skip]').innerText(),'Visible');
 assert.equal(await page.locator('#providerFilter option[value="1"][data-gp-skip]').innerText(),'Visible');
 // Existing dynamic notifications must switch along with the rest of the interface.
 await page.evaluate(()=>{const el=document.createElement('p');el.id='fixture-notice';el.textContent='Ajout effectué avec succès !';document.body.append(el)});
 await page.waitForFunction(()=>document.querySelector('#fixture-notice').textContent==='تمت الإضافة بنجاح!');
 await page.selectOption('#gp-lang-select','en');
 await page.waitForFunction(()=>document.querySelector('#fixture-notice').textContent==='Added successfully!');
 assert.equal(await page.locator('.folder-link span[data-gp-skip]').innerText(),'Visible');
 console.log('PASS dynamic notification translations and preserved category names');
 await page.goto('http://127.0.0.1:18976/importer.php');
 await page.waitForSelector('#gp-lang-select');
 await page.selectOption('#gp-lang-select','ar');
 await page.waitForFunction(()=>document.documentElement.lang==='ar');
 assert.equal(await page.locator('h3').innerText(),'اختيار مصدر');
 await page.setViewportSize({width:390,height:844});
 assert.equal(await page.evaluate(()=>document.documentElement.dir),'rtl');
 console.log('PASS importer selector and Arabic mobile direction');
 assert.deepEqual(errors,[]);
 console.log('PASS no browser JavaScript exceptions');
 // Check that none of the literal UI text fragments lack translations.
 await page.goto('http://127.0.0.1:18976/admin.php');
 const missing=await page.evaluate(()=>{const walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);const out=new Set();while(walker.nextNode()){const n=walker.currentNode;if(n.parentElement.closest('script,style,[data-gp-skip]'))continue;const text=(n.__gpOriginal||n.textContent).trim().replace(/\s+/g,' ');if(/[a-zA-ZÀ-ÿ]/.test(text)&&!['Z','×','Xtream','M3U','http://localhost'].includes(text)&&!Object.keys(GPanelUI.LANGS.fr).includes(text)&&!Object.values(GPanelUI.LANGS.en).includes(text))out.add(text)}return [...out]});
 assert.deepEqual(missing.filter(text=>text!=='XTREAM'&&!text.startsWith('https://fixture.invalid')),[]);
 console.log('PASS dashboard static labels covered by all three dictionaries');
 for (const width of [1440,768,390]) {
  await page.setViewportSize({width,height:900});
  for (const route of ['admin.php','admin.php?tab=clients','admin.php?tab=providers','editor.php','editor.php?cat_id=10','importer.php','importer.php?fournisseur_id=1','import_selective.php','import_selective.php?fournisseur_id=1','movie_duplicates.php']) {
   await page.goto('http://127.0.0.1:18976/'+route);await page.waitForSelector('html.gp-ready');
   assert.equal(await page.locator('.sidebar a[aria-current="page"]').count(),1,route);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),route+' overflows at '+width);
   if (route.startsWith('import')) {
    const main = page.locator('main,.main-content').first();
    const box = await main.boundingBox();
    assert(box && box.y < 200 && box.width > 200, route+' content pushed below viewport at '+width+': '+JSON.stringify(box));
    assert(await main.evaluate(el=>!!el.closest('form') || [...el.querySelectorAll('form')].some(f=>f.getBoundingClientRect().height>0)),route+' form invisible');
   }
   if(width===390){const toggle=page.locator('.gp-mobile-nav');await toggle.click();assert.equal(await toggle.getAttribute('aria-expanded'),'true');await page.keyboard.press('Escape');assert.equal(await toggle.getAttribute('aria-expanded'),'false');}
  }
  console.log('PASS all admin screens navigation and overflow at '+width);
 }
 assert.deepEqual(errors,[]);
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
