// Diwan's helper: a page guide that hops beside the section being read, thinks,
// then says one short line about it, and watches the form fields (moves to the
// one in focus, frowns at an invalid value, smiles at a valid one). Drawn by
// @bible-strong/avatar-web (vendored in js/lib/, MIT). The character is the
// Avatar Lab export diwan-dark.avatar.json, inlined because .htaccess refuses
// to serve .json. A module, so nothing here is global.
//
// Not on phones: there is no room beside anything, and they are spared the
// 70 KB download. Reduced motion: the library holds a still picture, lines
// appear whole, and the base stylesheet turns the hops into jumps.
const HIDDEN_KEY = 'diwan-helper-hidden';
let hiddenByVisitor = false;
try { hiddenByVisitor = sessionStorage.getItem(HIDDEN_KEY) === '1'; } catch {}

if (matchMedia('(min-width: 640px)').matches && !hiddenByVisitor) {
  const { createAvatar } = await import('./lib/avatar-web-1.1.0.js');

  const character = {
    name: 'Diwan Dark',
    body: {
      primary: { type: 'cube', width: 176, height: 152, depth: 130, roundness: 0.88 },
      nodes: [
        { surface: { type: 'sphere', width: 78, height: 78, depth: 42, roundness: 1 }, position: [-76, -72, -14], rotation: [0, 0, 0] },
        { surface: { type: 'sphere', width: 78, height: 78, depth: 42, roundness: 1 }, position: [76, -72, -14], rotation: [0, 0, 0] },
        { surface: { type: 'cylinder', width: 52, height: 56, depth: 64, roundness: 1.5, morphRoundness: 0.45 }, position: [0, -74, -14], rotation: [0, 0, 0] },
      ],
    },
    colors: { body: '#00e5a0', eyes: '#000000' },
    face: {
      eyes: { width: 1.15, height: 1.15, gap: 1.05, shiftY: -0.06 },
      nose: { enabled: true, shape: 'inverted-triangle', roundness: 65, width: 12, height: 9, x: 0, y: 18, angle: 0 },
      mouth: { enabled: true, shape: 'curve', curve: 50, width: 18, height: 6, x: 0, y: 32, angle: 0 },
      perspective: 1,
    },
  };

  const S = 96;      // the helper's box, px
  const EDGE = 16;   // kept this far from the window's edges
  const TOP = 88;    // and below the fixed header
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const pricing = document.getElementById('pricing') ? '#pricing' : '/pricing.html';

  // Said the first time each section is read. Keyed by section id; 'hero' is the page's first section.
  const LINES = {
    hero: { text: "Hi! I'm Diwan's helper. Scroll and I'll show you around.", action: ['See the plans', pricing] },
    demo: { text: 'One minute, from start to receipt. Press play!' },
    pricing: { text: 'Simple monthly plans. Pick one and request access.' },
    faq: { text: 'Got a question? The answer is probably down here.' },
    download: { text: 'Ready? Get Diwan running on your counter.' },
  };
  // Said the first time a field is focused. Keyed by name, then by type.
  const FIELD_LINES = {
    email: 'Just your email, so we can reply.',
    license_key: 'Paste your licence key to get your installer link.',
    message: "Take your time, I'm listening.",
  };

  const off = new AbortController();   // every listener goes when the visitor hides the helper
  const on = (target, type, fn, opts = {}) => target.addEventListener(type, fn, { ...opts, signal: off.signal });
  const wait = (ms) => new Promise((done) => setTimeout(done, ms));
  const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);

  const hop = 'transition-transform duration-700 ease-[cubic-bezier(.34,1.4,.64,1)]';
  const root = document.createElement('div');
  root.className = `group fixed top-0 left-0 z-40 drop-shadow-[0_0_22px_var(--color-glow)] ${hop}`;
  root.innerHTML = `
    <button type="button" class="block cursor-pointer border-0 bg-transparent p-0" aria-label="Diwan helper"></button>
    <button type="button" class="absolute -top-1 -right-1 grid size-6 cursor-pointer place-items-center rounded-full border border-line bg-surface text-[.85rem] leading-none text-muted opacity-0 transition-opacity group-hover:opacity-100 hover:text-ink focus-visible:opacity-100" aria-label="Hide the helper">&times;</button>`;
  const [hit, hideButton] = root.querySelectorAll('button');

  const bubble = document.createElement('div');
  bubble.className = `fixed top-0 left-0 z-40 w-max max-w-[17rem] rounded-2xl bg-[#e9fff5] px-4 py-3 text-[.9rem] leading-snug font-medium text-[#04120c] shadow-[0_10px_40px_-12px_rgb(0_229_160/.5)] ${hop}`;
  bubble.hidden = true;
  bubble.innerHTML = '<p aria-hidden="true"></p><a class="mt-2 inline-block rounded-full bg-[#04120c] px-3 py-1.5 text-[.82rem] font-semibold text-white hover:bg-black" hidden></a>';
  const [said, act] = bubble.children;
  const live = document.createElement('p');   // screen readers get the whole line once, not every typed letter
  live.className = 'sr-only';
  live.setAttribute('aria-live', 'polite');

  document.body.append(bubble, root, live);
  const mascot = createAvatar(hit, { character, state: 'waking', size: S, followPointer: true, ariaLabel: 'Diwan helper' });

  // Reactions loop until told otherwise, so each one may carry its way back to idle.
  let back;
  const react = (state, ms) => {
    clearTimeout(back);
    mascot.setState(state);
    if (ms) back = setTimeout(() => mascot.setState('idle'), ms);
  };

  // ── where it stands ──────────────────────────────────────────────────
  const sections = [...document.querySelectorAll('main section')]
    .map((el, i) => ({ el, key: i === 0 ? 'hero' : el.id, heading: el.querySelector('h1, h2') }))
    .filter((s) => s.heading);
  let anchor = null, field = null, current = null;

  // A heading's own text, not its full-width block; a field's box.
  const boxOf = (el) => {
    if (!/^H\d$/.test(el.tagName)) return el.getBoundingClientRect();
    const range = document.createRange();
    range.selectNodeContents(el);
    return range.getBoundingClientRect();
  };

  const place = () => {
    let x = innerWidth - S - EDGE, y = innerHeight - S - EDGE;   // no anchor: the corner
    if (anchor) {
      const r = boxOf(anchor);
      x = r.right + 20;
      y = r.top + r.height / 2 - S / 2;
      // A heading with no room on its right: its left. A field never goes left (the
      // field beside it is there); it, or a heading with no room either, gets the
      // helper standing on its top edge, at its right end.
      if (x > innerWidth - S - EDGE && /^H\d$/.test(anchor.tagName)) x = r.left - S - 20;
      if (x < EDGE || x > innerWidth - S - EDGE) { x = r.right - S; y = r.top - S + 14; }
    }
    x = clamp(x, EDGE, innerWidth - S - EDGE);
    y = clamp(y, TOP, innerHeight - S - EDGE);
    root.style.transform = `translate(${x}px, ${y}px)`;
    if (!bubble.hidden) {
      let by = y - bubble.offsetHeight - 10;
      if (by < TOP) by = y + S + 10;
      bubble.style.transform = `translate(${clamp(x + S * 0.55, EDGE, innerWidth - bubble.offsetWidth - EDGE)}px, ${by}px)`;
    }
  };

  // The section being read: the last one whose top is above the middle of the window.
  const follow = () => {
    if (!field && sections.length) {
      let s = sections[0];
      for (const t of sections) if (t.el.getBoundingClientRect().top < innerHeight / 2) s = t;
      anchor = s.heading;
      if (s !== current) {
        current = s;
        mascot.poke('bounce');
        const line = LINES[s.key];
        if (line && !s.said) { s.said = true; say(line); } else hush();
      }
    }
    place();
  };

  // ── talking ──────────────────────────────────────────────────────────
  let talk = 0, hushTimer;
  const hush = () => { talk++; clearTimeout(hushTimer); bubble.hidden = true; };
  const say = async ({ text, action }) => {
    const me = ++talk;
    clearTimeout(hushTimer);
    act.hidden = true;
    bubble.style.width = '';
    said.textContent = '…';
    bubble.hidden = false;
    place();
    react('curious');   // thinks first
    await wait(reduced ? 0 : 700);
    if (me !== talk) return;
    live.textContent = text;
    react('happy', 1500);
    // Sized for the whole line first, so typing never pushes the bubble off screen.
    said.textContent = text;
    bubble.style.width = `${bubble.offsetWidth}px`;
    place();
    if (!reduced) for (let i = 1; i <= text.length; i++) {
      said.textContent = text.slice(0, i);
      await wait(22);
      if (me !== talk) return;
    }
    if (action) { [act.textContent, act.href] = action; act.hidden = false; }
    place();
    hushTimer = setTimeout(hush, 7000);
  };
  on(bubble, 'pointerenter', () => clearTimeout(hushTimer));
  on(bubble, 'pointerleave', () => { if (!bubble.hidden) hushTimer = setTimeout(hush, 2500); });
  on(act, 'click', hush);

  // ── fields ───────────────────────────────────────────────────────────
  let warnedAt = -Infinity;
  const watched = (el) => el.matches?.('input, textarea, select') && el.type !== 'hidden'
    && el.tabIndex >= 0 && !el.closest('[aria-hidden="true"]');   // never the honeypot
  on(document, 'focusin', (e) => {
    if (!watched(e.target)) return;
    field = anchor = e.target;
    react('listening');
    const line = FIELD_LINES[field.name] ?? FIELD_LINES[field.type];
    // A warning about the field just left is still showing: let it be read first.
    if (line && !field.helperSaid && performance.now() - warnedAt > 3000) { field.helperSaid = true; say({ text: line }); } else place();
  });
  on(document, 'focusout', (e) => {
    if (e.target !== field) return;
    const el = field;
    field = null;
    if (el.value && el.type !== 'radio' && el.type !== 'checkbox') {
      if (el.validity.valid) react('happy', 1800);
      else {
        warnedAt = performance.now();
        react('confused', 2500);
        say({ text: el.type === 'email' ? 'Hmm, that email looks off.' : 'Hmm, check that last field.' });
      }
    } else react('idle');
    follow();
  });
  // A submit the browser refused: go to the first field it complains about.
  on(document, 'invalid', (e) => {
    if (!watched(e.target) || e.target === field) return;
    anchor = e.target;
    react('confused', 2500);
    say({ text: 'This one needs a look.' });
  }, { capture: true });

  // ── scrolling, the pointer, idling ───────────────────────────────────
  let queued = false, lastY = scrollY, glance;
  const onScroll = () => {
    const dy = scrollY - lastY;
    lastY = scrollY;
    if (dy) {   // glances the way the page moves
      mascot.setLookAt([0, dy > 0 ? -0.6 : 0.6]);
      clearTimeout(glance);
      glance = setTimeout(() => mascot.setLookAt(null), 350);
    }
    if (!queued) { queued = true; requestAnimationFrame(() => { queued = false; follow(); }); }
  };
  on(window, 'scroll', onScroll, { passive: true });
  on(window, 'resize', onScroll);

  on(hit, 'click', () => { react('laughing', 2500); mascot.poke('bounce'); say({ text: 'Hehe, that tickles!' }); });

  // Primary buttons (bg-accent) and the pricing cards' buttons.
  on(document, 'pointerover', (e) => {
    if (e.target.closest?.('a.bg-accent, button.bg-accent, .plan__cta')) react('excited', 2000);
  });

  // Dozes after 25 s without input, sleeps 25 s later; any input wakes it.
  let quiet, nudged = false;
  const rouse = () => {
    clearTimeout(quiet);
    if (mascot.getState().state === 'sleeping') { react('waking'); say({ text: "Oh! You're back. Hi!" }); }
    else if (mascot.getState().state === 'drowsy') react('waking');
    quiet = setTimeout(() => {
      react('drowsy');
      if (!nudged) { nudged = true; say({ text: "Still there? Take your time, I'll wait." }); }
      quiet = setTimeout(() => { hush(); react('sleeping'); }, 25000);
    }, 25000);
  };
  for (const type of ['pointermove', 'keydown', 'scroll']) on(window, type, rouse, { passive: true });

  on(hideButton, 'click', () => {
    try { sessionStorage.setItem(HIDDEN_KEY, '1'); } catch {}
    off.abort();
    hush();
    [back, quiet, glance].forEach(clearTimeout);
    mascot.destroy();
    root.remove();
    bubble.remove();
    live.remove();
  });

  rouse();
  follow();
}
