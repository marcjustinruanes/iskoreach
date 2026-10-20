import type { Paginated } from './types';
export interface ChatThread { id: number; partner: string; last_message_at: string | null; unread: number }
export interface ChatMessage { id: number; mine: boolean; body: string | null; sent_at: string; read_at: string | null; attachment: { name: string; size_bytes: number; download_url: string } | null }
export interface ChatState { thread: ChatThread; typing: boolean; messages: Paginated<ChatMessage> }
