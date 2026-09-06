import Phaser from 'phaser';
import { BG_COLOR, LAYOUTS, BOSS_TYPES } from '@battlefield/config.js';
import { BusEvent, TextureKey, SCENE_KEY } from '@battlefield/constants.js';
import { bus } from './bus.js';
import { Leaderboard } from './leaderboard.js';
import { Impact } from './impact.js';
import { Projectile } from './projectile.js';
import { Attacks } from './attacks.js';
import { Boss } from './boss.js';
import { Charge } from './charge.js';
import { Bubble } from './bubble.js';
import { MoveInput } from './move-input.js';
import { Fighter } from './fighter.js';
import { ensureSparkTexture } from './spark-texture.js';
import { registerAllFighterAnimations } from './fighter/animations.js';

/** Phaser scene coordinator — wires all battlefield managers and handles the Phaser lifecycle. */
export class BattlefieldScene extends Phaser.Scene {
  constructor() {
    super(SCENE_KEY);
  }

  /**
   * Loads all sprite sheets, atlases, and FX spritesheets needed by the scene.
   *
   * @return {void}
   */
  preload() {
    // Single atlas covers all 138 fighter strips
    if (!this.textures.exists(TextureKey.FIGHTERS)) {
      this.load.atlas(
        TextureKey.FIGHTERS,
        '/assets/battlefield/fighters/fighters-atlas.png',
        '/assets/battlefield/fighters/fighters-atlas.json',
      );
    }
    for (const boss of BOSS_TYPES) {
      if (boss.animFiles) {
        for (const [anim, info] of Object.entries(boss.animFiles)) {
          const texKey = `${boss.key}-${anim}`;
          if (!this.textures.exists(texKey))
            this.load.spritesheet(texKey, info.file, { frameWidth: info.frameWidth, frameHeight: info.frameHeight });
        }
      } else {
        if (!this.textures.exists(boss.key))
          this.load.spritesheet(boss.key, boss.file, { frameWidth: boss.frameWidth, frameHeight: boss.frameHeight });
      }
    }
    if (!this.textures.exists(TextureKey.FIREBALL))
      this.load.spritesheet(TextureKey.FIREBALL, '/assets/battlefield/fx/fireball.png', { frameWidth: 16, frameHeight: 16 });
    if (!this.textures.exists(TextureKey.EXPLOSION))
      this.load.spritesheet(TextureKey.EXPLOSION, '/assets/battlefield/fx/explosion.png', { frameWidth: 32, frameHeight: 32 });
    const loaderBar = document.getElementById('bf-loader-bar');
    const loader    = document.getElementById('bf-loader');
    this.load.on('progress', v => { if (loaderBar) loaderBar.style.width = Math.round(v * 100) + '%'; });
    this.load.on('complete', () => {
      if (loader) loader.style.display = 'none';
      const pixelArtKeys = [
        ...BOSS_TYPES.filter(b => b.pixelArt !== false).flatMap(b =>
          b.animFiles ? Object.keys(b.animFiles).map(anim => `${b.key}-${anim}`) : [b.key]
        ),
        TextureKey.FIREBALL, TextureKey.EXPLOSION,
      ];
      for (const key of pixelArtKeys) {
        this.textures.get(key).setFilter(Phaser.Textures.FilterMode.NEAREST);
      }
      // Fighter atlas: NEAREST filter + register all animations from named frames
      this.textures.get(TextureKey.FIGHTERS)?.setFilter(Phaser.Textures.FilterMode.NEAREST);
      registerAllFighterAnimations(this);
    });
  }

