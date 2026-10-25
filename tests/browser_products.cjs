const {chromium}=require('playwright');
const {spawn}=require('node:child_process');
const net=require('node:net');
const path=require('node:path');
const assert=(v,m)=>{if(!v)throw Error(m);console.log('PASS '+m);};
(async()=>{
 const port=await new Promise(resolve=>{const s=net.createServer().listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const server=spawn('php',['-S',`127.0.0.1:${port}`,'-t','public','tests/router.php'],{cwd:path.resolve(__dirname,'..'),env:{...process.env,APP_ENV:'testing'},stdio:'ignore'});
 let browser;
 try{
  browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE}:{})});
  const page=await browser.newPage({viewport:{width:390,height:844}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const catalog={types:[{id:1,name:'Polo',active:1},{id:2,name:'T-shirt',active:1},{id:3,name:'Jacket',active:1}],zones:[1,2,3,4,5].map(id=>({id,name:'Zone '+id})),images:[],variants:[]};
  let submitted;
  await page.route('**/api/admin/products*',async route=>{
    if(route.request().method()==='POST'){submitted=route.request().postData();await route.fulfill({json:{ok:true}});}else await route.fulfill({json:catalog});
  });
  await page.goto(`http://127.0.0.1:${port}/admin`);
  // Exercise the real admin JS in a DOM harness; authentication/DB are not mocked as passed.
  await page.evaluate(()=>{document.querySelector('main').innerHTML='<form id="filters"></form><h2 id="module-title"></h2><div id="admin-content"></div><div id="editor"></div><div id="pagination"><button id="prev-page"></button><span id="page-label"></span><button id="next-page"></button></div><button id="logout"></button>';});
  await page.addScriptTag({url:`http://127.0.0.1:${port}/assets/admin.js`});
  await page.evaluate(c=>{data=c;moduleName='products';bulkVariantEditor();},catalog);
  await page.locator('#add-bulk-row').click();assert(await page.locator('.bulk-row').count()===2,'add size row');
  await page.locator('.remove-size').last().click();assert(await page.locator('.bulk-row').count()===1,'remove size row without inline handler');
  await page.locator('#add-bulk-row').click();
  const sizeInputs=page.locator('[name$="_size"]'),priceInputs=page.locator('[name$="_price"]');
  await sizeInputs.nth(0).fill('S');await priceInputs.nth(0).fill('100.01');await sizeInputs.nth(1).fill('M');await priceInputs.nth(1).fill('200');
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'size form fits mobile');
  await page.locator('#edit-form button[type=submit]').click();await page.locator('.swal2-confirm').click();
  await page.waitForFunction(()=>document.querySelector('#bulk-variant'));
  const rows=JSON.parse(submitted).variants;assert(rows.length===2&&rows[0].price==='100.01','one request carries both exact prices');
  await page.evaluate(()=>bulkImageEditor());assert(await page.locator('#edit-form input[type=file]').count()===15,'all 15 zone/type slots available');
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=','base64');
  await page.locator('#image_1_1').setInputFiles({name:'polo.png',mimeType:'image/png',buffer:png});
  await page.locator('#image_5_3').setInputFiles({name:'jacket.png',mimeType:'image/png',buffer:png});
  assert(await page.locator('#preview_image_1_1').isVisible(),'selected image preview visible');
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'image form fits mobile');
  await page.locator('#edit-form button[type=submit]').click();await page.locator('.swal2-confirm').click();
  await page.waitForFunction(()=>document.querySelector('#bulk-image'));
  assert(submitted.includes('name="image_1_1"')&&submitted.includes('name="image_5_3"'),'multiple zones and types sent together');
  assert(!submitted.includes('name="image_2_1"'),'empty slots omitted to preserve images');
  assert(errors.length===0,'no JavaScript errors');
 }finally{if(browser)await browser.close();server.kill();}
})().catch(e=>{console.error(e);process.exitCode=1;});
