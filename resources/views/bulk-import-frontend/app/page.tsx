// app/page.tsx

"use client";

import * as React from "react";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { CsvImportForm } from "@/components/import/csv-import-form";
import { ImageUploadForm } from "@/components/upload/image-upload-form";
import { ImportHistory } from "@/components/import/import-history";
import { StatisticsDashboard } from "@/components/dashboard/statistics-dashboard";
import { FileText, Upload, History, BarChart3 } from "lucide-react";

export default function Home() {
    const [activeTab, setActiveTab] = React.useState("import");

    return (
        <div className="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100">
            {/* Header */}
            <header className="border-b bg-white shadow-sm">
                <div className="container mx-auto px-4 py-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-3xl font-bold text-gray-900">
                                Bulk Import System
                            </h1>
                            <p className="text-sm text-gray-600 mt-1">
                                Enterprise-grade CSV import and chunked image
                                upload
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <div className="rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700">
                                Laravel 11 + Next.js 14
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            {/* Main Content */}
            <main className="container mx-auto px-4 py-8">
                <Tabs
                    value={activeTab}
                    onValueChange={setActiveTab}
                    className="space-y-6"
                >
                    {/* Tab Navigation */}
                    <div className="flex items-center justify-center">
                        <TabsList className="grid w-full max-w-2xl grid-cols-4 h-auto p-1">
                            <TabsTrigger
                                value="import"
                                className="flex items-center gap-2 py-3"
                            >
                                <FileText className="h-4 w-4" />
                                <span className="hidden sm:inline">
                                    CSV Import
                                </span>
                            </TabsTrigger>
                            <TabsTrigger
                                value="upload"
                                className="flex items-center gap-2 py-3"
                            >
                                <Upload className="h-4 w-4" />
                                <span className="hidden sm:inline">
                                    Image Upload
                                </span>
                            </TabsTrigger>
                            <TabsTrigger
                                value="history"
                                className="flex items-center gap-2 py-3"
                            >
                                <History className="h-4 w-4" />
                                <span className="hidden sm:inline">
                                    History
                                </span>
                            </TabsTrigger>
                            <TabsTrigger
                                value="statistics"
                                className="flex items-center gap-2 py-3"
                            >
                                <BarChart3 className="h-4 w-4" />
                                <span className="hidden sm:inline">
                                    Statistics
                                </span>
                            </TabsTrigger>
                        </TabsList>
                    </div>

                    {/* Tab Content */}
                    <TabsContent value="import" className="space-y-6">
                        <CsvImportForm />
                    </TabsContent>

                    <TabsContent value="upload" className="space-y-6">
                        <ImageUploadForm />
                    </TabsContent>

                    <TabsContent value="history" className="space-y-6">
                        <ImportHistory />
                    </TabsContent>

                    <TabsContent value="statistics" className="space-y-6">
                        <StatisticsDashboard />
                    </TabsContent>
                </Tabs>
            </main>

            {/* Footer */}
            <footer className="border-t bg-white mt-12">
                <div className="container mx-auto px-4 py-6">
                    <div className="flex flex-col md:flex-row items-center justify-between gap-4">
                        <div className="text-sm text-gray-600">
                            <p>
                                Built with{" "}
                                <span className="text-red-500">♥</span> using
                                Laravel 11, Next.js 14, TypeScript, and Tailwind
                                CSS
                            </p>
                        </div>
                        <div className="flex items-center gap-4 text-xs text-gray-500">
                            <span>Chunked Upload Support</span>
                            <span>•</span>
                            <span>Resume Capability</span>
                            <span>•</span>
                            <span>SHA256 Validation</span>
                            <span>•</span>
                            <span>Variant Generation</span>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    );
}
