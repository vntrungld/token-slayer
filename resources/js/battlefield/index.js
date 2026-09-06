import Phaser from 'phaser';
import { BattlefieldScene } from './scene.js';
import { LAYOUTS, BG_COLOR } from './config.js';
import { bus } from './bus.js';
import { snapshotState } from './snapshot.js';
import { computeHudTop } from './hud-position.js';
import { formatHp } from './format.js';
import { drawFighterPreview, drawFighterFrame } from './fighter/preview.js';
import { createPreviewGame, destroyPreviewGame } from './character-preview/game.js';
import { BusEvent, SCENE_KEY } from './constants.js';

const ECHO_EVENT_MAP = {
  HitDealt:        BusEvent.HIT,
  BossSpawned:     BusEvent.BOSS_SPAWNED,
  BossKilled:      BusEvent.BOSS_KILLED,
  FighterJoined:   BusEvent.FIGHTER_JOINED,
  FighterCharging: BusEvent.FIGHTER_CHARGING,
  FighterIdled:    BusEvent.FIGHTER_IDLED,
  FighterMoved:    BusEvent.FIGHTER_MOVED,
  FighterChargeCleared: BusEvent.FIGHTER_CHARGE_CLEARED,
  FighterCharacterChanged: BusEvent.CHARACTER_CHANGED,
};

const ECHO_RETRY_INTERVAL_MS = 200;
const ECHO_RETRY_TIMEOUT_MS = 10_000;

let echoChannel = null;
let echoRetryInterval = null;
let resyncBound = false;

function attachEchoListeners() {
  if (echoChannel) {
    for (const evt of Object.keys(ECHO_EVENT_MAP)) {
      echoChannel.stopListening('.' + evt);
    }
  }
  echoChannel = window.Echo.channel('battlefield');
  for (const [evt, key] of Object.entries(ECHO_EVENT_MAP)) {
    echoChannel.listen('.' + evt, payload => bus.emit(key, payload));
  }
  bindResyncOnReconnect();
}

/**
 * Wires a one-time listener so that whenever the Echo/Reverb connection
 * (re)establishes, the Battlefield Livewire component is asked for the
 * current authoritative fighter positions. Reverb broadcasts have no replay,
 * so a `FighterMoved` that fired while this tab's WebSocket was disconnected
 * is otherwise lost forever for this one client; requesting a resync repairs
 * whatever drifted instead of leaving it wrong until the next full reload.
 *
 * @return {void}
 */
function bindResyncOnReconnect() {
  if (resyncBound || !window.Echo?.connector?.pusher?.connection) {
    return;
  }
  resyncBound = true;

  window.Livewire.on('battlefield-resynced', ({ positions }) => {
    bus.emit(BusEvent.POSITIONS_RESYNCED, { positions });
  });

  window.Echo.connector.pusher.connection.bind('connected', () => {
    window.Livewire.dispatch('request-resync');
  });
}

function subscribeEcho() {
  if (window.Echo) {
    attachEchoListeners();
    return;
  }
  if (echoRetryInterval) {
    return;
  }
  const start = Date.now();
  echoRetryInterval = setInterval(() => {
    if (window.Echo) {
      clearInterval(echoRetryInterval);
      echoRetryInterval = null;
      attachEchoListeners();
    } else if (Date.now() - start > ECHO_RETRY_TIMEOUT_MS) {
      clearInterval(echoRetryInterval);
      echoRetryInterval = null;
      console.warn('[battlefield] window.Echo not available after retries; events will not be received');
    }
  }, ECHO_RETRY_INTERVAL_MS);
}

/**
 * Returns 'portrait' or 'landscape' based on current viewport dimensions.
 *
 * @return {string}
 */
export function detectMode() {
  return window.innerWidth < window.innerHeight ? 'portrait' : 'landscape';
}

/**
 * How many real canvas pixels to render per logical pixel.
 *
 * The scene is authored in a fixed logical coordinate space (960x540, see
 * LAYOUTS) and Scale.FIT stretches that canvas to fill whatever space the
 * page gives it. On a wide display that stretch is close to 2x -- measured
 * live at 1863 CSS px for a 960px canvas -- so every pixel the game drew was
 * being blown up nearly double, which is what made avatars (and everything
 * else) look soft and blocky next to crisp pixel-art sprites.
 *
 * Rendering the canvas at `logical * scale` and zooming the camera by the
 * same factor keeps every coordinate in the codebase logical while giving
 * the renderer enough real pixels to land roughly 1:1 on screen. Derived
 * from the space actually available (times devicePixelRatio) rather than
 * fixed, so small screens don't pay for pixels they can't show; capped
 * because past ~2.5x the cost stops buying visible sharpness.
 *
 * @param {HTMLElement} mount
 * @param {{logicalWidth: number}} layout
 * @return {number}
 */
