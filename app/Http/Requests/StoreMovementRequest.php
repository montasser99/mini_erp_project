<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMovementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'type' => ['required', Rule::in(['IN', 'OUT', 'TRANSFER'])],
            'quantity' => ['required', 'integer', 'min:1'],
            'destination_warehouse_id' => [
                'nullable',
                'integer',
                'exists:warehouses,id',
                Rule::requiredIf($this->input('type') === 'TRANSFER'),
            ],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Get custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Le produit est requis.',
            'product_id.exists' => 'Le produit sélectionné n\'existe pas.',
            'warehouse_id.required' => 'L\'entrepôt est requis.',
            'warehouse_id.exists' => 'L\'entrepôt sélectionné n\'existe pas.',
            'type.required' => 'Le type de mouvement est requis.',
            'type.in' => 'Le type doit être IN, OUT ou TRANSFER.',
            'quantity.required' => 'La quantité est requise.',
            'quantity.min' => 'La quantité doit être au moins 1.',
            'destination_warehouse_id.required_if' => 'L\'entrepôt de destination est requis pour un transfert.',
            'destination_warehouse_id.exists' => 'L\'entrepôt de destination n\'existe pas.',
        ];
    }
}

