import { HTMLAttributes } from 'react';

export default function ApplicationLogo(props: HTMLAttributes<HTMLDivElement>) {
    return (
        <div {...props} className={`flex items-center gap-2 font-bold tracking-tight text-indigo-700 ${props.className || ''}`}>
            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-sm text-lg">
                📦
            </span>
            <div className="flex flex-col leading-none">
                <span className="text-base font-extrabold text-gray-900 tracking-tight">Mini ERP</span>
                <span className="text-[10px] font-semibold text-indigo-600 uppercase tracking-wider">Stock Manager</span>
            </div>
        </div>
    );
}
