<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Models\User;
use Facile\JoseVerifier\JWK\JwksProviderBuilder;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\ClientInterface;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Issuer\Metadata\Provider\MetadataProviderBuilder;
use Facile\OpenIDClient\Service\AuthorizationService;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Service\Builder\UserInfoServiceBuilder;
use Facile\OpenIDClient\Service\UserInfoService;
use Facile\OpenIDClient\Session\AuthSession;
use Facile\OpenIDClient\Session\AuthSessionInterface;
use Facile\OpenIDClient\Token\TokenSetInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Psr\Http\Client\ClientInterface as PsrHttpClient;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use RuntimeException;

/**
 * Generic OpenID Connect relying-party integration, built on top of the spec-driven
 * facile-it/php-openid-client library (discovery, PKCE, JWKS/ID-token verification are
 * handled inside that library, not here). Account-linking policy lives in
 * OidcUserResolver - see that class for the security-critical part of this feature.
 */
class OidcService
{
    public const STATE_SESSION_KEY = 'oidc.state';

    // Carries the nonce + PKCE code_verifier (see AuthSession::jsonSerialize()); the state is
    // additionally stored separately under STATE_SESSION_KEY so it can be verified up front
    // with hash_equals() before anything else about the callback is trusted.
    public const AUTH_SESSION_KEY = 'oidc.auth_session';

    public function __construct(
        private readonly OidcUserResolver $resolver,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('services.oidc.enabled')
            && filled(config('services.oidc.issuer'))
            && filled(config('services.oidc.client_id'));
    }

