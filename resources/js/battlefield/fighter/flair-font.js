/**
 * The webfont the model-flair orbit ring is set in. Everything else in the
 * battlefield uses the browser's default `monospace`; the ring deliberately
 * does not, because it is a flair effect that is supposed to read as
 * something other than ordinary scene text.
 *
 * Self-hosted at a fixed path rather than registered through the
 * `laravel-vite-plugin/fonts` bunny() helper that Instrument Sans uses: the
 * admin's flair preview renders inside the Filament panel, which does not
 * load this app's Vite CSS bundle, so the two surfaces can only share one
 * face if its URL is stable and declarable from both. The @font-face lives
 * in resources/css/app.css for the battlefield page and in
 * resources/views/filament/flair-preview.blade.php for the panel.
 */
export const FLAIR_FONT_FAMILY = "'Chakra Petch', monospace";
export const FLAIR_FONT_WEIGHT = '700';

let loading = null;

/**
 * Resolves once the flair webfont is usable for canvas rasterization.
 *
 * A Phaser Text bakes its glyphs into a texture the moment it is created and
 * never re-rasterizes on its own, so a Text built before the face has
 * downloaded is stuck rendering in the fallback for its whole life —
 * `font-display: swap` repaints DOM text but cannot reach into a canvas
 * texture. Callers use this to re-apply the family once, after the fact.
 *
 * @return {Promise<void>}
 */
export function ensureFlairFont() {
  if (!loading) {
    loading = document.fonts?.load
      ? document.fonts.load(`${FLAIR_FONT_WEIGHT} 16px ${FLAIR_FONT_FAMILY}`).then(() => {}, () => {})
      : Promise.resolve();
  }
  return loading;
}

/**
 * Whether the flair webfont is already available synchronously, so a caller
 * can skip the re-apply pass entirely on every flair after the first.
 *
 * @return {boolean}
 */
export function isFlairFontReady() {
  return document.fonts?.check?.(`${FLAIR_FONT_WEIGHT} 16px ${FLAIR_FONT_FAMILY}`) ?? true;
}
