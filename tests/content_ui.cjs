const {chromium}=require(process.env.PLAYWRIGHT_PATH || 'playwright');
const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',route=>route.request().url().startsWith(process.env.FIXTURE_BASE)?route.continue():route.abort());
 await page.goto(process.env.FIXTURE_BASE+'/__fixture.php');
 for(const width of [1440,390]){
  await page.setViewportSize({width,height:900});
  for(const path of ['panel_diagnostics.php','personal_content.php','content_edit.php?kind=live','import_selective.php?fournisseur_id=1&kind=movie']){
   await page.goto(process.env.FIXTURE_BASE+'/'+path);await page.waitForSelector('html.gp-ready');
   assert.equal(await page.locator('h1').count(),1);
   assert(await page.locator('h1').isVisible());
   assert((await page.locator('h1').boundingBox()).y<220,'heading below initial screen');
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'page overflows '+path+' '+width);
   if(process.env.UI_OUTPUT && path==='panel_diagnostics.php')await page.screenshot({path:process.env.UI_OUTPUT+'/diagnostic-'+width+'.png',fullPage:true});
   console.log('PASS UI '+width+' '+path);
  }
 }
 assert.deepEqual(errors,[]);await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
