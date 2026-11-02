import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { PageProps, Product } from '@/types';
import { FormEventHandler, useState } from 'react';

interface ProductsPageProps extends PageProps {
    products: Product[];
}

export default function ProductsIndex({ products }: ProductsPageProps) {
    const [showForm, setShowForm] = useState(false);
    const [editingProduct, setEditingProduct] = useState<Product | null>(null);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        sku: '',
        description: '',
        price: '0',
    });

    const openCreate = () => {
        setEditingProduct(null);
        reset();
        setShowForm(true);
    };

    const openEdit = (product: Product) => {
        setEditingProduct(product);
        setData({
            name: product.name,
            sku: product.sku,
            description: product.description || '',
            price: String(product.price),
        });
        setShowForm(true);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editingProduct) {
            put(route('products.update', editingProduct.id), {
                onSuccess: () => { setShowForm(false); reset(); },
            });
        } else {
            post(route('products.store'), {
                onSuccess: () => { setShowForm(false); reset(); },
            });
        }
    };

    const { delete: destroy } = useForm({});
    const handleDelete = (product: Product) => {
        if (confirm(`Supprimer le produit "${product.name}" ?`)) {
            destroy(route('products.destroy', product.id));
        }
    };

    // Calculer le stock total d'un produit
    const getTotalStock = (product: Product) => {
        return product.stock_levels?.reduce((sum, sl) => sum + sl.quantity, 0) || 0;
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex justify-between items-center">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        📦 Produits
                    </h2>
                    <button
                        onClick={openCreate}
                        className="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition"
                    >
                        + Nouveau produit
                    </button>
                </div>
            }
        >
            <Head title="Produits" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    {/* Formulaire modal */}
                    {showForm && (
                        <div className="mb-6 overflow-hidden rounded-lg bg-white shadow p-6">
                            <h3 className="text-lg font-semibold mb-4">
                                {editingProduct ? 'Modifier le produit' : 'Ajouter un produit'}
                            </h3>
                            <form onSubmit={submit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">Nom *</label>
                                    <input
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        required
                                    />
                                    {errors.name && <p className="text-red-500 text-xs mt-1">{errors.name}</p>}
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">SKU *</label>
                                    <input
                                        type="text"
                                        value={data.sku}
                                        onChange={(e) => setData('sku', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        required
                                    />
                                    {errors.sku && <p className="text-red-500 text-xs mt-1">{errors.sku}</p>}
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">Prix (€) *</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.price}
                                        onChange={(e) => setData('price', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        required
                                    />
                                    {errors.price && <p className="text-red-500 text-xs mt-1">{errors.price}</p>}
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">Description</label>
                                    <input
                                        type="text"
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                                <div className="md:col-span-2 flex gap-3">
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                                    >
                                        {processing ? 'En cours...' : (editingProduct ? 'Modifier' : 'Créer')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => { setShowForm(false); reset(); }}
                                        className="px-4 py-2 bg-gray-200 text-gray-700 text-sm rounded-lg hover:bg-gray-300"
                                    >
                                        Annuler
                                    </button>
                                </div>
                            </form>
                        </div>
                    )}

                    {/* Liste des produits */}
                    <div className="overflow-hidden rounded-lg bg-white shadow">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Stock total</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Détail stock</th>
                                        <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200">
                                    {products.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="px-6 py-8 text-center text-gray-500">
                                                Aucun produit. Cliquez sur "+ Nouveau produit" pour commencer.
                                            </td>
                                        </tr>
                                    ) : (
                                        products.map((product) => (
                                            <tr key={product.id} className="hover:bg-gray-50">
                                                <td className="px-6 py-4 text-sm font-medium text-gray-900">{product.name}</td>
                                                <td className="px-6 py-4 text-sm text-gray-500 font-mono">{product.sku}</td>
                                                <td className="px-6 py-4 text-sm text-gray-900">{Number(product.price).toFixed(2)} €</td>
                                                <td className="px-6 py-4">
                                                    <span className={`px-2 py-1 text-sm font-bold rounded ${getTotalStock(product) > 10 ? 'text-green-700 bg-green-100' : getTotalStock(product) > 0 ? 'text-yellow-700 bg-yellow-100' : 'text-red-700 bg-red-100'}`}>
                                                        {getTotalStock(product)}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 text-xs text-gray-500">
                                                    {product.stock_levels && product.stock_levels.length > 0 ? (
                                                        product.stock_levels.map((sl) => (
                                                            <div key={sl.id}>
                                                                {sl.warehouse?.name}: <strong>{sl.quantity}</strong>
                                                            </div>
                                                        ))
                                                    ) : (
                                                        <span className="text-gray-400">-</span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 text-right text-sm">
                                                    <button
                                                        onClick={() => openEdit(product)}
                                                        className="text-indigo-600 hover:text-indigo-900 mr-3"
                                                    >
                                                        Modifier
                                                    </button>
                                                    <button
                                                        onClick={() => handleDelete(product)}
                                                        className="text-red-600 hover:text-red-900"
                                                    >
                                                        Supprimer
                                                    </button>
                                                </td>
                                            </tr>
                                        ))
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

