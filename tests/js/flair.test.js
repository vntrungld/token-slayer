import { describe, expect, it } from 'vitest';
import {
  buildRingChars,
  clearFlair,
  createFlairState,
  darkenHex,
  hasFlairChanged,
  isFlairActive,
  resolveFlairDuration,
  spinMultiplier,
  spotlightBoost,
  startFlair,
} from '../../resources/js/battlefield/fighter/flair.js';

describe('flair lifecycle', () => {
  it('is inactive until a flair starts', () => {
    expect(isFlairActive(createFlairState(), 1000)).toBe(false);
  });

  it('stays active for the configured duration', () => {
    const state = startFlair(createFlairState(), 'fable', 1000, 6000);
    expect(isFlairActive(state, 6999)).toBe(true);
    expect(isFlairActive(state, 7001)).toBe(false);
  });

  it('resets the timer when the same flair fires again', () => {
    let state = startFlair(createFlairState(), 'fable', 1000, 6000);
    state = startFlair(state, 'fable', 5000, 6000);
    expect(isFlairActive(state, 10000)).toBe(true);
  });

  it('is untouched by a hit that carries no flair', () => {
    // A Sonnet turn landing mid-badge must not cut the Fable effect short:
    // the flair's lifetime is owned by its own timer, never by the next hit.
    let state = startFlair(createFlairState(), 'fable', 1000, 6000);
    state = startFlair(state, null, 3000, 6000);
    expect(isFlairActive(state, 6500)).toBe(true);
    expect(isFlairActive(state, 7001)).toBe(false);
  });

  it('keeps the original flair key when a null hit passes through', () => {
    let state = startFlair(createFlairState(), 'fable', 1000, 6000);
    state = startFlair(state, null, 3000, 6000);
    expect(state.flair).toBe('fable');
  });

  it('clears on teardown', () => {
    const state = clearFlair(startFlair(createFlairState(), 'fable', 1000, 6000));
    expect(isFlairActive(state, 2000)).toBe(false);
  });

  it('does not mutate the state it was given', () => {
    const original = createFlairState();
    startFlair(original, 'fable', 1000, 6000);
    expect(isFlairActive(original, 1500)).toBe(false);
  });
});

describe('resolveFlairDuration', () => {
  it('uses the server-supplied per-model duration when present', () => {
    // Duration is now per-model admin config broadcast on the payload, not a
    // fixed constant -- a model configured for 9000ms must not be clamped to
    // whatever the client's own fallback happens to be.
    expect(resolveFlairDuration(9000, 6000)).toBe(9000);
  });

  it('falls back to the client default when the payload carries none', () => {
    // An older cached client, or a flair with no configured duration.
    expect(resolveFlairDuration(null, 6000)).toBe(6000);
    expect(resolveFlairDuration(undefined, 6000)).toBe(6000);
  });

  it('falls back on a non-positive duration rather than trusting it blindly', () => {
    expect(resolveFlairDuration(0, 6000)).toBe(6000);
    expect(resolveFlairDuration(-500, 6000)).toBe(6000);
  });
});

describe('buildRingChars', () => {
  it('always tiles a whole number of repeats, never a partial word at the seam', () => {
    // A remainder here would splice two different letters together where lap
    // N wraps back to phase 0 -- e.g. "FABLE" cut into "FAB"+"LE" mid-ring.
    const unitLength = 'FABLE  ✦  '.length;
    const chars = buildRingChars('FABLE');
    expect(chars.length % unitLength).toBe(0);
  });

  it('produces enough characters to read as a flowing ring, not sparse dots', () => {
    expect(buildRingChars('FABLE').length).toBeGreaterThanOrEqual(15);
  });

  it('repeats the label correctly regardless of word length', () => {
    const chars = buildRingChars('OPUS');
    const word = chars.map(c => c.ch).join('');
    expect(word).toContain('OPUS');
  });

  it('walks phase backwards so the front arc reads left-to-right, not mirrored', () => {
    // In the front/bottom arc (sinA >= 0) screen-x DECREASES as angle
    // increases, so phase must decrease as reading-order index increases --
    // otherwise the most visible arc reads words backwards ("OPUS" -> "SUPO").
    const chars = buildRingChars('OPUS');
    expect(chars[1].phase).toBeLessThan(chars[0].phase);
  });

  it('spaces every ring the same regardless of label length', () => {
    // Angular step must be a property of the ring, not of the word -- a short
    // and a long label should read at the same visual density.
    const short = buildRingChars('GPT');
    const long = buildRingChars('SONNET');
    const stepOf = chars => Math.abs(chars[1].phase - chars[0].phase);
    expect(stepOf(short)).toBeCloseTo(stepOf(long), 1);
  });
});

