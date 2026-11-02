import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
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
    const typeLabels: Record<string, { label: string; color: string; icon: string }> = {
        IN: { label: 'Entrée (IN)', color: 'bg-emerald-100 text-emerald-800 border border-emerald-200', icon: '📥' },
        OUT: { label: 'Sortie (OUT)', color: 'bg-rose-100 text-rose-800 border border-rose-200', icon: '📤' },
        TRANSFER: { label: 'Transfert', color: 'bg-blue-100 text-blue-800 border border-blue-200', icon: '🔁' },
    };

    const maxStock = Math.max(...stockByWarehouse.map(w => w.total_quantity || 0), 1);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h2 className="text-2xl font-black tracking-tight text-gray-900 flex items-center gap-2">
                            <span>📊 Tableau de Bord ERP</span>
                            <span className="text-xs font-semibold px-2.5 py-0.5 rounded-full bg-indigo-100 text-indigo-700">
                                Temps Réel
                            </span>
                        </h2>
                        <p className="text-sm text-gray-500 mt-1">
                            Vue consolidée des stocks, valorisation financière, transferts multi-entrepôts et alertes.
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Link
                            href={route('movements.index')}
                            className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg shadow-sm transition flex items-center gap-1.5"
                        >
                            <span>➕ Opérer un mouvement</span>
                        </Link>
                    </div>
                </div>
            }
        >
            <Head title="Tableau de bord - Mini ERP" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-8">

                    {/* 4 Cartes KPI Principales */}
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="overflow-hidden rounded-xl bg-white p-6 shadow-sm border border-gray-100 hover:shadow-md transition">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-bold text-gray-400 uppercase tracking-wider">Références Articles</span>
                                <span className="p-2 bg-indigo-50 text-indigo-600 rounded-lg text-lg">📦</span>
                            </div>
                            <div className="mt-4 flex items-baseline justify-between">
                                <div className="text-3xl font-extrabold text-gray-900">{stats.totalProducts}</div>
                                <Link href={route('products.index')} className="text-xs font-semibold text-indigo-600 hover:underline">
                                    Voir catalogue →
                                </Link>
                            </div>
                        </div>

                        <div className="overflow-hidden rounded-xl bg-white p-6 shadow-sm border border-gray-100 hover:shadow-md transition">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-bold text-gray-400 uppercase tracking-wider">Entrepôts Actifs</span>
                                <span className="p-2 bg-blue-50 text-blue-600 rounded-lg text-lg">🏭</span>
                            </div>
                            <div className="mt-4 flex items-baseline justify-between">
                                <div className="text-3xl font-extrabold text-gray-900">{stats.totalWarehouses}</div>
                                <Link href={route('warehouses.index')} className="text-xs font-semibold text-blue-600 hover:underline">
                                    Voir sites →
                                </Link>
                            </div>
                        </div>

                        <div className="overflow-hidden rounded-xl bg-white p-6 shadow-sm border border-gray-100 hover:shadow-md transition">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-bold text-gray-400 uppercase tracking-wider">Mouvements Enregistrés</span>
                                <span className="p-2 bg-emerald-50 text-emerald-600 rounded-lg text-lg">🔄</span>
                            </div>
                            <div className="mt-4 flex items-baseline justify-between">
                                <div className="text-3xl font-extrabold text-gray-900">{stats.totalMovements}</div>
                                <span className="text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded">
                                    Audit immuable
                                </span>
                            </div>
                        </div>

                        <div className="overflow-hidden rounded-xl bg-white p-6 shadow-sm border border-gray-100 hover:shadow-md transition">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-bold text-gray-400 uppercase tracking-wider">Valeur Totale Stock</span>
                                <span className="p-2 bg-purple-50 text-purple-600 rounded-lg text-lg">💰</span>
                            </div>
                            <div className="mt-4 flex items-baseline justify-between">
                                <div className="text-2xl sm:text-3xl font-black text-indigo-700">{stats.totalStockValue} €</div>
                                <span className="text-[11px] text-gray-400">Prix unitaire</span>
                            </div>
                        </div>
                    </div>

                    {/* Section Intermédiaire : Distribution par entrepôt & Alertes */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        {/* Stock par entrepôt avec barres de progression */}
                        <div className="rounded-xl bg-white shadow-sm border border-gray-100 p-6">
                            <div className="flex items-center justify-between mb-6">
                                <div>
                                    <h3 className="text-lg font-bold text-gray-900 flex items-center gap-2">
                                        <span>🏭 Distribution du Stock par Site</span>
                                    </h3>
                                    <p className="text-xs text-gray-500">Niveaux de stockage consolidés</p>
                                </div>
                                <Link href={route('warehouses.index')} className="text-xs text-indigo-600 font-semibold hover:underline">
                                    Gérer les sites
                                </Link>
                            </div>

                            {stockByWarehouse.length === 0 ? (
                                <p className="text-gray-500 text-sm">Aucun entrepôt configuré.</p>
                            ) : (
                                <div className="space-y-5">
                                    {stockByWarehouse.map((w) => {
                                        const percentage = Math.round(((w.total_quantity || 0) / maxStock) * 100);
                                        return (
                                            <div key={w.id} className="space-y-1.5">
                                                <div className="flex justify-between items-center text-sm font-medium">
                                                    <span className="text-gray-800 font-semibold">{w.name}</span>
                                                    <span className="font-mono font-bold text-indigo-600">
                                                        {w.total_quantity || 0} unités
                                                    </span>
                                                </div>
                                                <div className="w-full bg-gray-100 rounded-full h-2.5 overflow-hidden">
                                                    <div
                                                        className="bg-indigo-600 h-2.5 rounded-full transition-all duration-500"
                                                        style={{ width: `${Math.max(percentage, 5)}%` }}
                                                    ></div>
                                                </div>
                                                <div className="text-[11px] text-gray-400 flex justify-between">
                                                    <span>{w.location || 'Localisation non définie'}</span>
                                                    <span>{percentage}% du site principal</span>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </div>

                        {/* Alertes de Stock (Rupture / Seuil bas) */}
                        <div className="rounded-xl bg-white shadow-sm border border-gray-100 p-6">
                            <div className="flex items-center justify-between mb-6">
                                <div>
                                    <h3 className="text-lg font-bold text-gray-900 flex items-center gap-2">
                                        <span>⚠️ Alertes de Disponibilité</span>
                                    </h3>
                                    <p className="text-xs text-gray-500">Seuil de réapprovisionnement (≤ 10 unités)</p>
                                </div>
                                <span className={`text-xs px-2.5 py-0.5 rounded-full font-bold ${
                                    outOfStock.length > 0
                                        ? 'bg-rose-100 text-rose-800'
                                        : lowStockAlerts.length > 0
                                        ? 'bg-yellow-100 text-yellow-800'
                                        : 'bg-emerald-100 text-emerald-800'
                                }`}>
                                    {outOfStock.length + lowStockAlerts.length} alerte(s)
                                </span>
                            </div>

                            {lowStockAlerts.length === 0 && outOfStock.length === 0 ? (
                                <div className="p-8 text-center bg-emerald-50/50 rounded-xl border border-emerald-100">
                                    <span className="text-3xl block mb-2">✅</span>
                                    <h4 className="font-bold text-emerald-900 text-sm">Niveaux de stock optimaux</h4>
                                    <p className="text-xs text-emerald-700 mt-1">
                                        Aucun produit n'est en rupture ni sous le seuil d'alerte.
                                    </p>
                                </div>
                            ) : (
                                <div className="space-y-3 max-h-72 overflow-y-auto pr-1">
                                    {outOfStock.map((item) => (
                                        <div key={item.id} className="flex items-center justify-between p-3.5 bg-rose-50 rounded-lg border border-rose-200">
                                            <div>
                                                <div className="font-bold text-rose-900 text-sm">{item.product?.name}</div>
                                                <div className="text-xs text-rose-700">
                                                    Entrepôt : {item.warehouse?.name} • SKU : {item.product?.sku}
                                                </div>
                                            </div>
                                            <span className="px-2.5 py-1 text-xs font-black text-white bg-rose-600 rounded shadow-sm">
                                                0 EN STOCK
                                            </span>
                                        </div>
                                    ))}

                                    {lowStockAlerts.map((item) => (
                                        <div key={item.id} className="flex items-center justify-between p-3.5 bg-amber-50 rounded-lg border border-amber-200">
                                            <div>
                                                <div className="font-bold text-amber-900 text-sm">{item.product?.name}</div>
                                                <div className="text-xs text-amber-700">
                                                    Entrepôt : {item.warehouse?.name} • SKU : {item.product?.sku}
                                                </div>
                                            </div>
                                            <span className="px-2.5 py-1 text-xs font-bold text-amber-800 bg-amber-200 rounded">
                                                {item.quantity} restant(s)
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Derniers mouvements enregistrés */}
                    <div className="rounded-xl bg-white shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                            <div>
                                <h3 className="text-lg font-bold text-gray-900">
                                    📋 Activité Récente (10 Dernières Opérations)
                                </h3>
                                <p className="text-xs text-gray-500">
                                    Transactions validées avec verrouillage pessimiste
                                </p>
                            </div>
                            <Link
                                href={route('movements.index')}
                                className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 hover:underline"
                            >
                                Voir tout l'historique →
                            </Link>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-100 text-sm">
                                <thead className="bg-gray-50/80">
                                    <tr>
                                        <th className="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date & Heure</th>
                                        <th className="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Type</th>
                                        <th className="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Produit</th>
                                        <th className="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Entrepôt(s)</th>
                                        <th className="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Quantité</th>
                                        <th className="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Opérateur</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 bg-white">
                                    {recentMovements.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="px-6 py-8 text-center text-gray-500 text-xs">
                                                Aucun mouvement enregistré.
                                            </td>
                                        </tr>
                                    ) : (
                                        recentMovements.map((movement) => {
                                            const badge = typeLabels[movement.type] || { label: movement.type, color: 'bg-gray-100 text-gray-800', icon: '•' };
                                            return (
                                                <tr key={movement.id} className="hover:bg-gray-50/80 transition">
                                                    <td className="px-6 py-3.5 text-xs text-gray-500 font-mono">
                                                        {new Date(movement.created_at).toLocaleString('fr-FR')}
                                                    </td>
                                                    <td className="px-6 py-3.5">
                                                        <span className={`px-2.5 py-1 text-xs font-semibold rounded-full inline-flex items-center gap-1 ${badge.color}`}>
                                                            <span>{badge.icon}</span>
                                                            <span>{badge.label}</span>
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-3.5 font-medium text-gray-900">
                                                        {movement.product?.name}
                                                    </td>
                                                    <td className="px-6 py-3.5 text-xs text-gray-600">
                                                        {movement.type === 'TRANSFER' && movement.destination_warehouse ? (
                                                            <div className="flex items-center gap-1">
                                                                <span className="font-semibold text-gray-700">{movement.warehouse?.name}</span>
                                                                <span className="text-indigo-600 font-bold">➜</span>
                                                                <span className="font-semibold text-indigo-700">{movement.destination_warehouse.name}</span>
                                                            </div>
                                                        ) : (
                                                            movement.warehouse?.name
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-3.5 font-mono font-bold">
                                                        <span className={movement.type === 'OUT' ? 'text-rose-600' : 'text-emerald-600'}>
                                                            {movement.type === 'OUT' ? `-${movement.quantity}` : `+${movement.quantity}`}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-3.5 text-xs text-gray-500">
                                                        {movement.user?.name ?? 'Admin'}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
