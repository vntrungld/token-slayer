export const TIMINGS = {
  projectileArcMs: 320,
  flinchMs: 120,
  hpBarMs: 250,
  cameraShake: { duration: 180, intensity: 0.003 },
  chargeRingPulseMs: 600,
  fighterJoinMs: 300,
  bossSpawnMs: 500,
  bossKilledMs: 400,
  // Flair badge. The server broadcasts a per-model duration on HitDealt
  // (admin-configured in ai_models); flairDurationMs here is only the
  // fallback used when that value is missing (an older cached client, or a
  // flair with no configured duration), via flair.js's resolveFlairDuration.
  // 400ms is 2.5 flashes/second -- under the 3/second flashing-content
  // threshold, which matters on a display the team leaves open all day.
  flairDurationMs: 6000,
  flairBlinkMs: 400,
};
