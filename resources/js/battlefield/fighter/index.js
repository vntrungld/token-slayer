import Phaser from 'phaser';
import { FIGHTER_TYPES, TIMINGS } from '@battlefield/config.js';
import { computeFighterPositions, damageScaleMultiplier, fighterDisplayConfig } from '@battlefield/layout.js';
import { AnimState, AttackType, TextureKey } from '@battlefield/constants.js';
import { Boss } from '@battlefield/boss.js';
import { planRoute } from '@battlefield/move-geometry.js';
import { resolveFighterPlacement } from '@battlefield/fighter-placement.js';
import { driftedPositions } from '@battlefield/resync.js';
import { loadAvatarTexture, makeFallbackAvatarTexture } from './avatar.js';
import { clearFlair, createFlairState, isFlairActive, resolveFlairDuration, startFlair } from './flair.js';

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
   * Shows or refreshes a fighter's flair badge for this hit.
   *
   * @param {object} fighter
   * @param {?string} flair
   * @param {?number} flairDurationMs  server-broadcast duration for this model,
   *   or null to fall back to the client default (an older cached client, or a
   *   flair with no configured duration)
   * @return {void}
   */
  applyFlair(fighter, flair, flairDurationMs) {
    const now = this.scene.time.now;
    const durationMs = resolveFlairDuration(flairDurationMs, TIMINGS.flairDurationMs);
    fighter.flairState = startFlair(fighter.flairState ?? createFlairState(), flair, now, durationMs);

    if (!isFlairActive(fighter.flairState, now)) {
      return;
    }

    if (!fighter.flairBadge?.scene) {
      // A child of the fighter container, so it inherits movement and the
      // damage rest-scale for free. `entry.handle` is world-space and is null
      // whenever handles are hidden, which would leave the badge unanchored.
      const head = fighter.head;
      const y = (head?.y ?? 0) - (head?.displayHeight ?? 28) / 2 - 10;
      fighter.flairBadge = this.scene.add.text(0, y, fighter.flairState.flair.toUpperCase(), {
        fontFamily: 'monospace', fontSize: '10px', color: '#fbbf24',
        backgroundColor: '#00000099', padding: { x: 4, y: 1 },
      }).setOrigin(0.5, 1);
      fighter.sprite?.add?.(fighter.flairBadge);
    }

    fighter.flairBadge.setText(fighter.flairState.flair.toUpperCase()).setAlpha(1);
    this.scene.tweens.killTweensOf(fighter.flairBadge);
    fighter.flairBlink = this.scene.tweens.add({
      targets: fighter.flairBadge,
      alpha: 0.15,
      duration: TIMINGS.flairBlinkMs,
      yoyo: true,
      repeat: -1,
    });

    if (fighter.flairTimer) {
      fighter.flairTimer.remove();
    }
    fighter.flairTimer = this.scene.time.delayedCall(durationMs, () => this.destroyFlair(fighter));
  }

  /**
   * Removes a fighter's flair badge, its blink tween and its expiry timer.
   * Called both when the badge expires and when the fighter leaves, so no
   * tween or delayedCall outlives the object it targets.
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
    if (fighter.flairBadge) {
      this.scene.tweens.killTweensOf(fighter.flairBadge);
      if (fighter.flairBadge.scene) fighter.flairBadge.destroy();
      fighter.flairBadge = null;
    }
    fighter.flairBlink = null;
    fighter.flairState = clearFlair();
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
      this.applyFlair(fighter, payload.flair ?? null, payload.flair_duration_ms ?? null);
      this.scene.tweens.killTweensOf(fighter.sprite);
      if (fighter.handle) this.scene.tweens.killTweensOf(fighter.handle);
      fighter.pos = { x: fighter.sprite.x, y: fighter.sprite.y };
      fighter.waypointMoving = false;
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
