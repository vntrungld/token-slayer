import Phaser from 'phaser';
import { FIGHTER_TYPES, TIMINGS } from '@battlefield/config.js';
import { computeFighterPositions, damageScaleMultiplier, fighterDisplayConfig } from '@battlefield/layout.js';
import { AnimState, AttackType, TextureKey } from '@battlefield/constants.js';
import { Boss } from '@battlefield/boss.js';
import { planRoute } from '@battlefield/move-geometry.js';
import { resolveFighterPlacement } from '@battlefield/fighter-placement.js';
import { driftedPositions } from '@battlefield/resync.js';
import { loadAvatarTexture, makeFallbackAvatarTexture } from './avatar.js';
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
} from './flair.js';

// Tiny RPG sprite geometry constants — do not change without re-measuring the atlas.
const SPRITE_CHAR_HEIGHT = 18;
const SPRITE_HALF_FRAME  = 50;
const SPRITE_CHAR_TOP    = 38;
const SPRITE_CHAR_BOT    = 56;

const HANDLE_MAX_CHARS = 12;

/** @param {string} handle @param {number} maxChars @return {string} */
function truncateHandle(handle, maxChars = HANDLE_MAX_CHARS) {
  if (!handle || handle.length <= maxChars) {
    return handle ?? '';
  }
  return handle.slice(0, maxChars - 1) + '…';
}

/** @param {number} displaySize @return {number} */
function handleFontPx(displaySize) {
  return Math.max(10, Math.round(displaySize * 0.25));
}

/** Returns logical avatar pixel size from fighter display size. @param {number} displaySize @return {number} */
export function avatarPx(displaySize) {
  return Math.round(displaySize * 0.85);
}

