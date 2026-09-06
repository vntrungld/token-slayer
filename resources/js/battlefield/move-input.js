import { Boss } from '@battlefield/boss.js';
import { AnimState } from '@battlefield/constants.js';
import { isInsideLeaderboardPanel, planRoute } from '@battlefield/move-geometry.js';

/**
 * Returns the font size in pixels for a fighter handle label.
 *
 * @param {number} displaySize
 * @return {number}
 */
function handleFontPx(displaySize) {
  return Math.max(10, Math.round(displaySize * 0.25));
}

const CLICK_DEBOUNCE_MS = 250;

/** Handles click-to-move input: route planning, chevron animation, ripple effect. */
export class MoveInput {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene = scene;
  }

  /**
   * Registers pointer input handlers on the scene. Call once from scene.create().
   *
   * @return {void}
   */
  setup() {
    if (!this.scene.currentUserId) {
      return;
    }

    let debounceTimer = null;

    // worldX/worldY, not x/y: the camera is zoomed by renderScale (see
    // scene.js), so pointer.x/y are canvas pixels while every layout
    // constant and sprite position here is in logical world units. They were
    // interchangeable only while the camera sat at zoom 1.
    this.scene.input.on('pointerdown', pointer => {
      if (isInsideLeaderboardPanel(pointer.worldX, pointer.worldY, this.scene.layout)) {
        return;
      }

      // Always show ripple at click point
      this._spawnClickRipple(pointer.worldX, pointer.worldY);

      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        const entry = this.scene.fighters.get(this.scene.currentUserId);
        if (entry?.isStunned) return;
        const from  = entry?.sprite ? { x: entry.sprite.x, y: entry.sprite.y } : { x: pointer.worldX, y: pointer.worldY };
        const route = this._planRoute(from.x, from.y, pointer.worldX, pointer.worldY);
        if (!route || route.length === 0) return;

        const final = route[route.length - 1];
        const x = parseFloat((final.x / this.scene.layout.logicalWidth).toFixed(4));
        const y = parseFloat((final.y / this.scene.layout.logicalHeight).toFixed(4));

        // Always cancel any stale waypoint animation — kills tweens without onComplete,
        // so we must manually clear the flag to unblock handleFighterMoved.
        if (entry) {
          entry.waypointMoving = false;
          this.scene.tweens.killTweensOf(entry.sprite);
          if (entry.handle) this.scene.tweens.killTweensOf(entry.handle);
        }

        if (route.length > 1 && entry) {
          // Detour route: animate locally, dispatch only the final destination
          this._animateRoute(entry, route);
        }

        if (window.Livewire) {
          window.Livewire.dispatch('fighter-move', { x, y });
        }
      }, CLICK_DEBOUNCE_MS);
    });

    this.scene.input.on('pointermove', pointer => {
      const overLeaderboard = isInsideLeaderboardPanel(pointer.worldX, pointer.worldY, this.scene.layout);
      this.scene.game.canvas.style.cursor = overLeaderboard ? 'default' : 'pointer';
    });
  }

  /**
   * Draws a filled arrowhead chevron shape into a graphics object.
   *
   * @param {Phaser.GameObjects.Graphics} g
   * @param {number} ax
   * @param {number} ay
   * @param {number} px
   * @param {number} py
   * @param {number} color
   * @param {number} alpha
   * @param {number} [s=1]
   * @return {void}
   */
  _drawChevron(g, ax, ay, px, py, color, alpha, s = 1) {
    // Arrowhead with a notch cut into the back — looks like the LoL click indicator
    // ax/ay = unit vector toward tip, px/py = perpendicular
    const TIP   = 7  * s;
    const BODY  = 4.5 * s;
    const HALF  = 4  * s;
    const NOTCH = 1.8 * s;
    g.fillStyle(color, alpha);
    g.fillPoints([
      { x:  ax * TIP,               y:  ay * TIP },               // tip
      { x: -ax * BODY + px * HALF,  y: -ay * BODY + py * HALF },  // base-right
      { x: -ax * NOTCH,             y: -ay * NOTCH },              // back notch
      { x: -ax * BODY - px * HALF,  y: -ay * BODY - py * HALF },  // base-left
    ], true);
  }

  /**
   * Spawns the converging-arrow ripple effect at (x, y).
   *
   * @param {number} x
   * @param {number} y
   * @return {void}
   */
  _spawnClickRipple(x, y) {
    const COLOR      = 0x44dd11;
    const COLOR_HI   = 0xaaffaa;
    const COLOR_GLOW = 0xccffaa;
    const R_START    = 16;
    const CONVERGE   = 270;

    const diagonals = [Math.PI * 0.25, Math.PI * 0.75, Math.PI * 1.25, Math.PI * 1.75];
    const arrows = [];

    for (const angle of diagonals) {
      const g = this.scene.add.graphics();
      g.setAlpha(0);

      const inward = angle + Math.PI;
      const ax = Math.cos(inward);
      const ay = Math.sin(inward);
      const px = -ay;
      const py =  ax;

      // Outer glow — white, faint, slightly larger
      this._drawChevron(g, ax, ay, px, py, 0xffffff, 0.18, 1.5);
      // Mid glow — green, semi-transparent, slightly larger
      this._drawChevron(g, ax, ay, px, py, COLOR_HI, 0.25, 1.2);
      // Core — solid bright green
      this._drawChevron(g, ax, ay, px, py, COLOR, 1.0, 1.0);
      // Tip highlight — tiny bright dot at the point
      g.fillStyle(0xeeffcc, 0.9);
      g.fillCircle(ax * 7, ay * 7, 1.2);

      g.setPosition(x + Math.cos(angle) * R_START, y + Math.sin(angle) * R_START);
      arrows.push(g);

      this.scene.tweens.add({ targets: g, alpha: 1, duration: 50, ease: 'Quad.easeOut' });
    }

    let completed = 0;
    for (const g of arrows) {
      this.scene.tweens.add({
        targets: g,
        x,
        y,
        alpha: 0,
        duration: CONVERGE,
        ease: 'Quad.easeIn',
        delay: 35,
        onComplete: () => {
          g.destroy();
          if (++completed < arrows.length) {
            return;
          }

          // Phase 2 — burst
          const burst = this.scene.add.graphics();
          burst.fillStyle(COLOR_GLOW, 1);
          burst.fillCircle(0, 0, 3);
          burst.setPosition(x, y);
          this.scene.tweens.add({
            targets: burst,
            scaleX: 3.5,
            scaleY: 3.5,
            alpha: 0,
            duration: 150,
            ease: 'Quad.easeOut',
            onComplete: () => {
              burst.destroy();

              // Phase 3 — ring
              const ring = this.scene.add.graphics();
              ring.lineStyle(1.2, COLOR, 0.9);
              ring.strokeCircle(0, 0, 5);
              ring.setPosition(x, y);
              this.scene.tweens.add({
                targets: ring,
                scaleX: 3.5,
                scaleY: 3.5,
                alpha: 0,
                duration: 260,
                ease: 'Quad.easeOut',
                onComplete: () => ring.destroy(),
              });
            },
          });
        },
      });
    }
  }

  /**
   * Returns the move-geometry context for the current user's fighter.
   *
   * @return {{layout: object, bossType: object, fsize: number}}
   */
  _geometryCtx() {
    const entry = this.scene.currentUserId ? this.scene.fighters.get(this.scene.currentUserId) : null;
    const fsize = entry ? entry.displaySize * (entry.damageScale ?? 1) : 48;
    return {
      layout: this.scene.layout,
      bossType: Boss.bossTypeFor(this.scene.bossState?.number ?? 0),
      fsize,
    };
  }

  /**
   * Returns a waypoint list from (fromX, fromY) to (toX, toY), routing around blocked zones.
   *
   * @param {number} fromX
   * @param {number} fromY
   * @param {number} toX
   * @param {number} toY
   * @return {Array<{x: number, y: number}>|null}
   */
  _planRoute(fromX, fromY, toX, toY) {
    return planRoute(fromX, fromY, toX, toY, this._geometryCtx());
  }

  /**
   * Animates the fighter sprite through the given waypoint list locally.
   *
   * @param {object} entry
   * @param {Array<{x: number, y: number}>} route
   * @return {void}
   */
  _animateRoute(entry, route) {
    this.scene.tweens.killTweensOf(entry.sprite);
    if (entry.handle) this.scene.tweens.killTweensOf(entry.handle);
    entry.waypointMoving = true;

    const SPEED = 300; // px/s

    const step = (idx) => {
      if (!entry.sprite?.active || idx >= route.length) {
        entry.waypointMoving = false;
        return;
      }
      const target = route[idx];
      const dx = target.x - entry.sprite.x;
      const dy = target.y - entry.sprite.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      const duration = Math.max(150, Math.round((dist / SPEED) * 1000));
      const flipX = dx < -5 ? true : (dx > 5 ? false : target.x > this.scene.layout.boss.anchor.x);

      if (entry.body && entry.animState !== AnimState.ATTACK) {
        entry.animState = AnimState.WALK;
        entry.body.setFlipX(flipX);
        entry.body.play(entry.ftype.key + '-walk', true);
      }

      this.scene.tweens.add({
        targets: entry.sprite,
        x: target.x, y: target.y,
        duration,
        ease: 'Linear',
        onComplete: () => {
          if (!entry.sprite?.active) {
            entry.waypointMoving = false;
            return;
          }
          if (idx === route.length - 1) {
            entry.pos = target;
            entry.hasCustomPosition = true;
            entry.waypointMoving = false;
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
        const spriteScale = entry.sprite.scaleX;
        const fontPx = handleFontPx(entry.displaySize);
        this.scene.tweens.add({
          targets: entry.handle,
          x: target.x,
          y: target.y + entry.legH * spriteScale + fontPx,
          duration,
          ease: 'Linear',
        });
      }

      if (entry.bubble) {
        const avatarRelY   = entry.head?.y ?? 0;
        const avatarRadius = (entry.head?.displayHeight ?? 28) / 2;
        entry.bubble.tweenTo(this.scene, target.x, target.y + avatarRelY - avatarRadius - 28, duration);
      }

      const charge = this.scene.charges.get(entry.id);
      if (charge) {
        if (charge.trail?.scene) {
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
        if (charge.bubble) {
          const avatarRelY   = entry.head?.y ?? 0;
          const avatarRadius = (entry.head?.displayHeight ?? 28) / 2;
          charge.bubble.tweenTo(this.scene, target.x, target.y + avatarRelY - avatarRadius - 16, duration);
        }
      }
    };

    step(0);
  }

}
