// lib/hooks/use-import.ts

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { importService } from "@/lib/services/import.service";
import { useImportStore } from "@/lib/store/import-store";
import { useToastActions } from "@/lib/providers/toast-provider";

/**
 * Hook for importing products
 */
export function useImportProducts() {
    const toast = useToastActions();
    const { startImport, completeImport, failImport } = useImportStore();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({
            file,
            options,
        }: {
            file: File;
            options?: { skip_duplicates?: boolean; update_existing?: boolean };
        }) => {
            return importService.importProducts(file, options);
        },
        onSuccess: (data) => {
            completeImport(
                {
                    id: data.import_log_id,
                    total_rows: data.total,
                    imported_rows: data.imported,
                    updated_rows: data.updated,
                    invalid_rows: data.invalid,
                    duplicate_rows: data.duplicates,
                } as any,
                data
            );

            if (data.invalid > 0 || data.duplicates > 0) {
                toast.warning(
                    "Import completed with issues",
                    `${data.processed} processed, ${data.invalid} invalid, ${data.duplicates} duplicates`
                );
            } else {
                toast.success(
                    "Import successful",
                    `${data.imported} imported, ${data.updated} updated`
                );
            }

            queryClient.invalidateQueries({ queryKey: ["import-history"] });
            queryClient.invalidateQueries({ queryKey: ["import-statistics"] });
        },
        onError: (error: any) => {
            failImport(error.message);
            toast.error("Import failed", error.message);
        },
    });
}

/**
 * Hook for validating CSV
 */
export function useValidateCsv() {
    const toast = useToastActions();

    return useMutation({
        mutationFn: async (file: File) => {
            return importService.validateCsv(file);
        },
        onSuccess: (data) => {
            if (data.valid) {
                toast.success("Validation passed", "CSV file is valid");
            } else {
                toast.error("Validation failed", data.errors.join(", "));
            }
        },
        onError: (error: any) => {
            toast.error("Validation failed", error.message);
        },
    });
}

/**
 * Hook for getting required columns
 */
export function useRequiredColumns() {
    return useQuery({
        queryKey: ["required-columns"],
        queryFn: () => importService.getRequiredColumns(),
        staleTime: Infinity, // Never stale
    });
}

/**
 * Hook for getting import history
 */
export function useImportHistory(params?: {
    page?: number;
    per_page?: number;
    type?: string;
}) {
    return useQuery({
        queryKey: ["import-history", params],
        queryFn: () => importService.getImportHistory(params),
    });
}

/**
 * Hook for getting import details
 */
export function useImportDetails(importId: string, enabled: boolean = true) {
    return useQuery({
        queryKey: ["import-details", importId],
        queryFn: () => importService.getImportDetails(importId),
        enabled: enabled && !!importId,
    });
}

/**
 * Hook for getting import statistics
 */
export function useImportStatistics(params?: { days?: number; type?: string }) {
    return useQuery({
        queryKey: ["import-statistics", params],
        queryFn: () => importService.getImportStatistics(params),
    });
}

/**
 * Hook for downloading sample CSV
 */
export function useDownloadSampleCsv() {
    const toast = useToastActions();

    return useMutation({
        mutationFn: async () => {
            importService.downloadSampleCsv();
            return true;
        },
        onSuccess: () => {
            toast.success("Sample downloaded", "Check your downloads folder");
        },
        onError: (error: any) => {
            toast.error("Download failed", error.message);
        },
    });
}

/**
 * Hook for downloading mock CSV
 */
export function useDownloadMockCsv() {
    const toast = useToastActions();

    return useMutation({
        mutationFn: async (rows: number) => {
            importService.downloadMockCsv(rows);
            return rows;
        },
        onSuccess: (rows) => {
            toast.success("Mock data downloaded", `${rows} rows generated`);
        },
        onError: (error: any) => {
            toast.error("Download failed", error.message);
        },
    });
}
