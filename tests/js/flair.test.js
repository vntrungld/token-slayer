import { describe, expect, it } from 'vitest';
import { clearFlair, createFlairState, isFlairActive, startFlair } from '../../resources/js/battlefield/fighter/flair.js';

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
