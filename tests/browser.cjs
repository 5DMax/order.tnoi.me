/* Development-only browser tests. Catalog and order APIs are mocked; no MySQL/live API claims. */
const {chromium}=require('playwright');
const {spawn}=require('node:child_process');
const net=require('node:net');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const assert=(v,m)=>{if(!v)throw Error(m);console.log('PASS '+m);};
(async()=>{
 const port=await new Promise(resolve=>{const s=net.createServer().listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const server=spawn('php',['-S',`127.0.0.1:${port}`,'-t','public','tests/router.php'],{cwd:root,env:{...process.env,APP_ENV:'testing',APP_KEY:'browser-test-only-key-not-production-1234'},stdio:'ignore'});
 let browser;
 try{
  browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE}:{})});
  const context=await browser.newContext({viewport:{width:390,height:844}});
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const catalog={ready:true,zones:[{id:1,name:'ภูธานี'},{id:2,name:'สนุก'}],provinces:[{id:1,zone_id:1,name:'หนองคาย'},{id:6,zone_id:2,name:'นครพนม'}],types:[{id:1,name:'Polo',active:1},{id:2,name:'เสื้อคอกลม',active:1},{id:3,name:'Jacket',active:0}],variants:[{id:1,product_type_id:1,size:'S',price_satang:10001,active:1},{id:2,product_type_id:1,size:'M',price_satang:20002,active:1},{id:3,product_type_id:2,size:'L',price_satang:null,active:1}],images:[]};
  await page.route('**/api/catalog',r=>r.fulfill({json:catalog}));
  let submitted;
  await page.route('**/api/orders',async r=>{submitted=r.request().postDataJSON();await r.fulfill({json:{reference:'TEST-ORDER',token:'a'.repeat(64)}});});
  await page.route('**/api/order',r=>r.fulfill({json:{reference:'TEST-ORDER',status:'pending_payment',total_satang:80008,attempts:[],bank:{bank_name:'TEST BANK',name_th:'TEST',bank_number:'0000000000'},contact:{contact_phone:'0000000000'}}}));
  await page.goto(`http://127.0.0.1:${port}`);await page.waitForFunction(()=>document.querySelector('#zone option[value="1"]'));
  await page.locator('#select2-zone-container').click();await page.getByRole('option',{name:'ภูธานี',exact:true}).click();
  await page.locator('#select2-province-container').click();await page.getByRole('option',{name:'หนองคาย',exact:true}).click();
  await page.locator('#next').click();await page.locator('#qty-1').fill('2');await page.locator('#qty-2').fill('3');
  assert(await page.locator('#qty-3').isDisabled(),'unpriced variant disabled');
  assert((await page.locator('#live-total').textContent()).includes('800.08'),'client exact satang total');
  await page.locator('#next').click();assert((await page.locator('#summary').textContent()).includes('800.08'),'summary exact total');
  assert((await page.locator('#summary').textContent()).includes('5 ตัว'),'multi-size quantity summary');
  await page.locator('#back').click();assert(await page.locator('#qty-2').inputValue()==='3','back preserves quantities');await page.locator('#next').click();await page.locator('#next').click();
  await page.locator('#name').fill('ผู้ทดสอบ ระบบ');await page.locator('#phone').fill('0812345678');await page.locator('#address').fill('ที่อยู่ทดสอบสำหรับจัดส่งสินค้า');await page.locator('#privacy').check();await page.locator('#next').click();await page.locator('#payment').waitFor({state:'visible'});
  assert(submitted.items.length===2&&submitted.privacy===true,'checkout posts multi-size items and acknowledgement');
  assert(!('price' in submitted.items[0]),'client does not submit authoritative price');
  assert(page.url().includes('#reference='),'access token in fragment');
  assert(!new URL(page.url()).search,'access token absent from query string');
  assert(await page.locator('#access-link').inputValue()===page.url(),'resume link presented');
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'mobile page has no horizontal overflow');
  await page.screenshot({path:'/tmp/sport-shirts-mobile.png',fullPage:true});
  await page.setViewportSize({width:1440,height:1000});await page.goto(`http://127.0.0.1:${port}/`);await page.screenshot({path:'/tmp/sport-shirts-desktop.png',fullPage:true});
  assert(errors.length===0,'no browser JavaScript errors: '+errors.join('; '));
  await page.goto(`http://127.0.0.1:${port}/admin`);assert(await page.locator('#login').isVisible(),'admin login browser render');
  console.log('Browser flow passed with mocked APIs; screenshots /tmp/sport-shirts-{mobile,desktop}.png');
 }finally{if(browser)await browser.close();server.kill();}
})().catch(e=>{console.error(e);process.exitCode=1;});