  /**
   * Creates and wires all battlefield managers, seeds initial state, and registers bus handlers.
   *
   * @return {void}
   */
  create() {
    this.isShuttingDown = false;
    this.mode = this.game.registry.get('mode') ?? 'landscape';
    this.layout = LAYOUTS[this.mode];
    const L = this.layout;

    // The canvas is created at logical size * renderScale (see index.js's
    // renderScaleFor) purely for pixel density; zooming the camera by the
    // same factor keeps the whole scene authored in logical coordinates.
    // centerOn is required: with zoom alone the camera's view stays anchored
    // on its own midpoint, which would show the wrong half of the world.
    const renderScale = this.game.registry.get('renderScale') ?? 1;
    this.cameras.main.setZoom(renderScale);
    this.cameras.main.centerOn(L.logicalWidth / 2, L.logicalHeight / 2);

    this.add.rectangle(L.logicalWidth / 2, L.logicalHeight / 2, L.logicalWidth, L.logicalHeight, BG_COLOR);
    ensureSparkTexture(this);
    this.add.image(L.logicalWidth / 2, L.logicalHeight / 2, this.makeVignetteTexture());

    const state = this.game.registry.get('initialState');
    this.boss = new Boss(this);
    this.boss.create(state);

    this.bubble = new Bubble(this);
    this.charge = new Charge(this);
    this.impact = new Impact(this);
    this.projectile = new Projectile(this);
    this.attacks = new Attacks(this);

    this.fighters = new Map();
    this.damageTotals = new Map();
    this.currentUserId = state.currentUserId ?? null;
    this.fighter = new Fighter(this);
    this.fighter.seedInitial(state);

    this.leaderboard = new Leaderboard(this);
    this.leaderboard.seed(state.leaderboard ?? []);

    this.charges = new Map();
    // Synthesizes the live `fighter-charging` payload shape — keep in sync with FighterCharging::broadcastWith().
    for (const f of state.fighters) {
      if (f.charging) {
        this.charge.handleCharging({
          user_id: f.id,
          activity: f.charging.activity,
        });
      }
    }

    this._busHandlers = {
      [BusEvent.HIT]:              payload => this.fighter.handleHit(payload),
      [BusEvent.BOSS_SPAWNED]:     payload => this.boss.handleBossSpawned(payload),
      [BusEvent.BOSS_KILLED]:      payload => this.boss.handleBossKilled(payload),
      [BusEvent.FIGHTER_CHARGING]: payload => this.charge.handleCharging(payload),
      [BusEvent.FIGHTER_IDLED]:    payload => this.fighter.handleIdled(payload),
      [BusEvent.FIGHTER_JOINED]:   payload => this.fighter.handleFighterJoined(payload),
      [BusEvent.FIGHTER_MOVED]:    payload => this.fighter.handleFighterMoved(payload),
      [BusEvent.POSITIONS_RESYNCED]: payload => this.fighter.reconcilePositions(payload.positions),
      [BusEvent.FIGHTER_CHARGE_CLEARED]: payload => this.charge.handleChargeCleared(payload),
      [BusEvent.CHARACTER_CHANGED]: payload => this.fighter.updateCharacters([payload]),
    };

    this.moveInput = new MoveInput(this);
    this.moveInput.setup();
    for (const [evt, fn] of Object.entries(this._busHandlers)) {
      bus.on(evt, fn);
    }
    this.events.once('shutdown', () => {
      this.isShuttingDown = true;
      for (const [evt, fn] of Object.entries(this._busHandlers)) {
        bus.off(evt, fn);
      }
      this.leaderboard?.destroy?.();
      this.tooltip = null;
      this.hoveredUserId = null;
    });

    this.events.emit('ready');
    this.game.events.emit('ready');
  }

  /**
   * Syncs world-space activity bubbles to their fighter containers every frame.
   *
   * @return {void}
   */
  update() {
    for (const [userId, entry] of this.fighters.entries()) {
      if (!entry.sprite?.active) continue;
      const charge = this.charges?.get(userId);
      if (!charge?.bubble) continue;
      const avatarRelY   = entry.head?.y ?? 0;
      const avatarRadius = (entry.head?.displayHeight ?? 28) / 2;
      charge.bubble.moveTo(entry.sprite.x, entry.sprite.y + avatarRelY - avatarRadius - 16);
    }
  }

  /**
   * Adds a Phaser Text object with LINEAR-filtered resolution-doubled rendering.
   *
   * @param {number} x
   * @param {number} y
   * @param {string} content
   * @param {object} style
   * @param {number} [resolution=2]
   * @return {Phaser.GameObjects.Text}
   */
  addSharpText(x, y, content, style, resolution = 2) {
    const text = this.add.text(x, y, content, style).setOrigin(0.5).setResolution(resolution);
    text.texture.setFilter(Phaser.Textures.FilterMode.LINEAR);
    const originalSetText = text.setText.bind(text);
    text.setText = (...args) => {
      const result = originalSetText(...args);
      text.texture.setFilter(Phaser.Textures.FilterMode.LINEAR);
      return result;
    };
    return text;
  }

  /** Creates and registers the radial vignette gradient texture for the current mode. */
  makeVignetteTexture() {
    const { logicalWidth: W, logicalHeight: H } = this.layout;
    const key = `bf-vignette-${this.mode}`;
    if (!this.textures.exists(key)) {
      const canvas = document.createElement('canvas');
      canvas.width = W;
      canvas.height = H;
      const ctx = canvas.getContext('2d');
      const grad = ctx.createRadialGradient(W / 2, H / 2, H * 0.18, W / 2, H / 2, H * 0.88);
      grad.addColorStop(0, 'rgba(0,0,0,0)');
      grad.addColorStop(1, 'rgba(0,0,0,0.62)');
      ctx.fillStyle = grad;
      ctx.fillRect(0, 0, W, H);
      this.textures.addCanvas(key, canvas);
    }
    return key;
  }

}
