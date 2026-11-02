<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'location',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Les niveaux de stock de l'entrepôt.
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * Les mouvements liés à cet entrepôt (source ou unique).
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Les mouvements de transfert dont cet entrepôt est le destinataire.
     */
    public function destinationStockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'destination_warehouse_id');
    }
}
