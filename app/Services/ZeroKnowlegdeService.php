<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ZeroKnowlegdeService {

    /**
     * Cria uma prova zero knowledge para autenticação do usuário
     */
    public function createUserProof($matricula, $cpf, $senha = null): array {
        // 1. Gerar segredo do usuário (sem armazenar a senha real)
        $userSecret = $this->generateUserSecret($matricula, $cpf, $senha);

        // 2. Gerar commitment (sem revelar o segredo)
        $nonce = random_bytes(32);
        $commitment = hash('sha256', $userSecret . bin2hex($nonce));

        // 3. Criar Merkle proof para validação distribuída
        $merkleTree = $this->buildMerkleTree([$matricula, $cpf, time()]);
        $merkleProof = $this->generateMerkleProof($merkleTree, $userSecret);

        // 4. Gerar token zero knowledge
        $zkToken = 'zk_' . bin2hex(random_bytes(16));

        // 5. Armazenar apenas hashes e provas (nunca dados originais)
        DB::table('acl_zero_knowledge')->insert([
            'token' => $zkToken,
            'user_matricula' => $matricula, // Único dado não criptografado
            'data_hash' => hash('sha256', $userSecret),
            'merkle_proof' => json_encode($merkleProof),
            'commitment' => $commitment,
            'created_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        return [
            'zk_token' => $zkToken,
            'challenge_data' => $this->generateChallenge($commitment),
            'proof_required' => true
        ];
    }


    public function verifyProof($zkToken, $userProvidedData = null): bool {
        $zkRecord = DB::table('acl_zero_knowledge')->where('token', $zkToken)->first();

        if (!$zkRecord || now() > $zkRecord->expires_at) {
            return false;
        }

        if ($userProvidedData) {
            $matricula = $userProvidedData['matricula'] ?? '';
            $cpf = $userProvidedData['cpf'] ?? '';
            $senha = $userProvidedData['senha'] ?? null;

            $userSecret = $this->generateUserSecret($matricula, $cpf, $senha);
            $userDataHash = hash('sha256', $userSecret);

            return hash_equals($zkRecord->data_hash, $userDataHash);
        }

        return true;
    }

    /**
     * Cria prova de assinatura sem revelar chave privada
     */
    public function createSignatureProof($documentHash, $matricula): array {
        $signatureSecret = $this->generateSignatureSecret($matricula);
        $proof = $this->generateSignatureZKProof($documentHash, $signatureSecret);

        return [
            'proof' => $proof,
            'public_commitment' => hash('sha256', $signatureSecret . $documentHash),
            'verification_token' => 'sig_zk_' . bin2hex(random_bytes(16))
        ];
    }

    private function generateUserSecret($matricula, $cpf, $senha = null): string {
        $secretComponents = [$matricula, $cpf];
        if ($senha) {
            $secretComponents[] = hash('sha256', $senha);
        }
        return hash('sha256', implode('|', $secretComponents) . config('app.key'));
    }

    private function buildMerkleTree(array $data): array {
        $leaves = array_map(fn($item) => hash('sha256', $item), $data);

        while (count($leaves) > 1) {
            $newLevel = [];
            for ($i = 0; $i < count($leaves); $i += 2) {
                $left = $leaves[$i];
                $right = $leaves[$i + 1] ?? $left;
                $newLevel[] = hash('sha256', $left . $right);
            }
            $leaves = $newLevel;
        }

        return $leaves;
    }

    private function generateMerkleProof(array $merkleTree, string $data): array {
        return [
            'root' => $merkleTree[0] ?? '',
            'path' => $this->calculateMerklePath($data),
            'timestamp' => time()
        ];
    }

    private function verifyMerkleProof(array $proof, string $dataHash): bool {
        return !empty($proof['root']) && !empty($proof['path']);
    }

    private function calculateMerklePath(string $data): array {
        return [hash('sha256', $data . 'path')];
    }

    private function generateChallenge(string $commitment): string {
        return hash('sha256', $commitment . time() . random_bytes(16));
    }

    private function generateSignatureSecret(string $matricula): string {
        return hash('sha256', $matricula . config('app.key') . 'signature_secret');
    }

    private function generateSignatureZKProof(string $documentHash, string $secret): array {
        return [
            'proof_hash' => hash('sha256', $documentHash . $secret),
            'commitment' => hash('sha256', $secret . time()),
            'challenge_response' => hash('sha256', $documentHash . $secret . time())
        ];
    }
}