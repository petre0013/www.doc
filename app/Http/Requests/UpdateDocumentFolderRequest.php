<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rename only. A folder has no slug to keep unique and no body to edit, and its
 * workspace never changes — moving content between workspaces is a document
 * concern (DocumentController@move), not a folder one.
 */
class UpdateDocumentFolderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
