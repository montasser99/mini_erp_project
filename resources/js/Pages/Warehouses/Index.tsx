import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { PageProps, Warehouse } from '@/types';
import { FormEventHandler, useState } from 'react';

interface WarehousesPageProps extends PageProps {
    warehouses: (Warehouse & { total_quantity: number })[];
}

export default function WarehousesIndex({ warehouses }: WarehousesPageProps) {
    const [showForm, setShowForm] = useState(false);
    const [editingWarehouse, setEditingWarehouse] = useState<Warehouse | null>(null);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        location: '',
    });

    const openCreate = () => {
        setEditingWarehouse(null);
        reset();
        setShowForm(true);
    };

    const openEdit = (warehouse: Warehouse) => {
        setEditingWarehouse(warehouse);
        setData({
            name: warehouse.name,
            location: warehouse.location || '',
        });
        setShowForm(true);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editingWarehouse) {
            put(route('warehouses.update', editingWarehouse.id), {
                onSuccess: () => { setShowForm(false); reset(); },
            });
        } else {
            post(route('warehouses.store'), {
                onSuccess: () => { setShowForm(false); reset(); },
            });
        }
    };

    const { delete: destroy } = useForm({});
    const handleDelete = (warehouse: Warehouse) => {
        if (confirm(`Supprimer l'entrepôt "${warehouse.name}" ?`)) {
            destroy(route('warehouses.destroy', warehouse.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex justify-between items-center">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        🏭 Entrepôts
                    </h2>
                    <button
                        onClick={openCreate}
                        className="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition"
                    >
                        + Nouvel entrepôt
                    </button>
                </div>
            }
        >
            <Head title="Entrepôts" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    {/* Formulaire */}
                    {showForm && (
                        <div className="mb-6 overflow-hidden rounded-lg bg-white shadow p-6">
                            <h3 className="text-lg font-semibold mb-4">
                                {editingWarehouse ? "Modifier l'entrepôt" : 'Ajouter un entrepôt'}
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
                                    <label className="block text-sm font-medium text-gray-700">Localisation</label>
                                    <input
                                        type="text"
                                        value={data.location}
                                        onChange={(e) => setData('location', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                    {errors.location && <p className="text-red-500 text-xs mt-1">{errors.location}</p>}
                                </div>
                                <div className="md:col-span-2 flex gap-3">
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                                    >
                                        {processing ? 'En cours...' : (editingWarehouse ? 'Modifier' : 'Créer')}
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

                    {/* Grille d'entrepôts */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        {warehouses.length === 0 ? (
                            <div className="md:col-span-3 text-center py-12 bg-white rounded-lg shadow">
                                <p className="text-gray-500">Aucun entrepôt. Cliquez sur "+ Nouvel entrepôt" pour commencer.</p>
                            </div>
                        ) : (
                            warehouses.map((warehouse) => (
                                <div key={warehouse.id} className="overflow-hidden rounded-lg bg-white shadow hover:shadow-lg transition">
                                    <div className="p-6">
                                        <div className="flex justify-between items-start">
                                            <div>
                                                <h3 className="text-lg font-semibold text-gray-900">{warehouse.name}</h3>
                                                <p className="text-sm text-gray-500 mt-1">{warehouse.location || 'Pas de localisation'}</p>
                                            </div>
                                            <span className={`px-2 py-1 text-xs rounded-full ${warehouse.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>
                                                {warehouse.is_active ? 'Actif' : 'Inactif'}
                                            </span>
                                        </div>
                                        <div className="mt-4 p-3 bg-gray-50 rounded-lg">
                                            <div className="text-sm text-gray-500">Stock total</div>
                                            <div className="text-2xl font-bold text-indigo-600">{warehouse.total_quantity || 0} unités</div>
                                        </div>
                                        <div className="mt-4 flex gap-2">
                                            <button
                                                onClick={() => openEdit(warehouse)}
                                                className="flex-1 text-center px-3 py-2 text-sm text-indigo-600 bg-indigo-50 rounded-lg hover:bg-indigo-100"
                                            >
                                                Modifier
                                            </button>
                                            <button
                                                onClick={() => handleDelete(warehouse)}
                                                className="px-3 py-2 text-sm text-red-600 bg-red-50 rounded-lg hover:bg-red-100"
                                            >
                                                Supprimer
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