function renderScaleFor(mount, layout) {
  const available = (mount?.clientWidth || window.innerWidth) * (window.devicePixelRatio || 1);
  return Math.min(2.5, Math.max(1, available / layout.logicalWidth));
}

function bootGame(mount, state, mode) {
  const layout = LAYOUTS[mode];
  const renderScale = renderScaleFor(mount, layout);
  const game = new Phaser.Game({
    type: Phaser.AUTO,
    parent: mount,
    width: Math.round(layout.logicalWidth * renderScale),
    height: Math.round(layout.logicalHeight * renderScale),
    backgroundColor: BG_COLOR,
    pixelArt: false,
    antialias: true,
    scale: { mode: Phaser.Scale.FIT, autoCenter: Phaser.Scale.CENTER_BOTH },
    scene: [BattlefieldScene],
  });
  game.registry.set('initialState', state);
  game.registry.set('mode', mode);
  game.registry.set('renderScale', renderScale);

  game.events.once('ready', () => {
    subscribeEcho();
    const scene = game.scene.getScene(SCENE_KEY);
    window.__battlefield = {
      bus,
      game,
      scene,
      get mode() { return game.registry.get('mode'); },
      // The authored coordinate space, NOT game.scale.gameSize (which is now
      // the higher-resolution canvas). Anything positioning DOM overlays
      // against the canvas -- the Damage HUD in battlefield.blade.php --
      // must scale against this, or it silently shrinks by renderScale.
      logicalWidth: layout.logicalWidth,
      logicalHeight: layout.logicalHeight,
      renderScale,
      bossHp: () => scene.bossState?.currentHp,
      bossMaxHp: () => scene.bossState?.maxHp,
      computeHudTop,
      formatHp,
      drawFighterPreview,
      drawFighterFrame,
      createCharacterPreview: createPreviewGame,
      destroyCharacterPreview: destroyPreviewGame,
    };
  });

  return game;
}

// Module-level cleanup — removes the previous bootBattlefield's resize listeners.
let _cleanupResize = null;

/**
 * Boots the Phaser battlefield game and wires up resize/orientation listeners.
 *
 * @param {HTMLElement} mount
 * @param {object} state
 * @return {Phaser.Game}
 */
export function bootBattlefield(mount, state) {
  _cleanupResize?.();
  _cleanupResize = null;

  let currentMode = detectMode();
  let currentState = state;
  let currentGame = bootGame(mount, currentState, currentMode);
  let pending = null;
  let destroyed = false;

  const applyModeChange = (next) => {
    currentMode = next;
    const layout = LAYOUTS[next];
    const scene = currentGame.scene.getScene('battlefield');
    currentState = snapshotState(currentState, scene);
    currentGame.scale.setGameSize(layout.logicalWidth, layout.logicalHeight);
    currentGame.registry.set('mode', next);
    currentGame.registry.set('initialState', currentState);
    scene.scene.restart();
  };

  // Desktop resize: immediately refresh FIT scale so the canvas tracks the
  // new viewport size in real-time, then check for a mode flip after 300ms.
  const onResize = () => {
    if (destroyed) return;
    currentGame.scale.refresh(); // keep canvas CSS in sync immediately
    clearTimeout(pending);
    pending = setTimeout(() => {
      if (destroyed) return;
      const next = detectMode();
      if (next === currentMode) return;
      showBfLoader();
      applyModeChange(next);
    }, 300);
  };

  // Orientation change: immediately cover the screen so the 300 ms where
  // Phaser auto-rescales to the wrong aspect ratio is hidden behind the loader.
  const onOrientationChange = () => {
    if (destroyed) return;
    showBfLoader();
    clearTimeout(pending);
    pending = setTimeout(() => {
      if (destroyed) return;
      const next = detectMode();
      if (next === currentMode) {
        // Spurious event — restore the game, hide loader.
        hideBfLoader();
        return;
      }
      applyModeChange(next);
    }, 300);
  };

  window.addEventListener('resize', onResize);
  window.addEventListener('orientationchange', onOrientationChange);

  _cleanupResize = () => {
    destroyed = true;
    clearTimeout(pending);
    window.removeEventListener('resize', onResize);
    window.removeEventListener('orientationchange', onOrientationChange);
  };

  currentGame.events.once('destroy', () => { _cleanupResize?.(); });

  return currentGame;
}

function showBfLoader() {
  const el = document.getElementById('bf-loader');
  if (el) el.style.display = 'flex';
}

function hideBfLoader() {
  const el = document.getElementById('bf-loader');
  if (el) el.style.display = 'none';
}

window.bootBattlefield = bootBattlefield;
