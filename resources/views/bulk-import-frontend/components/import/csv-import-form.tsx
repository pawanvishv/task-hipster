// components/import/csv-import-form.tsx

'use client';

import * as React from 'react';
import { Upload, FileText, Download, CheckCircle2, XCircle } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Dropzone } from '@/components/upload/dropzone';
import { useImportProducts, useValidateCsv, useDownloadSampleCsv, useDownloadMockCsv } from '@/lib/hooks/use-import';
import { useImportStore } from '@/lib/store/import-store';
import { formatBytes, formatNumber } from '@/lib/utils';

export function CsvImportForm() {
  const [selectedFile, setSelectedFile] = React.useState<File | null>(null);
  const [validationResult, setValidationResult] = React.useState<any>(null);

  const importMutation = useImportProducts();
  const validateMutation = useValidateCsv();
  const downloadSampleMutation = useDownloadSampleCsv();
  const downloadMockMutation = useDownloadMockCsv();

  const { activeImport, isImporting } = useImportStore();

  const handleFileSelect = (files: any[]) => {
    if (files.length > 0) {
      setSelectedFile(files[0]);
      setValidationResult(null);
    }
  };

  const handleValidate = async () => {
    if (!selectedFile) return;

    const result = await validateMutation.mutateAsync(selectedFile);
    setValidationResult(result);
  };

  const handleImport = async () => {
    if (!selectedFile) return;

    await importMutation.mutateAsync({
      file: selectedFile,
      options: {
        update_existing: true,
        skip_duplicates: false,
      },
    });

    // Clear form after successful import
    setSelectedFile(null);
    setValidationResult(null);
  };

  const handleDownloadSample = () => {
    downloadSampleMutation.mutate();
  };

  const handleDownloadMock = (rows: number) => {
    downloadMockMutation.mutate(rows);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h2 className="text-3xl font-bold tracking-tight">Import Products</h2>
        <p className="text-muted-foreground mt-2">
          Upload a CSV file to bulk import or update products
        </p>
      </div>

      {/* Sample Downloads */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Download className="h-5 w-5" />
            Download Templates
          </CardTitle>
          <CardDescription>
            Download sample CSV files to get started
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="flex flex-wrap gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={handleDownloadSample}
              disabled={downloadSampleMutation.isPending}
            >
              <FileText className="h-4 w-4 mr-2" />
              Sample CSV (3 rows)
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => handleDownloadMock(1000)}
              disabled={downloadMockMutation.isPending}
            >
              <FileText className="h-4 w-4 mr-2" />
              Mock Data (1,000 rows)
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => handleDownloadMock(10000)}
              disabled={downloadMockMutation.isPending}
            >
              <FileText className="h-4 w-4 mr-2" />
              Mock Data (10,000 rows)
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => handleDownloadMock(50000)}
              disabled={downloadMockMutation.isPending}
            >
              <FileText className="h-4 w-4 mr-2" />
              Mock Data (50,000 rows)
            </Button>
          </div>
          <p className="text-xs text-muted-foreground">
            Required columns: <code className="bg-gray-100 px-1 py-0.5 rounded">sku, name, price, stock_quantity</code>
          </p>
        </CardContent>
      </Card>

      {/* File Upload */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Upload className="h-5 w-5" />
            Upload CSV File
          </CardTitle>
          <CardDescription>
            Maximum file size: 100MB
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Dropzone
            onFilesAdded={handleFileSelect}
            maxFiles={1}
            maxSize={100 * 1024 * 1024} // 100MB
            accept={{
              'text/csv': ['.csv'],
              'application/vnd.ms-excel': ['.csv'],
            }}
            multiple={false}
            disabled={isImporting}
          />

          {selectedFile && (
            <div className="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-gray-900">
                    {selectedFile.name}
                  </p>
                  <p className="text-xs text-gray-500">
                    {formatBytes(selectedFile.size)}
                  </p>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => {
                    setSelectedFile(null);
                    setValidationResult(null);
                  }}
                  disabled={isImporting}
                >
                  Remove
                </Button>
              </div>

              {/* Validation Result */}
              {validationResult && (
                <div className={`mt-3 rounded-md p-3 ${
                  validationResult.valid
                    ? 'bg-green-50 border border-green-200'
                    : 'bg-red-50 border border-red-200'
                }`}>
                  <div className="flex items-start gap-2">
                    {validationResult.valid ? (
                      <CheckCircle2 className="h-5 w-5 text-green-600 flex-shrink-0" />
                    ) : (
                      <XCircle className="h-5 w-5 text-red-600 flex-shrink-0" />
                    )}
                    <div className="flex-1">
                      <p className={`text-sm font-medium ${
                        validationResult.valid ? 'text-green-800' : 'text-red-800'
                      }`}>
                        {validationResult.valid
                          ? 'Validation Passed'
                          : 'Validation Failed'}
                      </p>
                      {!validationResult.valid && validationResult.errors && (
                        <ul className="mt-2 space-y-1 text-xs text-red-700">
                          {validationResult.errors.map((error: string, idx: number) => (
                            <li key={idx}>• {error}</li>
                          ))}
                        </ul>
                      )}
                    </div>
                  </div>
                </div>
              )}

              {/* Action Buttons */}
              <div className="mt-4 flex gap-2">
                <Button
                  variant="outline"
                  onClick={handleValidate}
                  disabled={validateMutation.isPending || isImporting}
                  loading={validateMutation.isPending}
                >
                  Validate
                </Button>
                <Button
                  onClick={handleImport}
                  disabled={
                    importMutation.isPending ||
                    isImporting ||
                    (validationResult && !validationResult.valid)
                  }
                  loading={importMutation.isPending}
                >
                  Start Import
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Active Import Progress */}
      {activeImport && isImporting && (
        <Card className="border-blue-200 bg-blue-50/50">
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-blue-900">
              <div className="h-2 w-2 rounded-full bg-blue-600 animate-pulse" />
              Import in Progress
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <div className="flex justify-between text-sm">
                <span className="text-gray-700">Processing...</span>
                <span className="font-medium text-gray-900">
                  {activeImport.processed_rows} / {activeImport.total_rows}
                </span>
              </div>
              <Progress
                value={
                  activeImport.total_rows > 0
                    ? (activeImport.processed_rows / activeImport.total_rows) * 100
                    : 0
                }
                showLabel
              />
            </div>

            <div className="grid grid-cols-2 gap-4 text-sm">
              <div>
                <p className="text-gray-500">Imported</p>
                <p className="text-lg font-semibold text-green-600">
                  {formatNumber(activeImport.imported_rows)}
                </p>
              </div>
              <div>
                <p className="text-gray-500">Updated</p>
                <p className="text-lg font-semibold text-blue-600">
                  {formatNumber(activeImport.updated_rows)}
                </p>
              </div>
              <div>
                <p className="text-gray-500">Invalid</p>
                <p className="text-lg font-semibold text-red-600">
                  {formatNumber(activeImport.invalid_rows)}
                </p>
              </div>
              <div>
                <p className="text-gray-500">Duplicates</p>
                <p className="text-lg font-semibold text-yellow-600">
                  {formatNumber(activeImport.duplicate_rows)}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Import Result */}
      {importMutation.isSuccess && importMutation.data && (
        <Card className="border-green-200 bg-green-50/50">
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-green-900">
              <CheckCircle2 className="h-5 w-5" />
              Import Completed
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
              <StatCard
                label="Total Rows"
                value={importMutation.data.total}
                variant="default"
              />
              <StatCard
                label="Imported"
                value={importMutation.data.imported}
                variant="success"
              />
              <StatCard
                label="Updated"
                value={importMutation.data.updated}
                variant="info"
              />
              <StatCard
                label="Invalid"
                value={importMutation.data.invalid}
                variant="error"
              />
              <StatCard
                label="Duplicates"
                value={importMutation.data.duplicates}
                variant="warning"
              />
              <StatCard
                label="Success Rate"
                value={`${importMutation.data.success_rate}%`}
                variant="success"
              />
            </div>

            {importMutation.data.errors && importMutation.data.errors.length > 0 && (
              <details className="mt-4">
                <summary className="cursor-pointer text-sm font-medium text-gray-700">
                  View Errors ({importMutation.data.errors.length})
                </summary>
                <div className="mt-2 max-h-60 overflow-y-auto rounded-md border border-gray-200 bg-white p-3">
                  <pre className="text-xs text-gray-600">
                    {JSON.stringify(importMutation.data.errors, null, 2)}
                  </pre>
                </div>
              </details>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

interface StatCardProps {
  label: string;
  value: string | number;
  variant: 'default' | 'success' | 'info' | 'error' | 'warning';
}

function StatCard({ label, value, variant }: StatCardProps) {
  const colors = {
    default: 'text-gray-600',
    success: 'text-green-600',
    info: 'text-blue-600',
    error: 'text-red-600',
    warning: 'text-yellow-600',
  };

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-3">
      <p className="text-xs text-gray-500">{label}</p>
      <p className={`text-2xl font-bold ${colors[variant]}`}>
        {typeof value === 'number' ? formatNumber(value) : value}
      </p>
    </div>
  );
}
