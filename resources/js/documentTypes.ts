export interface VaultDocument { id: number; label: string; original_name: string; mime_type: string; size_bytes: number; uploaded_at: string; download_url: string }
export interface AttachedDocument { id: number; name: string; file_name: string; version: number; size_bytes: number; download_url: string }
export interface DocumentRequirement { id: number; name: string; description: string | null; required: boolean; allowed_mime_types: string[] | null; max_size_kb: number | null; complete: boolean; attachment: AttachedDocument | null }
