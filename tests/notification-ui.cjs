const {chromium}=require('playwright-core');
const path=require('path');
const root=path.resolve(__dirname,'..');
(async()=>{
    const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
    try {
        for(const role of ['admin','technician','subscriber']){
            const page=await browser.newPage();let cleared=[],navigation=false;
            let items=[{id:1,title:'Connection update',message:'First notification',link:'/details',created_at:'2026-11-17T10:00:00',unread:1},{id:2,title:'Second update',message:'Keep this notification',link:'/details',created_at:'2026-11-17T10:00:00',unread:1}];
            if(role==='admin')items.unshift({id:null,activity_key:'interruption:42',interruption_id:42,title:'Repair report',message:'Assigned repair',link:'/details',created_at:'2026-11-17T10:00:00',unread:0,assignment:{kind:'interruption',id:42,assigned:false}});
            await page.route('http://geonet.test/**',route=>{
                const req=route.request();
                if(req.url().endsWith('/fixture'))return route.fulfill({contentType:'text/html',body:`<!doctype html><html lang="en"><head><title>Notification test</title></head><body><main><h1>${role} notifications</h1><div data-notification-center data-csrf-token="test-token"><button class="notification-trigger" aria-label="Notifications">Notifications<span class="notification-count" data-notification-count hidden></span></button><section data-notification-panel hidden><div data-notification-list></div></section></div>${role==='admin'?'<section class="dashboard-notification-feed"><div data-notification-list></div></section>':''}</main></body></html>`});
                if(req.url().endsWith('/api/notifications.php')){
                    if(req.method()==='POST'){
                        const body=req.postDataJSON();if(body.csrfToken!=='test-token')throw new Error('Missing CSRF token');
                        if(body.action!=='clear_one')throw new Error('Dismiss invoked a different action');
                        cleared.push(body);items=items.filter(item=>body.notificationId!=null?item.id!==body.notificationId:item.activity_key!==body.activityKey);
                        return route.fulfill({json:{ok:true}});
                    }
                    return route.fulfill({json:{ok:true,activity:items,notifications:items,assignment_technicians:[{id:1,full_name:'Technician',availability:'available'}],unread_count:items.filter(n=>n.unread).length}});
                }
                navigation=true;return route.fulfill({body:'Unexpected navigation'});
            });
            await page.goto('http://geonet.test/fixture');
            await page.addStyleTag({path:path.join(root,'assets/app.css')});await page.addStyleTag({path:path.join(root,'assets/design-system.css')});
            await page.addScriptTag({path:path.join(root,'assets/notifications.js')});
            await page.locator('.notification-trigger').click();
            const firstTitle=role==='admin'?'Repair report':'Connection update';
            await page.getByRole('button',{name:'Dismiss '+firstTitle,exact:true}).first().click();
            await page.waitForFunction(title=>!Array.from(document.querySelectorAll('.notification-title')).some(n=>n.textContent===title),firstTitle);
            if(cleared.length!==1||navigation)throw new Error(role+' dismissal navigated or cleared multiple items');
            if(role==='admin' && cleared[0].activityKey!=='interruption:42')throw new Error('Synthetic activity key missing');
            if(role!=='admin' && await page.locator('.notification-count').textContent()!=='1')throw new Error('Unread count did not update: '+await page.locator('.notification-count').textContent());
            if(!await page.getByText('Second update',{exact:true}).count())throw new Error('Unrelated notification removed');
            console.log('PASS: '+role+' dismisses one notification without navigating or clearing other items');
            await page.close();
        }
    }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
