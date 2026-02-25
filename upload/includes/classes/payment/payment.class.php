<?php

require_once DirPath::get('classes') . 'payment/payment.interface.php';

class Payment implements PaymentSystemInterface
{

    private static ?Payment $instance = null;
    private PaymentSystemInterface $instance_payment;

    private function __construct(PaymentSystemInterface $instance_payment)
    {
        $this->instance_payment = $instance_payment;
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(?PaymentSystemInterface $payment = null): Payment
    {
        if (self::$instance === null) {
            if ($payment === null) {
                throw new Exception('Payment instance required for first initialization');
            }
            self::$instance = new self($payment);
        }
        return self::$instance;
    }

    /**
     * Reset singleton instance (for testing)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function getInstancePayment() :PaymentSystemInterface
    {
        return $this->instance_payment;
    }

    /**
     * Handle successful payment - ClipBucket logic
     */
    public function successPayment(string $transactionId, array $data = []): bool
    {
        // First handle PayPal-specific logic
        $paypalResult = $this->getInstancePayment()->successPayment($transactionId, $data);

        // Then handle ClipBucket-specific logic (membership)
        // Récupérer l'id_user_membership depuis les données
        $idUserMembership = $data['id_user_membership'] ?? null;
        if (empty($idUserMembership)) {
            throw new Exception('id_user_membership is required for membership payment');
        }

        // Récupérer les données du membership
        $membershipData = Membership::getInstance()->getAllHistoMembershipForUser([
            'id_user_membership' => $idUserMembership
        ]);

        if (empty($membershipData) || empty($membershipData[0])) {
            throw new Exception('Membership not found for id: ' . $idUserMembership);
        }

        $membership = $membershipData[0];
        $idPaypalTransaction = (int)$transactionId;

        try {
            // Mettre à jour le membership
            Membership::getInstance()->updateHistoMembership([
                'id_user_membership' => $idUserMembership,
                'id_user_memberships_status' => 2, // completed
                'date_start' => date('Y-m-d'),
                'date_end' => $this->getNextDate($membership['frequency'] ?? 'monthly', date('Y-m-d'))
            ]);

            // Lier la transaction au membership
            $this->insertTransactionOnUserMembership($idUserMembership, $idPaypalTransaction);
        } catch (Exception $e) {
            error_log('ClipbucketPayment successPayment failed: ' . $e->getMessage());
            throw $e;
        }

        return $paypalResult;
    }

    /**
     * Calculate next payment date based on frequency
     */
    protected function getNextDate(string $frequency, string $date): string
    {
        $timestamp = strtotime($date);

        switch (strtolower($frequency)) {
            case 'weekly':
                $next = strtotime('+1 week', $timestamp);
                break;
            case 'monthly':
                $next = strtotime('+1 month', $timestamp);
                break;
            case 'yearly':
                $next = strtotime('+1 year', $timestamp);
                break;
            default:
                return date('Y-m-d', $timestamp);
        }

        return date('Y-m-d', $next);
    }

    /**
     * Link PayPal transaction to user membership
     */
    protected function insertTransactionOnUserMembership(int $idUserMembership, int $idPaypalTransaction): void
    {
        $sql = 'INSERT IGNORE INTO ' . cb_sql_table('user_memberships_transactions') . ' 
                (id_user_membership, id_paypal_transaction) 
                VALUES (' . mysql_clean($idUserMembership) . ', ' . mysql_clean($idPaypalTransaction) . ')';

        Clipbucket_db::getInstance()->execute($sql);
    }

    public function failedPayment(string $transactionId, string $reason, array $data = []): bool
    {
        return $this->getInstancePayment()->failedPayment($transactionId, $reason, $data);
    }

    public function getPaymentData(string $transactionId): array
    {
        return $this->getInstancePayment()->getPaymentData($transactionId);
    }

    public function createPaymentFromToken(string $token, float $amount, string $currency): array
    {
        return $this->getInstancePayment()->createPaymentFromToken($token, $amount, $currency);
    }

    public function successPaymentFromToken(string $transactionId, string $token): bool
    {
        return $this->getInstancePayment()->successPaymentFromToken($transactionId, $token);
    }

    public function failedPaymentFromToken(string $transactionId, string $token, string $reason): bool
    {
        return $this->getInstancePayment()->failedPaymentFromToken($transactionId, $token, $reason);
    }

    public function refundPayment(string $transactionId, ?float $amount = null): array
    {
        return $this->getInstancePayment()->refundPayment($transactionId, $amount);
    }

    public function successRefundPayment(string $refundId, string $originalTransactionId): bool
    {
        return $this->getInstancePayment()->successRefundPayment($refundId, $originalTransactionId);
    }

    public function failedRefundPayment(string $refundId, string $originalTransactionId, string $reason): bool
    {
        return $this->getInstancePayment()->failedRefundPayment($refundId, $originalTransactionId, $reason);
    }

    public function getAllActiveTokensFromUser($userId)
    {
        return $this->getInstancePayment()->getAllActiveTokensFromUser($userId);
    }

    public function deleteTokenFromUser($userId, string $token)
    {
        return $this->getInstancePayment()->deleteTokenFromUser($userId, $token);
    }

    public function getHtmlForCrudToken(): string
    {
        return $this->getInstancePayment()->getHtmlForCrudToken();
    }

    public function getHtmlPayment(): string
    {
        return $this->getInstancePayment()->getHtmlPayment();
    }

    public function getAllTransaction(int $idUserMembership) :array
    {
        return $this->getInstancePayment()->getAllTransaction($idUserMembership);
    }

    protected function ajaxUserGetToken(int $userId) {
        $vaults = $this->getAllActiveTokensFromUser($userId);
        $cleanVault = [];
        foreach($vaults as $vault) {
            $cleanVault[] = [
                'id' => $vault['token_id'],
                'brand' => strtolower($vault['brand']),
                'last4' => $vault['last4'],
                'expiry' => $vault['expiry'],
                'holder' => $vault['holder'] ?? '',
                'is_default' => $vault['is_default'],
            ];
        }
        return ['success' => true, 'vaults' => $cleanVault];
    }

    protected function ajaxUserRemoveToken(int $userId, string $tokenId) {
        /** check if user has right to delete this vault */
        $vaults = $this->getAllActiveTokensFromUser($userId);
        $found = false;
        foreach ($vaults as $vault) {
            if($vault['token_id'] != $tokenId) {
                continue;
            }
            $found = true;
        }
        if($found == false) {
            throw new Exception('This token is link to another user or not exist. you can\'t delete it.');
        }

        /** delete vault */
        $this->deleteTokenFromUser($userId, $tokenId);

        return ['success' => true];
    }

    public function callAction(string $action, int $userId) :array
    {
        if(empty($userId)) {
            throw new Exception('User is needed');
        }

        switch($action) {

            case 'user_get_token':
                return $this->ajaxUserGetToken(userId: $userId);

            case 'user_remove_token':
                return $this->ajaxUserRemoveToken(userId: $userId, tokenId: $_POST['token_id']);

            case 'js_files':
                $urlJsFile = $this->getJsFile();
                header('Location: '.$urlJsFile);
                die();

            case 'css_files':
                $urlCssFile = $this->getCssFile();
                header('Location: '.$urlCssFile);
                die();

            default:
                return $this->getInstancePayment()->callAction($action, $userId);
        }
    }

    public function getJsFile() :string
    {
        return $this->getInstancePayment()->getJsFile();
    }

    public function getCssFile() :string
    {
        return $this->getInstancePayment()->getCssFile();
    }
}
