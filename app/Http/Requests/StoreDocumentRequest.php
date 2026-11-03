<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'parent_id' => [
                'nullable', 
                'integer', 
                \Illuminate\Validation\Rule::exists('documents', 'id')->where('workspace_id', $this->input('workspace_id'))
            ],
            // Create a page straight into a folder. Scoped to the same workspace
            // for the same reason parent_id is: the folder id comes from the
            // client, and a cross-workspace one would violate the DB invariant.
            // The "top-level pages only" rule is enforced in the controller, so
            // it can be a readable 422 like refile()'s.
            'folder_id' => [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('document_folders', 'id')->where('workspace_id', $this->input('workspace_id')),
            ],
            'position' => ['nullable', 'integer'],
            'content' => ['nullable', 'array'],
            'template_id' => ['nullable', 'integer', 'exists:templates,id'],
            'metadata' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'exists:tags,id'],
        ];
    }
}
