// lib/providers/toast-provider.tsx

"use client";

import {
    createContext,
    useContext,
    useState,
    useCallback,
    type ReactNode,
} from "react";
import { Toast } from "@/types";
import { generateId } from "@/lib/utils";

interface ToastContextType {
    toasts: Toast[];
    addToast: (toast: Omit<Toast, "id">) => void;
    removeToast: (id: string) => void;
    clearToasts: () => void;
}

const ToastContext = createContext<ToastContextType | undefined>(undefined);

interface ToastProviderProps {
    children: ReactNode;
}

export function ToastProvider({ children }: ToastProviderProps) {
    const [toasts, setToasts] = useState<Toast[]>([]);

    const addToast = useCallback((toast: Omit<Toast, "id">) => {
        const id = generateId();
        const duration =
            toast.duration ||
            parseInt(process.env.NEXT_PUBLIC_TOAST_DURATION || "5000");

        const newToast: Toast = {
            ...toast,
            id,
            duration,
        };

        setToasts((prev) => [...prev, newToast]);

        // Auto-remove toast after duration
        setTimeout(() => {
            removeToast(id);
        }, duration);
    }, []);

    const removeToast = useCallback((id: string) => {
        setToasts((prev) => prev.filter((toast) => toast.id !== id));
    }, []);

    const clearToasts = useCallback(() => {
        setToasts([]);
    }, []);

    return (
        <ToastContext.Provider
            value={{ toasts, addToast, removeToast, clearToasts }}
        >
            {children}
        </ToastContext.Provider>
    );
}

export function useToast() {
    const context = useContext(ToastContext);

    if (!context) {
        throw new Error("useToast must be used within ToastProvider");
    }

    return context;
}

// Convenience methods
export function useToastActions() {
    const { addToast } = useToast();

    return {
        success: (title: string, description?: string) => {
            addToast({ title, description, type: "success" });
        },
        error: (title: string, description?: string) => {
            addToast({ title, description, type: "error" });
        },
        warning: (title: string, description?: string) => {
            addToast({ title, description, type: "warning" });
        },
        info: (title: string, description?: string) => {
            addToast({ title, description, type: "info" });
        },
    };
}
