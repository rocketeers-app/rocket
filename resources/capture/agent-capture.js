#!/usr/bin/env node
// agent-capture: records what a session changed, against the running copy of the application.
// It reads a storyboard (JSON) and writes one screenshot per scene plus a walkthrough video with
// an intro card, a caption per scene and a closing summary card into $AGENT_MEDIA_DIR, next to a
// manifest.json the platform reads once the session ends. The cards, captions and highlights are
// drawn in the look of the application itself — its colours, fonts, logo and name, read from the
// running copy — so the walkthrough reads as part of that product. With --screenshots-only it
// takes the screenshots alone, without pauses or a video, so a storyboard is quick to get right.
// Written into the runner image by BuildAgentRunnerImage; not meant to be edited on the server.
// `rocket capture` runs this same script on a developer's machine: it points AGENT_BASE_URL at the
// local site, AGENT_PLAYWRIGHT at its own Playwright and AGENT_MEDIA_DIR at a local directory. The
// copy in packages/rocket/resources/capture is kept identical to this one by a test.

'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync, spawnSync } = require('child_process');
const playwright = () =>
    require(
        process.env.AGENT_PLAYWRIGHT ||
            '/usr/local/lib/node_modules/playwright',
    );

const OUTPUT = process.env.AGENT_MEDIA_DIR || '/agent-media';
const PORT = process.env.AGENT_APP_PORT || '8080';
const BASE_URL = (
    process.env.AGENT_BASE_URL || `http://127.0.0.1:${PORT}`
).replace(/\/+$/, '');
const BASE_HOST = BASE_URL.replace(/^https?:\/\//, '').replace(
    /[.*+?^${}()|[\]\\]/g,
    '\\$&',
);
const COMMAND = process.env.AGENT_CAPTURE_COMMAND || 'agent-capture';
const MAX_SCENES = 12;
const STEP_PAUSE_MS = 700;
const ACTION_TIMEOUT_MS = 8000;
const NETWORK_IDLE_MS = 1500;
const SCREENSHOT_SCALE = 2;
const FRAME_RATE = 25;
const ZOOM_MS = 800;
const ZOOM_MAX = 2.5;
const FONT_TIMEOUT_MS = 3000;
const FONTS_CSS =
    process.env.AGENT_CAPTURE_FONTS ||
    '/usr/local/share/agent-capture/fonts.css';

const FONTS = (() => {
    try {
        return fs.readFileSync(FONTS_CSS, 'utf8');
    } catch {
        return '';
    }
})();

const FALLBACK_FONT =
    "'Inter', 'Liberation Sans', 'DejaVu Sans', system-ui, sans-serif";
const WHITE = [255, 255, 255];
const BLACK = [0, 0, 0];
const CREDIT = 'Recorded by Rocketeers';
const MAGNIFIER =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M11 8v6M8 11h6"/></svg>';

const EXAMPLE = {
    title: 'Export invoices as CSV',
    intro: 'The invoices overview gets an Export button that downloads the filtered list as a CSV file.',
    viewport: { width: 1440, height: 900 },
    setup: [
        { goto: '/login' },
        { fill: ['input[name=email]', 'test@example.com'] },
        { fill: ['input[name=password]', 'password'] },
        { click: 'button[type=submit]' },
        { waitFor: 'text=Dashboard' },
    ],
    scenes: [
        {
            title: 'The new Export button',
            caption:
                'The invoices overview now has an Export button in its header.',
            goto: '/invoices',
            highlight: "button:has-text('Export')",
            zoom: true,
        },
        {
            title: 'Exporting a filtered list',
            caption:
                'Filtering first and then exporting downloads only the invoices that are shown.',
            goto: '/invoices',
            steps: [
                { fill: ['input[name=search]', 'Acme'] },
                { press: 'Enter' },
                { click: "button:has-text('Export')" },
            ],
            highlight: 'table',
            hold: 5,
        },
    ],
    summary:
        'Invoices can be exported as CSV, respecting the active filters. Worth checking: the column order in the file.',
};

const USAGE = `Usage: agent-capture <storyboard.json>
       agent-capture --screenshots-only <storyboard.json>
                                 takes the screenshots alone, without pauses or a video
       agent-capture --example   prints an example storyboard

A storyboard is JSON with:
  title      short name of the change (required)
  intro      one or two sentences shown on the opening card
  summary    one or two sentences shown on the closing card
  viewport   { width, height }, default 1440x900
  setup      steps run before recording starts, e.g. logging in (not in the video)
  scenes     1-${MAX_SCENES} scenes, each { title, caption, goto, steps, highlight, zoom, hold }
  theme      { name, logo, accent, background, text, font, heading, radius }, all optional

The cards, captions and highlights take on the look of the app, read from its first scene; set
any of theme to override what was read (colours as CSS colours, logo as a path in the app, logo
or name false to leave it out). The cards are written to cards/ to look at.
zoom: true zooms the video in on the scene's highlight once its screenshot is taken, and back out
after its hold; a selector zooms in on that instead. The video only: screenshots stay unzoomed.

Steps: { goto: "/path" } { click: sel } { fill: [sel, value] } { select: [sel, value] }
       { press: "Enter" | [sel, key] } { hover: sel } { scroll: sel | pixels }
       { wait: ms } { waitFor: sel }
Paths are relative to ${BASE_URL}. Output goes to ${OUTPUT}.`;

const escapeHtml = (value) =>
    String(value ?? '').replace(
        /[&<>"']/g,
        (char) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[char],
    );

const slug = (value) =>
    String(value || 'scene')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 40) || 'scene';

const readingSeconds = (text, minimum) =>
    Math.min(
        10,
        Math.max(
            minimum,
            Math.ceil(String(text || '').split(/\s+/).length / 3),
        ),
    );

const urlFor = (target) =>
    /^https?:\/\//.test(target)
        ? target
        : BASE_URL + (target.startsWith('/') ? target : `/${target}`);

const pathOf = (url) =>
    url.replace(new RegExp('^https?://' + BASE_HOST), '') || '/';

const secondsOf = (value) => Math.round(value * 10) / 10;

const hex = (rgb) =>
    '#' +
    rgb
        .map((value) => Math.round(value).toString(16).padStart(2, '0'))
        .join('');

const alpha = ([r, g, b], amount) => `rgba(${r}, ${g}, ${b}, ${amount})`;

const mix = (from, to, amount) =>
    from.map((value, index) =>
        Math.round(value + (to[index] - value) * amount),
    );

