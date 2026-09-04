<?php

namespace App\Services\Client;

use App\Services\GitHub\ReleaseResolver;
use App\Services\SlayerWheelProvider;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Renders each install script exactly once and caches the rendered string
 * **together with** its sha256, so the bytes served and the digest published
 * cannot drift apart.
 *
 * That coupling is the whole point. `/install` resolves `clientVersion` from
 * {@see ReleaseResolver} fresh on every request, deliberately, so the stamp
 * matches the artifact. Hashing a second, separately-rendered copy would let a
 * release landing between the two renders publish a digest for bytes nobody was
 * served — after which every client refuses to update on a checksum mismatch,
 * forever, with no error anywhere.
 */
class ReleaseArtifacts
{
    /**
     * How long a rendered artifact and its digest stay cached. Long enough that
     * ingest never pays the GitHub round-trip (the client's own curl gives up
     * after 3 s while `github.timeout` is 8 s), short enough that a new release
     * reaches clients promptly. The scheduled refresh keeps it warm.
     *
     * @var int
     */
    private const int TTL_SECONDS = 900;

    /**
     * Install-script views this service publishes.
     *
     * @var array<int, string>
     */
    public const array VIEWS = ['install-script', 'install-script-ps1'];

    /**
     * Build the service.
     *
     * @param  ReleaseResolver  $resolver
     * @param  SlayerWheelProvider  $wheel
     */
    public function __construct(
        private readonly ReleaseResolver $resolver,
        private readonly SlayerWheelProvider $wheel,
    ) {}

    /**
     * The exact bytes to serve for an install script, or null if they could not
     * be produced. Callers serving this MUST serve it verbatim — rendering
     * their own copy reintroduces the drift this class exists to prevent.
     *
     * @param  string  $view  one of {@see self::VIEWS}
     * @return ?string
     */
    public function body(string $view): ?string
    {
        return $this->artifact($view)['body'] ?? null;
    }

    /**
     * The sha256 of the bytes {@see self::body()} returns for this view.
     *
     * @param  string  $view  one of {@see self::VIEWS}
     * @return ?string
     */
    public function digest(string $view): ?string
    {
        return $this->artifact($view)['sha256'] ?? null;
    }

    /**
     * The sha256 of the CLI wheel this server relays. The installer verifies
     * the wheel against it before `pip install`, mirroring the pinned-jq
     * checksum pattern the installer already uses for its own binary.
     *
     * @return ?string
     */
    public function wheelDigest(): ?string
    {
        try {
            return Cache::remember($this->key('wheel'), self::TTL_SECONDS, function (): ?string {
                $bytes = $this->wheel->bytes();

                return $bytes === null ? null : hash('sha256', $bytes);
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Pre-render and cache every artifact, so no request ever pays for it.
     * Called by the scheduled `client-artifacts:refresh` command.
     *
     * @return void
     */
    public function warm(): void
    {
        foreach (self::VIEWS as $view) {
            $this->artifact($view);
        }

        $this->wheelDigest();
    }

    /**
     * Render (or read from cache) one install script and its digest.
     * Never throws: no digest is worth a 500 on `/api/events`, which is the
     * single append-only write path for usage.
     *
     * @param  string  $view  one of {@see self::VIEWS}
     * @return array{body: string, sha256: string}|array{}
     */
    private function artifact(string $view): array
    {
        try {
            return Cache::remember($this->key($view), self::TTL_SECONDS, function () use ($view): array {
                $body = view($view, $this->viewData($view))->render();

                return ['body' => $body, 'sha256' => hash('sha256', $body)];
            });
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * The data both install-script views expect. Mirrors the routes exactly;
     * if the two ever diverge the digest stops describing the served bytes.
     *
     * @param  string  $view  one of {@see self::VIEWS}
     * @return array<string, string>
     */
    private function viewData(string $view): array
    {
        $routeName = $view === 'install-script-ps1' ? 'install-script-ps1' : 'install-script';

        return [
            'baseUrl' => url('/api/events'),
            'apiBase' => url('/'),
            'namespace' => config('app.hook_namespace'),
            'clientVersion' => $this->resolver->latest()['version'] ?? '',
            'hookVersion' => config('token_slayer.hook_version'),
            'installUrl' => route($routeName),
            'slayerWheelUrl' => route('slayer-wheel'),
        ];
    }

    /**
     * Cache key for an artifact, namespaced by the base URL. The rendered
     * script embeds absolute URLs, so the same code serving two hostnames
     * (a domain and a bare IP on staging, say) must not share one entry —
     * a client would otherwise be handed a script pointing at the wrong host.
     *
     * @param  string  $name  view name, or `wheel`
     * @return string
     */
    private function key(string $name): string
    {
        return 'client:artifact:'.$name.':'.sha1((string) url('/'));
    }
}
