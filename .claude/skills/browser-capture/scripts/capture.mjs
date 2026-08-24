#!/usr/bin/env node
/**
 * Screenshot the WordPress admin, deterministically.
 *
 * Logs in once, reuses the session, and writes numbered PNGs to docs/screenshots.
 * Determinism is the point: a screenshot that shifts between runs is useless for
 * comparing before and after, so the viewport is fixed, animations are disabled
 * and the admin bar's live clock is hidden.
 *
 * Usage:
 *   node capture.mjs                              # default set
 *   node capture.mjs --shot rates=/wp/wp-admin/admin.php?page=cc-rates
 *   node capture.mjs --list
 */

import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const argv = process.argv.slice(2);

const readFlag = (name, fallback) => {
	const index = argv.indexOf(`--${name}`);
	return index !== -1 && argv[index + 1] ? argv[index + 1] : fallback;
};

const baseUrl = readFlag('url', process.env.WP_HOME || 'http://localhost:8080').replace(/\/$/, '');
const user = readFlag('user', process.env.WP_ADMIN_USER || 'admin');
const password = readFlag('password', process.env.WP_ADMIN_PASSWORD || 'admin');
const outDir = readFlag('out', 'docs/screenshots');
const width = Number(readFlag('width', '1440'));
const height = Number(readFlag('height', '900'));

// Bedrock does not use stock paths: core is served from /wp, so login and admin
// live under it. A tool that assumes /wp-login.php gets a 404 and reports it as
// "the site is down".
const LOGIN_PATH = '/wp/wp-login.php';

const DEFAULT_SHOTS = [
	{ name: 'dashboard', path: '/wp/wp-admin/' },
	{ name: 'themes', path: '/wp/wp-admin/themes.php' },
	{ name: 'plugins', path: '/wp/wp-admin/plugins.php' },
	{ name: 'front-page', path: '/', anonymous: true },
];

const custom = argv
	.filter((arg, index) => argv[index - 1] === '--shot')
	.map((pair) => {
		const [name, target] = pair.split('=');
		return { name, path: target };
	});

const shots = custom.length > 0 ? custom : DEFAULT_SHOTS;

if (argv.includes('--list')) {
	for (const shot of shots) {
		console.log(`${shot.name}\t${shot.path}${shot.anonymous ? '\t(logged out)' : ''}`);
	}
	process.exit(0);
}

// Freezes anything that would otherwise differ between two runs of the same page.
const STABILISE_CSS = `
	*, *::before, *::after {
		animation: none !important;
		transition: none !important;
		caret-color: transparent !important;
	}
	#wp-admin-bar-wp-logo, .wp-heartbeat-active { visibility: hidden !important; }
`;

async function login(page) {
	await page.goto(`${baseUrl}${LOGIN_PATH}`, { waitUntil: 'domcontentloaded' });

	if (!(await page.locator('#loginform').count())) {
		throw new Error(`no login form at ${baseUrl}${LOGIN_PATH} — is the stack up?`);
	}

	await page.fill('#user_login', user);
	await page.fill('#user_pass', password);
	await Promise.all([
		page.waitForLoadState('networkidle'),
		page.click('#wp-submit'),
	]);

	if (page.url().includes('wp-login.php')) {
		throw new Error(`login failed for "${user}" — still on the login page`);
	}
}

async function capture(context, shot, index) {
	const page = await context.newPage();
	const target = shot.path.startsWith('http') ? shot.path : `${baseUrl}${shot.path}`;

	await page.goto(target, { waitUntil: 'networkidle' });
	await page.addStyleTag({ content: STABILISE_CSS });

	const file = path.join(outDir, `${String(index).padStart(2, '0')}-${shot.name}.png`);
	await page.screenshot({ path: file, fullPage: true });
	await page.close();

	console.log(`wrote ${file}`);
	return file;
}

const browser = await chromium.launch();
let failed = 0;

try {
	await mkdir(outDir, { recursive: true });

	const context = await browser.newContext({
		viewport: { width, height },
		deviceScaleFactor: 2,
		reducedMotion: 'reduce',
	});

	const anonymous = shots.filter((shot) => shot.anonymous);
	const authenticated = shots.filter((shot) => !shot.anonymous);

	if (authenticated.length > 0) {
		const page = await context.newPage();
		await login(page);
		await page.close();
	}

	let index = 1;
	for (const shot of [...authenticated, ...anonymous]) {
		try {
			await capture(context, shot, index++);
		} catch (error) {
			console.error(`FAILED ${shot.name}: ${error.message}`);
			failed++;
		}
	}
} catch (error) {
	console.error(error.message);
	failed++;
} finally {
	await browser.close();
}

process.exit(failed > 0 ? 1 : 0);
