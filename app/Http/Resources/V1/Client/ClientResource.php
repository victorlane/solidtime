<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Client;

use App\Http\Resources\V1\BaseResource;
use App\Models\Client;
use Illuminate\Http\Request;

/**
 * @property Client $resource
 */
class ClientResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, string|bool|int|\stdClass|null>
     */
    public function toArray(Request $request): array
    {
        return [
            /** ID */
            'id' => $this->resource->id,
            /** Name */
            'name' => $this->resource->name,
            /** Whether the client is archived */
            'is_archived' => $this->resource->is_archived,
            /** @var array<string, string> $metadata Custom metadata as key-value pairs, f.e. for linking the client to external systems */
            // Cast to object so an empty metadata map serialises as {} rather than [].
            // PHP encodes an empty array as a JSON array, which makes the field change
            // type depending on whether it happens to be populated and breaks typed clients.
            'metadata' => (object) ($this->resource->metadata ?? []),
            /** When the tag was created */
            'created_at' => $this->formatDateTime($this->resource->created_at),
            /** When the tag was last updated */
            'updated_at' => $this->formatDateTime($this->resource->updated_at),
        ];
    }
}
