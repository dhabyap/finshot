<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customerId' => ['required', 'string', 'max:10', 'exists:customers,customer_id'],
            'sendCurrency' => ['required', 'string', 'in:KRW'],
            'sendAmount' => ['required', 'regex:/^[0-9]+$/'],
            'receiveCurrency' => ['required', 'string', 'in:PHP'],
            'recipientName' => ['required', 'string', 'max:100'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $message = collect($validator->errors()->all())->first() ?? 'Invalid request';

        throw new HttpResponseException(response()->json([
            'error' => 'VALIDATION_ERROR',
            'message' => $message,
        ], 400));
    }

    protected function prepareForValidation(): void
    {
        $key = trim((string) $this->header('Idempotency-Key', ''));
        if ($key === '' || strlen($key) > 100) {
            throw new HttpResponseException(response()->json([
                'error' => 'VALIDATION_ERROR',
                'message' => 'Idempotency-Key header is required (1-100 characters)',
            ], 400));
        }

        $this->attributes->set('idempotencyKey', $key);
    }

    public function idempotencyKey(): string
    {
        return $this->attributes->get('idempotencyKey');
    }
}
