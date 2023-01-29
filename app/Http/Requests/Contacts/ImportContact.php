<?php

namespace App\Http\Requests\Contacts;

use App\Rules\ExcelRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportContact extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('create_contact');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {

        if (request()->option_toggle == 'on') {
            return [
                    'import_file' => ['required', new ExcelRule(request()->file('import_file'))],
            ];
        }

        return [
                'recipients' => 'required',
                'delimiter'  => 'required',
        ];
    }
}