    public function authorizationRedirectUrl(Request $request): string
    {
        $this->ensureEnabled();

        $state = Str::random(40);
        $nonce = Str::random(40);
        $codeVerifier = Str::random(96);
        $authSession = new AuthSession;
        $authSession->setState($state);
        $authSession->setNonce($nonce);
        $authSession->setCodeVerifier($codeVerifier);

        $request->session()->put(self::STATE_SESSION_KEY, $state);
        $request->session()->put(self::AUTH_SESSION_KEY, $authSession->jsonSerialize());

        return $this->authorizationService()->getAuthorizationUri($this->client(), [
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64UrlEncode(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @throws RuntimeException on any failure (invalid/tampered state, provider error, failed
     *                          token exchange/verification, or a claims/account-linking policy violation)
     */
    public function authenticateCallback(Request $request): User
    {
        $this->ensureEnabled();

        $error = $request->query('error');
        if (is_string($error) && $error !== '') {
            throw new RuntimeException('OIDC provider returned an error: '.$error);
        }

        // Compare the state under hash_equals so this cannot be short-circuited on a
        // timing side channel, and always pull() it so it cannot be replayed.
        $state = $request->query('state');
        $expectedState = $request->session()->pull(self::STATE_SESSION_KEY);
        if (! is_string($state) || ! is_string($expectedState) || $expectedState === '' || ! hash_equals($expectedState, $state)) {
            throw new RuntimeException('Invalid OIDC state.');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            throw new RuntimeException('Missing OIDC authorization code.');
        }

        $authSession = $this->pullAuthSession($request);
        $client = $this->client();
        $tokenSet = $this->authorizationService()->callback(
            $client,
            $request->query(),
            $this->redirectUri(),
            $authSession
        );
        $claims = $this->claims($client, $tokenSet);

        return $this->resolver->resolve($claims);
    }

    private function ensureEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('OIDC is not enabled.');
        }
    }

    private function client(): ClientInterface
    {
        $httpClient = $this->httpClient();
        $httpFactory = $this->httpFactory();
        $issuer = (new IssuerBuilder)
            ->setMetadataProviderBuilder(
                (new MetadataProviderBuilder)
                    ->setHttpClient($httpClient)
                    ->setRequestFactory($httpFactory)
                    ->setUriFactory($httpFactory)
            )
            ->setJwksProviderBuilder(new JwksProviderBuilder)
            ->build(rtrim((string) config('services.oidc.issuer'), '/').'/.well-known/openid-configuration');

        $clientId = (string) config('services.oidc.client_id');
        if ($clientId === '') {
            throw new RuntimeException('OIDC client_id is not configured.');
        }

        $redirectUri = $this->redirectUri();
        if ($redirectUri === '') {
            throw new RuntimeException('OIDC redirect URI could not be resolved.');
        }

        $clientSecret = config('services.oidc.client_secret');
        $authMethod = config('services.oidc.token_endpoint_auth_method')
            ?: (filled($clientSecret) ? 'client_secret_basic' : 'none');
        $clientMetadata = [
            'client_id' => $clientId,
            'redirect_uris' => [$redirectUri],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $authMethod,
        ];
        if (filled($clientSecret)) {
            $clientMetadata['client_secret'] = (string) $clientSecret;
        }

        return (new ClientBuilder)
            ->setHttpClient($httpClient)
            ->setIssuer($issuer)
            ->setClientMetadata(ClientMetadata::fromArray($clientMetadata))
            ->build();
    }

    private function authorizationService(): AuthorizationService
    {
        // The setters are declared on the abstract builder and return `self`, so chaining
        // loses the concrete type that actually carries build().
        $builder = new AuthorizationServiceBuilder;
        $builder->setHttpClient($this->httpClient());
        $builder->setRequestFactory($this->httpFactory());

        return $builder->build();
    }

    private function userInfoService(): UserInfoService
    {
        $builder = new UserInfoServiceBuilder;
        $builder->setHttpClient($this->httpClient());
        $builder->setRequestFactory($this->httpFactory());

        return $builder->build();
    }

    private function httpClient(): PsrHttpClient
    {
        return new GuzzleClient([
            'timeout' => (float) config('services.oidc.http_timeout'),
            'http_errors' => false,
        ]);
    }

    private function httpFactory(): RequestFactoryInterface&UriFactoryInterface
    {
        return new HttpFactory;
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(ClientInterface $client, TokenSetInterface $tokenSet): array
    {
        $claims = $tokenSet->claims();

        if (
            $tokenSet->getAccessToken() !== null
            && $client->getIssuer()->getMetadata()->getUserinfoEndpoint() !== null
        ) {
            $userInfo = $this->userInfoService()->getUserInfo($client, $tokenSet);

            $this->assertSubjectsMatch($claims, $userInfo);

            $claims = array_merge($claims, $userInfo);
        }

        if (! isset($claims['sub']) || ! is_string($claims['sub']) || $claims['sub'] === '') {
            throw new RuntimeException('OIDC user claims are missing sub.');
        }

        if (! isset($claims['email']) || ! is_string($claims['email']) || $claims['email'] === '') {
            throw new RuntimeException('OIDC user claims are missing email.');
        }

        return $claims;
    }

    /**
     * Reject a userinfo response describing a different subject than the ID token just verified.
     *
     * Both arrays are taken as plain claim maps on purpose. The library annotates its claims as
     * `array{}&array{...}`, which static analysis reads as the empty shape, and that would make
     * every lookup below look impossible even though the claims are populated at runtime.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $userInfo
     */
    private function assertSubjectsMatch(array $claims, array $userInfo): void
    {
        if (
            isset($claims['sub'], $userInfo['sub'])
            && is_string($claims['sub'])
            && is_string($userInfo['sub'])
            && ! hash_equals($claims['sub'], $userInfo['sub'])
        ) {
            throw new RuntimeException('OIDC ID token and userinfo subjects do not match.');
        }
    }

    private function pullAuthSession(Request $request): AuthSessionInterface
    {
        $authSession = $request->session()->pull(self::AUTH_SESSION_KEY);
        if (! is_array($authSession)) {
            throw new RuntimeException('Missing OIDC auth session.');
        }

        return AuthSession::fromArray($authSession);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function redirectUri(): string
    {
        if (filled(config('services.oidc.redirect_uri'))) {
            return (string) config('services.oidc.redirect_uri');
        }

        return route('oidc.callback');
    }

    /**
     * @return list<string>
     */
    private function scopes(): array
    {
        $scopes = config('services.oidc.scopes');
        if (! is_array($scopes) || $scopes === []) {
            return ['openid', 'profile', 'email'];
        }

        return array_values(array_filter(array_map('strval', $scopes)));
    }
}
