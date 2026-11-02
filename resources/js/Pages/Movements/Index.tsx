import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { PageProps, Product, Warehouse, StockMovement, PaginatedData } from '@/types';
import { FormEventHandler, useState } from 'react';

interface MovementsPageProps extends PageProps {
    movements: PaginatedData<StockMovement>;
    products: Product[];
    warehouses: Warehouse[];
}

export default function MovementsIndex({ movements, products, warehouses }: MovementsPageProps) {
    const [showForm, setShowForm] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        product_id: products.length > 0 ? String(products[0].id) : '',
        warehouse_id: warehouses.length > 0 ? String(warehouses[0].id) : '',
        type: 'IN' as 'IN' | 'OUT' | 'TRANSFER',
        quantity: '1',
        destination_warehouse_id: warehouses.length > 1 ? String(warehouses[1].id) : '',
        reference: '',
        notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('movements.store'), {
            onSuccess: () => {
                setShowForm(false);
                reset('quantity', 'reference', 'notes');
            },
        });
    };

    const typeBadges: Record<string, { label: string; badgeClass: string }> = {
        IN: { label: 'Entrée (IN)', badgeClass: 'bg-emerald-100 text-emerald-800 border border-emerald-200' },
        OUT: { label: 'Sortie (OUT)', badgeClass: 'bg-rose-100 text-rose-800 border border-rose-200' },
        TRANSFER: { label: 'Transfert', badgeClass: 'bg-blue-100 text-blue-800 border border-blue-200' },
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h2 className="text-xl font-bold leading-tight text-gray-900">
                            🔄 Mouvements de Stock
                        </h2>
                        <p className="text-sm text-gray-500 mt-1">
                            Historique des flux, entrées, sorties et transferts inter-entrepôts avec protection anti-stock négatif.
                        </p>
                    </div>
                    <button
                        onClick={() => setShowForm(!showForm)}
                        className="px-4 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 shadow-sm transition flex items-center gap-2"
                    >
                        <span>{showForm ? '✖ Fermer le formulaire' : '➕ Nouveau mouvement'}</span>
                    </button>
                </div>
            }
        >
            <Head title="Mouvements de stock" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">

                    {/* Formulaire de création de mouvement */}
                    {showForm && (
                        <div className="bg-white rounded-xl shadow-md border border-gray-100 p-6 transition">
                            <div className="border-b border-gray-100 pb-4 mb-6">
                                <h3 className="text-lg font-bold text-gray-900">
                                    Enregistrer une opération de stock
                                </h3>
                                <p className="text-sm text-gray-500">
                                    Toutes les transactions sont atomiques et vérifiées contre les accès concurrents.
                                </p>
                            </div>

                            <form onSubmit={submit} className="space-y-6">
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    {/* Type d'opération */}
                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 mb-1">
                                            Type d'opération *
                                        </label>
                                        <select
                                            value={data.type}
                                            onChange={(e) => setData('type', e.target.value as 'IN' | 'OUT' | 'TRANSFER')}
                                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm font-medium"
                                        >
                                            <option value="IN">📥 Entrée de stock (IN)</option>
                                            <option value="OUT">📤 Sortie de stock (OUT)</option>
                                            <option value="TRANSFER">🔁 Transfert inter-entrepôts</option>
                                        </select>
                                        {errors.type && <p className="text-red-600 text-xs mt-1">{errors.type}</p>}
                                    </div>

                                    {/* Produit */}
                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 mb-1">
                                            Produit *
                                        </label>
                                        <select
                                            value={data.product_id}
                                            onChange={(e) => setData('product_id', e.target.value)}
                                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                            required
                                        >
                                            <option value="">Sélectionner un produit...</option>
                                            {products.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name} ({p.sku})
                                                </option>
                                            ))}
                                        </select>
                                        {errors.product_id && <p className="text-red-600 text-xs mt-1">{errors.product_id}</p>}
                                    </div>

                                    {/* Quantité */}
                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 mb-1">
                                            Quantité *
                                        </label>
                                        <input
                                            type="number"
                                            min="1"
                                            value={data.quantity}
                                            onChange={(e) => setData('quantity', e.target.value)}
                                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                            required
                                        />
                                        {errors.quantity && <p className="text-red-600 text-xs mt-1 font-semibold">{errors.quantity}</p>}
                                    </div>

                                    {/* Entrepôt Source */}
                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 mb-1">
                                            {data.type === 'TRANSFER' ? 'Entrepôt source *' : 'Entrepôt concerné *'}
                                        </label>
                                        <select
                                            value={data.warehouse_id}
                                            onChange={(e) => setData('warehouse_id', e.target.value)}
                                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                            required
                                        >
                                            <option value="">Sélectionner un entrepôt...</option>
                                            {warehouses.map((w) => (
                                                <option key={w.id} value={w.id}>
                                                    {w.name} {w.location ? `(${w.location})` : ''}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.warehouse_id && <p className="text-red-600 text-xs mt-1">{errors.warehouse_id}</p>}
                                    </div>

                                    {/* Entrepôt Destination (si transfert) */}
                                    {data.type === 'TRANSFER' && (
                                        <div>
                                            <label className="block text-sm font-semibold text-gray-700 mb-1">
                                                Entrepôt de destination *
                                            </label>
                                            <select
                                                value={data.destination_warehouse_id}
                                                onChange={(e) => setData('destination_warehouse_id', e.target.value)}
                                                className="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                                required
                                            >
                                                <option value="">Sélectionner la destination...</option>
                                                {warehouses
                                                    .filter((w) => String(w.id) !== String(data.warehouse_id))
                                                    .map((w) => (
                                                        <option key={w.id} value={w.id}>
                                                            {w.name} {w.location ? `(${w.location})` : ''}
                                                        </option>
                                                    ))}
                                            </select>
                                            {errors.destination_warehouse_id && (
                                                <p className="text-red-600 text-xs mt-1">{errors.destination_warehouse_id}</p>
                                            )}
                                        </div>
                                    )}

                                    {/* Référence document */}
                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 mb-1">
                                            Référence (ex: BL-2026-001)
                                        </label>
                                        <input
                                            type="text"
                                            placeholder="ex: FACT-9821, BL-102"
                                            value={data.reference}
                                            onChange={(e) => setData('reference', e.target.value)}
                                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                        />
                                        {errors.reference && <p className="text-red-600 text-xs mt-1">{errors.reference}</p>}
                                    </div>
                                </div>

                                {/* Notes */}
                                <div>
                                    <label className="block text-sm font-semibold text-gray-700 mb-1">
                                        Notes / Justification
                                    </label>
                                    <textarea
                                        rows={2}
                                        placeholder="Commentaires optionnels..."
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    />
                                    {errors.notes && <p className="text-red-600 text-xs mt-1">{errors.notes}</p>}
                                </div>

                                <div className="flex items-center justify-end gap-3 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setShowForm(false)}
                                        className="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition"
                                    >
                                        Annuler
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="px-5 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 shadow transition disabled:opacity-50"
                                    >
                                        {processing ? 'Validation transactionnelle...' : 'Valider le mouvement'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    )}

                    {/* Tableau Historique des mouvements */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                        <div className="p-4 sm:p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                            <h3 className="text-base font-bold text-gray-900">
                                Historique complet ({movements.total} opérations)
                            </h3>
                            <span className="text-xs text-gray-500 font-mono">
                                Verrouillage pessimiste actif (SELECT FOR UPDATE)
                            </span>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date & Heure</th>
                                        <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Type</th>
                                        <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Produit</th>
                                        <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Flux Entrepôts</th>
                                        <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Quantité</th>
                                        <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Réf / Notes</th>
                                        <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Opérateur</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200 text-sm">
                                    {movements.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="px-6 py-12 text-center text-gray-500">
                                                Aucun mouvement pour l'instant. Utilisez le bouton "Nouveau mouvement" pour ajouter un flux.
                                            </td>
                                        </tr>
                                    ) : (
                                        movements.data.map((m) => {
                                            const badge = typeBadges[m.type] || { label: m.type, badgeClass: 'bg-gray-100 text-gray-800' };
                                            return (
                                                <tr key={m.id} className="hover:bg-gray-50/80 transition">
                                                    <td className="px-6 py-4 whitespace-nowrap text-gray-600 text-xs font-mono">
                                                        {new Date(m.created_at).toLocaleString('fr-FR')}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <span className={`px-2.5 py-1 text-xs font-semibold rounded-full ${badge.badgeClass}`}>
                                                            {badge.label}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="font-semibold text-gray-900">{m.product?.name ?? `ID #${m.product_id}`}</div>
                                                        <div className="text-xs text-gray-500 font-mono">{m.product?.sku}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        {m.type === 'TRANSFER' ? (
                                                            <div className="flex items-center gap-1.5 font-medium text-xs">
                                                                <span className="text-gray-700 bg-gray-100 px-2 py-0.5 rounded">{m.warehouse?.name}</span>
                                                                <span className="text-indigo-600 font-bold">➜</span>
                                                                <span className="text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded font-semibold">
                                                                    {m.destination_warehouse?.name ?? `#${m.destination_warehouse_id}`}
                                                                </span>
                                                            </div>
                                                        ) : (
                                                            <span className="text-gray-700 font-medium">
                                                                {m.warehouse?.name}
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <span className={`font-mono font-bold text-sm ${m.type === 'OUT' ? 'text-rose-600' : 'text-emerald-600'}`}>
                                                            {m.type === 'OUT' ? `-${m.quantity}` : `+${m.quantity}`}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <div className="text-xs font-mono text-gray-800">{m.reference || '-'}</div>
                                                        {m.notes && <div className="text-xs text-gray-500 italic mt-0.5 truncate max-w-xs">{m.notes}</div>}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-xs text-gray-600">
                                                        {m.user?.name ?? 'Système'}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination */}
                        {movements.links && movements.links.length > 3 && (
                            <div className="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                                <div className="text-xs text-gray-500">
                                    Affichage de {movements.data.length} sur {movements.total} opérations
                                </div>
                                <div className="flex gap-1">
                                    {movements.links.map((link, idx) => (
                                        link.url ? (
                                            <Link
                                                key={idx}
                                                href={link.url}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                                className={`px-3 py-1 text-xs rounded border transition ${
                                                    link.active
                                                        ? 'bg-indigo-600 text-white border-indigo-600 font-bold'
                                                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-100'
                                                }`}
                                            />
                                        ) : (
                                            <span
                                                key={idx}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                                className="px-3 py-1 text-xs rounded border border-gray-200 text-gray-400 bg-gray-50 cursor-not-allowed"
                                            />
                                        )
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}

