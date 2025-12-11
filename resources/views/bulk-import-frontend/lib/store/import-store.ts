// lib/store/import-store.ts

import { create } from "zustand";
import { devtools, persist } from "zustand/middleware";
import { ImportLog, ImportResult } from "@/types";

interface ImportState {
    // Active import
    activeImport: ImportLog | null;

    // Import history
    importHistory: ImportLog[];

    // Recent results
    recentResults: ImportResult[];

    // UI State
    isImporting: boolean;

    // Actions
    startImport: (importLog: ImportLog) => void;
    updateImport: (importLog: ImportLog) => void;
    completeImport: (importLog: ImportLog, result: ImportResult) => void;
    failImport: (error: string) => void;

    addToHistory: (importLog: ImportLog) => void;
    clearHistory: () => void;

    setRecentResults: (results: ImportResult[]) => void;
    addResult: (result: ImportResult) => void;
    clearResults: () => void;

    // Getters
    getImportById: (id: string) => ImportLog | undefined;
    getSuccessRate: () => number;
}

export const useImportStore = create<ImportState>()(
    devtools(
        persist(
            (set, get) => ({
                activeImport: null,
                importHistory: [],
                recentResults: [],
                isImporting: false,

                startImport: (importLog) =>
                    set({
                        activeImport: importLog,
                        isImporting: true,
                    }),

                updateImport: (importLog) =>
                    set({
                        activeImport: importLog,
                    }),

                completeImport: (importLog, result) =>
                    set((state) => ({
                        activeImport: null,
                        isImporting: false,
                        importHistory: [
                            importLog,
                            ...state.importHistory,
                        ].slice(0, 50), // Keep last 50
                        recentResults: [result, ...state.recentResults].slice(
                            0,
                            10
                        ), // Keep last 10
                    })),

                failImport: (error) =>
                    set({
                        activeImport: null,
                        isImporting: false,
                    }),

                addToHistory: (importLog) =>
                    set((state) => ({
                        importHistory: [
                            importLog,
                            ...state.importHistory,
                        ].slice(0, 50),
                    })),

                clearHistory: () => set({ importHistory: [] }),

                setRecentResults: (results) => set({ recentResults: results }),

                addResult: (result) =>
                    set((state) => ({
                        recentResults: [result, ...state.recentResults].slice(
                            0,
                            10
                        ),
                    })),

                clearResults: () => set({ recentResults: [] }),

                getImportById: (id) => {
                    const state = get();
                    if (state.activeImport?.id === id)
                        return state.activeImport;
                    return state.importHistory.find((imp) => imp.id === id);
                },

                getSuccessRate: () => {
                    const results = get().recentResults;
                    if (results.length === 0) return 0;

                    const totalSuccess = results.reduce(
                        (sum, result) => sum + result.success_rate,
                        0
                    );
                    return Math.round(totalSuccess / results.length);
                },
            }),
            {
                name: "import-storage",
                partialize: (state) => ({
                    importHistory: state.importHistory,
                    recentResults: state.recentResults,
                }),
            }
        )
    )
);
