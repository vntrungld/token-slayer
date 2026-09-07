<?php

namespace App\Support;

use App\Enums\ModelFamily;

/**
 * Renders a raw `events.model` id as the name a person would use for it —
 * `claude-fable-5-1` as "Fable 5.1", `gpt-5.5` as "GPT-5.5".
 *
 * Split out from {@see ModelFamily} because that enum answers three different
 * questions and only one of them is the name. The family is the right answer
 * for colour (Opus 5 and Opus 4.8 belong in the same colour) and for the
 * cost rank that picks a turn's dominant model, but it is the wrong answer for
 * a label: it throws the version away, which is tolerable for Anthropic's
 * lines and outright wrong for OpenAI's, where "GPT" is the whole vendor
 * rather than one line and every model would collapse into a single bucket.
 */
final class ModelName
{
    /**
     * Shown for an event that carries no model at all — rows predating model
     * tracking, and rows from clients that have not updated.
     *
     * @var string
     */
    public const string UNKNOWN = 'Unknown';

    /**
     * The display name for a raw model id.
     *
     * An id whose line this app does not recognise is returned verbatim. A
     * name is how someone finds the thing again, so inventing one for a shape
     * we cannot read would produce a label matching nothing in the data.
     *
     * @param  ?string  $modelId  the raw id stored in `events.model`
     * @return string
     */
    public static function for(?string $modelId): string
    {
        $id = trim((string) $modelId);

        if ($id === '') {
            return self::UNKNOWN;
        }

        $family = ModelFamily::fromModelId($id);

        if ($family === null) {
            return $id;
        }

        $segments = self::segmentsAfterLine(strtolower($id), $family);

        if ($segments === null) {
            return $id;
        }

        return self::compose($family, $segments);
    }

    /**
     * The family a raw id belongs to, for callers that render the name here
     * but still need the family's colour or rank. A thin pass-through, so a
     * caller never has to reach for both classes to describe one model.
     *
     * @param  ?string  $modelId  the raw id stored in `events.model`
     * @return ?ModelFamily
     */
    public static function familyOf(?string $modelId): ?ModelFamily
    {
        return ModelFamily::fromModelId($modelId);
    }

    /**
     * The id's segments that follow its line token — the version and any
     * variant — or null when the line token is not a segment of its own
     * (nothing left to render a version from).
     *
     * Located by searching for the line rather than by taking the first
     * segment, so a vendor prefix (`claude-opus-5`) or any future
     * re-ordering does not get mistaken for the line itself.
     *
     * @param  string  $id  the lowercased raw id
     * @param  ModelFamily  $family  the family already resolved from it
     * @return ?array<int, string>
     */
    private static function segmentsAfterLine(string $id, ModelFamily $family): ?array
    {
        // A trailing training-date stamp is noise in a label: two builds of
        // Haiku 4.5 are the same model to anyone reading a chart.
        $segments = explode('-', preg_replace('/-\d{8}$/', '', $id));
        $position = array_search($family->value, $segments, true);

        return $position === false ? null : array_slice($segments, $position + 1);
    }

    /**
     * Assemble the line label with its version and variant.
     *
     * The separator differs by vendor because the vendors write their own
     * names differently: Anthropic spaces it ("Opus 5"), OpenAI hyphenates
     * it ("GPT-5.5"). Getting this wrong is small but reads as careless to
     * anyone who works with these models daily.
     *
     * @param  ModelFamily  $family  the model's line
     * @param  array<int, string>  $segments  the id's segments after the line token
     * @return string
     */
    private static function compose(ModelFamily $family, array $segments): string
    {
        $version = [];
        $variant = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\d+(\.\d+)*$/', $segment) === 1) {
                $version[] = $segment;

                continue;
            }

            $variant[] = ucfirst($segment);
        }

        // "5-1" is a point release, not a range — the id's hyphen is a dot.
        $name = $family->getLabel();

        if ($version !== []) {
            $name .= ($family === ModelFamily::Gpt ? '-' : ' ').implode('.', $version);
        }

        return $variant === [] ? $name : $name.' '.implode(' ', $variant);
    }
}
