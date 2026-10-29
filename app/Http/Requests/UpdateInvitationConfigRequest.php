<?php

namespace App\Http\Requests;

use App\Services\InvitationConfig\InvitationConfigValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateInvitationConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('event'));
    }

    public function rules(): array
    {
        return [
            'config' => ['required', 'array'],
        ];
    }

    /**
     * The JSON Schema for the config's version decides; errors come back keyed
     * by path, e.g. config.sections.3.start.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! is_array($this->input('config'))) {
                    return;
                }

                foreach (app(InvitationConfigValidator::class)->validate($this->input('config')) as $pointer => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add('config'.str_replace('/', '.', rtrim($pointer, '/')), $message);
                    }
                }
            },
        ];
    }
}
