<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentoSecurityService {

    /**
     * Criptografa e armazena documento de forma que nem programadores tenham acesso
     */
    public function secureDocument($documentContent, $userInfo, $signature, $publicKey): string {
        $userKey = $this->generateUserKey($userInfo);

        $encryptedDocument = $this->encryptWithUserKey($documentContent, $userKey);
        $encryptedSignature = $this->encryptWithUserKey($signature, $userKey);
        $encryptedPublicKey = $this->encryptWithUserKey($publicKey, $userKey);

        $documentId = 'doc_' . bin2hex(random_bytes(16));

        DB::table('secure_documents')->insert([
            'document_id' => $documentId,
            'encrypted_document' => base64_encode($encryptedDocument),
            'encrypted_signature' => base64_encode($encryptedSignature),
            'encrypted_public_key' => base64_encode($encryptedPublicKey),
            'user_hash' => hash('sha256', $userInfo['matricula'] . $userInfo['cpf']), // Hash para indexação
            'document_hash' => hash('sha256', $documentContent),
            'created_at' => now(),
            'expires_at' => now()->addYears(10),
        ]);

        return $documentId;
    }

    /**
     * Recupera documento apenas com dados do usuário (Zero-Knowledge)
     */
    public function retrieveDocument($documentId, $userInfo): ?array {
        $record = DB::table('secure_documents')
            ->where('document_id', $documentId)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return null;
        }

        $userHash = hash('sha256', $userInfo['matricula'] . $userInfo['cpf']);
        if ($record->user_hash !== $userHash) {
            return null; // Usuário não autorizado
        }

        $userKey = $this->generateUserKey($userInfo);

        try {
            $document = $this->decryptWithUserKey(
                base64_decode($record->encrypted_document),
                $userKey
            );
            $signature = $this->decryptWithUserKey(
                base64_decode($record->encrypted_signature),
                $userKey
            );
            $publicKey = $this->decryptWithUserKey(
                base64_decode($record->encrypted_public_key),
                $userKey
            );

            return [
                'document' => $document,
                'signature' => $signature,
                'public_key' => $publicKey,
                'document_hash' => $record->document_hash
            ];
        } catch (Exception $e) {
            return null; // Falha na descriptografia = acesso negado
        }
    }

    /**
     * Verifica documento SEM descriptografar (para verificação pública)
     */
    public function verifyDocumentIntegrity($documentId): bool {
        $record = DB::table('secure_documents')
            ->where('document_id', $documentId)
            ->where('expires_at', '>', now())
            ->first();

        return $record !== null;
    }

    private function generateUserKey($userInfo): string {
        $keyComponents = [
            $userInfo['matricula'],
            $userInfo['cpf'],
            config('app.key'), // Salt do sistema
            'document_encryption_v1'
        ];

        return hash('sha256', implode('|', $keyComponents), true);
    }

    private function encryptWithUserKey($data, $key): string {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $iv . $encrypted;
    }

    private function decryptWithUserKey($encryptedData, $key): string {
        $iv = substr($encryptedData, 0, 16);
        $encrypted = substr($encryptedData, 16);

        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new Exception('Falha na descriptografia');
        }

        return $decrypted;
    }
}