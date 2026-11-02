import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { PageProps, StockMovement, StockLevel, Warehouse } from '@/types';

interface DashboardProps extends PageProps {
    stats: {
        totalProducts: number;
        totalWarehouses: number;
        totalMovements: number;
        totalStockValue: string;
    };
    recentMovements: StockMovement[];
    lowStockAlerts: StockLevel[];
    outOfStock: StockLevel[];
    stockByWarehouse: (Warehouse & { total_quantity: number })[];
}

export default function Dashboard({
    stats,
    recentMovements,
    lowStockAlerts,
    outOfStock,
    stockByWarehouse,
}: DashboardProps) {
    const typeLabels: Record<string, { label: string; color: string }> = {
        IN: { label: 'Entrée', color: 'bg-green-100 text-green-800' },
        OUT: { label: 'Sortie', color: 'bg-red-100 text-red-800' },
        TRANSFER: { label: 'Transfert', color: 'bg-blue-100 text-blue-800' },
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    📊 Tableau de bord
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    {/* Statistiques principales */}
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4 mb-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow p-6">
                            <div className="text-sm font-medium text-gray-500">Produits</div>
                            <div className="mt-2 text-3xl font-bold text-gray-900">{stats.totalProducts}</div>
                        </div>
                        <div className="overflow-hidden rounded-lg bg-white shadow p-6">
                            <div className="text-sm font-medium text-gray-500">Entrepôts actifs</div>
                            <div className="mt-2 text-3xl font-bold text-gray-900">{stats.totalWarehouses}</div>
                        </div>
                        <div className="overflow-hidden rounded-lg bg-white shadow p-6">
                            <div className="text-sm font-medium text-gray-500">Mouvements total</div>
                            <div className="mt-2 text-3xl font-bold text-gray-900">{stats.totalMovements}</div>
                        </div>
                        <div className="overflow-hidden rounded-lg bg-white shadow p-6">
                            <div className="text-sm font-medium text-gray-500">Valeur du stock</div>
                            <div className="mt-2 text-3xl font-bold text-indigo-600">{stats.totalStockValue} €</div>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                        {/* Stock par entrepôt */}
                        <div className="overflow-hidden rounded-lg bg-white shadow">
                            <div className="p-6">
                                <h3 className="text-lg font-semibold text-gray-800 mb-4">🏭 Stock par entrepôt</h3>
                                {stockByWarehouse.length === 0 ? (
                                    <p className="text-gray-500">Aucun entrepôt configuré.</p>
                                ) : (
                                    <div className="space-y-3">
                                        {stockByWarehouse.map((warehouse) => (
                                            <div key={warehouse.id} className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                                <div>
                                                    <div className="font-medium text-gray-800">{warehouse.name}</div>
                                                    <div className="text-sm text-gray-500">{warehouse.location || 'Pas de localisation'}</div>
                                                </div>
                                                <div className="text-2xl font-bold text-indigo-600">
                                                    {warehouse.total_quantity || 0}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Alertes */}
                        <div className="overflow-hidden rounded-lg bg-white shadow">
                            <div className="p-6">
                                <h3 className="text-lg font-semibold text-gray-800 mb-4">⚠️ Alertes de stock</h3>
                                {lowStockAlerts.length === 0 && outOfStock.length === 0 ? (
                                    <p className="text-green-600">✅ Tous les stocks sont à un niveau normal.</p>
                                ) : (
                                    <div className="space-y-2">
                                        {outOfStock.map((item) => (
                                            <div key={item.id} className="flex items-center justify-between p-3 bg-red-50 rounded-lg border border-red-200">
                                                <div>
                                                    <span className="font-medium text-red-800">{item.product?.name}</span>
                                                    <span className="text-sm text-red-600 ml-2">({item.warehouse?.name})</span>
                                                </div>
                                                <span className="px-2 py-1 text-xs font-bold text-white bg-red-500 rounded">RUPTURE</span>
                                            </div>
                                        ))}
                                        {lowStockAlerts.map((item) => (
                                            <div key={item.id} className="flex items-center justify-between p-3 bg-yellow-50 rounded-lg border border-yellow-200">
                                                <div>
                                                    <span className="font-medium text-yellow-800">{item.product?.name}</span>
                                                    <span className="text-sm text-yellow-600 ml-2">({item.warehouse?.name})</span>
                                                </div>
                                                <span className="px-2 py-1 text-xs font-bold text-yellow-800 bg-yellow-200 rounded">
                                                    {item.quantity} restant(s)
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Derniers mouvements */}
                    <div className="overflow-hidden rounded-lg bg-white shadow">
                        <div className="p-6">
                            <h3 className="text-lg font-semibold text-gray-800 mb-4">📋 Derniers mouvements</h3>
                            {recentMovements.length === 0 ? (
                                <p className="text-gray-500">Aucun mouvement enregistré.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produit</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entrepôt</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Qté</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Utilisateur</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200">
                                            {recentMovements.map((movement) => (
                                                <tr key={movement.id} className="hover:bg-gray-50">
                                                    <td className="px-4 py-3 text-sm text-gray-600">
                                                        {new Date(movement.created_at).toLocaleString('fr-FR')}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span className={`px-2 py-1 text-xs font-semibold rounded-full ${typeLabels[movement.type].color}`}>
                                                            {typeLabels[movement.type].label}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-sm font-medium text-gray-900">
                                                        {movement.product?.name}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-gray-600">
                                                        {movement.warehouse?.name}
                                                        {movement.type === 'TRANSFER' && movement.destination_warehouse && (
                                                            <span className="text-indigo-600"> → {movement.destination_warehouse.name}</span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm font-bold text-gray-900">{movement.quantity}</td>
                                                    <td className="px-4 py-3 text-sm text-gray-600">{movement.user?.name}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
