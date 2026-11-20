// Generate fixtures first: php plugins/fatture-in-cloud-sync/tests/admin-ui.php --preview
// Chrome must be running locally with --remote-debugging-port=9222.
import { writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
const dir = new URL('../../../artifacts/fic-ui-0.8.0/', import.meta.url);
const target = await fetch('http://127.0.0.1:9222/json/new?about:blank', { method: 'PUT' }).then(r => r.json());
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
let next = 0;
const pending = new Map();
const errors = [];
socket.onmessage = ({data}) => {
    const message = JSON.parse(data);
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    if (!message.id) return;
    const {resolve, reject} = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) reject(new Error(JSON.stringify(message.error))); else resolve(message.result);
};
const call = (method, params = {}) => new Promise((resolve, reject) => { const id = ++next; pending.set(id, {resolve,reject}); socket.send(JSON.stringify({id,method,params})); });
const evaluate = async expression => {
    const result = await call('Runtime.evaluate', {expression, returnByValue:true, awaitPromise:true});
    if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
};
let checks = 0;
function check(value, message) { if (!value) throw new Error(message); checks++; }
async function navigate(section, width) {
    await call('Emulation.setDeviceMetricsOverride', {width, height:1000, deviceScaleFactor:1, mobile:width<600});
    await call('Page.navigate', {url:new URL(section+'.html',dir).href});
    for (let i=0;i<50;i++) {
        if (await evaluate(`document.readyState === 'complete' && document.querySelector('h1')?.textContent === ${JSON.stringify({panoramica:'Panoramica',documenti:'Documenti',rinnovi:'Rinnovi',impostazioni:'Impostazioni',assistenza:'Assistenza'}[section])}`)) break;
        await new Promise(resolve=>setTimeout(resolve,50));
    }
    const layout = await evaluate(`({width:innerWidth,scroll:document.documentElement.scrollWidth,main:!!document.querySelector('.fic-content'),nav:document.querySelectorAll('nav [aria-current="page"]').length,side:getComputedStyle(document.querySelector('#wpside')).backgroundColor, forms:document.querySelectorAll('form form').length})`);
    check(layout.main && layout.nav === 1,section+' renders navigation');
    check(layout.width <= width+1 && layout.scroll <= width+1,section+' overflows '+width+': '+JSON.stringify(layout));
    check(layout.side==='rgb(29, 35, 39)',section+' changes outer WordPress sidebar');
    check(layout.forms===0,section+' has nested forms');
}
async function screenshot(name) {
    const layout=await call('Page.getLayoutMetrics');
    const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width:layout.cssContentSize.width,height:layout.cssContentSize.height,scale:1}});
    await writeFile(new URL(name+'.png',dir),Buffer.from(shot.data,'base64'));
}
try {
    await call('Page.enable'); await call('Runtime.enable');
    for(const width of [1440,1024,768,390,320]) for(const section of ['panoramica','documenti','rinnovi','impostazioni','assistenza']) {
        await navigate(section,width);
        if(width===1440 && ['panoramica','documenti','rinnovi','impostazioni'].includes(section)) await screenshot(section+'-desktop');
        if(width===390 && ['panoramica','documenti','impostazioni'].includes(section)) await screenshot(section+'-mobile');
    }
    await navigate('impostazioni',1440);
    check(await evaluate(`document.querySelector('input[name="plans[4][first]"]').checked && document.querySelector('input[name="plans[4][renewals]"]').checked`),'Selected plan exposes both first and renewal rules');
    check(await evaluate(`!document.querySelector('input[name="plans[3][first]"]').checked && !document.querySelector('input[name="plans[5][renewals]"]').checked`),'Excluded and first-only plans shown accurately');
    check(await evaluate(`document.querySelector('input[name="confirm_plan_rules"]').required && document.querySelector('input[name="fic_action"][value="plan_rules_save"]') !== null`),'Explicit plan confirmation and real server action present');
    await navigate('documenti',1440);
    check(await evaluate(`!document.querySelector('[data-fic-filters]').hidden`),'Search is enabled by JS');
    await evaluate(`document.querySelector('[data-fic-search]').value='9142';document.querySelector('[data-fic-search]').dispatchEvent(new Event('input'))`);
    check(await evaluate(`document.querySelectorAll('[data-fic-state]:not([hidden])').length===1`),'Search by document ID');
    await evaluate(`document.querySelector('[data-fic-search]').value='not-found';document.querySelector('[data-fic-search]').dispatchEvent(new Event('input'))`);
    check(await evaluate(`!document.querySelector('[data-fic-empty]').hidden`),'Empty filter result visible');
    await evaluate(`document.querySelector('[data-fic-search]').value='';document.querySelector('[data-fic-filter]').value='review';document.querySelector('[data-fic-filter]').dispatchEvent(new Event('change'))`);
    check(await evaluate(`document.querySelectorAll('[data-fic-state]:not([hidden])').length===1 && document.querySelector('[data-fic-state="attention"]').hidden===false`),'State filter');
    await call('Emulation.setScriptExecutionDisabled',{value:true});
    await navigate('documenti',390);
    check(await evaluate(`document.querySelector('[data-fic-filters]').hidden && document.querySelectorAll('[data-fic-state]:not([hidden])').length===4 && document.querySelectorAll('form').length>0`),'No-JS view preserves forms and full queue');
    await call('Emulation.setScriptExecutionDisabled',{value:false});
    check(errors.length===0,'No browser JS errors: '+errors.join(', '));
    console.log(`PASS: ${checks} browser checks. Five PHP fixture views, widths 320–1440, search, filters, no JS. Screenshots: ${fileURLToPath(dir)}`);
} finally { await call('Page.close'); socket.close(); }
