// lib/store/upload-store.ts

import { create } from "zustand";
import { devtools, persist } from "zustand/middleware";
import { Upload, UploadProgress, FileWithPreview } from "@/types";

interface UploadState {
    // Active uploads
    activeUploads: Map<string, UploadProgress>;

    // Completed uploads
    completedUploads: Upload[];

    // Failed uploads
    failedUploads: Map<string, { upload: UploadProgress; error: string }>;

    // Queue
    uploadQueue: FileWithPreview[];

    // UI State
    isUploading: boolean;
    isPaused: boolean;

    // Actions
    addToQueue: (files: FileWithPreview[]) => void;
    removeFromQueue: (filename: string) => void;
    clearQueue: () => void;

    startUpload: (
        uploadId: string,
        filename: string,
        totalChunks: number
    ) => void;
    updateProgress: (progress: UploadProgress) => void;
    completeUpload: (uploadId: string, upload: Upload) => void;
    failUpload: (uploadId: string, error: string) => void;
    removeUpload: (uploadId: string) => void;
    retryUpload: (uploadId: string) => void;

    pauseUploads: () => void;
    resumeUploads: () => void;
    cancelUpload: (uploadId: string) => void;
    cancelAllUploads: () => void;

    clearCompleted: () => void;
    clearFailed: () => void;
    clearAll: () => void;

    // Getters
    getActiveUploadCount: () => number;
    getTotalProgress: () => number;
    getUploadById: (uploadId: string) => UploadProgress | undefined;
}

export const useUploadStore = create<UploadState>()(
    devtools(
        persist(
            (set, get) => ({
                activeUploads: new Map(),
                completedUploads: [],
                failedUploads: new Map(),
                uploadQueue: [],
                isUploading: false,
                isPaused: false,

                addToQueue: (files) =>
                    set((state) => ({
                        uploadQueue: [...state.uploadQueue, ...files],
                    })),

                removeFromQueue: (filename) =>
                    set((state) => ({
                        uploadQueue: state.uploadQueue.filter(
                            (f) => f.name !== filename
                        ),
                    })),

                clearQueue: () => set({ uploadQueue: [] }),

                startUpload: (uploadId, filename, totalChunks) =>
                    set((state) => {
                        const newActiveUploads = new Map(state.activeUploads);
                        newActiveUploads.set(uploadId, {
                            uploadId,
                            filename,
                            progress: 0,
                            uploadedChunks: 0,
                            totalChunks,
                            status: "uploading",
                        });

                        return {
                            activeUploads: newActiveUploads,
                            isUploading: true,
                        };
                    }),

                updateProgress: (progress) =>
                    set((state) => {
                        const newActiveUploads = new Map(state.activeUploads);
                        newActiveUploads.set(progress.uploadId, progress);

                        return {
                            activeUploads: newActiveUploads,
                        };
                    }),

                completeUpload: (uploadId, upload) =>
                    set((state) => {
                        const newActiveUploads = new Map(state.activeUploads);
                        newActiveUploads.delete(uploadId);

                        return {
                            activeUploads: newActiveUploads,
                            completedUploads: [
                                ...state.completedUploads,
                                upload,
                            ],
                            isUploading: newActiveUploads.size > 0,
                        };
                    }),

                failUpload: (uploadId, error) =>
                    set((state) => {
                        const newActiveUploads = new Map(state.activeUploads);
                        const upload = newActiveUploads.get(uploadId);
                        newActiveUploads.delete(uploadId);

                        const newFailedUploads = new Map(state.failedUploads);
                        if (upload) {
                            newFailedUploads.set(uploadId, {
                                upload: { ...upload, status: "failed", error },
                                error,
                            });
                        }

                        return {
                            activeUploads: newActiveUploads,
                            failedUploads: newFailedUploads,
                            isUploading: newActiveUploads.size > 0,
                        };
                    }),

                removeUpload: (uploadId) =>
                    set((state) => {
                        const newActiveUploads = new Map(state.activeUploads);
                        newActiveUploads.delete(uploadId);

                        const newFailedUploads = new Map(state.failedUploads);
                        newFailedUploads.delete(uploadId);

                        return {
                            activeUploads: newActiveUploads,
                            failedUploads: newFailedUploads,
                            isUploading: newActiveUploads.size > 0,
                        };
                    }),

                retryUpload: (uploadId) =>
                    set((state) => {
                        const failed = state.failedUploads.get(uploadId);
                        if (!failed) return state;

                        const newFailedUploads = new Map(state.failedUploads);
                        newFailedUploads.delete(uploadId);

                        const newActiveUploads = new Map(state.activeUploads);
                        newActiveUploads.set(uploadId, {
                            ...failed.upload,
                            status: "uploading",
                            error: undefined,
                        });

                        return {
                            activeUploads: newActiveUploads,
                            failedUploads: newFailedUploads,
                            isUploading: true,
                        };
                    }),

                pauseUploads: () => set({ isPaused: true }),

                resumeUploads: () => set({ isPaused: false }),

                cancelUpload: (uploadId) =>
                    set((state) => {
                        const newActiveUploads = new Map(state.activeUploads);
                        newActiveUploads.delete(uploadId);

                        return {
                            activeUploads: newActiveUploads,
                            isUploading: newActiveUploads.size > 0,
                        };
                    }),

                cancelAllUploads: () =>
                    set({
                        activeUploads: new Map(),
                        isUploading: false,
                    }),

                clearCompleted: () => set({ completedUploads: [] }),

                clearFailed: () => set({ failedUploads: new Map() }),

                clearAll: () =>
                    set({
                        activeUploads: new Map(),
                        completedUploads: [],
                        failedUploads: new Map(),
                        uploadQueue: [],
                        isUploading: false,
                        isPaused: false,
                    }),

                getActiveUploadCount: () => get().activeUploads.size,

                getTotalProgress: () => {
                    const uploads = Array.from(get().activeUploads.values());
                    if (uploads.length === 0) return 0;

                    const totalProgress = uploads.reduce(
                        (sum, upload) => sum + upload.progress,
                        0
                    );
                    return Math.round(totalProgress / uploads.length);
                },

                getUploadById: (uploadId) => get().activeUploads.get(uploadId),
            }),
            {
                name: "upload-storage",
                partialize: (state) => ({
                    completedUploads: state.completedUploads,
                }),
            }
        )
    )
);
