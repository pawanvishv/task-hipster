// components/upload/dropzone.tsx

'use client';

import * as React from 'react';
import { useDropzone } from 'react-dropzone';
import { Upload, X, File, Image as ImageIcon } from 'lucide-react';
import { cn, formatBytes, isImageFile, isCsvFile } from '@/lib/utils';
import { FileWithPreview } from '@/types';

interface DropzoneProps {
  onFilesAdded: (files: FileWithPreview[]) => void;
  maxFiles?: number;
  maxSize?: number;
  accept?: Record<string, string[]>;
  disabled?: boolean;
  multiple?: boolean;
  className?: string;
}

export function Dropzone({
  onFilesAdded,
  maxFiles = 10,
  maxSize = 5 * 1024 * 1024 * 1024, // 5GB
  accept,
  disabled = false,
  multiple = true,
  className,
}: DropzoneProps) {
  const [files, setFiles] = React.useState<FileWithPreview[]>([]);

  const onDrop = React.useCallback(
    (acceptedFiles: File[]) => {
      const newFiles = acceptedFiles.map((file) => {
        const fileWithPreview = Object.assign(file, {
          preview: isImageFile(file) ? URL.createObjectURL(file) : undefined,
          uploadId: undefined,
          progress: 0,
          status: 'pending' as const,
        });
        return fileWithPreview;
      });

      setFiles((prev) => [...prev, ...newFiles]);
      onFilesAdded(newFiles);
    },
    [onFilesAdded]
  );

  const {
    getRootProps,
    getInputProps,
    isDragActive,
    isDragReject,
    fileRejections,
  } = useDropzone({
    onDrop,
    maxFiles,
    maxSize,
    accept,
    disabled,
    multiple,
  });

  const removeFile = (file: FileWithPreview) => {
    setFiles((prev) => prev.filter((f) => f !== file));
    if (file.preview) {
      URL.revokeObjectURL(file.preview);
    }
  };

  // Cleanup previews on unmount
  React.useEffect(() => {
    return () => {
      files.forEach((file) => {
        if (file.preview) {
          URL.revokeObjectURL(file.preview);
        }
      });
    };
  }, [files]);

  return (
    <div className={cn('w-full', className)}>
      <div
        {...getRootProps()}
        className={cn(
          'relative cursor-pointer rounded-lg border-2 border-dashed p-8 text-center transition-colors',
          isDragActive && 'border-primary bg-primary/5',
          isDragReject && 'border-red-500 bg-red-50',
          disabled && 'cursor-not-allowed opacity-50',
          !isDragActive && !isDragReject && 'border-gray-300 hover:border-gray-400'
        )}
      >
        <input {...getInputProps()} />

        <div className="flex flex-col items-center justify-center gap-4">
          <div className={cn(
            'rounded-full p-4',
            isDragActive && 'bg-primary/10',
            isDragReject && 'bg-red-100'
          )}>
            <Upload className={cn(
              'h-10 w-10',
              isDragActive && 'text-primary',
              isDragReject && 'text-red-500',
              !isDragActive && !isDragReject && 'text-gray-400'
            )} />
          </div>

          <div>
            <p className="text-lg font-medium text-gray-900">
              {isDragActive ? (
                isDragReject ? (
                  'Invalid file type'
                ) : (
                  'Drop files here'
                )
              ) : (
                'Drag & drop files here'
              )}
            </p>
            <p className="mt-1 text-sm text-gray-500">
              or click to browse
            </p>
          </div>

          <div className="text-xs text-gray-500">
            <p>Maximum file size: {formatBytes(maxSize)}</p>
            {maxFiles > 1 && <p>Maximum files: {maxFiles}</p>}
          </div>
        </div>
      </div>

      {/* File Rejections */}
      {fileRejections.length > 0 && (
        <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-4">
          <p className="text-sm font-medium text-red-800">
            {fileRejections.length} file(s) rejected:
          </p>
          <ul className="mt-2 space-y-1 text-xs text-red-700">
            {fileRejections.map(({ file, errors }) => (
              <li key={file.name}>
                {file.name} - {errors.map((e) => e.message).join(', ')}
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* File List */}
      {files.length > 0 && (
        <div className="mt-4 space-y-2">
          {files.map((file, index) => (
            <FilePreview
              key={`${file.name}-${index}`}
              file={file}
              onRemove={() => removeFile(file)}
            />
          ))}
        </div>
      )}
    </div>
  );
}

interface FilePreviewProps {
  file: FileWithPreview;
  onRemove: () => void;
}

function FilePreview({ file, onRemove }: FilePreviewProps) {
  const isImage = isImageFile(file);
  const isCsv = isCsvFile(file);

  return (
    <div className="flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-3">
      {/* File Icon/Preview */}
      <div className="flex-shrink-0">
        {isImage && file.preview ? (
          <img
            src={file.preview}
            alt={file.name}
            className="h-12 w-12 rounded object-cover"
          />
        ) : (
          <div className="flex h-12 w-12 items-center justify-center rounded bg-gray-100">
            {isCsv ? (
              <File className="h-6 w-6 text-gray-600" />
            ) : (
              <ImageIcon className="h-6 w-6 text-gray-600" />
            )}
          </div>
        )}
      </div>

      {/* File Info */}
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-gray-900">
          {file.name}
        </p>
        <p className="text-xs text-gray-500">
          {formatBytes(file.size)}
        </p>
      </div>

      {/* Status Badge */}
      {file.status && file.status !== 'pending' && (
        <div className={cn(
          'rounded-full px-2 py-1 text-xs font-medium',
          file.status === 'uploading' && 'bg-blue-100 text-blue-700',
          file.status === 'completed' && 'bg-green-100 text-green-700',
          file.status === 'failed' && 'bg-red-100 text-red-700'
        )}>
          {file.status}
        </div>
      )}

      {/* Remove Button */}
      <button
        onClick={onRemove}
        className="flex-shrink-0 rounded-full p-1 hover:bg-gray-100 transition-colors"
        aria-label="Remove file"
      >
        <X className="h-4 w-4 text-gray-500" />
      </button>
    </div>
  );
}
