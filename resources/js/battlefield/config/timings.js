export const TIMINGS = {
  projectileArcMs: 320,
  flinchMs: 120,
  hpBarMs: 250,
  cameraShake: { duration: 180, intensity: 0.003 },
  chargeRingPulseMs: 600,
  fighterJoinMs: 300,
  bossSpawnMs: 500,
  bossKilledMs: 400,
  // Flair halo. The server broadcasts a per-model duration and color on
  // HitDealt (admin-configured in ai_models); these two are only the
  // fallbacks used when a value is missing (an older cached client, or a
  // flair with no configured duration/color), via flair.js's
  // resolveFlairDuration and Fighter#applyFlair respectively.
  flairDurationMs: 6000,
  flairDefaultColor: '#fbbf24',
  // One lap of the orbit ring at rest (before/after a hit's spin-up boost).
  flairOrbitPeriodMs: 3200,
};