/** Manages the full fighter lifecycle: join, move, hit, relayout, avatar loading, and scaling. */
export class Fighter {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene = scene;
  }

  /**
   * Returns the canonical display scale for a fighter based on size and damage.
   *
   * @param {{ displaySize: number, baseSize: number, damageScale?: number }} fighter
   * @return {number}
   */
  static fighterRestScale(fighter) {
    return (fighter.displaySize / fighter.baseSize) * (fighter.damageScale ?? 1);
  }

  /**
   * Seeds the scene with initial fighters and restores damage totals from state.
   *
   * @param {{ fighters: Array, damageTotals?: Array }} state
   * @return {void}
   */
  seedInitial(state) {
    const L = this.scene.layout;
    const config = fighterDisplayConfig(state.fighters.length, this.scene.mode);
    const autoPositions = computeFighterPositions(
      state.fighters.length,
      L.fighters.rowXRange,
      config.topY,
      config.perRow,
      config.rowSpacing,
    );
    const bossType = Boss.bossTypeFor(this.scene.bossState?.number ?? 0);
    const damageByUser = new Map(state.damageTotals ?? []);
    state.fighters.forEach((f, i) => {
      const damageScale = damageScaleMultiplier(damageByUser.get(f.id) ?? 0, this.scene.bossState?.maxHp);
      const ctx = {
        layout: L,
        bossType,
        fsize: config.displaySize * damageScale,
      };
      const { pos, isCustom } = resolveFighterPlacement(f.position, autoPositions[i], ctx);
      this.addFighter(f, pos, config);
      if (isCustom) {
        this.scene.fighters.get(f.id).hasCustomPosition = true;
      }
    });
    for (const [userId, damage] of state.damageTotals ?? []) {
      this.scene.damageTotals.set(userId, damage);
      this.rescaleFighterByDamage(userId);
    }
  }

  /**
   * Handles the fighter-joined event payload, adding the fighter to the scene.
   * A rejoining fighter carries its persisted position and is restored there
   * rather than placed in the next free grid slot.
   *
   * @param {{ user_id: number|string, slack_handle?: string, display_name?: string, character?: string, position?: {x: number, y: number}|null }} payload
   * @return {void}
   */
  handleFighterJoined(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    if (this.scene.fighters.has(payload.user_id)) {
      return;
    }
    const fighter = {
      id: payload.user_id,
      handle: payload.slack_handle,
      display_name: payload.display_name ?? null,
      character: payload.character ?? null,
    };

    const count = this.scene.fighters.size + 1;
    const config = fighterDisplayConfig(count, this.scene.mode);
    const positions = computeFighterPositions(
      count,
      this.scene.layout.fighters.rowXRange,
      config.topY,
      config.perRow,
      config.rowSpacing,
    );

    const damageScale = damageScaleMultiplier(
      this.scene.damageTotals.get(payload.user_id) ?? 0,
      this.scene.bossState?.maxHp,
    );
    const { pos, isCustom } = resolveFighterPlacement(payload.position, positions[positions.length - 1], {
      layout: this.scene.layout,
      bossType: Boss.bossTypeFor(this.scene.bossState?.number ?? 0),
      fsize: config.displaySize * damageScale,
    });
    this.addFighter(fighter, pos, config);

    const entry = this.scene.fighters.get(fighter.id);
    if (!entry) {
      return;
    }
    // Must be flagged before relayoutFighters(), which grid-snaps every
    // fighter not already marked as custom-positioned.
    entry.hasCustomPosition = isCustom;
    this.relayoutFighters();

    const finalScale = entry.sprite.scaleX;
    entry.sprite.setScale(0);
    this.scene.tweens.add({
      targets: entry.sprite,
      scale: finalScale,
      duration: TIMINGS.fighterJoinMs,
      ease: 'Back.easeOut',
    });
  }

  /**
   * Re-skins each listed fighter's sprite to the given character.
   * characterForBoss() is deterministic per (user, boss), so every existing
   * fighter needs this when the boss changes — not just newly-joined ones,
   * which already get their correct character from handleFighterJoined.
   *
   * @param {Array<{user_id: number|string, character: string}>} roster
   * @return {void}
   */
  updateCharacters(roster) {
    for (const { user_id: userId, character } of roster ?? []) {
      const entry = this.scene.fighters.get(userId);
      if (!entry || !character || entry.ftype?.key === character) {
        continue;
      }
      const ftype = FIGHTER_TYPES.find(ft => ft.key === character);
      if (!ftype) {
        continue;
      }
      entry.ftype = ftype;
      entry.animState = AnimState.IDLE;
      entry.body.setTexture(TextureKey.FIGHTERS, `${ftype.key}-idle-0`);
      const idleAnim = this.scene.anims.get(`${ftype.key}-idle`);
      if (idleAnim?.frames?.length) {
        entry.body.play(`${ftype.key}-idle`);
      }
    }
  }

  /**
   * Adds a fighter sprite and label to the scene at the given position.
   *
   * @param {{ id: number|string, handle?: string, slack_handle?: string, display_name?: string, character?: string }} fighter
   * @param {{ x: number, y: number }} pos
   * @param {{ displaySize?: number, showHandle?: boolean }} options
   * @return {void}
   */
  addFighter(fighter, pos, options = {}) {
    const size = options.displaySize ?? 48;

    // Pick character type by fighter.character key, fall back to id modulo
    const ftypeKey = fighter.character ?? null;
    const ftype = (ftypeKey && FIGHTER_TYPES.find(ft => ft.key === ftypeKey))
      ?? FIGHTER_TYPES[Math.abs(Number(fighter.id) || 0) % FIGHTER_TYPES.length];
    // Scale so the visible character (18px of the 100px frame) fills `size` logical px
    const scale     = size / SPRITE_CHAR_HEIGHT;
    const legH      = Math.round((SPRITE_CHAR_BOT - SPRITE_HALF_FRAME) * scale);
    const avatarY   = -Math.round((SPRITE_HALF_FRAME - SPRITE_CHAR_TOP) * scale) - 38;
    const avSize    = avatarPx(size);
    const fontPx    = handleFontPx(size);
    const maxChars  = Math.max(8, Math.round(size * 0.22));
    const displayName = fighter.handle || fighter.slack_handle || fighter.display_name || '';

    const container = this.scene.add.container(pos.x, pos.y).setDepth(2);

    // Body sprite — starts in idle animation (waiting state)
    const body = this.scene.add.sprite(0, 0, TextureKey.FIGHTERS, `${ftype.key}-idle-0`).setScale(scale);
    const idleAnim = this.scene.anims.get(ftype.key + '-idle');
    if (idleAnim?.frames?.length) {
      body.play(ftype.key + '-idle');
    }
    container.add(body);
    const avatarUrl = fighter.id ? `/avatars/${fighter.id}?v=${Date.now()}` : null;
    const initialKey = this.scene.textures.exists(`fighter-${fighter.id}`)
      ? `fighter-${fighter.id}`
      : makeFallbackAvatarTexture(this.scene, fighter);
    const head = this.scene.add.image(0, avatarY, initialKey).setDisplaySize(avSize, avSize);
    head.setInteractive({ useHandCursor: true });
    head.on('pointerover', () => this.scene.bubble?.showFighterTooltip?.(fighter.id));
    head.on('pointerout', () => this.scene.bubble?.hideFighterTooltip?.(fighter.id));
    container.add(head);

    // Handle label (world-space)
    const handle = options.showHandle === false
      ? null
      : this.scene.addSharpText(pos.x, pos.y + legH + fontPx, truncateHandle(displayName, maxChars), {
          fontFamily: 'monospace',
          fontSize: `${fontPx}px`,
          color: '#fbbf24',
        });

    this.scene.fighters.set(fighter.id, {
      id: fighter.id,
      sprite: container,
      body,
      head,
      handle,
      handleText: displayName,
      pos,
      baseSize: size,
      displaySize: size,
      avatarSize: avSize,
      avatarUrl,
      legH,
      ftype,
      damageScale: 1,
      animState: AnimState.IDLE,
      isStunned: false,
      lastStunAt: null,
      waypointMoving: false,
      hasCustomPosition: false,
      rescaleTween: null,
    });

    if (initialKey !== `fighter-${fighter.id}` && avatarUrl) {
      loadAvatarTexture(this.scene, fighter.id, avatarUrl).then(realKey => {
        if (head.scene) {
          head.setTexture(realKey).setDisplaySize(avSize, avSize);
        }
      }).catch(e => console.warn('[battlefield]', e.message));
    }
  }

  /**
   * Handles the fighter-moved event, tweening the fighter to the new position.
   *
   * @param {{ user_id: number|string, x: number, y: number }} payload
   * @return {void}
   */
  handleFighterMoved(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    const entry = this.scene.fighters.get(payload.user_id);
    if (!entry) {
      return;
    }
    if (entry.isStunned) {
      return;
    }
    // Skip server echo while local waypoint animation is in progress for own fighter
    if (entry.waypointMoving && payload.user_id === this.scene.currentUserId) {
      return;
    }

    const raw = {
      x: payload.x * this.scene.layout.logicalWidth,
      y: payload.y * this.scene.layout.logicalHeight,
    };
    const ctx = {
      layout: this.scene.layout,
      bossType: Boss.bossTypeFor(this.scene.bossState?.number ?? 0),
      fsize: entry.displaySize * (entry.damageScale ?? 1),
    };
    const route = planRoute(entry.sprite.x, entry.sprite.y, raw.x, raw.y, ctx)
      ?? [{ x: entry.sprite.x, y: entry.sprite.y }];

    // Kill any in-progress move tweens before starting new ones
    this.scene.tweens.killTweensOf(entry.sprite);
    if (entry.handle) {
      this.scene.tweens.killTweensOf(entry.handle);
    }

    this._animateMoveRoute(entry, route);
  }

  /**
   * Tweens a fighter's sprite, handle, and charge trail through the given
   * waypoint list — mirrors MoveInput's local route animation so remote
   * viewers see the same detour the mover planned, instead of a straight
   * line that clips the boss/HP-bar column.
   *
   * @param {object} entry
   * @param {Array<{x: number, y: number}>} route
   * @return {void}
   */
  _animateMoveRoute(entry, route) {
    const SPEED_PX_PER_SEC = 300;

    const step = (idx) => {
      if (!entry.sprite?.active || idx >= route.length) {
        return;
      }
      const target = route[idx];
      const dx = target.x - entry.sprite.x;
      const dy = target.y - entry.sprite.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      const duration = Math.max(200, Math.round((dist / SPEED_PX_PER_SEC) * 1000));

      // Flip toward movement direction; fall back to boss-facing when barely horizontal
      const flipX = dx < -5 ? true : (dx > 5 ? false : target.x > this.scene.layout.boss.anchor.x);

      // Start walk animation (unless mid-attack)
      if (entry.body && entry.animState !== AnimState.ATTACK && entry.ftype) {
        entry.animState = AnimState.WALK;
        entry.body.setFlipX(flipX);
        entry.body.play(entry.ftype.key + '-walk', true);
      }

      this.scene.tweens.add({
        targets: entry.sprite,
        x: target.x,
        y: target.y,
        duration,
        ease: 'Linear',
        onComplete: () => {
          if (!entry.sprite?.active) {
            return;
          }
          if (idx === route.length - 1) {
            entry.pos = target;
            entry.hasCustomPosition = true;
            if (entry.body && entry.animState !== AnimState.ATTACK) {
              const isCharging = this.scene.charges.has(entry.id);
              const next = isCharging ? AnimState.WALK : AnimState.IDLE;
              entry.animState = next;
              entry.body.setFlipX(next === AnimState.WALK ? target.x > this.scene.layout.boss.anchor.x : false);
              entry.body.play(entry.ftype.key + '-' + next, true);
            }
          }
          step(idx + 1);
        },
      });

      if (entry.handle) {
        this.scene.tweens.killTweensOf(entry.handle);
        const scale  = entry.sprite.scaleX;
        const fontPx = handleFontPx(entry.displaySize);
        this.scene.tweens.add({
          targets: entry.handle,
          x: target.x,
          y: target.y + entry.legH * scale + fontPx,
          duration,
          ease: 'Linear',
        });
      }

      const charge = this.scene.charges.get(entry.id);
      if (charge?.trail?.scene) {
        this.scene.tweens.killTweensOf(charge.trail);
        const tb = target.x <= this.scene.layout.boss.anchor.x ? 1 : -1;
        const cb = Math.round(entry.displaySize / 3);
        this.scene.tweens.add({
          targets: charge.trail,
          x: target.x - tb * Math.round(entry.displaySize * 0.18),
          y: target.y + cb - Math.round(entry.displaySize * 0.12),
          duration,
          ease: 'Linear',
        });
      }
    };

    step(0);
  }

  /**
   * Repairs any fighters whose local position has drifted from the
   * server-authoritative snapshot returned by `Battlefield::resync()` — the
   * fix for Reverb's lack of event replay, where a `FighterMoved` broadcast
   * that fires while this client is disconnected is otherwise lost forever
   * for this one viewer. Silently corrects only the fighters that actually
   * drifted, leaving everyone already in sync untouched.
   *
   * @param {Array<{user_id: number|string, x: number, y: number}>} serverPositions
   * @return {void}
   */
  reconcilePositions(serverPositions) {
    if (!Array.isArray(serverPositions) || serverPositions.length === 0) {
      return;
    }
    const L = this.scene.layout;
    const localFighters = [];
    for (const [id, entry] of this.scene.fighters.entries()) {
      localFighters.push({
        id,
        x: entry.pos.x / L.logicalWidth,
        y: entry.pos.y / L.logicalHeight,
        waypointMoving: entry.waypointMoving,
      });
    }
    for (const server of driftedPositions(localFighters, serverPositions)) {
      this.handleFighterMoved({ user_id: server.user_id, x: server.x, y: server.y });
    }
  }

  /**
   * Handles the fighter-idled event, clearing charge and removing the fighter.
   *
   * @param {{ user_id: number|string }} payload
   * @return {void}
   */
  handleIdled(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    const userId = payload.user_id;
    this.scene.charge?.clearCharge?.(userId);
    this.removeFighter(userId);
  }

  /**
   * Removes a fighter from the scene with a fade-out tween.
   *
   * @param {number|string} userId
   * @return {void}
   */
  /**
   * Shows or refreshes a fighter's flair halo for this hit: a ring of glyphs
   * spelling the model's name, orbiting the whole fighter (not just the head,
   * where it would compete with the activity bubble for the same patch of
   * screen), plus a one-shot burst.
   *
   * @param {object} fighter
   * @param {?string} flair
   * @param {?number} flairDurationMs  server-broadcast duration for this model,
   *   or null to fall back to the client default (an older cached client, or a
   *   flair with no configured duration)
   * @param {?string} flairColor  server-broadcast (admin-configured) hex color,
   *   or null to fall back to the client default (an older cached client)
   * @return {void}
   */
  applyFlair(fighter, flair, flairDurationMs, flairColor) {
    const now = this.scene.time.now;
    const durationMs = resolveFlairDuration(flairDurationMs, TIMINGS.flairDurationMs);
    const previous = { flair: fighter.flairState?.flair ?? null, color: fighter.flairColor ?? null };
    fighter.flairState = startFlair(fighter.flairState ?? createFlairState(), flair, now, durationMs);

    if (!isFlairActive(fighter.flairState, now)) {
      return;
    }

    const next = { flair: fighter.flairState.flair, color: flairColor ?? fighter.flairColor ?? TIMINGS.flairDefaultColor };
    fighter.flairColor = next.color;

    // A different model taking over mid-flight (or the same model saved with
    // a new admin color) must replace the ring's content, not just leave the
    // old glyphs spinning under the new state.
    if (!fighter.flairRing || hasFlairChanged(previous, next)) {
      this.startFlairRing(fighter);
    }

    if (flair) {
      // Only a genuinely flair-triggering hit re-bursts and spins the ring
      // up -- an ordinary hit landing while a prior flair is still counting
      // down (startFlair leaves that state untouched) must not replay the
      // full VFX stack on every hit.
      fighter.flairLastBurstAt = now;
      this.burstFlair(fighter);
    }

    fighter.flairTimer?.remove();
    // Resynced to the state's own expiry rather than re-deriving a fresh
    // `durationMs` countdown here: a hit with no flair leaves expiresAt
    // untouched, and rescheduling from `now` on every such hit would
    // silently extend the visible flair well past its real duration.
    const remainingMs = Math.max(0, fighter.flairState.expiresAt - now);
    fighter.flairTimer = this.scene.time.delayedCall(remainingMs, () => this.destroyFlair(fighter));
  }

  /**
   * Creates the orbiting ring of glyphs (the model's name, repeated
   * marquee-style — see flair.js's buildRingChars) and starts the per-tick
   * updater that keeps it circling the fighter for the rest of the flair's
   * lifetime. World-space Text objects rather than children of
   * `fighter.sprite`, matching boss/stun.js's orbiting-star precedent:
   * a container renders all its children at one depth, but half the ring
   * must render BEHIND the fighter and half in FRONT of it each frame.
   *
   * @param {object} fighter
   * @return {void}
   */
  startFlairRing(fighter) {
    // Defensive: a ring being rebuilt mid-flight (a different flair took
    // over) must never leave the old ticker/glyphs orphaned.
    this.stopFlairRing(fighter);

    const label = fighter.flairState.flair.toUpperCase();
    const scale = (fighter.displaySize ?? 45) / 45;
    const fontPx = Math.max(8, Math.round(9 * scale));
    const deep = darkenHex(fighter.flairColor, 0.65);

    fighter.flairRing = buildRingChars(label).map(({ ch, phase }) => ({
      ch,
      phase,
      text: this.scene
        .add.text(0, 0, ch, {
          fontFamily: 'monospace', fontSize: `${fontPx}px`, color: fighter.flairColor,
          stroke: deep, strokeThickness: 2,
        })
        .setOrigin(0.5)
        .setDepth(1),
    }));
    fighter.flairAngle = 0;
    fighter.flairRingTicker = this.scene.time.addEvent({
      delay: 16,
      loop: true,
      callback: () => this.updateFlairRing(fighter),
    });
  }

  /**
   * Per-tick position/depth/scale update for one fighter's orbit ring.
   * Advances the shared orbit angle by real elapsed time (not a fixed step),
   * sped up by {@see spinMultiplier} right after a triggering hit, and
   * places each glyph on an ellipse around the fighter: the near/front arc
   * (sinA >= 0) renders full-size in front of the sprite, brightened further
   * by {@see spotlightBoost} as it sweeps through the closest point; the far
   * arc renders smaller and dimmer behind it.
   *
   * @param {object} fighter
   * @return {void}
   */
  updateFlairRing(fighter) {
    if (!fighter.sprite?.active || !fighter.flairRing) {
      this.stopFlairRing(fighter);
      return;
    }

    const now = this.scene.time.now;
    const dt = this.scene.game.loop.delta;
    const mult = spinMultiplier(now - (fighter.flairLastBurstAt ?? -Infinity));
    fighter.flairAngle += ((Math.PI * 2) / TIMINGS.flairOrbitPeriodMs) * mult * dt;

    const scale = (fighter.displaySize ?? 45) / 45;
    const rx = 30 * scale;
    const ry = 11 * scale;
    const headOffY = -Math.round(6 * scale);
    const cx = fighter.sprite.x;
    const cy = fighter.sprite.y + headOffY;

    fighter.flairRing.forEach(({ text, phase }) => {
      const a = fighter.flairAngle + phase;
      const sinA = Math.sin(a);
      const back = sinA < 0;
      const boost = back ? 0 : spotlightBoost(a);
      text.setPosition(cx + Math.cos(a) * rx, cy + sinA * ry);
      text.setDepth(back ? 1 : 3);
      text.setScale((back ? 0.7 : 1) * (1 + 0.4 * boost));
      text.setAlpha(back ? 0.55 : 1);
    });
  }

  /**
   * Tears down one fighter's orbit ring: stops its ticker and destroys every
   * glyph. Safe to call when no ring is active.
   *
   * @param {object} fighter
   * @return {void}
   */
  stopFlairRing(fighter) {
    fighter.flairRingTicker?.remove();
    fighter.flairRingTicker = null;
    fighter.flairRing?.forEach(({ text }) => { if (text.scene) text.destroy(); });
    fighter.flairRing = null;
  }

  /**
   * One-shot "toả ra" burst that plays alongside every triggering flair hit:
   * two staggered shockwave rings and upward sparkles from the fighter's
   * feet, plus a bright core flash and a starburst of spokes at chest
   * height — both additively blended so they read as stacking light rather
   * than a flat color wash. Purely decorative and self-cleaning — nothing
   * here is tracked on `fighter` beyond a `flairBurstAt` timestamp, used only
   * to skip re-bursting when a hit lands while the previous burst (≤850ms) is
   * still animating — realistic hit cadence is seconds apart, so this only
   * guards a pathological run of hits from stacking unbounded Graphics/
   * particle objects, and never affects the orbit ring itself.
   *
   * @param {object} fighter
   * @return {void}
   */
  burstFlair(fighter) {
    const now = this.scene.time.now;
    if (fighter.flairBurstAt && now - fighter.flairBurstAt < 300) {
      return;
    }
    fighter.flairBurstAt = now;

    const color = fighter.flairColor;
    const colorInt = Phaser.Display.Color.HexStringToColor(color).color;
    const footY = Math.round((fighter.baseSize ?? 48) / 2.2);
    const ringRadius = Math.max(10, Math.round((fighter.baseSize ?? 48) * 0.22));

    [0, 100].forEach(delayMs => {
      this.scene.time.delayedCall(delayMs, () => {
        if (!fighter.sprite?.scene) {
          return;
        }
        const ring = this.scene.add.graphics();
        ring.lineStyle(2, colorInt, 0.9);
        ring.strokeCircle(0, 0, ringRadius);
        ring.setPosition(0, footY);
        fighter.sprite.add(ring);
        this.scene.tweens.add({
          targets: ring,
          scaleX: 2,
          scaleY: 2,
          alpha: 0,
          duration: 600,
          ease: 'Sine.easeOut',
          onComplete: () => { if (ring.scene) ring.destroy(); },
        });
      });
    });

    if (!fighter.pos) {
      return;
    }
    const emitter = this.scene.add.particles(fighter.pos.x, fighter.pos.y + footY, TextureKey.SPARK, {
      tint: { onEmit: () => colorInt },
      scale: { start: 0.6, end: 0 },
      alpha: { start: 0.9, end: 0 },
      speedX: { min: -30, max: 30 },
      speedY: { min: -90, max: -40 },
      lifespan: { min: 500, max: 750 },
      blendMode: Phaser.BlendModes.ADD,
    });
    // Matches charge.js's feet-level emitters, which explicitly layer at
    // depth 1 (below the fighter container's depth 2) rather than the
    // default depth 0.
    emitter.setDepth(1);
    emitter.explode(8);
    this.scene.time.delayedCall(850, () => { if (emitter.scene) emitter.destroy(); });

    this.burstCorePop(fighter, colorInt, footY);
    this.burstSpokes(fighter, colorInt, footY);
  }

  /**
   * A bright white-to-color flash at chest height, additively blended so it
   * reads as a hot flare rather than a flat colored disc.
   *
   * @param {object} fighter
   * @param {number} colorInt
   * @param {number} footY
   * @return {void}
   */
  burstCorePop(fighter, colorInt, footY) {
    const core = this.scene.add.circle(fighter.pos.x, fighter.pos.y - footY, 5, 0xffffff, 1);
    core.setBlendMode(Phaser.BlendModes.ADD);
    core.setDepth(2);
    this.scene.tweens.add({
      targets: core,
      scale: 7,
      alpha: 0,
      duration: 260,
      ease: 'Cubic.easeOut',
      onUpdate: tween => core.setFillStyle(tween.progress > 0.35 ? colorInt : 0xffffff),
      onComplete: () => core.destroy(),
    });
  }

  /**
   * A starburst of spokes shooting outward from chest height on trigger —
   * alternating long/short lengths with a little angular jitter reads as an
   * irregular explosion rather than a mechanical "sun". Additively blended,
   * one shared Graphics object redrawn each tween tick rather than N
   * separate Graphics/Tween pairs per spoke.
   *
   * @param {object} fighter
   * @param {number} colorInt
   * @param {number} footY
   * @return {void}
   */
  burstSpokes(fighter, colorInt, footY) {
    const originX = fighter.pos.x;
    const originY = fighter.pos.y - footY;
    const N = 12;
    const spokes = Array.from({ length: N }, (_, i) => {
      const a = (i / N) * Math.PI * 2 + Phaser.Math.FloatBetween(-0.15, 0.15);
      const long = i % 3 === 0;
      return {
        a,
        len: long ? Phaser.Math.Between(90, 120) : Phaser.Math.Between(40, 70),
        width: long ? 3 : 1.5,
      };
    });

    const g = this.scene.add.graphics();
    g.setBlendMode(Phaser.BlendModes.ADD);
    g.setDepth(2);

    const draw = (growth, fade) => {
      g.clear();
      spokes.forEach(({ a, len, width }) => {
        g.lineStyle(width, colorInt, fade);
        const l = len * growth;
        g.lineBetween(originX, originY, originX + Math.cos(a) * l, originY + Math.sin(a) * l);
      });
    };

    const state = { growth: 0 };
    this.scene.tweens.add({
      targets: state,
      growth: 1,
      duration: 150,
      ease: 'Cubic.easeOut',
      onUpdate: () => draw(state.growth, 1),
      onComplete: () => {
        this.scene.tweens.add({
          targets: state,
          growth: 1,
          duration: 220,
          ease: 'Sine.easeIn',
          onUpdate: tween => draw(1, 1 - tween.progress),
          onComplete: () => g.destroy(),
        });
      },
    });
  }

  /**
   * Removes a fighter's flair halo — the orbit ring and its ticker, the
   * expiry timer — and resets its flair state. Called both when the flair
   * expires and when the fighter leaves, so no tween, ticker or delayedCall
   * outlives the object it targets.
   *
   * @param {object} fighter
   * @return {void}
   */
  destroyFlair(fighter) {
    if (!fighter) {
      return;
    }
    fighter.flairTimer?.remove?.();
    fighter.flairTimer = null;
    this.stopFlairRing(fighter);
    fighter.flairState = clearFlair();
    fighter.flairColor = null;
    fighter.flairLastBurstAt = null;
  }

  removeFighter(userId) {
    const entry = this.scene.fighters.get(userId);
    if (!entry) {
      return;
    }
    this.scene.fighters.delete(userId);
    this.destroyFlair(entry);
    this.scene.tweens.add({
      targets: entry.sprite,
      alpha: 0,
      duration: 300,
      onComplete: () => { if (entry.sprite?.scene) entry.sprite.destroy(); },
    });
    if (entry.handle?.scene) {
      this.scene.tweens.add({
        targets: entry.handle,
        alpha: 0,
        duration: 300,
        onComplete: () => { if (entry.handle?.scene) entry.handle.destroy(); },
      });
    }
    this.relayoutFighters();
  }

  /**
   * Reflows all fighters into grid positions based on current count.
   *
   * @return {void}
   */
  relayoutFighters() {
    const count = this.scene.fighters.size;
    if (count === 0) {
      return;
    }
    const config = fighterDisplayConfig(count, this.scene.mode);
    const positions = computeFighterPositions(
      count,
      this.scene.layout.fighters.rowXRange,
      config.topY,
      config.perRow,
      config.rowSpacing,
    );

    let i = 0;
    for (const [userId, entry] of this.scene.fighters.entries()) {
      const gridTarget = positions[i++];
      const target = entry.hasCustomPosition ? entry.pos : gridTarget;
      const newSize = config.displaySize;
      const sizeChanged = newSize !== entry.displaySize;

      if (!entry.hasCustomPosition) {
        this.scene.tweens.add({
          targets: entry.sprite,
          x: target.x,
          y: target.y,
          duration: 200,
          ease: 'Quad.easeOut',
        });
      }

      if (sizeChanged) {
        entry.displaySize = newSize;
        entry.sprite.setScale(Fighter.fighterRestScale(entry));
      }

      const scale   = entry.sprite.scaleX;
      const fontPx  = handleFontPx(newSize);
      const maxChrs = Math.max(8, Math.round(newSize * 0.22));
      const handleY = target.y + entry.legH * scale + fontPx;
      if (config.showHandle && !entry.handle) {
        entry.handle = this.scene.addSharpText(target.x, handleY, truncateHandle(entry.handleText, maxChrs), {
          fontFamily: 'monospace',
          fontSize: `${fontPx}px`,
          color: '#fbbf24',
        });
      } else if (!config.showHandle && entry.handle) {
        entry.handle.destroy();
        entry.handle = null;
      } else if (entry.handle) {
        this.scene.tweens.add({
          targets: entry.handle,
          x: target.x,
          y: handleY,
          duration: 200,
          ease: 'Quad.easeOut',
        });
      }

      entry.pos = target;

      const charge = this.scene.charges.get(userId);
      if (charge) {
        // Ring is inside the container at (0,0) — rebuild if size changed
        if (sizeChanged && charge.ring?.scene) {
          this.scene.tweens.killTweensOf(charge.ring);
          charge.ring.destroy();
          charge.ring = this.scene.charge?.createChargingRing?.(entry);
          entry.sprite.addAt(charge.ring, 0);
        }
        // Trail is world-space — rebuild on size change, reposition otherwise
        if (sizeChanged && charge.trail?.scene) {
          charge.trail.stop();
          charge.trail.destroy();
          charge.trail = this.scene.charge?.createChargingTrail?.(entry);
        } else if (charge.trail?.scene) {
          const tb = entry.pos.x <= this.scene.layout.boss.anchor.x ? 1 : -1;
          const cb = Math.round(entry.displaySize / 3);
          charge.trail.setPosition(
            target.x - tb * Math.round(entry.displaySize * 0.18),
            target.y + cb - Math.round(entry.displaySize * 0.12),
          );
        }
        if (charge.bubble) {
          const avatarRelY   = entry.head?.y ?? 0;
          const avatarRadius = (entry.head?.displayHeight ?? 28) / 2;
          charge.bubble.moveTo(target.x, target.y + avatarRelY - avatarRadius - 16);
        }
      }
    }

    if (this.scene.hoveredUserId != null) {
      this.scene.bubble?.showFighterTooltip?.(this.scene.hoveredUserId);
    }
  }

  /**
   * Rescales a fighter based on their total damage dealt to the boss.
   *
   * @param {number|string} userId
   * @return {void}
   */
  rescaleFighterByDamage(userId) {
    const fighter = this.scene.fighters.get(userId);
    if (!fighter) {
      return;
    }
    fighter.damageScale = damageScaleMultiplier(this.scene.damageTotals.get(userId) ?? 0, this.scene.bossState?.maxHp);
    this.tweenToRestScale(fighter);
  }

  /**
   * Tween the fighter toward its canonical rest scale without killing other tweens.
   * If an attack animation currently owns the sprite's scale, skip.
   *
   * @param {{ sprite: object, rescaleTween?: object, displaySize: number, baseSize: number, damageScale?: number }} fighter
   * @param {{ duration?: number, ease?: string }} options
   * @return {void}
   */
  tweenToRestScale(fighter, { duration = 600, ease = 'Back.easeOut' } = {}) {
    fighter.rescaleTween?.remove();
    fighter.rescaleTween = null;
    const attackOwnsScale = this.scene.tweens.getTweensOf(fighter.sprite)
      .some(tw => tw.data?.some(d => d.key === 'scaleX' || d.key === 'scaleY'));
    if (attackOwnsScale) {
      return;
    }
    const target = Fighter.fighterRestScale(fighter);
    fighter.rescaleTween = this.scene.tweens.add({
      targets: fighter.sprite,
      scaleX: target,
      scaleY: target,
      duration,
      ease,
      onComplete: () => { fighter.rescaleTween = null; },
    });
  }

  /**
   * Handles the hit event payload: plays attack animation, applies damage scaling, and triggers projectile/impact.
   *
   * @param {{ user_id: number|string, damage: number, boss_hp_after: number, slack_handle?: string }} payload
   * @return {void}
   */
  handleHit(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    this.scene.charge?.clearCharge?.(payload.user_id);
    const fighter = this.scene.fighters.get(payload.user_id);
    if (fighter) {
      this.scene.tweens.killTweensOf(fighter.sprite);
      if (fighter.handle) this.scene.tweens.killTweensOf(fighter.handle);
      // fighter.pos must be current before applyFlair: its burst places a
      // world-space particle emitter at fighter.pos, and a fighter mid-move
      // would otherwise get its spark burst rendered at a stale position.
      fighter.pos = { x: fighter.sprite.x, y: fighter.sprite.y };
      fighter.waypointMoving = false;
      this.applyFlair(fighter, payload.flair ?? null, payload.flair_duration_ms ?? null, payload.flair_color ?? null);
    }
    const key     = fighter?.ftype?.key ?? null;
    const attacks = fighter?.ftype?.attacks ?? null;
    const pickIdx = attacks?.length ? Phaser.Math.Between(0, attacks.length - 1) : -1;
    const flipTowardBoss = fighter ? fighter.pos.x > this.scene.layout.boss.anchor.x : false;
    if (fighter?.body) {
      const atkAnimKey = pickIdx >= 0 ? `${key}-attack${pickIdx + 1}` : `${key}-attack`;
      fighter.animState = AnimState.ATTACK;
      fighter.body.off(Phaser.Animations.Events.ANIMATION_COMPLETE);
      fighter.body.setFlipX(flipTowardBoss);
      fighter.body.play(atkAnimKey);

      fighter.body.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
        if (!fighter.body?.scene) return;
        const next = this.scene.charges.has(fighter.id) ? AnimState.WALK : AnimState.IDLE;
        fighter.animState = next;
        fighter.body.setFlipX(next === AnimState.WALK ? flipTowardBoss : false);
        fighter.body.play(`${key}-${next}`);
      });
    }
    const isKillShot = (payload.boss_hp_after ?? 1) <= 0;
    if (payload.damage > 0 && fighter) {
      const prev = this.scene.damageTotals.get(payload.user_id) ?? 0;
      this.scene.damageTotals.set(payload.user_id, prev + payload.damage);
      // Update the canonical rest scale now so the attack animation about to
      // run settles onto it; the visual grow tween itself stays delayed.
      fighter.damageScale = damageScaleMultiplier(prev + payload.damage, this.scene.bossState?.maxHp);
      this.scene.time.delayedCall(isKillShot ? 720 : 120, () => {
        this.rescaleFighterByDamage(payload.user_id);
      });
    }
    const onImpact = () => {
      this.scene.leaderboard?.onHit(payload.user_id, payload.damage, payload.slack_handle);
      this.scene.impact.apply(payload.boss_hp_after);
      if (this.scene.hoveredUserId === payload.user_id) {
        this.scene.bubble?.showFighterTooltip?.(payload.user_id);
      }
      if (!isKillShot) {
        this.scene.time.delayedCall(90, () => this.scene.boss?.playBossReact?.());
      }
    };
    if (fighter) {
      const attackType = fighter.ftype?.attackType ?? AttackType.BLAST;
      const effKey = (pickIdx >= 0 && attacks?.[pickIdx]?.effectFrames) ? `${key}-effect${pickIdx + 1}` : null;
      const onEffect = effKey ? (x, y) => {
        if (!fighter.body?.scene) return;
        const eff = this.scene.add.sprite(x, y, TextureKey.FIGHTERS, `${effKey}-0`)
          .setScale(fighter.sprite.scaleX * fighter.body.scaleX)
          .setFlipX(flipTowardBoss)
          .setBlendMode(Phaser.BlendModes.ADD)
          .setDepth(3)
          .play(effKey);
        eff.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => eff.destroy());
      } : null;
      this.scene.attacks.dispatch(attackType, fighter, {
        isKillShot,
        damage: payload.damage,
        maxHp: this.scene.bossState?.maxHp ?? 1,
        onImpact,
        onEffect,
      });
    } else {
      this.scene.time.delayedCall(TIMINGS.projectileArcMs, onImpact);
    }
  }
}
