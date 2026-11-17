/* Local read-only browser audit. Requires playwright-core, axe-core and installed Chrome.
   Run a PHP test server with session.save_path=<workspace>/runtime/a11y-sessions on port 8099. */
const { chromium } = require('playwright-core');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const root = path.resolve(__dirname, '..');
const base = 'http://127.0.0.1:8099';
const sessionPath = path.join(root,'runtime','a11y-sessions');
fs.mkdirSync(sessionPath,{recursive:true});
const php = `session_save_path(${JSON.stringify(sessionPath.replaceAll('\\','/'))});require 'includes/geonet.php';$sessions=[];foreach(['admin','technician','subscriber'] as $role){$q=geonet_pdo()->prepare('SELECT id,display_name FROM users WHERE role=? LIMIT 1');$q->execute([$role]);$u=$q->fetch();if(!$u)continue;session_id('audit-'.bin2hex(random_bytes(12)));session_start();$_SESSION=['logged_in'=>true,'role'=>$role,'user_id'=>$u['id'],'display_name'=>$u['display_name'],'username'=>'Audit','profile_csrf_token'=>bin2hex(random_bytes(32))];if($role==='technician'){$q=geonet_pdo()->prepare('SELECT public_id FROM technicians WHERE user_id=?');$q->execute([$u['id']]);$_SESSION['tech_id']=$q->fetchColumn();}$sessions[$role]=session_id();session_write_close();}echo json_encode($sessions);`;
const sessions=JSON.parse(execFileSync('C:/xampp/php/php.exe',['-r',php],{cwd:root,encoding:'utf8'}));
(async()=>{
    const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
    const report=[];
    try {
        for(const role of ['public',...Object.keys(sessions)].filter(role=>!process.env.AUDIT_ROLE || role===process.env.AUDIT_ROLE)){
            const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
            if(sessions[role]) await context.addCookies([{name:'PHPSESSID',value:sessions[role],url:base}]);
            const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
            const pages=role==='public'?['index.php','signup.php']:role==='admin'?['dashboard.php#dashboard','dashboard.php#subscribers','dashboard.php#technicians','dashboard.php#maintenance','dashboard.php#analytics','dashboard.php#settings','dashboard.php#sms','interruptions.php','profile.php']:role==='technician'?['technician.php','technician.php#logs','technician.php#profile','interruptions.php','maintenance-work.php','profile.php']:['subscriber.php','subscriber.php#profile','subscription.php','interruptions.php'];
            for(const route of pages){
                await page.goto(base+'/'+route,{waitUntil:'networkidle',timeout:30000});await page.waitForTimeout(600);
                await page.addScriptTag({path:require.resolve('axe-core/axe.min.js')});
                const result=await page.evaluate(async()=>{const r=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}});return r.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary})),help:v.help}));});
                const data={role,route,violations:result,errors:errors.splice(0)};report.push(data);console.log(role,route,'violations='+result.length,'errors='+data.errors.length);
                await page.screenshot({path:path.join(root,'runtime',`audit-${role}-${route.replace(/[^a-z0-9]/gi,'-')}.png`),fullPage:true});
            }
            if(role==='admin') {
                await page.goto(base+'/dashboard.php#dashboard',{waitUntil:'networkidle'});
                await page.locator('#toggleMapEditor').click();await page.locator('#toggleBarangayManager').click();
                if(await page.locator('#barangaySelect option').count()>1)await page.locator('#barangaySelect').selectOption({index:1});
                await page.addScriptTag({path:require.resolve('axe-core/axe.min.js')});
                const toolViolations=await page.evaluate(async()=>(await axe.run({runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}})).violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})));
                // W3C 2.5.8 Essential explicitly allows accurately positioned, dense map pins.
                // Preserve the raw findings for human review; do not disable target-size globally.
                const mapExceptions=toolViolations.filter(v=>v.id==='target-size' && v.nodes.every(n=>n.target.every(t=>typeof t==='string' && t.includes('leaflet-marker-icon'))));
                const unresolved=toolViolations.filter(v=>!mapExceptions.includes(v));
                report.push({role,route:'expanded map tools',violations:unresolved,reviewedMapExceptions:mapExceptions,exceptionReference:'https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html#exceptions'});console.log('expanded map tools unresolved='+unresolved.length+' reviewed map exceptions='+mapExceptions.length);
                await page.goto(base+'/dashboard.php#subscribers',{waitUntil:'networkidle'});await page.locator('#showAddSubscriber').click();await page.waitForTimeout(200);
                await page.addScriptTag({path:require.resolve('axe-core/axe.min.js')});
                const dialogViolations=await page.evaluate(async()=>(await axe.run({runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}})).violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})));
                report.push({role,route:'subscriber dialog',violations:dialogViolations});console.log('subscriber dialog violations='+dialogViolations.length);
                const focusInside=await page.evaluate(()=>document.getElementById('addSubscriberModal').contains(document.activeElement));
                await page.keyboard.press('Escape');await page.waitForTimeout(200);
                const closed=await page.locator('#addSubscriberModal').evaluate(el=>!el.classList.contains('open'));
                report.push({role,route:'dialog keyboard',errors:focusInside&&closed?[]:['Dialog focus or Escape failed']});console.log('dialog keyboard='+(focusInside&&closed));
                await page.goto(base+'/dashboard.php#dashboard',{waitUntil:'networkidle'});await page.setViewportSize({width:390,height:844});await page.waitForTimeout(400);
                const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
                console.log('mobile overflow='+overflow);report.push({role,route:'mobile',overflow});
                await page.screenshot({path:path.join(root,'runtime','audit-mobile.png'),fullPage:true});
                await page.setViewportSize({width:320,height:800});await page.waitForTimeout(200);
                const narrowOverflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
                report.push({role,route:'320px reflow',overflow:narrowOverflow});console.log('320px overflow='+narrowOverflow);
            }
            await context.close();
        }
    }finally{await browser.close();for(const id of Object.values(sessions)){const file=path.join(sessionPath,'sess_'+id);if(fs.existsSync(file))fs.unlinkSync(file);}fs.writeFileSync(path.join(root,'runtime','accessibility-audit.json'),JSON.stringify(report,null,2));}
    process.exitCode=report.some(r=>r.violations?.length||r.errors?.length||r.overflow)?1:0;
})().catch(error=>{console.error(error.message);process.exitCode=1;});
