// types/index.ts

/**
 * Product related types
 */
export interface Product {
  id: number;
  sku: string;
  name: string;
  description: string | null;
  price: number;
  stock_quantity: number;
  primary_image_id: string | null;
  status: 'active' | 'inactive' | 'discontinued';
  metadata: Record<string, any> | null;
  created_at: string;
  updated_at: string;
  primary_image?: Image;
}

/**
 * Upload related types
 */
export interface Upload {
  id: string;
  original_filename: string;
  stored_filename: string;
  mime_type: string;
  total_size: number;
  total_chunks: number;
  uploaded_chunks: number;
  checksum_sha256: string;
  status: 'pending' | 'uploading' | 'completed' | 'failed';
  upload_metadata: UploadMetadata | null;
  completed_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface UploadMetadata {
  uploaded_chunks: number[];
  initialized_at: string;
  failure_reason?: string;
}

/**
 * Image related types
 */
export interface Image {
  id: string;
  upload_id: string;
  variant: 'original' | 'thumbnail_256' | 'medium_512' | 'large_1024';
  path: string;
  disk: string;
  url: string;
  width: number;
  height: number;
  size_bytes: number;
  mime_type: string;
  created_at: string;
  updated_at: string;
}

/**
 * Import related types
 */
export interface ImportLog {
  id: string;
  import_type: 'products' | 'users';
  filename: string;
  file_hash: string;
  status: 'pending' | 'processing' | 'completed' | 'failed' | 'partially_completed';
  total_rows: number;
  processed_rows: number;
  imported_rows: number;
  updated_rows: number;
  invalid_rows: number;
  duplicate_rows: number;
  error_details: ErrorDetail[] | null;
  configuration: Record<string, any> | null;
  started_at: string | null;
  completed_at: string | null;
  processing_time_seconds: number | null;
  user_id: number | null;
  created_at: string;
  updated_at: string;
}

export interface ErrorDetail {
  row?: number;
  error?: string;
  errors?: string[];
  sku?: string;
  first_seen?: number;
}

export interface ImportResult {
  total: number;
  imported: number;
  updated: number;
  invalid: number;
  duplicates: number;
  processed: number;
  success_rate: number;
  errors: ErrorDetail[];
  import_log_id: string;
}

export interface ImportStatistics {
  total_imports: number;
  completed_imports: number;
  failed_imports: number;
  total_rows_processed: number;
  total_rows_imported: number;
  total_rows_updated: number;
  total_rows_invalid: number;
  total_rows_duplicate: number;
  average_success_rate: number;
  average_processing_time: number;
}

/**
 * API Response types
 */
export interface ApiResponse<T = any> {
  success: boolean;
  message?: string;
  data?: T;
  error?: string;
  errors?: Record<string, string[]>;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number;
  to: number;
}

/**
 * Upload chunk types
 */
export interface ChunkUploadData {
  upload_id: string;
  chunk_index: number;
  total_chunks: number;
  chunk_data: string; // Base64 encoded
  checksum: string; // SHA256
  original_filename: string;
  chunk_size?: number;
  total_size?: number;
}

export interface InitializeUploadData {
  original_filename: string;
  total_chunks: number;
  total_size: number;
  checksum_sha256: string;
  mime_type?: string;
}

export interface UploadProgress {
  uploadId: string;
  filename: string;
  progress: number; // 0-100
  uploadedChunks: number;
  totalChunks: number;
  status: Upload['status'];
  error?: string;
}

export interface ResumeUploadInfo {
  can_resume: boolean;
  message: string;
  uploaded_chunks: number[];
  missing_chunks: number[];
  progress: number;
}

/**
 * File types
 */
export interface FileWithPreview extends File {
  preview?: string;
  uploadId?: string;
  progress?: number;
  status?: 'pending' | 'uploading' | 'completed' | 'failed';
  error?: string;
}

/**
 * Form types
 */
export interface ImportFormData {
  file: File | null;
  options: {
    skip_duplicates?: boolean;
    update_existing?: boolean;
  };
}

/**
 * Validation types
 */
export interface ValidationError {
  field: string;
  message: string;
}

/**
 * CSV Column types
 */
export interface RequiredColumns {
  required_columns: string[];
  import_type: string;
}

/**
 * Toast notification types
 */
export interface Toast {
  id: string;
  title: string;
  description?: string;
  type: 'success' | 'error' | 'warning' | 'info';
  duration?: number;
}

/**
 * Filter and sort types
 */
export interface ImportFilter {
  status?: ImportLog['status'];
  type?: ImportLog['import_type'];
  from_date?: string;
  to_date?: string;
}

export interface UploadFilter {
  status?: Upload['status'];
  from_date?: string;
  to_date?: string;
}

export interface SortConfig {
  field: string;
  direction: 'asc' | 'desc';
}

/**
 * Chart data types
 */
export interface ChartDataPoint {
  name: string;
  value: number;
  label?: string;
}

export interface ImportChartData {
  date: string;
  imported: number;
  updated: number;
  invalid: number;
  duplicates: number;
}