const luminance = (rgb) => {
    const [r, g, b] = rgb.map((value) => {
        const channel = value / 255;
        return channel <= 0.03928
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

const contrast = (a, b) => {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (light + 0.05) / (dark + 0.05);
};

const readable = (color, against, ratio) => {
    const toward = luminance(against) < 0.4 ? WHITE : BLACK;
    let result = color;

    for (let step = 0; step < 12 && contrast(result, against) < ratio; step++)
        result = mix(result, toward, 0.15);

    return result;
};

const cssValue = (value) => String(value).replace(/[{}<>;\\]/g, '');

// What the app looks like, read from a page of it: the CSS variables a design system names its
// primary colour in, else the colour most of its buttons, links or coloured areas share; the
// fonts of its text and headings, the rounding of its buttons, the logo in its header and the
// name it goes by. Colours are read through a canvas, so oklch(), hsl() and the rest come back
// as plain RGB. The storyboard's theme overrides any of it.
async function readTheme(page, override) {
    const raw = await page
        .evaluate((override) => {
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 1;
            const paint = canvas.getContext('2d', { willReadFrequently: true });
            const colorOf = (value) => {
                if (typeof value !== 'string' || !value.trim()) return null;
                paint.fillStyle = 'rgba(1, 2, 3, 0.5)';
                const sentinel = paint.fillStyle;
                paint.fillStyle = value.trim();
                if (paint.fillStyle === sentinel) return null;
                paint.clearRect(0, 0, 1, 1);
                paint.fillRect(0, 0, 1, 1);
                const [r, g, b, a] = paint.getImageData(0, 0, 1, 1).data;
                return a < 200 ? null : [r, g, b];
            };
            const channel = (value) => {
                const c = value / 255;
                return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
            };
            const luminance = ([r, g, b]) =>
                0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
            const contrast = (a, b) => {
                const [x, y] = [luminance(a), luminance(b)].sort(
                    (p, q) => q - p,
                );
                return (x + 0.05) / (y + 0.05);
            };
            const vivid = (rgb) => {
                if (!rgb) return false;
                const max = Math.max(...rgb) / 255;
                const min = Math.min(...rgb) / 255;
                const lightness = (max + min) / 2;
                const saturation =
                    max === min
                        ? 0
                        : (max - min) / (1 - Math.abs(2 * lightness - 1));
                return (
                    saturation >= 0.3 && lightness > 0.15 && lightness < 0.85
                );
            };
            const visible = (element) => {
                const box = element.getBoundingClientRect();
                if (
                    box.width < 4 ||
                    box.height < 4 ||
                    box.bottom < 0 ||
                    box.right < 0 ||
                    box.top > innerHeight ||
                    box.left > innerWidth
                )
                    return false;
                const style = getComputedStyle(element);
                return (
                    style.visibility !== 'hidden' &&
                    style.display !== 'none' &&
                    Number(style.opacity) > 0.1
                );
            };
            const vote = (entries) => {
                const weights = new Map();
                entries.filter(Boolean).forEach(([rgb, weight]) => {
                    const key = rgb.join(',');
                    weights.set(key, (weights.get(key) || 0) + weight);
                });
                const [best] = [...weights.entries()].sort(
                    (a, b) => b[1] - a[1],
                );
                return best ? best[0].split(',').map(Number) : null;
            };
            const backgroundOf = (element) => {
                for (let node = element; node; node = node.parentElement) {
                    const color = colorOf(
                        getComputedStyle(node).backgroundColor,
                    );
                    if (color) return color;
                }
                return null;
            };
            const styled = document.styleSheets.length > 0;
            const background = backgroundOf(document.body) ??
                backgroundOf(
                    document.elementFromPoint(innerWidth / 2, innerHeight / 2),
                ) ?? [255, 255, 255];
            const text = colorOf(getComputedStyle(document.body).color);
            const distinct = (rgb) => rgb && contrast(rgb, background) >= 1.6;
            const root = getComputedStyle(document.documentElement);
            const variable = (name) => {
                const value = root.getPropertyValue(name).trim();
                return value
                    ? (colorOf(value) ??
                          colorOf(`hsl(${value})`) ??
                          colorOf(`rgb(${value})`) ??
                          colorOf(`oklch(${value})`))
                    : null;
            };
            const controls = [
                ...document.querySelectorAll(
                    'button, a, [role="button"], input[type="submit"]',
                ),
            ]
                .slice(0, 400)
                .filter(visible);
            const fillOf = (element) =>
                colorOf(getComputedStyle(element).backgroundColor);
            const candidates = [
                [
                    'css variables',
                    () =>
                        [
                            '--color-primary',
                            '--primary',
                            '--color-primary-600',
                            '--color-primary-500',
                            '--color-brand',
                            '--brand',
                            '--color-brand-600',
                            '--color-brand-500',
                            '--brand-color',
                            '--theme-color',
                            '--color-accent',
                            '--accent',
                        ]
                            .map(variable)
                            .find(distinct),
                ],
                [
                    'buttons',
                    () =>
                        vote(
                            controls.map((element) => {
                                const fill = fillOf(element);
                                return vivid(fill) && distinct(fill)
                                    ? [
                                          fill,
                                          element.matches(
                                              '[type="submit"], [class*="primary"]',
                                          )
                                              ? 3
                                              : 1,
                                      ]
                                    : null;
                            }),
                        ),
                ],
                [
                    'links',
                    () =>
                        vote(
                            [...document.querySelectorAll('a')]
                                .slice(0, 400)
                                .filter(visible)
                                .map((element) => {
                                    const color = colorOf(
                                        getComputedStyle(element).color,
                                    );
                                    return vivid(color) && distinct(color)
                                        ? [color, 1]
                                        : null;
                                }),
                        ),
                ],
                [
                    'coloured areas',
                    () =>
                        vote(
                            [...document.body.querySelectorAll('*')]
                                .slice(0, 2500)
                                .filter(visible)
                                .map((element) => {
                                    const fill = fillOf(element);
                                    if (!vivid(fill) || !distinct(fill))
                                        return null;
                                    const box = element.getBoundingClientRect();
                                    return [
                                        fill,
                                        Math.min(
                                            box.width * box.height,
                                            200000,
                                        ),
                                    ];
                                }),
                        ),
                ],
            ];
            let accent = null;
            let accentFrom = 'none';
            for (const [source, find] of candidates) {
                accent = find() ?? null;
                if (accent) {
                    accentFrom = source;
                    break;
                }
            }
            const button =
                controls.find(
                    (element) =>
                        accent && fillOf(element)?.join() === accent.join(),
                ) ?? controls.find((element) => element.matches('button'));
            const radius = button
                ? parseFloat(getComputedStyle(button).borderTopLeftRadius)
                : null;
            const heading = [...document.querySelectorAll('h1, h2')].find(
                visible,
            );
            const inlined = (svg) => {
                const clone = svg.cloneNode(true);
                const sources = [svg, ...svg.querySelectorAll('*')];
                const targets = [clone, ...clone.querySelectorAll('*')];
                sources.forEach((source, index) => {
                    const style = getComputedStyle(source);
                    [
                        'fill',
                        'fill-opacity',
                        'stroke',
                        'stroke-width',
                        'stroke-opacity',
                        'color',
                        'opacity',
                        'display',
                    ].forEach((property) =>
                        targets[index].style.setProperty(
                            property,
                            style.getPropertyValue(property),
                        ),
                    );
                });
                const box = svg.getBoundingClientRect();
                if (!clone.getAttribute('viewBox'))
                    clone.setAttribute(
                        'viewBox',
                        `0 0 ${box.width} ${box.height}`,
                    );
                clone.removeAttribute('class');
                clone.setAttribute('width', String(box.width));
                clone.setAttribute('height', String(box.height));
                return clone.outerHTML;
            };
            const mark = [
                ...document.querySelectorAll(
                    ':is(header, nav, aside, [class*="logo" i], [id*="logo" i], a[href="/"]) :is(img, svg), img[alt*="logo" i], img[src*="logo" i], svg[class*="logo" i]',
                ),
            ].find((element) => {
                if (!visible(element) || element.parentElement?.closest('svg'))
                    return false;
                if (element.matches('svg') && element.querySelector('use'))
                    return false;
                const box = element.getBoundingClientRect();
                return (
                    box.height >= 14 &&
                    box.height <= 120 &&
                    box.width <= 360 &&
                    box.top < 240
                );
            });
            const icon = [
                ...document.querySelectorAll(
                    'link[rel="apple-touch-icon"], link[rel~="icon"]',
                ),
            ]
                .map((link) => link.href)
                .find((href) => href && !/\.ico(\?|$)/.test(href));
            const markBox = mark?.getBoundingClientRect();
            const logo = mark
                ? {
                      ...(mark.matches('img')
                          ? { src: mark.currentSrc || mark.src }
                          : { svg: inlined(mark) }),
                      wide: markBox.width / markBox.height > 2.5,
                  }
                : icon
                  ? { src: icon, wide: false }
                  : null;
            const meta = (selector) =>
                document.querySelector(selector)?.content?.trim() || null;
            const titled = document.title
                .split(/\s+[-|–—·:]\s+/)
                .map((part) => part.trim())
                .filter(Boolean);
            const alt = mark?.matches('img')
                ? mark.alt.replace(/\blogo\b/i, '').trim()
                : '';
            const name =
                meta('meta[name="application-name"]') ??
                meta('meta[property="og:site_name"]') ??
                (alt || null) ??
                (titled.length > 1 ? titled.at(-1) : null);
            const given = (key) =>
                Object.prototype.hasOwnProperty.call(override, key);
            const overridden = colorOf(override.accent);

            return {
                name: given('name') ? override.name || null : name,
                logo: given('logo')
                    ? override.logo
                        ? {
                              src: new URL(String(override.logo), location.href)
                                  .href,
                              wide: false,
                          }
                        : null
                    : logo,
                background: colorOf(override.background) ?? background,
                text: colorOf(override.text) ?? text,
                accent: overridden ?? accent,
                accentFrom: overridden ? 'storyboard' : accentFrom,
                font:
                    override.font ??
                    (styled
                        ? getComputedStyle(document.body).fontFamily
                        : null),
                heading:
                    override.heading ??
                    override.font ??
                    (styled && heading
                        ? getComputedStyle(heading).fontFamily
                        : null),
                radius: Number.isFinite(Number(override.radius))
                    ? Number(override.radius)
                    : radius,
            };
        }, override || {})
        .catch((error) => {
            console.error(
                `agent-capture: the look of the app could not be read (${String(error.message || error).split('\n')[0]}); using a neutral one.`,
            );
            return {};
        });

    return paletteOf(raw);
}

function paletteOf(raw) {
    const background = raw.background ?? WHITE;
    const dark = luminance(background) < 0.2;
    const text =
        raw.text && contrast(raw.text, background) >= 4.5
            ? raw.text
            : dark
              ? [248, 250, 252]
              : [17, 24, 39];
    const surface = mix(background, WHITE, dark ? 0.08 : 0.85);
    const accent = readable(raw.accent ?? text, background, 2.2);
    const font = raw.font
        ? `${cssValue(raw.font)}, ${FALLBACK_FONT}`
        : FALLBACK_FONT;

    return {
        name: raw.name ?? null,
        logo: raw.logo ?? null,
        accentFrom: raw.accentFrom ?? 'none',
        dark,
        background: hex(background),
        surface: hex(surface),
        text: hex(text),
        muted: hex(readable(mix(text, background, 0.3), background, 4.5)),
        faint: hex(readable(mix(text, background, 0.5), background, 3)),
        border: alpha(text, dark ? 0.16 : 0.12),
        accent: hex(accent),
        accentText: hex(readable(readable(accent, background, 3), surface, 3)),
        onAccent:
            contrast(accent, WHITE) >= contrast(accent, BLACK)
                ? '#ffffff'
                : '#000000',
        glow: alpha(accent, 0.4),
        tint: alpha(accent, dark ? 0.24 : 0.16),
        tintSoft: alpha(accent, dark ? 0.12 : 0.08),
        dim: dark ? 'rgba(0, 0, 0, 0.4)' : 'rgba(15, 23, 42, 0.18)',
        shadow: dark
            ? '0 18px 48px rgba(0, 0, 0, 0.5)'
            : '0 14px 36px rgba(15, 23, 42, 0.16)',
        font,
        heading: raw.heading
            ? `${cssValue(raw.heading)}, ${FALLBACK_FONT}`
            : font,
        radius: Math.min(16, Math.max(0, Number(raw.radius) || 0)),
        fallbackFonts: !raw.font,
    };
}

const describeTheme = (theme) =>
    [
        theme.name ? `name "${theme.name}"` : 'no name',
        theme.logo ? 'a logo' : 'no logo',
        `accent ${theme.accent} (from ${theme.accentFrom})`,
        `background ${theme.background}`,
        `text ${theme.text}`,
        `font ${theme.font.split(',')[0]}`,
    ].join(', ');

// Everything agent-capture draws over the app lives in a shadow root, so the app's own CSS cannot
// restyle it, and in the top layer, so it stays above the app and out of a zoom.
async function mountOverlay(page, id, theme, css, html) {
    await page.evaluate(
        ({ id, css, html, fonts }) => {
            if (fonts && !document.getElementById('__agent_fonts')) {
                const style = document.createElement('style');
                style.id = '__agent_fonts';
                style.textContent = fonts;
                document.head.append(style);
            }

            document.getElementById(id)?.remove();
            const host = document.createElement('div');
            host.id = id;
            host.setAttribute('popover', 'manual');
            host.style.cssText =
                'all:initial;display:block;position:fixed;inset:0;margin:0;padding:0;border:0;' +
                'background:transparent;overflow:visible;pointer-events:none;z-index:2147483647;';
            document.documentElement.append(host);
            const root = host.attachShadow({ mode: 'open' });
            root.innerHTML = `<style>* { box-sizing: border-box; margin: 0; } ${css}</style>${html}`;
            host.showPopover?.();

            return Promise.race([
                Promise.all([
                    document.fonts.ready,
                    ...[...root.querySelectorAll('img')].map((image) =>
                        image.decode().catch(() => image.remove()),
                    ),
                ]),
                new Promise((resolve) => setTimeout(resolve, 3000)),
            ]).then(() => undefined);
        },
        { id, css, html, fonts: theme.fallbackFonts ? FONTS : '' },
    );
}

const unmount = (page, id) =>
    page
        .evaluate((id) => document.getElementById(id)?.remove(), id)
        .catch(() => {});

function cardOf(theme, eyebrow, title, body) {
    const date = new Date().toISOString().slice(0, 10);
    const logo = theme.logo
        ? theme.logo.svg
            ? `<span class="logo">${theme.logo.svg}</span>`
            : `<img class="logo" src="${escapeHtml(theme.logo.src)}" alt="">`
        : '';
    const name =
        theme.name && !theme.logo?.wide
            ? `<span>${escapeHtml(theme.name)}</span>`
            : '';

    return {
        css: `
    .card { position: fixed; inset: 0; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;
        padding: 72px 96px 80px; background: ${theme.background}; color: ${theme.text}; font-family: ${theme.font}; }
    .glow { position: absolute; inset: 0; background: radial-gradient(56rem 38rem at 88% -14%, ${theme.tint}, transparent 62%),
        radial-gradient(40rem 30rem at -8% 112%, ${theme.tintSoft}, transparent 58%); }
    header, main, footer { position: relative; }
    header { display: flex; align-items: center; gap: 16px; min-height: 48px; font-family: ${theme.heading}; font-size: 28px;
        font-weight: 650; letter-spacing: -.01em; }
    .logo { display: flex; align-items: center; }
    img.logo, .logo svg { height: ${theme.logo?.wide ? 40 : 48}px; width: auto; max-width: 320px; object-fit: contain; }
    main { max-width: 1160px; }
    .eyebrow { font-size: 15px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: ${theme.accentText}; }
    h1 { margin-top: 18px; font-family: ${theme.heading}; font-size: 64px; line-height: 1.06; font-weight: 700;
        letter-spacing: -.02em; }
    p { margin-top: 26px; max-width: 980px; font-size: 25px; line-height: 1.5; color: ${theme.muted}; }
    ol { margin-top: 34px; padding: 0; list-style: none; counter-reset: scene; display: grid; grid-auto-flow: column;
        grid-template-rows: repeat(6, auto); justify-content: start; gap: 14px 56px; font-size: 21px; color: ${theme.muted}; }
    li { counter-increment: scene; display: flex; align-items: baseline; gap: 14px; }
    li::before { content: counter(scene, decimal-leading-zero); font-size: 15px; font-weight: 600;
        font-variant-numeric: tabular-nums; color: ${theme.accentText}; }
    footer { display: flex; justify-content: flex-end; font-size: 15px; color: ${theme.faint}; }
    .bar { position: absolute; left: 0; right: 0; bottom: 0; height: 8px; background: ${theme.accent}; }`,
        html: `<div class="card"><div class="glow"></div><header>${logo}${name}</header><main><div class="eyebrow">${escapeHtml(eyebrow)}</div><h1>${escapeHtml(title)}</h1>${body}</main><footer><span>${CREDIT} · ${date}</span></footer><div class="bar"></div></div>`,
    };
}

const fontsReady = (page) =>
    page
        .evaluate(
            (timeout) =>
                Promise.race([
                    document.fonts.ready.then(() => true),
                    new Promise((resolve) => setTimeout(resolve, timeout)),
                ]),
            FONT_TIMEOUT_MS,
        )
        .catch(() => {});

async function runStep(page, step, typing) {
    const [action] = Object.keys(step);
    const value = step[action];

    switch (action) {
        case 'goto':
            await page.goto(urlFor(value), { waitUntil: 'load' });
            await page
                .waitForLoadState('networkidle', { timeout: NETWORK_IDLE_MS })
                .catch(() => {});
            await fontsReady(page);
            return;
        case 'click':
            await page.locator(value).first().click();
            await page
                .waitForLoadState('networkidle', { timeout: NETWORK_IDLE_MS })
                .catch(() => {});
            return;
        case 'fill':
            if (typing) {
                await page.locator(value[0]).first().fill('');
                await page
                    .locator(value[0])
                    .first()
                    .pressSequentially(String(value[1]), { delay: 35 });
            } else {
                await page.locator(value[0]).first().fill(String(value[1]));
            }
            return;
        case 'select':
            await page.locator(value[0]).first().selectOption(String(value[1]));
            return;
        case 'press':
            if (Array.isArray(value)) {
                await page.locator(value[0]).first().press(value[1]);
            } else {
                await page.keyboard.press(value);
            }
            await page
                .waitForLoadState('networkidle', { timeout: NETWORK_IDLE_MS })
                .catch(() => {});
            return;
        case 'hover':
            await page.locator(value).first().hover();
            return;
        case 'scroll':
            if (typeof value === 'number') {
                await page.mouse.wheel(0, value);
            } else {
                await page.locator(value).first().scrollIntoViewIfNeeded();
            }
            return;
        case 'wait':
            await page.waitForTimeout(Math.min(Number(value) || 0, 10000));
            return;
        case 'waitFor':
            await page.locator(value).first().waitFor({ timeout: 15000 });
            return;
        default:
            throw new Error(`Unknown step "${action}"`);
    }
}

async function showCaption(page, theme, index, total, scene) {
    const radius = Math.min(theme.radius + 4, 16);

    await mountOverlay(
        page,
        '__agent_caption',
        theme,
        `
    .caption { position: fixed; left: 50%; bottom: 32px; transform: translateX(-50%); width: max-content;
        max-width: min(920px, 88vw); display: flex; gap: 14px; padding: 14px 22px 16px 16px; background: ${theme.surface};
        color: ${theme.text}; border: 1px solid ${theme.border}; border-radius: ${radius}px; box-shadow: ${theme.shadow};
        font: 400 15px/1.45 ${theme.font}; text-align: left; }
    .caption::before { content: ''; flex: none; width: 4px; border-radius: 4px; background: ${theme.accent}; }
    .step { font-size: 12px; font-weight: 600; letter-spacing: .1em; font-variant-numeric: tabular-nums; color: ${theme.accentText}; }
    .title { margin-top: 3px; font-family: ${theme.heading}; font-size: 19px; line-height: 1.25; font-weight: 650; }
    .body { margin-top: 3px; color: ${theme.muted}; }`,
        `<div class="caption"><div><div class="step">${String(index).padStart(2, '0')} / ${String(total).padStart(2, '0')}</div><div class="title">${escapeHtml(scene.title || `Scene ${index}`)}</div>${scene.caption ? `<div class="body">${escapeHtml(scene.caption)}</div>` : ''}</div></div>`,
    );
}

async function setCaptionVisible(page, visible) {
    await page.evaluate((visible) => {
        const host = document.getElementById('__agent_caption');
        if (host) host.style.visibility = visible ? 'visible' : 'hidden';
    }, visible);
}

const raiseOverlays = (page) =>
    page
        .evaluate(() =>
            ['__agent_caption', '__agent_zoom'].forEach((id) => {
                const host = document.getElementById(id);
                if (host?.matches(':popover-open')) {
                    host.hidePopover();
                    host.showPopover();
                }
            }),
        )
        .catch(() => {});

// The ring is placed in the page rather than over it, at document coordinates, so it scrolls,
// shows in a full-page screenshot and grows with a zoom along with what it rings.
async function highlight(page, theme, selector) {
    const target = page.locator(selector).first();
    await target.scrollIntoViewIfNeeded({ timeout: 5000 });
    const box = await target.boundingBox();

    if (!box) {
        return;
    }

    await page.evaluate(
        ({ box, theme }) => {
            document.getElementById('__agent_highlight')?.remove();
            const ring = document.createElement('div');
            ring.id = '__agent_highlight';
            ring.style.cssText =
                `all:initial;position:absolute;box-sizing:border-box;left:${box.x + scrollX - 8}px;top:${box.y + scrollY - 8}px;` +
                `width:${box.width + 16}px;height:${box.height + 16}px;border:3px solid ${theme.accent};` +
                `border-radius:${Math.min(theme.radius + 6, 18)}px;box-shadow:0 0 0 9999px ${theme.dim},0 0 24px ${theme.glow};` +
                'pointer-events:none;z-index:2147483646;';
            document.documentElement.append(ring);
        },
        { box, theme },
    );
}

// A zoom scales the page itself, so text is drawn sharp at the larger size rather than blown up
// from the video. Fixed elements would otherwise be left at the top of the document once the root
// is transformed; they are moved by the scroll offset so they stay where they were on screen. A
// frame and a badge show for as long as the view is zoomed, so it is plain when it is.
async function zoomIn(page, theme, selector) {
    const box = await page
        .locator(selector)
        .first()
        .boundingBox({ timeout: 3000 })
        .catch(() => null);

    if (!box) {
        console.error(`agent-capture: nothing to zoom in on at ${selector}.`);
        return null;
    }

    const scale = await page.evaluate(
        ({ box, ms, max }) => {
            const width = innerWidth;
            const height = innerHeight;
            const s = Math.min(
                max,
                (width * 0.7) / (box.width + 48),
                (height * 0.7) / (box.height + 48),
            );

            if (s < 1.2) return null;

            const left = Math.min(
                Math.max(box.x + box.width / 2 - width / s / 2, 0),
                width - width / s,
            );
            const top = Math.min(
                Math.max(box.y + box.height / 2 - height / s / 2, 0),
                height - height / s,
            );
            const html = document.documentElement;
            html.dataset.agentZoom = JSON.stringify({
                transform: html.style.transform,
                origin: html.style.transformOrigin,
                transition: html.style.transition,
            });

            [...document.body.querySelectorAll('*')].forEach((element) => {
                const style = getComputedStyle(element);
                if (style.position !== 'fixed') return;
                for (
                    let node = element.parentElement;
                    node && node !== document.body;
                    node = node.parentElement
                ) {
                    const parent = getComputedStyle(node);
                    if (parent.transform !== 'none' || parent.filter !== 'none')
                        return;
                }
                const [x = '0px', y = '0px', z = ''] =
                    style.translate === 'none'
                        ? []
                        : style.translate.split(' ');
                element.dataset.agentTranslate = element.style.translate;
                element.style.translate =
                    `calc(${x} + ${scrollX}px) calc(${y} + ${scrollY}px) ${z}`.trim();
            });

            html.style.transformOrigin = '0 0';
            html.style.transition = 'none';
            html.style.transform = 'translate(0px, 0px) scale(1)';
            void getComputedStyle(html).transform;
            html.style.transition = `transform ${ms}ms cubic-bezier(.45, 0, .2, 1)`;
            html.style.transform = `translate(${(1 - s) * scrollX - s * left}px, ${(1 - s) * scrollY - s * top}px) scale(${s})`;

            return Math.round(s * 10) / 10;
        },
        { box, ms: ZOOM_MS, max: ZOOM_MAX },
    );

    if (scale === null) {
        await resetZoom(page);
        console.error(
            `agent-capture: ${selector} already fills most of the view, so it is not zoomed in on.`,
        );
        return null;
    }

    await mountOverlay(
        page,
        '__agent_zoom',
        theme,
        `
    .frame { position: fixed; inset: 0; box-shadow: inset 0 0 0 3px ${theme.accent}; opacity: 0; transition: opacity .3s ease; }
    .badge { position: fixed; top: 20px; right: 20px; display: flex; align-items: center; gap: 8px; padding: 8px 14px 8px 11px;
        border-radius: 999px; background: ${theme.accent}; color: ${theme.onAccent}; font: 600 14px/1 ${theme.font};
        box-shadow: ${theme.shadow}; opacity: 0; transform: translateY(-6px); transition: opacity .3s ease, transform .3s ease; }
    .badge svg { width: 16px; height: 16px; }
    .on .frame, .on .badge { opacity: 1; transform: none; }`,
        `<div class="zoom"><div class="frame"></div><div class="badge">${MAGNIFIER}<span>Zoomed in ${scale}×</span></div></div>`,
    );
    await page.evaluate(() =>
        requestAnimationFrame(() =>
            document
                .getElementById('__agent_zoom')
                ?.shadowRoot.querySelector('.zoom')
                ?.classList.add('on'),
        ),
    );
    await page.waitForTimeout(ZOOM_MS);

    return scale;
}

async function zoomOut(page) {
    await page.evaluate(() => {
        document
            .getElementById('__agent_zoom')
            ?.shadowRoot.querySelector('.zoom')
            ?.classList.remove('on');
        document.documentElement.style.transform =
            'translate(0px, 0px) scale(1)';
    });
    await page.waitForTimeout(ZOOM_MS);
    await resetZoom(page);
}

const resetZoom = (page) =>
    page
        .evaluate(() => {
            const html = document.documentElement;
            document.getElementById('__agent_zoom')?.remove();

            if (!html.dataset.agentZoom) return;

            const saved = JSON.parse(html.dataset.agentZoom);
            html.style.transition = saved.transition;
            html.style.transform = saved.transform;
            html.style.transformOrigin = saved.origin;
            delete html.dataset.agentZoom;
            document
                .querySelectorAll('[data-agent-translate]')
                .forEach((element) => {
                    element.style.translate = element.dataset.agentTranslate;
                    delete element.dataset.agentTranslate;
                });
        })
        .catch(() => {});

async function clearOverlays(page) {
    await resetZoom(page);
    await page
        .evaluate(() => {
            document.getElementById('__agent_caption')?.remove();
            document.getElementById('__agent_highlight')?.remove();
        })
        .catch(() => {});
}

function validate(storyboard) {
    if (!storyboard || typeof storyboard !== 'object')
        throw new Error('The storyboard is not a JSON object.');
    if (!storyboard.title) throw new Error('The storyboard needs a title.');
    if (!Array.isArray(storyboard.scenes) || storyboard.scenes.length === 0)
        throw new Error('The storyboard needs at least one scene.');
    if (storyboard.scenes.length > MAX_SCENES)
        throw new Error(`Keep it to ${MAX_SCENES} scenes at most.`);
    if (
        storyboard.theme !== undefined &&
        (typeof storyboard.theme !== 'object' || storyboard.theme === null)
    )
        throw new Error('The theme needs to be an object.');
    storyboard.scenes.forEach((scene, index) => {
        if (!scene.goto && !(scene.steps || []).length)
            throw new Error(`Scene ${index + 1} needs a goto or steps.`);
        if (scene.zoom === true && !scene.highlight)
            throw new Error(
                `Scene ${index + 1} zooms in on its highlight, but has none; give zoom a selector instead.`,
            );
    });
}

// The recording runs at a device scale of 1: on the Linux runner a scale of 2 makes Chromium hand the
// screencast frames at half the video size, which Playwright pads with grey, so the walkthrough
// showed the page in its top-left quarter. Screenshots are still taken at twice the resolution,
// by asking Chromium for a scaled capture of the viewport (or the whole page) through CDP.
async function screenshot(page, file, fullPage, scale = SCREENSHOT_SCALE) {
    const client = await page.context().newCDPSession(page);

    try {
        const view = await page.evaluate(() => ({
            x: window.scrollX,
            y: window.scrollY,
            width: window.innerWidth,
            height: window.innerHeight,
            pageWidth: document.documentElement.scrollWidth,
            pageHeight: document.documentElement.scrollHeight,
        }));
        const clip = fullPage
            ? { x: 0, y: 0, width: view.pageWidth, height: view.pageHeight }
            : { x: view.x, y: view.y, width: view.width, height: view.height };
        const { data } = await client.send('Page.captureScreenshot', {
            format: 'png',
            captureBeyondViewport: fullPage,
            clip: { ...clip, scale },
        });

        fs.writeFileSync(file, Buffer.from(data, 'base64'));
    } finally {
        await client.detach().catch(() => {});
    }
}

const even = (value) => Math.max(2, Math.floor(value / 2) * 2);

// Playwright pads every screencast frame smaller than the video with grey (Y 126, U/V 128) in its
// bottom-right, and Chromium on the Linux runner can hand frames smaller than the viewport, which
// put the page in a corner of a grey field. The grey is masked out and the largest area the frames
// covered is detected, over the whole recording, so one grey element inside a page cannot shrink it.
function recordedArea(webm, viewport) {
    const probe = spawnSync(
        'ffmpeg',
        [
            '-hide_banner',
            '-i',
            webm,
            '-vf',
            "fps=2,lutyuv=y='if(between(val\\,122\\,132)\\,0\\,255)',cropdetect=limit=16:round=2:reset=0",
            '-f',
            'null',
            '-',
        ],
        { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 },
    );
    const found = [
        ...String(probe.stderr || '').matchAll(/crop=(\d+):(\d+):(\d+):(\d+)/g),
    ].pop();

    if (!found) return null;

    const [width, height, x, y] = found.slice(1).map(Number);
    const padded =
        width < viewport.width * 0.98 || height < viewport.height * 0.98;
    const plausible =
        width >= viewport.width * 0.2 && height >= viewport.height * 0.2;

    return padded && plausible ? { width, height, x, y } : null;
}

// The browser keeps recording until its context is closed, so the clip runs a little past the last
// scene; the summary card is placed from the length of the video itself rather than the clock.
function durationOf(file) {
    const probe = spawnSync(
        'ffprobe',
        [
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            file,
        ],
        { encoding: 'utf8' },
    );
    const seconds = Number.parseFloat(String(probe.stdout || ''));

    return Number.isFinite(seconds) && seconds > 0 ? seconds : null;
}

const X264 = [
    '-c:v',
    'libx264',
    '-preset',
    'slow',
    '-tune',
    'stillimage',
    '-crf',
    '18',
    '-profile:v',
    'high',
    '-pix_fmt',
    'yuv420p',
    '-movflags',
    '+faststart',
    '-an',
];

const still = (card) => [
    '-loop',
    '1',
    '-framerate',
    String(FRAME_RATE),
    '-t',
    String(card.seconds),
    '-i',
    card.file,
];

// The cards are stills, so they are not recorded in real time but rendered once and held for as
// long as they are read, in front of and behind the browser clip. When that fails the clip goes
// out on its own, without cards.
function composeVideo(webm, viewport, cards) {
    const mp4 = path.join(OUTPUT, 'video.mp4');
    const area = recordedArea(webm, viewport);
    const size = `${even(viewport.width)}:${even(viewport.height)}`;
    const frames = `fps=${FRAME_RATE},setsar=1,format=yuv420p`;
    const clip = `${area ? `crop=${area.width}:${area.height}:${area.x}:${area.y},` : ''}scale=${size}:flags=lanczos,${frames}`;

    if (area) {
        console.error(
            `agent-capture: the browser recorded ${area.width}×${area.height} of the ${viewport.width}×${viewport.height} video; cropping the grey padding and scaling it back up.`,
        );
    }

    const attempts = [
        cards && [
            ...still(cards.intro),
            '-i',
            webm,
            ...still(cards.summary),
            '-filter_complex',
            `[0:v]scale=${size},${frames}[intro];[1:v]${clip}[clip];[2:v]scale=${size},${frames}[summary];[intro][clip][summary]concat=n=3:v=1:a=0[video]`,
            '-map',
            '[video]',
        ],
        ['-i', webm, '-vf', clip],
    ].filter(Boolean);

    for (const [index, inputs] of attempts.entries()) {
        try {
            execFileSync(
                'ffmpeg',
                ['-y', '-loglevel', 'error', ...inputs, ...X264, mp4],
                { stdio: ['ignore', 'ignore', 'pipe'] },
            );
            fs.rmSync(webm, { force: true });
            return { file: 'video.mp4', cards: Boolean(cards) && index === 0 };
        } catch (error) {
            console.error(
                `agent-capture: ffmpeg failed (${
                    String(error.stderr || error.message || error)
                        .trim()
                        .split('\n')[0]
                })${index < attempts.length - 1 ? '; trying the clip without its cards' : ''}.`,
            );
        }
    }

    return { file: path.basename(webm), cards: false };
}

// The cards are drawn over a page of the app, so the fonts it loads are there to draw them in.
async function renderCards(page, storyboard, scenes, theme) {
    const directory = path.join(OUTPUT, 'cards');
    const shown = scenes.find((scene) => scene.screenshot);

    await page
        .goto(urlFor(shown?.url || storyboard.scenes[0].goto || '/'), {
            waitUntil: 'load',
        })
        .catch(() => {});
    await fontsReady(page);

    const recap = scenes
        .filter((scene) => scene.screenshot)
        .map((scene) => `<li>${escapeHtml(scene.title)}</li>`)
        .join('');
    const cards = {
        intro: {
            file: path.join(directory, 'intro.png'),
            seconds: readingSeconds(storyboard.intro, 3),
            ...cardOf(
                theme,
                'What changed',
                storyboard.title,
                storyboard.intro
                    ? `<p>${escapeHtml(storyboard.intro)}</p>`
                    : '',
            ),
        },
        summary: {
            file: path.join(directory, 'summary.png'),
            seconds: readingSeconds(storyboard.summary, 4),
            ...cardOf(
                theme,
                'Summary',
                storyboard.title,
                `${storyboard.summary ? `<p>${escapeHtml(storyboard.summary)}</p>` : ''}<ol>${recap}</ol>`,
            ),
        },
    };

    fs.mkdirSync(directory, { recursive: true });

    for (const entry of Object.values(cards)) {
        await mountOverlay(page, '__agent_card', theme, entry.css, entry.html);
        await screenshot(page, entry.file, false, 1);
        await unmount(page, '__agent_card');
    }

    return cards;
}

// Laravel apps that force https (URL::forceHttps, an https APP_URL or ASSET_URL) point their assets,
// forms and redirects at https://127.0.0.1, where the running copy does not listen. Those requests
// are answered from the plain http server instead, cookies included. Chromium does not route the
// hop of a redirect it was handed, so a redirect to https://127.0.0.1 is pointed at http instead.
// A site that is served over https itself, like a local Herd or Valet site, needs none of this.
async function servePlainHttp(context) {
    if (!BASE_URL.startsWith('http://')) return;

    const secure = new RegExp('^https://' + BASE_HOST + '(?=/)');

    await context.route(secure, async (route) => {
        const request = route.request();

        try {
            const response = await context.request.fetch(
                request.url().replace(/^https:/, 'http:'),
                {
                    method: request.method(),
                    headers: await request.allHeaders(),
                    data: request.postDataBuffer() ?? undefined,
                    maxRedirects: 0,
                    failOnStatusCode: false,
                },
            );
            const location = response.headers().location;

            await route.fulfill({
                response,
                ...(location
                    ? {
                          headers: {
                              ...response.headers(),
                              location: location.replace(secure, BASE_URL),
                          },
                      }
                    : {}),
            });
        } catch (error) {
            console.error(
                `agent-capture: ${request.url()} could not be served over http (${String(error.message || error).split('\n')[0]}).`,
            );
            await route.abort().catch(() => {});
        }
    });
}

// The running copy was booted from the checkout before this turn changed it, so its frontend
// build and database are brought up to the checkout first; otherwise the recording shows the
// application as it was, not as the agent left it.
function refreshRunningCopy() {
    if (process.env.AGENT_APP_BOOT !== '1') return;

    console.log('Bringing the running copy up to date with the checkout…');

    try {
        execFileSync('agent-session', ['--refresh'], {
            stdio: ['ignore', 'ignore', 'inherit'],
            timeout: (Number(process.env.AGENT_APP_BOOT_SECONDS) || 600) * 1000,
        });
    } catch (error) {
        console.error(
            `agent-capture: refreshing the running copy failed (${String(error.message || error).split('\n')[0]}); see /tmp/agent/bootstrap.status and /tmp/agent/bootstrap.log. Recording anyway, so check the screenshots show the change.`,
        );
    }
}

async function capture(storyboard, { video = true } = {}) {
    validate(storyboard);
    refreshRunningCopy();

    fs.mkdirSync(OUTPUT, { recursive: true });
    fs.readdirSync(OUTPUT).forEach((name) =>
        fs.rmSync(path.join(OUTPUT, name), { recursive: true, force: true }),
    );
    fs.mkdirSync(path.join(OUTPUT, 'screenshots'), { recursive: true });

    const viewport = {
        width: storyboard.viewport?.width || 1440,
        height: storyboard.viewport?.height || 900,
    };
    const browser = await playwright().chromium.launch({
        channel: 'chromium',
        args: [
            '--disable-dev-shm-usage',
            '--font-render-hinting=none',
            '--force-color-profile=srgb',
        ],
    });
    const manifest = {
        version: 1,
        title: storyboard.title,
        intro: storyboard.intro || null,
        summary: storyboard.summary || null,
        video: null,
        duration: null,
        summary_at: null,
        viewport,
        scenes: [],
    };

    try {
        const setup = await browser.newContext({
            viewport,
            deviceScaleFactor: 1,
            ignoreHTTPSErrors: true,
            bypassCSP: true,
        });
        await servePlainHttp(setup);
        const setupPage = await setup.newPage();
        setupPage.setDefaultTimeout(ACTION_TIMEOUT_MS);

        for (const step of storyboard.setup || [])
            await runStep(setupPage, step, false);

        const videoDirectory = path.join(OUTPUT, '.video');
        const context = await browser.newContext({
            viewport,
            deviceScaleFactor: 1,
            storageState: await setup.storageState(),
            ignoreHTTPSErrors: true,
            bypassCSP: true,
            ...(video
                ? { recordVideo: { dir: videoDirectory, size: viewport } }
                : {}),
        });
        await servePlainHttp(context);
        const page = await context.newPage();
        page.setDefaultTimeout(ACTION_TIMEOUT_MS);
        const startedAt = Date.now();
        const at = () => secondsOf((Date.now() - startedAt) / 1000);
        const total = storyboard.scenes.length;
        let theme = null;

        for (const [position, scene] of storyboard.scenes.entries()) {
            const index = position + 1;
            const entry = {
                title: scene.title || `Scene ${index}`,
                caption: scene.caption || null,
                url: null,
                screenshot: null,
                at: video ? at() : null,
                error: null,
            };

            try {
                if (scene.goto)
                    await runStep(page, { goto: scene.goto }, video);

                if (!theme) {
                    theme = await readTheme(page, storyboard.theme);
                    console.log(
                        `Styled after the app: ${describeTheme(theme)}.`,
                    );
                }

                if (video) {
                    await showCaption(page, theme, index, total, scene);
                    await page.waitForTimeout(1200);
                }

                for (const step of scene.steps || []) {
                    await runStep(page, step, video);
                    if (video) {
                        await raiseOverlays(page);
                        await page.waitForTimeout(STEP_PAUSE_MS);
                    }
                }

                if (scene.highlight)
                    await highlight(page, theme, scene.highlight).catch(
                        () => {},
                    );

                const file = `${String(index).padStart(2, '0')}-${slug(entry.title)}.png`;
                await setCaptionVisible(page, false);
                await fontsReady(page);
                await screenshot(
                    page,
                    path.join(OUTPUT, 'screenshots', file),
                    Boolean(scene.fullPage),
                );
                await setCaptionVisible(page, true);

                entry.screenshot = `screenshots/${file}`;
                entry.url = pathOf(page.url());

                const hold =
                    (Number(scene.hold) || readingSeconds(scene.caption, 3)) *
                    1000;
                const zoomOn =
                    scene.zoom === true ? scene.highlight : scene.zoom || null;

                if (video && zoomOn) {
                    const zoomed = await zoomIn(page, theme, zoomOn);
                    await page.waitForTimeout(
                        zoomed ? Math.max(hold, 2500) : hold,
                    );
                    if (zoomed) await zoomOut(page);
                } else if (video) {
                    await page.waitForTimeout(hold);
                } else if (zoomOn && !(await page.locator(zoomOn).count())) {
                    console.error(
                        `scene ${index} (${entry.title}): nothing to zoom in on at ${zoomOn}.`,
                    );
                }
            } catch (error) {
                entry.error = String(error.message || error).split('\n')[0];
                console.error(
                    `scene ${index} (${entry.title}) failed: ${entry.error}`,
                );
            }

            await clearOverlays(page);
            manifest.scenes.push(entry);
        }

        const clipEnd = at();
        const recording = page.video();
        await context.close();

        theme ??= await readTheme(setupPage, storyboard.theme);
        const cards = await renderCards(
            setupPage,
            storyboard,
            manifest.scenes,
            theme,
        ).catch((error) => {
            console.error(
                `agent-capture: the intro and summary cards could not be drawn (${String(error.message || error).split('\n')[0]}).`,
            );
            return null;
        });

        if (video && recording) {
            const recorded = path.join(OUTPUT, 'video.webm');
            fs.renameSync(await recording.path(), recorded);

            const composed = composeVideo(recorded, viewport, cards);
            const intro = composed.cards ? cards.intro.seconds : 0;
            const outro = composed.cards ? cards.summary.seconds : 0;
            const length =
                durationOf(path.join(OUTPUT, composed.file)) ??
                intro + clipEnd + outro;

            manifest.video = composed.file;
            manifest.scenes.forEach((scene) => {
                scene.at = secondsOf(scene.at + intro);
            });
            manifest.summary_at = composed.cards
                ? secondsOf(length - outro)
                : null;
            manifest.duration = secondsOf(length);
        }

        await setup.close();
        fs.rmSync(videoDirectory, { recursive: true, force: true });
    } finally {
        await browser.close();
    }

    fs.writeFileSync(
        path.join(OUTPUT, 'manifest.json'),
        JSON.stringify(manifest, null, 2),
    );

    return manifest;
}

async function main() {
    const flags = process.argv.slice(2).filter((arg) => arg.startsWith('-'));
    const [file] = process.argv.slice(2).filter((arg) => !arg.startsWith('-'));
    const unknown = flags.filter(
        (flag) =>
            !['--example', '--help', '-h', '--screenshots-only'].includes(flag),
    );

    if (flags.includes('--example')) {
        console.log(JSON.stringify(EXAMPLE, null, 2));
        return 0;
    }

    if (flags.includes('--help') || flags.includes('-h')) {
        console.log(USAGE);
        return 0;
    }

    if (!file || unknown.length) {
        if (unknown.length)
            console.error(`agent-capture: unknown option ${unknown.join(' ')}`);
        console.log(USAGE);
        return 1;
    }

    const video = !flags.includes('--screenshots-only');
    const storyboard = JSON.parse(fs.readFileSync(file, 'utf8'));
    const manifest = await capture(storyboard, { video });
    const captured = manifest.scenes.filter((scene) => scene.screenshot).length;
    const failed = manifest.scenes.filter((scene) => scene.error);

    console.log(
        `Captured ${captured} of ${manifest.scenes.length} scenes${manifest.video ? `, video ${manifest.video} (${manifest.duration}s)` : ''} in ${OUTPUT}.`,
    );
    failed.forEach((scene) =>
        console.log(`  - "${scene.title}" failed: ${scene.error}`),
    );
    console.log(
        video
            ? 'Look at the screenshots with your Read tool before you finish, and run it again if a scene shows the wrong thing.'
            : `Screenshots only, no video. Look at them and at the cards in ${path.join(OUTPUT, 'cards')} with your Read tool and run --screenshots-only again until every scene is right; then run \`${COMMAND} ${file}\` once, without --screenshots-only, for the walkthrough video.`,
    );

    return captured > 0 ? 0 : 1;
}

main().then(
    (code) => process.exit(code),
    (error) => {
        console.error(`agent-capture: ${error.message || error}`);
        process.exit(1);
    },
);
