// components/upload/image-upload-form.tsx

'use client';

import * as React from 'react';
import { Image as ImageIcon, Upload, CheckCircle2, Loader2 } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dropzone } from '@/components/upload/dropzone';
import { UploadProgressCard } from '@/components/upload/upload-progress-card';
import { useUploadFile, useUploadMultipleFiles } from '@/lib/hooks/use-upload';
import { useUploadStore } from '@/lib/store/upload-store';
import { formatNumber } from '@/lib/utils';
import { FileWithPreview } from '@/types';

export function ImageUploadForm() {
  const [queuedFiles, setQueuedFiles] = React.useState<FileWithPreview[]>([]);
  const [isUploading, setIsUploading] = React.useState(false);

  const uploadFileMutation = useUploadFile();
  const uploadMultipleMutation = useUploadMultipleFiles();

  const { activeUploads, completedUploads, failedUploads, clearCompleted, clearFailed } =
    useUploadStore();

  const activeUploadsArray = Array.from(activeUploads.values());
  const failedUploadsArray = Array.from(failedUploads.values());

  const handleFilesAdded = (files: FileWithPreview[]) => {
    setQueuedFiles((prev) => [...prev, ...files]);
  };

  const handleStartUpload = async () => {
    if (queuedFiles.length === 0) return;

    setIsUploading(true);

    try {
      if (queuedFiles.length === 1) {
        await uploadFileMutation.mutateAsync(queuedFiles[0]);
      } else {
        await uploadMultipleMutation.mutateAsync(queuedFiles);
      }

      setQueuedFiles([]);
    } catch (error) {
      console.error('Upload failed:', error);
    } finally {
      setIsUploading(false);
    }
  };

  const handleClearQueue = () => {
    setQueuedFiles([]);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h2 className="text-3xl font-bold tracking-tight">Upload Images</h2>
        <p className="text-muted-foreground mt-2">
          Upload images with chunked upload support and automatic variant generation
        </p>
      </div>

      {/* Upload Form */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Upload className="h-5 w-5" />
            Select Images
          </CardTitle>
          <CardDescription>
            Drag and drop images or click to browse. Maximum 5GB per file.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <Dropzone
            onFilesAdded={handleFilesAdded}
            maxFiles={50}
            maxSize={5 * 1024 * 1024 * 1024} // 5GB
            accept={{
              'image/*': ['.jpg', '.jpeg', '.png', '.gif', '.webp'],
            }}
            multiple={true}
            disabled={isUploading}
          />

          {queuedFiles.length > 0 && (
            <div className="flex items-center justify-between rounded-lg border border-blue-200 bg-blue-50 p-4">
              <div>
                <p className="text-sm font-medium text-blue-900">
                  {formatNumber(queuedFiles.length)} file(s) ready to upload
                </p>
                <p className="text-xs text-blue-700">
                  Images will be processed with variants (256px, 512px, 1024px)
                </p>
              </div>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleClearQueue}
                  disabled={isUploading}
                >
                  Clear
                </Button>
                <Button
                  size="sm"
                  onClick={handleStartUpload}
                  disabled={isUploading}
                  loading={isUploading}
                >
                  <Upload className="h-4 w-4 mr-2" />
                  Upload All
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Upload Status Tabs */}
      <Card>
        <CardHeader>
          <CardTitle>Upload Status</CardTitle>
        </CardHeader>
        <CardContent>
          <Tabs defaultValue="active" className="w-full">
            <TabsList className="grid w-full grid-cols-3">
              <TabsTrigger value="active" className="relative">
                Active
                {activeUploadsArray.length > 0 && (
                  <span className="ml-2 rounded-full bg-blue-600 px-2 py-0.5 text-xs text-white">
                    {activeUploadsArray.length}
                  </span>
                )}
              </TabsTrigger>
              <TabsTrigger value="completed" className="relative">
                Completed
                {completedUploads.length > 0 && (
                  <span className="ml-2 rounded-full bg-green-600 px-2 py-0.5 text-xs text-white">
                    {completedUploads.length}
                  </span>
                )}
              </TabsTrigger>
              <TabsTrigger value="failed" className="relative">
                Failed
                {failedUploadsArray.length > 0 && (
                  <span className="ml-2 rounded-full bg-red-600 px-2 py-0.5 text-xs text-white">
                    {failedUploadsArray.length}
                  </span>
                )}
              </TabsTrigger>
            </TabsList>

            {/* Active Uploads */}
            <TabsContent value="active" className="space-y-3">
              {activeUploadsArray.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12 text-center">
                  <Loader2 className="h-12 w-12 text-gray-400 mb-4" />
                  <p className="text-sm text-gray-500">No active uploads</p>
                </div>
              ) : (
                activeUploadsArray.map((upload) => (
                  <UploadProgressCard
                    key={upload.uploadId}
                    upload={upload}
                    onCancel={() => {
                      // Handle cancel
                    }}
                  />
                ))
              )}
            </TabsContent>

            {/* Completed Uploads */}
            <TabsContent value="completed" className="space-y-3">
              {completedUploads.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12 text-center">
                  <CheckCircle2 className="h-12 w-12 text-gray-400 mb-4" />
                  <p className="text-sm text-gray-500">No completed uploads</p>
                </div>
              ) : (
                <>
                  <div className="flex justify-end">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={clearCompleted}
                    >
                      Clear All
                    </Button>
                  </div>
                  {completedUploads.map((upload) => (
                    <CompletedUploadCard key={upload.id} upload={upload} />
                  ))}
                </>
              )}
            </TabsContent>

            {/* Failed Uploads */}
            <TabsContent value="failed" className="space-y-3">
              {failedUploadsArray.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12 text-center">
                  <CheckCircle2 className="h-12 w-12 text-gray-400 mb-4" />
                  <p className="text-sm text-gray-500">No failed uploads</p>
                </div>
              ) : (
                <>
                  <div className="flex justify-end">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={clearFailed}
                    >
                      Clear All
                    </Button>
                  </div>
                  {failedUploadsArray.map(({ upload, error }) => (
                    <FailedUploadCard
                      key={upload.uploadId}
                      upload={upload}
                      error={error}
                      onRetry={() => {
                        // Handle retry
                      }}
                    />
                  ))}
                </>
              )}
            </TabsContent>
          </Tabs>
        </CardContent>
      </Card>

      {/* Statistics */}
      {(activeUploadsArray.length > 0 ||
        completedUploads.length > 0 ||
        failedUploadsArray.length > 0) && (
        <Card>
          <CardHeader>
            <CardTitle>Upload Statistics</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <StatCard
                label="Active"
                value={activeUploadsArray.length}
                icon={<Loader2 className="h-5 w-5 text-blue-600 animate-spin" />}
                color="blue"
              />
              <StatCard
                label="Completed"
                value={completedUploads.length}
                icon={<CheckCircle2 className="h-5 w-5 text-green-600" />}
                color="green"
              />
              <StatCard
                label="Failed"
                value={failedUploadsArray.length}
                icon={<ImageIcon className="h-5 w-5 text-red-600" />}
                color="red"
              />
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

interface CompletedUploadCardProps {
  upload: any;
}

function CompletedUploadCard({ upload }: CompletedUploadCardProps) {
  return (
    <Card className="border-green-200 bg-green-50/50">
      <CardContent className="p-4">
        <div className="flex items-start gap-3">
          <CheckCircle2 className="h-5 w-5 text-green-600 flex-shrink-0 mt-1" />
          <div className="flex-1 min-w-0">
            <p className="text-sm font-medium text-gray-900">
              {upload.original_filename}
            </p>
            <p className="text-xs text-gray-500 mt-1">
              Completed at {new Date(upload.completed_at).toLocaleString()}
            </p>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

interface FailedUploadCardProps {
  upload: any;
  error: string;
  onRetry: () => void;
}

function FailedUploadCard({ upload, error, onRetry }: FailedUploadCardProps) {
  return (
    <Card className="border-red-200 bg-red-50/50">
      <CardContent className="p-4">
        <div className="flex items-start gap-3">
          <ImageIcon className="h-5 w-5 text-red-600 flex-shrink-0 mt-1" />
          <div className="flex-1 min-w-0">
            <p className="text-sm font-medium text-gray-900">
              {upload.filename}
            </p>
            <p className="text-xs text-red-600 mt-1">{error}</p>
          </div>
          <Button variant="outline" size="sm" onClick={onRetry}>
            Retry
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

interface StatCardProps {
  label: string;
  value: number;
  icon: React.ReactNode;
  color: 'blue' | 'green' | 'red';
}

function StatCard({ label, value, icon, color }: StatCardProps) {
  const colors = {
    blue: 'border-blue-200 bg-blue-50',
    green: 'border-green-200 bg-green-50',
    red: 'border-red-200 bg-red-50',
  };

  return (
    <div className={`rounded-lg border p-4 ${colors[color]}`}>
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-gray-600">{label}</p>
          <p className="text-3xl font-bold text-gray-900">{formatNumber(value)}</p>
        </div>
        {icon}
      </div>
    </div>
  );
}
