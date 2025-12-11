// lib/api-client.ts

import axios, {
    AxiosInstance,
    AxiosRequestConfig,
    AxiosResponse,
    AxiosError,
} from "axios";

/**
 * API Client Configuration
 */
class ApiClient {
    private client: AxiosInstance;
    private baseURL: string;

    constructor() {
        this.baseURL =
            process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000/api";

        this.client = axios.create({
            baseURL: this.baseURL,
            timeout: parseInt(process.env.NEXT_PUBLIC_API_TIMEOUT || "30000"),
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
            },
        });

        this.setupInterceptors();
    }

    /**
     * Setup request and response interceptors
     */
    private setupInterceptors(): void {
        // Request interceptor
        this.client.interceptors.request.use(
            (config) => {
                // Add auth token if available
                const token = this.getAuthToken();
                if (token) {
                    config.headers.Authorization = `Bearer ${token}`;
                }

                // Log request in development
                if (process.env.NODE_ENV === "development") {
                    console.log("API Request:", {
                        method: config.method?.toUpperCase(),
                        url: config.url,
                        data: config.data,
                    });
                }

                return config;
            },
            (error) => {
                return Promise.reject(error);
            }
        );

        // Response interceptor
        this.client.interceptors.response.use(
            (response) => {
                // Log response in development
                if (process.env.NODE_ENV === "development") {
                    console.log("API Response:", {
                        status: response.status,
                        url: response.config.url,
                        data: response.data,
                    });
                }

                return response;
            },
            (error: AxiosError) => {
                return this.handleError(error);
            }
        );
    }

    /**
     * Get authentication token from storage
     */
    private getAuthToken(): string | null {
        if (typeof window !== "undefined") {
            return localStorage.getItem("auth_token");
        }
        return null;
    }

    /**
     * Set authentication token
     */
    public setAuthToken(token: string): void {
        if (typeof window !== "undefined") {
            localStorage.setItem("auth_token", token);
        }
    }

    /**
     * Remove authentication token
     */
    public removeAuthToken(): void {
        if (typeof window !== "undefined") {
            localStorage.removeItem("auth_token");
        }
    }

    /**
     * Handle API errors
     */
    private handleError(error: AxiosError): Promise<never> {
        if (error.response) {
            // Server responded with error status
            const status = error.response.status;
            const data = error.response.data as any;

            console.error("API Error Response:", {
                status,
                message: data?.message,
                errors: data?.errors,
            });

            // Handle specific status codes
            switch (status) {
                case 401:
                    // Unauthorized - clear token and redirect to login
                    this.removeAuthToken();
                    if (typeof window !== "undefined") {
                        window.location.href = "/login";
                    }
                    break;
                case 403:
                    // Forbidden
                    console.error("Access forbidden");
                    break;
                case 404:
                    // Not found
                    console.error("Resource not found");
                    break;
                case 422:
                    // Validation error
                    console.error("Validation failed:", data?.errors);
                    break;
                case 500:
                    // Server error
                    console.error("Server error occurred");
                    break;
            }

            return Promise.reject({
                status,
                message: data?.message || "An error occurred",
                errors: data?.errors || {},
                data: data,
            });
        } else if (error.request) {
            // Request made but no response
            console.error("API No Response:", error.request);
            return Promise.reject({
                status: 0,
                message:
                    "No response from server. Please check your connection.",
                errors: {},
            });
        } else {
            // Error setting up request
            console.error("API Request Error:", error.message);
            return Promise.reject({
                status: 0,
                message: error.message || "An error occurred",
                errors: {},
            });
        }
    }

    /**
     * GET request
     */
    public async get<T = any>(
        url: string,
        config?: AxiosRequestConfig
    ): Promise<AxiosResponse<T>> {
        return this.client.get<T>(url, config);
    }

    /**
     * POST request
     */
    public async post<T = any>(
        url: string,
        data?: any,
        config?: AxiosRequestConfig
    ): Promise<AxiosResponse<T>> {
        return this.client.post<T>(url, data, config);
    }

    /**
     * PUT request
     */
    public async put<T = any>(
        url: string,
        data?: any,
        config?: AxiosRequestConfig
    ): Promise<AxiosResponse<T>> {
        return this.client.put<T>(url, data, config);
    }

    /**
     * PATCH request
     */
    public async patch<T = any>(
        url: string,
        data?: any,
        config?: AxiosRequestConfig
    ): Promise<AxiosResponse<T>> {
        return this.client.patch<T>(url, data, config);
    }

    /**
     * DELETE request
     */
    public async delete<T = any>(
        url: string,
        config?: AxiosRequestConfig
    ): Promise<AxiosResponse<T>> {
        return this.client.delete<T>(url, config);
    }

    /**
     * Upload file with progress tracking
     */
    public async uploadFile<T = any>(
        url: string,
        formData: FormData,
        onUploadProgress?: (progressEvent: any) => void,
        config?: AxiosRequestConfig
    ): Promise<AxiosResponse<T>> {
        return this.client.post<T>(url, formData, {
            ...config,
            headers: {
                ...config?.headers,
                "Content-Type": "multipart/form-data",
            },
            onUploadProgress,
        });
    }

    /**
     * Get base URL
     */
    public getBaseURL(): string {
        return this.baseURL;
    }

    /**
     * Get axios instance (for advanced usage)
     */
    public getInstance(): AxiosInstance {
        return this.client;
    }
}

// Export singleton instance
export const apiClient = new ApiClient();

// Export class for testing
export default ApiClient;
