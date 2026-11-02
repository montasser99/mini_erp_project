export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
}

export interface Product {
    id: number;
    name: string;
    sku: string;
    description?: string;
    price: number;
    created_at: string;
    updated_at: string;
    stock_levels?: StockLevel[];
}

export interface Warehouse {
    id: number;
    name: string;
    location?: string;
    is_active: boolean;
    created_at: string;
    updated_at: string;
    total_quantity?: number;
}

export interface StockLevel {
    id: number;
    product_id: number;
    warehouse_id: number;
    quantity: number;
    product?: Product;
    warehouse?: Warehouse;
}

export interface StockMovement {
    id: number;
    product_id: number;
    warehouse_id: number;
    type: 'IN' | 'OUT' | 'TRANSFER';
    quantity: number;
    destination_warehouse_id?: number;
    reference?: string;
    notes?: string;
    user_id: number;
    created_at: string;
    product?: Product;
    warehouse?: Warehouse;
    destination_warehouse?: Warehouse;
    user?: User;
}

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash?: {
        success?: string;
        error?: string;
    };
};
