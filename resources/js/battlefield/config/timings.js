export const TIMINGS = {
  projectileArcMs: 320,
  flinchMs: 120,
  hpBarMs: 250,
  cameraShake: { duration: 180, intensity: 0.003 },
  chargeRingPulseMs: 600,
  fighterJoinMs: 300,
  bossSpawnMs: 500,
  bossKilledMs: 400,
  // Flair badge. 400ms is 2.5 flashes/second -- under the 3/second
  // flashing-content threshold, which matters on a display the team leaves
  // open all day. Not server config: nothing delivers a config value to the
  // browser, so it would drift from the visible behaviour.
  flairDurationMs: 6000,
  flairBlinkMs: 400,
};
