<?php

namespace App\Http\Requests\SenderID;

use Illuminate\Foundation\Http\FormRequest;

class CustomSenderID extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('create_sender_id');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
                'sender_id' => 'required',
                'plan'      => 'required|exists:senderid_plans,id',
        ];
    }

    /**
     * custom message
     *
     * @return string[]
     */
    public function messages(): array
    {
        return [
                'sender_id.unique' => __('locale.sender_id.sender_id_available', ['sender_id' => $this->sender_id]),
        ];
    }
}
