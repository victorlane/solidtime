<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

class OrganizationApiKeyCanNotActOnBehalfOfAUser extends ApiException
{
    public const string KEY = 'organization_api_key_can_not_act_on_behalf_of_a_user';
}
