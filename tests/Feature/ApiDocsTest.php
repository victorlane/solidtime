<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCaseWithDatabase;

class ApiDocsTest extends TestCaseWithDatabase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        // Building the OpenAPI document allocates a lot of memory and leaves cyclic references
        // behind, so collect them eagerly to keep this test class within the default memory limit.
        gc_collect_cycles();
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function organizationRoleProvider(): array
    {
        return [
            'owner' => [Role::Owner],
            'manager' => [Role::Manager],
            'employee' => [Role::Employee],
        ];
    }

    /**
     * Every role is checked through the gate instead of the HTTP route, because rendering the docs
     * builds the whole OpenAPI document and that costs roughly 85 MB per request. The requests
     * below cover the HTTP stack once for the least privileged role.
     */
    #[DataProvider('organizationRoleProvider')]
    public function test_every_organization_role_is_allowed_to_view_the_api_docs(Role $role): void
    {
        // Arrange
        $data = $this->createUserWithRole($role);

        // Act
        $allowed = Gate::forUser($data->user)->allows('viewApiDocs');

        // Assert
        $this->assertTrue($allowed);
    }

    public function test_member_of_an_organization_can_view_the_api_docs_ui(): void
    {
        // Arrange
        $data = $this->createUserWithRole(Role::Employee);

        // Act
        $response = $this->actingAs($data->user)->get(route('scramble.docs.ui'));

        // Assert
        // The rendered document is a few hundred kilobytes, so only cheap substring checks are
        // used here. Decoding it would multiply the memory needed by this test.
        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertTrue(str_contains($content, '<elements-api'), 'The Stoplight UI is missing.');
        // The UI embeds the generated document, so this also asserts that building the document
        // succeeds for a signed in member.
        $this->assertTrue(
            str_contains($content, 'docs.apiDescriptionDocument = {'),
            'The generated OpenAPI document is missing.'
        );
    }

    public function test_member_of_an_organization_can_view_the_generated_openapi_document(): void
    {
        // Arrange
        $data = $this->createUserWithRole(Role::Employee);

        // Act
        $response = $this->actingAs($data->user)->get(route('scramble.docs.document'));

        // Assert
        // The document is a few hundred kilobytes, so it is inspected as a string instead of
        // decoding it into a PHP array, which would need several times the memory.
        $response->assertOk();
        $response->assertHeader('content-type', 'application/json');
        $content = (string) $response->getContent();
        $this->assertTrue(
            str_starts_with($content, '{'."\n".'    "openapi": "3.1.0",'),
            'The response is not an OpenAPI 3.1.0 document.'
        );
        $this->assertTrue(
            str_contains($content, 'time-entries'),
            'The document does not describe the time entry endpoints.'
        );
    }

    public function test_guest_can_not_view_the_api_docs_ui(): void
    {
        // Act
        $response = $this->get(route('scramble.docs.ui'));

        // Assert
        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_guest_can_not_view_the_generated_openapi_document(): void
    {
        // Act
        $response = $this->get(route('scramble.docs.document'));

        // Assert
        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_guest_is_not_allowed_by_the_view_api_docs_gate(): void
    {
        // Act
        $allowed = Gate::forUser(null)->allows('viewApiDocs');

        // Assert
        $this->assertFalse($allowed);
    }

    public function test_user_with_an_unverified_email_can_not_view_the_api_docs_ui(): void
    {
        // Arrange
        $user = User::factory()->unverified()->create();

        // Act
        $response = $this->actingAs($user)->get(route('scramble.docs.ui'));

        // Assert
        $response->assertRedirect(route('verification.notice'));
    }

    public function test_user_without_an_organization_can_not_view_the_api_docs_ui(): void
    {
        // Arrange
        $user = User::factory()->create();

        // Act
        $response = $this->actingAs($user)->get(route('scramble.docs.ui'));

        // Assert
        $response->assertForbidden();
    }
}
