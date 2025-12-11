// components/upload/upload-progress-card.tsx

'use client';

import * as React from 'react';
import { CheckCircle2, XCircle, Loader2, Pause, Play, X } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Button } from '@/components/ui/button';
import { cn, formatBytes } from '@/lib/utils';
import { UploadProgress } from '@/types';

interface UploadProgressCardProps {
  upload: UploadProgress;
  onPause?: () => void;
  onResume?: () => void;
  onCancel?: () => void;
  onRetry?: () => void;
}

export function UploadProgressCard({
  upload,
  onPause,
  onResume,
  onCancel,
  onRetry,
}: UploadProgressCardProps) {
  const isCompleted = upload.status === 'completed';
  const isFailed = upload.status === 'failed';
  const isUploading = upload.status === 'uploading';

  return (
    <Card className={cn(
      'transition-all',
      isCompleted && 'border-green-200 bg-green-50/50',
      isFailed && 'border-red-200 bg-red-50/50'
    )}>
      <CardContent className="p-4">
        <div className="flex items-start gap-3">
          {/* Status Icon */}
          <div className="flex-shrink-0 mt-1">
            {isCompleted && (
              <CheckCircle2 className="h-5 w-5 text-green-600" />
            )}
            {isFailed && (
              <XCircle className="h-5 w-5 text-red-600" />
            )}
            {isUploading && (
              <Loader2 className="h-5 w-5 text-blue-600 animate-spin" />
            )}
          </div>

          {/* Content */}
          <div className="flex-1 min-w-0 space-y-2">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-gray-900">
                  {upload.filename}
                </p>
                <p className="text-xs text-gray-500">
                  {upload.uploadedChunks} / {upload.totalChunks} chunks
                </p>
              </div>

              {/* Action Buttons */}
              <div className="flex items-center gap-1">
                {isUploading && onPause && (
                  <Button
                    size="icon"
                    variant="ghost"
                    onClick={onPause}
                    className="h-8 w-8"
                  >
                    <Pause className="h-4 w-4" />
                  </Button>
                )}

                {isFailed && onRetry && (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={onRetry}
                  >
                    Retry
                  </Button>
                )}

                {(isUploading || isFailed) && onCancel && (
                  <Button
                    size="icon"
                    variant="ghost"
                    onClick={onCancel}
                    className="h-8 w-8"
                  >
                    <X className="h-4 w-4" />
                  </Button>
                )}
              </div>
            </div>

            {/* Progress Bar */}
            {!isCompleted && (
              <Progress value={upload.progress} showLabel size="sm" />
            )}

            {/* Error Message */}
            {isFailed && upload.error && (
              <p className="text-xs text-red-600">
                {upload.error}
              </p>
            )}

            {/* Completion Message */}
            {isCompleted && (
              <p className="text-xs text-green-600">
                Upload completed successfully
              </p>
            )}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
