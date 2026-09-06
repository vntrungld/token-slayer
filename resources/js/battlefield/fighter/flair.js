// Flair lifecycle: which special badge a fighter is wearing and until when.
// Kept pure and free of Phaser so the timing rules — the part that is easy to
// get wrong — can be tested without a game runtime.

/** Empty flair state: no badge, no expiry. */
export function createFlairState() {
  return { flair: null, expiresAt: 0 };
}

/**
 * Start or extend a flair. A hit carrying no flair leaves the state alone, so a
 * plain Sonnet turn landing while a Fable badge is still up never cuts it
 * short: the badge's lifetime belongs to its own timer, not to the next hit.
 * At ~29% of turns on Fable, both cases are common rather than edge cases.
 *
 * @param {{flair: ?string, expiresAt: number}} state
 * @param {?string} flair
 * @param {number} now
 * @param {number} durationMs
 * @returns {{flair: ?string, expiresAt: number}} a new state; the input is not mutated
 */
export function startFlair(state, flair, now, durationMs) {
  if (!flair) {
    return state;
  }
  return { flair, expiresAt: now + durationMs };
}

/** Whether a flair is still showing at `now`. */
export function isFlairActive(state, now) {
  return state.flair !== null && now < state.expiresAt;
}

/** Drop the flair, e.g. when the fighter is removed from the scene. */
export function clearFlair() {
  return createFlairState();
}

/**
 * The duration a flair badge should stay up: the server-broadcast per-model
 * value when it is a real positive number, otherwise the client's own
 * fallback constant. The server decides which models get flair at all and for
 * how long -- this only guards against a missing or malformed value, it never
 * overrides a valid one.
 *
 * @param {?number} payloadDurationMs
 * @param {number} fallbackMs
 * @returns {number}
 */
export function resolveFlairDuration(payloadDurationMs, fallbackMs) {
  return typeof payloadDurationMs === 'number' && payloadDurationMs > 0 ? payloadDurationMs : fallbackMs;
}

/** Radians between consecutive glyphs on the orbit ring. */
const RING_CHAR_STEP = 0.3;

/**
 * Builds the repeating "MODEL ✦ MODEL ✦ ..." ring text as {ch, phase} slots
 * evenly spaced around a full circle. A single word spread at wide gaps is
 * illegible -- half of it always sits on the faded back arc -- so the label
 * tiles at a FIXED angular step (independent of word length): short and long
 * names read at the same visual density, and at least one full occurrence
 * always sits in the bright front arc.
 *
 * The step count is rounded to a whole multiple of the unit length so the
 * ring wraps seamlessly: a remainder at the seam (angle 2π back to 0) would
 * splice two different letters together mid-word.
 *
 * Phase walks BACKWARDS (negative) on purpose: in the front/bottom arc
 * (sinA >= 0, the caller's most emphasized zone) screen-x decreases as angle
 * increases, so laying characters out in increasing order there reads them
 * backwards exactly where they are most visible ("OPUS" -> "SUPO"). Reversing
 * the phase direction fixes the front arc; the de-emphasized back arc reads
 * backwards instead, which is the side worth sacrificing.
 *
 * @param {string} label  the model's flair key, already uppercased by the caller
 * @param {number} [charStepRad]  radians between glyphs; smaller reads denser
 * @returns {Array<{ch: string, phase: number}>}
 */
export function buildRingChars(label, charStepRad = RING_CHAR_STEP) {
  const unit = `${label}  ✦  `;
  const repeats = Math.max(1, Math.round((Math.PI * 2) / charStepRad / unit.length));
  const n = repeats * unit.length;
  const chars = [];
  for (let i = 0; i < n; i++) {
    chars.push({ ch: unit[i % unit.length], phase: -(i / n) * Math.PI * 2 });
  }
  return chars;
}

/** Duration (ms) of the post-hit spin-up before the ring eases back to cruise speed. */
export const SPIN_BOOST_MS = 1000;

/**
 * Angular-speed multiplier for the orbit ring at `elapsedMs` since the last
 * triggering hit: whips around fast right after impact, then eases back to
 * 1x (cruise speed) by the end of the boost window. Outside the window
 * (before the hit, or once it has fully eased out) the ring runs at cruise
 * speed -- the momentum change is what sells "impact" on a loop that would
 * otherwise read as flat background motion.
 *
 * @param {number} elapsedMs  time since the triggering hit, in ms
 * @param {number} [boostMs]  length of the spin-up window
 * @returns {number}
 */
export function spinMultiplier(elapsedMs, boostMs = SPIN_BOOST_MS) {
  if (elapsedMs < 0 || elapsedMs > boostMs) {
    return 1;
  }
  const p = elapsedMs / boostMs;
  return 1 + 3.2 * Math.pow(1 - p, 2.2);
}

/**
 * Brightness/scale boost for whichever slice of the ring currently sits
 * closest to the viewer (angle === π/2, the front sweet spot): a moving
 * "spotlight" sweeps across the flowing text as it rotates, so the segment
 * that is already most legible also reads as the most eye-catching.
 *
 * @param {number} angle  the glyph's current position on the ring, in radians
 * @returns {number}  0 (far side) to 1 (dead center of the front arc)
 */
export function spotlightBoost(angle) {
  let d = angle - Math.PI / 2;
  d = Math.atan2(Math.sin(d), Math.cos(d));
  return Math.max(0, Math.cos(d * 1.6));
}

/**
 * Whether the fighter's orbit ring needs to be rebuilt: either it doesn't
 * exist yet, or the model/color actually changed since it was created. A
 * repeat hit that refreshes the SAME flair must not rebuild it — that would
 * restart the ring's orbit position and momentum for no reason, and without
 * this check a DIFFERENT flair taking over mid-flight would leave the old
 * model's name/color showing until the whole window lapses naturally.
 *
 * @param {{flair: ?string, color: ?string}} previous
 * @param {{flair: ?string, color: ?string}} next
 * @returns {boolean}
 */
export function hasFlairChanged(previous, next) {
  return previous.flair !== next.flair || previous.color !== next.color;
}

/**
 * Darkens a `#rrggbb` hex color by `amount` (0 = unchanged, 1 = black), used
 * for the glyph stroke so it stays readable against the ring's fill color
 * without needing a second admin-configured value. Passes through anything
 * that is not a plain 6-digit hex color rather than throwing, since a
 * malformed admin-entered value must never break the whole hit.
 *
 * @param {string} hex
 * @param {number} amount
 * @returns {string}
 */
export function darkenHex(hex, amount) {
  const match = /^#?([0-9a-f]{6})$/i.exec(hex);
  if (!match) {
    return hex;
  }
  const num = parseInt(match[1], 16);
  const channel = shift => Math.round(((num >> shift) & 0xff) * (1 - amount));
  const toHex = value => value.toString(16).padStart(2, '0');
  return `#${toHex(channel(16))}${toHex(channel(8))}${toHex(channel(0))}`;
}
