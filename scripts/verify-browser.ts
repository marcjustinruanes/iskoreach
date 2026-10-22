// Dependency-free Chrome smoke check. Start Laravel with Vite or built assets.
import { spawn } from 'node:child_process';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import assert from 'node:assert/strict';

const browserPath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const appUrl = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const profile = await mkdtemp(join(tmpdir(), 'iskoreach-browser-'));
const browser = spawn(browserPath, [
    '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank',
], { stdio: 'ignore', windowsHide: true });
const delay = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));
let socket: WebSocket | undefined;
let chatSocket: WebSocket | undefined;
try {
    let port = '';
    for (let attempt = 0; attempt < 100; attempt++) {
        try { port = (await readFile(join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; break; }
        catch { await delay(100); }
    }
    assert.ok(port, 'Chrome remote debugging must start');
    const target = await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' }).then(r => r.json());
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise<void>((resolve, reject) => { socket!.onopen = () => resolve(); socket!.onerror = reject; });
    let sequence = 0;
    const pending = new Map<number, { resolve: (value: Record<string, unknown>) => void; reject: (error: Error) => void }>();
    const errors: string[] = [];
    let webSocketRequests = 0;
    socket.onmessage = event => {
        const message = JSON.parse(String(event.data));
        if (message.method === 'Runtime.exceptionThrown') errors.push(JSON.stringify(message.params));
        if (message.method === 'Network.webSocketCreated' && !String(message.params.url).includes(':5173')) webSocketRequests++;
        const request = pending.get(message.id);
        if (request) {
            pending.delete(message.id);
            if (message.error) request.reject(new Error(JSON.stringify(message.error)));
            else request.resolve(message.result);
        }
    };
    const command = (method: string, params: Record<string, unknown> = {}) => new Promise<Record<string, unknown>>((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => { pending.delete(id); reject(new Error('Chrome command timed out: ' + method)); }, 30000);
        pending.set(id, { resolve: value => { clearTimeout(timeout); resolve(value); }, reject: error => { clearTimeout(timeout); reject(error); } });
        socket!.send(JSON.stringify({ id, method, params }));
    });
    const evaluate = async (expression: string) => {
        const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        return (result.result as { value: unknown }).value;
    };
    const waitFor = async (expression: string) => {
        for (let attempt = 0; attempt < 100; attempt++) {
            if (await evaluate(expression)) return;
            await delay(100);
        }
        throw new Error(`Timed out: ${expression}; feedback: ${await evaluate("[...document.querySelectorAll('[role=alert]')].map(item=>item.textContent).join(' | ')")}`);
    };
    await command('Runtime.enable');
    await command('Network.enable');
    await command('Page.enable');
    await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
    await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await command('Page.navigate', { url: appUrl });
    await waitFor("document.querySelector('h1')?.textContent.includes('Find scholarships.')");
    await waitFor("getComputedStyle(document.querySelector('h1')).fontSize === '48px'");
    await evaluate('document.fonts.ready.then(() => true)');
    assert.equal(await evaluate("(() => { const style = getComputedStyle(document.querySelector('h1')); return parseFloat(style.lineHeight) >= parseFloat(style.fontSize); })()"), true, 'Hero headline lines do not overlap');
    await waitFor("[...document.querySelectorAll('a[aria-label=\"IskoReach home\"] img')].length > 0 && [...document.querySelectorAll('a[aria-label=\"IskoReach home\"] img')].every(image => image.complete && image.naturalWidth > 0)");
    assert.equal(await evaluate("[...document.querySelectorAll('a[aria-label=\"IskoReach home\"] img')].every(image => image.getAttribute('src') === '/images/iskoreach-logo.png')"), true, 'Shared branding uses the repository logo');
    assert.equal(await evaluate("document.querySelector('link[rel=icon]').getAttribute('href').endsWith('/images/iskoreach-logo.png')"), true, 'Browser icon uses the shared logo');
    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
    assert.equal(await evaluate("[...document.fonts].some(font => font.family.includes('Plus Jakarta Sans') && font.status === 'loaded')"), true, 'Stitch font must actually load');
    assert.equal(await evaluate("document.querySelector('h1').getAnimations().length"), 0, 'Hero remains readable without decorative motion');
    await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    assert.equal(await evaluate("getComputedStyle(document.querySelector('#how-it-works')).opacity"), '1');
    await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
    assert.equal(await evaluate("document.querySelectorAll('[data-testid=scholarship-grid] article').length <= 3"), true, 'Preview contains at most three real programs');
    if (process.env.QA_LANDING_PREVIEW_CHECK === 'true') {
        assert.equal(await evaluate("document.querySelectorAll('[data-testid=scholarship-grid] article').length"), 3);
        assert.equal(await evaluate("document.body.textContent.includes('4 published programs')"), true, 'Directory statistics come from the isolated fixture records');
        assert.equal(await evaluate("document.querySelector('[data-testid=scholarship-grid]').textContent.includes('QA Scholarship Foundation')"), true);
        await evaluate("document.querySelector('[data-testid=scholarship-grid] article h3 a').click()");
        await waitFor("document.querySelector('h1')?.textContent.startsWith('QA Education Grant')");
        await command('Page.navigate', { url: appUrl });
        await waitFor("document.querySelector('[data-testid=scholarship-grid] article') !== null");
    }
    assert.equal(await evaluate("document.querySelectorAll('nav[aria-label=\"Main navigation\"] a').length"), 4, 'Primary navigation remains concise');
    assert.equal(await evaluate("document.querySelector('footer a[href=\"/privacy\"]') !== null && document.querySelector('footer a[href=\"/terms\"]') !== null"), true);
    assert.equal(await evaluate("document.body.textContent.includes('120+') || document.body.textContent.includes('?48M+') || document.body.textContent.includes('Sample provider workspace')"), false);
    assert.equal(await evaluate("document.querySelector('form[aria-label=\"Find scholarships\"]') !== null"), true);
    const screenshotDirectory = process.env.QA_SCREENSHOT_DIR;
    if (screenshotDirectory) await mkdir(screenshotDirectory, { recursive: true });
    for (const width of [320, 640, 768, 1024, 1280, 1536, 1920]) {
        await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
        await evaluate('window.scrollTo(0, 0)');
        await delay(100);
        assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true, 'Landing should fit ' + width + 'px');
        if (screenshotDirectory && [320, 1280, 1920].includes(width)) {
            const metrics = await command('Page.getLayoutMetrics');
            const content = metrics.cssContentSize as { width: number; height: number };
            const capture = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: content.width, height: content.height, scale: 1 } });
            await writeFile(join(screenshotDirectory, 'landing-' + width + '.png'), Buffer.from(capture.data as string, 'base64'));
            if (width === 320 || width === 1280) {
                const heroCapture = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: content.width, height: Math.min(content.height, width === 320 ? 1500 : 850), scale: 1 } });
                await writeFile(join(screenshotDirectory, 'landing-hero-' + width + '.png'), Buffer.from(heroCapture.data as string, 'base64'));
            }
        }
    }
    await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 844, deviceScaleFactor: 1, mobile: true });
    await evaluate("document.querySelector('button[aria-label=\"Open navigation menu\"]').click()");
    await waitFor("document.querySelector('[role=dialog]') !== null");
    await evaluate("document.querySelector('[role=dialog] a[href=\"/#about\"]').click()");
    await waitFor("document.querySelector('[role=dialog]') === null");
    await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
    await waitFor('navigator.onLine === false');
    await evaluate("document.querySelector('form[aria-label=\"Find scholarships\"]').requestSubmit()");
    await waitFor("document.body.textContent.includes('You are offline')");
    await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    await waitFor('navigator.onLine === true');
    await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await evaluate("document.querySelector('button').blur(); [...document.querySelectorAll('button')].find(button => button.textContent.includes('How provider approval works')).click()");
    await waitFor("document.querySelector('[role=dialog]') !== null");
    await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await waitFor("document.querySelector('[role=dialog]') === null");
    await evaluate("(() => { const input = document.querySelector('input[name=q]'); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, 'landing-browser-check'); input.dispatchEvent(new Event('input', { bubbles: true })); })()");
    await evaluate("document.querySelector('form[aria-label=\"Find scholarships\"]').requestSubmit()");
    await waitFor("document.querySelector('h1')?.textContent === 'Find a scholarship'");
    assert.equal(await evaluate("new URL(location.href).searchParams.get('q')"), 'landing-browser-check', 'Landing search reaches the real directory with its filters');
    await command('Page.navigate', { url: appUrl });
    await waitFor("document.querySelector('h1')?.textContent.includes('Find scholarships.')");
    await evaluate('window.__inertiaNavigationCheck = true');
    await evaluate("document.querySelector('a[href$=\"/login\"]').click()");
    await waitFor("document.querySelector('h1')?.textContent === 'Log in'");
    assert.equal(await evaluate('window.__inertiaNavigationCheck'), true, 'Inertia navigation should preserve the page context');
    assert.equal(await evaluate("document.querySelectorAll('input[type=password]').length"), 1);
    assert.equal(await evaluate("getComputedStyle(document.querySelector('input[type=email]')).fontSize"), '16px');
    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontSize"), '24px');
    if (screenshotDirectory) {
        await mkdir(screenshotDirectory, { recursive: true });
        const capture = await command('Page.captureScreenshot', { format: 'png' });
        await writeFile(join(screenshotDirectory, 'login-desktop.png'), Buffer.from(capture.data as string, 'base64'));
    }
    await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true, 'Mobile page should not overflow');
    await evaluate("document.querySelector('a[href$=\"/register\"]').click()");
    await waitFor("document.querySelector('h1')?.textContent === 'Student registration'");
    await evaluate("document.querySelector('a[href=\"/register/provider\"]').click()");
    await waitFor("document.querySelector('input[name=position]') !== null");
    assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true, 'Provider registration should fit mobile width');
    assert.equal(await evaluate("[...document.querySelectorAll('input:not([type=hidden])')].every(input => input.labels.length > 0)"), true, 'Each registration field needs a label');
    if (screenshotDirectory) {
        const capture = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        await writeFile(join(screenshotDirectory, 'register-mobile.png'), Buffer.from(capture.data as string, 'base64'));
    }
    if (process.env.QA_AUDIT_CORRECTION_CHECK === 'true' || process.env.QA_PROFILE_PAGES_CHECK === 'true' || process.env.QA_PAGINATION_CHECK === 'true' || process.env.QA_DASHBOARD_REDESIGN_CHECK === 'true' || process.env.QA_SIDEBAR_RAIL_CHECK === 'true' || process.env.QA_DEMO_PROVIDERS_CHECK === 'true' || process.env.QA_SYSTEM_UI_CHECK === 'true' || process.env.QA_ACCOUNT_ENHANCEMENTS_CHECK === 'true' || process.env.QA_STUDENT_CATALOG_CHECK === 'true' || process.env.QA_LOGOUT_CHECK === 'true' || process.env.QA_STUDENT_REGISTRATION_CHECK === 'true' || process.env.QA_WORKFLOW_COMPLETION_CHECK === 'true' || process.env.QA_INPUT_VALIDATION_CHECK === 'true' || process.env.QA_ACADEMIC_TERMS_CHECK === 'true' || process.env.QA_PUBLIC_SITE_CHECK === 'true' || process.env.QA_LOGIN_REDIRECT_CHECK === 'true' || process.env.QA_STUDENT_STAGE1_CHECK === 'true' || process.env.QA_LOCATION_CHECK === 'true' || process.env.QA_HIDDEN_SCROLLBARS_CHECK === 'true' || process.env.QA_PROVIDER_UX_CHECK === 'true' || process.env.QA_BENEFITS_CHECK === 'true' || process.env.QA_RENEWALS_CHECK === 'true' || process.env.QA_CHAT_CHECK === 'true' || process.env.QA_NOTIFICATIONS_CHECK === 'true' || process.env.QA_PRIVATE_DOCUMENTS_CHECK === 'true' || process.env.QA_APPLICATION_WORKFLOW_CHECK === 'true' || process.env.QA_SCHOLARSHIP_MANAGEMENT_CHECK === 'true' || process.env.QA_PROVIDER_STAGE2_CHECK === 'true' || process.env.QA_AUTH_CHECK === 'true' || process.env.QA_SCHOLARSHIP_READONLY_CHECK === 'true' || process.env.QA_APPLICANT_COUNTS_CHECK === 'true' || process.env.QA_AWARD_STAGE6_CHECK === 'true' || process.env.QA_ADMIN_LAYOUT_CHECK === 'true' || process.env.QA_SIDEBAR_MOTION_CHECK === 'true' || process.env.QA_PROVIDER_STAGE1_CHECK === 'true') {
        const fill = async (selector: string, value: string) => {
            await evaluate('document.querySelector(' + JSON.stringify(selector) + ').focus()');
            await command('Input.insertText', { text: value });
        };
        const screenshot = async (name: string) => {
            if (!screenshotDirectory) return;
            await evaluate('window.scrollTo(0, 0)');
            await delay(250);
            const metrics = await command('Page.getLayoutMetrics');
            const content = metrics.cssContentSize as { width: number; height: number };
            const capture = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: content.width, height: content.height, scale: 1 } });
            await writeFile(join(screenshotDirectory, name + '.png'), Buffer.from(capture.data as string, 'base64'));
        };
        const login = async (role: string, heading: string) => {
            await command('Page.navigate', { url: appUrl + '/login' });
            await waitFor("document.querySelector('input[name=email]') !== null");
            await fill('input[name=email]', role + '@iskoreach.test');
            await fill('input[name=password]', 'temporary-test-password');
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor('document.querySelector(\'h1\')?.textContent === ' + JSON.stringify(heading));
        };
        const openSignOut = async () => {
            const visibleButton = "[...document.querySelectorAll('button')].find(button=>(button.getAttribute('aria-label')==='Sign out' || button.textContent.trim()==='Log out') && button.getBoundingClientRect().width>0)";
            if (!await evaluate('Boolean('+visibleButton+')')) {
                await evaluate("[...document.querySelectorAll('button')].find(button=>/^Open .*navigation$/.test(button.getAttribute('aria-label')||'')).click()");
                await waitFor("document.querySelector('[role=dialog] button[aria-label=\"Sign out\"]') !== null");
            }
            await evaluate(visibleButton+'.click()');
        };
        const logout = async () => {
            if (await evaluate("document.querySelector('header a[href=\"/dashboard\"]') !== null")) {
                await evaluate("document.querySelector('header a[href=\"/dashboard\"]').click()");
                await waitFor("document.querySelector('button[aria-label=\"Sign out\"]') !== null");
            }
            await openSignOut();
            await waitFor("document.querySelector('[role=dialog]')?.textContent.includes('Would you like to sign out?')");
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Sign-out confirmation fits ' + width);
            }
            const beforeCancel = await evaluate('location.pathname');
            await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
            await waitFor('navigator.onLine === false');
            await evaluate("[...document.querySelectorAll('[role=dialog] button')].find(button => button.textContent.trim() === 'Yes, sign out').click()");
            await waitFor("document.querySelector('[role=dialog]').textContent.includes('You are offline')");
            await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            await waitFor('navigator.onLine === true');
            await evaluate("[...document.querySelectorAll('[role=dialog] button')].find(button => button.textContent.trim() === 'Stay signed in').click()");
            await waitFor("document.querySelector('[role=dialog]') === null");
            assert.equal(await evaluate('location.pathname'), beforeCancel, 'Cancelling sign out keeps the session and page');
            await openSignOut();
            await waitFor("document.querySelector('[role=dialog]')?.textContent.includes('Would you like to sign out?')");
            await evaluate("[...document.querySelectorAll('[role=dialog] button')].find(button => button.textContent.trim() === 'Yes, sign out').click()");
            await waitFor("document.querySelector('h1')?.textContent.includes('Find scholarships.')");
        };
        if (process.env.QA_AUDIT_CORRECTION_CHECK === 'true') {
            assert.ok(process.env.QA_REGISTRATION_DB?.includes('iskoreach-registration-'));
            const applicationId = await new Promise<number>((resolve, reject) => {
                const php = spawn('php', [], { windowsHide:true, env:{...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:process.env.QA_REGISTRATION_DB,DB_URL:'',APP_URL:appUrl,DOCUMENT_STORAGE_ROOT:process.env.QA_REGISTRATION_DB+'-documents'},stdio:['pipe','pipe','pipe'] });
                let output=''; let error=''; php.stdout.on('data',chunk=>output+=chunk); php.stderr.on('data',chunk=>error+=chunk);
                php.on('close',code=>code===0?resolve(Number(output.trim())):reject(new Error(error)));
                php.stdin.end(`<?php
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if(config('database.default')!=='sqlite'||!str_contains(config('database.connections.sqlite.database'),'iskoreach-registration-'))throw new RuntimeException('Isolated database required.');
$student=App\\Models\\User::where('email','logout-student@iskoreach.test')->firstOrFail();
$provider=App\\Models\\User::where('email','logout-provider@iskoreach.test')->firstOrFail();
$program=App\\Models\\Scholarship::factory()->create(['provider_id'=>$provider->providerProfile->id,'title'=>'QA document correction browser check','status'=>'published','published_at'=>now()]);
$rule=App\\Models\\ScholarshipRequirement::factory()->create(['scholarship_id'=>$program->id,'name'=>'Current COR','requirement_stage'=>'application','allowed_mime_types'=>['image/png'],'max_size_kb'=>1024]);
$item=App\\Models\\Application::factory()->create(['scholarship_id'=>$program->id,'student_id'=>$student->id,'status'=>'under_review']);
$source=app(App\\Services\\PrivateDocuments::class)->upload($student,Illuminate\\Http\\UploadedFile::fake()->image('unclear.png'),'Enrollment proof');
$attachment=App\\Models\\ApplicationDocument::create(['application_id'=>$item->id,'scholarship_requirement_id'=>$rule->id,'student_document_id'=>$source->id,'version'=>1,'checklist_completed_at'=>now(),'requirement_snapshot'=>$rule->toArray()]);
$attachment->forceFill(['submitted_at'=>now(),'review_status'=>'rejected','review_notes'=>'Please upload a clear current COR'])->save(); echo $item->id;`);
            });
            assert.ok(applicationId>0);
            await login('logout-student','Your student account');
            await command('Page.navigate',{url:appUrl+'/student/applications/'+applicationId});
            await waitFor("document.querySelector('h1')?.textContent === 'Application details' && document.body.textContent.includes('Please upload a clear current COR')");
            assert.equal(await evaluate("document.body.textContent.includes('Document review and corrections') && document.body.textContent.includes('Resubmit correction')"),true);
            for(const width of [320,768,1440]) {
                await command('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
                assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true);
                await screenshot('audit-document-correction-'+width);
            }
            const png=await evaluate("(()=>{const canvas=document.createElement('canvas');canvas.width=128;canvas.height=128;canvas.getContext('2d').fillRect(0,0,128,128);return canvas.toDataURL('image/png').split(',')[1]})()") as string;
            const file=join(profile,'corrected-cor.png'); await writeFile(file,Buffer.from(png,'base64'));
            const document=await command('DOM.getDocument'); const node=await command('DOM.querySelector',{nodeId:(document.root as {nodeId:number}).nodeId,selector:'main input[type=file]'});
            await command('DOM.setFileInputFiles',{nodeId:node.nodeId,files:[file]});
            await evaluate("document.querySelector('main input[type=file]').closest('form').querySelector('input[type=checkbox]').click()");
            await evaluate("document.querySelector('main input[type=file]').closest('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('Document uploaded and attached to this application.') && document.body.textContent.includes('corrected-cor.png')");
            assert.equal(await evaluate("document.body.textContent.includes('Under review') && !document.body.textContent.includes('Resubmit correction')"),true);
            await evaluate("[...document.querySelectorAll('main button')].find(button=>button.textContent==='Open conversation').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Conversation' && Boolean(document.querySelector('#message-body'))");
            assert.equal(await evaluate("document.querySelector('#message-body').closest('.chat-workspace') !== null"),true,'Composer and messages share one panel');
            const message='Layout verification '+Date.now();
            await fill('#message-body',message);
            await evaluate("document.querySelector('#message-body').closest('form').requestSubmit()");
            await waitFor("document.querySelector('main ol')?.textContent.includes("+JSON.stringify(message)+")");
            const conversationUrl=await evaluate('location.pathname');
            for(const width of [320,768,1440]) {
                await command('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
                assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Conversation fits '+width);
                await screenshot('refined-conversation-'+width);
            }
            await logout();
            await login('logout-provider','Your provider account');
            await command('Page.navigate',{url:appUrl+conversationUrl});
            await waitFor("document.querySelector('main ol')?.textContent.includes("+JSON.stringify(message)+")");
            await evaluate("[...document.querySelectorAll('main button')].find(button=>button.textContent==='Mark as read').click()");
            await waitFor("[...document.querySelectorAll('main button')].find(button=>button.textContent==='Mark as read').disabled");
            await logout();
            await login('logout-student','Your student account');
            await command('Page.navigate',{url:appUrl+'/student/email-code'});
            await waitFor("document.querySelector('h1')?.textContent === 'Verify your email with a code'");
            assert.equal(await evaluate("Boolean(document.querySelector('#student-sidebar')) && !document.querySelector('nav[aria-label=\"Public navigation\"]')"),true);
            assert.equal(await evaluate("Boolean(document.querySelector('main a[href=\"/student/profile#verification\"]'))"),true);
            await logout();
            console.log('PASS: rejected-document feedback and real correction upload from application details, pending-review status, mobile/tablet/desktop layouts and student email-code workspace routing.');
        } else if (process.env.QA_PROFILE_PAGES_CHECK === 'true') {
            await login('logout-student','Your student account');
            const go=async(path:string)=>{await command('Page.navigate',{url:appUrl+path});await waitFor("document.querySelector('h1')?.textContent==='My Profile'");};
            const setValue=async(selector:string,value:string)=>{await evaluate(`(() => {const field=document.querySelector(${JSON.stringify(selector)});Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(field,${JSON.stringify(value)});field.dispatchEvent(new Event('input',{bubbles:true}));})()`);};
            const profilePage=async(label:string)=>{await evaluate(`[...document.querySelectorAll('nav[aria-label="Profile pages"] button')].find(button=>button.textContent.includes(${JSON.stringify(label)})).click()`);await waitFor(`document.querySelector('nav[aria-label="Profile pages"] [aria-current=page]').textContent.includes(${JSON.stringify(label)})`);};
            const responsive=async(name:string)=>{for(const width of [320,768,1440]){await command('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,name+' fits '+width);await screenshot(name+'-'+width);}};
            await go('/student/profile#personal');
            assert.equal(await evaluate("document.querySelector('input[name=first_name]').getBoundingClientRect().height>0 && document.querySelector('input[name=school]')===null"),true,'Only one profile section is shown');
            const contact=await evaluate("document.querySelector('input[name=contact_number]').value") as string;
            await setValue('input[name=contact_number]','+');
            await profilePage('Academic');
            const school='QA Profile Pages '+Date.now();
            await setValue('input[name=school]',school);
            await profilePage('Personal');
            assert.equal(await evaluate("document.querySelector('input[name=contact_number]').value"),'+','Edits survive profile pagination');
            await profilePage('Academic');
            assert.equal(await evaluate("document.querySelector('input[name=school]').value"),school);
            await evaluate("document.querySelector('form[aria-label=\"Student profile\"]').requestSubmit()");
            await waitFor("document.querySelector('nav[aria-label=\"Profile pages\"] [aria-current=page]').textContent.includes('Personal') && document.querySelector('input[name=contact_number][aria-invalid=true]')!==null");
            await setValue('input[name=contact_number]',contact);
            await evaluate("document.querySelector('form[aria-label=\"Student profile\"]').requestSubmit()");
            await waitFor("document.body.textContent.includes('Your profile has been saved.')");
            await go('/student/profile#academic');
            await waitFor("document.querySelector('input[name=school]')!==null");
            assert.equal(await evaluate("document.querySelector('input[name=school]').value"),school,'Saved edits persist in the database');
            for(const label of ['Personal','Academic','Address','Verification']){await profilePage(label);await responsive('profile-page-'+label.toLowerCase());}
            const image=await evaluate("(()=>{const canvas=document.createElement('canvas');canvas.width=128;canvas.height=128;canvas.getContext('2d').fillRect(0,0,128,128);return canvas.toDataURL('image/png').split(',')[1];})()") as string;
            const photoPath=join(profile,'profile-pages-picture.png');await writeFile(photoPath,Buffer.from(image,'base64'));
            const root=await command('DOM.getDocument');const node=await command('DOM.querySelector',{nodeId:(root.root as {nodeId:number}).nodeId,selector:'input[name=photo]'});await command('DOM.setFileInputFiles',{nodeId:node.nodeId,files:[photoPath]});
            await waitFor("document.querySelector('#verification img')?.src.startsWith('blob:')");
            await profilePage('Address');await profilePage('Verification');
            assert.equal(await evaluate("document.querySelector('#verification img').src.startsWith('blob:')"),true,'Picture preview survives pagination');
            await evaluate("[...document.querySelectorAll('#verification button')].find(button=>button.textContent==='Save picture').click()");
            await waitFor("document.body.textContent.includes('Profile picture saved.')");
            await evaluate("[...document.querySelectorAll('#verification button')].find(button=>button.textContent==='Remove picture').click()");
            await waitFor("document.querySelector('[role=dialog]')?.textContent.includes('Remove profile picture?')");
            assert.equal(await evaluate("[...document.querySelectorAll('[role=dialog] button')].find(button=>button.textContent==='Confirm removal').classList.contains('bg-civic-danger')"),true,'Destructive confirmation uses red');
            await evaluate("[...document.querySelectorAll('[role=dialog] button')].find(button=>button.textContent==='Confirm removal').click()");
            await waitFor("!document.querySelector('#verification img')");
            await command('Page.navigate',{url:appUrl+'/student/scholarships/qa-card-alignment-long-title'});
            await waitFor("document.querySelector('h1')?.textContent.includes('Accessible learning materials')");
            await responsive('scholarship-detail-sections');
            await evaluate("[...document.querySelectorAll('[role=tab]')].find(tab=>tab.textContent.startsWith('Renewal')).click()");
            await waitFor("document.querySelector('[role=tab][aria-selected=true]')?.textContent.startsWith('Renewal')");
            assert.equal(await evaluate("[...document.querySelectorAll('[role=tabpanel]')].filter(panel=>panel.getBoundingClientRect().height>0).some(panel=>panel.textContent.includes('QA Renewal Grades'))"),true);
            await command('Network.emulateNetworkConditions',{offline:true,latency:0,downloadThroughput:0,uploadThroughput:0});await waitFor('navigator.onLine===false');
            await evaluate("[...document.querySelectorAll('button')].find(button=>button.textContent==='Prepare application').click()");
            await waitFor("[...document.querySelectorAll('[role=alert]')].some(item=>item.textContent.includes('offline'))");
            await command('Network.emulateNetworkConditions',{offline:false,latency:0,downloadThroughput:-1,uploadThroughput:-1});await waitFor('navigator.onLine===true');
            await evaluate("[...document.querySelectorAll('button')].find(button=>button.textContent==='Prepare application').click()");
            await waitFor("location.pathname.startsWith('/student/applications/') && document.querySelector('h1')?.textContent==='Application details'");
            await command('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
            await logout();
            console.log('PASS: four profile pages, retained edits/files, saved database updates, automatic error-page selection, private photo upload/removal, deep links, semantic button colors, scholarship document tabs, application creation/offline feedback and 320/768/1440px layouts.');
        } else if (process.env.QA_PAGINATION_CHECK === 'true') {
            await login('logout-student','Your student account');
            assert.equal(await evaluate("document.querySelector('[data-sidebar-summary]')===null"),true,'Workspace banner removed');
            await command('Page.navigate',{url:appUrl+'/student/scholarships?q=QA'});
            await waitFor("document.querySelector('nav[aria-label=\"Scholarship result pages\"] [aria-current=page]') !== null");
            assert.equal(await evaluate("document.querySelectorAll('[data-scholarship-card]').length"),6);
            const nav='nav[aria-label="Scholarship result pages"]';
            assert.equal(await evaluate(`document.querySelector('${nav} button[aria-label="Previous page"]').disabled`),true);
            for(const width of [320,768,1440]) {await command('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Pagination fits '+width);await screenshot('pagination-scholarships-'+width);}
            await evaluate(`document.querySelector('${nav} a[aria-label="Go to page 2"]').click()`);
            await waitFor("new URLSearchParams(location.search).get('page')==='2' && document.querySelector('nav[aria-label=\"Scholarship result pages\"] [aria-current=page]')?.textContent==='2'");
            assert.equal(await evaluate("new URLSearchParams(location.search).get('q')"),'QA','Search survives page change');
            assert.equal(await evaluate("document.querySelectorAll('[data-scholarship-card]').length<=6"),true);
            assert.equal(await evaluate(`document.querySelector('${nav} button[aria-label="Next page"]').disabled`),true,'Last-page next is disabled');
            await evaluate(`document.querySelector('${nav} a[aria-label="Go to page 1"]').click()`);
            await waitFor("document.querySelector('nav[aria-label=\"Scholarship result pages\"] [aria-current=page]')?.textContent==='1'");
            await logout();
            await login('logout-provider','Your provider account');
            assert.equal(await evaluate("document.querySelector('[data-sidebar-summary]')===null"),true);
            await logout();
            await login('logout-admin','Dashboard');
            assert.equal(await evaluate("document.querySelector('[data-sidebar-summary]')===null"),true);
            await command('Page.navigate',{url:appUrl+'/admin/users?role=student'});
            await waitFor("document.querySelector('nav[aria-label=\"Account list pages\"]')!==null || document.querySelector('main a[aria-label=\"Go to page 2\"]')!==null");
            await evaluate("document.querySelector('main a[aria-label=\"Go to page 2\"]').click()");
            await waitFor("new URLSearchParams(location.search).get('page')==='2'");
            assert.equal(await evaluate("new URLSearchParams(location.search).get('role')"),'student','Role filter survives pagination');
            await command('Page.navigate',{url:appUrl+'/admin/users?q=logout-provider'});
            await waitFor("[...document.querySelectorAll('main a')].some(link=>/\\/admin\\/users\\/\\d+$/.test(new URL(link.href).pathname))");
            const account=await evaluate("[...document.querySelectorAll('main a')].find(link=>/\\/admin\\/users\\/\\d+$/.test(new URL(link.href).pathname)).href") as string;
            await command('Page.navigate',{url:account+'?source=pagination-check'});
            await waitFor("document.querySelector('nav[aria-label=\"Account activity pages\"] a[aria-label=\"Go to page 2\"]')!==null");
            const activity='nav[aria-label="Account activity pages"]';
            const href=await evaluate(`document.querySelector('${activity} a[aria-label="Go to page 2"]').href`) as string;
            assert.equal(new URL(href).searchParams.get('activity_page'),'2');
            assert.equal(new URL(href).searchParams.get('source'),'pagination-check');
            await evaluate(`document.querySelector('${activity} a[aria-label="Go to page 2"]').click()`);
            await waitFor("new URLSearchParams(location.search).get('activity_page')==='2' && document.querySelector('nav[aria-label=\"Account activity pages\"] [aria-current=page]')?.textContent==='2'");
            await logout();
            console.log('PASS: numbered pages, current-page indicators, disabled boundaries, six scholarships per page, preserved search/role filters, custom activity_page URLs, mobile layouts and removed workspace banners.');
        } else if (process.env.QA_DASHBOARD_REDESIGN_CHECK === 'true') {
            for (const [role,heading] of [['student','Your student account'],['provider','Your provider account'],['admin','Dashboard']]) {
                await command('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
                await login('logout-'+role,heading);
                for (const width of [320,375,768,1024,1440,1920]) {
                    await command('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});
                    await delay(250);
                    assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,role+' dashboard fits '+width);
                    assert.equal(await evaluate("[...document.querySelectorAll('main input:not([type=hidden])')].every(input=>input.labels.length>0)"),true,'Dashboard inputs have labels');
                    await screenshot(role+'-dashboard-redesign-'+width);
                }
                const destinations=await evaluate("[...new Set([...document.querySelectorAll('main a[href]')].map(link=>link.href))]") as string[];
                for (const href of destinations) {
                    assert.equal(new URL(href).origin,new URL(appUrl).origin,'Dashboard action stays in IskoReach');
                    const status=await evaluate('fetch('+JSON.stringify(href)+",{cache:'no-store'}).then(response=>response.status)");
                    assert.equal(status,200,role+' action opens: '+new URL(href).pathname);
                }
                if (await evaluate("document.querySelector('button[aria-pressed]') !== null")) {
                    await evaluate("[...document.querySelectorAll('button[aria-pressed]')].find(button=>button.textContent==='Percentages').click()");
                    await waitFor("[...document.querySelectorAll('button[aria-pressed=true]')].some(button=>button.textContent==='Percentages')");
                    assert.equal(await evaluate("[...document.querySelectorAll('main .dashboard-distribution')].every(progress=>progress.previousElementSibling.lastElementChild.textContent===Math.round(progress.value/progress.max*100)+'%')"),true,'Chart percentages use the actual recorded counts');
                    await evaluate("[...document.querySelectorAll('button[aria-pressed]')].find(button=>button.textContent==='Counts').click()");
                }
                if(role==='student') {
                    await fill('main input[name=q]','QA Education');
                    await command('Network.emulateNetworkConditions',{offline:true,latency:0,downloadThroughput:0,uploadThroughput:0});
                    await waitFor('navigator.onLine===false');
                    await evaluate("document.querySelector('main form').requestSubmit()");
                    await waitFor("[...document.querySelectorAll('[role=alert]')].some(item=>item.textContent.includes('offline'))");
                    await command('Network.emulateNetworkConditions',{offline:false,latency:0,downloadThroughput:-1,uploadThroughput:-1});
                    await waitFor('navigator.onLine===true');
                    await evaluate("document.querySelector('main form').requestSubmit()");
                    await waitFor("location.pathname==='/student/scholarships' && location.search.includes('QA')");
                }
                if(role==='provider') {
                    await command('Page.navigate',{url:appUrl+'/provider/workspace'});
                    await waitFor("document.querySelector('h1')?.textContent==='Provider workspace'");
                    for(const width of [320,768,1440]) {await command('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true);await screenshot('provider-workbench-'+width);}
                }
                await logout();
                console.log('PASS: '+role+' dashboard responsive composition, real data rendering, all quick-action destinations and interactions.');
            }
        } else if (process.env.QA_SIDEBAR_RAIL_CHECK === 'true') {
            for (const [role, heading] of [['student','Your student account'],['provider','Your provider account'],['admin','Dashboard']]) {
                await command('Emulation.setDeviceMetricsOverride',{width:1440,height:600,deviceScaleFactor:1,mobile:false});
                await login('logout-'+role,heading);
                const selector='#'+role+'-sidebar';
                if(await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]') !== null")) await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]').click()");
                await waitFor(`getComputedStyle(document.querySelector('${selector}')).width === '240px'`);
                assert.equal(await evaluate("document.querySelector('header button[aria-label=\"Sign out\"]') === null"),true,'No sign out in the top header');
                const groupButtons=await evaluate(`document.querySelectorAll('${selector} nav button[aria-expanded]').length`) as number;
                assert.ok(groupButtons >= 3, 'Related tools use expandable groups');
                await evaluate(`[...document.querySelectorAll('${selector} nav button[aria-expanded=false]')].forEach(button=>button.click())`);
                await waitFor(`[...document.querySelectorAll('${selector} nav button[aria-expanded]')].every(button=>button.getAttribute('aria-expanded')==='true')`);
                const footerBefore=await evaluate(`document.querySelector('${selector} [data-sidebar-account]').getBoundingClientRect().top`);
                assert.equal(await evaluate(`(() => { const scroll=document.querySelector('${selector} [data-sidebar-scroll]'); scroll.scrollTop=scroll.scrollHeight; return scroll.scrollTop>0; })()`),true,'Navigation scrolls independently');
                assert.equal(await evaluate(`document.querySelector('${selector} [data-sidebar-account]').getBoundingClientRect().top`),footerBefore,'Account footer does not scroll away');
                assert.equal(await evaluate(`(() => { const box=document.querySelector('${selector} .sidebar-sign-out').getBoundingClientRect(); return box.top>=0 && box.bottom<=innerHeight; })()`),true,'Sign out remains fully visible');
                await evaluate("document.querySelector('button[aria-label=\"Collapse sidebar\"]').focus()");
                await command('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',text:'\r',unmodifiedText:'\r',windowsVirtualKeyCode:13});
                await command('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
                await waitFor(`getComputedStyle(document.querySelector('${selector}')).width === '80px'`);
                assert.equal(await evaluate(`document.querySelector('${selector}').inert`),false,'Collapsed icons remain interactive');
                assert.equal(await evaluate(`getComputedStyle(document.querySelector('${selector} .brand-wordmark')).display`),'none');
                assert.equal(await evaluate(`document.querySelector('${selector} img').getBoundingClientRect().width > 0`),true,'Logo remains visible');
                assert.equal(await evaluate(`[...document.querySelectorAll('${selector} nav a')].filter(link=>link.getBoundingClientRect().width>0).every(link=>link.getAttribute('aria-label') && link.title && link.querySelector('svg').getBoundingClientRect().width > 0 && getComputedStyle(link.querySelector('span')).display === 'none')`),true,'Icons have names and tooltips while words are hidden');
                assert.equal(await evaluate("document.documentElement.scrollWidth <= innerWidth"),true);
                await screenshot(role+'-icon-rail');
                await evaluate(`document.querySelector('${selector} nav button[aria-expanded]').click()`);
                await waitFor(`getComputedStyle(document.querySelector('${selector}')).width === '240px' && document.querySelector('${selector} nav button[aria-expanded]').getAttribute('aria-expanded') === 'true'`);
                const groupedTarget = await evaluate(`document.getElementById(document.querySelector('${selector} nav button[aria-expanded]').getAttribute('aria-controls')).querySelector('a').pathname`);
                await evaluate(`document.getElementById(document.querySelector('${selector} nav button[aria-expanded]').getAttribute('aria-controls')).querySelector('a').click()`);
                await waitFor('location.pathname === '+JSON.stringify(groupedTarget));
                await delay(400);
                await waitFor(`document.querySelector('${selector} nav a[aria-current=page]') !== null`);
                await command('Page.reload');
                await delay(700);
                await delay(700);
                await waitFor(`document.querySelector('${selector} nav a[aria-current=page]')?.getBoundingClientRect().width > 0`);
                await evaluate("document.querySelector('button[aria-label=\"Collapse sidebar\"]').click()");
                await waitFor(`getComputedStyle(document.querySelector('${selector}')).width === '80px'`);
                const next=await evaluate(`document.querySelectorAll('${selector} nav a')[1].getAttribute('href')`) as string;
                await evaluate(`document.querySelectorAll('${selector} nav a')[1].click()`);
                await waitFor('location.pathname === '+JSON.stringify(new URL(next,appUrl).pathname));
                await command('Page.reload');
                await delay(700);
                await waitFor(`document.querySelector('${selector}[data-collapsed=true]') !== null && getComputedStyle(document.querySelector('${selector}')).width === '80px'`);
                await command('Emulation.setDeviceMetricsOverride',{width:375,height:600,deviceScaleFactor:1,mobile:true});
                await evaluate(`document.querySelector('button[aria-label="Open ${role} navigation"]').click()`);
                await waitFor("document.querySelector('[role=dialog]') !== null");
                assert.equal(await evaluate("[...document.querySelectorAll('[role=dialog] nav a')].every(link=>getComputedStyle(link.querySelector('span')).display !== 'none')"),true,'Mobile drawer keeps readable labels');
                const mobileFooter=await evaluate("document.querySelector('[role=dialog] [data-sidebar-account]').getBoundingClientRect().top");
                await evaluate("document.querySelector('[role=dialog] nav').parentElement.parentElement.scrollTop=10000");
                assert.equal(await evaluate("document.querySelector('[role=dialog] [data-sidebar-account]').getBoundingClientRect().top"),mobileFooter,'Mobile account footer stays pinned');
                assert.equal(await evaluate("document.querySelector('[role=dialog] button[aria-label=\"Sign out\"]').getBoundingClientRect().bottom<=innerHeight"),true);
                await command('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
                await command('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
                await waitFor("document.querySelector('[role=dialog]') === null");
                await command('Emulation.setDeviceMetricsOverride',{width:1440,height:600,deviceScaleFactor:1,mobile:false});
                await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]').click()");
                await waitFor(`getComputedStyle(document.querySelector('${selector}')).width === '240px'`);
                assert.notEqual(await evaluate(`getComputedStyle(document.querySelector('${selector} .brand-wordmark')).display`),'none');
                await logout();
            }
            console.log('PASS: pinned account/sign-out footers on short desktop/mobile viewports; confirmation/cancel/offline/actual logout; all three sidebars retain an accessible 80px logo/icon rail, keyboard toggle, working links, persisted state, full mobile labels, and 240px expansion.');
        } else if (process.env.QA_DEMO_PROVIDERS_CHECK === 'true') {
            const credentialsPath = join(process.cwd(), 'storage', 'app', 'private', 'demo-provider-credentials.json');
            const credentials = JSON.parse(await readFile(credentialsPath, 'utf8')) as Record<string, {provider:string;password:string}>;
            assert.equal(Object.keys(credentials).length, 5);
            const owned: number[] = [];
            for (const [email, credential] of Object.entries(credentials)) {
                await command('Page.navigate', {url:appUrl+'/login'});
                await waitFor("document.querySelector('input[name=email]') !== null");
                await fill('input[name=email]', email);
                await fill('input[name=password]', credential.password);
                await evaluate("document.querySelector('form').requestSubmit()");
                await waitFor("document.querySelector('h1')?.textContent === 'Your provider account'");
                await command('Page.navigate', {url:appUrl+'/provider/dashboard'});
                await waitFor("document.querySelector('h1')?.textContent === 'Your provider account' && Boolean(JSON.parse(document.querySelector('#app').dataset.page).props.profile)");
                const props = await evaluate("JSON.parse(document.querySelector('#app').dataset.page).props") as {profile:{organization_name:string};summary:{scholarships:number};recentScholarships:{id:number}[]};
                assert.equal(props.profile.organization_name, credential.provider);
                assert.equal(props.summary.scholarships, 2);
                const id=props.recentScholarships[0].id;
                const ownStatus=await evaluate(`fetch('/provider/scholarships/${id}/edit',{cache:'no-store'}).then(response=>response.status)`);
                assert.equal(ownStatus,200,'Provider can manage an owned scholarship');
                if (owned.length) {
                    const forbidden=await evaluate(`fetch('/provider/scholarships/${owned[0]}/edit',{cache:'no-store'}).then(response=>response.status)`);
                    assert.equal(forbidden,403,'Another provider scholarship remains private');
                }
                owned.push(id);
                await logout();
                console.log('PASS: actual local login and own dashboard for '+email);
            }
            for (const [availability,total] of [['open',6],['upcoming',2],['closed',2]] as const) {
                await command('Page.navigate', {url:appUrl+'/scholarships?q=%5BDEMO%5D&availability='+availability});
                await waitFor("document.querySelector('h1')?.textContent === 'Find a scholarship' && Boolean(JSON.parse(document.querySelector('#app').dataset.page).props.scholarships)");
                const data=await evaluate("JSON.parse(document.querySelector('#app').dataset.page)") as {props:{scholarships:{total:number;data:{show_url:string;title:string}[]}}};
                assert.equal(data.props.scholarships.total,total,'Actual demo '+availability+' records');
                for (const item of data.props.scholarships.data) {
                    const status=await evaluate('fetch('+JSON.stringify(item.show_url)+').then(response=>response.status)');
                    assert.equal(status,200,'Actual demo scholarship detail opens');
                }
            }
            console.log('PASS: live database has 6 open, 2 upcoming, and 2 closed demo listings; details load from saved records.');
        } else if (process.env.QA_SYSTEM_UI_CHECK === 'true') {
            let checked = 0;
            const inspect = async (path: string) => {
                await command('Page.navigate', { url: appUrl + path });
                await waitFor('location.pathname === ' + JSON.stringify(path) + " && document.querySelector('main h1') !== null");
                await evaluate('document.fonts.ready.then(() => true)');
                if (path === '/passers' || path === '/scholarships') {
                    assert.equal(await evaluate("Boolean(document.querySelector('aside.workspace-sidebar')) && !document.querySelector('nav[aria-label=\"Main navigation\"]')"),true,path+' retains the role workspace');
                    assert.equal(await evaluate("Boolean(document.querySelector('aside.workspace-sidebar nav a[aria-current=\"page\"]'))"),true,path+' has an active sidebar link');
                }
                assert.equal(await evaluate("/^(403|404|500|Server Error)/.test(document.querySelector('h1').textContent)"), false, path + ' renders its authorized page');
                assert.equal(await evaluate("parseFloat(getComputedStyle(document.querySelector('main')).paddingLeft)>=16"),true,path+' keeps a content gutter');
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    await delay(80);
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, path + ' fits ' + width);
                    assert.equal(await evaluate("[...document.querySelectorAll('main input:not([type=hidden])')].every(input => input.labels.length > 0 || input.getAttribute('aria-label') || input.getAttribute('aria-labelledby'))"), true, path + ' inputs are labeled');
                    if (/dashboard|profile|student-verifications$|scholarships\/create/.test(path)) await screenshot('system-' + path.slice(1).replaceAll('/', '-') + '-' + width);
                }
                checked++;
                const links = await evaluate("[...document.querySelectorAll('main a[href]')].map(link=>new URL(link.href).pathname)") as string[];
                return links.filter(href => /^\/(student\/applications\/\d+|student\/scholarships\/[^/]+|provider\/applications\/\d+|provider\/scholarships\/\d+\/edit|admin\/(providers|users|scholarships|student-verifications)\/\d+|messages\/\d+|benefits\/\d+|renewals\/\d+)$/.test(href));
            };
            for (const [role, heading, paths] of [
                [process.env.QA_UI_STUDENT ?? 'logout-student', 'Your student account', ['/student/dashboard', '/passers', '/scholarships', '/student/profile', '/student/scholarships', '/student/applications', '/student/requirements', '/student/documents', '/student/awards', '/messages', '/notifications', '/renewals', '/benefits', '/declarations', '/scholarship-announcements']],
                ['logout-provider', 'Your provider account', ['/provider/dashboard', '/passers', '/scholarships', '/provider/workspace', '/provider/profile', '/provider/scholarships', '/provider/scholarships/create', '/provider/applications', '/provider/applicant-counts', '/messages', '/notifications', '/renewals', '/benefits', '/scholarship-announcements']],
                ['logout-admin', 'Dashboard', ['/admin/dashboard', '/passers', '/scholarships', '/admin/providers', '/admin/users', '/admin/scholarships', '/admin/student-verifications', '/admin/applicant-counts', '/admin/awards', '/admin/renewals', '/admin/releases', '/admin/academic-terms', '/admin/reports', '/admin/activity-logs', '/notifications']],
            ] as [string, string, string[]][]) {
                await login(role, heading);
                const navigationLinks = await evaluate("[...document.querySelectorAll('aside.workspace-sidebar nav a[href]')].map(link=>link.getAttribute('href'))") as string[];
                for(const href of new Set(navigationLinks)) {
                    const status=await evaluate('fetch('+JSON.stringify(href)+',{credentials:"same-origin"}).then(response=>response.status)');
                    assert.equal(status,200,role+' sidebar destination '+href);
                }
                const details = new Set<string>();
                for (const path of paths) {
                    const links = await inspect(path);
                    for (const href of links.slice(0, 1)) details.add(href);
                }
                for (const path of details) await inspect(path);
                await logout();
                console.log('PASS: ' + role + ' authorized pages, labels, responsive layouts and sign-out confirmation.');
            }
            console.log('PASS: ' + checked + ' workspace pages checked at 320/768/1440px.');
        } else if (process.env.QA_ACCOUNT_ENHANCEMENTS_CHECK === 'true') {
            const accountPrefix=process.env.QA_ACCOUNT_PREFIX??'enhancement';
            const accountSizes = async (name: string) => { for(const width of [320,768,1440]) { await command('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768}); assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,name+' fits '+width); await screenshot(name+'-'+width); } };
            const go = async (path: string, heading: string) => { await command('Page.navigate',{url:appUrl+path}); await waitFor('document.querySelector("h1")?.textContent === '+JSON.stringify(heading)); };
            const setValue = async (selector: string, value: string) => { await evaluate(`(() => { const input=document.querySelector(${JSON.stringify(selector)}); Object.getOwnPropertyDescriptor(input instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype,'value').set.call(input,${JSON.stringify(value)}); input.dispatchEvent(new Event('input',{bubbles:true})); })()`); };
            const uploadFile = async (selector: string,path: string) => { const document=await command('DOM.getDocument'); const node=await command('DOM.querySelector',{nodeId:(document.root as {nodeId:number}).nodeId,selector}); await command('DOM.setFileInputFiles',{nodeId:node.nodeId,files:[path]}); await evaluate('document.querySelector('+JSON.stringify(selector)+').closest("form").requestSubmit()'); };
            await login(accountPrefix+'-student','Your student account');
            await accountSizes('student-account-dashboard');
            assert.equal(await evaluate("document.body.textContent.includes('4/4 completed') && document.body.textContent.includes('not applied')"),true);
            await fill('input[name=q]','QA Education'); await evaluate("document.querySelector('main form').requestSubmit()");
            await waitFor("document.querySelector('h1')?.textContent === 'Find a scholarship'"); assert.equal(await evaluate('location.pathname'),'/student/scholarships');
            await go('/student/profile','My Profile');
            await accountSizes('student-account-profile');
            await evaluate("[...document.querySelectorAll('nav[aria-label=\"Profile pages\"] button')].find(button=>button.textContent.includes('Academic')).click()");
            await setValue('input[name=school]','Enhanced QA University');
            await evaluate("document.querySelector('form[aria-label=\"Student profile\"]').requestSubmit()");
            await waitFor("document.body.textContent.includes('Your profile has been saved.')");
            const picture = await evaluate("(() => { const canvas=document.createElement('canvas');canvas.width=128;canvas.height=128;const ctx=canvas.getContext('2d');ctx.fillStyle='#123563';ctx.fillRect(0,0,128,128);return canvas.toDataURL('image/png').split(',')[1]; })()") as string;
            const path=join(profile,'account-proof.png'); await writeFile(path,Buffer.from(picture,'base64'));
            await evaluate("[...document.querySelectorAll('nav[aria-label=\"Profile pages\"] button')].find(button=>button.textContent.includes('Verification')).click()");
            await uploadFile('input[name=photo]',path); await waitFor("document.body.textContent.includes('Profile picture saved.')");
            for (const kind of ['student_id','enrollment']) { await uploadFile('#verification-'+kind,path); await waitFor("document.querySelector('#verification-'+"+JSON.stringify(kind)+").closest('form').textContent.includes('Uploaded:')"); }
            await waitFor("[...document.querySelectorAll('#verification button')].every(button=>button.getAttribute('aria-busy')!=='true')");
            await evaluate("document.querySelector('#verification input[type=checkbox]').click()");
            await waitFor("![...document.querySelectorAll('#verification button')].find(button=>button.textContent==='Apply for student verification').disabled");
            await evaluate("[...document.querySelectorAll('#verification button')].find(button=>button.textContent==='Apply for student verification').click()");
            await waitFor("document.body.textContent.includes('Verification submitted for administrator review.')");
            assert.equal(await evaluate("document.querySelector('main').textContent.includes('Verified student')"),false,'Checklist completion does not award a badge');
            await logout();
            await login(accountPrefix+'-admin','Dashboard');
            await go('/admin/student-verifications','Student verification');
            await evaluate("document.querySelector('main a[href*=\"/admin/student-verifications/\"]').click()");
            await waitFor("document.querySelector('h1')?.textContent==='Review student verification'");
            await waitFor("document.querySelector('main img').complete && document.querySelector('main img').naturalWidth>0");
            await accountSizes('student-verification-review');
            assert.equal(await evaluate("Promise.all([...document.querySelectorAll('main a')].filter(link=>link.textContent.includes('Download')).map(link=>fetch(link.href).then(response=>response.status))).then(statuses=>statuses.length===2&&statuses.every(status=>status===200))"),true);
            await setValue('textarea[name=reason]','Identity and current enrollment reviewed in isolated test'); await evaluate("document.querySelector('main input[type=checkbox]').click();document.querySelector('main form').requestSubmit()");
            await waitFor("document.body.textContent.includes('Student verification decision saved.')");
            await logout(); await login(accountPrefix+'-student','Your student account');
            assert.equal(await evaluate("document.body.textContent.includes('verified')"),true);
            await go('/student/profile','My Profile'); await waitFor("document.body.textContent.includes('Verified student')"); await evaluate("[...document.querySelectorAll('nav[aria-label=\"Profile pages\"] button')].find(button=>button.textContent.includes('Verification')).click()");
            await evaluate("[...document.querySelectorAll('button')].find(button=>button.textContent==='Remove picture').click()");
            await waitFor("document.querySelector('[role=dialog]')!==null"); await evaluate("[...document.querySelectorAll('[role=dialog] button')].find(button=>button.textContent==='Confirm removal').click()");
            await waitFor("document.body.textContent.includes('revoked')");
            await go('/student/requirements','My scholarship requirements');
            assert.equal(await evaluate("document.querySelectorAll('main input[type=file]').length"),2);
            const doc = await command('DOM.getDocument');const node=await command('DOM.querySelector',{nodeId:(doc.root as {nodeId:number}).nodeId,selector:'main input[type=file]'});await command('DOM.setFileInputFiles',{nodeId:node.nodeId,files:[path]});
            await evaluate("document.querySelector('main input[type=file]').closest('form').querySelector('input[type=checkbox]').click();document.querySelector('main input[type=file]').closest('form').requestSubmit()");
            await waitFor("[...document.querySelectorAll('main span')].filter(span=>span.textContent==='Uploaded').length===1");
            assert.equal(await evaluate("[...document.querySelectorAll('main span')].filter(span=>span.textContent==='Missing').length"),1,'Applications keep separate requirement statuses');
            for(const width of [320,768,1440]) {await command('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768}); assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true);}
            await logout(); await login(accountPrefix+'-email','Your student account');
            await go('/student/email-code','Verify your email with a code');
            await fill('input[name=code]','111111');await evaluate("document.querySelector('main form').requestSubmit()");await waitFor("document.querySelector('[role=alert]')!==null");
            await setValue('input[name=code]','548321'); await evaluate("document.querySelector('main form').requestSubmit()"); await waitFor("document.querySelector('h1')?.textContent==='Your student account'");
            await go('/student/profile','My Profile'); await setValue('input[name=school]','Updated legacy school'); await evaluate("document.querySelector('form[aria-label=\"Student profile\"]').requestSubmit()"); await waitFor("document.body.textContent.includes('Your profile has been saved.')");
            await logout();
            console.log('PASS: real profile save, picture upload/removal, private verification documents, pending/admin approval/badge revocation, independent requirement uploads, dashboard search/progress and email-code error/success UI in an isolated database.');
        } else if (process.env.QA_STUDENT_CATALOG_CHECK === 'true') {
            await login('logout-student', 'Your student account');
            await evaluate("document.querySelector('#student-sidebar a[href=\"/student/scholarships\"]').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Find a scholarship'");
            assert.equal(await evaluate('location.pathname'), '/student/scholarships');
            assert.equal(await evaluate("document.querySelector('#student-sidebar a[aria-current=page]').getAttribute('href')"), '/student/scholarships');
            assert.equal(await evaluate("document.querySelector('nav[aria-label=\"Main navigation\"]') === null"), true);
            for (const width of [320,768,1280,1440]) {
                await command('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});
                await delay(250);
                assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Scholarship cards fit '+width);
                assert.equal(await evaluate(`(() => {
                    const cards=[...document.querySelectorAll('[data-scholarship-card]')];
                    if(!cards.length)return false;
                    for(const card of cards) {
                        const peers=cards.filter(other=>Math.abs(other.getBoundingClientRect().top-card.getBoundingClientRect().top)<1);
                        for(const selector of ['h2','h2 + p','[data-scholarship-field="benefit"]','[data-scholarship-field="location"]','[data-scholarship-field="opening"]','[data-scholarship-field="deadline"]','[data-scholarship-field="applicants"]','[data-scholarship-action]']) {
                            if(peers.some(peer=>Math.abs(peer.querySelector(selector).getBoundingClientRect().top-card.querySelector(selector).getBoundingClientRect().top)>1))return false;
                        }
                    }
                    return true;
                })()`),true,'Titles, providers, metadata and actions align within each scholarship row at '+width);
                await screenshot('scholarship-card-alignment-'+width);
            }
            await fill('input[name=q]', 'QA Education');
            await evaluate("document.querySelector('form[aria-label=\"Scholarship discovery filters\"]').requestSubmit()");
            await waitFor("location.search.includes('QA')");
            assert.equal(await evaluate('location.pathname'), '/student/scholarships');
            await evaluate("document.querySelector('main h2 a').click()");
            await waitFor("document.querySelector('h1')?.textContent.startsWith('QA Education Grant')");
            assert.equal(await evaluate("location.pathname.startsWith('/student/scholarships/') && document.querySelector('#student-sidebar') !== null"), true);
            await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent === 'Back to scholarships').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Find a scholarship'");
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Reset').click()");
            await waitFor("location.search === ''");
            assert.equal(await evaluate('location.pathname'), '/student/scholarships');
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Student catalog fits ' + width);
            }
            await logout();
            console.log('PASS: student sidebar discovery, active navigation, filtering, detail/back links and reset remain in the student workspace at 320/768/1440px.');
        } else if (process.env.QA_LOGOUT_CHECK === 'true') {
            for (const [role, heading, dashboard] of [['logout-student', 'Your student account', '/student/dashboard'], ['logout-provider', 'Your provider account', '/provider/dashboard'], ['logout-admin', 'Dashboard', '/admin/dashboard']]) {
                await login(role, heading);
                await logout();
                await command('Page.navigate', { url: appUrl + dashboard });
                await waitFor("document.querySelector('h1')?.textContent === 'Log in'");
            }
            console.log('PASS: student, provider and admin menus require confirmation; cancel preserves session; offline sign-out is retryable; confirmed logout blocks authenticated routes.');
        } else if (process.env.QA_STUDENT_REGISTRATION_CHECK === 'true') {
            const navigate = async (path: string, selector: string) => {
                await command('Page.navigate', { url: appUrl + path });
                await waitFor('document.querySelector(' + JSON.stringify(selector) + ') !== null');
            };
            const choose = async (name: string, value: string) => {
                await evaluate(`(() => { const el = document.querySelector('select[name="${name}"]'); el.value = ${JSON.stringify(value)}; el.dispatchEvent(new Event('change', {bubbles: true})); })()`);
            };
            await navigate('/register/student', 'input[name=first_name]');
            assert.equal(await evaluate("document.querySelector('input[name=name]') === null"), true);
            assert.equal(await evaluate("document.querySelector('input[name=organization_name]') === null"), true);
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Student registration fits ' + width);
            }
            const registrationEmail = 'registration-' + Date.now() + '@example.test';
            const next = async (selector: string) => {
                await evaluate("document.querySelector('form').requestSubmit()"); await waitFor('document.querySelector(' + JSON.stringify(selector) + ') !== null');
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Registration step fits ' + width);
                    assert.equal(await evaluate("document.querySelector('.registration-navigation').getBoundingClientRect().top >= document.querySelector('.registration-step-content').getBoundingClientRect().bottom"), true, 'Navigation does not overlap fields');
                }
            };
            const previous = async (selector: string) => { await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Previous').click()"); await waitFor('document.querySelector(' + JSON.stringify(selector) + ') !== null'); };
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.querySelector('input[name=first_name]').getAttribute('aria-invalid') === 'true'");
            await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
            assert.equal(await evaluate("getComputedStyle(document.querySelector('.registration-step-content')).animationName"), 'none');
            await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
            await screenshot('registration-student-personal');
            await fill('input[name=first_name]', 'Ana');
            await fill('input[name=first_name]', '123');
            assert.equal(await evaluate("document.querySelector('input[name=first_name]').value"), 'Ana', 'Name input blocks digits');
            await evaluate("(() => { window.__originalConfirm = window.confirm; window.__leaveAsked = false; window.confirm = () => { window.__leaveAsked = true; return false; }; document.querySelector('a[href=\"/register/provider\"]').click(); })()");
            assert.equal(await evaluate("window.__leaveAsked && location.pathname === '/register/student'"), true, 'Leaving with progress requires confirmation');
            await evaluate('window.confirm = window.__originalConfirm');
            await fill('input[name=surname]', 'Browser');
            await fill('input[name=middle_name]', 'Test');
            await choose('suffix', 'III');
            await choose('sex', 'female');
            await evaluate(`(() => { const el = document.querySelector('input[name=date_of_birth]'); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(el, '2004-05-15'); el.dispatchEvent(new Event('input',{bubbles:true})); el.dispatchEvent(new Event('change',{bubbles:true})); })()`);
            await fill('input[name=contact_number]', 'letters');
            assert.equal(await evaluate("document.querySelector('input[name=contact_number]').value"), '', 'Contact input blocks letters');
            await fill('input[name=contact_number]', '09171234567');
            await next('input[name=student_number]');
            assert.equal(await evaluate("document.querySelectorAll('.registration-stepper .is-complete').length"), 1);
            await previous('input[name=first_name]');
            assert.equal(await evaluate("document.querySelector('input[name=first_name]').value"), 'Ana');
            await next('input[name=student_number]');
            await fill('input[name=student_number]', 'BROWSER-' + Date.now());
            await fill('input[name=school]', 'Browser Test University');
            await fill('input[name=college]', 'College of Science');
            await fill('input[name=course]', 'Computer Science');
            await choose('year_level', '2');
            await choose('enrollment_status', 'enrolled');
            await next('select[name=region_code]');
            await waitFor("document.querySelector('select[name=region_code] option[value=\"040000000\"]') !== null");
            await choose('region_code', '040000000');
            await waitFor("document.querySelector('select[name=province_code] option[value=\"042100000\"]') !== null");
            await choose('province_code', '042100000');
            await waitFor("document.querySelector('select[name=city_code]').options.length > 1");
            await choose('city_code', await evaluate("document.querySelector('select[name=city_code]').options[1].value") as string);
            await waitFor("document.querySelector('select[name=barangay_code]').options.length > 1");
            await choose('barangay_code', await evaluate("document.querySelector('select[name=barangay_code]').options[1].value") as string);
            await next('input[name=email]');
            await fill('input[name=email]', registrationEmail);
            await fill('input[name=password]', 'temporary-test-password');
            await fill('input[name=password_confirmation]', 'temporary-test-password');
            await evaluate("document.querySelector('input[name=terms_accepted]').click(); document.querySelector('input[name=privacy_accepted]').click(); document.querySelector('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('Review your registration')");
            assert.equal(await evaluate("document.querySelector('input[name=password]') === null"), true, 'Review hides password fields');
            await evaluate("document.querySelector('button[aria-label=\"Edit Academic information\"]').click()");
            await waitFor("document.querySelector('input[name=school]') !== null");
            assert.equal(await evaluate("document.querySelector('input[name=school]').value"), 'Browser Test University');
            await next('select[name=region_code]'); await next('input[name=email]');
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('Review your registration')");
            await previous('input[name=email]');
            assert.equal(await evaluate("document.querySelector('input[name=email]').value"), registrationEmail);
            assert.equal(await evaluate("document.querySelector('input[name=password]').value"), 'temporary-test-password');
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('Review your registration')");
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.querySelector('h1')?.textContent === 'Verify your email'");
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('Another verification link has been sent')");
            await navigate('/student/dashboard', 'h1');
            await waitFor("document.querySelector('h1')?.textContent === 'Verify your email'");
            assert.ok(process.env.QA_REGISTRATION_DB?.includes('iskoreach-registration-'));
            const getVerificationUrl = (email: string) => new Promise<string>((resolve, reject) => {
                const php = spawn('php', [], { windowsHide: true, env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: process.env.QA_REGISTRATION_DB, DB_URL: '', APP_URL: appUrl, QA_REGISTRATION_EMAIL: email }, stdio: ['pipe', 'pipe', 'pipe'] });
                let output = ''; let error = '';
                php.stdout.on('data', chunk => { output += chunk; }); php.stderr.on('data', chunk => { error += chunk; });
                php.on('close', code => code === 0 ? resolve(output.trim()) : reject(new Error(error)));
                php.stdin.end(`<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || !str_contains(config('database.connections.sqlite.database'), 'iskoreach-registration-')) { throw new RuntimeException('Disposable registration database required.'); }
$user = App\\Models\\User::where('email', getenv('QA_REGISTRATION_EMAIL'))->firstOrFail();
echo Illuminate\\Support\\Facades\\URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->id, 'hash' => sha1($user->email)]);`);
            });
            await command('Page.navigate', { url: await getVerificationUrl(registrationEmail) });
            await waitFor("document.querySelector('h1')?.textContent === 'Your student account'");
            await navigate('/student/profile', 'h1');
            await waitFor("document.querySelector('h1')?.textContent === 'My Profile'");
            assert.equal(await evaluate("document.querySelector('input[name=first_name]').value === 'Ana' && document.querySelector('input[name=surname]').value === 'Browser'"), true);
            await evaluate("[...document.querySelectorAll('nav[aria-label=\"Profile pages\"] button')].find(button=>button.textContent.includes('Academic')).click()");
            assert.equal(await evaluate("document.querySelector('input[name=school]').value === 'Browser Test University'"),true);
            await logout();
            await navigate('/register/provider', 'input[name=position]');
            assert.equal(await evaluate("document.querySelector('input[name=first_name]') !== null"), true);
            await screenshot('registration-provider-representative');
            const providerEmail = 'provider-browser-' + Date.now() + '@example.test';
            await fill('input[name=first_name]', 'Provider'); await fill('input[name=surname]', 'Browser');
            await fill('input[name=position]', 'Director'); await fill('input[name=contact_number]', '09171234567'); await fill('input[name=work_email]', providerEmail);
            await next('input[name=organization_name]');
            await fill('input[name=organization_name]', 'Browser Foundation'); await fill('input[name=office_address]', '123 Test Office');
            await choose('organization_type', 'individual');
            assert.equal(await evaluate("document.querySelector('input[name=organization_name]') === null"), true);
            await choose('organization_type', 'foundation');
            assert.equal(await evaluate("document.querySelector('input[name=organization_name]').value"), 'Browser Foundation');
            await waitFor("document.querySelector('select[name=region_code] option[value=\"040000000\"]') !== null");
            await choose('region_code', '040000000');
            await waitFor("document.querySelector('select[name=province_code] option[value=\"042100000\"]') !== null");
            await choose('province_code', '042100000');
            await waitFor("document.querySelector('select[name=city_code]').options.length > 1");
            await choose('city_code', await evaluate("document.querySelector('select[name=city_code]').options[1].value") as string);
            await fill('input[name=organization_email]', providerEmail); await fill('input[name=organization_phone]', '09171234567');
            await next('input[name=representative_id]');
            await screenshot('registration-provider-documents');
            const proofPath = join(profile, 'provider-proof.png');
            await writeFile(proofPath, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j9K0AAAAASUVORK5CYII=', 'base64'));
            for (const kind of ['representative_id', 'organization_proof']) {
                const document = await command('DOM.getDocument');
                const node = await command('DOM.querySelector', { nodeId: (document.root as { nodeId: number }).nodeId, selector: 'input[name=' + kind + ']' });
                await command('DOM.setFileInputFiles', { nodeId: node.nodeId, files: [proofPath] });
            }
            await next('input[name=email]');
            await previous('input[name=representative_id]');
            assert.equal(await evaluate("document.body.textContent.includes('Selected: provider-proof.png')"), true, 'Selected files survive Previous navigation');
            await next('input[name=email]');
            await fill('input[name=email]', providerEmail); await fill('input[name=password]', 'temporary-test-password'); await fill('input[name=password_confirmation]', 'temporary-test-password');
            await evaluate("document.querySelector('input[name=terms_accepted]').click(); document.querySelector('input[name=privacy_accepted]').click(); document.querySelector('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('Review your registration')");
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Provider review fits ' + width);
                await screenshot('registration-review-' + width);
            }
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.querySelector('h1')?.textContent === 'Verify your email'");
            await command('Page.navigate', { url: await getVerificationUrl(providerEmail) });
            await waitFor("document.querySelector('h1')?.textContent === 'Organization review pending'");
            assert.equal(await evaluate("document.querySelectorAll('a[href*=\"provider-verification\"]').length"), 2);
            assert.equal(await evaluate("fetch('/provider/workspace').then(response => response.status)"), 403);
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Log out').click()");
            await waitFor("document.querySelector('[role=dialog]')?.textContent.includes('Would you like to sign out?')");
            await evaluate("[...document.querySelectorAll('[role=dialog] button')].find(button => button.textContent.trim() === 'Yes, sign out').click()");
            await waitFor("document.querySelector('h1')?.textContent.includes('Find scholarships.')");
            await navigate('/terms', 'h1');
            assert.equal(await evaluate("document.querySelector('h1').textContent"), 'Terms of Use');
            await navigate('/privacy', 'h1');
            assert.equal(await evaluate("document.querySelector('h1').textContent"), 'Privacy Policy');
            console.log('PASS: student and provider four-step navigation, required-field errors, Previous/Next data and file preservation, final review and signup, individual option, live address selectors, signed email verification, provider pending approval/workspace restriction, private documents, policies and 320/768/1440 layouts.');
        } else if (process.env.QA_WORKFLOW_COMPLETION_CHECK === 'true') {
            const go = async (path: string, heading: string) => {
                await command('Page.navigate', { url: appUrl + path });
                await waitFor('document.querySelector("h1")?.textContent === ' + JSON.stringify(heading));
            };
            const labelledInput = async (label: string, value: string) => {
                await evaluate(`(() => { const label = [...document.querySelectorAll('label')].find(item => item.textContent.trim() === ${JSON.stringify(label)}); document.getElementById(label.htmlFor).focus(); })()`);
                await command('Input.insertText', { text: value });
            };
            await login('provider', 'Your provider account');
            await go('/scholarship-announcements', 'Scholarship announcements');
            await labelledInput('Announcement title', 'Browser verified announcement');
            await fill('textarea', 'Prepare your COR and grades for the next academic term.');
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("[...document.querySelectorAll('h2')].some(item => item.textContent === 'Browser verified announcement')");
            await go('/declarations', 'Existing scholarship declarations');
            assert.equal(await evaluate("document.body.textContent.includes('Outside browser scholarship')"), true);
            await evaluate("document.querySelector('form input[type=checkbox]').click()");
            await labelledInput('Verification explanation', 'Evidence reviewed in isolated browser test');
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('Provider review: verified')");
            await go('/provider/applicant-counts/1', 'Report external applicants');
            const csvPath = join(profile, 'responses.csv');
            await writeFile(csvPath, 'email\nexternal@example.test\nEXTERNAL@example.test\nother@example.test\n'.replaceAll('\\n', '\n'));
            const csvDocument = await command('DOM.getDocument');
            const csvNode = await command('DOM.querySelector', { nodeId: (csvDocument.root as { nodeId: number }).nodeId, selector: 'input[type=file]' });
            await command('DOM.setFileInputFiles', { nodeId: csvNode.nodeId, files: [csvPath] });
            await labelledInput('External source label', 'Browser verified CSV source');
            await evaluate(`(() => { const label = [...document.querySelectorAll('label')].find(item => item.textContent.trim() === 'CSV source as-of date'); const input = document.getElementById(label.htmlFor); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, new Date().toLocaleDateString('en-CA', {timeZone:'Asia/Manila'})); input.dispatchEvent(new Event('input', {bubbles:true})); input.dispatchEvent(new Event('change', {bubbles:true})); document.querySelector('input[type=file]').closest('form').querySelector('input[type=checkbox]').click(); })()`);
            await evaluate("document.querySelector('input[type=file]').closest('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('CSV count imported for administrator verification')");
            await go('/provider/scholarships/1/edit', 'Edit scholarship');
            assert.equal(await evaluate("document.querySelector('input[name=accepts_existing_scholars]') !== null"), true);
            assert.equal(await evaluate("document.body.textContent.includes('Give existing scholarship recipients lower priority')"), true);
            await logout();
            await login('student', 'Your student account');
            await go('/scholarship-announcements', 'Scholarship announcements');
            assert.equal(await evaluate("document.body.textContent.includes('Browser verified announcement')"), true);
            await go('/declarations', 'Existing scholarship declarations');
            assert.equal(await evaluate("document.body.textContent.includes('Provider review: verified')"), true);
            assert.equal(await evaluate("document.body.textContent.includes('Save provider verification')"), false);
            await go('/student/applications/1', 'Application details');
            assert.equal(await evaluate("document.body.textContent.includes('Outside browser scholarship: verified')"), true);
            assert.equal(await evaluate("document.body.textContent.includes('must verify declared scholarships')"), false);
            assert.equal(await evaluate("document.body.textContent.includes('will be marked low priority')"), false);
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                await go('/declarations', 'Existing scholarship declarations');
                assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true, 'Declarations fit ' + width);
                await go('/scholarship-announcements', 'Scholarship announcements');
                assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true, 'Announcements fit ' + width);
            }
            console.log('PASS: isolated browser announcement publish/read, CSV upload, provider declaration verification, student verification display, explicit priority settings, and 320/768/1440px layouts.');
        } else if (process.env.QA_INPUT_VALIDATION_CHECK === 'true') {
            const go = async (path: string, selector: string) => {
                await command('Page.navigate', { url: appUrl + path });
                await waitFor('document.querySelector(' + JSON.stringify(selector) + ') !== null');
            };
            const setValue = async (selector: string, value: string) => {
                await evaluate('(() => { const input = document.querySelector(' + JSON.stringify(selector) + '); const proto = input instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(proto, "value").set.call(input, ' + JSON.stringify(value) + '); input.dispatchEvent(new Event("input", { bubbles: true })); })()');
            };
            const submit = async () => evaluate("document.querySelector('main form').requestSubmit()");
            await go('/register', 'input[name=name]');
            await submit();
            await waitFor("document.querySelector('input[name=name]').getAttribute('aria-invalid') === 'true'");
            assert.equal(await evaluate("document.activeElement.name"), 'name');
            await setValue('input[name=name]', '123456');
            await setValue('input[name=email]', 'validation-new@iskoreach.test');
            await setValue('input[name=password]', 'valid-password!');
            await setValue('input[name=password_confirmation]', 'valid-password!');
            await submit();
            await waitFor("document.body.textContent.includes('Enter a name using letters')");
            await screenshot('registration-server-name-error');
            await setValue('input[name=name]', 'Ma. Jos\u00e9 O\u2019Neill');
            await setValue('input[name=email]', 'person @example.test');
            await submit();
            await waitFor("document.querySelector('input[name=email]').getAttribute('aria-invalid') === 'true'");
            await setValue('input[name=email]', 'STUDENT@iskoreach.test');
            await setValue('input[name=password]', 'valid-password!');
            await setValue('input[name=password_confirmation]', 'valid-password!');
            await submit();
            await waitFor("document.body.textContent.includes('already been taken')");
            await setValue('input[name=email]', 'validation-new@iskoreach.test');
            await setValue('input[name=password]', 'a'.repeat(73));
            await setValue('input[name=password_confirmation]', 'a'.repeat(73));
            await submit();
            await waitFor("document.body.textContent.includes('at most 72 UTF-8 bytes;')");
            assert.equal(await evaluate("document.querySelector('input[name=password]').type"), 'password');
            await setValue('input[name=password]', ' valid password! ');
            await setValue('input[name=password_confirmation]', 'different');
            await submit();
            await waitFor("document.body.textContent.includes('confirmation does not match')");
            await setValue('input[name=password_confirmation]', ' valid password! ');
            await submit();
            await waitFor("document.querySelector('h1')?.textContent === 'Your student account'");
            await logout();
            await go('/login', 'input[name=email]');
            await fill('input[name=email]', 'validation-new@iskoreach.test');
            await fill('input[name=password]', ' valid password! ');
            await submit();
            await waitFor("document.querySelector('h1')?.textContent === 'Your student account'");
            await go('/student/profile', 'input[name=school]');
            await setValue('input[name=year_level]', '13');
            await submit();
            await waitFor("document.querySelector('input[name=year_level]').getAttribute('aria-invalid') === 'true'");
            await setValue('input[name=school]', '  School #123 & College  ');
            await setValue('input[name=course]', '  BS IT (4-year)  ');
            await setValue('input[name=year_level]', '12');
            await submit();
            await waitFor("document.body.textContent.includes('Your profile has been saved.')");
            await go('/student/profile', 'input[name=school]');
            assert.equal(await evaluate("document.querySelector('input[name=school]').value"), 'School #123 & College');
            await screenshot('student-profile-normalized');
            await logout();
            await login('provider', 'Your provider account');
            await go('/provider/profile', 'input[name=contact_email]');
            await setValue('input[name=contact_email]', 'person @example.test');
            await submit();
            await waitFor("document.querySelector('input[name=contact_email]').getAttribute('aria-invalid') === 'true'");
            assert.equal(await evaluate("document.querySelector('[role=dialog]') === null"), true);
            await setValue('input[name=contact_email]', ' PROVIDER@ISKOREACH.TEST ');
            await submit();
            await waitFor("document.body.textContent.includes('No changes were made')");
            await go('/provider/scholarships/create', 'input[name=title]');
            await fill('input[name=title]', 'Validation Browser Grant');
            await fill('input[name=scholarship_type]', 'Merit');
            await setValue('textarea[name=description]', 'A valid grant description for the isolated browser fixture.');
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent === 'Add requirement').click()");
            await waitFor("document.querySelector('input[name=\"requirements.0.name\"]') !== null");
            assert.equal(await evaluate("document.querySelector('input[name=\"requirements.0.name\"]').maxLength"), 255);
            assert.equal(await evaluate("document.querySelector('input[name=\"requirements.0.description\"]').maxLength"), 2000);
            await submit();
            await waitFor("document.querySelector('input[name=\"requirements.0.name\"]').getAttribute('aria-invalid') === 'true'");
            await fill('input[name="requirements.0.name"]', 'Enrollment proof');
            await submit();
            await waitFor("document.querySelector('h1')?.textContent === 'Edit scholarship'");
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
                await screenshot('scholarship-requirements-' + width);
            }
            await logout();
            console.log('PASS: registration required/server-name/email/duplicate/password-byte/confirmation feedback, Unicode account creation, spaced-password login, student year/trim save, provider cosmetic approval preservation, nested requirement errors/limits, draft creation, and responsive layouts.');
        } else if (process.env.QA_ACADEMIC_TERMS_CHECK === 'true') {
            const click = async (text: string) => evaluate('[...document.querySelectorAll("button")].find(button => button.textContent.trim().startsWith(' + JSON.stringify(text) + ')).click()');
            const setValue = async (name: string, value: string) => {
                await evaluate('(() => { const input = document.querySelector(' + JSON.stringify('[name="' + name + '"]') + '); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value").set.call(input, ' + JSON.stringify(value) + '); input.dispatchEvent(new Event("input", { bubbles: true })); input.dispatchEvent(new Event("change", { bubbles: true })); })()');
            };
            const dialogSubmit = () => evaluate("document.querySelector('[role=dialog] form').requestSubmit()");
            const mutateFixture = async (action: string) => {
                assert.ok(process.env.QA_ACADEMIC_TERMS_DB?.includes('applicant-counts-terms-'));
                const php = spawn('php', [], { windowsHide: true, env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: process.env.QA_ACADEMIC_TERMS_DB, DB_URL: '', CACHE_STORE: 'array', MAIL_MAILER: 'array', BROADCAST_CONNECTION: 'null', QA_TERM_ACTION: action }, stdio: ['pipe', 'pipe', 'pipe'] });
                let output = ''; php.stdout.on('data', chunk => { output += chunk.toString(); }); php.stderr.on('data', chunk => { output += chunk.toString(); });
                const source = String.raw`<?php
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || !str_contains(config('database.connections.sqlite.database'), 'applicant-counts-terms-')) { throw new RuntimeException('Disposable term fixtures required.'); }
$term=App\Models\AcademicTerm::firstOrFail();
if (getenv('QA_TERM_ACTION') === 'stale') {
 $management=app(App\Services\AcademicTermManagement::class); $actor=App\Models\User::where('role','admin')->firstOrFail();
 $management->save($actor, [...$management->values($term), 'semester'=>'Updated by another administrator', 'version'=>$management->version($term)], $term);
} else {
 $provider=App\Models\ProviderProfile::firstOrFail(); $student=App\Models\User::where('email','student@iskoreach.test')->firstOrFail();
 $program=App\Models\Scholarship::create(['provider_id'=>$provider->id,'title'=>'Term workflow fixture','slug'=>'term-workflow-fixture','description'=>'Disposable academic term workflow fixture.','scholarship_type'=>'Merit','currency'=>'PHP','status'=>'published','published_at'=>now(),'opens_at'=>now()->subDay(),'deadline_at'=>now()->addMonth(),'accepts_existing_scholars'=>false,'academic_term_id'=>$term->id]);
 $program->locations()->create(['scope'=>'nationwide']);
 App\Models\Application::create(['scholarship_id'=>$program->id,'student_id'=>$student->id,'academic_term_id'=>$term->id,'status'=>'submitted','submitted_at'=>now()]);
}
echo 'Term fixture updated.';`;
                php.stdin.end(source.replaceAll(String.fromCharCode(92, 92), String.fromCharCode(92)));
                assert.equal(await new Promise(resolve => php.on('close', resolve)), 0, output);
            };
            const responsiveTerms = async (name: string) => {
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, name + ' fits ' + width);
                    if (width === 1440) {
                        assert.equal(await evaluate("document.querySelectorAll('#admin-sidebar nav a[aria-current=page]').length"), 1);
                        assert.equal(await evaluate("document.querySelector('#admin-sidebar nav a[aria-current=page]')?.getAttribute('href').endsWith('/admin/academic-terms')"), true);
                    }
                    await screenshot(name + '-' + width);
                }
            };
            await login('admin', 'Dashboard');
            await evaluate("document.querySelector('#admin-sidebar a[href$=\"/admin/academic-terms\"]').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Academic terms'");
            if (process.env.QA_TERMS_READONLY_CHECK !== 'true') {
            assert.equal(await evaluate("document.body.textContent.includes('No academic terms yet')"), true);
            await responsiveTerms('terms-empty');
            await click('Create term');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            await setValue('academic_year', '2026-2027');
            await setValue('semester', 'First semester');
            await setValue('starts_on', '2026-08-01');
            await setValue('ends_on', '2026-07-31');
            await evaluate("document.querySelector('input[name=confirmed]').click()");
            await dialogSubmit();
            await waitFor("document.querySelector('[role=dialog]').textContent.includes('The end date must be after')");
            assert.equal(await evaluate("document.querySelector('input[name=academic_year]').value"), '2026-2027');
            await setValue('ends_on', '2026-12-31');
            await responsiveTerms('terms-create-validation');
            await dialogSubmit();
            await waitFor("document.querySelector('[role=dialog]') === null && document.body.textContent.includes('Academic term created')");
            await responsiveTerms('terms-populated');
            await click('Create term');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await setValue('academic_year', '2026-2027');
            await setValue('semester', 'First semester');
            await evaluate("document.querySelector('input[name=confirmed]').click()");
            await dialogSubmit();
            await waitFor("document.querySelector('[role=dialog]').textContent.includes('already exist')");
            await click('Cancel');
            await waitFor("document.querySelector('[role=dialog]') === null");
            await delay(250);
            await click('Edit term');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await setValue('semester', 'First semester revised');
            await evaluate("document.querySelector('input[name=confirmed]').click()");
            await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
            await waitFor('navigator.onLine === false');
            await dialogSubmit();
            await waitFor("document.querySelector('[role=dialog]').textContent.includes('You are offline')");
            assert.equal(await evaluate("document.querySelector('input[name=semester]').value"), 'First semester revised');
            await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            await waitFor('navigator.onLine === true');
            await dialogSubmit();
            await waitFor("document.querySelector('[role=dialog]') === null && document.body.textContent.includes('First semester revised')");
            await click('Edit term');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await mutateFixture('stale');
            await evaluate("document.querySelector('input[name=confirmed]').click()");
            await dialogSubmit();
            await waitFor("document.querySelector('[role=dialog]').textContent.includes('This term changed')");
            await click('Reload latest terms');
            await waitFor("document.querySelector('[role=dialog]') === null && document.body.textContent.includes('Updated by another administrator')");
            await mutateFixture('reference');
            }
            await command('Page.navigate', { url: appUrl + '/admin/academic-terms' });
            await waitFor("document.body.textContent.includes('In use') && document.querySelector('h1')?.textContent === 'Academic terms'");
            await click('View usage');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelectorAll('[role=dialog] input').length"), 0);
            assert.equal(await evaluate("[...document.querySelectorAll('[role=dialog] button')].some(button => button.textContent.includes('Save changes'))"), false);
            await responsiveTerms('terms-read-only');
            await click('Close');
            await setValue('q', 'Unmatched label');
            await evaluate("document.querySelector('form[aria-label=\"Filter academic terms\"]').requestSubmit()");
            await waitFor("document.body.textContent.includes('No matching academic terms')");
            await click('Reset');
            await waitFor("document.body.textContent.includes('Updated by another administrator')");
            await command('Page.navigate', { url: appUrl + '/admin/activity-logs?type=academic_term' });
            await waitFor("document.querySelector('h1')?.textContent === 'Activity logs'");
            assert.equal(await evaluate("document.body.textContent.includes('Academic term updated')"), true);
            await logout();
            await login('provider', 'Your provider account');
            assert.equal(await evaluate("fetch('/admin/academic-terms').then(response => response.status)"), 403);
            await command('Page.navigate', { url: appUrl + '/provider/scholarships/create' });
            await waitFor("document.querySelector('select#academic_term_id') !== null");
            assert.equal(await evaluate("document.querySelector('select#academic_term_id').textContent.includes('Updated by another administrator')"), true);
            await logout();
            await login('student', 'Your student account');
            assert.equal(await evaluate("fetch('/admin/academic-terms').then(response => response.status)"), 403);
            await command('Page.navigate', { url: appUrl + '/student/applications/1' });
            await waitFor("document.body.textContent.includes('Saved academic term')");
            assert.equal(await evaluate("document.body.textContent.includes('Updated by another administrator')"), true);
            await logout();
            console.log(process.env.QA_TERMS_READONLY_CHECK === 'true' ? 'PASS: final read-only academic-term layouts, filters, audit, role restrictions, provider selector, and saved student term.' : 'PASS: academic-term empty/create/validation/duplicate/edit/offline/stale/read-only/filter/audit flows, role restrictions, provider selector, saved student term, and 320/768/1440px layouts.');
        } else if (process.env.QA_PUBLIC_SITE_CHECK === 'true') {

            const go = async (path: string, heading: string) => {
                await command('Page.navigate', { url: appUrl + path });
                await waitFor('document.querySelector("h1")?.textContent === ' + JSON.stringify(heading));
            };
            const publicNavigation = [['Home', '/'], ['Scholarships', '/scholarships'], ['About', '/#about'], ['Contact', '/#contact']];
            const checkPublicChrome = async () => {
                assert.deepEqual(await evaluate("[...document.querySelectorAll('header nav a')].map(link => [link.textContent, link.getAttribute('href')])"), publicNavigation);
                assert.equal(await evaluate("document.querySelector('footer nav[aria-label=\"Footer navigation\"]') !== null"), true);
                assert.equal(await evaluate("[...document.querySelectorAll('footer a')].filter(link => link.getAttribute('href')?.startsWith('#')).length"), 0, 'Footer section links resolve from every page');
                assert.equal(await evaluate("document.querySelector('#student-sidebar') === null"), true);
            };
            const checkSizes = async (name: string) => {
                await checkPublicChrome();
                for (const width of [320, 768, 1024, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, name + ' fits ' + width);
                    if ([320, 1440].includes(width)) await screenshot(name + '-' + width);
                }
            };
            await go('/', 'Find scholarships.Build your future.');
            assert.equal(await evaluate("document.querySelector('[data-testid=scholarship-grid]').textContent.includes('Phase Four Document Grant')"), true);
            assert.equal(await evaluate("document.querySelector('[data-testid=scholarship-grid]').textContent.includes('Browser Count Grant')"), false);
            await fill('input[name=q]', 'Phase Four');
            await fill('input[name=location]', 'Anywhere');
            await evaluate("document.querySelector('form[aria-label=\"Find scholarships\"]').requestSubmit()");
            await waitFor("document.querySelector('h1')?.textContent === 'Find a scholarship'");
            assert.equal(await evaluate("new URLSearchParams(location.search).get('location')"), 'Anywhere');
            assert.equal(await evaluate("document.body.textContent.includes('Phase Four Document Grant')"), true, 'Nationwide matches any location');
            await checkSizes('public-directory');
            await evaluate("document.querySelector('details').open = true");
            await evaluate("(() => { const select = document.querySelector('select[name=availability]'); const set = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set; set.call(select, 'upcoming'); select.dispatchEvent(new Event('change', { bubbles: true })); })()");
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('No matching scholarships')");
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Reset').click()");
            await waitFor("document.body.textContent.includes('Phase Four Document Grant') && location.search === ''");
            await go('/scholarships/phase-four-documents', 'Phase Four Document Grant');
            await evaluate("document.querySelector('footer a[href=\"/#how-it-works\"]').click()");
            await waitFor("document.querySelector('#how-it-works') !== null && location.hash === '#how-it-works'");
            assert.equal(await evaluate("document.querySelector('#how-it-works') !== null"), true);
            for (const hash of ['#about', '#contact']) {
                await evaluate('document.querySelector(' + JSON.stringify('header nav a[href="/' + hash + '"]') + ').click()');
                await waitFor('location.hash === ' + JSON.stringify(hash));
                await waitFor('document.querySelector("header nav a[aria-current=page]")?.getAttribute("href") === ' + JSON.stringify('/' + hash));
                assert.equal(await evaluate('document.querySelector(' + JSON.stringify(hash) + ') !== null'), true);
            }
            await evaluate("document.querySelector('footer a[href=\"/passers\"]').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Scholarship passers'");
            await checkPublicChrome();
            await evaluate("document.querySelector('header nav a[href=\"/scholarships\"]').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Find a scholarship'");
            await checkPublicChrome();
            await go('/scholarships/phase-four-documents', 'Phase Four Document Grant');
            assert.equal(await evaluate("document.body.textContent.includes('Aggregate counts cannot automatically')"), true);
            await checkSizes('public-details');
            await evaluate("[...document.querySelectorAll('a')].find(link => link.textContent.includes('Log in as a student')).click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Log in'");
            await fill('input[name=email]', 'student@iskoreach.test');
            await fill('input[name=password]', 'temporary-test-password');
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.querySelector('h1')?.textContent === 'Phase Four Document Grant'");
            assert.equal(await evaluate("document.querySelector('#student-sidebar') === null && document.querySelector('header nav[aria-label=\"Main navigation\"]') !== null"), true);
            assert.equal(await evaluate("[...document.querySelectorAll('button')].some(button => button.textContent.includes('Prepare application'))"), true);
            await logout();
            await go('/passers', 'Scholarship passers');
            assert.equal(await evaluate("document.body.textContent.includes('Public Browser Scholar')"), true);
            assert.equal(await evaluate("document.body.textContent.includes('student@iskoreach.test')"), false);
            await checkSizes('public-passers');
            await fill('input[name=q]', 'No such scholar');
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('No public listings match')");
            await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
            await waitFor('navigator.onLine === false');
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('You are offline')");
            await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            await waitFor('navigator.onLine === true');
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Reset').click()");
            await waitFor("document.body.textContent.includes('Public Browser Scholar') && location.search === ''");
            for (const [role, heading] of [['student', 'Your student account'], ['provider', 'Your provider account'], ['admin', 'Dashboard']]) {
                await login(role, heading);
                const footerLink = await evaluate("[...document.querySelectorAll('aside a')].find(link => link.textContent.includes('View public site'))?.getAttribute('href')");
                assert.ok(footerLink === '/' || footerLink === appUrl + '/');
                await go('/', 'Find scholarships.Build your future.');
                assert.equal(await evaluate("[...document.querySelectorAll('a')].some(link => link.textContent === 'My account')"), true);
                await go('/scholarships', 'Find a scholarship');
                await checkPublicChrome();
                await go('/scholarships/phase-four-documents', 'Phase Four Document Grant');
                await checkPublicChrome();
                await go('/passers', 'Scholarship passers');
                await checkPublicChrome();
                await command('Page.navigate', { url: appUrl + '/dashboard' });
                await waitFor('document.querySelector("h1")?.textContent === ' + JSON.stringify(heading));
                await logout();
            }
            console.log('PASS: public database listings, search, any-location match, availability/reset, details, selected-program login, passer privacy/search/offline recovery, role-to-public navigation, and responsive pages.');
        } else if (process.env.QA_LOGIN_REDIRECT_CHECK === 'true') {
            await command('Page.navigate', { url: appUrl + '/provider/dashboard' });
            await waitFor("document.querySelector('input[name=email]') !== null");
            await login('student', 'Your student account');
            assert.equal(await evaluate('location.pathname'), '/student/dashboard');
            assert.equal(await evaluate("fetch('/provider/dashboard').then(response => response.status)"), 403);
            await logout();
            await command('Page.navigate', { url: appUrl + '/student/profile' });
            await waitFor("document.querySelector('input[name=email]') !== null");
            await login('student', 'My Profile');
            assert.equal(await evaluate('location.pathname'), '/student/profile');
            await logout();
            await command('Page.navigate', { url: appUrl + '/student/dashboard' });
            await waitFor("document.querySelector('input[name=email]') !== null");
            await login('provider', 'Your provider account');
            assert.equal(await evaluate('location.pathname'), '/provider/dashboard');
            await logout();
            console.log('PASS: login discards another role workspace destination, preserves an authorized student profile deep link, and keeps role denials intact.');
        } else if (process.env.QA_STUDENT_STAGE1_CHECK === 'true') {
            const navigate = async (path: string, title: string) => { await command('Page.navigate', { url: appUrl + path }); await waitFor("document.querySelector('h1')?.textContent === " + JSON.stringify(title)); };
            const responsive = async (name: string) => {
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                    await delay(250);
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, name + ' fits ' + width);
                    assert.equal(await evaluate("document.querySelector('header').getBoundingClientRect().height"), 64);
                    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
                    assert.equal(await evaluate("[...document.querySelectorAll('input:not([type=file]):not([type=checkbox])')].every(item => parseFloat(getComputedStyle(item).fontSize) >= 16)"), true);
                    if (width >= 1024) {
                        assert.equal(await evaluate("document.getElementById('student-sidebar').getBoundingClientRect().width"), 240);
                        assert.equal(await evaluate("document.querySelectorAll('#student-sidebar nav a[aria-current=page]').length"), 1);
                        assert.equal(await evaluate("[...document.querySelectorAll('#student-sidebar nav a')].every(item => item.getBoundingClientRect().height >= 44)"), true);
                    }
                    await screenshot('student-' + name + '-' + width);
                }
            };
            const setValue = async (selector: string, value: string) => evaluate('(() => { const input = document.querySelector(' + JSON.stringify(selector) + '); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value").set.call(input, ' + JSON.stringify(value) + '); input.dispatchEvent(new Event("input", { bubbles: true })); input.dispatchEvent(new Event("change", { bubbles: true })); })()');
            await login('student', 'Your student account');
            assert.equal(await evaluate("document.body.textContent.includes('No submitted applications yet')"), true);
            assert.equal(await evaluate("document.body.textContent.includes('No upcoming renewal tasks.')"), true);
            await responsive('dashboard-empty');
            await navigate('/student/profile', 'My Profile');
            await responsive('profile');
            await setValue('input[name=school]', 'Browser Student University');
            await setValue('input[name=course]', 'BS Mathematics');
            await setValue('input[name=year_level]', '13');
            await evaluate("document.querySelector('form[aria-label=\"Student profile\"]').noValidate = true; document.querySelector('form[aria-label=\"Student profile\"]').requestSubmit()");
            await waitFor("document.querySelector('input[name=year_level]')?.getAttribute('aria-invalid') === 'true'");
            assert.equal(await evaluate("document.querySelector('input[name=school]').value"), 'Browser Student University');
            await setValue('input[name=year_level]', '3');
            await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
            await evaluate("document.querySelector('form[aria-label=\"Student profile\"]').requestSubmit()");
            await waitFor("document.body.textContent.includes('You are offline.')");
            await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            await evaluate("document.querySelector('form[aria-label=\"Student profile\"]').requestSubmit()");
            await waitFor("document.body.textContent.includes('Your profile has been saved.')");
            assert.equal(await evaluate("document.querySelector('input[name=school]').value"), 'Browser Student University');
            assert.equal(await evaluate("document.body.textContent.includes('You have unsaved changes.')"), false);
            assert.equal(await evaluate("fetch('/admin/dashboard').then(r => r.status)"), 403);
            assert.equal(await evaluate("fetch('/provider/dashboard').then(r => r.status)"), 403);
            assert.ok(process.env.QA_STUDENT_STAGE1_DB?.includes('applicant-counts-student-stage1-'));
            const php = spawn('php', [], { windowsHide: true, env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: process.env.QA_STUDENT_STAGE1_DB, DB_URL: '', MAIL_MAILER: 'array', BROADCAST_CONNECTION: 'null' }, stdio: ['pipe', 'pipe', 'pipe'] });
            let phpOutput = ''; php.stdout.on('data', data => phpOutput += data); php.stderr.on('data', data => phpOutput += data);
            php.stdin.end('<?php require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); if (config("database.default") !== "sqlite" || !str_contains(config("database.connections.sqlite.database"), "applicant-counts-student-stage1-")) throw new RuntimeException("Expected isolated fixture"); $student = App\\Models\\User::where("email", "student@iskoreach.test")->firstOrFail(); $provider = App\\Models\\ProviderProfile::firstOrFail(); $program = App\\Models\\Scholarship::factory()->create(["provider_id" => $provider->id, "title" => "Student Stage One Grant"]); $accepted = App\\Models\\Application::factory()->create(["student_id" => $student->id, "scholarship_id" => $program->id, "status" => "accepted"]); App\\Models\\Application::factory()->create(["student_id" => $student->id, "status" => "under_review"]); App\\Models\\Application::factory()->create(["student_id" => $student->id, "status" => "not_started", "submitted_at" => null]); $foreign = App\\Models\\Application::factory()->create(); $award = App\\Models\\ScholarshipAward::factory()->create(["application_id" => $accepted->id]); $renewal = App\\Models\\Renewal::factory()->create(["scholarship_award_id" => $award->id, "requirements_snapshot" => []]); echo json_encode(["application" => $accepted->id, "foreign" => $foreign->id, "renewal" => $renewal->id]);');
            assert.equal(await new Promise(resolve => php.on('close', resolve)), 0, phpOutput);
            const ids = JSON.parse(phpOutput);
            await navigate('/student/dashboard', 'Your student account');
            assert.equal(await evaluate("document.body.textContent.includes('Student Stage One Grant')"), true);
            assert.equal(await evaluate("document.querySelectorAll('main a[href$=\"/student/applications/" + ids.application + "\"]').length"), 1);
            assert.equal(await evaluate("document.querySelectorAll('main a[href$=\"/renewals/" + ids.renewal + "\"]').length"), 1);
            await responsive('dashboard-populated');
            assert.equal(await evaluate("fetch('/student/applications/" + ids.foreign + "').then(r => r.status)"), 403);
            const pages = [
                ['/student/applications', 'My applications'], ['/student/documents', 'My document vault'],
                ['/messages', 'Messages'], ['/notifications', 'Notifications'], ['/renewals', 'Renewals'],
                ['/benefits', 'Awards and benefits'], ['/student/awards', 'My awards'],
            ];
            for (const [path, title] of pages) { await navigate(path, title); await responsive(path.split('/').pop()!); }
            await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 400, deviceScaleFactor: 1, mobile: false });
            assert.equal(await evaluate("getComputedStyle(document.getElementById('student-sidebar')).scrollbarWidth"), 'none');
            assert.equal(await evaluate("(() => { const el = document.getElementById('student-sidebar'); el.scrollTop = el.scrollHeight; return el.scrollTop > 0; })()"), true);
            await evaluate("document.querySelector('button[aria-label=\"Collapse sidebar\"]').click()");
            await waitFor("getComputedStyle(document.getElementById('student-sidebar')).width === '80px'");
            await evaluate('window.__studentStage1Reload = true');
            await command('Page.reload');
            await waitFor("window.__studentStage1Reload !== true && document.querySelector('h1')?.textContent === 'My awards' && getComputedStyle(document.getElementById('student-sidebar')).width === '80px'");
            await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]').click()");
            await waitFor("getComputedStyle(document.getElementById('student-sidebar')).visibility === 'visible'");
            await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 800, deviceScaleFactor: 1, mobile: true });
            await evaluate("document.querySelector('button[aria-label=\"Open student navigation\"]').click()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            assert.equal(await evaluate("document.querySelectorAll('[role=dialog] nav a').length"), 10);
            await screenshot('student-mobile-drawer');
            await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await waitFor("document.querySelector('[role=dialog]') === null");
            assert.equal(await evaluate("document.activeElement.getAttribute('aria-label')"), 'Open student navigation');
            await navigate('/student/dashboard', 'Your student account');
            await evaluate("document.querySelector('main a[href=\"/scholarships\"]').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Find a scholarship'");
            await navigate('/student/dashboard', 'Your student account');
            await logout();
            console.log('PASS: Student Stage 1 empty/populated dashboard, profile validation/save/offline recovery, private-role denials, ten navigation links, responsive layouts, collapse persistence, hidden scrollbars and mobile drawer.');
        } else if (process.env.QA_LOCATION_CHECK === 'true') {
            const click = async (text: string, selector = 'button') => evaluate('[...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(item => item.textContent.trim() === ' + JSON.stringify(text) + ').click()');
            const setValue = async (selector: string, value: string) => evaluate('(() => { const input = document.querySelector(' + JSON.stringify(selector) + '); Object.getOwnPropertyDescriptor(input instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype, "value").set.call(input, ' + JSON.stringify(value) + '); input.dispatchEvent(new Event("input", { bubbles: true })); input.dispatchEvent(new Event("change", { bubbles: true })); })()');
            const responsive = async (name: string) => { for (const width of [320, 768, 1440]) { await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 }); await delay(250); assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true); await screenshot(name + '-' + width); } };
            await login('provider', 'Your provider account');
            await command('Page.navigate', { url: appUrl + '/provider/scholarships/create' });
            await waitFor("document.querySelector('h1')?.textContent === 'Create scholarship'");
            await setValue('#scope-0', 'city');
            await waitFor("document.querySelector('#region-0 option[value=\"130000000\"]') !== null");
            await setValue('#region-0', '130000000');
            await waitFor("document.querySelector('#city-0 option[value=\"137404000\"]') !== null");
            await setValue('#city-0', '137404000');
            await setValue('#region-0', '040000000');
            assert.equal(await evaluate("document.querySelector('#city-0').value"), '');
            await waitFor("document.querySelector('#city-0 option[value=\"043404000\"]') !== null");
            await setValue('#region-0', '130000000');
            await waitFor("document.querySelector('#city-0 option[value=\"137404000\"]') !== null");
            await setValue('#city-0', '137404000');
            await responsive('provider-location-picker');
            await fill('input[name=title]', 'API Location Grant');
            await fill('#description', 'A scholarship with API-backed Philippine location selection.');
            await fill('input[name=scholarship_type]', 'Academic');
            await setValue('input[name=opens_at]', new Date(Date.now() + 8 * 3600000 - 86400000).toISOString().slice(0,16));
            await setValue('input[name=deadline_at]', new Date(Date.now() + 8 * 3600000 + 30 * 86400000).toISOString().slice(0,16));
            await click('Create draft');
            await waitFor("document.querySelector('h1')?.textContent === 'Edit scholarship'");
            await waitFor("document.querySelector('#city-0').value === '137404000' && !document.querySelector('#city-0').disabled");
            await command('Network.setCacheDisabled', { cacheDisabled: true });
            await command('Network.setBlockedURLs', { urls: ['*/locations/regions*'] });
            await evaluate('window.__locationReload = true');
            await command('Page.reload');
            await waitFor("window.__locationReload !== true && document.body.textContent.includes('Your selection is preserved')");
            assert.equal(await evaluate("document.querySelector('#region-0').value"), '130000000');
            assert.equal(await evaluate("document.querySelector('#city-0').value"), '137404000');
            await screenshot('location-lookup-offline');
            await command('Network.setBlockedURLs', { urls: [] });
            await click('Retry location lookup');
            await waitFor("!document.querySelector('#city-0').disabled && document.querySelector('#city-0').selectedOptions[0].textContent === 'Quezon City'");
            await click('Publish');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await fill('input[name=reason]', 'Confirmed program location and opening dates.');
            await evaluate("document.querySelector('input[name=confirmed]').click()");
            await click('Confirm change');
            await waitFor("document.querySelector('h1')?.textContent === 'My scholarships'");
            await click('View public listing', 'main a');
            await waitFor("document.querySelector('h1')?.textContent === 'API Location Grant'");
            assert.equal(await evaluate("document.body.textContent.includes('Quezon City') && document.body.textContent.includes('NCR')"), true);
            assert.equal(await evaluate("document.body.textContent.includes('137404000')"), false);
            await responsive('public-location-names');
            await command('Page.navigate', { url: appUrl + '/scholarships?location=Quezon' });
            await waitFor("document.querySelector('h1')?.textContent === 'Find a scholarship'");
            assert.equal(await evaluate("document.body.textContent.includes('API Location Grant')"), true);
            await command('Page.navigate', { url: appUrl + '/provider/dashboard' });
            await waitFor("document.querySelector('h1')?.textContent === 'Your provider account'");
            await logout();
            console.log('API-backed region/city selection, dependent reset, saved selection, offline/retry recovery, publication, readable names, name search and responsive checks passed.');
        } else if (process.env.QA_HIDDEN_SCROLLBARS_CHECK === 'true') {
            const check = async (selector: string) => {
                await waitFor('document.querySelector(' + JSON.stringify(selector) + ') !== null');
                const expression = 'document.querySelector(' + JSON.stringify(selector) + ')';
                assert.equal(await evaluate('getComputedStyle(' + expression + ').scrollbarWidth'), 'none');
                assert.equal(await evaluate('getComputedStyle(' + expression + ', "::-webkit-scrollbar").display'), 'none');
                assert.equal(await evaluate(expression + '.scrollHeight > ' + expression + '.clientHeight'), true, 'Fixture height must require scrolling');
                await evaluate(expression + '.scrollTop = 0');
                const point = await evaluate('(() => { const box = ' + expression + '.getBoundingClientRect(); return { x: box.x + box.width / 2, y: box.y + Math.min(box.height / 2, 180) }; })()') as { x: number; y: number };
                await command('Input.dispatchMouseEvent', { type: 'mouseWheel', x: point.x, y: point.y, deltaX: 0, deltaY: 350 });
                await waitFor(expression + '.scrollTop > 0');
                await evaluate(expression + '.scrollTop = 0; ' + expression + '.querySelector("a").focus()');
                await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'PageDown', code: 'PageDown', windowsVirtualKeyCode: 34 });
                await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'PageDown', code: 'PageDown', windowsVirtualKeyCode: 34 });
                await waitFor(expression + '.scrollTop > 0');
            };
            for (const [role, heading, selector] of [['provider', 'Your provider account', '#provider-sidebar'], ['admin', 'Dashboard', '#admin-sidebar'], ['student', 'Your student account', '#account-navigation']]) {
                await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 400, deviceScaleFactor: 1, mobile: false });
                await login(role, heading);
                await check(selector);
                await logout();
            }
            await login('provider', 'Your provider account');
            await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 400, deviceScaleFactor: 1, mobile: true });
            await evaluate("document.querySelector('button[aria-label=\"Open provider navigation\"]').click()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await check('[role=dialog] [data-headlessui-state][class*="overflow-y-auto"]');
            await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await waitFor("document.querySelector('[role=dialog]') === null");
            await logout();
            console.log('Hidden scrollbar checks passed for provider/admin/student desktop sidebars and the provider mobile drawer; wheel and keyboard scrolling remain available.');
        } else if (process.env.QA_PROVIDER_UX_CHECK === 'true') {
            const click = async (text: string, selector = 'button') => evaluate('[...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(item => item.textContent.trim() === ' + JSON.stringify(text) + ').click()');
            const pages = [
                ['/provider/dashboard', 'Your provider account', 'dashboard'],
                ['/provider/profile', 'Organization profile', 'profile'],
                ['/provider/scholarships', 'My scholarships', 'scholarships'],
                ['/provider/applications', 'Applicants', 'applicants'],
                ['/messages', 'Messages', 'messages'],
                ['/renewals', 'Renewals', 'renewals'],
                ['/benefits', 'Awards and benefits', 'benefits'],
                ['/provider/applicant-counts', 'Applicant counts', 'counts'],
                ['/provider/workspace', 'Provider workspace', 'workspace'],
            ];
            const navigate = async (path: string, heading: string) => { await command('Page.navigate', { url: appUrl + path }); await waitFor("document.querySelector('h1')?.textContent === " + JSON.stringify(heading)); };
            const responsive = async (name: string) => {
                for (const width of [320, 768, 1024, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                    await delay(250);
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, name + ' must fit ' + width);
                    assert.equal(await evaluate("document.querySelector('header').getBoundingClientRect().height"), 64);
                    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
                    assert.equal(await evaluate("[...document.querySelectorAll('input, select, textarea')].filter(item => item.type !== 'checkbox').every(item => parseFloat(getComputedStyle(item).fontSize) >= 16)"), true, 'Readable controls at ' + width);
                    if (width >= 1024) {
                        assert.equal(await evaluate("document.getElementById('provider-sidebar').getBoundingClientRect().width"), 240);
                        assert.equal(await evaluate("document.querySelectorAll('#provider-sidebar a[aria-current=page]').length"), 1);
                        assert.equal(await evaluate("[...document.querySelectorAll('#provider-sidebar nav a')].every(item => item.getBoundingClientRect().height >= 44)"), true);
                    }
                    await screenshot(name + '-' + width);
                }
            };
            await login('provider', 'Your provider account');
            for (const [path, heading, name] of pages) {
                await navigate(path, heading);
                await responsive('empty-' + name);
            }
            await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
            await evaluate("document.querySelector('button[aria-label=\"Collapse sidebar\"]').click()");
            await waitFor("getComputedStyle(document.getElementById('provider-sidebar')).width === '80px'");
            await evaluate('window.__providerUxReload = true');
            await command('Page.reload');
            await waitFor("window.__providerUxReload !== true && document.querySelector('h1')?.textContent === 'Provider workspace' && getComputedStyle(document.getElementById('provider-sidebar')).width === '80px'");
            await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]').click()");
            await waitFor("getComputedStyle(document.getElementById('provider-sidebar')).visibility === 'visible'");
            await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 800, deviceScaleFactor: 1, mobile: true });
            await evaluate("document.querySelector('button[aria-label=\"Open provider navigation\"]').click()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelectorAll('[role=dialog] nav a').length"), 9);
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            assert.equal(await evaluate("[...document.querySelectorAll('[role=dialog] a')].some(item => item.textContent === 'Provider workspace')"), true);
            await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await waitFor("document.querySelector('[role=dialog]') === null");
            assert.ok(process.env.QA_PROVIDER_UX_DB?.includes('applicant-counts-'));
            const php = spawn('php', [], { windowsHide: true, env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: process.env.QA_PROVIDER_UX_DB, DB_URL: '', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'database', BROADCAST_CONNECTION: 'null' }, stdio: ['pipe', 'pipe', 'pipe'] });
            php.stdin.end(`<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if(config('database.default')!=='sqlite' || !str_contains(config('database.connections.sqlite.database'),'applicant-counts-provider-ux-')){throw new RuntimeException('Expected isolated provider UX fixture.');}
$provider=App\\Models\\ProviderProfile::firstOrFail();$student=App\\Models\\User::where('role','student')->firstOrFail();
$program=App\\Models\\Scholarship::create(['provider_id'=>$provider->id,'title'=>'Community Leadership and Academic Excellence Scholarship for Undergraduate Students','slug'=>'provider-ux-grant','description'=>'Disposable provider UX fixture.','scholarship_type'=>'Merit','status'=>'published','published_at'=>now(),'opens_at'=>now()->subDay(),'deadline_at'=>now()->addMonth(),'accepts_existing_scholars'=>false]);
$program->locations()->create(['scope'=>'nationwide']);
App\\Models\\Scholarship::create(['provider_id'=>$provider->id,'title'=>'New Scholar Support Grant','slug'=>'provider-ux-draft','description'=>'Draft fixture.','scholarship_type'=>'Merit','status'=>'draft','accepts_existing_scholars'=>false]);
$application=App\\Models\\Application::create(['scholarship_id'=>$program->id,'student_id'=>$student->id,'status'=>'accepted','submitted_at'=>now(),'submission_snapshot'=>['student'=>['name'=>$student->name,'school'=>'Fixture College','course'=>'Computer Science','year_level'=>'2']]]);
$term=App\\Models\\AcademicTerm::create(['academic_year'=>'2026-2027','semester'=>'First semester','starts_on'=>now()->toDateString(),'ends_on'=>now()->addMonths(5)->toDateString()]);
$award=App\\Models\\ScholarshipAward::create(['application_id'=>$application->id,'academic_term_id'=>$term->id,'status'=>'active','starts_on'=>now('Asia/Manila')->toDateString(),'ends_on'=>now('Asia/Manila')->addMonths(5)->toDateString()]);
App\\Models\\Renewal::create(['scholarship_award_id'=>$award->id,'academic_term_id'=>$term->id,'status'=>'pending','deadline_at'=>now()->addMonth(),'requirements_snapshot'=>[]]);
App\\Models\\BenefitRelease::create(['scholarship_award_id'=>$award->id,'academic_term_id'=>$term->id,'amount'=>'5000.00','currency'=>'PHP','status'=>'pending','scheduled_at'=>now()->addWeek(),'recorded_by'=>$provider->user_id]);
$conversation=App\\Models\\Conversation::create(['student_id'=>$student->id,'provider_id'=>$provider->id,'last_message_at'=>now()]);
App\\Models\\Message::create(['conversation_id'=>$conversation->id,'sender_id'=>$student->id,'body'=>'Private fixture question.','submission_token'=>(string)Illuminate\\Support\\Str::uuid()]);
echo 'Provider UX population complete.';
`);
            let failure = ''; php.stderr.on('data', chunk => { failure += chunk; });
            await new Promise<void>((resolve, reject) => { php.on('error', reject); php.on('exit', code => code === 0 ? resolve() : reject(new Error(failure))); });
            for (const [path, heading, name] of pages) {
                await navigate(path, heading);
                await responsive('populated-' + name);
            }
            await navigate('/provider/scholarships', 'My scholarships');
            await fill('input[name=q]', 'No matching program');
            await click('Apply filters');
            await waitFor("document.body.textContent.includes('No matching scholarships')");
            await click('Reset');
            await waitFor("document.body.textContent.includes('Community Leadership')");
            await navigate('/provider/applications', 'Applicants');
            await fill('input[name=q]', 'No matching program'); await click('Apply filters');
            await waitFor("document.body.textContent.includes('No matching submitted applications')");
            await click('Reset'); await waitFor("document.body.textContent.includes('Community Leadership')");
            await navigate('/messages', 'Messages');
            await evaluate("document.querySelector('main a[href=\"/messages/1\" ]').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Conversation'");
            await navigate('/provider/profile', 'Organization profile');
            await fill('input[name=organization_name]', ' Updated');
            await click('Save organization details');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await click('Cancel'); await waitFor("document.querySelector('[role=dialog]') === null");
            await navigate('/provider/profile', 'Organization profile');
            assert.equal(await evaluate("document.querySelector('input[name=organization_name]').value"), 'Browser Scholarship Foundation');
            await logout();
            console.log('Provider UX: all nine pages passed empty/populated checks at 320/768/1024/1440px; compact header/sidebar, active links, controls, collapse persistence, mobile drawer, filters, message entry and profile confirmation/cancel verified.');
        } else if (process.env.QA_BENEFITS_CHECK === 'true') {
            const click = async (text: string, selector = 'button') => evaluate('[...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(item => item.textContent.trim() === ' + JSON.stringify(text) + ').click()');
            const setValue = async (selector: string, value: string) => {
                await evaluate('(() => { const input = document.querySelector(' + JSON.stringify(selector) + '); Object.getOwnPropertyDescriptor(input instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : input instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype, "value").set.call(input, ' + JSON.stringify(value) + '); input.dispatchEvent(new Event("input", { bubbles: true })); input.dispatchEvent(new Event("change", { bubbles: true })); })()');
            };
            const responsive = async (name: string) => {
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    await delay(250);
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, name + ' must fit ' + width);
                    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
                    await screenshot(name + '-' + width);
                }
            };
            const open = async (path: string, heading: string) => { await command('Page.navigate', { url: appUrl + path }); await waitFor("document.querySelector('h1')?.textContent === " + JSON.stringify(heading)); };
            const confirmed = async () => evaluate("document.querySelector('[role=dialog] input[name=confirmed]').click()");
            await login('provider', 'Your provider account');
            await open('/provider/applications/1', 'Applicant review');
            await click('Manage confirmed award', 'a');
            await waitFor("document.querySelector('h1')?.textContent === 'Confirm scholarship award'");
            await responsive('provider-award-form');
            await setValue('select[name=academic_term_id]', '1');
            const today = new Date(Date.now() + 8 * 3600000).toISOString().slice(0, 10);
            await setValue('input[name=starts_on]', today);
            await fill('textarea[name=reason]', 'Provider confirmed the scholarship award.');
            await click('Review award confirmation');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await confirmed(); await click('Cancel');
            await waitFor("document.querySelector('[role=dialog]') === null");
            await click('Review award confirmation');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await click('Confirm award');
            await waitFor("document.querySelector('h1')?.textContent === 'Award AW-1'");
            await responsive('provider-confirmed-award');
            await click('Add benefit record');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await setValue('input[name=amount]', '2500.25');
            await fill('textarea[name=reason]', 'First semester benefit installment.');
            await confirmed();
            await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
            await click('Save benefit record');
            await waitFor("document.querySelector('[role=alert]')?.textContent.includes('offline')");
            assert.equal(await evaluate("document.querySelector('input[name=amount]').value"), '2500.25');
            await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            await click('Save benefit record');
            await waitFor("document.querySelector('[role=dialog]') === null && document.body.textContent.includes('BR-1')");
            await click('Mark processing');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await fill('textarea[name=reason]', 'Installment processing with the organization.');
            await confirmed(); await click('Confirm update');
            await waitFor("document.querySelector('[role=dialog]') === null && document.body.textContent.includes('Processing')");
            await click('Record released');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            const releaseDate = new Date(Date.now() + 8 * 3600000 - 60000).toISOString().slice(0, 16);
            await setValue('input[name=released_at]', releaseDate);
            await fill('textarea[name=reason]', 'Provider confirmed the actual benefit release.');
            await confirmed(); await click('Confirm update');
            await waitFor("document.querySelector('[role=dialog]') === null && document.body.textContent.includes('Released')");
            assert.equal(await evaluate("[...document.querySelectorAll('button')].some(item => item.textContent === 'Record released')"), false);
            await responsive('provider-released-benefit');
            await logout();
            await login('student', 'Your student account');
            await open('/benefits', 'Awards and benefits');
            await responsive('student-benefit-list');
            await click('Browser Count Grant', 'main a');
            await waitFor("document.querySelector('h1')?.textContent === 'Award AW-1'");
            assert.equal(await evaluate("document.body.textContent.includes('PHP 2500.25') && document.body.textContent.includes('Released')"), true);
            assert.equal(await evaluate("[...document.querySelectorAll('button')].some(item => item.textContent === 'Revoke award')"), false);
            await responsive('student-benefit-history');
            await open('/student/awards', 'My awards');
            await click('Choose public listing');
            await waitFor("document.querySelector('[role=dialog]') !== null"); await confirmed();
            await click('Give consent');
            await waitFor("document.querySelector('[role=dialog]') === null");
            await logout();
            await login('admin', 'Dashboard');
            await open('/admin/awards', 'Awards and passers');
            await click('Review listing');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await fill('input[name=reason]', 'Confirmed active award and student consent.');
            await confirmed(); await evaluate("document.querySelector('[role=dialog] form').requestSubmit()");
            await waitFor("document.querySelector('[role=dialog]') === null && document.body.textContent.includes('Publicly listed')");
            assert.equal(await evaluate('(async () => (await fetch("/benefits/1", { headers: { Accept: "application/json" } })).status)()'), 403);
            await logout();
            await open('/passers', 'Scholarship passers');
            await waitFor("document.body.textContent.includes('Student Browser Fixture')");
            await responsive('public-passers-filters');
            await setValue('input[name=q]', 'Nonexistent');
            await click('Search');
            await waitFor("document.body.textContent.includes('No public listings match')");
            await click('Reset');
            await waitFor("document.body.textContent.includes('Student Browser Fixture')");
            await login('provider', 'Your provider account');
            await open('/benefits/1', 'Award AW-1');
            await click('Revoke award');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await fill('textarea[name=reason]', 'Award revoked in the disposable QA fixture.');
            await confirmed(); await click('Confirm update');
            await waitFor("document.querySelector('[role=dialog]') === null && document.body.textContent.includes('revoked')");
            assert.equal(await evaluate("document.body.textContent.includes('PHP 2500.25') && document.body.textContent.includes('Released')"), true);
            await logout(); await open('/passers', 'Scholarship passers');
            await waitFor("document.body.textContent.includes('No public results yet')");
            console.log('Award creation, confirmation/cancel, offline benefit recovery, processing/released history, student ownership, consent/publication, public filters, revocation, and responsive checks passed.');
        } else if (process.env.QA_RENEWALS_CHECK === 'true') {
            const click = async (text: string, selector = 'button') => evaluate('[...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(item => item.textContent.trim() === ' + JSON.stringify(text) + ').click()');
            const setValue = async (selector: string, value: string) => {
                await evaluate('(() => { const input = document.querySelector(' + JSON.stringify(selector) + '); Object.getOwnPropertyDescriptor(input instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype, "value").set.call(input, ' + JSON.stringify(value) + '); input.dispatchEvent(new Event("input", { bubbles: true })); input.dispatchEvent(new Event("change", { bubbles: true })); })()');
            };
            const responsive = async (name: string) => {
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    await delay(250);
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, name + ' must fit ' + width);
                    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
                    await screenshot(name + '-' + width);
                }
            };
            await login('provider', 'Your provider account');
            await command('Page.navigate', { url: appUrl + '/renewals' });
            await waitFor("document.querySelector('h1')?.textContent === 'Renewals'");
            await responsive('provider-renewals');
            await click('Open renewal request');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await setValue('select[name=award_id]', '1');
            await setValue('select[name=academic_term_id]', '1');
            const deadline = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 16);
            await setValue('input[name=deadline_at]', deadline);
            await evaluate("document.querySelector('input[name=confirmed]').click()");
            await click('Cancel');
            await waitFor("document.querySelector('[role=dialog]') === null");
            await click('Open renewal request');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await click('Open request');
            await waitFor("document.querySelector('h1')?.textContent === 'Renewal RN-1'");
            await responsive('provider-pending-renewal');
            assert.equal(await evaluate("document.body.textContent.includes('Student draft files remain private')"), true);
            await logout();
            await login('student', 'Your student account');
            await command('Page.navigate', { url: appUrl + '/renewals/1' });
            await waitFor("document.querySelector('h1')?.textContent === 'Renewal RN-1'");
            assert.equal(await evaluate("[...document.querySelectorAll('button')].find(item => item.textContent === 'Review and submit renewal').disabled"), true);
            await responsive('student-renewal-checklist');
            await setValue('select[aria-label="Vault file for Renewal enrollment proof"]', '1');
            await evaluate("document.querySelector('input[type=checkbox]').click()");
            await click('Attach file');
            await waitFor("document.body.textContent.includes('renewal-proof.pdf')");
            await click('Remove attachment');
            await evaluate("document.querySelector('input[type=checkbox]').click()");
            await click('Confirm removal');
            await waitFor("!document.body.textContent.includes('renewal-proof.pdf')");
            await setValue('select[aria-label="Vault file for Renewal enrollment proof"]', '1');
            await evaluate("document.querySelector('input[type=checkbox]').click()");
            await click('Attach file');
            await waitFor("document.body.textContent.includes('renewal-proof.pdf')");
            await click('Review and submit renewal');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await evaluate("document.querySelector('[role=dialog] input[name=confirmed]').click()");
            await click('Cancel');
            await click('Review and submit renewal');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await evaluate("document.querySelector('[role=dialog] input[name=confirmed]').click()");
            await click('Confirm submit');
            await waitFor("!document.querySelector('[role=dialog]') && document.body.textContent.includes('Submitted')");
            await responsive('student-submitted-renewal');
            const downloadUrl = await evaluate("[...document.querySelectorAll('a')].find(item => item.textContent === 'renewal-proof.pdf').href") as string;
            const download = await evaluate('(async () => { const response = await fetch(' + JSON.stringify(downloadUrl) + '); return { status: response.status, cache: response.headers.get("Cache-Control"), disposition: response.headers.get("Content-Disposition") }; })()') as { status: number; cache: string; disposition: string };
            assert.equal(download.status, 200); assert.ok(download.cache.includes('no-store')); assert.ok(download.disposition.includes('attachment'));
            await logout();
            await login('provider', 'Your provider account');
            await command('Page.navigate', { url: appUrl + '/renewals/1' });
            await waitFor("document.querySelector('h1')?.textContent === 'Renewal RN-1'");
            await responsive('provider-renewal-review');
            assert.equal(await evaluate('(async () => (await fetch(' + JSON.stringify(downloadUrl) + ')).status)()'), 200);
            await click('Approve renewal');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await fill('textarea[name=reason]', 'Renewal documents reviewed in disposable browser QA.');
            await evaluate("document.querySelector('input[name=confirmed]').click()");
            await click('Confirm approved');
            await waitFor("!document.querySelector('[role=dialog]') && document.body.textContent.includes('Approved')");
            assert.equal(await evaluate("[...document.querySelectorAll('button')].some(button => button.textContent === 'Reject renewal')"), false);
            await responsive('provider-approved-renewal');
            await logout();
            await login('admin', 'Dashboard');
            assert.equal(await evaluate('(async () => (await fetch(' + JSON.stringify(downloadUrl) + ', { headers: { Accept: "application/json" } })).status)()'), 403);
            await logout();
            console.log('Renewal request, confirmation/cancel, checklist attach/remove, submission, private downloads, approval, final decisions, role denial, and responsive checks passed.');
        } else if (process.env.QA_CHAT_CHECK === 'true') {
            const click = async (text: string, selector = 'button') => evaluate('[...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(item => item.textContent.trim() === ' + JSON.stringify(text) + ').click()');
            const responsive = async (name: string) => {
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    await delay(250);
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, name + ' must fit ' + width);
                    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
                    await screenshot(name + '-' + width);
                }
            };
            const fixture = async (mode: string) => {
                assert.ok(process.env.QA_CHAT_DB?.includes('applicant-counts-'));
                const php = spawn('php', [], { windowsHide: true, env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: process.env.QA_CHAT_DB, DB_URL: '', CACHE_STORE: 'file', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', BROADCAST_CONNECTION: 'reverb', REVERB_APP_ID: 'chat-fixture', REVERB_APP_KEY: 'chat-fixture-key', REVERB_APP_SECRET: 'chat-fixture-secret', REVERB_HOST: '127.0.0.1', REVERB_PORT: '8085', REVERB_SCHEME: 'http', QA_CHAT_MODE: mode }, stdio: ['pipe', 'pipe', 'pipe'] });
                php.stdin.end(`<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || !str_contains(config('database.connections.sqlite.database'), 'applicant-counts-')) { throw new RuntimeException('Expected disposable SQLite.'); }
$thread = App\\Models\\Conversation::firstOrFail();
if (getenv('QA_CHAT_MODE') === 'typing') { app(App\\Services\\PrivateChat::class)->typing($thread->student, $thread, true); }
elseif (getenv('QA_CHAT_MODE') === 'read') { app(App\\Services\\PrivateChat::class)->read($thread->student, $thread, $thread->messages()->max('id')); }
else { $thread->provider->forceFill(['verification_status' => 'pending'])->save(); }
echo 'Chat fixture action complete.';
`);
                let error = ''; php.stderr.on('data', chunk => { error += chunk; });
                await new Promise<void>((resolve, reject) => { php.on('error', reject); php.on('exit', code => code === 0 ? resolve() : reject(new Error('Disposable chat action failed: ' + error))); });
            };
            const authChannel = async (socketId: string, channel: string) => evaluate('(async () => { const token = document.cookie.split("; ").find(cookie => cookie.startsWith("XSRF-TOKEN="))?.slice(11); const response = await fetch("/broadcasting/auth", { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", "X-XSRF-TOKEN": decodeURIComponent(token || "") }, body: JSON.stringify(' + JSON.stringify({ socket_id: socketId, channel_name: channel }) + ') }); return { status: response.status, body: await response.json() }; })()') as Promise<{ status: number; body: { auth: string } }>;
            await login('student', 'Your student account');
            await command('Page.navigate', { url: appUrl + '/student/applications/1' });
            await waitFor("document.querySelector('h1')?.textContent === 'Application details'");
            await click('Open conversation');
            await waitFor("document.querySelector('h1')?.textContent === 'Conversation'");
            const threadUrl = await evaluate('location.pathname') as string;
            const channel = 'private-chat.' + threadUrl.split('/').at(-1);
            const frames: { event: string; data: string }[] = [];
            chatSocket = new WebSocket('ws://127.0.0.1:8085/app/chat-fixture-key?protocol=7&client=js&version=8.4.0&flash=false');
            chatSocket.onmessage = event => frames.push(JSON.parse(String(event.data)));
            await new Promise<void>((resolve, reject) => { chatSocket!.onopen = () => resolve(); chatSocket!.onerror = () => reject(new Error('Local Reverb connection failed')); });
            for (let i = 0; i < 100 && !frames.some(frame => frame.event === 'pusher:connection_established'); i++) await delay(100);
            const connected = frames.find(frame => frame.event === 'pusher:connection_established');
            assert.ok(connected, 'Local Reverb must establish a real WebSocket connection');
            const socketId = JSON.parse(connected.data).socket_id as string;
            const auth = await authChannel(socketId, channel);
            assert.equal(auth.status, 200, 'Student can authorize their private conversation');
            chatSocket.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth: auth.body.auth, channel } }));
            for (let i = 0; i < 100 && !frames.some(frame => frame.event === 'pusher_internal:subscription_succeeded'); i++) await delay(100);
            assert.ok(frames.some(frame => frame.event === 'pusher_internal:subscription_succeeded'), 'Private Reverb subscription must succeed');
            await fill('textarea[name=body]', 'Student browser question <b>plain text</b>');
            assert.ok(process.env.QA_CHAT_FILE);
            const dom = await command('DOM.getDocument');
            const query = await command('DOM.querySelector', { nodeId: (dom.root as { nodeId: number }).nodeId, selector: 'input[type=file]' });
            await command('DOM.setFileInputFiles', { nodeId: query.nodeId, files: [process.env.QA_CHAT_FILE] });
            await delay(3500);
            const signalsBeforeSend = frames.filter(frame => frame.event === 'chat.changed').length;
            await click('Send message');
            await waitFor("document.querySelector('ol[aria-label=\"Conversation messages\"]')?.textContent.includes('Student browser question')");
            assert.equal(await evaluate("document.querySelector('ol[aria-label=\"Conversation messages\"] b') !== null"), false);
            const attachmentUrl = await evaluate("document.querySelector('main a[href*=\"/messages/attachments/\"]').getAttribute('href')") as string;
            assert.equal(await evaluate('fetch(' + JSON.stringify(attachmentUrl) + ').then(response => response.status)'), 200);
            for (let i = 0; i < 100 && frames.filter(frame => frame.event === 'chat.changed').length <= signalsBeforeSend; i++) await delay(100);
            const received = frames.filter(frame => frame.event === 'chat.changed');
            assert.ok(received.length > signalsBeforeSend, 'Committed chat changes must arrive over real Reverb');
            assert.deepEqual(JSON.parse(received[0].data), { changed: true }, 'WebSocket signals must omit private message/file content');
            await responsive('student-chat-thread');
            chatSocket.close(); chatSocket = undefined;
            await logout();
            await login('provider', 'Your provider account');
            await command('Page.navigate', { url: appUrl + '/messages' });
            await waitFor("document.querySelector('h1')?.textContent === 'Messages'");
            await responsive('provider-chat-inbox');
            await command('Page.navigate', { url: appUrl + threadUrl });
            await waitFor("document.querySelector('h1')?.textContent === 'Conversation'");
            assert.equal(await evaluate("document.querySelector('ol[aria-label=\"Conversation messages\"]').textContent.includes('Student browser question')"), true);
            assert.equal(await evaluate('fetch(' + JSON.stringify(attachmentUrl) + ').then(response => response.status)'), 200);
            await fixture('typing');
            await waitFor("document.body.textContent.includes('The other participant is typing')");
            await click('Mark as read');
            await fill('textarea[name=body]', 'Provider browser reply');
            await click('Send message');
            await waitFor("document.querySelector('ol[aria-label=\"Conversation messages\"]').textContent.includes('Provider browser reply')");
            await fixture('read');
            await waitFor("[...document.querySelectorAll('ol[aria-label=\"Conversation messages\"] li')].some(item => item.textContent.includes('Provider browser reply') && item.textContent.includes('Read'))");
            await responsive('provider-chat-thread');
            await logout();
            await login('waitlisted', 'Your student account');
            assert.equal(await evaluate('fetch(' + JSON.stringify(threadUrl + '/state') + ', { headers: { Accept: "application/json" } }).then(response => response.status)'), 403);
            assert.equal(await evaluate('fetch(' + JSON.stringify(attachmentUrl) + ').then(response => response.status)'), 403);
            assert.equal((await authChannel(socketId, channel)).status, 403, 'Outsiders cannot authorize the private Reverb channel');
            await logout();
            await login('provider', 'Your provider account');
            await command('Page.navigate', { url: appUrl + threadUrl });
            await waitFor("document.querySelector('h1')?.textContent === 'Conversation'");
            await fixture('revoke');
            await waitFor("document.body.textContent.includes('Conversation access changed')");
            assert.equal(await evaluate("document.querySelector('ol[aria-label=\"Conversation messages\"]') !== null"), false);
            assert.equal(await evaluate('fetch(' + JSON.stringify(attachmentUrl) + ').then(response => response.status)'), 403);
            await logout();
            console.log('PASS: private student/provider chat, real PDF transfer, plain-text rendering, typing expiry/state, read receipts, outsider/revoked-access denial, responsive layouts, and real local Reverb private subscription/event delivery with signal-only payloads.');
        } else if (process.env.QA_NOTIFICATIONS_CHECK === 'true') {
            const click = async (text: string, selector = 'button') => evaluate('[...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(item => item.textContent.trim() === ' + JSON.stringify(text) + ').click()');
            const responsive = async (name: string) => {
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    await delay(250);
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, name + ' must fit ' + width);
                    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
                    assert.equal(await evaluate("[...document.querySelectorAll('a[aria-label^=\"Notifications:\"]')].some(link => link.getBoundingClientRect().width >= 44)"), true);
                    await screenshot(name + '-' + width);
                }
            };
            const fixture = async (mode: string) => {
                assert.ok(process.env.QA_NOTIFICATION_DB?.includes('applicant-counts-'), 'Notifications require a disposable SQLite fixture');
                const php = spawn('php', [], { windowsHide: true, env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: process.env.QA_NOTIFICATION_DB, DB_URL: '', QUEUE_CONNECTION: 'database', MAIL_MAILER: 'array', BROADCAST_CONNECTION: 'null', QA_NOTICE_MODE: mode }, stdio: ['pipe', 'pipe', 'pipe'] });
                php.stdin.end(`<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || !str_contains(config('database.connections.sqlite.database'), 'applicant-counts-')) { throw new RuntimeException('Expected disposable SQLite.'); }
$application = App\\Models\\Application::whereHas('student', fn ($query) => $query->where('email', 'student@iskoreach.test'))->firstOrFail();
$provider = $application->scholarship->provider->user;
if (getenv('QA_NOTICE_MODE') === 'provider') {
    $provider->notifyNow(new App\\Notifications\\ApplicationActivity($application->id, 'submitted', 'submitted', $provider->id, 'provider'), ['database']);
} else {
    app(App\\Services\\ApplicationWorkflow::class)->review($provider, $application, ['version' => $application->workflow_version, 'status' => 'under_review', 'reason' => 'Private browser review explanation', 'confirmed' => true]);
}
echo 'Notification fixture ready.';
`);
                let error = ''; php.stderr.on('data', chunk => { error += chunk; });
                await new Promise<void>((resolve, reject) => { php.on('error', reject); php.on('exit', code => code === 0 ? resolve() : reject(new Error('Disposable notification fixture failed: ' + error))); });
            };
            const inbox = async () => { await command('Page.navigate', { url: appUrl + '/notifications' }); await waitFor("document.querySelector('h1')?.textContent === 'Notifications'"); };
            await login('provider', 'Your provider account');
            await inbox();
            assert.equal(await evaluate("document.body.textContent.includes('No matching notifications')"), true);
            await fixture('provider');
            await delay(16000);
            await waitFor("document.querySelector('a[aria-label=\"Notifications: 1 unread\"]') !== null");
            await click('Mark all as read');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await click('Cancel');
            await waitFor("document.querySelector('[role=dialog]') === null");
            await click('Mark all as read');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await evaluate("document.querySelector('[role=dialog] input[name=confirmed]').click()");
            await click('Confirm mark all read');
            await waitFor("document.querySelector('[role=dialog]') === null && document.querySelector('a[aria-label=\"Notifications: 0 unread\"]') !== null");
            await responsive('provider-notification-inbox');
            await click('Unread', 'nav[aria-label=\"Notification filters\"] a');
            await waitFor("document.body.textContent.includes('No matching notifications')");
            await click('Read', 'nav[aria-label=\"Notification filters\"] a');
            await waitFor("document.body.textContent.includes('New scholarship application')");
            await click('Open record', 'main a');
            await waitFor("document.querySelector('h1')?.textContent === 'Applicant review'");
            await fixture('student');
            await logout();
            await login('student', 'Your student account');
            await inbox();
            assert.equal(await evaluate("document.body.textContent.includes('Application status update')"), true);
            assert.equal(await evaluate("document.querySelector('main').textContent.includes('Private browser review explanation')"), false);
            await responsive('student-notification-inbox');
            await click('Mark as read');
            await waitFor("document.querySelector('a[aria-label=\"Notifications: 0 unread\"]') !== null");
            await logout();
            await login('admin', 'Dashboard');
            await inbox();
            assert.equal(await evaluate("document.body.textContent.includes('No matching notifications')"), true);
            await responsive('admin-notification-inbox');
            assert.equal(await evaluate("document.querySelector('header').getBoundingClientRect().height"), 64);
            await logout();
            console.log('PASS: live unread polling against an isolated database, same-page read-all reset, owner inboxes, filters, confirmation/cancel, record links, private previews, and responsive student/provider/admin notification layouts.');
        } else if (process.env.QA_PRIVATE_DOCUMENTS_CHECK === 'true') {
            const click = async (text: string, selector = 'button') => evaluate('[...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(item => item.textContent.trim() === ' + JSON.stringify(text) + ').click()');
            const navigate = async (path: string, title: string) => { await command('Page.navigate', { url: appUrl + path }); await waitFor('document.querySelector("h1")?.textContent === ' + JSON.stringify(title)); };
            const responsive = async (name: string) => {
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    await delay(250);
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, name + ' must fit ' + width);
                    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
                    await screenshot(name + '-' + width);
                }
            };
            await login('student', 'Your student account');
            await navigate('/student/documents', 'My document vault');
            await click('Upload to vault');
            await waitFor("document.querySelector('[role=alert]') !== null");
            await fill('input[name=label]', 'Browser Enrollment Record');
            const dom = await command('DOM.getDocument');
            const query = await command('DOM.querySelector', { nodeId: (dom.root as { nodeId: number }).nodeId, selector: 'input[type=file]' });
            assert.ok(process.env.QA_DOCUMENT_FIXTURE, 'Set a disposable PDF fixture path');
            await command('DOM.setFileInputFiles', { nodeId: query.nodeId, files: [process.env.QA_DOCUMENT_FIXTURE] });
            await click('Upload to vault');
            await waitFor("document.body.textContent.includes('Document uploaded to your private vault.')");
            await responsive('student-private-vault');
            const vaultDownload = await evaluate("document.querySelector('main a[href$=download]').getAttribute('href')") as string;
            assert.equal(await evaluate('fetch(' + JSON.stringify(vaultDownload) + ').then(response => response.status)'), 200);
            await navigate('/scholarships', 'Find a scholarship');
            assert.equal(await evaluate("document.querySelector('header nav a[aria-current=page]')?.getAttribute('href')"), '/scholarships');
            await fill('input[name=q]', 'No such scholarship fixture');
            await click('Apply filters');
            await waitFor("document.body.textContent.includes('No matching scholarships')");
            await responsive('student-discovery-empty');
            await click('Reset');
            await waitFor("document.body.textContent.includes('Phase Four Document Grant')");
            await fill('input[name=q]', 'Phase Four');
            await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
            await click('Apply filters');
            await waitFor("document.querySelector('[role=alert]')?.textContent.includes('offline')");
            assert.equal(await evaluate("document.querySelector('input[name=q]').value"), 'Phase Four');
            await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            await click('Apply filters');
            await waitFor("location.search.includes('Phase') && document.body.textContent.includes('Phase Four Document Grant')");
            await responsive('student-discovery');
            await click('Phase Four Document Grant', 'main a');
            await waitFor("document.querySelector('h1')?.textContent === 'Phase Four Document Grant'");
            assert.equal(await evaluate("document.querySelector('header nav a[aria-current=page]')?.getAttribute('href')"), '/scholarships');
            await responsive('student-program-details');
            await click('Prepare application');
            await waitFor("document.querySelector('h1')?.textContent === 'Application details'");
            const applicationUrl = await evaluate('location.pathname') as string;
            assert.equal(await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent === 'Update my school details')?.getAttribute('href')"), '/student/profile');
            assert.equal(await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Review and submit').disabled"), true);
            await evaluate("(() => { const input = document.querySelector('select[name=document_id]'); input.value = input.options[1].value; input.dispatchEvent(new Event('change', { bubbles: true })); })()");
            await evaluate("document.querySelector('section input[name=confirmed]').click()");
            await click('Attach and confirm');
            await waitFor("document.body.textContent.includes('Attached and confirmed')");
            await responsive('student-document-checklist');
            await click('Remove attachment');
            await evaluate("document.querySelector('section input[name=confirmed]').click()");
            await click('Confirm attachment removal');
            await waitFor("!document.body.textContent.includes('Attached and confirmed')");
            assert.equal(await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Review and submit').disabled"), true);
            await evaluate("(() => { const input = document.querySelector('select[name=document_id]'); input.value = input.options[1].value; input.dispatchEvent(new Event('change', { bubbles: true })); })()");
            await evaluate("document.querySelector('section input[name=confirmed]').click()");
            await click('Attach and confirm');
            await waitFor("document.body.textContent.includes('Attached and confirmed')");
            await click('Review and submit');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await evaluate("document.querySelector('[role=dialog] input[name=confirmed]').click()");
            await click('Confirm submission');
            await waitFor("document.querySelector('[role=dialog]') === null && document.body.textContent.includes('Submitted documents')");
            const attachmentDownload = await evaluate("document.querySelector('main a[href*=application-documents]').getAttribute('href')") as string;
            assert.equal(await evaluate('fetch(' + JSON.stringify(attachmentDownload) + ').then(response => response.headers.get("content-disposition").startsWith("attachment"))'), true);
            await responsive('student-submitted-documents');
            await navigate('/student/applications', 'My applications');
            await responsive('student-application-tracker');
            await evaluate("(() => { const select = document.querySelector('select[name=status]'); Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(select, 'rejected'); select.dispatchEvent(new Event('change', { bubbles: true })); })()");
            await click('Apply filter');
            await waitFor("location.search.includes('rejected') && document.body.textContent.includes('No matching applications')");
            await click('Reset');
            await waitFor("!location.search && document.body.textContent.includes('Phase Four Document Grant')");
            await navigate('/student/documents', 'My document vault');
            await click('Remove from vault');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await click('Cancel');
            await waitFor("document.querySelector('[role=dialog]') === null");
            await click('Remove from vault');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await evaluate("document.querySelector('[role=dialog] input[name=confirmed]').click()");
            await click('Confirm removal');
            await waitFor("document.querySelector('[role=dialog]') === null && document.body.textContent.includes('No matching documents')");
            assert.equal(await evaluate('fetch(' + JSON.stringify(attachmentDownload) + ').then(response => response.status)'), 200);
            await logout();
            await login('provider', 'Your provider account');
            await navigate(applicationUrl.replace('/student/', '/provider/'), 'Applicant review');
            assert.equal(await evaluate('fetch(' + JSON.stringify(attachmentDownload) + ').then(response => response.status)'), 200);
            assert.equal(await evaluate('fetch(' + JSON.stringify(vaultDownload) + ').then(response => response.status)'), 404);
            await responsive('provider-submitted-documents');
            await click('Under review');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await fill('textarea[name=reason]', 'Student submission reviewed in the isolated acceptance run.');
            await evaluate("document.querySelector('[role=dialog] input[name=confirmed]').click()");
            await click('Confirm decision');
            await waitFor("document.querySelector('[role=dialog]') === null && document.body.textContent.includes('Latest provider explanation')");
            await click('Open conversation');
            await waitFor("document.querySelector('h1')?.textContent === 'Conversation'");
            const conversationUrl = await evaluate('location.pathname') as string;
            await fill('textarea[name=body]', 'Provider question <b>shown as text</b>');
            const messageDom = await command('DOM.getDocument');
            const messageInput = await command('DOM.querySelector', { nodeId: (messageDom.root as { nodeId: number }).nodeId, selector: 'input[type=file]' });
            await command('DOM.setFileInputFiles', { nodeId: messageInput.nodeId, files: [process.env.QA_DOCUMENT_FIXTURE] });
            await click('Send message');
            await waitFor("document.querySelector('ol[aria-label=\"Conversation messages\"]')?.textContent.includes('Provider question')");
            await logout();
            await login('student', 'Your student account');
            await navigate('/notifications', 'Notifications');
            assert.equal(await evaluate("document.body.textContent.includes('Provider question')"), false);
            await responsive('student-notification-inbox');
            await click('Mark all as read');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await click('Cancel');
            await waitFor("document.querySelector('[role=dialog]') === null");
            await click('Mark all as read');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await evaluate("document.querySelector('[role=dialog] input[name=confirmed]').click()");
            await click('Confirm mark all read');
            await waitFor("document.querySelector('[role=dialog]') === null && !document.querySelector('nav[aria-label=\"Student navigation\"] a[href=\"/notifications\"] span.sr-only')");
            await navigate('/messages', 'Messages');
            await responsive('student-conversation-inbox');
            await click('Browser Scholarship Foundation', 'main a h2');
            await waitFor("document.querySelector('h1')?.textContent === 'Conversation'");
            assert.equal(await evaluate("document.querySelector('ol[aria-label=\"Conversation messages\"] b') !== null"), false);
            const chatDownload = await evaluate("document.querySelector('main a[href*=\"/messages/attachments/\"]').href") as string;
            assert.equal(await evaluate('fetch(' + JSON.stringify(chatDownload) + ').then(response => response.status)'), 200);
            await click('Mark as read');
            await waitFor("[...document.querySelectorAll('button')].find(item => item.textContent === 'Mark as read').disabled");
            await fill('textarea[name=body]', 'Student reply from the acceptance run.');
            await click('Send message');
            await waitFor("document.querySelector('ol[aria-label=\"Conversation messages\"]')?.textContent.includes('Student reply')");
            await responsive('student-message-thread');
            await navigate(applicationUrl, 'Application details');
            assert.equal(await evaluate("document.body.textContent.includes('Student submission reviewed in the isolated acceptance run.')"), true);
            await responsive('student-reviewed-application');
            await logout();
            await login('provider', 'Your provider account');
            await navigate(conversationUrl, 'Conversation');
            assert.equal(await evaluate("document.querySelector('ol[aria-label=\"Conversation messages\"]')?.textContent.includes('Student reply')"), true);
            await click('Mark as read');
            await waitFor("[...document.querySelectorAll('button')].find(item => item.textContent === 'Mark as read').disabled");
            await logout();
            assert.equal(await evaluate('fetch(' + JSON.stringify(attachmentDownload) + ').then(response => response.redirected && response.url.endsWith("/login"))'), true);
            console.log('PASS: student discovery/filter/offline recovery, application tracker/review, real PDF upload and retained private downloads, notification read-all/cancel, two-way chat/attachments/receipts, provider/guest restrictions, and responsive layouts at 320/768/1440px.');
        } else if (process.env.QA_APPLICATION_WORKFLOW_CHECK === 'true') {
            const click = async (text: string, selector = 'button') => evaluate('[...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(item => item.textContent.trim() === ' + JSON.stringify(text) + ').click()');
            const navigate = async (path: string, title: string) => { await command('Page.navigate', { url: appUrl + path }); await waitFor('document.querySelector("h1")?.textContent === ' + JSON.stringify(title)); };
            const responsive = async (name: string) => {
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    await delay(250);
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, name + ' must fit ' + width);
                    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
                    await screenshot(name + '-' + width);
                }
            };
            await login('student', 'Your student account');
            await navigate('/scholarships/phase-three-open', 'Phase Three Open Grant');
            await click('Prepare application');
            await waitFor("document.querySelector('h1')?.textContent === 'Application details'");
            const applicationUrl = await evaluate('location.pathname') as string;
            assert.equal(await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent === 'Update my school details')?.getAttribute('href')"), '/student/profile');
            await responsive('student-application-draft');
            await click('Review and submit');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            await click('Cancel');
            await waitFor("document.querySelector('[role=dialog]') === null");
            await click('Review and submit');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await evaluate("document.querySelector('input[name=confirmed]').click()");
            await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
            await waitFor('navigator.onLine === false');
            await click('Confirm submission');
            await waitFor("document.querySelector('[role=dialog]').textContent.includes('You are offline')");
            await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            await waitFor('navigator.onLine === true');
            await click('Confirm submission');
            await waitFor("document.querySelector('[role=dialog]') === null && ![...document.querySelectorAll('button')].some(button => button.textContent.trim() === 'Review and submit')");
            await responsive('student-application-submitted');
            await logout();
            await login('provider', 'Your provider account');
            await navigate('/provider/applications', 'Applicants');
            await responsive('provider-applications');
            const reviewUrl = applicationUrl.replace('/student/', '/provider/');
            await navigate(reviewUrl, 'Applicant review');
            assert.equal(await evaluate("document.body.textContent.includes('Student Browser Fixture')"), true);
            assert.equal(await evaluate("document.body.textContent.includes('student@iskoreach.test')"), false);
            await responsive('provider-application-review');
            const decide = async (status: string) => {
                await click(status);
                await waitFor("document.querySelector('[role=dialog]') !== null");
                await fill('textarea[name=reason]', 'Reviewed in the disposable browser workflow.');
                await evaluate("document.querySelector('input[name=confirmed]').click()");
                await click('Confirm decision');
                await waitFor("document.querySelector('[role=dialog]') === null");
            };
            await decide('Under review');
            await decide('Waitlisted');
            await decide('Accepted');
            await waitFor("document.body.textContent.includes('No further review actions')");
            await logout();
            await login('student', 'Your student account');
            await navigate('/student/applications', 'My applications');
            assert.equal(await evaluate("document.body.textContent.includes('Accepted') && document.body.textContent.includes('Phase Three Open Grant')"), true);
            await responsive('student-application-tracker');
            await navigate(applicationUrl, 'Application details');
            assert.equal(await evaluate("document.body.textContent.includes('Reviewed in the disposable browser workflow.')"), true);
            await responsive('student-application-decision');
            await navigate('/scholarships/phase-three-documents', 'Phase Three Documents Grant');
            await click('Prepare application');
            await waitFor("document.querySelector('h1')?.textContent === 'Application details'");
            assert.equal(await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Review and submit').disabled"), true);
            assert.equal(await evaluate("document.body.textContent.includes('missing or incompatible document attachment')"), true);
            await logout();
            await login('existing', 'Your student account');
            await navigate('/scholarships/phase-three-open', 'Phase Three Open Grant');
            await click('Prepare application');
            await waitFor("document.querySelector('h1')?.textContent === 'Application details'");
            assert.equal(await evaluate("document.body.textContent.includes('does not accept existing scholars')"), true);
            assert.equal(await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Review and submit').disabled"), true);
            await navigate('/scholarships/phase-three-existing', 'Phase Three Existing Scholars Grant');
            await click('Prepare application');
            await waitFor("document.querySelector('h1')?.textContent === 'Application details'");
            await click('Review and submit');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await evaluate("document.querySelector('input[name=confirmed]').click()");
            assert.equal(await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Confirm submission').disabled"), true);
            await evaluate("document.querySelector('input[name=conflict_confirmed]').click()");
            await click('Confirm submission');
            await waitFor("document.querySelector('[role=dialog]') === null && document.body.textContent.includes('Low priority at submission')");
            await screenshot('student-application-low-priority');
            await logout();
            console.log('PASS: student submission/tracker, profile sharing, offline feedback, provider review/history, final decisions, required-document blocking, and active-award conflict confirmations; responsive pages at 320, 768, and 1440px.');
        } else if (process.env.QA_SCHOLARSHIP_MANAGEMENT_CHECK === 'true') {
            const click = async (text: string, selector = 'button') => evaluate('[...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(item => item.textContent.trim() === ' + JSON.stringify(text) + ').click()');
            const setValue = async (selector: string, value: string) => {
                await evaluate('(() => { const input = document.querySelector(' + JSON.stringify(selector) + '); Object.getOwnPropertyDescriptor(input instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : input instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype, "value").set.call(input, ' + JSON.stringify(value) + '); input.dispatchEvent(new Event("input", { bubbles: true })); input.dispatchEvent(new Event("change", { bubbles: true })); })()');
            };
            const responsive = async (name: string) => {
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    await delay(250);
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, name + ' must fit ' + width);
                    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
                    await screenshot(name + '-' + width);
                }
            };
            await command('Page.navigate', { url: appUrl + '/scholarships' });
            await waitFor("document.querySelector('h1')?.textContent === 'Find a scholarship'");
            assert.equal(await evaluate("document.body.textContent.includes('No matching scholarships')"), true);
            await login('provider', 'Your provider account');
            await click('Create scholarship', 'main a');
            await waitFor("document.querySelector('h1')?.textContent === 'Create scholarship'");
            await click('Create draft');
            await waitFor("document.querySelector('[role=alert]') !== null");
            await setValue('input[name=title]', 'Browser Stage Two Scholarship');
            await setValue('textarea[name=description]', 'A disposable program for checking scholarship creation and public discovery.');
            await setValue('input[name=scholarship_type]', 'Academic');
            await setValue('input[name=benefit_amount]', '12000');
            await setValue('input[name=opens_at]', new Date(Date.now() - 86400000).toISOString().slice(0, 16));
            await setValue('input[name=deadline_at]', new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 16));
            await setValue('textarea[name=eligibility_criteria]', 'Enrolled student\nMeets the recorded academic criteria');
            await click('Add requirement');
            await setValue('section[aria-labelledby=requirements-title] input', 'Proof of enrollment');
            await responsive('provider-scholarship-create');
            await click('Create draft');
            await waitFor("document.querySelector('h1')?.textContent === 'Edit scholarship'");
            const editUrl = await evaluate('location.href') as string;
            await setValue('input[name=title]', 'Browser Stage Two Scholarship Updated');
            await click('Save scholarship details');
            await waitFor("[...document.querySelectorAll('main button')].some(button => button.textContent.trim() === 'Publish' && !button.disabled)");
            await click('Publish');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            await click('Cancel');
            await waitFor("document.querySelector('[role=dialog]') === null");
            const lifecycle = async (action: string) => {
                await click(action);
                await waitFor("document.querySelector('[role=dialog]') !== null");
                await setValue('input[name=reason]', 'Disposable browser workflow verification');
                await evaluate("document.querySelector('input[name=confirmed]').click()");
                await click('Confirm change');
                await waitFor("document.querySelector('[role=dialog]') === null");
                await waitFor("document.querySelector('h1')?.textContent === 'My scholarships'");
                if (action !== 'Remove and retain history') {
                    await command('Page.navigate', { url: editUrl });
                    await waitFor("document.querySelector('h1')?.textContent === 'Edit scholarship'");
                }
            };
            await lifecycle('Publish');
            await waitFor("document.querySelector('input[name=title]').matches(':disabled')");
            await responsive('provider-scholarship-published');
            await logout();
            await command('Page.navigate', { url: appUrl + '/scholarships' });
            await waitFor("document.body.textContent.includes('Browser Stage Two Scholarship Updated')");
            assert.equal(await evaluate("document.body.textContent.includes('Browser Count Grant') || document.body.textContent.includes('Private Waitlisted Fixture')"), false);
            await responsive('public-scholarships');
            await setValue('input[name=q]', 'missing program');
            await click('Apply filters');
            await waitFor("document.body.textContent.includes('No matching scholarships')");
            await click('Reset');
            await waitFor("document.body.textContent.includes('Browser Stage Two Scholarship Updated')");
            await click('View program details', 'main a');
            await waitFor("document.querySelector('h1')?.textContent === 'Browser Stage Two Scholarship Updated'");
            const publicUrl = await evaluate('location.href') as string;
            assert.equal(await evaluate("document.body.textContent.includes('0 total') && document.body.textContent.includes('Enrolled student')"), true);
            assert.equal(await evaluate("document.body.textContent.includes('Proof of enrollment')"), true);
            assert.equal(await evaluate("document.querySelector('main a[href=\"/login\"]')?.textContent"), 'Log in as a student to apply');
            await responsive('public-scholarship-details');
            await login('provider', 'Your provider account');
            await command('Page.navigate', { url: editUrl });
            await waitFor("document.querySelector('h1')?.textContent === 'Edit scholarship'");
            await lifecycle('Close');
            assert.equal(await evaluate('fetch(' + JSON.stringify(publicUrl) + ').then(response => response.status)'), 200);
            await lifecycle('Archive');
            assert.equal(await evaluate('fetch(' + JSON.stringify(publicUrl) + ').then(response => response.status)'), 404);
            await lifecycle('Remove and retain history');
            await waitFor("document.querySelector('h1')?.textContent === 'My scholarships'");
            await setValue('select[name=records]', 'retained');
            await click('Apply filters');
            await waitFor("document.body.textContent.includes('Retained history')");
            await responsive('provider-scholarships-retained');
            assert.equal(await evaluate("document.querySelector('#provider-sidebar [aria-current=page]')?.textContent"), 'My scholarships');
            await command('Page.navigate', { url: appUrl + '/provider/scholarships/1/edit' });
            await waitFor("document.querySelector('h1')?.textContent === 'Edit scholarship'");
            assert.equal(await evaluate("document.querySelector('input[name=title]').matches(':disabled')"), true, 'Existing application history locks content');
            await logout();
            console.log('PASS: provider draft creation, validation, editing, publication, public filters/details, lifecycle confirmations, content locks, and retained history at 320, 768, and 1440px.');
        } else if (process.env.QA_PROVIDER_STAGE1_CHECK === 'true') {
            const click = async (text: string, selector = 'button') => evaluate('[...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(item => item.textContent.trim() === ' + JSON.stringify(text) + ').click()');
            const responsive = async (name: string) => {
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
                    assert.equal(await evaluate("document.querySelector('header').getBoundingClientRect().height"), 64);
                    await screenshot(name + '-' + width);
                }
            };
            await login('provider', 'Your provider account');
            assert.equal(await evaluate("document.querySelector('#provider-sidebar [aria-current=page]')?.textContent"), 'Dashboard');
            assert.equal(await evaluate("document.body.textContent.includes('Other private scholarship') || document.body.textContent.includes('Other private notification') || document.body.textContent.includes('Private Waitlisted Fixture')"), false);
            assert.equal(await evaluate("document.querySelector('main a[href=\"/provider/scholarships/create\"]') !== null"), true);
            assert.equal(await evaluate("document.querySelectorAll('#provider-sidebar [aria-disabled=true]').length"), 0);
            await responsive('provider-dashboard-stage1');
            await evaluate("document.querySelector('button[aria-label=\"Collapse sidebar\"]').click()");
            await waitFor("getComputedStyle(document.getElementById('provider-sidebar')).width === '80px'");
            await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]').click()");
            await delay(250);
            await evaluate("document.querySelector('button[aria-label=\"Recent verification decisions\"]').click()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            assert.equal(await evaluate("document.querySelector('[role=dialog]').textContent.includes('Browser approval notice')"), true);
            assert.equal(await evaluate("(() => { const ids = [...document.querySelectorAll('[id]')].map(item => item.id); return ids.length === new Set(ids).size; })()"), true);
            await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await waitFor("document.querySelector('[role=dialog]') === null");
            await click('Edit organization profile', 'main a');
            await waitFor("document.querySelector('h1')?.textContent === 'Organization profile'");
            assert.equal(await evaluate("document.querySelector('#provider-sidebar [aria-current=page]')?.textContent"), 'Organization profile');
            await responsive('provider-profile-stage1');
            await evaluate("document.querySelector('input[name=organization_name]').select()");
            await command('Input.insertText', { text: 'Browser Updated Foundation' });
            await evaluate("document.querySelector('input[name=contact_email]').select()");
            await command('Input.insertText', { text: 'invalid-email' });
            await evaluate("document.querySelector('form[aria-label=\"Organization profile\"]').requestSubmit()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await click('Save and request review');
            await waitFor("document.querySelector('[role=dialog]') === null && document.querySelector('input[name=contact_email]').getAttribute('aria-invalid') === 'true'");
            assert.equal(await evaluate("document.querySelector('input[name=organization_name]').value"), 'Browser Updated Foundation');
            await evaluate("document.querySelector('input[name=contact_email]').select()");
            await command('Input.insertText', { text: 'updated-provider@iskoreach.test' });
            await evaluate("document.querySelector('form[aria-label=\"Organization profile\"]').requestSubmit()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await click('Cancel');
            await waitFor("document.querySelector('[role=dialog]') === null");
            await evaluate("document.querySelector('form[aria-label=\"Organization profile\"]').requestSubmit()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
            await click('Save and request review');
            await waitFor("[...document.querySelectorAll('[role=alert]')].some(item => item.textContent.includes('offline'))");
            await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            await click('Save and request review');
            await waitFor("document.querySelector('h1')?.textContent === 'Organization review pending'");
            assert.equal(await evaluate("document.querySelector('input[name=organization_name]').value"), 'Browser Updated Foundation');
            assert.equal(await evaluate("fetch('/provider/workspace').then(response => response.status)"), 403);
            assert.equal(await evaluate("fetch('/provider/applicant-counts').then(response => response.status)"), 403);
            await command('Page.navigate', { url: appUrl + '/provider/profile' });
            await waitFor("document.querySelector('h1')?.textContent === 'Organization profile'");
            await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 844, deviceScaleFactor: 1, mobile: true });
            await evaluate("document.querySelector('button[aria-label=\"Open provider navigation\"]').click()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog] a[href$=\"/provider/applicant-counts\"]') === null"), true);
            await screenshot('provider-pending-mobile-navigation');
            await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await waitFor("document.querySelector('[role=dialog]') === null");
            assert.equal(await evaluate("document.activeElement.getAttribute('aria-label')"), 'Open provider navigation');
            await logout();
            await login('admin', 'Dashboard');
            assert.equal(await evaluate("document.querySelector('header').getBoundingClientRect().height"), 64);
            assert.equal(await evaluate("document.getElementById('admin-sidebar') !== null && document.getElementById('provider-sidebar') === null"), true);
            console.log('PASS: Provider Stage 1 layout/dashboard/profile, scoped activity, disabled future actions, responsive typography, notifications, animation, validation preservation, confirmation/cancel, offline feedback, and re-verification restrictions.');
        } else if (process.env.QA_SIDEBAR_MOTION_CHECK === 'true') {
            let sidebarStyle: unknown;
            for (const [role, heading, id] of [['admin', 'Dashboard', 'admin-sidebar'], ['student', 'Your student account', 'student-sidebar'], ['provider', 'Your provider account', 'provider-sidebar']]) {
                await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
                await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
                await login(role, heading);
                if (await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]') !== null")) {
                    await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]').click()");
                    await delay(250);
                }
                const style = await evaluate('(() => { const sidebar = document.getElementById(' + JSON.stringify(id) + '); const link = sidebar.querySelector("a[aria-current=page]"); const icon = link.querySelector("svg"); return { background: getComputedStyle(sidebar).backgroundColor, image: getComputedStyle(sidebar).backgroundImage, width: sidebar.getBoundingClientRect().width, active: getComputedStyle(link).backgroundColor, color: getComputedStyle(link).color, icon: getComputedStyle(icon).color, font: getComputedStyle(link).fontSize, height: link.getBoundingClientRect().height }; })()');
                if (sidebarStyle) assert.deepEqual(style, sidebarStyle, role + ' sidebar matches the shared navigation design');
                sidebarStyle = style;
                const state = await evaluate('(async () => { const sidebar = document.getElementById(' + JSON.stringify(id) + '); const content = sidebar.nextElementSibling; getComputedStyle(sidebar).translate; document.querySelector(\'button[aria-label="Collapse sidebar"]\').click(); await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))); const animations = [...sidebar.getAnimations(), ...content.getAnimations()]; animations.forEach(animation => { animation.pause(); animation.currentTime = 100; }); const result = { animations: animations.length, x: sidebar.getBoundingClientRect().x, width: sidebar.getBoundingClientRect().width, padding: parseFloat(getComputedStyle(content).paddingLeft), inert: sidebar.inert }; animations.forEach(animation => animation.finish()); return result; })()') as { animations: number; x: number; width: number; padding: number; inert: boolean };
                assert.ok(state.animations >= 2 && state.x === 0 && state.width > 80 && state.width < 240, role + ' sidebar narrows between endpoints');
                assert.ok(state.padding > 80 && state.padding < 240, role + ' content shifts with sidebar');
                assert.equal(state.inert, false, 'Collapsed icon navigation stays in keyboard order');
                await waitFor('getComputedStyle(document.getElementById(' + JSON.stringify(id) + ')).width === "80px"');
                await screenshot(role + '-motion-collapsed');
                await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]').click()");
                await delay(250);
                assert.equal(await evaluate('document.getElementById(' + JSON.stringify(id) + ').inert'), false);
                await screenshot(role + '-motion-expanded');
                await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
                assert.equal(await evaluate('getComputedStyle(document.getElementById(' + JSON.stringify(id) + ')).transitionProperty'), 'none');
                await evaluate("document.querySelector('button[aria-label=\"Collapse sidebar\"]').click()");
                await waitFor('getComputedStyle(document.getElementById(' + JSON.stringify(id) + ')).width === "80px"');
                assert.equal(await evaluate('document.getElementById(' + JSON.stringify(id) + ').getAnimations().length'), 0);
                await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 844, deviceScaleFactor: 1, mobile: true });
                const menuLabel = role === 'admin' ? 'Open admin navigation' : role === 'provider' ? 'Open provider navigation' : 'Open student navigation';
                await evaluate('document.querySelector(' + JSON.stringify('button[aria-label="' + menuLabel + '"]') + ').click()');
                await waitFor("document.querySelector('[role=dialog] nav') !== null");
                assert.equal(await evaluate("getComputedStyle(document.querySelector('[role=dialog] nav').parentElement).backgroundColor"), 'rgb(16, 43, 60)');
                await screenshot(role + '-mobile-navigation');
                await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
                await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
                await waitFor("document.querySelector('[role=dialog]') === null");
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
                await logout();
            }
            console.log('PASS: admin, student, and provider sidebars animate with content, hide keyboard targets, respect reduced motion, and keep mobile navigation usable.');
        } else if (process.env.QA_ADMIN_LAYOUT_CHECK === 'true') {
            await login('admin', 'Dashboard');
            const pages = [
                ['dashboard', 'Dashboard'], ['providers', 'Provider reviews'], ['users', 'User management'],
                ['scholarships', 'Scholarship monitoring'], ['applicant-counts', 'Applicant counts'],
                ['awards', 'Awards and passers'], ['renewals', 'Renewal monitoring'], ['releases', 'Benefit releases'],
                ['reports', 'Reports'], ['activity-logs', 'Activity logs'],
            ];
            for (const [path, title] of pages) {
                await command('Page.navigate', { url: appUrl + '/admin/' + path });
                await waitFor('document.querySelector(\'h1\')?.textContent === ' + JSON.stringify(title));
                for (const width of [320, 768, 1024, 1440, 1920]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, path + ' fits ' + width);
                    assert.equal(await evaluate("document.querySelector('header').getBoundingClientRect().height"), 64, 'Header stays compact');
                    if (width >= 1024) {
                        assert.equal(await evaluate("document.getElementById('admin-sidebar').getBoundingClientRect().width"), 240);
                        assert.equal(await evaluate("[...document.querySelectorAll('#admin-sidebar nav a')].every(link => link.getBoundingClientRect().height >= 44 && link.scrollWidth <= link.clientWidth)"), true, 'Sidebar labels fit with comfortable targets');
                    }
                    if (path === 'dashboard' || path === 'scholarships') await screenshot('admin-compact-' + path + '-' + width);
                }
            }
            await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 450, deviceScaleFactor: 1, mobile: false });
            assert.equal(await evaluate("getComputedStyle(document.getElementById('admin-sidebar')).scrollbarWidth"), 'none');
            assert.equal(await evaluate("(() => { const sidebar = document.getElementById('admin-sidebar'); sidebar.scrollTop = sidebar.scrollHeight; return sidebar.scrollTop > 0; })()"), true);
            await evaluate("document.querySelector('button[aria-label=\"Collapse sidebar\"]').focus()");
            await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', text: '\r', unmodifiedText: '\r', windowsVirtualKeyCode: 13 });
            await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
            await waitFor("getComputedStyle(document.getElementById('admin-sidebar')).width === '80px'");
            await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]').click()");
            await waitFor("getComputedStyle(document.getElementById('admin-sidebar')).visibility === 'visible'");
            await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 844, deviceScaleFactor: 1, mobile: true });
            await evaluate("document.querySelector('button[aria-label=\"Open admin navigation\"]').click()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            assert.equal(await evaluate("document.querySelector('[role=dialog]').scrollWidth <= innerWidth"), true);
            await screenshot('admin-compact-mobile-navigation');
            await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await waitFor("document.querySelector('[role=dialog]') === null");
            assert.equal(await evaluate("document.activeElement.getAttribute('aria-label')"), 'Open admin navigation');
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.includes('Account menu')).click()");
            await waitFor("document.querySelector('[role=menu]') !== null");
            assert.equal(await evaluate("(() => { const rect = document.querySelector('[role=menu]').getBoundingClientRect(); return rect.left >= 0 && rect.right <= innerWidth; })()"), true);
            await command('Page.navigate', { url: appUrl + '/admin/reports?type=applications' });
            await waitFor("document.querySelector('h1')?.textContent === 'Reports'");
            await waitFor("document.querySelector('select[name=type]')?.value === 'applications'");
            assert.equal(await evaluate("document.body.textContent.includes('Browser Count Grant')"), true);
            const csv = await evaluate("fetch(document.querySelector('a[download]').href).then(async response => ({ status: response.status, type: response.headers.get('content-type'), text: await response.text() }))") as { status: number; type: string; text: string };
            assert.equal(csv.status, 200); assert.equal(csv.type, 'text/csv; charset=UTF-8');
            assert.ok(csv.text.includes('Browser Count Grant') && !csv.text.includes('student@iskoreach.test'));
            for (const type of ['registrations', 'providers', 'scholarships', 'locations', 'active_awards', 'renewals', 'releases', 'external_counts']) {
                await command('Page.navigate', { url: appUrl + '/admin/reports?type=' + type });
                await waitFor('document.querySelector(\'select[name=type]\')?.value === ' + JSON.stringify(type));
                assert.equal(await evaluate("document.querySelector('header').getBoundingClientRect().height"), 64);
            }
            await command('Page.navigate', { url: appUrl + '/admin/activity-logs' });
            await waitFor("document.querySelector('h1')?.textContent === 'Activity logs'");
            assert.equal(await evaluate("document.body.textContent.includes('Student Browser Fixture') || document.body.textContent.includes('student@iskoreach.test')"), false);
            await evaluate("(() => { const select = document.querySelector('select[name=action]'); select.value = 'award.publish'; select.dispatchEvent(new Event('change', {bubbles:true})); })()");
            await evaluate("document.querySelector('form[aria-label=\"Activity log filters\"]').requestSubmit()");
            await waitFor("location.search.includes('action=award.publish') && document.body.textContent.includes('1 matching event.')");
            for (const path of ['reports', 'activity-logs']) {
                await command('Page.navigate', { url: appUrl + '/admin/' + path });
                await waitFor('document.querySelector(\'h1\')?.textContent === ' + JSON.stringify(path === 'reports' ? 'Reports' : 'Activity logs'));
                for (const width of [320, 768, 1440]) {
                    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
                    await screenshot('stage7-' + path + '-' + width);
                }
            }
            console.log('PASS: all ten admin pages fit 320–1920px; 64px header, 240px sidebar, readable labels, 44px links, scrolling, hamburger, focus, all report types, CSV download, and activity filters.');
        } else if (process.env.QA_AWARD_STAGE6_CHECK === 'true') {
            const click = async (label: string, selector = 'button') => evaluate('[...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(item => item.textContent.trim() === ' + JSON.stringify(label) + ').click()');
            const viewport = async (width: number, height = 1000) => command('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 768 });
            const open = async (path: string, heading: string) => {
                await command('Page.navigate', { url: appUrl + path });
                await waitFor('document.querySelector(\'h1\')?.textContent === ' + JSON.stringify(heading));
            };
            const responsive = async (name: string) => {
                for (const width of [320, 768, 1440]) {
                    await viewport(width);
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, name + ' must fit ' + width);
                    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
                    await screenshot(name + '-' + width);
                }
            };
            await open('/passers', 'Scholarship passers');
            assert.equal(await evaluate("document.body.textContent.includes('No public results yet')"), true);
            await responsive('public-passers-empty');
            await login('student', 'Your student account');
            await open('/student/awards', 'My awards');
            await responsive('student-awards-private');
            await click('Choose public listing');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            await evaluate("document.querySelector('input[name=confirmed]').click(); document.querySelector('[role=dialog] form').requestSubmit()");
            await waitFor("document.querySelector('[role=dialog]') === null");
            await waitFor("document.body.textContent.includes('Your consent is recorded.')");
            await logout();
            await viewport(1440);
            await login('admin', 'Dashboard');
            await open('/admin/awards', 'Awards and passers');
            assert.equal(await evaluate("document.body.textContent.includes('Student Browser Fixture') || document.body.textContent.includes('student@iskoreach.test')"), false, 'Oversight must not reveal student identity');
            await viewport(1440, 450);
            assert.equal(await evaluate("getComputedStyle(document.getElementById('admin-sidebar')).scrollbarWidth"), 'none');
            assert.equal(await evaluate("getComputedStyle(document.getElementById('admin-sidebar'), '::-webkit-scrollbar').display"), 'none');
            assert.equal(await evaluate("(() => { const sidebar = document.getElementById('admin-sidebar'); sidebar.scrollTop = 150; return sidebar.scrollTop > 0; })()"), true, 'Hidden scrollbar must preserve scrolling');
            assert.equal(await evaluate("document.querySelector('button[aria-label=\"Collapse sidebar\"] svg').classList.contains('lucide-menu')"), true, 'Sidebar uses hamburger');
            await evaluate("document.querySelector('button[aria-label=\"Collapse sidebar\"]').click()");
            await waitFor("getComputedStyle(document.getElementById('admin-sidebar')).width === '80px'");
            await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]').click()");
            await responsive('admin-awards');
            await click('Review listing');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await fill('input[name=reason]', 'Confirmed active award and student consent.');
            await evaluate("document.querySelector('input[name=confirmed]').click()");
            await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
            await evaluate("document.querySelector('[role=dialog] form').requestSubmit()");
            await waitFor("document.querySelector('[role=alert]')?.textContent.includes('offline')");
            await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            await evaluate("document.querySelector('[role=dialog] form').requestSubmit()");
            await waitFor("document.querySelector('[role=dialog]') === null");
            await waitFor("document.body.textContent.includes('Publicly listed')");
            await open('/admin/renewals', 'Renewal monitoring');
            assert.equal(await evaluate("document.body.textContent.includes('Private renewal notes')"), false);
            await responsive('admin-renewals');
            await open('/admin/releases', 'Benefit releases');
            assert.equal(await evaluate("document.body.textContent.includes('5000.25')"), true);
            assert.equal(await evaluate("document.body.textContent.includes('Private release notes')"), false);
            await responsive('admin-releases');
            await logout();
            await open('/passers', 'Scholarship passers');
            await waitFor("document.body.textContent.includes('Student Browser Fixture')");
            assert.equal(await evaluate("document.body.textContent.includes('student@iskoreach.test')"), false);
            await responsive('public-passers-published');
            await login('student', 'Your student account');
            await open('/student/awards', 'My awards');
            await click('Withdraw public listing consent');
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await evaluate("document.querySelector('input[name=confirmed]').click(); document.querySelector('[role=dialog] form').requestSubmit()");
            await waitFor("document.querySelector('[role=dialog]') === null");
            await logout();
            await open('/passers', 'Scholarship passers');
            await waitFor("document.body.textContent.includes('No public results yet')");
            console.log('PASS: Stage 6 consent, publication, offline feedback, withdrawal, admin privacy, monitoring, responsive typography, hamburger, and scrolling with hidden sidebar scrollbars.');
        } else if (process.env.QA_APPLICANT_COUNTS_CHECK === 'true') {
            const click = async (label: string, selector = 'button') => evaluate('[...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(item => item.textContent.trim() === ' + JSON.stringify(label) + ').click()');
            const preview = () => evaluate("[...document.querySelectorAll('p')].find(item => item.textContent === 'Public-count preview')?.nextElementSibling.textContent");
            const desktop = () => command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
            const collapse = async (role: string, id: string, heading: string) => {
                await desktop();
                await evaluate("document.querySelector('button[aria-label=\"Collapse sidebar\"]').focus()");
                await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', text: '\r', unmodifiedText: '\r', windowsVirtualKeyCode: 13 });
                await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
                await waitFor('getComputedStyle(document.getElementById(' + JSON.stringify(id) + ')).width === "80px"');
                await screenshot(role + '-sidebar-collapsed');
                await evaluate('window.__sidebarReloadMarker = true');
                await command('Page.reload');
                await waitFor('window.__sidebarReloadMarker !== true && document.querySelector(\'h1\')?.textContent === ' + JSON.stringify(heading));
                assert.equal(await evaluate('getComputedStyle(document.getElementById(' + JSON.stringify(id) + ')).width'), '80px');
                await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]').click()");
                await waitFor('getComputedStyle(document.getElementById(' + JSON.stringify(id) + ')).visibility === "visible"');
                await screenshot(role + '-sidebar-expanded');
            };
            const openCounts = async (role: string) => {
                await click('Applicant counts', 'a'); await waitFor("document.querySelector('h1')?.textContent === 'Applicant counts'");
                await click('Browser Count Grant', 'main a');
                await waitFor('document.querySelector(\'h1\')?.textContent === ' + JSON.stringify(role === 'admin' ? 'Applicant count details' : 'Report external applicants'));
            };
            const submitReport = async (count: string) => {
                await fill('input[name=reported_count]', count); await fill('input[name=source_label]', 'Manual browser fixture register');
                await evaluate("(() => { const input = document.querySelector('input[name=source_as_of]'); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Manila'}).format(new Date())); input.dispatchEvent(new Event('input', {bubbles:true})); input.dispatchEvent(new Event('change', {bubbles:true})); })()");
                await evaluate("document.querySelector('input[name=duplicates_excluded]').click(); document.querySelector('form').requestSubmit()");
                await waitFor("document.body.textContent.includes('Report received.')");
                await waitFor("document.querySelector('input[name=reported_count]')?.value === ''");
            };
            await login('admin', 'Dashboard'); await collapse('admin', 'admin-sidebar', 'Dashboard'); await logout();
            await login('provider', 'Your provider account'); await collapse('provider', 'provider-sidebar', 'Your provider account'); await openCounts('provider');
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true); await screenshot('provider-count-form-' + width);
            }
            await submitReport('10'); assert.equal(await preview(), '2'); await logout();
            await login('admin', 'Dashboard'); await openCounts('admin');
            assert.equal(await evaluate("document.body.textContent.includes('Private Waitlisted Fixture') || document.body.textContent.includes('student@iskoreach.test')"), false);
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true); await screenshot('admin-count-details-' + width);
            }
            await click('Verify report'); await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            await evaluate("document.querySelector('input[name=duplicates_checked]').click(); document.querySelector('[role=dialog] form').requestSubmit()");
            await waitFor("document.querySelector('[role=dialog]') === null"); assert.equal(await preview(), '12'); await logout();
            await login('provider', 'Your provider account'); await openCounts('provider'); await submitReport('15'); assert.equal(await preview(), '12'); await logout();
            await login('admin', 'Dashboard'); await openCounts('admin'); await click('Reject report');
            await waitFor("document.querySelector('textarea[name=reason]') !== null");
            assert.equal(await evaluate("document.querySelector('textarea[name=reason]').required"), true);
            await fill('textarea[name=reason]', 'The source has not excluded all internal applicants.');
            await evaluate("document.querySelector('[role=dialog] form').requestSubmit()"); await waitFor("document.querySelector('[role=dialog]') === null"); assert.equal(await preview(), '12');
            await click('Record correction'); await waitFor("document.querySelector('input[name=corrected_count]') !== null");
            await evaluate("document.querySelector('input[name=corrected_count]').select()"); await command('Input.insertText', { text: '7' });
            await fill('textarea[name=reason]', 'Reconciled the register and removed duplicates.');
            await evaluate("document.querySelector('input[name=duplicates_checked]').click(); document.querySelector('[role=dialog] form').requestSubmit()");
            await waitFor("document.querySelector('[role=dialog]') === null"); assert.equal(await preview(), '9');
            assert.equal(await evaluate("document.body.textContent.includes('Reported: 10') && document.body.textContent.includes('Reported: 15') && document.body.textContent.includes('Corrected: 7')"), true);
            await screenshot('admin-count-correction-history'); await logout();
            await login('student', 'Your student account'); await collapse('student', 'account-navigation', 'Your student account');
            await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 844, deviceScaleFactor: 1, mobile: true });
            await evaluate("document.querySelector('button[aria-controls=account-navigation]').click()");
            await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await waitFor("document.querySelector('button[aria-controls=account-navigation]').getAttribute('aria-expanded') === 'false'");
            assert.equal(await evaluate("document.activeElement === document.querySelector('button[aria-controls=account-navigation]')"), true); await logout();
            console.log('PASS: all three sidebars collapse by keyboard, persist after reload, and reopen; mobile Escape restores focus; manual reporting, verified-only totals, rejection, corrections, retained history, responsive layouts, and student privacy.');
        } else if (process.env.QA_SCHOLARSHIP_READONLY_CHECK === 'true') {
            await login('admin', 'Dashboard');
            await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent.includes('View current and retained records')).click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Scholarship monitoring'");
            await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent === 'Browser Civic Education Grant').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Scholarship details'");
            assert.equal(await evaluate("document.body.textContent.includes('Minimum grade')"), true, 'Criteria use readable labels');
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
                await screenshot('scholarship-final-details-' + width);
            }
            await logout();
            console.log('PASS: final scholarship details render readable criteria at 320/768/1440px.');
        } else {
        if (process.env.QA_SCHOLARSHIP_MONITORING_CHECK === 'true') {
            await login('admin', 'Dashboard');
            await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent.includes('View current and retained records')).click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Scholarship monitoring'");
            await waitFor("document.body.textContent.includes('3 matching records.')");
            for (const width of [320, 375, 768, 1024, 1440, 1920]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Scholarship monitoring fits ' + width);
                assert.equal(await evaluate("[...document.querySelectorAll('main input, main select')].every(input => input.labels.length > 0)"), true, 'Scholarship filters have labels');
                await screenshot('scholarship-monitoring-' + width);
            }
            await fill('input[name=q]', 'Missing scholarship fixture');
            await evaluate("document.querySelector('form[aria-label=\"Search and filter scholarships\"]').requestSubmit()");
            await waitFor("document.body.textContent.includes('No matching scholarship records')");
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Reset filters').click()");
            await waitFor("document.querySelector('input[name=q]').value === ''");
            await evaluate("document.querySelector('select[name=status]').value = 'draft'; document.querySelector('select[name=status]').dispatchEvent(new Event('change', {bubbles:true}))");
            await fill('input[name=location]', 'nationwide');
            await evaluate("document.querySelector('form[aria-label=\"Search and filter scholarships\"]').requestSubmit()");
            await waitFor("document.body.textContent.includes('1 matching record.')");
            await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent === 'Browser Civic Education Grant').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Scholarship details'");
            assert.equal(await evaluate("document.body.textContent.includes('Certificate of enrollment')"), true);
            assert.equal(await evaluate("document.body.textContent.includes('View applicant count breakdown')"), true, 'Scholarship counts lead to the verified breakdown');
            assert.equal(await evaluate("document.querySelector('a[aria-current=page]')?.textContent.includes('Scholarship monitoring')"), true);
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Scholarship details fit ' + width);
                await screenshot('scholarship-details-' + width);
            }
            await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent.includes('Back to scholarship monitoring')).click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Scholarship monitoring'");
            await evaluate("document.querySelector('select[name=records]').value = 'retained'; document.querySelector('select[name=records]').dispatchEvent(new Event('change', {bubbles:true})); document.querySelector('form[aria-label=\"Search and filter scholarships\"]').requestSubmit()");
            await waitFor("document.body.textContent.includes('1 matching record.')");
            await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent === 'Browser Retained Grant').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Scholarship details'");
            assert.equal(await evaluate("document.body.textContent.includes('Retained record: removed on')"), true);
            await screenshot('scholarship-retained-details');
            await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 844, deviceScaleFactor: 1, mobile: true });
            await logout();
            console.log('PASS: scholarship monitoring fits 320–1920px; labelled filters, search/reset, status/location filtering, record details, unavailable applicant counts, retained records, and active navigation.');
        }
        if (process.env.QA_USER_MANAGEMENT_CHECK === 'true') {
            await login('admin', 'Dashboard');
            await evaluate("document.querySelector('main a[href*=\"/admin/users?role=student\"]').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'User management'");
            assert.equal(await evaluate("document.querySelector('select[name=role]').value"), 'student');
            await waitFor("document.body.textContent.includes('1 matching account.')");
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Reset').click()");
            await waitFor("document.querySelector('select[name=role]').value === ''");
            await waitFor("document.body.textContent.includes('2 matching accounts.')");
            for (const width of [320, 375, 768, 1024, 1440, 1920]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'User management fits ' + width);
                assert.equal(await evaluate("[...document.querySelectorAll('main input, main select')].every(input => input.labels.length > 0)"), true, 'Account filter controls have labels');
                await screenshot('user-management-' + width);
            }
            await fill('input[name=q]', 'No matching account fixture');
            await evaluate("document.querySelector('form[aria-label=\"Search and filter accounts\"]').requestSubmit()");
            await waitFor("document.body.textContent.includes('No matching accounts')");
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Reset filters').click()");
            await waitFor("document.querySelector('input[name=q]').value === ''");
            await fill('input[name=q]', 'student@iskoreach.test');
            await evaluate("document.querySelector('form[aria-label=\"Search and filter accounts\"]').requestSubmit()");
            await waitFor("document.body.textContent.includes('1 matching account.')");
            await evaluate("[...document.querySelectorAll('main tr')].find(row => row.textContent.includes('student@iskoreach.test')).querySelector('a[aria-label^=\"View account\"]').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Account details'");
            assert.equal(await evaluate("document.body.textContent.includes('No recorded account activity')"), true);
            assert.equal(await evaluate("document.querySelector('a[aria-current=page]')?.textContent.includes('User management')"), true);
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Account details fit ' + width);
                await screenshot('student-account-details-' + width);
            }
            await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent.includes('Back to user management')).click()");
            await waitFor("document.querySelector('h1')?.textContent === 'User management'");
            await evaluate("[...document.querySelectorAll('main tr')].find(row => row.textContent.includes('provider@iskoreach.test')).querySelector('a[aria-label^=\"View account\"]').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Account details'");
            await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent.includes('Open provider verification')).click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Provider verification'");
            await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 844, deviceScaleFactor: 1, mobile: true });
            await evaluate("document.querySelector('button[aria-label=\"Open admin navigation\"]').click()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            await evaluate("[...document.querySelectorAll('[role=dialog] a')].find(link => link.textContent.includes('User management')).click()");
            await waitFor("document.querySelector('h1')?.textContent === 'User management'");
            await waitFor("document.querySelector('[role=dialog]') === null");
            await logout();
            console.log('PASS: account list fits 320–1920px; student metric, labelled filters, search/reset, empty states, scoped details, active navigation, mobile sidebar, and provider verification link.');
        }
        if (process.env.QA_ADMIN_FOUNDATION_CHECK === 'true') {
            await login('admin', 'Dashboard');
            assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
            for (const width of [320, 375, 768, 1024, 1440, 1920]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true, 'Admin dashboard fits ' + width);
                assert.equal(await evaluate("document.querySelectorAll('main a[href*=\"/admin/users?role=student\"]').length"), 1, 'Student metric leads to the student account list');
                await screenshot('admin-foundation-' + width);
            }
            await evaluate("document.querySelector('button[aria-label^=\"Pending work:\"]').click()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await waitFor("document.querySelector('[role=dialog]') === null");
            await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 844, deviceScaleFactor: 1, mobile: true });
            await evaluate("document.querySelector('button[aria-label=\"Open admin navigation\"]').click()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            await evaluate("[...document.querySelectorAll('[role=dialog] a')].find(link => link.textContent.includes('Provider verification')).click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Provider reviews'");
            await waitFor("document.querySelector('[role=dialog]') === null");
            assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true, 'Provider queue fits 320px');
            await screenshot('admin-provider-queue-mobile');
            await logout();
            console.log('PASS: admin dashboard fits 320–1920px; shared typography, pending-work dialog, focus/Escape, mobile sidebar, provider queue, and account-menu logout.');
        }
        if (process.env.QA_AUTH_STAGE1_CHECK === 'true') {
            for (const width of [375, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                for (const [path, name, heading] of [
                    ['/login', 'login', 'Log in'],
                    ['/register', 'register-student', 'Create your account'],
                    ['/register?account=provider', 'register-provider', 'Create your account'],
                    ['/forgot-password', 'forgot', 'Forgot password?'],
                    ['/reset-password/invalid-token?email=student%40iskoreach.test', 'reset', 'Reset your password'],
                ]) {
                    await command('Page.navigate', { url: appUrl + path });
                    await waitFor('document.querySelector(\'h1\')?.textContent === ' + JSON.stringify(heading));
                    await evaluate('document.fonts.ready.then(() => true)');
                    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
                    assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true, name + ' fits ' + width);
                    assert.equal(await evaluate("[...document.querySelectorAll('input:not([type=hidden])')].every(input => input.labels.length > 0)"), true);
                    if (path.includes('account=provider')) assert.equal(await evaluate("document.querySelector('input[value=provider]').checked"), true);
                    await screenshot('auth-' + name + '-' + width);
                }
            }
            await command('Emulation.setDeviceMetricsOverride', { width: 375, height: 844, deviceScaleFactor: 1, mobile: true });
            await command('Page.navigate', { url: appUrl + '/login' });
            await waitFor("document.querySelector('input[name=password]') !== null");
            await fill('input[name=password]', 'visibility-check');
            await evaluate("document.querySelector('button[aria-label=\"Show password\"]').click()");
            assert.equal(await evaluate("document.querySelector('input[name=password]').type"), 'text');
            await evaluate("document.querySelector('button[aria-label=\"Hide password\"]').click()");
            assert.equal(await evaluate("document.querySelector('input[name=password]').type"), 'password');
            await evaluate("document.querySelector('button[aria-label=\"Open navigation menu\"]').focus(); document.querySelector('button[aria-label=\"Open navigation menu\"]').click()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await waitFor("document.querySelector('[role=dialog]') === null");
            await waitFor("document.activeElement?.getAttribute('aria-label') === 'Open navigation menu'");
            await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('You’re offline')");
            await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            await screenshot('auth-offline-feedback');
            await command('Page.navigate', { url: appUrl + '/login' });
            await waitFor("document.querySelector('input[name=email]') !== null");
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.querySelector('input[name=email]').getAttribute('aria-invalid') === 'true'");
            await screenshot('auth-login-validation');
            await command('Page.navigate', { url: appUrl + '/register' });
            await waitFor("document.querySelector('input[name=name]') !== null");
            await fill('input[name=name]', 'Duplicate Browser Fixture');
            await fill('input[name=email]', 'student@iskoreach.test');
            await fill('input[name=password]', 'temporary-test-password');
            await fill('input[name=password_confirmation]', 'temporary-test-password');
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.querySelector('input[name=email]').getAttribute('aria-invalid') === 'true'");
            assert.equal(await evaluate("document.querySelector('input[name=name]').value"), 'Duplicate Browser Fixture');
            await screenshot('auth-duplicate-email');
            await command('Page.navigate', { url: appUrl + '/forgot-password' });
            await waitFor("document.querySelector('input[name=email]') !== null");
            await fill('input[name=email]', 'missing-browser-fixture@iskoreach.test');
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.querySelector('h1')?.textContent === 'Check your email'");
            await screenshot('auth-reset-confirmation');
            await command('Page.navigate', { url: appUrl + '/reset-password/invalid-token?email=student%40iskoreach.test' });
            await waitFor("document.querySelector('input[name=password_confirmation]') !== null");
            await fill('input[name=password]', 'replacement-password');
            await fill('input[name=password_confirmation]', 'replacement-password');
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.body.textContent.includes('We couldn’t reset your password')");
            await screenshot('auth-invalid-reset-token');
            for (const kind of ['student', 'provider']) {
                await command('Page.navigate', { url: appUrl + '/register?account=' + kind });
                await waitFor("document.querySelector('input[name=name]') !== null");
                await fill('input[name=name]', 'Stage One ' + kind + ' Browser Fixture');
                await fill('input[name=email]', kind === 'student' ? 'stage1-learner@iskoreach.test' : 'stage1-organization@iskoreach.test');
                if (kind === 'provider') await fill('input[name=organization_name]', 'Stage One Registration Foundation');
                await fill('input[name=password]', 'temporary-test-password');
                await fill('input[name=password_confirmation]', 'temporary-test-password');
                await evaluate("document.querySelector('form').requestSubmit()");
                await waitFor('document.querySelector(\'h1\')?.textContent === ' + JSON.stringify(kind === 'student' ? 'Your student account' : 'Organization review pending'));
                await screenshot('auth-registration-success-' + kind);
                await logout();
            }
            console.log('PASS: auth pages at 375/768/1440px; font and labels; password visibility; keyboard-dismissed public drawer/focus return; offline feedback; backend validation; duplicate email; safe reset confirmation; invalid reset token.');
        }
        await login('student', 'Your student account');
        await evaluate("document.querySelector('button[aria-controls=account-navigation]').click()");
        assert.equal(await evaluate("document.querySelector('button[aria-controls=account-navigation]').getAttribute('aria-expanded')"), 'true');
        await screenshot('student-mobile');
        assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true);
        await logout();
        await login('provider', 'Organization review pending');
        await waitFor("document.body.textContent.includes('Pending approval')");
        await screenshot('provider-pending-mobile');
        for (const width of [375, 768, 1440]) {
            await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
            assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true, 'Provider status fits ' + width);
            await screenshot('provider-pending-' + width);
        }
        await logout();
        if (process.env.QA_AUTH_STAGE1_CHECK === 'true') {
            await login('rejected', 'Organization not approved');
            await waitFor("document.body.textContent.includes('Please correct your registered organization name.')");
            await screenshot('provider-rejected');
            await evaluate("document.querySelector('input[name=organization_name]').focus(); document.querySelector('input[name=organization_name]').select()");
            await command('Input.insertText', { text: 'Corrected Browser Organization' });
            await evaluate("document.querySelector('form').requestSubmit()");
            await waitFor("document.querySelector('h1')?.textContent === 'Organization review pending'");
            await screenshot('provider-corrected');
            await logout();
        }
        await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
        await login('admin', 'Dashboard');
        await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent.includes('Review provider accounts')).click()");
        await waitFor("document.querySelector('h1')?.textContent === 'Provider reviews'");
        await screenshot('admin-desktop');
        if (process.env.QA_PROVIDER_STAGE2_CHECK === 'true') {
            await fill('input[name=q]', 'No matching organization fixture');
            await evaluate("document.querySelector('form[aria-label=\"Search and filter providers\"]').requestSubmit()");
            await waitFor("document.body.textContent.includes('No matching provider accounts')");
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Reset filters').click()");
            await waitFor("document.querySelector('input[name=q]')?.value === ''");
            await fill('input[name=q]', 'provider@iskoreach.test');
            await evaluate("document.querySelector('select[name=status]').value = 'pending'; document.querySelector('select[name=status]').dispatchEvent(new Event('change', {bubbles:true}))");
            await evaluate("document.querySelector('form[aria-label=\"Search and filter providers\"]').requestSubmit()");
            await waitFor("document.body.textContent.includes('1 matching account.')");
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Provider search fits ' + width);
                await screenshot('provider-search-' + width);
            }
        }
        await evaluate("[...document.querySelectorAll('main tr')].find(row => row.textContent.includes('provider@iskoreach.test')).querySelector('a[aria-label^=\"View review\"]').click()");
        await waitFor("document.querySelector('h1')?.textContent === 'Provider verification'");
        if (process.env.QA_PROVIDER_STAGE2_CHECK === 'true') {
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Provider details fit ' + width);
                await screenshot('provider-details-' + width);
            }
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Approve provider').click()");
            await waitFor("document.querySelector('[role=dialog]') !== null");
            assert.equal(await evaluate("document.querySelector('[role=dialog]').contains(document.activeElement)"), true);
            await evaluate("[...document.querySelectorAll('[role=dialog] button')].find(button => button.textContent === 'Cancel').click()");
            await waitFor("document.querySelector('[role=dialog]') === null");
            assert.equal(await evaluate("document.body.textContent.includes('No verification history yet')"), true, 'Cancel does not save a decision');
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Reject provider').click()");
            await waitFor("document.querySelector('textarea[name=rejection_reason]') !== null");
            assert.equal(await evaluate("document.querySelector('textarea[name=rejection_reason]').validity.valueMissing"), true);
            await fill('textarea[name=rejection_reason]', 'Please verify the registered organization identity.');
            await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
            await waitFor('navigator.onLine === false');
            await evaluate("document.querySelector('[role=dialog] form').requestSubmit()");
            await waitFor("document.querySelector('[role=dialog]').textContent.includes('You are offline')");
            await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
            await waitFor('navigator.onLine === true');
            await evaluate("document.querySelector('[role=dialog] form').requestSubmit()");
            await waitFor("document.querySelector('[role=dialog]') === null");
            await waitFor("document.body.textContent.includes('Please verify the registered organization identity.')");
            await screenshot('provider-review-rejected');
            await logout();
            await login('provider', 'Organization not approved');
            assert.equal(await evaluate("document.querySelector('#verification-notices-title')?.textContent"), 'Recent account decisions');
            await screenshot('provider-decision-notification');
            await logout();
            await login('admin', 'Dashboard');
            await evaluate("[...document.querySelectorAll('main a')].find(link => link.textContent.includes('Review provider accounts')).click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Provider reviews'");
            await evaluate("[...document.querySelectorAll('main tr')].find(row => row.textContent.includes('provider@iskoreach.test')).querySelector('a[aria-label^=\"View review\"]').click()");
            await waitFor("document.querySelector('h1')?.textContent === 'Provider verification'");
        }
        await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Approve provider').click()");
        await waitFor("document.querySelector('[role=dialog]') !== null");
        await evaluate("[...document.querySelectorAll('[role=dialog] button')].find(button => button.textContent.trim() === 'Confirm approval').click()");
        await waitFor("document.querySelector('[role=dialog]') === null");
        await waitFor("document.body.textContent.includes('Provider decision saved.')");
        if (process.env.QA_PROVIDER_STAGE2_CHECK === 'true') {
            assert.equal(await evaluate("document.body.textContent.includes('Please verify the registered organization identity.')"), true, 'Prior rejection reason remains in history after approval');
            await screenshot('provider-review-approved-history');
            console.log('PASS: provider search/status filters, empty/reset state, responsive details, confirmation/cancel, required reason, offline feedback, rejection notification, approval, and retained review history.');
        }
        await logout();
        await login('provider', 'Your provider account');
        await waitFor("document.querySelector('a[href$=\"/provider/workspace\"]') !== null");
        await screenshot('provider-approved-desktop');
        await evaluate("document.querySelector('a[href$=\"/provider/workspace\"]').click()");
        await waitFor("document.querySelector('h1')?.textContent === 'Provider workspace'");
        assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontSize"), '28px');
        console.log('PASS: isolated student/provider/admin sessions, mobile navigation, pending provider display, administrator approval, and approved workspace navigation.');
    }
    }
    if (process.env.QA_PUBLIC_EMPTY_CHECK === 'true') {
        for (const [path, title, empty] of [['/', 'Find scholarships.Build your future.', 'No published scholarships yet'], ['/scholarships', 'Find a scholarship', 'No matching scholarships'], ['/passers', 'Scholarship passers', 'No public results yet']]) {
            await command('Page.navigate', { url: appUrl + path });
            await waitFor('document.querySelector("h1")?.textContent === ' + JSON.stringify(title));
            assert.equal(await evaluate('document.body.textContent.includes(' + JSON.stringify(empty) + ')'), true);
            for (const width of [320, 768, 1440]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
            }
        }
        console.log('PASS: empty home, scholarship directory, and public passers show honest empty states at 320, 768, and 1440px.');
    }
    assert.deepEqual(errors, [], 'No uncaught browser errors');
    assert.equal(webSocketRequests, 0, 'Reverb must stay disabled by default');
    console.log('PASS: Stitch font loaded; landing fits 320–1920px; live discovery form, honest directory content, and mobile drawer work; authentication navigation and 16px form text preserved; no runtime errors; Reverb stays optional.');
} finally {
    chatSocket?.close();
    socket?.close();
    browser.kill();
    // Chrome may hold profile files briefly; the OS temporary directory owns this isolated profile.
}

