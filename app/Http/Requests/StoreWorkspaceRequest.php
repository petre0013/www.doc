<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkspaceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:workspaces,slug'],
            'description' => ['nullable', 'string'],
            'position' => ['nullable', 'integer'],
            // Null/absent = ungrouped (top level), the default. Re-grouping later
            // is a structural move and goes through WorkspaceController@regroup.
            'group_id' => ['nullable', 'integer', 'exists:workspace_groups,id'],
        ];
    }
}
