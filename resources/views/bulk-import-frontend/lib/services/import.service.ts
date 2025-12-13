// lib/services/import.service.ts

import { apiClient } from "@/lib/api-client";
import {
    ImportResult,
    ImportLog,
    ImportStatistics,
    ApiResponse,
    PaginatedResponse,
} from "@/types";

export interface ImportOptions {
    validate_only?: boolean;
    skip_invalid?: boolean;
    update_existing?: boolean;
}

class ImportService {
    /**
     * Import products from CSV file
     */
    public async importProducts(
        file: File,
        options: ImportOptions = {}
    ): Promise<ImportResult> {
        const formData = new FormData();
        formData.append("file", file);
        formData.append("validate_only", options.validate_only ? "1" : "0");
        formData.append("skip_invalid", options.skip_invalid ? "1" : "0");
        formData.append("update_existing", options.update_existing ? "1" : "0");

        const response = await apiClient.post<ApiResponse<ImportResult>>(
            "/imports/products",
            formData,
            {
                headers: {
                    "Content-Type": "multipart/form-data",
                },
            }
        );

        if (!response.data.success) {
            throw new Error(response.data.message || "Import failed");
        }

        return response.data.data!;
    }

    /**
     * Validate CSV file without importing
     */
    public async validateCsv(file: File): Promise<any> {
        const formData = new FormData();
        formData.append("file", file);

        const response = await apiClient.post<ApiResponse<any>>(
            "/imports/products/validate",
            formData,
            {
                headers: {
                    "Content-Type": "multipart/form-data",
                },
            }
        );

        if (!response.data.success) {
            throw new Error(response.data.message || "Validation failed");
        }

        return response.data.data;
    }

    /**
     * Get required columns for CSV import
     */
    public async getRequiredColumns(): Promise<string[]> {
        const response = await apiClient.get<
            ApiResponse<{ columns: string[] }>
        >("/imports/products/columns");

        if (!response.data.success) {
            throw new Error("Failed to get required columns");
        }

        return response.data.data!.columns;
    }

    /**
     * Get import history
     */
    public async getImportHistory(
        params: {
            page?: number;
            per_page?: number;
            status?: string;
            from_date?: string;
            to_date?: string;
        } = {}
    ): Promise<PaginatedResponse<ImportLog>> {
        const response = await apiClient.get<
            ApiResponse<PaginatedResponse<ImportLog>>
        >("/imports/history", { params });

        if (!response.data.success) {
            throw new Error("Failed to fetch import history");
        }

        return response.data.data!;
    }

    /**
     * Get import details by ID
     */
    public async getImportDetails(importId: string): Promise<{
        import: ImportLog;
        summary: ImportResult;
    }> {
        const response = await apiClient.get<ApiResponse<any>>(
            `/imports/${importId}`
        );

        if (!response.data.success) {
            throw new Error("Failed to fetch import details");
        }

        return response.data.data;
    }

    /**
     * Get import statistics
     */
    public async getImportStatistics(
        params: {
            days?: number;
        } = {}
    ): Promise<{
        statistics: ImportStatistics;
        period: {
            from: string;
            to: string;
        };
    }> {
        const response = await apiClient.get<ApiResponse<any>>(
            "/imports/statistics",
            { params }
        );

        if (!response.data.success) {
            throw new Error("Failed to fetch statistics");
        }

        return response.data.data;
    }

    /**
     * Download sample CSV
     */
    public async downloadSampleCsv(): Promise<void> {
        const response = await apiClient.get("/imports/products/sample", {
            responseType: "blob",
        });

        const blob = new Blob([response.data], { type: "text/csv" });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = "sample_products.csv";
        link.click();
        window.URL.revokeObjectURL(url);
    }

    /**
     * Generate mock CSV data
     */
    public generateMockCsv(rows: number = 20000): void {
        const headers = [
            "sku",
            "name",
            "price",
            "stock_quantity",
            "description",
            "status",
            "primary_image",
        ];
        const statuses = ["active", "inactive", "draft"];
        const imageUrl = "/Users/pawan/Downloads/50mb.jpg";

        let csv = headers.join(",") + "\n";

        for (let i = 1; i <= rows; i++) {
            const row = [
                `SKU${String(i).padStart(6, "0")}`,
                `Product ${i}`,
                (Math.random() * 1000 + 10).toFixed(2),
                Math.floor(Math.random() * 500),
                `Description for product ${i}`.replace(/,/g, ";"),
                statuses[Math.floor(Math.random() * statuses.length)],
                imageUrl,
            ];
            csv += row.join(",") + "\n";
        }

        const blob = new Blob([csv], { type: "text/csv" });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = `mock_products_${rows}.csv`;
        link.click();
        window.URL.revokeObjectURL(url);
    }
}

export const importService = new ImportService();
export default ImportService;
