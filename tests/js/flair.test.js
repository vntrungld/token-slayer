import { describe, expect, it } from 'vitest';
import { clearFlair, createFlairState, isFlairActive, resolveFlairDuration, startFlair } from '../../resources/js/battlefield/fighter/flair.js';

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
