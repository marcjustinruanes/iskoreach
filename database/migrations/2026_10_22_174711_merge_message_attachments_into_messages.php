<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('message_attachments')->join('messages', 'messages.id', '=', 'message_attachments.message_id')->whereColumn('uploaded_by', '!=', 'sender_id')->exists()) {
            throw new RuntimeException('Resolve chat attachments whose uploader differs from their message sender before merging.');
        }
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('attachment_path')->nullable()->unique();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime_type', 100)->nullable();
            $table->unsignedBigInteger('attachment_size_bytes')->nullable();
            $table->char('attachment_checksum', 64)->nullable();
        });
        DB::table('message_attachments')->orderBy('id')->chunkById(200, function ($attachments): void {
            foreach ($attachments as $attachment) {
                DB::table('messages')->where('id', $attachment->message_id)->update([
                    'attachment_path' => $attachment->path,
                    'attachment_original_name' => $attachment->original_name,
                    'attachment_mime_type' => $attachment->mime_type,
                    'attachment_size_bytes' => $attachment->size_bytes,
                    'attachment_checksum' => $attachment->checksum,
                ]);
            }
        });
        Schema::drop('message_attachments');
    }

    public function down(): void
    {
        Schema::create('message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('accounts')->restrictOnDelete();
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->timestamps();
        });
        DB::table('messages')->whereNotNull('attachment_path')->orderBy('id')->chunkById(200, function ($messages): void {
            foreach ($messages as $message) {
                DB::table('message_attachments')->insert([
                    'message_id' => $message->id, 'uploaded_by' => $message->sender_id,
                    'path' => $message->attachment_path, 'original_name' => $message->attachment_original_name,
                    'mime_type' => $message->attachment_mime_type, 'size_bytes' => $message->attachment_size_bytes,
                    'checksum' => $message->attachment_checksum, 'created_at' => $message->created_at, 'updated_at' => $message->updated_at,
                ]);
            }
        });
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropUnique(['attachment_path']);
            $table->dropColumn(['attachment_path', 'attachment_original_name', 'attachment_mime_type', 'attachment_size_bytes', 'attachment_checksum']);
        });
    }
};
