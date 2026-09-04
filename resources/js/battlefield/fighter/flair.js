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
