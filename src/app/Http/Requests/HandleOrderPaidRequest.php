<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HandleOrderPaidRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'event' => 'required|string',
            'order_id' => 'required|string',
            'customer.email' => 'required|email',
            'customer.first_name' => 'required|string',
            'customer.last_name' => 'required|string',
            'items' => 'required|array|min:1',
            'payment_status' => 'nullable|string',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}



