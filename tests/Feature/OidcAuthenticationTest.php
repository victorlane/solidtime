<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Service\Auth\OidcService;
use App\Service\Auth\OidcUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class OidcAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function enableOidc(array $overrides = []): void
    {
        config(array_merge([
            'services.oidc.enabled' => true,
            'services.oidc.issuer' => 'https://idp.example.com',
            'services.oidc.client_id' => 'client-id',
        ], $overrides));
    }

    // --- Route/controller wiring -------------------------------------------------------

    public function test_oidc_redirect_is_not_available_when_disabled(): void
    {
        config(['services.oidc.enabled' => false]);

        $this->get('/auth/oidc/redirect')->assertNotFound();
    }

    public function test_oidc_callback_is_not_available_when_disabled(): void
    {
        config(['services.oidc.enabled' => false]);

        $this->get('/auth/oidc/callback')->assertNotFound();
    }

    public function test_oidc_redirect_delegates_to_oidc_service(): void
    {
        $this->enableOidc();
        $this->mock(OidcService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isEnabled')->once()->andReturn(true);
            $mock->shouldReceive('authorizationRedirectUrl')
                ->once()
                ->andReturn('https://idp.example.com/authorize?client_id=client-id');
        });

        $this->get('/auth/oidc/redirect')
            ->assertRedirect('https://idp.example.com/authorize?client_id=client-id');
    }

    public function test_oidc_callback_authenticates_user_returned_by_oidc_service(): void
    {
        $this->enableOidc();
        $user = User::factory()->create();

        $this->mock(OidcService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('isEnabled')->once()->andReturn(true);
            $mock->shouldReceive('authenticateCallback')->once()->andReturn($user);
        });

        $this->get('/auth/oidc/callback?code=code-1&state=expected-state')
            ->assertRedirect(RouteServiceProvider::HOME);

        $this->assertAuthenticatedAs($user);
    }

    public function test_oidc_callback_with_invalid_or_tampered_state_is_rejected(): void
    {
        $this->enableOidc();

        // No state was ever put into the session (i.e. the redirect leg never happened for
        // this session), so any state value presented at the callback must be rejected.
        $response = $this->withSession([])
            ->get('/auth/oidc/callback?code=some-code&state=tampered-state');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_oidc_callback_rejects_mismatched_state_even_when_a_state_is_in_session(): void
    {
        $this->enableOidc();

        $response = $this->withSession([OidcService::STATE_SESSION_KEY => 'expected-state'])
            ->get('/auth/oidc/callback?code=some-code&state=different-state');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_oidc_callback_rejects_provider_error(): void
    {
        $this->enableOidc();

        $response = $this->withSession([OidcService::STATE_SESSION_KEY => 'expected-state'])
            ->get('/auth/oidc/callback?error=access_denied&state=expected-state');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // --- Account-linking policy (OidcUserResolver) --------------------------------------

    private function resolver(): OidcUserResolver
    {
        return app(OidcUserResolver::class);
    }

    public function test_resolve_auto_provisions_a_new_user_and_organization(): void
    {
        $this->assertDatabaseMissing('users', ['email' => 'new.user@example.com']);

        $user = $this->resolver()->resolve([
            'sub' => 'sub-1',
            'email' => 'New.User@Example.com',
            'email_verified' => true,
            'name' => 'New User',
        ]);

        $this->assertSame('sub-1', $user->oidc_sub);
        $this->assertSame('new.user@example.com', $user->email);
        $this->assertSame('New User', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->currentOrganization);
    }

    public function test_resolve_matches_returning_user_by_oidc_sub_first(): void
    {
        $user = User::factory()->create([
            'email' => 'linked@example.com',
            'oidc_sub' => 'sub-existing',
        ]);

        // A second, unrelated local account happens to share the claimed email - if email
        // matching were consulted first this would incorrectly resolve to that other account.
        User::factory()->create(['email' => 'someone-else@example.com']);

        $resolved = $this->resolver()->resolve([
            'sub' => 'sub-existing',
            'email' => 'linked@example.com',
            'email_verified' => true,
            'name' => 'Updated Name',
        ]);

        $this->assertTrue($resolved->is($user));
        $this->assertSame('Updated Name', $resolved->fresh()->name);
    }

    public function test_resolve_oidc_sub_match_takes_priority_over_email_match(): void
    {
        // Two different local accounts: one already linked to this OIDC subject under a
        // different email, and one (unlinked) that happens to have the claimed email.
        $linkedUser = User::factory()->create([
            'email' => 'linked-account@example.com',
            'oidc_sub' => 'sub-priority',
        ]);
        $emailMatchUser = User::factory()->create([
            'email' => 'claimed-email@example.com',
            'oidc_sub' => null,
        ]);

        $resolved = $this->resolver()->resolve([
            'sub' => 'sub-priority',
            'email' => 'claimed-email@example.com',
            'email_verified' => true,
            'name' => 'Someone',
        ]);

        $this->assertTrue($resolved->is($linkedUser));
        $this->assertFalse($resolved->is($emailMatchUser));
        // The email-matching account must be left untouched and unlinked.
        $this->assertNull($emailMatchUser->fresh()->oidc_sub);
    }

    public function test_resolve_does_not_auto_link_unverified_email_to_an_existing_account(): void
    {
        $victim = User::factory()->create([
            'email' => 'victim@example.com',
            'oidc_sub' => null,
        ]);

        $this->expectException(RuntimeException::class);

        try {
            $this->resolver()->resolve([
                'sub' => 'attacker-sub',
                'email' => 'victim@example.com',
                'email_verified' => false,
                'name' => 'Attacker',
            ]);
        } finally {
            // The victim's existing account must remain completely untouched: not linked to
            // the attacker's OIDC identity, name unchanged.
            $victim->refresh();
            $this->assertNull($victim->oidc_sub);
            $this->assertNotSame('Attacker', $victim->name);
        }
    }

    public function test_resolve_allows_verified_email_auto_link_when_enabled(): void
    {
        $existing = User::factory()->create([
            'email' => 'verified-link@example.com',
            'oidc_sub' => null,
        ]);

        $resolved = $this->resolver()->resolve([
            'sub' => 'sub-verified',
            'email' => 'verified-link@example.com',
            'email_verified' => true,
            'name' => 'Verified User',
        ]);

        $this->assertTrue($resolved->is($existing));
        $this->assertSame('sub-verified', $resolved->fresh()->oidc_sub);
    }

    public function test_resolve_rejects_email_auto_link_when_auto_link_disabled(): void
    {
        config(['services.oidc.auto_link' => false]);

        $existing = User::factory()->create([
            'email' => 'no-auto-link@example.com',
            'oidc_sub' => null,
        ]);

        $this->expectException(RuntimeException::class);

        try {
            $this->resolver()->resolve([
                'sub' => 'sub-no-link',
                'email' => 'no-auto-link@example.com',
                'email_verified' => true,
                'name' => 'Someone',
            ]);
        } finally {
            $this->assertNull($existing->fresh()->oidc_sub);
        }
    }

    public function test_resolve_rejects_account_already_linked_to_a_different_oidc_subject(): void
    {
        $existing = User::factory()->create([
            'email' => 'already-linked@example.com',
            'oidc_sub' => 'original-sub',
        ]);

        $this->expectException(RuntimeException::class);

        try {
            $this->resolver()->resolve([
                'sub' => 'different-sub',
                'email' => 'already-linked@example.com',
                'email_verified' => true,
                'name' => 'Someone',
            ]);
        } finally {
            $this->assertSame('original-sub', $existing->fresh()->oidc_sub);
        }
    }

    public function test_resolve_rejects_auto_registration_when_disabled_and_no_match(): void
    {
        config(['services.oidc.auto_register' => false]);

        $this->expectException(RuntimeException::class);

        $this->resolver()->resolve([
            'sub' => 'brand-new-sub',
            'email' => 'brand-new@example.com',
            'email_verified' => true,
            'name' => 'Brand New',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'brand-new@example.com']);
    }
}