describe('spinMultiplier', () => {
  it('whips the orbit fast in the instant right after a hit', () => {
    expect(spinMultiplier(0)).toBeGreaterThan(3);
  });

  it('eases back down to cruise speed (1x) by the end of the boost window', () => {
    expect(spinMultiplier(1000)).toBeCloseTo(1, 5);
  });

  it('is exactly cruise speed outside the boost window in either direction', () => {
    expect(spinMultiplier(-1)).toBe(1);
    expect(spinMultiplier(1001)).toBe(1);
  });

  it('decreases monotonically across the boost window', () => {
    expect(spinMultiplier(200)).toBeGreaterThan(spinMultiplier(600));
    expect(spinMultiplier(600)).toBeGreaterThan(spinMultiplier(900));
  });
});

describe('spotlightBoost', () => {
  it('peaks at the front-center sweet spot', () => {
    expect(spotlightBoost(Math.PI / 2)).toBeCloseTo(1, 5);
  });

  it('is much dimmer on the far side of the ring than at the peak', () => {
    expect(spotlightBoost(-Math.PI / 2)).toBeLessThan(spotlightBoost(Math.PI / 2) * 0.4);
  });

  it('never goes negative', () => {
    for (let a = 0; a < Math.PI * 2; a += 0.3) {
      expect(spotlightBoost(a)).toBeGreaterThanOrEqual(0);
    }
  });
});

describe('darkenHex', () => {
  it('returns black at full darken amount', () => {
    expect(darkenHex('#ffffff', 1)).toBe('#000000');
  });

  it('returns the same color at zero darken amount', () => {
    expect(darkenHex('#fbbf24', 0)).toBe('#fbbf24');
  });

  it('halves each channel at amount 0.5', () => {
    expect(darkenHex('#ffffff', 0.5)).toBe('#808080');
  });

  it('passes through a value that is not a hex color, rather than throwing', () => {
    expect(darkenHex('not-a-color', 0.5)).toBe('not-a-color');
  });
});

describe('hasFlairChanged', () => {
  it('is false when the same flair and color refresh mid-flight', () => {
    // A repeat hit on the SAME model must not rebuild the ring -- that would
    // restart its orbit position/momentum for no reason.
    expect(hasFlairChanged({ flair: 'fable', color: '#fbbf24' }, { flair: 'fable', color: '#fbbf24' })).toBe(false);
  });

  it('is true when a different model takes over mid-flight', () => {
    // Without this, the ring keeps showing the OLD model's name/color until
    // the whole flair window lapses naturally.
    expect(hasFlairChanged({ flair: 'opus', color: '#818cf8' }, { flair: 'fable', color: '#fbbf24' })).toBe(true);
  });

  it('is true when only the color changed (admin re-saved the same model with a new color)', () => {
    expect(hasFlairChanged({ flair: 'fable', color: '#fbbf24' }, { flair: 'fable', color: '#a855f7' })).toBe(true);
  });

  it('is true for the very first flair (no previous ring)', () => {
    expect(hasFlairChanged({ flair: null, color: null }, { flair: 'fable', color: '#fbbf24' })).toBe(true);
  });
});
