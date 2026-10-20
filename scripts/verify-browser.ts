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
        throw new Error(`Timed out: ${expression}`);
    };
    await command('Runtime.enable');
    await command('Network.enable');
    await command('Page.enable');
    await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
    await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await command('Page.navigate', { url: appUrl });
    await waitFor("document.querySelector('h1')?.textContent.includes('Your Next')");
    await waitFor("getComputedStyle(document.querySelector('h1')).fontSize === '48px'");
    await evaluate('document.fonts.ready.then(() => true)');
    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily.includes('Plus Jakarta Sans')"), true);
    assert.equal(await evaluate("[...document.fonts].some(font => font.family.includes('Plus Jakarta Sans') && font.status === 'loaded')"), true, 'Stitch font must actually load');
    assert.equal(await evaluate("getComputedStyle(document.querySelector('main figure')).animationName"), 'landing-reveal', 'Hero entrance animation must be available');
    await evaluate("document.querySelector('#how-it-works').scrollIntoView()");
    await waitFor("getComputedStyle(document.querySelector('#how-it-works')).animationName === 'landing-reveal'");
    await delay(750);
    assert.equal(await evaluate("getComputedStyle(document.querySelector('#how-it-works')).opacity"), '1', 'Revealed content must finish fully visible');
    await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    await waitFor("getComputedStyle(document.querySelector('main figure')).animationName === 'none'");
    assert.equal(await evaluate("getComputedStyle(document.querySelector('#how-it-works')).animationName"), 'none', 'Reduced motion must disable scroll reveals');
    await evaluate('window.scrollTo(0, 0)');
    await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
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
    await evaluate("document.querySelector('[role=dialog] a[href=\"/#how-it-works\"]').click()");
    await waitFor("document.querySelector('[role=dialog]') === null");
    await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
    await waitFor('navigator.onLine === false');
    await evaluate("document.querySelector('form[aria-label=\"Find scholarships\"]').requestSubmit()");
    await waitFor("document.body.textContent.includes('You are offline')");
    await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    await waitFor('navigator.onLine === true');
    await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await evaluate('window.__inertiaNavigationCheck = true');
    await evaluate("document.querySelector('a[href$=\"/login\"]').click()");
    await waitFor("document.querySelector('h1')?.textContent === 'Log in'");
    assert.equal(await evaluate('window.__inertiaNavigationCheck'), true, 'Inertia navigation should preserve the page context');
    assert.equal(await evaluate("document.querySelectorAll('input[type=password]').length"), 1);
    assert.equal(await evaluate("getComputedStyle(document.querySelector('input[type=email]')).fontSize"), '16px');
    assert.equal(await evaluate("getComputedStyle(document.querySelector('h1')).fontSize"), '30px');
    if (screenshotDirectory) {
        await mkdir(screenshotDirectory, { recursive: true });
        const capture = await command('Page.captureScreenshot', { format: 'png' });
        await writeFile(join(screenshotDirectory, 'login-desktop.png'), Buffer.from(capture.data as string, 'base64'));
    }
    await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true, 'Mobile page should not overflow');
    await evaluate("document.querySelector('a[href$=\"/register\"]').click()");
    await waitFor("document.querySelector('h1')?.textContent === 'Create your account'");
    await evaluate("document.querySelector('input[value=provider]').click()");
    await waitFor("document.querySelector('input[name=organization_name]') !== null");
    assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true, 'Provider registration should fit mobile width');
    assert.equal(await evaluate("[...document.querySelectorAll('input:not([type=hidden])')].every(input => input.labels.length > 0)"), true, 'Each registration field needs a label');
    if (screenshotDirectory) {
        const capture = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        await writeFile(join(screenshotDirectory, 'register-mobile.png'), Buffer.from(capture.data as string, 'base64'));
    }
    if (process.env.QA_INPUT_VALIDATION_CHECK === 'true' || process.env.QA_ACADEMIC_TERMS_CHECK === 'true' || process.env.QA_PUBLIC_SITE_CHECK === 'true' || process.env.QA_LOGIN_REDIRECT_CHECK === 'true' || process.env.QA_STUDENT_STAGE1_CHECK === 'true' || process.env.QA_LOCATION_CHECK === 'true' || process.env.QA_HIDDEN_SCROLLBARS_CHECK === 'true' || process.env.QA_PROVIDER_UX_CHECK === 'true' || process.env.QA_BENEFITS_CHECK === 'true' || process.env.QA_RENEWALS_CHECK === 'true' || process.env.QA_CHAT_CHECK === 'true' || process.env.QA_NOTIFICATIONS_CHECK === 'true' || process.env.QA_PRIVATE_DOCUMENTS_CHECK === 'true' || process.env.QA_APPLICATION_WORKFLOW_CHECK === 'true' || process.env.QA_SCHOLARSHIP_MANAGEMENT_CHECK === 'true' || process.env.QA_PROVIDER_STAGE2_CHECK === 'true' || process.env.QA_AUTH_CHECK === 'true' || process.env.QA_SCHOLARSHIP_READONLY_CHECK === 'true' || process.env.QA_APPLICANT_COUNTS_CHECK === 'true' || process.env.QA_AWARD_STAGE6_CHECK === 'true' || process.env.QA_ADMIN_LAYOUT_CHECK === 'true' || process.env.QA_SIDEBAR_MOTION_CHECK === 'true' || process.env.QA_PROVIDER_STAGE1_CHECK === 'true') {
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
        const logout = async () => {
            if (await evaluate("document.querySelector('header a[href=\"/dashboard\"]') !== null")) {
                await evaluate("document.querySelector('header a[href=\"/dashboard\"]').click()");
                await waitFor("[...document.querySelectorAll('button')].some(button => button.textContent.includes('Account menu'))");
            }
            if (!await evaluate("[...document.querySelectorAll('button')].some(button => button.textContent.trim() === 'Log out')")) {
                await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.includes('Account menu')).click()");
                await waitFor("[...document.querySelectorAll('button')].some(button => button.textContent.trim() === 'Log out')");
            }
            await evaluate("[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Log out').click()");
            await waitFor("document.querySelector('h1')?.textContent.includes('Your Next')");
        };
        if (process.env.QA_INPUT_VALIDATION_CHECK === 'true') {
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
            const publicNavigation = [['Home', '/'], ['Find Scholarships', '/scholarships'], ['How It Works', '/#how-it-works'], ['For Providers', '/#for-providers'], ['About Us', '/#about'], ['Passers', '/passers']];
            const checkPublicChrome = async () => {
                assert.deepEqual(await evaluate("[...document.querySelectorAll('header nav a')].map(link => [link.textContent, link.getAttribute('href')])"), publicNavigation);
                assert.equal(await evaluate("document.querySelectorAll('footer h2').length"), 3);
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
            await go('/', 'Your Next Opportunity Starts Here.');
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
            await evaluate("document.querySelector('header nav a[href=\"/#how-it-works\"]').click()");
            await waitFor("document.querySelector('#how-it-works') !== null && location.hash === '#how-it-works'");
            await waitFor("document.querySelector('header nav a[aria-current=page]')?.getAttribute('href') === '/#how-it-works'");
            for (const hash of ['#for-providers', '#about']) {
                await evaluate('document.querySelector(' + JSON.stringify('header nav a[href="/' + hash + '"]') + ').click()');
                await waitFor('location.hash === ' + JSON.stringify(hash));
                await waitFor('document.querySelector("header nav a[aria-current=page]")?.getAttribute("href") === ' + JSON.stringify('/' + hash));
                assert.equal(await evaluate('document.querySelector(' + JSON.stringify(hash) + ') !== null'), true);
            }
            await evaluate("document.querySelector('header nav a[href=\"/passers\"]').click()");
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
                await go('/', 'Your Next Opportunity Starts Here.');
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
            await waitFor("getComputedStyle(document.getElementById('student-sidebar')).visibility === 'hidden'");
            await evaluate('window.__studentStage1Reload = true');
            await command('Page.reload');
            await waitFor("window.__studentStage1Reload !== true && document.querySelector('h1')?.textContent === 'My awards' && getComputedStyle(document.getElementById('student-sidebar')).visibility === 'hidden'");
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
            await waitFor("getComputedStyle(document.getElementById('provider-sidebar')).visibility === 'hidden'");
            await evaluate('window.__providerUxReload = true');
            await command('Page.reload');
            await waitFor("window.__providerUxReload !== true && document.querySelector('h1')?.textContent === 'Provider workspace' && getComputedStyle(document.getElementById('provider-sidebar')).visibility === 'hidden'");
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
            await click('Mark received messages read');
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
            await click('Mark received messages read');
            await waitFor("[...document.querySelectorAll('button')].find(item => item.textContent === 'Mark received messages read').disabled");
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
            await click('Mark received messages read');
            await waitFor("[...document.querySelectorAll('button')].find(item => item.textContent === 'Mark received messages read').disabled");
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
            await waitFor("getComputedStyle(document.getElementById('provider-sidebar')).visibility === 'hidden'");
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
                assert.ok(state.animations >= 2 && state.x < 0 && state.x > -state.width, role + ' sidebar slides between endpoints');
                assert.ok(state.padding > 0 && state.padding < state.width, role + ' content shifts with sidebar');
                assert.equal(state.inert, true, 'Closing navigation immediately leaves keyboard order');
                await waitFor('getComputedStyle(document.getElementById(' + JSON.stringify(id) + ')).visibility === "hidden"');
                await screenshot(role + '-motion-collapsed');
                await evaluate("document.querySelector('button[aria-label=\"Expand sidebar\"]').click()");
                await delay(250);
                assert.equal(await evaluate('document.getElementById(' + JSON.stringify(id) + ').inert'), false);
                await screenshot(role + '-motion-expanded');
                await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
                assert.equal(await evaluate('getComputedStyle(document.getElementById(' + JSON.stringify(id) + ')).transitionProperty'), 'none');
                await evaluate("document.querySelector('button[aria-label=\"Collapse sidebar\"]').click()");
                await waitFor('getComputedStyle(document.getElementById(' + JSON.stringify(id) + ')).visibility === "hidden"');
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
            await waitFor("getComputedStyle(document.getElementById('admin-sidebar')).visibility === 'hidden'");
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
            await waitFor("getComputedStyle(document.getElementById('admin-sidebar')).visibility === 'hidden'");
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
                await waitFor('getComputedStyle(document.getElementById(' + JSON.stringify(id) + ')).visibility === "hidden"');
                await screenshot(role + '-sidebar-collapsed');
                await evaluate('window.__sidebarReloadMarker = true');
                await command('Page.reload');
                await waitFor('window.__sidebarReloadMarker !== true && document.querySelector(\'h1\')?.textContent === ' + JSON.stringify(heading));
                assert.equal(await evaluate('getComputedStyle(document.getElementById(' + JSON.stringify(id) + ')).visibility'), 'hidden');
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
        for (const [path, title, empty] of [['/', 'Your Next Opportunity Starts Here.', 'No published scholarships yet'], ['/scholarships', 'Find a scholarship', 'No matching scholarships'], ['/passers', 'Scholarship passers', 'No public results yet']]) {
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

