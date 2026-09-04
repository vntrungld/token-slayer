<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The display family a raw `events.model` id belongs to. Raw ids are stored
 * verbatim so a newly released model is still counted correctly on day one;
 * this enum is the render-time and resolution-time interpretation of them.
 *
 * Distinct from {@see Provider}, which describes an org account's credential
 * shape rather than the model that produced a turn.
 */
enum ModelFamily: string implements HasColor, HasLabel
{
    /**
     * Anthropic's Fable line.
     */
    case Fable = 'fable';

    /**
     * Anthropic's Opus line.
     */
    case Opus = 'opus';

    /**
     * Anthropic's Sonnet line.
     */
    case Sonnet = 'sonnet';

    /**
     * Anthropic's Haiku line.
     */
    case Haiku = 'haiku';

    /**
     * OpenAI's GPT line, as used by Codex.
     */
    case Gpt = 'gpt';

    /**
     * Resolve a raw provider model id to its family, or null when the id
     * matches no known family. Matching is by substring rather than exact
     * value because ids carry point releases and dates (`claude-fable-5-1`,
     * `claude-haiku-4-5-20251001`), and because a bare alias (`sonnet`) also
     * appears in real transcripts.
     *
     * @param  ?string  $modelId  the raw id stored in `events.model`
     * @return ?self
     */
    public static function fromModelId(?string $modelId): ?self
    {
        if ($modelId === null) {
            return null;
        }

        $needle = strtolower($modelId);

        foreach (self::cases() as $family) {
            if (str_contains($needle, $family->value)) {
                return $family;
            }
        }

        return null;
    }

    /**
     * Relative cost rank, higher meaning more expensive. Used to resolve a turn
     * that touched several models: the turn is labelled by the most expensive
     * model it reached, so an Opus turn that hit its limit and fell back to
     * Sonnet is not laundered into the cheap bucket — in such a turn the cheap
     * model produces MORE tokens precisely because it finished the work.
     *
     * @return int
     */
    public function rank(): int
    {
        return match ($this) {
            self::Fable => 40,
            self::Opus => 30,
            self::Gpt => 25,
            self::Sonnet => 20,
            self::Haiku => 10,
        };
    }

    /**
     * Human-readable label shown by Filament badges and chart legends.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Fable => 'Fable',
            self::Opus => 'Opus',
            self::Sonnet => 'Sonnet',
            self::Haiku => 'Haiku',
            self::Gpt => 'GPT',
        };
    }

    /**
     * Badge and chart colour for this family. Fixed per family rather than
     * assigned by position, so a chart's colours do not shuffle as new model
     * ids start appearing in the data.
     *
     * @return string
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Fable => 'warning',
            self::Opus => 'primary',
            self::Sonnet => 'success',
            self::Haiku => 'gray',
            self::Gpt => 'info',
        };
    }
}
