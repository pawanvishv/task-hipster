// lib/services/upload.service.ts

import { apiClient } from "@/lib/api-client";
import CryptoJS from "crypto-js";

class UploadService {
    private readonly CHUNK_SIZE: number;
    private readonly MAX_RETRIES = 3;
    private readonly RETRY_DELAY = 1000;

    constructor() {
        this.CHUNK_SIZE = parseInt(
            process.env.NEXT_PUBLIC_CHUNK_SIZE || "1048576"
        );
    }

    /**
     * Calculate SHA256 checksum for file
     */
    public async calculateChecksum(file: File): Promise<string> {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();

            reader.onload = (event) => {
                try {
                    const arrayBuffer = event.target?.result as ArrayBuffer;
                    const wordArray = CryptoJS.lib.WordArray.create(
                        new Uint8Array(arrayBuffer)
                    );
                    const hash = CryptoJS.SHA256(wordArray).toString(
                        CryptoJS.enc.Hex
                    );
                    console.log("File checksum calculated:", hash);
                    resolve(hash);
                } catch (error) {
                    console.error("Checksum calculation error:", error);
                    reject(error);
                }
            };

            reader.onerror = () => reject(new Error("Failed to read file"));
            reader.readAsArrayBuffer(file);
        });
    }

    /**
     * Calculate SHA256 checksum for chunk
     */
    public async calculateChunkChecksum(chunk: Blob): Promise<string> {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();

            reader.onload = (event) => {
                try {
                    const arrayBuffer = event.target?.result as ArrayBuffer;
                    const wordArray = CryptoJS.lib.WordArray.create(
                        new Uint8Array(arrayBuffer)
                    );
                    const hash = CryptoJS.SHA256(wordArray).toString(
                        CryptoJS.enc.Hex
                    );
                    resolve(hash);
                } catch (error) {
                    console.error("Chunk checksum calculation error:", error);
                    reject(error);
                }
            };

            reader.onerror = () => reject(new Error("Failed to read chunk"));
            reader.readAsArrayBuffer(chunk);
        });
    }

    /**
     * Convert blob to base64
     */
    private async blobToBase64(blob: Blob): Promise<string> {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onloadend = () => {
                try {
                    const result = reader.result as string;
                    // Remove data URL prefix (e.g., "data:application/octet-stream;base64,")
                    const base64 = result.split(",")[1];
                    resolve(base64);
                } catch (error) {
                    reject(error);
                }
            };
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    }

    /**
     * Split file into chunks
     */
    public getChunks(file: File): Blob[] {
        const chunks: Blob[] = [];
        const totalChunks = Math.ceil(file.size / this.CHUNK_SIZE);

        for (let i = 0; i < totalChunks; i++) {
            const start = i * this.CHUNK_SIZE;
            const end = Math.min(start + this.CHUNK_SIZE, file.size);
            chunks.push(file.slice(start, end));
        }

        return chunks;
    }

    /**
     * Upload single chunk with retry
     */
    public async uploadChunk(
        uploadId: string,
        file: File,
        chunkIndex: number,
        totalChunks: number,
        onProgress?: (progress: number) => void
    ): Promise<boolean> {
        const chunks = this.getChunks(file);
        const chunk = chunks[chunkIndex];

        if (!chunk) {
            throw new Error(`Chunk ${chunkIndex} not found`);
        }

        let retries = 0;
        while (retries < this.MAX_RETRIES) {
            try {
                console.log(
                    `Uploading chunk ${chunkIndex}/${totalChunks}, size: ${chunk.size} bytes`
                );

                // Calculate checksum BEFORE converting to base64
                const chunkChecksum = await this.calculateChunkChecksum(chunk);
                console.log(`Chunk ${chunkIndex} checksum:`, chunkChecksum);

                // Convert to base64 AFTER calculating checksum
                const chunkBase64 = await this.blobToBase64(chunk);

                const data = {
                    upload_id: uploadId,
                    chunk_index: chunkIndex,
                    total_chunks: totalChunks,
                    chunk_data: chunkBase64,
                    checksum: chunkChecksum,
                    original_filename: file.name,
                    chunk_size: chunk.size,
                    total_size: file.size,
                };

                const response = await apiClient.post<any>(
                    "/uploads/chunk",
                    data
                );

                if (response.data.success) {
                    console.log(`Chunk ${chunkIndex} uploaded successfully`);
                    if (onProgress && response.data.data) {
                        onProgress(response.data.data.progress);
                    }
                    return true;
                }

                throw new Error(response.data.message || "Chunk upload failed");
            } catch (error: any) {
                retries++;
                console.error(
                    `Chunk ${chunkIndex} upload attempt ${retries} failed:`,
                    error.message
                );

                if (retries >= this.MAX_RETRIES) {
                    throw new Error(
                        `Failed to upload chunk ${chunkIndex} after ${this.MAX_RETRIES} retries: ${error.message}`
                    );
                }

                // Exponential backoff
                await this.delay(this.RETRY_DELAY * Math.pow(2, retries - 1));
            }
        }

        return false;
    }

    /**
     * Initialize upload
     */
    public async initializeUpload(file: File, checksum?: string): Promise<any> {
        const totalChunks = Math.ceil(file.size / this.CHUNK_SIZE);
        const fileChecksum = checksum || (await this.calculateChecksum(file));

        console.log("Initializing upload:", {
            filename: file.name,
            size: file.size,
            totalChunks,
            checksum: fileChecksum,
        });

        const data = {
            original_filename: file.name,
            total_chunks: totalChunks,
            total_size: file.size,
            checksum_sha256: fileChecksum,
            mime_type: file.type,
        };

        const response = await apiClient.post<any>("/uploads/initialize", data);

        if (!response.data.success) {
            throw new Error(
                response.data.message || "Failed to initialize upload"
            );
        }

        return {
            id: response.data.data.upload_id,
            original_filename: file.name,
            stored_filename: "",
            mime_type: file.type,
            total_size: file.size,
            total_chunks: totalChunks,
            uploaded_chunks: response.data.data.uploaded_chunks,
            checksum_sha256: fileChecksum,
            status: response.data.data.status,
            upload_metadata: null,
            completed_at: null,
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
        };
    }

    /**
     * Complete upload
     */
    public async completeUpload(
        uploadId: string,
        generateVariants: boolean = true
    ): Promise<any> {
        const response = await apiClient.post<any>(
            `/uploads/${uploadId}/complete`,
            {
                generate_variants: generateVariants,
            }
        );

        if (!response.data.success) {
            throw new Error(
                response.data.message || "Failed to complete upload"
            );
        }

        return {
            upload: {
                id: response.data.data.upload_id,
                status: response.data.data.status,
                completed_at: response.data.data.completed_at,
            },
            images: response.data.data.images || [],
        };
    }

    /**
     * Get upload status
     */
    public async getUploadStatus(uploadId: string): Promise<any> {
        const response = await apiClient.get<any>(
            `/uploads/${uploadId}/status`
        );

        if (!response.data.success) {
            throw new Error(
                response.data.message || "Failed to get upload status"
            );
        }

        return response.data.data;
    }

    /**
     * Resume upload
     */
    public async resumeUpload(uploadId: string): Promise<any> {
        const response = await apiClient.get<any>(
            `/uploads/${uploadId}/resume`
        );

        if (!response.data.success) {
            throw new Error(response.data.message || "Failed to resume upload");
        }

        return response.data.data;
    }

    /**
     * Cancel upload
     */
    public async cancelUpload(uploadId: string): Promise<boolean> {
        const response = await apiClient.delete<any>(
            `/uploads/${uploadId}/cancel`
        );
        return response.data.success;
    }

    /**
     * Verify checksum
     */
    public async verifyChecksum(uploadId: string): Promise<boolean> {
        const response = await apiClient.get<any>(
            `/uploads/${uploadId}/verify`
        );

        if (!response.data.success) {
            throw new Error("Failed to verify checksum");
        }

        return response.data.data.checksum_valid;
    }

    /**
     * Upload file with chunking
     */
    public async uploadFile(
        file: File,
        onProgress?: (progress: any) => void,
        onComplete?: (upload: any, images: any[]) => void,
        onError?: (error: Error) => void
    ): Promise<void> {
        try {
            // Initialize upload
            const upload = await this.initializeUpload(file);

            if (onProgress) {
                onProgress({
                    uploadId: upload.id,
                    filename: file.name,
                    progress: 0,
                    uploadedChunks: 0,
                    totalChunks: upload.total_chunks,
                    status: "uploading",
                });
            }

            // Upload chunks
            for (let i = 0; i < upload.total_chunks; i++) {
                await this.uploadChunk(
                    upload.id,
                    file,
                    i,
                    upload.total_chunks,
                    (progress) => {
                        if (onProgress) {
                            onProgress({
                                uploadId: upload.id,
                                filename: file.name,
                                progress,
                                uploadedChunks: i + 1,
                                totalChunks: upload.total_chunks,
                                status: "uploading",
                            });
                        }
                    }
                );
            }

            // Complete upload
            const result = await this.completeUpload(upload.id);

            if (onProgress) {
                onProgress({
                    uploadId: upload.id,
                    filename: file.name,
                    progress: 100,
                    uploadedChunks: upload.total_chunks,
                    totalChunks: upload.total_chunks,
                    status: "completed",
                });
            }

            if (onComplete) {
                onComplete(result.upload, result.images);
            }
        } catch (error: any) {
            console.error("Upload failed:", error);
            if (onError) {
                onError(error);
            }
            throw error;
        }
    }

    private delay(ms: number): Promise<void> {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    public getChunkSize(): number {
        return this.CHUNK_SIZE;
    }

    public calculateTotalChunks(fileSize: number): number {
        return Math.ceil(fileSize / this.CHUNK_SIZE);
    }
}

export const uploadService = new UploadService();
export default UploadService;
