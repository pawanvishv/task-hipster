// components/dashboard/statistics-dashboard.tsx

'use client';

import * as React from 'react';
import { TrendingUp, TrendingDown, FileText, Upload, CheckCircle2, XCircle } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useImportStatistics } from '@/lib/hooks/use-import';
import { formatNumber, formatDuration } from '@/lib/utils';
import { BarChart, Bar, LineChart, Line, PieChart, Pie, Cell, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const COLORS = {
  imported: '#10b981',
  updated: '#3b82f6',
  invalid: '#ef4444',
  duplicates: '#f59e0b',
  success: '#10b981',
  failed: '#ef4444',
};

export function StatisticsDashboard() {
  const [period, setPeriod] = React.useState<7 | 30 | 90>(30);

  const { data: statsData, isLoading, error } = useImportStatistics({
    days: period,
  });

  if (isLoading) {
    return (
      <Card>
        <CardContent className="p-12">
          <div className="flex flex-col items-center justify-center">
            <div className="h-8 w-8 animate-spin rounded-full border-4 border-gray-300 border-t-primary"></div>
            <p className="mt-4 text-sm text-gray-500">Loading statistics...</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  if (error) {
    return (
      <Card>
        <CardContent className="p-12">
          <div className="flex flex-col items-center justify-center text-center">
            <XCircle className="h-12 w-12 text-red-500 mb-4" />
            <p className="text-sm text-gray-900 font-medium">Failed to load statistics</p>
            <p className="text-xs text-gray-500 mt-1">{error.message}</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  if (!statsData || !statsData.statistics) {
    return (
      <Card>
        <CardContent className="p-12">
          <div className="flex flex-col items-center justify-center text-center">
            <FileText className="h-12 w-12 text-gray-400 mb-4" />
            <p className="text-sm text-gray-900 font-medium">No statistics available</p>
            <p className="text-xs text-gray-500 mt-1">Start importing data to see statistics</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  const stats = statsData.statistics;

  // Safely prepare chart data with default values
  const overviewData = [
    { name: 'Imported', value: stats.total_rows_imported || 0, fill: COLORS.imported },
    { name: 'Updated', value: stats.total_rows_updated || 0, fill: COLORS.updated },
    { name: 'Invalid', value: stats.total_rows_invalid || 0, fill: COLORS.invalid },
    { name: 'Duplicates', value: stats.total_rows_duplicate || 0, fill: COLORS.duplicates },
  ];

  const successData = [
    { name: 'Completed', value: stats.completed_imports || 0, fill: COLORS.success },
    { name: 'Failed', value: stats.failed_imports || 0, fill: COLORS.failed },
  ];

  // Check if there's any data
  const hasData = stats.total_imports > 0;

  if (!hasData) {
    return (
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-3xl font-bold tracking-tight">Statistics</h2>
            <p className="text-muted-foreground mt-2">
              Import analytics for the last {period} days
            </p>
          </div>

          {/* Period Selector */}
          <Tabs value={period.toString()} onValueChange={(v) => setPeriod(parseInt(v) as 7 | 30 | 90)}>
            <TabsList>
              <TabsTrigger value="7">7 Days</TabsTrigger>
              <TabsTrigger value="30">30 Days</TabsTrigger>
              <TabsTrigger value="90">90 Days</TabsTrigger>
            </TabsList>
          </Tabs>
        </div>

        <Card>
          <CardContent className="p-12">
            <div className="flex flex-col items-center justify-center text-center">
              <FileText className="h-12 w-12 text-gray-400 mb-4" />
              <p className="text-sm text-gray-900 font-medium">No imports yet</p>
              <p className="text-xs text-gray-500 mt-1">Start importing products to see statistics here</p>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-3xl font-bold tracking-tight">Statistics</h2>
          <p className="text-muted-foreground mt-2">
            Import analytics for the last {period} days
          </p>
        </div>

        {/* Period Selector */}
        <Tabs value={period.toString()} onValueChange={(v) => setPeriod(parseInt(v) as 7 | 30 | 90)}>
          <TabsList>
            <TabsTrigger value="7">7 Days</TabsTrigger>
            <TabsTrigger value="30">30 Days</TabsTrigger>
            <TabsTrigger value="90">90 Days</TabsTrigger>
          </TabsList>
        </Tabs>
      </div>

      {/* Key Metrics */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <MetricCard
          title="Total Imports"
          value={stats.total_imports}
          icon={<FileText className="h-5 w-5" />}
          trend={stats.total_imports > 0 ? 'up' : 'neutral'}
          color="blue"
        />
        <MetricCard
          title="Completed"
          value={stats.completed_imports}
          subtitle={`${((stats.completed_imports / stats.total_imports) * 100 || 0).toFixed(1)}% success rate`}
          icon={<CheckCircle2 className="h-5 w-5" />}
          trend={stats.completed_imports > stats.failed_imports ? 'up' : 'down'}
          color="green"
        />
        <MetricCard
          title="Rows Processed"
          value={stats.total_rows_processed}
          subtitle={`${formatNumber(stats.total_rows_imported + stats.total_rows_updated)} successful`}
          icon={<Upload className="h-5 w-5" />}
          trend="up"
          color="purple"
        />
        <MetricCard
          title="Avg Success Rate"
          value={`${stats.average_success_rate.toFixed(1)}%`}
          subtitle={`Avg time: ${formatDuration(Math.round(stats.average_processing_time))}`}
          icon={<TrendingUp className="h-5 w-5" />}
          trend={stats.average_success_rate >= 80 ? 'up' : 'down'}
          color="indigo"
        />
      </div>

      {/* Charts */}
      <div className="grid gap-6 md:grid-cols-2">
        {/* Rows Distribution */}
        <Card>
          <CardHeader>
            <CardTitle>Rows Distribution</CardTitle>
            <CardDescription>
              Breakdown of {formatNumber(stats.total_rows_processed)} processed rows
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={300}>
              <PieChart>
                <Pie
                  data={overviewData}
                  cx="50%"
                  cy="50%"
                  labelLine={false}
                  label={({ name, percent }) => `${name} ${(percent * 100).toFixed(0)}%`}
                  outerRadius={80}
                  fill="#8884d8"
                  dataKey="value"
                >
                  {overviewData.map((entry, index) => (
                    <Cell key={`cell-${index}`} fill={entry.fill} />
                  ))}
                </Pie>
                <Tooltip formatter={(value: number) => formatNumber(value)} />
              </PieChart>
            </ResponsiveContainer>

            <div className="mt-4 grid grid-cols-2 gap-4">
              {overviewData.map((item) => (
                <div key={item.name} className="flex items-center gap-2">
                  <div
                    className="h-3 w-3 rounded-full"
                    style={{ backgroundColor: item.fill }}
                  />
                  <div className="flex-1 min-w-0">
                    <p className="text-xs text-gray-500">{item.name}</p>
                    <p className="text-sm font-medium">{formatNumber(item.value)}</p>
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        {/* Import Success Rate */}
        <Card>
          <CardHeader>
            <CardTitle>Import Success Rate</CardTitle>
            <CardDescription>
              {stats.total_imports} total imports
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={300}>
              <PieChart>
                <Pie
                  data={successData}
                  cx="50%"
                  cy="50%"
                  labelLine={false}
                  label={({ name, percent }) => `${name} ${(percent * 100).toFixed(0)}%`}
                  outerRadius={80}
                  fill="#8884d8"
                  dataKey="value"
                >
                  {successData.map((entry, index) => (
                    <Cell key={`cell-${index}`} fill={entry.fill} />
                  ))}
                </Pie>
                <Tooltip formatter={(value: number) => formatNumber(value)} />
              </PieChart>
            </ResponsiveContainer>

            <div className="mt-4 grid grid-cols-2 gap-4">
              {successData.map((item) => (
                <div key={item.name} className="flex items-center gap-2">
                  <div
                    className="h-3 w-3 rounded-full"
                    style={{ backgroundColor: item.fill }}
                  />
                  <div className="flex-1 min-w-0">
                    <p className="text-xs text-gray-500">{item.name}</p>
                    <p className="text-sm font-medium">{formatNumber(item.value)}</p>
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Detailed Stats */}
      <Card>
        <CardHeader>
          <CardTitle>Detailed Statistics</CardTitle>
          <CardDescription>
            Period: {statsData.period.from} to {statsData.period.to}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <DetailCard
              label="Total Rows"
              value={stats.total_rows_processed}
              subValue={`${stats.total_imports} imports`}
            />
            <DetailCard
              label="Imported Rows"
              value={stats.total_rows_imported}
              subValue={`${((stats.total_rows_imported / stats.total_rows_processed) * 100 || 0).toFixed(1)}% of total`}
              valueColor="text-green-600"
            />
            <DetailCard
              label="Updated Rows"
              value={stats.total_rows_updated}
              subValue={`${((stats.total_rows_updated / stats.total_rows_processed) * 100 || 0).toFixed(1)}% of total`}
              valueColor="text-blue-600"
            />
            <DetailCard
              label="Invalid Rows"
              value={stats.total_rows_invalid}
              subValue={`${((stats.total_rows_invalid / stats.total_rows_processed) * 100 || 0).toFixed(1)}% of total`}
              valueColor="text-red-600"
            />
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

interface MetricCardProps {
  title: string;
  value: number | string;
  subtitle?: string;
  icon: React.ReactNode;
  trend?: 'up' | 'down' | 'neutral';
  color?: 'blue' | 'green' | 'purple' | 'indigo' | 'red';
}

function MetricCard({ title, value, subtitle, icon, trend, color = 'blue' }: MetricCardProps) {
  const colorClasses = {
    blue: 'bg-blue-100 text-blue-600',
    green: 'bg-green-100 text-green-600',
    purple: 'bg-purple-100 text-purple-600',
    indigo: 'bg-indigo-100 text-indigo-600',
    red: 'bg-red-100 text-red-600',
  };

  return (
    <Card>
      <CardContent className="p-6">
        <div className="flex items-center justify-between">
          <div className="flex-1">
            <p className="text-sm font-medium text-gray-600">{title}</p>
            <p className="text-3xl font-bold text-gray-900 mt-2">
              {typeof value === 'number' ? formatNumber(value) : value}
            </p>
            {subtitle && (
              <p className="text-xs text-gray-500 mt-1">{subtitle}</p>
            )}
          </div>
          <div className={`rounded-full p-3 ${colorClasses[color]}`}>
            {icon}
          </div>
        </div>
        {trend && trend !== 'neutral' && (
          <div className="mt-4 flex items-center gap-1">
            {trend === 'up' ? (
              <TrendingUp className="h-4 w-4 text-green-600" />
            ) : (
              <TrendingDown className="h-4 w-4 text-red-600" />
            )}
            <span className={`text-xs font-medium ${
              trend === 'up' ? 'text-green-600' : 'text-red-600'
            }`}>
              {trend === 'up' ? 'Trending up' : 'Trending down'}
            </span>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

interface DetailCardProps {
  label: string;
  value: number;
  subValue?: string;
  valueColor?: string;
}

function DetailCard({ label, value, subValue, valueColor = 'text-gray-900' }: DetailCardProps) {
  return (
    <div className="rounded-lg border border-gray-200 bg-white p-4">
      <p className="text-xs text-gray-500 mb-1">{label}</p>
      <p className={`text-2xl font-bold ${valueColor}`}>
        {formatNumber(value)}
      </p>
      {subValue && (
        <p className="text-xs text-gray-500 mt-1">{subValue}</p>
      )}
    </div>
  );
}
