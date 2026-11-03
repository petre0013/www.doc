<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A folder has no slug (it is never addressable) and no body — name + order is
 * the whole surface. `workspace_id` is taken from the route, never the payload,
 * so a folder can't be created into a workspace the URL didn't authorize.
 */
class StoreDocumentFolderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer'],
        ];
    }
}
