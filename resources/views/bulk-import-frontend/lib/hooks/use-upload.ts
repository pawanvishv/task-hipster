// lib/hooks/use-upload.ts

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { uploadService } from "@/lib/services/upload.service";
import { useUploadStore } from "@/lib/store/upload-store";
import { useToastActions } from "@/lib/providers/toast-provider";
import { Upload, UploadProgress, Image } from "@/types";

/**
 * Hook for initializing upload
 */
export function useInitializeUpload() {
    const toast = useToastActions();

    return useMutation({
        mutationFn: async ({
            file,
            checksum,
        }: {
            file: File;
            checksum?: string;
        }) => {
            return uploadService.initializeUpload(file, checksum);
        },
        onSuccess: (data) => {
            toast.success("Upload initialized", `Upload ID: ${data.id}`);
        },
        onError: (error: any) => {
            toast.error("Initialization failed", error.message);
        },
    });
}

/**
 * Hook for uploading file with chunks
 */
export function useUploadFile() {
    const toast = useToastActions();
    const { startUpload, updateProgress, completeUpload, failUpload } =
        useUploadStore();

    return useMutation({
        mutationFn: async (file: File) => {
            return new Promise<{ upload: Upload; images: Image[] }>(
                (resolve, reject) => {
                    uploadService.uploadFile(
                        file,
                        (progress: UploadProgress) => {
                            // Update store with progress
                            if (progress.uploadedChunks === 0) {
                                startUpload(
                                    progress.uploadId,
                                    progress.filename,
                                    progress.totalChunks
                                );
                            } else {
                                updateProgress(progress);
                            }
                        },
                        (upload: Upload, images: Image[]) => {
                            // Complete upload
                            completeUpload(upload.id, upload);
                            resolve({ upload, images });
                        },
                        (error: Error) => {
                            // Fail upload
                            reject(error);
                        }
                    );
                }
            );
        },
        onSuccess: (data) => {
            toast.success(
                "Upload completed",
                `${data.images.length} variants generated`
            );
        },
        onError: (error: any, variables) => {
            const uploadId = useUploadStore
                .getState()
                .activeUploads.values()
                .next().value?.uploadId;
            if (uploadId) {
                failUpload(uploadId, error.message);
            }
            toast.error("Upload failed", error.message);
        },
    });
}

/**
 * Hook for uploading multiple files
 */
export function useUploadMultipleFiles() {
    const toast = useToastActions();
    const { startUpload, updateProgress, completeUpload, failUpload } =
        useUploadStore();

    return useMutation({
        mutationFn: async (files: File[]) => {
            const results: Array<{ upload: Upload; images: Image[] }> = [];
            const errors: Array<{ file: string; error: string }> = [];

            for (const file of files) {
                try {
                    const result = await new Promise<{
                        upload: Upload;
                        images: Image[];
                    }>((resolve, reject) => {
                        uploadService.uploadFile(
                            file,
                            (progress: UploadProgress) => {
                                if (progress.uploadedChunks === 0) {
                                    startUpload(
                                        progress.uploadId,
                                        progress.filename,
                                        progress.totalChunks
                                    );
                                } else {
                                    updateProgress(progress);
                                }
                            },
                            (upload: Upload, images: Image[]) => {
                                completeUpload(upload.id, upload);
                                resolve({ upload, images });
                            },
                            (error: Error) => {
                                reject(error);
                            }
                        );
                    });

                    results.push(result);
                } catch (error: any) {
                    errors.push({ file: file.name, error: error.message });
                    const uploadId = useUploadStore
                        .getState()
                        .activeUploads.values()
                        .next().value?.uploadId;
                    if (uploadId) {
                        failUpload(uploadId, error.message);
                    }
                }
            }

            return { results, errors };
        },
        onSuccess: (data) => {
            if (data.errors.length > 0) {
                toast.warning(
                    "Some uploads failed",
                    `${data.results.length} succeeded, ${data.errors.length} failed`
                );
            } else {
                toast.success(
                    "All uploads completed",
                    `${data.results.length} files uploaded`
                );
            }
        },
        onError: (error: any) => {
            toast.error("Upload failed", error.message);
        },
    });
}

/**
 * Hook for getting upload status
 */
export function useUploadStatus(uploadId: string, enabled: boolean = true) {
    return useQuery({
        queryKey: ["upload-status", uploadId],
        queryFn: () => uploadService.getUploadStatus(uploadId),
        enabled: enabled && !!uploadId,
        refetchInterval: 2000, // Poll every 2 seconds
    });
}

/**
 * Hook for resuming upload
 */
export function useResumeUpload() {
    const toast = useToastActions();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (uploadId: string) => {
            return uploadService.resumeUpload(uploadId);
        },
        onSuccess: (data) => {
            if (data.can_resume) {
                toast.info(
                    "Upload can be resumed",
                    `${data.missing_chunks.length} chunks remaining`
                );
            } else {
                toast.warning("Cannot resume", data.message);
            }
            queryClient.invalidateQueries({ queryKey: ["upload-status"] });
        },
        onError: (error: any) => {
            toast.error("Resume failed", error.message);
        },
    });
}

/**
 * Hook for canceling upload
 */
export function useCancelUpload() {
    const toast = useToastActions();
    const queryClient = useQueryClient();
    const { cancelUpload } = useUploadStore();

    return useMutation({
        mutationFn: async (uploadId: string) => {
            return uploadService.cancelUpload(uploadId);
        },
        onSuccess: (_, uploadId) => {
            cancelUpload(uploadId);
            toast.info("Upload cancelled");
            queryClient.invalidateQueries({ queryKey: ["upload-status"] });
        },
        onError: (error: any) => {
            toast.error("Cancel failed", error.message);
        },
    });
}

/**
 * Hook for verifying upload checksum
 */
export function useVerifyChecksum() {
    const toast = useToastActions();

    return useMutation({
        mutationFn: async (uploadId: string) => {
            return uploadService.verifyChecksum(uploadId);
        },
        onSuccess: (isValid) => {
            if (isValid) {
                toast.success("Checksum verified", "File integrity confirmed");
            } else {
                toast.error("Checksum mismatch", "File may be corrupted");
            }
        },
        onError: (error: any) => {
            toast.error("Verification failed", error.message);
        },
    });
}

/**
 * Hook for calculating file checksum
 */
export function useCalculateChecksum() {
    return useMutation({
        mutationFn: async (file: File) => {
            return uploadService.calculateChecksum(file);
        },
    });
}
