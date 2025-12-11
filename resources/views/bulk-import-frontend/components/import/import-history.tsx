// components/import/import-history.tsx

'use client';

import * as React from 'react';
import { FileText, Clock, CheckCircle2, XCircle, AlertCircle, ChevronRight } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useImportHistory, useImportDetails } from '@/lib/hooks/use-import';
import { formatRelativeTime, formatNumber, formatDuration, getStatusVariant } from '@/lib/utils';
import { ImportLog } from '@/types';

export function ImportHistory() {
  const [selectedImport, setSelectedImport] = React.useState<string | null>(null);
  const [page, setPage] = React.useState(1);

  const { data: historyData, isLoading } = useImportHistory({
    page,
    per_page: 10,
  });

  const { data: detailsData } = useImportDetails(
    selectedImport || '',
    !!selectedImport
  );

  const handleViewDetails = (importId: string) => {
    setSelectedImport(importId);
  };

  const handleCloseDetails = () => {
    setSelectedImport(null);
  };

  if (isLoading) {
    return (
      <Card>
        <CardContent className="p-12">
          <div className="flex flex-col items-center justify-center">
            <div className="h-8 w-8 animate-spin rounded-full border-4 border-gray-300 border-t-primary"></div>
            <p className="mt-4 text-sm text-gray-500">Loading import history...</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h2 className="text-3xl font-bold tracking-tight">Import History</h2>
        <p className="text-muted-foreground mt-2">
          View past imports and their results
        </p>
      </div>

      {/* History List */}
      <Card>
        <CardHeader>
          <CardTitle>Recent Imports</CardTitle>
          <CardDescription>
            {historyData?.total ? `${formatNumber(historyData.total)} total imports` : 'No imports yet'}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {!historyData?.data || historyData.data.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-12 text-center">
              <FileText className="h-12 w-12 text-gray-400 mb-4" />
              <p className="text-sm text-gray-500">No import history available</p>
              <p className="text-xs text-gray-400 mt-1">
                Start an import to see it here
              </p>
            </div>
          ) : (
            <div className="space-y-3">
              {historyData.data.map((importLog) => (
                <ImportHistoryCard
                  key={importLog.id}
                  importLog={importLog}
                  onViewDetails={() => handleViewDetails(importLog.id)}
                />
              ))}

              {/* Pagination */}
              {historyData.last_page > 1 && (
                <div className="flex items-center justify-between pt-4 border-t">
                  <p className="text-sm text-gray-500">
                    Page {page} of {historyData.last_page}
                  </p>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setPage((p) => Math.max(1, p - 1))}
                      disabled={page === 1}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setPage((p) => Math.min(historyData.last_page, p + 1))}
                      disabled={page === historyData.last_page}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Details Modal/Panel */}
      {selectedImport && detailsData && (
        <Card className="border-primary">
          <CardHeader>
            <div className="flex items-start justify-between">
              <div>
                <CardTitle>Import Details</CardTitle>
                <CardDescription>
                  {detailsData.import.filename}
                </CardDescription>
              </div>
              <Button variant="ghost" size="sm" onClick={handleCloseDetails}>
                Close
              </Button>
            </div>
          </CardHeader>
          <CardContent className="space-y-6">
            {/* Summary Stats */}
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
              <StatCard
                label="Total Rows"
                value={detailsData.summary.total}
                color="default"
              />
              <StatCard
                label="Imported"
                value={detailsData.summary.imported}
                color="green"
              />
              <StatCard
                label="Updated"
                value={detailsData.summary.updated}
                color="blue"
              />
              <StatCard
                label="Invalid"
                value={detailsData.summary.invalid}
                color="red"
              />
              <StatCard
                label="Duplicates"
                value={detailsData.summary.duplicates}
                color="yellow"
              />
              <StatCard
                label="Success Rate"
                value={`${detailsData.summary.success_rate.toFixed(1)}%`}
                color="green"
              />
            </div>

            {/* Metadata */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <p className="text-xs text-gray-500 mb-1">Started At</p>
                <p className="text-sm font-medium text-gray-900">
                  {new Date(detailsData.import.started_at!).toLocaleString()}
                </p>
              </div>
              <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <p className="text-xs text-gray-500 mb-1">Processing Time</p>
                <p className="text-sm font-medium text-gray-900">
                  {formatDuration(detailsData.summary.processing_time)}
                </p>
              </div>
            </div>

            {/* Error Details */}
            {detailsData.import.error_details &&
             Object.keys(detailsData.import.error_details).length > 0 && (
              <details className="rounded-lg border border-gray-200">
                <summary className="cursor-pointer p-4 font-medium text-sm hover:bg-gray-50">
                  View Error Details
                </summary>
                <div className="border-t border-gray-200 p-4 bg-gray-50">
                  <pre className="text-xs text-gray-600 overflow-x-auto">
                    {JSON.stringify(detailsData.import.error_details, null, 2)}
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

interface ImportHistoryCardProps {
  importLog: ImportLog;
  onViewDetails: () => void;
}

function ImportHistoryCard({ importLog, onViewDetails }: ImportHistoryCardProps) {
  const statusIcons = {
    pending: <Clock className="h-4 w-4" />,
    processing: <Clock className="h-4 w-4" />,
    completed: <CheckCircle2 className="h-4 w-4" />,
    partially_completed: <AlertCircle className="h-4 w-4" />,
    failed: <XCircle className="h-4 w-4" />,
  };

  return (
    <Card className="hover:shadow-md transition-shadow">
      <CardContent className="p-4">
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-start gap-3 flex-1 min-w-0">
            <div className="flex-shrink-0 rounded-full p-2 bg-gray-100">
              <FileText className="h-5 w-5 text-gray-600" />
            </div>

            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 mb-1">
                <p className="text-sm font-medium text-gray-900 truncate">
                  {importLog.filename}
                </p>
                <Badge variant={getStatusVariant(importLog.status)}>
                  <span className="flex items-center gap-1">
                    {statusIcons[importLog.status]}
                    {importLog.status}
                  </span>
                </Badge>
              </div>

              <p className="text-xs text-gray-500 mb-2">
                {formatRelativeTime(importLog.created_at)}
              </p>

              <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                <span className="text-gray-600">
                  Total: <span className="font-medium text-gray-900">{formatNumber(importLog.total_rows)}</span>
                </span>
                <span className="text-green-600">
                  Imported: <span className="font-medium">{formatNumber(importLog.imported_rows)}</span>
                </span>
                <span className="text-blue-600">
                  Updated: <span className="font-medium">{formatNumber(importLog.updated_rows)}</span>
                </span>
                {importLog.invalid_rows > 0 && (
                  <span className="text-red-600">
                    Invalid: <span className="font-medium">{formatNumber(importLog.invalid_rows)}</span>
                  </span>
                )}
                {importLog.duplicate_rows > 0 && (
                  <span className="text-yellow-600">
                    Duplicates: <span className="font-medium">{formatNumber(importLog.duplicate_rows)}</span>
                  </span>
                )}
              </div>
            </div>
          </div>

          <Button
            variant="ghost"
            size="sm"
            onClick={onViewDetails}
            className="flex-shrink-0"
          >
            Details
            <ChevronRight className="h-4 w-4 ml-1" />
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

interface StatCardProps {
  label: string;
  value: string | number;
  color: 'default' | 'green' | 'blue' | 'red' | 'yellow';
}

function StatCard({ label, value, color }: StatCardProps) {
  const colors = {
    default: 'border-gray-200 bg-gray-50',
    green: 'border-green-200 bg-green-50',
    blue: 'border-blue-200 bg-blue-50',
    red: 'border-red-200 bg-red-50',
    yellow: 'border-yellow-200 bg-yellow-50',
  };

  const textColors = {
    default: 'text-gray-900',
    green: 'text-green-900',
    blue: 'text-blue-900',
    red: 'text-red-900',
    yellow: 'text-yellow-900',
  };

  return (
    <div className={`rounded-lg border p-3 ${colors[color]}`}>
      <p className="text-xs text-gray-600 mb-1">{label}</p>
      <p className={`text-2xl font-bold ${textColors[color]}`}>
        {typeof value === 'number' ? formatNumber(value) : value}
      </p>
    </div>
  );
}
