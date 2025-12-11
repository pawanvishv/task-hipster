// lib/providers/query-provider.tsx

"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ReactQueryDevtools } from "@tanstack/react-query-devtools";
import { useState, type ReactNode } from "react";

interface QueryProviderProps {
    children: ReactNode;
}

/**
 * React Query Provider
 * Configures global query client settings
 */
export function QueryProvider({ children }: QueryProviderProps) {
    const [queryClient] = useState(
        () =>
            new QueryClient({
                defaultOptions: {
                    queries: {
                        // Stale time: 5 minutes
                        staleTime: 5 * 60 * 1000,

                        // Cache time: 10 minutes
                        gcTime: 10 * 60 * 1000,

                        // Refetch on window focus
                        refetchOnWindowFocus: false,

                        // Refetch on reconnect
                        refetchOnReconnect: true,

                        // Retry failed requests
                        retry: 1,

                        // Retry delay
                        retryDelay: (attemptIndex) =>
                            Math.min(1000 * 2 ** attemptIndex, 30000),
                    },
                    mutations: {
                        // Retry mutations once
                        retry: 1,

                        // Retry delay for mutations
                        retryDelay: 1000,
                    },
                },
            })
    );

    return (
        <QueryClientProvider client={queryClient}>
            {children}
            {process.env.NODE_ENV === "development" && (
                <ReactQueryDevtools
                    initialIsOpen={false}
                    position="bottom-right"
                />
            )}
        </QueryClientProvider>
    );
}
